<?php

namespace App\Repository;

use App\Domain\Enum\StatutDemande;
use App\Entity\DemandeRetrait;
use App\Entity\Fournisseur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemandeRetrait>
 */
class DemandeRetraitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemandeRetrait::class);
    }

    /*
        - La demande qui attend encore sur ce planteur, s'il y en a une. C'est elle qui bloque un
          second dépôt : deux demandes accordées coup sur coup autorisent deux versements qui,
          ensemble, peuvent dépasser l'épargne — et c'est alors le guichet qui refuse, devant le
          planteur, pour une décision prise au bureau.

        - 'deletedAt' est filtré À LA MAIN : les extensions Doctrine ne s'appliquent qu'aux requêtes
          d'API Platform, pas à celles qu'un processor lance lui-même.
    */
    public function findEnAttentePourFournisseur(Fournisseur $fournisseur): ?DemandeRetrait
    {
        return $this->findOneBy([
            'fournisseur' => $fournisseur,
            'statut' => StatutDemande::EN_ATTENTE,
            'deletedAt' => null,
        ]);
    }

    /*
        - Les demandes accordées qu'aucun versement n'a encore servies, pour un planteur. Elles
          immobilisent son épargne sans l'avoir débitée : le guichet doit pouvoir les retrouver, et
          le dépôt d'une nouvelle demande doit en tenir compte.

        @return list<DemandeRetrait>
    */
    public function findAccordeesNonServies(Fournisseur $fournisseur): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.fournisseur = :fournisseur')
            ->andWhere('d.statut = :statut')
            ->andWhere('d.paiement IS NULL')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('fournisseur', $fournisseur)
            ->setParameter('statut', StatutDemande::APPROUVEE)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /*
        - COMBIEN ATTENDENT UNE DÉCISION, pour le tableau de bord. Le périmètre passe par le SITE
          que la demande porte elle-même, figé au dépôt.

        - EN_ATTENTE SEULEMENT, et c'est une vraie restriction ici : un retrait a trois temps —
          l'opérateur demande, l'administrateur accorde, l'opérateur verse. Une demande APPROUVÉE
          mais pas encore servie n'attend plus de décision, elle attend un guichet, et la compter
          avec les autres enverrait l'administrateur chercher ce qu'il a déjà tranché.
    */
    /** @param list<int> $siteIds */
    public function compterParStatut(array $siteIds, StatutDemande $statut): int
    {
        if($siteIds === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.site IN (:sites)')
            ->andWhere('d.statut = :statut')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('sites', $siteIds)
            ->setParameter('statut', $statut)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
