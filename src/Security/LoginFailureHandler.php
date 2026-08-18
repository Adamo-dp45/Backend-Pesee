<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/*
    - Sans lui, 'login_throttling' répond 401 comme un simple mauvais mot de passe et le frontend affiche « identifiants invalides » à quelqu'un qui est en réalité bloqué quinze minutes.
*/
class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            return new JsonResponse([
                'code' => Response::HTTP_TOO_MANY_REQUESTS,
                'message' => 'Trop de tentatives de connexion. Réessayez dans quelques minutes.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return new JsonResponse([
            'code' => Response::HTTP_UNAUTHORIZED,
            'message' => $exception->getMessageKey(), // Porte le motif de 'UserChecker' quand il y en a un
        ], Response::HTTP_UNAUTHORIZED);
    }
}
