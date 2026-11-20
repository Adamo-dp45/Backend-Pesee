<?php

namespace App\Repository;

use App\Entity\CategorieFournisseur;
use App\Entity\Entreprise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CategorieFournisseur>
 */
class CategorieFournisseurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CategorieFournisseur::class);
    }

    /**
     * Les catégories d'une entreprise, leurs tarifs chargés avec elles.
     *
     * La jointure explicite évite une requête par catégorie au moment de résoudre un prix : la
     * valorisation d'un lot de deux cents pesées retombe autrement sur autant d'allers-retours.
     *
     * @return list<CategorieFournisseur>
     */
    public function avecTarifs(Entreprise $entreprise): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('t', 'p')
            ->leftJoin('c.tarifs', 't', 'WITH', 't.deletedAt IS NULL')
            ->leftJoin('t.produit', 'p')
            ->andWhere('c.entreprise = :entreprise')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('entreprise', $entreprise)
            ->orderBy('c.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
