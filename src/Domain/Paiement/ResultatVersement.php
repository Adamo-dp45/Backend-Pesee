<?php

namespace App\Domain\Paiement;

use App\Domain\Enum\StatutPaiement;

/*
    - Ce que répond la passerelle. Trois issues seulement :
      · ENVOYE — acceptée, la confirmation viendra par webhook ;
      · REUSSI — confirmée immédiatement, ce que font certains agrégateurs sur de petits montants ;
      · ECHOUE — refusée, les fonds doivent être rendus à la caisse du site.
*/
final class ResultatVersement
{
    /**
     * @param array<string, mixed>|null $payload
     */
    private function __construct(
        public readonly StatutPaiement $statut,
        public readonly ?string $transactionId = null,
        public readonly ?string $message = null,
        public readonly ?array $payload = null
    )
    {
    }

    /** @param array<string, mixed>|null $payload */
    public static function envoye(string $transactionId, ?array $payload = null): self
    {
        return new self(StatutPaiement::ENVOYE, $transactionId, null, $payload);
    }

    /** @param array<string, mixed>|null $payload */
    public static function reussi(string $transactionId, ?array $payload = null): self
    {
        return new self(StatutPaiement::REUSSI, $transactionId, null, $payload);
    }

    /** @param array<string, mixed>|null $payload */
    public static function echoue(string $message, ?string $transactionId = null, ?array $payload = null): self
    {
        return new self(StatutPaiement::ECHOUE, $transactionId, $message, $payload);
    }

    /*
        - Un règlement qui n'est passé par aucun agrégateur : les billets ont changé de main devant le poste. Confirmé d'emblée, et SANS identifiant de transaction — il n'y a aucune transaction à retrouver ailleurs, et en inventer un ferait croire à une pièce qu'on pourrait réclamer au service client.
        - Une fabrique à part plutôt qu'un 'transactionId' rendu facultatif sur 'reussi()' : ce que répond une passerelle porte toujours une référence, et relâcher cette règle pour un cas qui ne la concerne pas l'aurait affaiblie pour tous les autres.
    */
    /** @param array<string, mixed>|null $payload */
    public static function confirmeSansPasserelle(?array $payload = null): self
    {
        return new self(StatutPaiement::REUSSI, null, null, $payload);
    }
}
