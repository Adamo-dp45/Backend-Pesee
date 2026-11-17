<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\DemandeRetrait;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Correction d'une demande par son auteur, et LA garde qui va avec : seulement tant qu'elle
      attend. Sans elle, on réécrit le montant d'une demande que l'administrateur a déjà accordée,
      et le guichet verserait autre chose que ce qui a été autorisé.
*/

/**
 * @implements ProcessorInterface<DemandeRetrait, DemandeRetrait>
 */
class CorrigerRetraitProcessor implements ProcessorInterface
{
    // Le type CONCRET et non l'interface : '$processor' est le seul nom lié à la persistance native, tout le reste s'autowire par son type
    public function __construct(private AuditProcessor $audit)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DemandeRetrait
    {
        if($data instanceof DemandeRetrait && $data->estTraitee()) {
            throw new UnprocessableEntityHttpException('Cette demande a déjà été traitée : elle ne peut plus être corrigée.');
        }

        return $this->audit->process($data, $operation, $uriVariables, $context);
    }
}
