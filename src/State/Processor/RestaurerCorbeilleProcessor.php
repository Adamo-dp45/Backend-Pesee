<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Service\CorbeilleService;
use App\Entity\Data\Corbeille;

/*
    - Sort un element de la corbeille. Le type et l'identifiant viennent de l'URI, pas d'un corps :
      il n'y a rien a saisir, on designe une ligne et on la remet en service.
*/

/**
 * @implements ProcessorInterface<Corbeille, Corbeille>
 */
final class RestaurerCorbeilleProcessor implements ProcessorInterface
{
    public function __construct(
        private CorbeilleService $corbeille
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Corbeille
    {
        $this->corbeille->restaurer(
            (string) ($uriVariables['type'] ?? ''),
            (int) ($uriVariables['id'] ?? 0)
        );

        return new Corbeille();
    }
}
