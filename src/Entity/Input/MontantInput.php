<?php

namespace App\Entity\Input;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

// Recharge du solde de l'entreprise, ou dotation de la caisse d'un pont bascule
final class MontantInput
{
    #[Assert\NotNull(message: 'Le montant est obligatoire')]
    #[Assert\Positive(message: 'Le montant doit être supérieur à zéro')]
    #[Groups(['write:Montant'])]
    public ?int $montant = null;

    #[Assert\Length(max: 500)]
    #[Groups(['write:Montant'])]
    public ?string $motif = null;
}
