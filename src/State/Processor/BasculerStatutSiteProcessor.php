<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Site;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/*
    - Bloque ou débloque un pont bascule. Bloquer coupe l'arrivée de ses pesées : le poste continue d'enregistrer localement, mais ses envois sont refusés et plus aucun paiement ne peut partir de sa caisse.
    - C'est le seul levier qui coupe un poste IMMÉDIATEMENT : il est relu à chaque envoi, alors qu'un renouvellement d'identifiants laisse vivre les jetons déjà émis.
*/

/**
 * @implements ProcessorInterface<Site, Site>
 */
class BasculerStatutSiteProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Site
    {
        $auteur = $this->security->getUser();

        $data->setStatut($data->getStatut()->inverse());

        if($auteur instanceof User) {
            $data->setUpdatedBy($auteur->getId());
        }

        $this->em->flush();

        return $data;
    }
}
