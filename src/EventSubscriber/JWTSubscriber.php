<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/*
    - 'UserChecker' ne joue qu'à la connexion : un compte suspendu APRÈS l'émission de son jeton continuerait sinon d'appeler l'API jusqu'à l'expiration. On relit donc le compte à chaque requête authentifiée.
    - Une requête de plus par appel, assumée : c'est le prix pour qu'une suspension prenne effet immédiatement plutôt qu'au bout d'une heure.
*/
class JWTSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private UserRepository $userRepository
    )
    {
    }

    public function onJwtAuthenticated(JWTAuthenticatedEvent $event): void
    {
        $user = $event->getToken()->getUser();

        if(!$user instanceof User) {
            return;
        }

        $frais = $this->userRepository->find($user->getId()); /*
            - '$user' est reconstruit depuis le jeton et porte donc l'état qu'il avait à l'émission
        */

        if($frais === null) {
            return;
        }

        if(!$frais->estActif()) {
            throw new AccessDeniedHttpException('Votre compte a été suspendu. Contactez votre administrateur.');
        }

        if($frais->getEntreprise() && !$frais->getEntreprise()->estActive()) {
            throw new AccessDeniedHttpException('Votre entreprise a été désactivée. Contactez le support.');
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            Events::JWT_AUTHENTICATED => 'onJwtAuthenticated',
        ];
    }
}
