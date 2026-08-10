<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/*
    - Refuse la connexion d'un compte suspendu ou d'une entreprise désactivée. Déclaré dans 'security.yaml' sous 'user_checker'.
    - Le site bloqué n'est PAS testé ici : un compte machine doit pouvoir obtenir son jeton, c'est la synchronisation qui refusera ses pesées.
*/
class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if(!$user instanceof User) {
            return;
        }

        if(!$user->estActif()) {
            throw new CustomUserMessageAuthenticationException('Votre compte a été suspendu. Contactez votre administrateur.');
        }

        if($user->getEntreprise() && !$user->getEntreprise()->estActive()) {
            throw new CustomUserMessageAuthenticationException('Votre entreprise a été désactivée. Contactez le support.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
