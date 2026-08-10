<?php

namespace App\Repository;

use App\Entity\Entreprise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Entreprise>
 */
class EntrepriseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entreprise::class);
    }

    /*
        - Le code est stocké en majuscules, mais rien n'oblige l'appelant à le normaliser avant de nous le passer : à l'inscription il arrive brut du formulaire.
    */
    public function findOneByCode(string $code): ?Entreprise
    {
        return $this->findOneBy(['codeentreprise' => strtoupper(trim($code))]);
    }
}
