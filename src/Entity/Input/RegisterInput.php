<?php

namespace App\Entity\Input;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - Inscription d'une entreprise et de son premier administrateur, en un seul geste. C'est le seul chemin qui donne un client immédiatement utilisable : créer l'entreprise seule laisse un compte que personne ne peut ouvrir.
    - Réservée au super administrateur. Elle a d'abord été ouverte en libre-service, ce qui imposait un limiteur de débit par IP ; l'inscription passant désormais par un compte authentifié, ce garde-fou n'a plus d'objet.
*/
final class RegisterInput
{
    /* ---- Le compte administrateur ------------------------------------------------------- */

    #[Assert\NotBlank(message: 'L\'adresse e-mail est obligatoire')]
    #[Assert\Email(message: 'Cette adresse e-mail n\'est pas valide')]
    #[Groups(['write:Register'])]
    public ?string $email = null;

    #[Assert\NotBlank(message: 'Le nom est obligatoire')]
    #[Assert\Length(min: 2, max: 255)]
    #[Groups(['write:Register'])]
    public ?string $nom = null;

    #[Assert\NotBlank(message: 'Le prénom est obligatoire')]
    #[Assert\Length(min: 2, max: 255)]
    #[Groups(['write:Register'])]
    public ?string $prenom = null;

    #[Assert\NotBlank(message: 'Le mot de passe est obligatoire')]
    #[Assert\Length(min: 6, max: 255, minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères')]
    #[Assert\Regex(
        pattern: '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
        message: 'Le mot de passe doit contenir au moins une minuscule, une majuscule et un chiffre'
    )]
    #[Groups(['write:Register'])]
    public ?string $motDePasse = null;

    #[Assert\Length(min: 8, max: 30)]
    #[Groups(['write:Register'])]
    public ?string $telephone = null;

    /* ---- L'entreprise --------------------------------------------------------------------- */

    #[Assert\NotBlank(message: 'Le nom de l\'entreprise est obligatoire')]
    #[Assert\Length(min: 2, max: 255)]
    #[Groups(['write:Register'])]
    public ?string $nomEntreprise = null;

    #[Assert\NotBlank(message: 'Le code entreprise est obligatoire')]
    #[Assert\Regex(
        pattern: '/^[A-Za-z0-9]{2,20}$/',
        message: 'Le code entreprise ne peut contenir que des lettres et des chiffres'
    )]
    #[Groups(['write:Register'])]
    public ?string $codeentreprise = null; // L'identifiant lisible remis au client, normalisé en majuscules par l'entité

    #[Assert\NotBlank(message: 'Un contact est obligatoire')]
    #[Assert\Length(min: 8, max: 30)]
    #[Groups(['write:Register'])]
    public ?string $contact1 = null;

    #[Assert\Length(min: 8, max: 30)]
    #[Groups(['write:Register'])]
    public ?string $contact2 = null;

    #[Assert\Length(max: 255)]
    #[Groups(['write:Register'])]
    public ?string $adresse = null;

    #[Assert\Email(message: 'Cette adresse e-mail n\'est pas valide')]
    #[Groups(['write:Register'])]
    public ?string $emailEntreprise = null;
}
