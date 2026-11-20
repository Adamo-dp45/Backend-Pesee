<?php

namespace App\Repository;

use App\Entity\MouvementCaisse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MouvementCaisse>
 */
class MouvementCaisseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MouvementCaisse::class);
    }

    /*
        - CE QUI EST ENTRÉ ET SORTI de chaque caisse sur une période, en une requête. Le sens est
          dans le groupement et non dans un signe : le montant d'un mouvement est toujours positif,
          c'est 'sens' qui porte la direction, et les additionner sans distinguer donnerait un
          nombre qui ne veut rien dire.

        - Le compte y est aussi, parce qu'une caisse « physique » n'est pas la même selon qu'elle
          est au coffre de l'entreprise ou dans le tiroir d'un opérateur. Les confondre ferait
          compter deux fois l'argent d'une dotation, qui sort de l'une pour entrer dans l'autre.

        @param list<int> $siteIds

        @return list<array{compte: string, caisse: string, sens: string, nombre: int, montant: int}>
     */
    public function bilanParCaisse(array $siteIds, ?int $entrepriseId, ?\DateTimeImmutable $du, ?\DateTimeImmutable $au): array
    {
        $base = $this->basePeriode($siteIds, $entrepriseId, $du, $au);

        if($base === null) {
            return [];
        }

        $lignes = $base
            ->select(
                'm.compte AS compte',
                'm.caisse AS caisse',
                'm.sens AS sens',
                'COUNT(m.id) AS nombre',
                'COALESCE(SUM(m.montant), 0) AS montant'
            )
            ->groupBy('compte')
            ->addGroupBy('caisse')
            ->addGroupBy('sens')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $l) => [
            'compte' => self::valeur($l['compte']),
            'caisse' => self::valeur($l['caisse']),
            'sens' => self::valeur($l['sens']),
            'nombre' => (int) $l['nombre'],
            'montant' => (int) $l['montant'],
        ], $lignes);
    }

    /*
        - CE QUI A FAIT BOUGER L'ARGENT : dotations, versements, remboursements, recharges. C'est la
          ventilation qui répond à « où est passé l'argent du poste », là où la précédente répond à
          « dans quelle caisse ».

        @param list<int> $siteIds

        @return list<array{type: string, sens: string, nombre: int, montant: int}>
     */
    public function bilanParType(array $siteIds, ?int $entrepriseId, ?\DateTimeImmutable $du, ?\DateTimeImmutable $au): array
    {
        $base = $this->basePeriode($siteIds, $entrepriseId, $du, $au);

        if($base === null) {
            return [];
        }

        $lignes = $base
            ->select(
                'm.type AS type',
                'm.sens AS sens',
                'COUNT(m.id) AS nombre',
                'COALESCE(SUM(m.montant), 0) AS montant'
            )
            ->groupBy('type')
            ->addGroupBy('sens')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $l) => [
            'type' => self::valeur($l['type']),
            'sens' => self::valeur($l['sens']),
            'nombre' => (int) $l['nombre'],
            'montant' => (int) $l['montant'],
        ], $lignes);
    }

    /*
        - Le même mouvement, vu par pont bascule. Les écritures du compte de l'entreprise n'ont pas
          de site : elles sont exclues d'office par la jointure, et c'est voulu — elles ont leur
          ligne à part dans la ventilation par caisse.

        @param list<int> $siteIds

        @return list<array{siteId: int, site: string, caisse: string, sens: string, montant: int}>
     */
    public function bilanParSite(array $siteIds, ?\DateTimeImmutable $du, ?\DateTimeImmutable $au): array
    {
        $base = $this->basePeriode($siteIds, null, $du, $au);

        if($base === null) {
            return [];
        }

        $lignes = $base
            ->join('m.site', 's')
            ->select(
                'IDENTITY(m.site) AS siteId',
                's.libellesite AS site',
                'm.caisse AS caisse',
                'm.sens AS sens',
                'COALESCE(SUM(m.montant), 0) AS montant'
            )
            ->groupBy('siteId')
            ->addGroupBy('site')
            ->addGroupBy('caisse')
            ->addGroupBy('sens')
            ->orderBy('site', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $l) => [
            'siteId' => (int) $l['siteId'],
            'site' => (string) $l['site'],
            'caisse' => self::valeur($l['caisse']),
            'sens' => self::valeur($l['sens']),
            'montant' => (int) $l['montant'],
        ], $lignes);
    }

    /*
        - LE PÉRIMÈTRE EST ÉCRIT ICI, À LA MAIN. Ces agrégats ne passent pas par les extensions
          Doctrine d'API Platform, qui ne s'appliquent qu'aux requêtes de ressource : les oublier
          ne lève aucune erreur, ça fait simplement sortir les chiffres d'un autre client.

        - Deux familles d'écritures, et la seconde n'a pas de site : celles du compte de
          l'entreprise. Un opérateur ne les voit pas — d'où l'entreprise facultative, que seul
          l'appelant sait accorder.

        @param list<int> $siteIds
     */
    private function basePeriode(array $siteIds, ?int $entrepriseId, ?\DateTimeImmutable $du, ?\DateTimeImmutable $au): ?QueryBuilder
    {
        if($siteIds === [] && $entrepriseId === null) {
            return null;
        }

        $qb = $this->createQueryBuilder('m');

        if($siteIds !== [] && $entrepriseId !== null) {
            $qb->andWhere('m.site IN (:sites) OR (m.site IS NULL AND m.entreprise = :entreprise)')
                ->setParameter('sites', $siteIds)
                ->setParameter('entreprise', $entrepriseId);
        } elseif($siteIds !== []) {
            $qb->andWhere('m.site IN (:sites)')->setParameter('sites', $siteIds);
        } else {
            $qb->andWhere('m.site IS NULL')
                ->andWhere('m.entreprise = :entreprise')
                ->setParameter('entreprise', $entrepriseId);
        }

        if($du !== null) {
            $qb->andWhere('m.createdAt >= :du')->setParameter('du', $du);
        }

        if($au !== null) {
            $qb->andWhere('m.createdAt <= :au')->setParameter('au', $au);
        }

        return $qb;
    }

    /*
        - Doctrine rend un champ 'enumType' tantôt converti, tantôt brut selon le mode d'hydratation.
          Les agrégats passent par 'getArrayResult()', où la conversion n'est pas garantie.
    */
    private static function valeur(mixed $valeur): string
    {
        return $valeur instanceof \BackedEnum ? (string) $valeur->value : (string) $valeur;
    }
}
