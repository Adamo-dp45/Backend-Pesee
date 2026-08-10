<?php

namespace App\Repository;

use App\Domain\Enum\StatutPaiement;
use App\Entity\Operation;
use App\Entity\Paiement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Paiement>
 */
class PaiementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Paiement::class);
    }

    /** Total réellement CONFIRMÉ sur une pesée. C'est lui qui alimente 'Operation::montantpaye'. */
    public function sommeVersee(Operation $operation): int
    {
        return $this->somme($operation, [StatutPaiement::REUSSI]);
    }

    /**
     * Total ENGAGÉ sur une pesée : confirmé, plus ce qui est encore en vol chez l'agrégateur.
     *
     * C'est ce montant qui borne un nouveau versement, et non le seul confirmé. Sinon, tant qu'un versement n'est pas confirmé, on pourrait en engager un second sur le même reste à payer et le planteur serait payé deux fois.
     */
    public function sommeEngagee(Operation $operation): int
    {
        // Étalement et non '+' : sur deux listes, l'opérateur '+' garde la gauche à clé égale et 'REUSSI' disparaîtrait
        return $this->somme($operation, [...StatutPaiement::enCours(), StatutPaiement::REUSSI]);
    }

    /**
     * Les versements restés en vol au-delà du délai, à réinterroger auprès de la passerelle.
     *
     * @return list<Paiement>
     */
    public function findARegulariser(\DateTimeImmutable $anterieurA): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.statut IN (:statuts)')
            ->andWhere('p.createdAt < :anterieurA')
            ->setParameter('statuts', StatutPaiement::enCours())
            ->setParameter('anterieurA', $anterieurA)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByReference(string $reference): ?Paiement
    {
        return $this->findOneBy(['reference' => $reference]);
    }

    /**
     * @param list<StatutPaiement> $statuts
     */
    private function somme(Operation $operation, array $statuts): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.montant), 0)')
            ->andWhere('p.operation = :operation')
            ->andWhere('p.statut IN (:statuts)')
            ->setParameter('operation', $operation)
            ->setParameter('statuts', $statuts)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Ce que chaque poste a déjà sorti de sa caisse sans confirmation de la passerelle. C'est
     * l'écart entre ce qu'un opérateur croit avoir et ce qu'il peut réellement engager.
     *
     * @param list<int> $siteIds
     *
     * @return array<int, int> 'id du site' => 'montant immobilisé'
     */
    public function montantsImmobilises(array $siteIds): array
    {
        if($siteIds === []) {
            return [];
        }

        $lignes = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.site) AS siteId', 'COALESCE(SUM(p.montant), 0) AS montant')
            ->andWhere('p.site IN (:sites)')
            ->andWhere('p.statut IN (:statuts)')
            ->setParameter('sites', $siteIds)
            ->setParameter('statuts', StatutPaiement::enCours())
            ->groupBy('siteId')
            ->getQuery()
            ->getArrayResult();

        $parSite = [];

        foreach($lignes as $ligne) {
            $parSite[(int) $ligne['siteId']] = (int) $ligne['montant'];
        }

        return $parSite;
    }
}
