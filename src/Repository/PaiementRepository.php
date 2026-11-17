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

    /**
     * Ce qui est réellement CONFIRMÉ sur une pesée, par part et au total. C'est lui qui alimente les trois cumuls dénormalisés de 'Operation'.
     *
     * @return array{produit: int, transport: int, total: int}
     */
    public function sommesVersees(Operation $operation): array
    {
        return $this->sommes($operation, [StatutPaiement::REUSSI]);
    }

    /**
     * Ce qui est ENGAGÉ sur une pesée, par part et au total : confirmé, plus ce qui est encore en vol chez l'agrégateur.
     *
     * Ce sont ces montants qui bornent un nouveau versement, et non les seuls confirmés. Sinon, tant qu'un versement n'est pas confirmé, on pourrait en engager un second sur le même reste à payer et le planteur serait payé deux fois.
     *
     * @return array{produit: int, transport: int, total: int}
     */
    public function sommesEngagees(Operation $operation): array
    {
        // Étalement et non '+' : sur deux listes, l'opérateur '+' garde la gauche à clé égale et 'REUSSI' disparaîtrait
        return $this->sommes($operation, [...StatutPaiement::enCours(), StatutPaiement::REUSSI]);
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
     * PAR PART : un versement en vol sur le transport ne doit pas amputer ce qu'on peut encore
     * engager sur la récolte, sans quoi l'API refuserait « un versement est déjà en cours » pour
     * une part sur laquelle rien n'est parti.
     *
     * @param list<int> $operationIds
     *
     * @return array<int, array{produit: int, transport: int}> 'id de la pesée' => ses deux montants en vol
     */
    public function montantsEnVolParPesee(array $operationIds): array
    {
        if($operationIds === []) {
            return [];
        }

        $lignes = $this->createQueryBuilder('p')
            ->select(
                'IDENTITY(p.operation) AS peseeId',
                'COALESCE(SUM(p.montantproduit), 0) AS produit',
                'COALESCE(SUM(p.montanttransport), 0) AS transport'
            )
            ->andWhere('p.operation IN (:pesees)')
            ->andWhere('p.statut IN (:statuts)')
            ->setParameter('pesees', $operationIds)
            ->setParameter('statuts', StatutPaiement::enCours())
            ->groupBy('peseeId')
            ->getQuery()
            ->getArrayResult();

        $parPesee = [];

        foreach($lignes as $ligne) {
            $parPesee[(int) $ligne['peseeId']] = [
                'produit' => (int) $ligne['produit'],
                'transport' => (int) $ligne['transport'],
            ];
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
    /*
        - UNE SEULE REQUÊTE pour les trois sommes, et c'est délibéré : trois requêtes laisseraient une
          fenêtre où un webhook confirme entre deux, et la base porterait un total différent de la
          somme de ses parts sans que rien ne le signale.
    */
    /**
     * @param list<StatutPaiement> $statuts
     *
     * @return array{produit: int, transport: int, total: int}
     */
    private function sommes(Operation $operation, array $statuts): array
    {
        /** @var array{produit: string|int|null, transport: string|int|null, total: string|int|null} $ligne */
        $ligne = $this->createQueryBuilder('p')
            ->select(
                'COALESCE(SUM(p.montantproduit), 0) AS produit',
                'COALESCE(SUM(p.montanttransport), 0) AS transport',
                'COALESCE(SUM(p.montant), 0) AS total'
            )
            ->andWhere('p.operation = :operation')
            ->andWhere('p.statut IN (:statuts)')
            ->setParameter('operation', $operation)
            ->setParameter('statuts', $statuts)
            ->getQuery()
            ->getSingleResult();

        return [
            'produit' => (int) $ligne['produit'],
            'transport' => (int) $ligne['transport'],
            'total' => (int) $ligne['total'],
        ];
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
