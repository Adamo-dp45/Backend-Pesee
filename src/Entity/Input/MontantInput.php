<?php

namespace App\Entity\Input;

use App\Domain\Enum\TypeCaisse;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

// Recharge du solde de l'entreprise, ou dotation de la caisse d'un pont bascule
final class MontantInput
{
    #[Assert\NotNull(message: 'Le montant est obligatoire')]
    #[Assert\Positive(message: 'Le montant doit être supérieur à zéro')]
    #[Groups(['write:Montant'])]
    public ?int $montant = null;

    /*
        - Quelle caisse reçoit. Obligatoire et sans valeur par défaut, délibérément : un défaut aurait fait atterrir la moitié des recharges dans la mauvaise caisse sans que personne ne s'en aperçoive avant de ne plus pouvoir payer. Mieux vaut un champ qu'on doit renseigner.
    */
    #[Assert\NotNull(message: 'La caisse à alimenter est obligatoire')]
    #[Groups(['write:Montant'])]
    public ?TypeCaisse $caisse = null;

    #[Assert\Length(max: 500)]
    #[Groups(['write:Montant'])]
    public ?string $motif = null;
}
