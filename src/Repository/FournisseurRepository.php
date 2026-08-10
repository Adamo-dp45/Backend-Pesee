<?php

namespace App\Repository;

use App\Entity\Fournisseur;
use App\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Fournisseur>
 */
class FournisseurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Fournisseur::class);
    }

    /**
     * Le référentiel d'un site, indexé par clé normalisée. Voir 'ProduitRepository::parCle()'.
     *
     * @return array<string, Fournisseur>
     */
    public function parCle(Site $site): array
    {
        $fournisseurs = $this->createQueryBuilder('f')
            ->andWhere('f.site = :site')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('site', $site)
            ->getQuery()
            ->getResult();

        $indexes = [];

        foreach($fournisseurs as $fournisseur) {
            $indexes[(string) $fournisseur->getCle()] = $fournisseur;
        }

        return $indexes;
    }

    /**
     * Les seuls planteurs d'un lot, indexés par clé.
     *
     * Contrairement aux produits — quelques dizaines par poste — les planteurs se comptent en milliers sur un gros site : on ne charge que ceux présents dans l'envoi.
     *
     * Ceux à la corbeille en sont écartés, comme dans 'ProduitRepository::parCle()'.
     *
     * @param list<string> $cles
     *
     * @return array<string, Fournisseur>
     */
    public function parCles(Site $site, array $cles): array
    {
        if($cles === []) {
            return [];
        }

        $fournisseurs = $this->createQueryBuilder('f')
            ->andWhere('f.site = :site')
            ->andWhere('f.cle IN (:cles)')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('site', $site)
            ->setParameter('cles', $cles)
            ->getQuery()
            ->getResult();

        $indexes = [];

        foreach($fournisseurs as $fournisseur) {
            $indexes[(string) $fournisseur->getCle()] = $fournisseur;
        }

        return $indexes;
    }

    /**
     * @return list<Fournisseur>
     */
    public function findPourLePoste(Site $site): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.site = :site')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('site', $site)
            ->orderBy('f.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
