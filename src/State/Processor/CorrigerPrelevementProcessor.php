<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Support\TauxPrelevement;
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

        /*
            - Le produit n'est pas corrigeable — il n'est pas dans le groupe d'écriture de la
              correction — mais le TAUX l'est, et c'est lui que la règle borne. Corriger 20 en 500
              sur un produit à 145 doit être refusé ici, sans quoi la demande part à l'administrateur
              dans un état qu'il ne pourra que rejeter.
        */
        if($data instanceof DemandePrelevement
            && ($motif = TauxPrelevement::motifDuRefus($data->getFournisseur(), $data->getProduit(), $data->getTauxDemande())) !== null) {
            throw new UnprocessableEntityHttpException($motif);
        }

        return $this->audit->process($data, $operation, $uriVariables, $context);
    }
}
