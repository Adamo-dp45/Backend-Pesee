<?php

namespace App\Domain\Enum;

/*
    - Demande de réapprovisionnement faite par l'opérateur d'un pont bascule quand sa caisse est épuisée. C'est le chemin normal pour remettre de l'argent dans un poste : l'administrateur peut toujours doter directement, mais alors personne ne garde trace de qui a demandé quoi.
*/
enum StatutDemande: string
{
    case EN_ATTENTE = 'EN_ATTENTE'; // Elle attend un administrateur, et elle seule bloque une nouvelle demande sur le même site
    case APPROUVEE = 'APPROUVEE';   // La caisse du site a été dotée : deux écritures sont parties au journal
    case REJETEE = 'REJETEE';       // Refusée avec un motif, que l'opérateur lit avant de redemander
    case ANNULEE = 'ANNULEE';       // Retirée par son auteur, sans mobiliser un administrateur pour une demande devenue sans objet

    public function libelle(): string
    {
        return match($this) {
            self::EN_ATTENTE => 'En attente',
            self::APPROUVEE => 'Approuvée',
            self::REJETEE => 'Rejetée',
            self::ANNULEE => 'Annulée',
        };
    }

    public function estTraitee(): bool
    {
        return $this !== self::EN_ATTENTE;
    }
}
