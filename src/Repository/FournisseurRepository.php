<?php

namespace App\Repository;

use App\Domain\Support\IndexFournisseurs;
use App\Entity\Fournisseur;
use App\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Fournisseur>
 */
class FournisseurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Fournisseur::class);
    }

    /**
     * Le référentiel d'un site, indexé par clé normalisée. Voir 'ProduitRepository::parCle()'.
     *
     * @return array<string, Fournisseur>
     */
    public function parCle(Site $site): array
    {
        $fournisseurs = $this->createQueryBuilder('f')
            ->andWhere('f.site = :site')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('site', $site)
            ->getQuery()
            ->getResult();

        $indexes = [];

        foreach($fournisseurs as $fournisseur) {
            $indexes[(string) $fournisseur->getCle()] = $fournisseur;
        }

        return $indexes;
    }

    /*
        - LES DEUX INDEX DE LA SYNCHRONISATION, en UNE requete. Le code rapproche, le libelle sert
          de repli pour les fiches creees avant les codes : voir 'IndexFournisseurs', qui porte
          l'ordre dans lequel on les interroge.

        - Un 'OR' plutot que deux requetes : les deux listes se recouvrent largement, et deux
          allers-retours chargeraient deux fois les memes lignes pour les recoller ensuite.

        @param list<string> $codes
        @param list<string> $cles
    */
    public function pourLaSynchronisation(Site $site, array $codes, array $cles): IndexFournisseurs
    {
        if($codes === [] && $cles === []) {
            return new IndexFournisseurs();
        }

        $qb = $this->createQueryBuilder('f')
            ->andWhere('f.site = :site')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('site', $site);

        $ou = [];

        if($codes !== []) {
            $ou[] = 'f.codefournisseur IN (:codes)';
            $qb->setParameter('codes', $codes);
        }

        if($cles !== []) {
            $ou[] = 'f.cle IN (:cles)';
            $qb->setParameter('cles', $cles);
        }

        $qb->andWhere(implode(' OR ', $ou));

        $index = new IndexFournisseurs();

        foreach($qb->getQuery()->getResult() as $fournisseur) {
            $index->ajouter($fournisseur);
        }

        return $index;
    }

    /**
     * @return list<Fournisseur>
     */
    public function findPourLePoste(Site $site): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.site = :site')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('site', $site)
            ->orderBy('f.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Combien de planteurs vivants par categorie, pour une page de categories.
     *
     * Une seule requete groupee : la liste des categories en poserait sinon une par ligne. Les
     * categories sans planteur sont ABSENTES du resultat, et c'est a l'appelant de lire zero — une
     * cle manquante n'est pas une donnee manquante ici, c'est un comptage a vide.
     *
     * @param list<int> $ids
     *
     * @return array<int, int>
     */
    public function compterParCategorie(array $ids): array
    {
        if($ids === []) {
            return [];
        }

        $lignes = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.categorie) AS ref', 'COUNT(f.id) AS nb')
            ->andWhere('f.categorie IN (:ids)')
            ->andWhere('f.deletedAt IS NULL')
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

}
