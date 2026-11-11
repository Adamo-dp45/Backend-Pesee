<?php

namespace App\Repository;

use App\Domain\Enum\StatutDemande;
use App\Entity\DemandePrelevement;
use App\Entity\Fournisseur;
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
        - La demande qui attend encore sur ce planteur, s'il y en a une. C'est elle qui bloque un
          second dépôt : deux demandes approuvées coup sur coup laisseraient deux taux, et personne
          ne saurait lequel s'applique.

        - 'deletedAt' est filtré À LA MAIN : les extensions Doctrine ne s'appliquent qu'aux requêtes
          d'API Platform, pas à celles qu'un processor lance lui-même.
    */
    public function findEnAttentePourFournisseur(Fournisseur $fournisseur): ?DemandePrelevement
    {
        return $this->findOneBy([
            'fournisseur' => $fournisseur,
            'statut' => StatutDemande::EN_ATTENTE,
            'deletedAt' => null,
        ]);
    }
}
