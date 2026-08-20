<?php

namespace App\Entity\Input;

use App\Domain\Enum\ReseauMobile;
use App\Validator\NumeroMobileValide;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - Verser tout ou partie de ce qui est dû à un planteur sur une pesée.
    - Le numéro et le réseau sont facultatifs : par défaut on prend ceux de la fiche du planteur. Les renseigner permet de payer sur un numéro de circonstance sans modifier sa fiche.
    - Un identifiant entier plutôt qu'une IRI : c'est plus simple à consommer côté client, et c'est le processor qui résout la pesée — en vérifiant au passage qu'elle est bien dans le périmètre de l'appelant.
*/
final class PaiementInput
{
    #[Assert\NotNull(message: 'La pesée à régler est obligatoire')]
    #[Groups(['write:Paiement'])]
    public ?int $operationId = null;

    #[Assert\NotNull(message: 'Le montant est obligatoire')]
    #[Assert\Positive(message: 'Le montant doit être supérieur à zéro')]
    #[Groups(['write:Paiement'])]
    public ?int $montant = null;

    /*
        - La forme est vérifiée dès la saisie plutôt qu'au moment de l'appel à l'agrégateur : le
          refuser ici coûte un message, le refuser là-bas coûte une réservation de fonds, un aller-
          retour réseau et un remboursement.
        - La règle elle-même vit dans 'NumeroMobile', d'où la normalisation la tire aussi : les deux
          ne peuvent pas diverger.
    */
    #[Assert\Length(max: 30)]
    #[NumeroMobileValide]
    #[Groups(['write:Paiement'])]
    public ?string $numeroDestinataire = null;

    #[Groups(['write:Paiement'])]
    public ?ReseauMobile $reseau = null;
}
