<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Domain\Enum\TypeActivite;
use App\Domain\Service\ActiviteLogger;
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
        private Security $security,
        private ActiviteLogger $activites
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

        $suspendu = !$data->estActif();

        $this->activites->log(
            $suspendu ? TypeActivite::USER_SUSPENDU : TypeActivite::USER_REACTIVE,
            sprintf(
                '%s a %s le compte de %s (%s)',
                $auteur instanceof User ? $auteur->getNomComplet() : 'Le système',
                $suspendu ? 'suspendu' : 'réactivé',
                $data->getNomComplet(),
                (string) $data->getEmail()
            ),
            $data
        );

        $this->em->flush();

        return $data;
    }
}
