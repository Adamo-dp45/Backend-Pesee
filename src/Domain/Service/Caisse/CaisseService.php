<?php

namespace App\Domain\Service\Caisse;

use App\Domain\Enum\CompteMouvement;
use App\Domain\Enum\TypeCaisse;
use App\Domain\Enum\TypeMouvement;
use App\Entity\DemandeSolde;
use App\Entity\Entreprise;
use App\Entity\Interface\CompteInterface;
use App\Entity\MouvementCaisse;
use App\Entity\Paiement;
use App\Entity\Site;
use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Le SEUL endroit qui modifie un solde. Aucun processor n'appelle 'setSoldeDe()' directement : une écriture qui passerait à côté ne laisserait aucune trace, et le grand livre cesserait de correspondre aux soldes.

    - Chaque méthode VERROUILLE la ligne concernée ('SELECT … FOR UPDATE') avant de lire le solde. Sans ce verrou, deux paiements simultanés lisent le même solde, le trouvent suffisant tous les deux, et le site se retrouve à découvert.

    - Chaque écriture produit une ligne de grand livre portant 'soldeAvant' et 'soldeApres' : pour un même compte ET une même caisse, le solde d'arrivée d'une ligne doit être le solde de départ de la suivante. La caisse fait partie de la clé — deux caisses sur un même poste tiennent deux chaînes distinctes, qui ne s'entrecroisent jamais.

    - CHAQUE MOUVEMENT RESTE DANS SA CAISSE. Il n'existe aucun verbe pour passer des espèces au flotte mobile money ou l'inverse : cette conversion a lieu chez l'agrégateur ou à la banque, pas ici. Une dotation prend donc dans la caisse physique de l'entreprise pour remplir la caisse physique du poste, et pareillement pour le numérique.
*/
class CaisseService
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security
    )
    {
    }

    /**
     * Recharge une des deux caisses de l'entreprise. Saisie de l'administrateur, effet immédiat.
     */
    public function rechargerEntreprise(Entreprise $entreprise, TypeCaisse $caisse, int $montant, ?string $motif = null): MouvementCaisse
    {
        $this->exigerMontantPositif($montant);

        return $this->em->wrapInTransaction(function() use ($entreprise, $caisse, $montant, $motif): MouvementCaisse {
            $this->verrouiller($entreprise);

            return $this->ecrireEntreprise($entreprise, $caisse, TypeMouvement::RECHARGE, $montant, $motif);
        });
    }

    /**
     * Dote une caisse d'un pont bascule depuis la MÊME caisse de l'entreprise.
     *
     * Produit DEUX lignes : l'argent sort de l'entreprise et entre dans le site. Il se déplace, il ne se crée pas — c'est ce qui rend le rapprochement possible.
     *
     * @return array{0: MouvementCaisse, 1: MouvementCaisse}
     */
    public function doterSite(Site $site, TypeCaisse $caisse, int $montant, ?string $motif = null, ?DemandeSolde $demande = null): array
    {
        $this->exigerMontantPositif($montant);

        $entreprise = $site->getEntreprise();

        if($entreprise === null) {
            throw new UnprocessableEntityHttpException('Ce pont bascule n\'est rattaché à aucune entreprise.');
        }

        return $this->em->wrapInTransaction(function() use ($site, $entreprise, $caisse, $montant, $motif, $demande): array {
            $this->verrouiller($entreprise); /*
                - Toujours l'entreprise PUIS le site, jamais l'inverse : deux dotations simultanées qui verrouilleraient dans des ordres opposés se bloqueraient mutuellement
            */
            $this->verrouiller($site);

            $this->exigerProvision($entreprise, $caisse, $montant);

            $sortie = $this->ecrireEntreprise(
                $entreprise,
                $caisse,
                TypeMouvement::ATTRIBUTION,
                $montant,
                $motif ?? sprintf('Dotation de %s', (string) $site->getLibellesite()),
                $demande
            );

            $entree = $this->ecrireSite($site, $caisse, TypeMouvement::DOTATION, $montant, $motif, null, $demande);

            return [$sortie, $entree];
        });
    }

    /**
     * Réserve les fonds d'un versement : la caisse du site est débitée AVANT l'appel à la passerelle. Tant que le versement est en vol, la somme ne peut pas être engagée deux fois.
     *
     * La caisse débitée découle du MODE DE RÈGLEMENT, jamais d'un paramètre : des espèces sortent du tiroir, un versement mobile sort du flotte, et personne n'a à choisir.
     */
    public function debiterPourPaiement(Paiement $paiement): MouvementCaisse
    {
        $site = $paiement->getSite();
        $montant = $paiement->getMontant();
        $caisse = $paiement->getCaisse();

        $this->exigerMontantPositif($montant);

        if($site === null) {
            throw new UnprocessableEntityHttpException('Ce paiement n\'est rattaché à aucun pont bascule.');
        }

        return $this->em->wrapInTransaction(function() use ($site, $caisse, $paiement, $montant): MouvementCaisse {
            $this->verrouiller($site);

            $this->exigerProvision($site, $caisse, $montant);

            return $this->ecrireSite($site, $caisse, TypeMouvement::PAIEMENT, $montant, 'Paiement ' . $paiement->getReference(), $paiement);
        });
    }

    /**
     * Restitue les fonds d'un versement qui a échoué. Contrepartie exacte du débit — même caisse, même montant — jamais une annulation d'écriture : le grand livre ne se réécrit pas.
     */
    public function rembourserPaiement(Paiement $paiement, string $motif): MouvementCaisse
    {
        $site = $paiement->getSite();

        if($site === null) {
            throw new UnprocessableEntityHttpException('Ce paiement n\'est rattaché à aucun pont bascule.');
        }

        return $this->em->wrapInTransaction(function() use ($site, $paiement, $motif): MouvementCaisse {
            $this->verrouiller($site);

            return $this->ecrireSite($site, $paiement->getCaisse(), TypeMouvement::REMBOURSEMENT, $paiement->getMontant(), $motif, $paiement);
        });
    }

    /* ---- Écriture ------------------------------------------------------------------------- */

    private function ecrireEntreprise(Entreprise $entreprise, TypeCaisse $caisse, TypeMouvement $type, int $montant, ?string $motif, ?DemandeSolde $demande = null): MouvementCaisse
    {
        $avant = $entreprise->getSoldeDe($caisse);
        $apres = $avant + ($type->sens()->signe() * $montant);

        $entreprise->setSoldeDe($caisse, $apres);

        return $this->tracer(
            (new MouvementCaisse())
                ->setCompte(CompteMouvement::ENTREPRISE)
                ->setCaisse($caisse)
                ->setEntreprise($entreprise)
                ->setSens($type->sens())
                ->setType($type)
                ->setMontant($montant)
                ->setSoldeAvant($avant)
                ->setSoldeApres($apres)
                ->setMotif($motif)
                ->setDemandeSolde($demande)
        );
    }

    private function ecrireSite(Site $site, TypeCaisse $caisse, TypeMouvement $type, int $montant, ?string $motif, ?Paiement $paiement = null, ?DemandeSolde $demande = null): MouvementCaisse
    {
        $avant = $site->getSoldeDe($caisse);
        $apres = $avant + ($type->sens()->signe() * $montant);

        $site->setSoldeDe($caisse, $apres);

        return $this->tracer(
            (new MouvementCaisse())
                ->setCompte(CompteMouvement::SITE)
                ->setCaisse($caisse)
                ->setEntreprise($site->getEntreprise())
                ->setSite($site)
                ->setSens($type->sens())
                ->setType($type)
                ->setMontant($montant)
                ->setSoldeAvant($avant)
                ->setSoldeApres($apres)
                ->setMotif($motif)
                ->setPaiement($paiement)
                ->setDemandeSolde($demande)
        );
    }

    private function tracer(MouvementCaisse $mouvement): MouvementCaisse
    {
        $auteur = $this->security->getUser();

        if($auteur instanceof User) {
            $mouvement->setEffectuePar($auteur)->setCreatedBy($auteur->getId());
        }

        $this->em->persist($mouvement);
        $this->em->flush();

        return $mouvement;
    }

    /*
        - 'refresh' avec verrou plutôt que 'lock' seul : il pose le verrou ET relit la ligne. Un solde chargé plus tôt dans la requête serait périmé, et on validerait une dépense sur une valeur que la base n'a plus.
    */
    private function verrouiller(Entreprise|Site $compte): void
    {
        $this->em->refresh($compte, LockMode::PESSIMISTIC_WRITE);
    }

    /*
        - Le contrôle de provision, écrit une seule fois pour les deux niveaux : un découvert se constate de la même façon sur l'entreprise et sur un poste. Le message, lui, s'adresse à qui est devant l'écran — il nomme le compte, la CAISSE, ce qui manque et ce qu'il y a. Sans le nom de la caisse, un opérateur qui voit son tiroir plein ne comprend pas pourquoi son versement mobile est refusé.
    */
    private function exigerProvision(CompteInterface $compte, TypeCaisse $caisse, int $montant): void
    {
        $disponible = $compte->getSoldeDe($caisse);

        if($disponible >= $montant) {
            return;
        }

        throw new UnprocessableEntityHttpException(sprintf(
            '%s de %s insuffisante : %s F CFA demandés, %s F CFA disponibles.',
            $caisse->libelle(),
            $compte->getLibelleCompte(),
            number_format($montant, 0, ',', ' '),
            number_format($disponible, 0, ',', ' ')
        ));
    }

    private function exigerMontantPositif(int $montant): void
    {
        if($montant <= 0) {
            throw new UnprocessableEntityHttpException('Le montant doit être supérieur à zéro.');
        }
    }
}
