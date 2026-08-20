<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Service\CorbeilleService;
use App\Entity\Data\Corbeille;

/*
    - Suppression definitive. Le seul geste de l'application qui efface reellement une ligne : partout
      ailleurs on marque, on n'efface pas. D'ou le refus des que quelque chose la reference encore.
*/

/**
 * @implements ProcessorInterface<Corbeille, Corbeille>
 */
final class PurgerCorbeilleProcessor implements ProcessorInterface
{
    public function __construct(
        private CorbeilleService $corbeille
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $this->corbeille->purger(
            (string) ($uriVariables['type'] ?? ''),
            (int) ($uriVariables['id'] ?? 0)
        );
    }
}
