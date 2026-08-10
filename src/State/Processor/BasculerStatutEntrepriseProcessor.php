<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Entreprise;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/*
    - Active ou désactive une entreprise. Désactiver bloque la connexion de TOUS ses utilisateurs, administrateurs compris : c'est le levier du super administrateur en cas d'impayé.
    - La synchronisation des postes n'est PAS interrompue. C'est délibéré : les pesées continuent d'arriver, seule la consultation est coupée, et le client ne perd rien de son exploitation pendant le litige.
*/

/**
 * @implements ProcessorInterface<Entreprise, Entreprise>
 */
class BasculerStatutEntrepriseProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Entreprise
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
