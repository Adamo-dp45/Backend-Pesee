<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Entreprise;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/*
    - L'entreprise de l'utilisateur connecté, sans qu'il ait à en connaître l'identifiant. Renvoie null pour le super administrateur, qui n'appartient à aucune entreprise.
*/

/**
 * @implements ProviderInterface<Entreprise>
 */
class MeEntrepriseProvider implements ProviderInterface
{
    public function __construct(
        private Security $security
    )
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?Entreprise
    {
        $utilisateur = $this->security->getUser();

        return $utilisateur instanceof User ? $utilisateur->getEntreprise() : null;
    }
}
