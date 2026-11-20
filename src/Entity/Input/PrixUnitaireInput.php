<?php

namespace App\Entity\Input;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - LE PRIX AU KILO ÉCRIT À LA MAIN SUR UNE PESÉE, c'est-à-dire la prime : une récolte
      particulièrement propre, un planteur qu'on veut retenir, un geste commercial décidé au bureau.
      Il l'emporte sur les trois sauts de la résolution habituelle, et il ne bougera plus.

    - STRICTEMENT POSITIF, et non « positif ou nul » : mettre un prix à zéro ne serait pas une prime,
      ce serait annuler la récolte d'un planteur depuis un écran de tarification. Une pesée sans prix
      a déjà sa représentation — 'prixunitaire' à null, posé par le calcul — et elle ne se saisit pas.

    - LE MOTIF N'EST PAS OBLIGATOIRE mais il est attendu : c'est lui qui se relit dans le journal
      six mois plus tard, quand un autre planteur demandera pourquoi son voisin a touché plus.
*/
final class PrixUnitaireInput
{
    #[Assert\NotNull(message: 'Le prix au kilo est obligatoire')]
    #[Assert\Positive(message: 'Le prix au kilo doit être supérieur à zéro')]
    #[Groups(['write:Operation:prix'])]
    public ?int $prixunitaire = null;

    #[Assert\Length(max: 500)]
    #[Groups(['write:Operation:prix'])]
    public ?string $motif = null;
}
