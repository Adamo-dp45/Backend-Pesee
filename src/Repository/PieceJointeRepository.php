<?php

namespace App\Repository;

use App\Entity\PieceJointe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PieceJointe>
 */
class PieceJointeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PieceJointe::class);
    }

    /*
        - LA PIÈCE DÉJÀ POSÉE SUR CETTE LIGNE, s'il y en a une. Un second dépôt la remplace : on se
          trompe de scan, on recommence, et laisser traîner l'ancienne ferait deux justificatifs
          pour un versement sans dire lequel fait foi.

        - 'deletedAt' se filtre À LA MAIN : les extensions Doctrine ne s'appliquent qu'aux requêtes
          d'API Platform, pas à celles qu'un processor lance lui-même.

        @param 'paiement'|'mouvementCaisse'|'demandeSolde'|'demandePrelevement'|'demandeRetrait' $champ
    */
    public function pourCible(string $champ, object $cible): ?PieceJointe
    {
        return $this->createQueryBuilder('p')
            ->andWhere(sprintf('p.%s = :cible', $champ))
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('cible', $cible)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
