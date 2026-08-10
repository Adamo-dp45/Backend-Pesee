<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/*
    - La route doit exister pour que la requête arrive jusqu'au pare-feu : le routeur s'exécute AVANT lui et répondrait 404. Le corps de la méthode, lui, n'est jamais atteint — 'json_login' intercepte la requête et c'est le gestionnaire de succès de LexikJWT qui renvoie le jeton.
*/
final class SecurityController extends AbstractController
{
    #[Route(path: '/api/login_check', name: 'api_login', methods: ['POST'])]
    public function jsonLogin(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'username' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
        ]);
    }
}
