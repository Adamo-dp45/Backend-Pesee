<?php

namespace App\Repository;

use App\Entity\Site;
use App\Entity\SynchronisationLot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynchronisationLot>
 */
class SynchronisationLotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynchronisationLot::class);
    }

    /** Le dernier envoi d'un poste, pour repérer ceux qui se sont tus. */
    public function findDernierPourSite(Site $site): ?SynchronisationLot
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.site = :site')
            ->setParameter('site', $site)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
