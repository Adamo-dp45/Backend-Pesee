<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation as ApiOperation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Output\ReferentielsOutput;
use App\Repository\OperationRepository;
use App\Security\Perimetre;

/*
    - Alimente les listes déroulantes de la page Bilan : les valeurs RÉELLEMENT rencontrées dans les
      pesées du périmètre, et pas un référentiel figé. Un transporteur qui n'est jamais passé n'a
      aucune raison d'apparaître dans un filtre.
*/

/**
 * @implements ProviderInterface<ReferentielsOutput>
 */
final class ReferentielsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Perimetre $perimetre,
        private readonly OperationRepository $operations
    )
    {
    }

    public function provide(ApiOperation $operation, array $uriVariables = [], array $context = []): ReferentielsOutput
    {
        $sortie = new ReferentielsOutput();
        $siteIds = $this->perimetre->siteIds();

        if($siteIds === []) {
            return $sortie;
        }

        foreach(array_keys(OperationRepository::REFERENTIELS) as $nom) {
            $sortie->{$nom} = $this->operations->valeursDistinctes($siteIds, $nom);
        }

        return $sortie;
    }
}
