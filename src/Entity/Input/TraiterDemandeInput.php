<?php

namespace App\Entity\Input;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - Le corps commun à l'approbation et au rejet d'une demande. Un seul DTO : les deux opérations n'envoient qu'un champ chacune, et deux classes d'un champ ne s'expliqueraient pas.
    - Aucun des deux champs n'est obligatoire ICI : l'approbation accepte un montant absent (« le montant demandé »), et l'exigence d'un motif au rejet est une règle métier, portée par son processor avec un message qui dit pourquoi.
*/
final class TraiterDemandeInput
{
    #[Assert\Positive(message: 'Le montant accordé doit être supérieur à zéro')]
    #[Groups(['write:Demande:traitement'])]
    public ?int $montantAccorde = null; // Nul = on accorde le montant demandé

    #[Assert\Length(max: 500)]
    #[Groups(['write:Demande:traitement'])]
    public ?string $motifRejet = null;
}
