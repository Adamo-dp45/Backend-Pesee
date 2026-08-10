<?php

namespace App\Entity\Input;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class ChangerMotDePasseInput
{
    /*
        - Le mot de passe actuel est exigé : sans lui, une session laissée ouverte sur un poste partagé suffirait à s'approprier le compte.
    */
    #[Assert\NotBlank(message: 'Le mot de passe actuel est obligatoire')]
    #[Groups(['write:User:motdepasse'])]
    public ?string $motDePasseActuel = null;

    #[Assert\NotBlank(message: 'Le nouveau mot de passe est obligatoire')]
    #[Assert\Length(min: 10, minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères')]
    #[Assert\NotCompromisedPassword(
        message: 'Ce mot de passe figure dans des fuites de données connues : choisissez-en un autre',
        skipOnError: true // La vérification interroge un service distant : sur une connexion de terrain coupée, elle ne doit pas empêcher de créer un compte
    )]
    #[Assert\Regex(
        pattern: '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
        message: 'Le mot de passe doit contenir au moins une minuscule, une majuscule et un chiffre'
    )]
    #[Groups(['write:User:motdepasse'])]
    public ?string $nouveauMotDePasse = null;
}
