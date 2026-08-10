<?php

namespace App\Entity\Input;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class ReinitialiserMotDePasseInput
{
    #[Assert\NotBlank(message: 'Le jeton est obligatoire')]
    #[Groups(['write:User:reinitialisation'])]
    public ?string $token = null;

    #[Assert\NotBlank(message: 'Le mot de passe est obligatoire')]
    #[Assert\Length(min: 10, minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères')]
    #[Assert\NotCompromisedPassword(
        message: 'Ce mot de passe figure dans des fuites de données connues : choisissez-en un autre',
        skipOnError: true // La vérification interroge un service distant : sur une connexion de terrain coupée, elle ne doit pas empêcher de créer un compte
    )]
    #[Assert\Regex(
        pattern: '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
        message: 'Le mot de passe doit contenir au moins une minuscule, une majuscule et un chiffre'
    )]
    #[Groups(['write:User:reinitialisation'])]
    public ?string $motDePasse = null;
}
