<?php

namespace App\Domain\Service\Caisse;

use App\Domain\Enum\ModeReglement;
use App\Domain\Enum\PartPesee;
use App\Domain\Enum\ReseauMobile;
use App\Domain\Enum\StatutPaiement;
use App\Domain\Enum\StatutReglement;
use App\Domain\Paiement\PasserellePaiementInterface;
use App\Domain\Paiement\ResultatVersement;
use App\Domain\Support\NumeroMobile;
use App\Entity\Fournisseur;
use App\Entity\Operation;
use App\Entity\Site;
use App\Domain\Service\Epargne\EpargneService;
use App\Domain\Service\MailerService;
use App\Entity\Paiement;
use App\Entity\User;
use App\Repository\PaiementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Le cycle d'un versement à un planteur :

      contrôles → réservation des fonds → appel de la passerelle → confirmation

    - Les fonds sont réservés AVANT l'appel : tant qu'un versement est en vol, la somme ne doit pas pouvoir être engagée une seconde fois. Un échec produit un REMBOURSEMENT, jamais une annulation d'écriture — le grand livre ne se réécrit pas.

    - 'appliquerResultat()' est idempotent : le webhook et la réconciliation peuvent porter la même confirmation, elle ne doit rembourser ou créditer qu'une fois.
*/
class PaiementService
{
    public function __construct(
        private EntityManagerInterface $em,
        private CaisseService $caisse,
        private PasserellePaiementInterface $passerelle,
        private PaiementRepository $paiementRepository,
        private EpargneService $epargne,
        private MailerService $mailer,
        private LoggerInterface $logger
    )
    {
    }

    /*
        - DEUX MONTANTS, UN PAR PART. Une pesée vaut deux choses distinctes — la récolte et le transport que le planteur a avancé — et on peut désormais régler l'une, l'autre, ou les deux, chacune en plusieurs fois.

        - LES DEUX PARTENT ENSEMBLE OU PAS DU TOUT. Un refus sur une part fait échouer tout l'appel : déverser en silence sur l'autre reviendrait à payer autre chose que ce que l'opérateur a saisi, avec le planteur devant lui.

        - Payer les deux d'un geste reste UN versement : une référence, une sortie de caisse, un seul envoi mobile money. Pour deux modes de règlement différents, on fait deux versements.
    */
    public function initier(Operation $pesee, int $montantProduit, int $montantTransport, ModeReglement $mode, User $initiePar, ?string $numeroDestinataire = null, ?ReseauMobile $reseau = null, ?string $numeroCheque = null): Paiement
    {
        $planteur = $pesee->getFournisseur();
        $site = $pesee->getSite();

        if($planteur === null || $site === null) {
            throw new UnprocessableEntityHttpException('Cette pesée n\'est rattachée ni à un planteur ni à un pont bascule.');
        }

        if(!$pesee->estTerminee()) {
            throw new UnprocessableEntityHttpException('Cette pesée n\'est pas terminée : le camion n\'est pas encore ressorti.');
        }

        if(!$site->accepteLaSynchronisation()) {
            throw new UnprocessableEntityHttpException('Ce pont bascule est bloqué : aucun paiement ne peut en partir.');
        }

        $this->exigerUnPlanteurReglable($planteur);

        $destination = $this->resoudreLaDestination($mode, $planteur, $numeroDestinataire, $numeroCheque);

        if($montantProduit < 0 || $montantTransport < 0) {
            throw new UnprocessableEntityHttpException('Un montant versé ne peut pas être négatif.');
        }

        if($montantProduit + $montantTransport <= 0) {
            throw new UnprocessableEntityHttpException('Il n\'y a rien à verser : indiquez un montant sur la récolte, sur le transport, ou sur les deux.');
        }

        /*
            - On borne sur ce qui est ENGAGÉ et non sur ce qui est confirmé : un versement encore en vol chez l'agrégateur a déjà quitté la caisse, et rien ne dit qu'il échouera. Ne compter que le confirmé permettrait d'engager deux fois le même reste à payer, et de payer le planteur en double.

            - CE SONT LES DEUX SEULES LIGNES DE L'APPLICATION OÙ LA BORNE D'UN VERSEMENT SE DÉCIDE. Il n'y en avait qu'une tant qu'une pesée se réglait d'un bloc. Tout le reste peut être juste : si elles repartaient du montant dû, le guichet verserait l'épargne avec le reste.

            - Une seule requête pour les deux, pour que les deux bornes voient le même instant.
        */
        $engage = $this->paiementRepository->sommesEngagees($pesee);

        $this->exigerUnMontantTenable(
            $montantProduit,
            $pesee->getPayableproduit(),
            $pesee->getMontantpayeproduit(),
            $engage['produit'],
            $pesee->getMotifNonPayableProduit(),
            Operation::PART_RECOLTE
        );

        $this->exigerUnMontantTenable(
            $montantTransport,
            $pesee->getPayabletransport(),
            $pesee->getMontantpayetransport(),
            $engage['transport'],
            $pesee->getMotifNonPayableTransport(),
            Operation::PART_TRANSPORT
        );

        return $this->executer($planteur, $site, $montantProduit, $montantTransport, $mode, $initiePar, $destination, $pesee, $reseau);
    }

    /*
        - LE VERSEMENT GROUPÉ : plusieurs pesées d'un MÊME planteur, en un geste. Au guichet, un
          planteur se présente avec trois tickets ; les régler un par un, c'est trois fois l'écran,
          trois fois le risque d'en oublier un, et trois fois la question « combien au juste ».

        - COCHER, C'EST RÉGLER LA TOTALITÉ de ce qui est coché. Aucun montant ne se saisit : on
          choisit les pesées, éventuellement la PART (la récolte seule, le transport seul, ou les
          deux), et chaque pesée reçoit tout ce qui lui reste à engager. Il n'y a donc aucune
          répartition à décider, et aucune façon de se tromper d'un franc.

        - UN SEUL PLANTEUR, UNE SEULE CAISSE, et le refus est explicite. Un versement sort d'une
          caisse vers une personne : mélanger deux planteurs produirait une ligne qui ne veut rien
          dire, et deux ponts bascules un débit sur la mauvaise caisse.

        - UNE SEULE TRANSACTION POUR TOUT LE LOT. Trois appels à 'initier()' en feraient trois, et
          une caisse à sec sur la troisième laisserait les deux premières payées — l'opérateur
          croirait son geste refusé alors que l'argent est sorti. D'où 'ecrireEtDebiter()', qui ne
          transacte pas, et la boucle sous une seule enveloppe.

        - LES BORNES SONT CELLES DE 'initier()', appliquées pesée par pesée : on part du PAYABLE
          moins ce qui est déjà ENGAGÉ (versements en vol compris). Une pesée qui n'a plus rien à
          engager est écartée en silence plutôt que de faire échouer tout le lot : elle a pu être
          réglée à l'autre guichet pendant que l'opérateur cochait.

        @param list<Operation> $pesees
        @param PartPesee|null  $part la récolte seule, le transport seul, ou null pour les deux

        @return list<Paiement>
    */
    public function initierLot(array $pesees, ?PartPesee $part, ModeReglement $mode, User $initiePar, ?string $numeroCheque = null): array
    {
        if($pesees === []) {
            throw new UnprocessableEntityHttpException('Aucune pesée sélectionnée.');
        }

        /*
            - LE GROUPÉ NE PASSE PAS PAR LA PASSERELLE, et le refus vaut mieux qu'un demi-service :
              trois envois mobiles sous une transaction, dont le deuxième échoue, laisseraient un
              lot à moitié parti que la réconciliation devrait démêler ligne à ligne. Le mode est
              de toute façon fermé à la saisie ; le jour où il rouvre, c'est ici qu'on décidera.
        */
        if($mode->passeParLaPasserelle()) {
            throw new UnprocessableEntityHttpException('Un versement groupé se règle en espèces ou par chèque : réglez les pesées une à une pour les autres modes.');
        }

        $planteur = null;
        $site = null;

        foreach($pesees as $pesee) {
            $sien = $pesee->getFournisseur();
            $sonSite = $pesee->getSite();

            if($sien === null || $sonSite === null) {
                throw new UnprocessableEntityHttpException('Une de ces pesées n’est rattachée ni à un planteur ni à un pont bascule.');
            }

            if(!$pesee->estTerminee()) {
                throw new UnprocessableEntityHttpException(sprintf(
                    'La pesée %s n’est pas terminée : le camion n’est pas encore ressorti.',
                    (string) $pesee->getNumticket()
                ));
            }

            $planteur ??= $sien;
            $site ??= $sonSite;

            if($sien->getId() !== $planteur->getId()) {
                throw new UnprocessableEntityHttpException('Un versement va à UN planteur : ces pesées ne sont pas toutes au même.');
            }

            if($sonSite->getId() !== $site->getId()) {
                throw new UnprocessableEntityHttpException('Ces pesées viennent de deux ponts bascules : un versement sort d’une seule caisse.');
            }
        }

        if(!$planteur instanceof Fournisseur || !$site instanceof Site) {
            throw new UnprocessableEntityHttpException('Ces pesées ne désignent ni planteur ni pont bascule.'); // Inatteignable : la boucle ci-dessus l a déjà refusé
        }

        if(!$site->accepteLaSynchronisation()) {
            throw new UnprocessableEntityHttpException('Ce pont bascule est bloqué : aucun paiement ne peut en partir.');
        }

        $this->exigerUnPlanteurReglable($planteur);

        $destination = $this->resoudreLaDestination($mode, $planteur, null, $numeroCheque);

        /* Le plan, arrêté AVANT d'écrire : ce qu'il reste à engager sur chaque pesée, part par part. */
        $plan = [];
        $total = 0;

        foreach($pesees as $pesee) {
            $engage = $this->paiementRepository->sommesEngagees($pesee);

            $montantProduit = $part === PartPesee::TRANSPORT ? 0 : max(0, $pesee->getPayableproduit() - $engage['produit']);
            $montantTransport = $part === PartPesee::RECOLTE ? 0 : max(0, $pesee->getPayabletransport() - $engage['transport']);

            if($montantProduit + $montantTransport <= 0) {
                continue;
            }

            $plan[] = [$pesee, $montantProduit, $montantTransport];
            $total += $montantProduit + $montantTransport;
        }

        if($total <= 0) {
            throw new UnprocessableEntityHttpException('Il n’y a rien à verser sur ces pesées : tout est déjà réglé ou engagé.');
        }

        $paiements = [];

        $this->em->wrapInTransaction(function() use ($plan, $planteur, $site, $mode, $initiePar, $destination, &$paiements): void {
            foreach($plan as [$pesee, $montantProduit, $montantTransport]) {
                $paiements[] = $this->ecrireEtDebiter($planteur, $site, $montantProduit, $montantTransport, $mode, $initiePar, $destination, $pesee, null);
            }
        });

        /*
            - LA CONFIRMATION LIGNE À LIGNE, hors de la transaction. Hors passerelle elle ne peut pas
              échouer — les billets ont déjà changé de main — et c'est elle qui fait tomber le reste
              à payer de chaque pesée. La faire dans la transaction aurait allongé d'autant le temps
              où la caisse est verrouillée.
        */
        foreach($paiements as $rang => $paiement) {
            $paiements[$rang] = $this->conclure($paiement, $mode, $initiePar);
        }

        return $paiements;
    }

    /*
        - Le contrôle d'UNE part, appliqué aux deux. Rien à verser sur cette part : on ne dit rien, c'est le cas courant — la plupart des planteurs n'ont pas de transport, et un opérateur qui ne règle que la récolte laisse l'autre champ à zéro.

        - LE REFUS SE COMPOSE DES CHIFFRES PASSÉS ICI, et non de ce que l'entité répondrait. Les siens viennent de sa collection de versements, qui n'est pas à jour au moment où l'on écrit : une pesée entièrement engagée s'y lisait encore libre, et le refus tombait sur un message générique au lieu de dire qu'un versement était en vol. Les formats, eux, sont ceux de l'entité — l'écran doit annoncer mot pour mot ce que le serveur répond.

        - Le motif de l'entité ne sert que lorsque la part est fermée POUR UNE AUTRE RAISON QUE L'ARGENT déjà versé : produit non tarifé, récolte intégralement retenue, planteur sans transport. Ces branches-là ne touchent pas à la collection.
    */
    private function exigerUnMontantTenable(int $montant, int $payable, int $paye, int $engage, ?string $motifDeLaPart, string $part): void
    {
        if($montant <= 0) {
            return;
        }

        if($payable <= 0) {
            throw new UnprocessableEntityHttpException(
                $motifDeLaPart ?? sprintf('Il n\'y a rien à verser sur la part %s de cette pesée.', $part)
            );
        }

        $reste = max(0, $payable - $engage);

        if($reste <= 0) {
            throw new UnprocessableEntityHttpException(sprintf(
                $paye >= $payable ? Operation::MOTIF_PART_REGLEE : Operation::MOTIF_PART_EN_COURS,
                $part
            ));
        }

        if($montant > $reste) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Montant supérieur au reste à payer sur la part %s : %s F CFA au maximum.',
                $part,
                number_format($reste, 0, ',', ' ')
            ));
        }
    }

    /*
        - LE RETRAIT D'ÉPARGNE : le planteur reprend ce qu'on lui gardait. C'est un versement comme
          un autre — même référence, même passerelle, même « en vol », même remboursement sur échec
          — à ceci près qu'il ne pointe sur aucune pesée et que sa borne est le solde d'épargne.

        - Rien à soustraire à cette borne : l'épargne étant débitée dans la transaction ci-dessous,
          un retrait encore en vol a déjà quitté le solde et ne peut pas être redemandé. C'est
          'EpargneService::retirer()' qui la vérifie, sous verrou, au moment d'écrire.

        - L'ordre compte : l'épargne d'abord, la caisse ensuite. Si le poste n'a pas la provision, le
          débit de la caisse lève et la transaction emporte le débit d'épargne avec elle — le
          planteur n'a rien perdu.
    */
    public function initierRetrait(Fournisseur $planteur, int $montant, ModeReglement $mode, User $initiePar, ?string $numeroDestinataire = null, ?ReseauMobile $reseau = null, ?string $numeroCheque = null): Paiement
    {
        $site = $planteur->getSite();

        if($site === null) {
            throw new UnprocessableEntityHttpException('Ce planteur n\'est rattaché à aucun pont bascule : aucun retrait ne peut en partir.');
        }

        if(!$site->accepteLaSynchronisation()) {
            throw new UnprocessableEntityHttpException('Ce pont bascule est bloqué : aucun retrait ne peut en partir.');
        }

        $this->exigerUnPlanteurReglable($planteur);

        $destination = $this->resoudreLaDestination($mode, $planteur, $numeroDestinataire, $numeroCheque);

        if($montant <= 0) {
            throw new UnprocessableEntityHttpException('Un retrait doit être supérieur à zéro.');
        }

        /* Tout sur la part produit : un retrait n'a pas de pesée, donc pas de transport à distinguer, et l'invariant « le total est la somme des parts » reste vrai sans exception. */
        return $this->executer(
            $planteur,
            $site,
            $montant,
            0,
            $mode,
            $initiePar,
            $destination,
            null,
            $reseau,
            fn() => $this->epargne->retirer($planteur, $montant, 'Retrait au guichet')
        );
    }

    /*
        - LA QUEUE COMMUNE À TOUS LES VERSEMENTS, pesée ou non : construire la pièce, réserver les
          fonds, appeler la passerelle. Elle a été extraite telle quelle le jour où le retrait
          d'épargne est arrivé — un versement qui ne pointe sur aucune pesée. Pas une ligne n'y a
          changé de comportement : seule la borne diffère d'un appelant à l'autre, et elle est
          vérifiée AVANT d'arriver ici.

        @param array{numero: ?string, cheque: ?string} $destination
    */
    private function executer(Fournisseur $planteur, Site $site, int $montantProduit, int $montantTransport, ModeReglement $mode, User $initiePar, array $destination, ?Operation $pesee = null, ?ReseauMobile $reseau = null, ?callable $avantLeDebit = null): Paiement
    {
        $paiement = null;

        /*
            - Le versement et la réservation des fonds dans la MÊME transaction. Écrire le paiement
              d'abord puis débiter laissait, quand la caisse est insuffisante, une ligne en
              'EN_ATTENTE' que rien ne rattrapait : elle comptait dans 'sommeEngagee()' — donc
              gelait une part du reste à payer pour toujours — et la réconciliation aurait fini par
              la faire confirmer par la passerelle, créditant un planteur sans qu'un franc soit sorti.
        */
        $this->em->wrapInTransaction(function() use (&$paiement, $planteur, $site, $montantProduit, $montantTransport, $mode, $initiePar, $destination, $pesee, $reseau, $avantLeDebit): void {
            $paiement = $this->ecrireEtDebiter($planteur, $site, $montantProduit, $montantTransport, $mode, $initiePar, $destination, $pesee, $reseau, $avantLeDebit);
        });

        if(!$paiement instanceof Paiement) {
            throw new \RuntimeException('La transaction du versement n a rien ecrit.'); // Inatteignable : la fermeture pose toujours la ligne
        }

        return $this->conclure($paiement, $mode, $initiePar);
    }

    /*
        - CE QUI S'ÉCRIT DANS LA TRANSACTION, et rien d'autre : la ligne du versement, le geste
          préalable s'il y en a un, et la réservation des fonds. Extrait pour que le versement
          GROUPÉ puisse en enchaîner plusieurs sous UNE transaction — imbriquer deux
          'wrapInTransaction' est à proscrire, DBAL ne pose pas de point de reprise.

        - Le contrôle du solde reste dans 'debiterPourPaiement()', sous verrou : le vérifier ici en
          plus serait à la fois redondant et sujet à la course.

        @param array{numero: ?string, cheque: ?string} $destination
    */
    private function ecrireEtDebiter(Fournisseur $planteur, Site $site, int $montantProduit, int $montantTransport, ModeReglement $mode, User $initiePar, array $destination, ?Operation $pesee, ?ReseauMobile $reseau, ?callable $avantLeDebit = null): Paiement
    {
        $paiement = (new Paiement())
            ->setReference(Paiement::genererReference())
            ->setMontants($montantProduit, $montantTransport)
            ->setModeReglement($mode)
            ->setOperation($pesee)
            ->setFournisseur($planteur)
            ->setSite($site)
            ->setNumeroDestinataire($destination['numero'])
            ->setNumeroCheque($destination['cheque'])
            ->setReseau($mode->passeParLaPasserelle() ? ($reseau ?? $planteur->getReseau()) : null)
            // Qui a traité le versement. Hors passerelle, c'est le guichet lui-même : une recherche a posteriori doit pouvoir distinguer les deux
            ->setGateway($mode->passeParLaPasserelle() ? $this->passerelle->nom() : 'caisse')
            ->setStatut(StatutPaiement::EN_ATTENTE)
            ->setInitiePar($initiePar);

        $paiement->setCreatedBy($initiePar->getId());

        /*
            - Le geste du retrait, s'il y en a un : débiter le livre d'épargne. Il est DANS la même
              transaction que la réservation des fonds, et c'est tout l'intérêt — les deux livres
              passent ou tombent ensemble. Les séparer aurait laissé une fenêtre où l'épargne est
              débitée sans qu'un franc soit sorti, ou l'inverse.
        */
        if($avantLeDebit !== null) {
            $avantLeDebit();
        }

        $this->em->persist($paiement);

        $this->caisse->debiterPourPaiement($paiement); // Au-delà de ce point, la somme est sortie de la caisse

        return $paiement;
    }

    /*
        - CE QUI SUIT LA TRANSACTION : la confirmation. Extrait avec 'ecrireEtDebiter' et pour la
          même raison — un lot écrit toutes ses lignes sous UNE transaction, puis conclut chacune.

        - Les espèces s'arrêtent ici. Les billets ont changé de main pendant que l'opérateur saisissait l'écran : il n'y a personne à interroger, rien qui puisse échouer plus tard, et donc rien à réconcilier. Le versement est confirmé dans la foulée, et 'recalculerLeReglement()' fait tomber le reste à payer immédiatement.
        - Le faire passer par 'appliquerResultat()' comme les autres n'est pas une coquetterie : c'est lui qui tient 'montantpaye' et le statut de règlement. Court-circuiter ce chemin aurait donné une seconde façon de solder une pesée, et deux façons finissent par diverger.
    */
    private function conclure(Paiement $paiement, ModeReglement $mode, User $initiePar): Paiement
    {
        if(!$mode->passeParLaPasserelle()) {
            return $this->appliquerResultat($paiement, ResultatVersement::confirmeSansPasserelle([
                'mode' => $mode->value,
                'guichet' => $initiePar->getUserIdentifier(),
            ]));
        }

        try {
            $resultat = $this->passerelle->verser($paiement);
        } catch(\Throwable $e) {
            /*
                - La passerelle est injoignable : on ne sait pas si le versement est parti. On reste en 'ENVOYE' et la réconciliation tranchera. Rembourser tout de suite risquerait de rendre des fonds qui ont bel et bien été versés.
            */
            $this->logger->error('Passerelle injoignable pour {reference} : {erreur}', [
                'reference' => $paiement->getReference(),
                'erreur' => $e->getMessage(),
            ]);
            $paiement->setStatut(StatutPaiement::ENVOYE)->setMessageErreur($e->getMessage());
            $this->em->flush();

            return $paiement;
        }

        return $this->appliquerResultat($paiement, $resultat);
    }

    /*
        - Ce qui interdit TOUT règlement, avant même de regarder le mode : la suspension du planteur.
          Elle n'a rien à voir avec un numéro manquant — l'un dit qu'on ne peut pas le joindre,
          l'autre qu'on ne doit pas le payer. Ne contrôler que le second rouvrait en espèces la
          porte que la suspension venait de fermer.
        - Vaut pour un retrait d'épargne autant que pour une pesée : un planteur suspendu ne touche
          rien, pas même ce qu'on lui gardait.
    */
    private function exigerUnPlanteurReglable(Fournisseur $planteur): void
    {
        if(($bloquant = $planteur->getMotifBloquant()) !== null) {
            throw new UnprocessableEntityHttpException($bloquant);
        }
    }

    /*
        - CE QUI SUIT NE CONCERNE QUE LE MOBILE MONEY ET LE CHÈQUE. Des espèces ne vont à aucun numéro : exiger un numéro de téléphone pour remettre des billets à quelqu'un qui est devant soi n'aurait servi qu'à rendre impayables les planteurs qui n'en ont pas — c'est-à-dire précisément ceux qu'on règle en liquide.
        - Le numéro et le réseau transmis sont donc ignorés hors du mobile money, plutôt que refusés : un formulaire qui garde ses champs cachés remplis ne doit pas faire échouer un versement.

        @return array{numero: ?string, cheque: ?string}
    */
    private function resoudreLaDestination(ModeReglement $mode, Fournisseur $planteur, ?string $numeroDestinataire, ?string $numeroCheque): array
    {
        $numero = null;

        if($mode->exigeUnNumero()) {
            // Le numéro transmis a priorité : il permet de payer sur un numéro de circonstance sans toucher à la fiche
            $numero = $numeroDestinataire ?? $planteur->getContact1();

            if($numero === null || $numero === '') {
                throw new UnprocessableEntityHttpException($planteur->getMotifNonPayable() ?? 'Ce planteur n\'a pas de numéro de téléphone.');
            }

            /*
                - LE dernier contrôle avant que l'argent ne quitte la caisse. Les setters du planteur
                  normalisent déjà, mais le numéro de circonstance n'y passe pas : il arrive du corps de la
                  requête et va droit à l'agrégateur. Le laisser filer, c'est réserver les fonds, appeler
                  la passerelle, essuyer un refus, puis rembourser — trois écritures pour une faute de
                  frappe.
                - On normalise plutôt que de refuser une forme correcte mal écrite : « 07 01 02 03 04 »
                  est un numéro valable, tapé comme on le lit.
            */
            $numero = NumeroMobile::normaliser($numero) ?? throw new UnprocessableEntityHttpException(
                sprintf('Le numéro « %s » n\'est pas un numéro mobile reconnu.', $numero)
            );

            if($planteur->getMotifNonPayable() !== null && $numeroDestinataire === null) {
                throw new UnprocessableEntityHttpException($planteur->getMotifNonPayable());
            }
        }

        $cheque = null;

        if($mode->exigeUnNumeroDeCheque()) {
            $cheque = $numeroCheque === null ? '' : trim($numeroCheque);

            /*
                - Sans ce numéro, le versement est intraçable au relevé bancaire : deux chèques du
                  même montant au même planteur deviennent indiscernables le jour où la banque est
                  consultée. On le refuse donc AVANT de tirer sur le compte, pas après.
            */
            if($cheque === '') {
                throw new UnprocessableEntityHttpException('Le numéro du chèque est obligatoire : c\'est lui qui permettra de le retrouver sur le relevé bancaire.');
            }
        }

        return ['numero' => $numero, 'cheque' => $cheque];
    }

    public function appliquerResultat(Paiement $paiement, ResultatVersement $resultat): Paiement
    {
        if($paiement->estFinal()) {
            return $paiement; // Idempotence : le webhook et la réconciliation peuvent porter la même confirmation
        }

        $paiement
            ->setStatut($resultat->statut)
            ->setGatewayTransactionId($resultat->transactionId ?? $paiement->getGatewayTransactionId())
            ->setGatewayPayload($resultat->payload)
            ->setMessageErreur($resultat->message);

        if($resultat->statut === StatutPaiement::REUSSI) {
            $paiement->setConfirmeAt(new \DateTimeImmutable());
        }

        $this->em->flush();

        if($resultat->statut === StatutPaiement::ECHOUE) {
            $this->caisse->rembourserPaiement($paiement, 'Remboursement du paiement ' . $paiement->getReference() . ' : ' . ($resultat->message ?? 'échec'));

            /*
                - UN RETRAIT ÉCHOUÉ REND L'ARGENT AUX DEUX LIVRES. La caisse vient d'être remboursée
                  ci-dessus ; sans la ligne qui suit, l'épargne du planteur resterait amputée d'une
                  somme qu'il n'a jamais touchée — et rien ne le signalerait, puisque les deux livres
                  sont séparés.
                - Un versement sans pesée EST un retrait : c'est le seul cas où 'operation' est nul.
            */
            if($paiement->getOperation() === null && ($planteur = $paiement->getFournisseur()) !== null) {
                $this->epargne->annulerRetrait(
                    $planteur,
                    $paiement->getMontant(),
                    'Retrait ' . $paiement->getReference() . ' annulé : ' . ($resultat->message ?? 'échec')
                );
            }

            // Prévenu APRÈS le remboursement : le message annonce que l'argent est revenu, il ne doit pas partir avant que ce soit vrai
            $this->mailer->paiementEchoue($paiement);
        }

        if(($pesee = $paiement->getOperation()) !== null) {
            $this->recalculerLeReglement($pesee);
        }

        return $paiement;
    }

    /**
     * Réinterroge les versements restés en vol. Sans ce filet, un webhook perdu immobiliserait les fonds du site indéfiniment.
     *
     * @return int le nombre de paiements dont l'état a changé
     */
    public function reconcilier(int $delaiMinutes = 15): int
    {
        $limite = new \DateTimeImmutable(sprintf('-%d minutes', $delaiMinutes));
        $traites = 0;

        foreach($this->paiementRepository->findARegulariser($limite) as $paiement) {
            try {
                $resultat = $this->passerelle->verifier((string) $paiement->getReference());
            } catch(\Throwable $e) {
                $this->logger->error('Réconciliation impossible pour {reference} : {erreur}', [
                    'reference' => $paiement->getReference(),
                    'erreur' => $e->getMessage(),
                ]);
                continue;
            }

            if($resultat->statut === StatutPaiement::ENVOYE) {
                continue; // Toujours en cours chez l'agrégateur
            }

            $this->appliquerResultat($paiement, $resultat);
            ++$traites;
        }

        return $traites;
    }

    /*
        - Tient à jour ce qui a réellement été versé sur une pesée. 'montantpaye' est dénormalisé : sans lui, afficher une liste imposerait une somme par ligne.
        - Le cumul est toujours RECALCULÉ, jamais incrémenté. Une addition se désynchronise au premier rejeu de webhook ; un recalcul, non.
        - La somme vient d'une requête et non de 'Operation::paiements' : cette collection n'est pas à jour juste après la confirmation, et la pesée paraîtrait impayée alors que l'argent est parti.
    */
    private function recalculerLeReglement(Operation $pesee): void
    {
        $verse = $this->paiementRepository->sommesVersees($pesee);

        $pesee->setMontantspayes($verse['produit'], $verse['transport']);
        $pesee->setStatutReglement(StatutReglement::pourLesParts(
            $pesee->getMontantdu(),
            $pesee->getPayableproduit(),
            $verse['produit'],
            $pesee->getPayabletransport(),
            $verse['transport'],
        ));

        $this->em->flush();
    }
}
