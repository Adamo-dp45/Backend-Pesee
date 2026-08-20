<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Domain\Service\CorbeilleService;
use App\Entity\Output\Corbeille\CorbeilleOutput;
use Symfony\Component\HttpFoundation\RequestStack;

/*
    - Le contenu de la corbeille. Les filtres sont lus sur la requête plutôt que déclarés en
      'ApiFilter' : la ressource n'a pas de table, donc pas de 'QueryBuilder' où un filtre Doctrine
      pourrait s'appliquer.
*/

/**
 * @implements ProviderInterface<CorbeilleOutput>
 */
final class CorbeilleProvider implements ProviderInterface
{
    public function __construct(
        private readonly CorbeilleService $corbeille,
        private readonly RequestStack $requestStack
    )
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CorbeilleOutput
    {
        $requete = $this->requestStack->getCurrentRequest();

        // '?type[]=produit&type[]=fournisseur' — un seul type se passe aussi bien en '?type=produit'
        $types = $requete?->query->all('type') ?? [];

        if($types === [] && is_string($seul = $requete?->query->get('type'))) {
            $types = [$seul];
        }

        $entreprise = $requete?->query->get('entreprise');

        return $this->corbeille->lister(
            $types,
            is_numeric($entreprise) ? (int) $entreprise : null
        );
    }
}
