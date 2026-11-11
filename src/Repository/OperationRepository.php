<?php

namespace App\Repository;

use App\Domain\Enum\StatutOperation;
use App\Entity\Operation;
use App\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Operation>
 */
class OperationRepository extends ServiceEntityRepository
{
    /*
        - Les champs libres que l'écran de pesée propose en liste déroulante, et sur lesquels son « Bilan standard » filtre. La carte est FERMÉE : le nom du champ vient d'ici et jamais d'une requête HTTP, sinon on injecterait n'importe quelle colonne dans le DQL.
    */
    public const REFERENTIELS = [
        'mouvement' => 'mouvement',
        'client' => 'client',
        'destination' => 'destination',
        'provenance' => 'provenance',
        'transporteur' => 'transporteur',
        'chauffeur' => 'chauffeur',
        'vehicule' => 'immatriculation',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Operation::class);
    }

    /**
     * Les pesées d'un lot, indexées par clé d'idempotence.
     *
     * En une requête : la synchronisation traite des centaines de lignes, les charger une par une ferait autant d'allers-retours.
     *
     * @param list<string> $codesecrets
     *
     * @return array<string, Operation>
     */
    public function parCodesecret(array $codesecrets): array
    {
        if($codesecrets === []) {
            return [];
        }

        $operations = $this->createQueryBuilder('o')
            ->andWhere('o.codesecret IN (:codesecrets)')
            ->setParameter('codesecrets', $codesecrets)
            ->getQuery()
            ->getResult();

        $indexees = [];

        foreach($operations as $operation) {
            $indexees[(string) $operation->getCodesecret()] = $operation;
        }

        return $indexees;
    }

    /**
     * Les pesées d'un site dont le montant peut encore bouger, pour un recalcul après changement de tarif. Les pesées déjà payées, même partiellement, en sont exclues.
     *
     * @return list<Operation>
     */
    public function findRecalculables(Site $site): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.site = :site')
            ->andWhere('o.deletedAt IS NULL')
            ->andWhere('o.montantpaye = 0')
            // Une pesée dont la retenue est arbitrée est dans le même état qu'une pesée déjà payée : une promesse a été écrite au planteur sur la base de ce montant, et aucun retrait n'existe pour la défaire
            ->andWhere('o.retenue IS NULL')
            ->setParameter('site', $site)
            ->getQuery()
            ->getResult();
    }

    /**
     * Valeurs distinctes d'un champ libre, pour les listes déroulantes du poste.
     *
     * @return list<array<string, string>>
     */
    public function getReferentiel(Site $site, string $nom): array
    {
        $champ = self::REFERENTIELS[$nom] ?? null;

        if($champ === null) {
            return [];
        }

        $lignes = $this->createQueryBuilder('o')
            ->select(sprintf('DISTINCT o.%s AS valeur', $champ))
            ->andWhere('o.site = :site')
            ->andWhere('o.deletedAt IS NULL')
            ->andWhere(sprintf('o.%s IS NOT NULL', $champ))
            ->setParameter('site', $site)
            ->orderBy('valeur', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn(array $ligne) => [$nom => (string) $ligne['valeur']], $lignes);
    }

    /**
     * Liste filtrée et paginée des pesées d'un pont bascule, pour l'écran « Bilan standard » du poste.
     *
     * @param array<string, string|null> $criteres
     *
     * @return array{rows: list<Operation>, total: int, poidsnet: int}
     */
    public function lister(Site $site, array $criteres, ?\DateTimeImmutable $du, ?\DateTimeImmutable $au, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.site = :site')
            ->andWhere('o.deletedAt IS NULL')
            ->andWhere('o.peseeAt2 IS NOT NULL') /*
                - L'écran d'origine ne listait que les pesées terminées ('date2 IS NOT NULL') et on garde ce comportement : un camion encore sur le pont n'a ni poids net ni montant, il n'a rien à faire dans un bilan
            */
            ->setParameter('site', $site)
            ->orderBy('o.peseeAt2', 'DESC')
            ->addOrderBy('o.id', 'DESC');

        if($du !== null) {
            $qb->andWhere('o.peseeAt2 >= :du')->setParameter('du', $du);
        }

        if($au !== null) {
            $qb->andWhere('o.peseeAt2 <= :au')->setParameter('au', $au);
        }

        foreach(self::REFERENTIELS as $nom => $champ) {
            if(!empty($criteres[$nom])) {
                $qb->andWhere(sprintf('o.%s = :critere_%s', $champ, $nom))->setParameter('critere_' . $nom, $criteres[$nom]);
            }
        }

        // Produit et planteur sont des entités : on filtre sur leur libellé, que le poste envoie
        if(!empty($criteres['produit'])) {
            $qb->join('o.produit', 'p')->andWhere('p.libelle = :critere_produit')->setParameter('critere_produit', $criteres['produit']);
        }

        if(!empty($criteres['fournisseur'])) {
            $qb->join('o.fournisseur', 'f')->andWhere('f.nom = :critere_fournisseur')->setParameter('critere_fournisseur', $criteres['fournisseur']);
        }

        $total = (int) (clone $qb)->select('COUNT(o.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $poidsnet = (int) (clone $qb)->select('COALESCE(SUM(o.poidsnet), 0)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        $rows = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['rows' => $rows, 'total' => $total, 'poidsnet' => $poidsnet];
    }

    /* ---- Agrégats du tableau de bord et du bilan ---------------------------------------------
       Toutes ces méthodes prennent la LISTE DES SITES AUTORISÉS, jamais l'entreprise : c'est
       'Perimetre' qui la calcule, et c'est ce qui restreint un opérateur à ses propres postes.

       Elles écrivent leur requête à la main et ne passent donc PAS par 'PerimetreExtension', qui ne
       s'applique qu'aux requêtes d'API Platform. Une liste vide doit donner un résultat VIDE,
       surtout pas l'absence de filtre : sans le 'return' anticipé, un compte sans périmètre verrait
       tout. */

    /**
     * Totaux de la période : volume pesé, argent dû, argent versé.
     *
     * @param list<int> $siteIds
     *
     * @return array{nbPesees: int, nbEnCours: int, tonnageNet: int, montantDu: int, montantPaye: int}
     */
    public function totaux(array $siteIds, ?\DateTimeImmutable $du, ?\DateTimeImmutable $au): array
    {
        $vide = ['nbPesees' => 0, 'nbEnCours' => 0, 'tonnageNet' => 0, 'montantDu' => 0, 'montantPaye' => 0];

        if($siteIds === []) {
            return $vide;
        }

        $ligne = $this->basePeriode($siteIds, $du, $au)
            ->select(
                'COUNT(o.id) AS nbPesees',
                'COALESCE(SUM(o.poidsnet), 0) AS tonnageNet',
                'COALESCE(SUM(o.montantdu), 0) AS montantDu',
                'COALESCE(SUM(o.montantpaye), 0) AS montantPaye'
            )
            ->getQuery()
            ->getSingleResult();

        // Les pesées en cours sont comptées à part : un camion encore sur le pont n'a ni poids net ni créance, l'inclure fausserait tous les totaux
        $enCours = (int) $this->baseSites($siteIds)
            ->select('COUNT(o.id)')
            ->andWhere('o.statut = :enCours')
            ->setParameter('enCours', StatutOperation::EN_COURS)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'nbPesees' => (int) $ligne['nbPesees'],
            'nbEnCours' => $enCours,
            'tonnageNet' => (int) $ligne['tonnageNet'],
            'montantDu' => (int) $ligne['montantDu'],
            'montantPaye' => (int) $ligne['montantPaye'],
        ];
    }

    /**
     * Tonnage jour par jour, pour la courbe du tableau de bord.
     *
     * @param list<int> $siteIds
     *
     * @return list<array{jour: string, tonnageNet: int, nbPesees: int}>
     */
    public function parJour(array $siteIds, ?\DateTimeImmutable $du, ?\DateTimeImmutable $au): array
    {
        if($siteIds === []) {
            return [];
        }

        $lignes = $this->basePeriode($siteIds, $du, $au)
            ->select(
                'SUBSTRING(o.peseeAt2, 1, 10) AS jour',
                'COALESCE(SUM(o.poidsnet), 0) AS tonnageNet',
                'COUNT(o.id) AS nbPesees'
            )
            ->groupBy('jour')
            ->orderBy('jour', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $l) => [
            'jour' => (string) $l['jour'],
            'tonnageNet' => (int) $l['tonnageNet'],
            'nbPesees' => (int) $l['nbPesees'],
        ], $lignes);
    }

    /**
     * Répartition par produit.
     *
     * @param list<int> $siteIds
     *
     * @return list<array{produit: string, tonnageNet: int, montantDu: int, nbPesees: int}>
     */
    public function parProduit(array $siteIds, ?\DateTimeImmutable $du, ?\DateTimeImmutable $au): array
    {
        if($siteIds === []) {
            return [];
        }

        $lignes = $this->basePeriode($siteIds, $du, $au)
            ->leftJoin('o.produit', 'p')
            ->select(
                "COALESCE(p.libelle, 'Sans produit') AS produit",
                'COALESCE(SUM(o.poidsnet), 0) AS tonnageNet',
                'COALESCE(SUM(o.montantdu), 0) AS montantDu',
                'COUNT(o.id) AS nbPesees'
            )
            ->groupBy('produit')
            ->orderBy('tonnageNet', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $l) => [
            'produit' => (string) $l['produit'],
            'tonnageNet' => (int) $l['tonnageNet'],
            'montantDu' => (int) $l['montantDu'],
            'nbPesees' => (int) $l['nbPesees'],
        ], $lignes);
    }

    /**
     * Répartition par pont bascule. C'est le cœur de la demande : voir depuis sa ville ce que produit chaque poste du territoire.
     *
     * @param list<int> $siteIds
     *
     * @return list<array{siteId: int, site: string, tonnageNet: int, montantDu: int, montantPaye: int, nbPesees: int}>
     */
    public function parSite(array $siteIds, ?\DateTimeImmutable $du, ?\DateTimeImmutable $au): array
    {
        if($siteIds === []) {
            return [];
        }

        $lignes = $this->basePeriode($siteIds, $du, $au)
            ->join('o.site', 's')
            ->select(
                's.id AS siteId',
                's.libellesite AS site',
                'COALESCE(SUM(o.poidsnet), 0) AS tonnageNet',
                'COALESCE(SUM(o.montantdu), 0) AS montantDu',
                'COALESCE(SUM(o.montantpaye), 0) AS montantPaye',
                'COUNT(o.id) AS nbPesees'
            )
            ->groupBy('s.id', 's.libellesite')
            ->orderBy('tonnageNet', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $l) => [
            'siteId' => (int) $l['siteId'],
            'site' => (string) $l['site'],
            'tonnageNet' => (int) $l['tonnageNet'],
            'montantDu' => (int) $l['montantDu'],
            'montantPaye' => (int) $l['montantPaye'],
            'nbPesees' => (int) $l['nbPesees'],
        ], $lignes);
    }

    /**
     * Croisement site × produit, qui alimente la page Bilan.
     *
     * @param list<int> $siteIds
     *
     * @return list<array{site: string, produit: string, tonnageNet: int, montantDu: int, montantPaye: int, nbPesees: int}>
     */
    public function parSiteEtProduit(array $siteIds, ?\DateTimeImmutable $du, ?\DateTimeImmutable $au): array
    {
        if($siteIds === []) {
            return [];
        }

        $lignes = $this->basePeriode($siteIds, $du, $au)
            ->join('o.site', 's')
            ->leftJoin('o.produit', 'p')
            ->select(
                's.libellesite AS site',
                "COALESCE(p.libelle, 'Sans produit') AS produit",
                'COALESCE(SUM(o.poidsnet), 0) AS tonnageNet',
                'COALESCE(SUM(o.montantdu), 0) AS montantDu',
                'COALESCE(SUM(o.montantpaye), 0) AS montantPaye',
                'COUNT(o.id) AS nbPesees'
            )
            ->groupBy('site', 'produit')
            ->orderBy('site', 'ASC')
            ->addOrderBy('tonnageNet', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $l) => [
            'site' => (string) $l['site'],
            'produit' => (string) $l['produit'],
            'tonnageNet' => (int) $l['tonnageNet'],
            'montantDu' => (int) $l['montantDu'],
            'montantPaye' => (int) $l['montantPaye'],
            'nbPesees' => (int) $l['nbPesees'],
        ], $lignes);
    }

    /**
     * Les dernières pesées reçues, pour l'aperçu du tableau de bord.
     *
     * @param list<int> $siteIds
     *
     * @return list<Operation>
     */
    public function dernieres(array $siteIds, int $limit = 10): array
    {
        if($siteIds === []) {
            return [];
        }

        return $this->baseSites($siteIds)
            ->andWhere('o.peseeAt2 IS NOT NULL')
            ->orderBy('o.peseeAt2', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Valeurs distinctes d'un champ libre sur tout le périmètre, pour les filtres du Bilan.
     *
     * @param list<int> $siteIds
     *
     * @return list<string>
     */
    public function valeursDistinctes(array $siteIds, string $nom): array
    {
        $champ = self::REFERENTIELS[$nom] ?? null; // La constante fait office de liste blanche : le nom de colonne est concaténé dans le DQL, il ne doit jamais venir d'une requête HTTP

        if($champ === null || $siteIds === []) {
            return [];
        }

        $lignes = $this->baseSites($siteIds)
            ->select(sprintf('DISTINCT o.%s AS valeur', $champ))
            ->andWhere(sprintf('o.%s IS NOT NULL', $champ))
            ->andWhere(sprintf("o.%s <> ''", $champ))
            ->orderBy('valeur', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn(array $l) => (string) $l['valeur'], $lignes);
    }

    /*
        - Le nombre de pesées de plusieurs référentiels EN UNE SEULE requête. C'est ce qui remplace le
          'COUNT' par ligne que faisait 'matching()' sur la collection : une liste de vingt-cinq
          produits lançait vingt-cinq requêtes, ici il en part une.

        - Le champ est choisi par une carte FERMÉE : il est concaténé dans le DQL, il ne doit jamais
          venir d'une requête HTTP.

        @param 'produit'|'fournisseur'|'site' $champ
        @param list<int> $ids

        @return array<int, int> 'id du référentiel' => 'nombre de pesées'
    */
    public function compterParReferentiel(string $champ, array $ids): array
    {
        if(!in_array($champ, ['produit', 'fournisseur', 'site'], true) || $ids === []) {
            return [];
        }

        $lignes = $this->createQueryBuilder('o')
            ->select(sprintf('IDENTITY(o.%s) AS ref', $champ), 'COUNT(o.id) AS nb')
            ->andWhere(sprintf('o.%s IN (:ids)', $champ))
            ->andWhere('o.deletedAt IS NULL')
            ->setParameter('ids', $ids)
            ->groupBy('ref')
            ->getQuery()
            ->getArrayResult();

        $compteurs = [];

        foreach($lignes as $ligne) {
            $compteurs[(int) $ligne['ref']] = (int) $ligne['nb'];
        }

        return $compteurs;
    }

    /** @param list<int> $siteIds */
    private function baseSites(array $siteIds): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.site IN (:sites)')
            ->andWhere('o.deletedAt IS NULL')
            ->setParameter('sites', $siteIds);
    }

    /* Base commune des agrégats : uniquement les pesées TERMINÉES, et bornées à la période. */
    /** @param list<int> $siteIds */
    private function basePeriode(array $siteIds, ?\DateTimeImmutable $du, ?\DateTimeImmutable $au): QueryBuilder
    {
        $qb = $this->baseSites($siteIds)
            ->andWhere('o.statut = :terminee')
            ->setParameter('terminee', StatutOperation::TERMINEE);

        if($du !== null) {
            $qb->andWhere('o.peseeAt2 >= :du')->setParameter('du', $du);
        }

        if($au !== null) {
            $qb->andWhere('o.peseeAt2 <= :au')->setParameter('au', $au);
        }

        return $qb;
    }
}
