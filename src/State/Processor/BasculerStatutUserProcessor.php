<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/*
    - Suspend ou réactive un compte. L'effet est immédiat : 'JWTSubscriber' relit l'utilisateur à chaque requête authentifiée, un jeton déjà émis ne survit donc pas à la suspension.
    - Les garde-fous (ni soi-même, ni un fondateur, ni un super administrateur) sont dans 'UserGuard', consulté par le voter avant qu'on arrive ici.
*/

/**
 * @implements ProcessorInterface<User, User>
 */
class BasculerStatutUserProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
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
