<?php

namespace App\Repository;

use App\Entity\Entreprise;
use App\Entity\Site;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Site>
 */
class SiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Site::class);
    }

    /*
        - Les deux requêtes qui alimentent 'Perimetre'. Elles ne renvoient que des identifiants :
          le périmètre sert de clause 'IN', hydrater les entités ne servirait à rien.
    */

    /** @return list<int> */
    public function idsParEntreprise(Entreprise $entreprise): array
    {
        return $this->ids('site.entreprise = :critere', $entreprise);
    }

    /** @return list<int> */
    public function idsParOperateur(User $operateur): array
    {
        return $this->ids('site.operateur = :critere', $operateur);
    }

    /** @return list<int> */
    private function ids(string $condition, Entreprise|User $critere): array
    {
        $lignes = $this->createQueryBuilder('site')
            ->select('site.id')
            ->andWhere($condition)
            ->andWhere('site.deletedAt IS NULL')
            ->setParameter('critere', $critere)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn(array $ligne) => (int) $ligne['id'], $lignes);
    }
}
