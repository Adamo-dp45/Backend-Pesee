<?php

namespace App\Repository;

use App\Entity\CompteEpargne;
use App\Entity\Entreprise;
use App\Entity\Fournisseur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompteEpargne>
 */
class CompteEpargneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompteEpargne::class);
    }

    /*
        - Les comptes d'un lot de planteurs, en UNE requête et indexés par identifiant de planteur.
          Même mécanique que 'FournisseurRepository::pourLaSynchronisation()' : la synchronisation traite des lots
          entiers, et aller chercher le compte planteur par planteur rendrait une requête par ligne.
    */
    /**
     * @param list<int> $fournisseurIds
     *
     * @return array<int, CompteEpargne>
     */
    public function parFournisseurs(array $fournisseurIds): array
    {
        if($fournisseurIds === []) {
            return [];
        }

        $comptes = $this->createQueryBuilder('c')
            ->andWhere('c.fournisseur IN (:ids)')
            ->setParameter('ids', $fournisseurIds)
            ->getQuery()
            ->getResult();

        $parFournisseur = [];

        foreach($comptes as $compte) {
            $parFournisseur[(int) $compte->getFournisseur()?->getId()] = $compte;
        }

        return $parFournisseur;
    }

    public function pourFournisseur(Fournisseur $fournisseur): ?CompteEpargne
    {
        return $this->findOneBy(['fournisseur' => $fournisseur]);
    }

    /*
        - Ce que l'entreprise doit à ses planteurs. Sans retrait, ce total ne fait que grossir, et
          c'est le seul endroit où l'administrateur le verra : il n'apparaît dans aucune caisse,
          puisqu'aucun billet n'a bougé.
    */
    public function totalDu(Entreprise $entreprise): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COALESCE(SUM(c.solde), 0)')
            ->andWhere('c.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
