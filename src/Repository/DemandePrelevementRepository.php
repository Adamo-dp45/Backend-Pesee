<?php

namespace App\Repository;

use App\Domain\Enum\StatutDemande;
use App\Entity\DemandePrelevement;
use App\Entity\Fournisseur;
use App\Entity\Produit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemandePrelevement>
 */
class DemandePrelevementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemandePrelevement::class);
    }

    /*
        - La demande qui attend encore sur ce planteur POUR CE PRODUIT, s'il y en a une. C'est elle
          qui bloque un second dépôt : deux demandes approuvées coup sur coup sur le même produit
          laisseraient deux taux, et personne ne saurait lequel s'applique.

        - Par couple et non par planteur seul : une demande sur le cacao ne doit pas empêcher d'en
          déposer une sur l'hévéa, ce sont deux décisions sans rapport.

        - 'deletedAt' est filtré À LA MAIN : les extensions Doctrine ne s'appliquent qu'aux requêtes
          d'API Platform, pas à celles qu'un processor lance lui-même.
    */
    public function findEnAttentePour(Fournisseur $fournisseur, Produit $produit): ?DemandePrelevement
    {
        return $this->findOneBy([
            'fournisseur' => $fournisseur,
            'produit' => $produit,
            'statut' => StatutDemande::EN_ATTENTE,
            'deletedAt' => null,
        ]);
    }

    /*
        - COMBIEN ATTENDENT UNE DÉCISION, pour le tableau de bord. Le périmètre passe par le SITE
          et non par le planteur : la demande le porte elle-même, figé au dépôt, et joindre le
          planteur pour y arriver rendrait la même réponse par un chemin de plus.

        - Un COUNT et non une liste comptée : l'accueil n'affiche qu'un nombre, et charger des
          demandes pour les dénombrer coûte une hydratation entière.
    */
    /** @param list<int> $siteIds */
    public function compterParStatut(array $siteIds, StatutDemande $statut): int
    {
        if($siteIds === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.site IN (:sites)')
            ->andWhere('d.statut = :statut')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('sites', $siteIds)
            ->setParameter('statut', $statut)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
