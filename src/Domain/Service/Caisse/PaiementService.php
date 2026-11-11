<?php

namespace App\Domain\Service\Caisse;

use App\Domain\Enum\ModeReglement;
use App\Domain\Enum\ReseauMobile;
use App\Domain\Enum\StatutPaiement;
use App\Domain\Enum\StatutReglement;
use App\Domain\Paiement\PasserellePaiementInterface;
use App\Domain\Paiement\ResultatVersement;
use App\Domain\Support\NumeroMobile;
use App\Entity\Operation;
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
        private MailerService $mailer,
        private LoggerInterface $logger
    )
    {
    }

    public function initier(Operation $pesee, int $montant, ModeReglement $mode, User $initiePar, ?string $numeroDestinataire = null, ?ReseauMobile $reseau = null): Paiement
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

        /*
            - Ce qui interdit TOUT règlement, avant même de regarder le mode : la suspension du planteur. Elle n'a rien à voir avec un numéro manquant — l'un dit qu'on ne peut pas le joindre, l'autre qu'on ne doit pas le payer. Ne contrôler que le second rouvrait en espèces la porte que la suspension venait de fermer.
        */
        if(($bloquant = $planteur->getMotifBloquant()) !== null) {
            throw new UnprocessableEntityHttpException($bloquant);
        }

        /*
            - TOUT CE QUI SUIT NE CONCERNE QUE LE MOBILE MONEY. Des espèces ne vont à aucun numéro : exiger un numéro de téléphone pour remettre des billets à quelqu'un qui est devant soi n'aurait servi qu'à rendre impayables les planteurs qui n'en ont pas — c'est-à-dire précisément ceux qu'on règle en liquide.
            - Le numéro et le réseau transmis sont donc ignorés hors du mobile money, plutôt que refusés : un formulaire qui garde ses champs cachés remplis ne doit pas faire échouer un versement.
        */
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

        /*
            - Une pesée qui ne vaut rien se refuse AVANT d'être comparée à ce qui a déjà été engagé : sans montant dû, les deux termes de la soustraction ci-dessous tombent à zéro et la pesée était annoncée « déjà soldée » alors que pas un franc n'était parti. Le motif, lui, nomme ce qu'il faut corriger — le plus souvent un produit créé par le poste et jamais tarifé.
        */
        $du = (int) $pesee->getMontantdu();

        if($du <= 0) {
            throw new UnprocessableEntityHttpException(
                $pesee->getMotifNonPayable() ?? 'Cette pesée n\'est pas valorisée : son montant est nul.'
            );
        }

        /*
            - Ce qu'on peut VERSER, et non ce que la pesée vaut : la part mise en compte sur l'épargne du planteur ne lui sera pas remise au guichet. Elle est déjà écrite à son nom dans le livre d'épargne — la verser une seconde fois au guichet reviendrait à la payer deux fois.
            - Refusé AVANT la soustraction qui suit, pour la raison déjà donnée plus haut à propos d'un prix nul : sans cela les deux termes tombent à zéro et la pesée est annoncée « déjà soldée » alors que pas un franc n'est parti.
        */
        $payable = $pesee->getMontantpayable();

        if($payable <= 0) {
            throw new UnprocessableEntityHttpException(
                $pesee->getMotifNonPayable() ?? 'Cette pesée est intégralement retenue sur l\'épargne du planteur.'
            );
        }

        /*
            - On borne sur ce qui est ENGAGÉ et non sur ce qui est confirmé : un versement encore en vol chez l'agrégateur a déjà quitté la caisse, et rien ne dit qu'il échouera. Ne compter que le confirmé permettrait d'engager deux fois le même reste à payer, et de payer le planteur en double.
            - C'EST LA SEULE LIGNE DE L'APPLICATION OÙ LA BORNE D'UN VERSEMENT SE DÉCIDE. Tout le reste peut être juste : si elle repartait du montant dû, le guichet verserait l'épargne avec le reste.
        */
        $reste = max(0, $payable - $this->paiementRepository->sommeEngagee($pesee));

        // Passé le contrôle ci-dessus, un reste nul ne peut plus venir que de l'argent : soldée, ou déjà engagée
        if($reste <= 0) {
            throw new UnprocessableEntityHttpException(
                $pesee->getResteapayer() <= 0
                    ? 'Cette pesée est déjà soldée.'
                    : 'Un versement est déjà en cours sur cette pesée pour le montant restant.'
            );
        }

        if($montant > $reste) {
            throw new UnprocessableEntityHttpException(sprintf('Montant supérieur au reste à payer : %s F CFA au maximum.', number_format($reste, 0, ',', ' ')));
        }

        $paiement = (new Paiement())
            ->setReference(Paiement::genererReference())
            ->setMontant($montant)
            ->setModeReglement($mode)
            ->setOperation($pesee)
            ->setFournisseur($planteur)
            ->setSite($site)
            ->setNumeroDestinataire($numero)
            ->setReseau($mode->passeParLaPasserelle() ? ($reseau ?? $planteur->getReseau()) : null)
            // Qui a traité le versement. Hors passerelle, c'est le guichet lui-même : une recherche a posteriori doit pouvoir distinguer les deux
            ->setGateway($mode->passeParLaPasserelle() ? $this->passerelle->nom() : 'caisse')
            ->setStatut(StatutPaiement::EN_ATTENTE)
            ->setInitiePar($initiePar);

        $paiement->setCreatedBy($initiePar->getId());

        /*
            - Le versement et la réservation des fonds dans la MÊME transaction. Écrire le paiement d'abord puis débiter laissait, quand la caisse est insuffisante, une ligne en 'EN_ATTENTE' que rien ne rattrapait : elle comptait dans 'sommeEngagee()' — donc gelait une part du reste à payer pour toujours — et la réconciliation aurait fini par la faire confirmer par la passerelle, créditant un planteur sans qu'un franc soit sorti.
            - Le contrôle du solde reste dans 'debiterPourPaiement()', sous verrou : le vérifier ici en plus serait à la fois redondant et sujet à la course.
        */
        $this->em->wrapInTransaction(function() use ($paiement): void {
            $this->em->persist($paiement);

            $this->caisse->debiterPourPaiement($paiement); // Au-delà de ce point, la somme est sortie de la caisse du site
        });

        /*
            - Les espèces s'arrêtent ici. Les billets ont changé de main pendant que l'opérateur saisissait l'écran : il n'y a personne à interroger, rien qui puisse échouer plus tard, et donc rien à réconcilier. Le versement est confirmé dans la foulée, et 'recalculerLeReglement()' fait tomber le reste à payer immédiatement.
            - Le faire passer par 'appliquerResultat()' comme les autres n'est pas une coquetterie : c'est lui qui tient 'montantpaye' et le statut de règlement. Court-circuiter ce chemin aurait donné une seconde façon de solder une pesée, et deux façons finissent par diverger.
        */
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
        $verse = $this->paiementRepository->sommeVersee($pesee);

        $pesee
            ->setMontantpaye($verse)
            ->setStatutReglement(StatutReglement::pour($pesee->getMontantdu(), $pesee->getMontantpayable(), $verse));

        $this->em->flush();
    }
}
