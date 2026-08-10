<?php

namespace App\Domain\Service\Caisse;

use App\Domain\Enum\CompteMouvement;
use App\Domain\Enum\TypeMouvement;
use App\Entity\DemandeSolde;
use App\Entity\Entreprise;
use App\Entity\MouvementCaisse;
use App\Entity\Paiement;
use App\Entity\Site;
use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Le SEUL endroit qui modifie un solde. Aucun processor n'appelle 'setSolde()' directement : une écriture qui passerait à côté ne laisserait aucune trace, et le grand livre cesserait de correspondre aux soldes.

    - Chaque méthode VERROUILLE la ligne concernée ('SELECT … FOR UPDATE') avant de lire le solde. Sans ce verrou, deux paiements simultanés lisent le même solde, le trouvent suffisant tous les deux, et le site se retrouve à découvert.

    - Chaque écriture produit une ligne de grand livre portant 'soldeAvant' et 'soldeApres' : pour un même compte, le solde d'arrivée d'une ligne doit être le solde de départ de la suivante.
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
     * Recharge le solde global de l'entreprise. Saisie de l'administrateur, effet immédiat.
     */
    public function rechargerEntreprise(Entreprise $entreprise, int $montant, ?string $motif = null): MouvementCaisse
    {
        $this->exigerMontantPositif($montant);

        return $this->em->wrapInTransaction(function() use ($entreprise, $montant, $motif): MouvementCaisse {
            $this->verrouiller($entreprise);

            return $this->ecrireEntreprise($entreprise, TypeMouvement::RECHARGE, $montant, $motif);
        });
    }

    /**
     * Dote la caisse d'un pont bascule depuis le solde de l'entreprise.
     *
     * Produit DEUX lignes : l'argent sort de l'entreprise et entre dans le site. Il se déplace, il ne se crée pas — c'est ce qui rend le rapprochement possible.
     *
     * @return array{0: MouvementCaisse, 1: MouvementCaisse}
     */
    public function doterSite(Site $site, int $montant, ?string $motif = null, ?DemandeSolde $demande = null): array
    {
        $this->exigerMontantPositif($montant);

        $entreprise = $site->getEntreprise();

        if($entreprise === null) {
            throw new UnprocessableEntityHttpException('Ce pont bascule n\'est rattaché à aucune entreprise.');
        }

        return $this->em->wrapInTransaction(function() use ($site, $entreprise, $montant, $motif, $demande): array {
            $this->verrouiller($entreprise); /*
                - Toujours l'entreprise PUIS le site, jamais l'inverse : deux dotations simultanées qui verrouilleraient dans des ordres opposés se bloqueraient mutuellement
            */
            $this->verrouiller($site);

            if($entreprise->getSolde() < $montant) {
                throw new UnprocessableEntityHttpException(sprintf(
                    'Solde de l\'entreprise insuffisant : %s F CFA demandés, %s F CFA disponibles.',
                    number_format($montant, 0, ',', ' '),
                    number_format($entreprise->getSolde(), 0, ',', ' ')
                )); // Le message s'adresse à qui est devant l'écran : il annonce ce qui manque, pas un code
            }

            $sortie = $this->ecrireEntreprise(
                $entreprise,
                TypeMouvement::ATTRIBUTION,
                $montant,
                $motif ?? 'Attribution au pont bascule ' . $site->getCode(),
                $demande
            );

            $entree = $this->ecrireSite(
                $site,
                TypeMouvement::DOTATION,
                $montant,
                $motif ?? 'Dotation reçue de l\'entreprise',
                null,
                $demande
            );

            return [$sortie, $entree];
        });
    }

    /**
     * Réserve les fonds d'un versement : la caisse du site est débitée AVANT l'appel à la passerelle. Tant que le versement est en vol, la somme ne peut pas être engagée deux fois.
     */
    public function debiterPourPaiement(Paiement $paiement): MouvementCaisse
    {
        $site = $paiement->getSite();
        $montant = $paiement->getMontant();

        $this->exigerMontantPositif($montant);

        if($site === null) {
            throw new UnprocessableEntityHttpException('Ce paiement n\'est rattaché à aucun pont bascule.');
        }

        return $this->em->wrapInTransaction(function() use ($site, $paiement, $montant): MouvementCaisse {
            $this->verrouiller($site);

            if($site->getSolde() < $montant) {
                throw new UnprocessableEntityHttpException(sprintf(
                    'Caisse de %s insuffisante : %s F CFA demandés, %s F CFA disponibles.',
                    (string) $site->getLibellesite(),
                    number_format($montant, 0, ',', ' '),
                    number_format($site->getSolde(), 0, ',', ' ')
                ));
            }

            return $this->ecrireSite($site, TypeMouvement::PAIEMENT, $montant, 'Paiement ' . $paiement->getReference(), $paiement);
        });
    }

    /**
     * Restitue les fonds d'un versement qui a échoué. Contrepartie exacte du débit, jamais une annulation d'écriture : le grand livre ne se réécrit pas.
     */
    public function rembourserPaiement(Paiement $paiement, string $motif): MouvementCaisse
    {
        $site = $paiement->getSite();

        if($site === null) {
            throw new UnprocessableEntityHttpException('Ce paiement n\'est rattaché à aucun pont bascule.');
        }

        return $this->em->wrapInTransaction(function() use ($site, $paiement, $motif): MouvementCaisse {
            $this->verrouiller($site);

            return $this->ecrireSite($site, TypeMouvement::REMBOURSEMENT, $paiement->getMontant(), $motif, $paiement);
        });
    }

    /* ---- Écriture ------------------------------------------------------------------------- */

    private function ecrireEntreprise(Entreprise $entreprise, TypeMouvement $type, int $montant, ?string $motif, ?DemandeSolde $demande = null): MouvementCaisse
    {
        $avant = $entreprise->getSolde();
        $apres = $avant + ($type->sens()->signe() * $montant);

        $entreprise->setSolde($apres);

        return $this->tracer(
            (new MouvementCaisse())
                ->setCompte(CompteMouvement::ENTREPRISE)
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

    private function ecrireSite(Site $site, TypeMouvement $type, int $montant, ?string $motif, ?Paiement $paiement = null, ?DemandeSolde $demande = null): MouvementCaisse
    {
        $avant = $site->getSolde();
        $apres = $avant + ($type->sens()->signe() * $montant);

        $site->setSolde($apres);

        return $this->tracer(
            (new MouvementCaisse())
                ->setCompte(CompteMouvement::SITE)
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

    private function exigerMontantPositif(int $montant): void
    {
        if($montant <= 0) {
            throw new UnprocessableEntityHttpException('Le montant doit être supérieur à zéro.');
        }
    }
}
