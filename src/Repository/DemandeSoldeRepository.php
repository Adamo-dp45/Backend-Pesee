<?php

namespace App\Repository;

use App\Domain\Enum\StatutDemande;
use App\Entity\DemandeSolde;
use App\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemandeSolde>
 */
class DemandeSoldeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemandeSolde::class);
    }

    /*
        - La demande qui bloque le site : tant qu'elle attend, on n'en dépose pas d'autre. Les demandes à la corbeille sont écartées à la main — les extensions Doctrine ne s'appliquent qu'aux requêtes d'API Platform, pas à celles qu'un processor lance lui-même.
    */
    public function findEnAttentePourSite(Site $site): ?DemandeSolde
    {
        return $this->findOneBy([
            'site' => $site,
            'statut' => StatutDemande::EN_ATTENTE,
            'deletedAt' => null,
        ]);
    }

    /**
     * Combien de demandes dans un état donné sur ces postes. Le tableau de la trésorerie s'en sert
     * pour annoncer ce qui attend une décision.
     *
     * @param list<int> $siteIds
     */
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
