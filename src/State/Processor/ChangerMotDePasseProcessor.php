<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Input\ChangerMotDePasseInput;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/*
    - Changement de mot de passe par l'intéressé lui-même. Le mot de passe actuel est exigé : une session ouverte ne suffit pas.
    - Les jetons de réinitialisation en cours ne sont PAS brûlés ici. C'est délibéré : quelqu'un qui vient de changer son mot de passe n'a plus de lien en attente, et en périmer un obligerait à traiter le cas « je change puis je clique sur mon vieux mail » comme une erreur alors qu'il est sans conséquence — le lien expire seul en une heure.
*/

/**
 * @implements ProcessorInterface<ChangerMotDePasseInput, User>
 */
class ChangerMotDePasseProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
    {
        $utilisateur = $this->security->getUser();

        if(!$utilisateur instanceof User) {
            throw new UnprocessableEntityHttpException('Aucun utilisateur connecté.');
        }

        if(!$this->hasher->isPasswordValid($utilisateur, (string) $data->motDePasseActuel)) {
            throw new UnprocessableEntityHttpException('Le mot de passe actuel est incorrect.');
        }

        $utilisateur->setPassword($this->hasher->hashPassword($utilisateur, (string) $data->nouveauMotDePasse));

        $this->em->flush();

        return $utilisateur;
    }
}
