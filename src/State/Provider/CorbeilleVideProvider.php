<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Data\Corbeille;

/*
    - Un provider qui ne charge rien. Il n'est pas inutile pour autant : sans lui, API Platform
      cherche l'element visé par une operation portant '{id}' dans son URI, ne trouve aucune source
      Doctrine derriere cette ressource, et repond 404 avant meme d'atteindre le processor.

    - Le vrai chargement se fait dans 'CorbeilleService', qui seul sait lire par-dessus le
      cloisonnement — c'est tout l'objet de la corbeille.
*/

/**
 * @implements ProviderInterface<Corbeille>
 */
final class CorbeilleVideProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Corbeille
    {
        return new Corbeille();
    }
}
