<?php

namespace App\Repository;

use App\Entity\Fournisseur;
use App\Entity\Prelevement;
use App\Entity\Produit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Prelevement>
 */
class PrelevementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Prelevement::class);
    }

    /*
        - Le prélèvement en cours d'un planteur SUR UN PRODUIT, ou null. Il n'y en a jamais plus d'un :
          les processors refusent d'en ouvrir un second, et le plus récent l'emporterait de toute
          façon — l'ordre explicite évite qu'une collision laisse l'arbitrage dépendre de l'ordre des
          lignes en base.

        - 'deletedAt IS NULL' à la main : les extensions Doctrine ne s'appliquent qu'aux requêtes
          d'API Platform, et c'est un processor qui appelle ceci.
    */
    public function findActifPour(Fournisseur $fournisseur, Produit $produit): ?Prelevement
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.fournisseur = :fournisseur')
            ->andWhere('p.produit = :produit')
            ->andWhere('p.arreteAt IS NULL')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('fournisseur', $fournisseur)
            ->setParameter('produit', $produit)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Tous les prélèvements en cours d'un planteur, du plus récent au plus ancien.
     *
     * @return list<Prelevement>
     */
    public function findActifsDe(Fournisseur $fournisseur): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.fournisseur = :fournisseur')
            ->andWhere('p.arreteAt IS NULL')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('fournisseur', $fournisseur)
            ->orderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
