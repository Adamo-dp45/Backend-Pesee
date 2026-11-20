<?php

namespace App\Repository;

use App\Entity\Entreprise;
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
     * Le référentiel d'une ENTREPRISE, indexé par clé normalisée.
     *
     * Une seule requête et un accès direct : la synchronisation rapproche des centaines de lignes, chercher en base pour chacune ferait autant d'allers-retours.
     *
     * Par entreprise et non par poste depuis que le produit lui appartient : deux ponts bascules qui pèsent du cacao pèsent LE même cacao, au même prix. C'est ce qui permet à un tarif de catégorie de ne se saisir qu'une fois.
     *
     * Les produits à la corbeille en sont écartés : si un poste renvoie un produit supprimé sur le web, une ligne neuve est créée et l'ancienne reste dans la corbeille. Il n'y a pas d'index unique en base pour s'y opposer, c'est 'UniqueParEntreprise' qui porte l'unicité et qui ne regarde lui aussi que les lignes vivantes.
     *
     * @return array<string, Produit>
     */
    public function parCle(Entreprise $entreprise): array
    {
        $produits = $this->createQueryBuilder('p')
            ->andWhere('p.entreprise = :entreprise')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('entreprise', $entreprise)
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
     * Un poste reçoit désormais TOUS les produits de son entreprise, y compris ceux qu'il ne pèse
     * jamais. C'est le prix à payer pour qu'un cacao soit un cacao partout, et le peseur garde de
     * toute façon la main : il choisit dans sa liste, il ne la subit pas.
     *
     * @return list<Produit>
     */
    public function findPourLePoste(Site $site): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.entreprise = :entreprise')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('entreprise', $site->getEntreprise())
            ->orderBy('p.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
