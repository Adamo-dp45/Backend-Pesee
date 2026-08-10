<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\DemandeSolde;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Correction d'une demande par son auteur, et LA garde qui va avec : seulement tant qu'elle attend. Sans elle, on réécrit le montant d'une demande sur laquelle un administrateur a déjà versé — l'écran affiche alors une somme que le journal contredit.

    - En V1 cette règle vivait dans le voter dédié à la demande. Ici le voter ne porte que le grossier « qui a le droit de modifier une demande » ; le « laquelle, et quand » revient au processor, comme pour l'approbation et le rejet.
*/

/**
 * @implements ProcessorInterface<DemandeSolde, DemandeSolde>
 */
class CorrigerDemandeProcessor implements ProcessorInterface
{
    /*
        - L'argument ne s'appelle pas '$processor' : 'services.yaml' lie ce nom-là à la persistance native pour tous les processeurs, et la correction doit passer par l'audit, qui retient l'auteur avant de persister.
    */
    public function __construct(private AuditProcessor $audit)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DemandeSolde
    {
        if($data instanceof DemandeSolde && $data->estTraitee()) {
            throw new UnprocessableEntityHttpException('Cette demande a déjà été traitée : elle ne peut plus être corrigée.');
        }

        return $this->audit->process($data, $operation, $uriVariables, $context);
    }
}
