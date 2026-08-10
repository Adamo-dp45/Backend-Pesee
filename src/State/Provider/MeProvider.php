<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/*
    - Fournit l'utilisateur connecté aux opérations '/me', en lecture comme en modification : sans '{id}' dans l'URI, API Platform ne sait pas quel objet mettre à jour et répond 404. C'est ce provider qui le lui désigne.
    - Encore faut-il que l'opération déclare 'uriVariables: []'. Sans cette déclaration la charge utile est désérialisée dans un compte NEUF : le nom et le prénom repartaient vides, et la contrainte d'unicité voyait le compte de l'intéressé comme un doublon.
*/

/**
 * @implements ProviderInterface<User>
 */
class MeProvider implements ProviderInterface
{
    public function __construct(
        private Security $security
    )
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?User
    {
        $utilisateur = $this->security->getUser();

        return $utilisateur instanceof User ? $utilisateur : null;
    }
}
