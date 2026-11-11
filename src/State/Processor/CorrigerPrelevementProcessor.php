<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\DemandePrelevement;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Correction d'une demande par son auteur, et LA garde qui va avec : seulement tant qu'elle
      attend. Sans elle, on réécrit le taux d'une demande que l'administrateur a déjà approuvée, et
      l'écran affiche alors un chiffre que la fiche du planteur contredit.
*/

/**
 * @implements ProcessorInterface<DemandePrelevement, DemandePrelevement>
 */
class CorrigerPrelevementProcessor implements ProcessorInterface
{
    /*
        - L'argument ne s'appelle pas '$processor' : 'services.yaml' lie ce nom-là à la persistance
          native pour tous les processeurs, et la correction doit passer par l'audit, qui retient
          l'auteur avant de persister.
    */
    public function __construct(private AuditProcessor $audit)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DemandePrelevement
    {
        if($data instanceof DemandePrelevement && $data->estTraitee()) {
            throw new UnprocessableEntityHttpException('Cette demande a déjà été traitée : elle ne peut plus être corrigée.');
        }

        return $this->audit->process($data, $operation, $uriVariables, $context);
    }
}
