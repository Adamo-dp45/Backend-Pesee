<?php

namespace App\Repository;

use App\Entity\Produit;
use App\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Produit>
 */
class ProduitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Produit::class);
    }

    /**
     * Le référentiel d'un site, indexé par clé normalisée.
     *
     * Une seule requête et un accès direct : la synchronisation rapproche des centaines de lignes, chercher en base pour chacune ferait autant d'allers-retours.
     *
     * Les produits à la corbeille en sont écartés : si le poste renvoie un produit supprimé sur le web, une ligne neuve est créée et l'ancienne reste dans la corbeille. Il n'y a pas d'index unique en base pour s'y opposer, c'est 'UniqueParSite' qui porte l'unicité et qui ne regarde lui aussi que les lignes vivantes.
     *
     * @return array<string, Produit>
     */
    public function parCle(Site $site): array
    {
        $produits = $this->createQueryBuilder('p')
            ->andWhere('p.site = :site')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('site', $site)
            ->getQuery()
            ->getResult();

        $indexes = [];

        foreach($produits as $produit) {
            $indexes[(string) $produit->getCle()] = $produit;
        }

        return $indexes;
    }

    /**
     * Le référentiel envoyé au poste, tel que son écran de pesée l'attend.
     *
     * @return list<Produit>
     */
    public function findPourLePoste(Site $site): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.site = :site')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('site', $site)
            ->orderBy('p.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
