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
     * Ce qui est parti de la caisse sur chaque pesée sans être encore confirmé, pour TOUTE une page
     * de pesées en une requête.
     *
     * Le besoin vient de la file du guichet : 'montantpaye' ne compte que le confirmé, donc tant que
     * la passerelle n'a pas répondu, une pesée qu'on vient de payer y affiche le même reste
     * qu'avant. L'opérateur croit que son versement n'est pas parti et recommence — l'API refuse,
     * mais il a fallu en arriver là pour le savoir.
     *
     * @param list<int> $operationIds
     *
     * @return array<int, int> 'id de la pesée' => 'montant en vol'
     */
    public function montantsEnVolParPesee(array $operationIds): array
    {
        if($operationIds === []) {
            return [];
        }

        $lignes = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.operation) AS peseeId', 'COALESCE(SUM(p.montant), 0) AS montant')
            ->andWhere('p.operation IN (:pesees)')
            ->andWhere('p.statut IN (:statuts)')
            ->setParameter('pesees', $operationIds)
            ->setParameter('statuts', StatutPaiement::enCours())
            ->groupBy('peseeId')
            ->getQuery()
            ->getArrayResult();

        $parPesee = [];

        foreach($lignes as $ligne) {
            $parPesee[(int) $ligne['peseeId']] = (int) $ligne['montant'];
        }

        return $parPesee;
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

    /**
     * Ce qui est en vol chez l'agrégateur, et depuis quand.
     *
     * La RÉCONCILIATION est une commande planifiée : si le cron s'arrête, plus rien ne le dit. Les
     * versements restent en attente, les caisses restent amputées, et personne ne s'en aperçoit
     * avant qu'un opérateur ne puisse plus payer. Cette mesure rend la panne visible à l'écran :
     * un versement qui attend depuis des heures ne peut signifier qu'une chose.
     *
     * @param list<int> $siteIds
     *
     * @return array{nombre: int, depuis: ?\DateTimeImmutable}
     */
    public function versementsEnVol(array $siteIds): array
    {
        if($siteIds === []) {
            return ['nombre' => 0, 'depuis' => null];
        }

        $ligne = $this->createQueryBuilder('p')
            ->select('COUNT(p.id) AS nombre', 'MIN(p.createdAt) AS depuis')
            ->andWhere('p.site IN (:sites)')
            ->andWhere('p.statut IN (:statuts)')
            ->setParameter('sites', $siteIds)
            ->setParameter('statuts', StatutPaiement::enCours())
            ->getQuery()
            ->getSingleResult();

        // 'MIN()' sur une date revient en chaîne : Doctrine ne sait pas qu'agréger une date rend une date
        $depuis = $ligne['depuis'] === null ? null : new \DateTimeImmutable((string) $ligne['depuis']);

        return ['nombre' => (int) $ligne['nombre'], 'depuis' => $depuis];
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
