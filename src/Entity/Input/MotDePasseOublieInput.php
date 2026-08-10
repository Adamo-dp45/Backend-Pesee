<?php

namespace App\Entity\Input;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class MotDePasseOublieInput
{
    #[Assert\NotBlank(message: 'L\'adresse e-mail est obligatoire')]
    #[Assert\Email(message: 'Cette adresse e-mail n\'est pas valide')]
    #[Groups(['write:User:oubli'])]
    public ?string $email = null;

    /*
        - L'adresse de la page de réinitialisation du site web, à laquelle le jeton sera ajouté. L'API ne connaît pas les routes du frontend : c'est lui qui les lui indique.
    */
    #[Assert\NotBlank(message: 'L\'adresse de réinitialisation est obligatoire')]
    #[Assert\Url(message: 'L\'adresse de réinitialisation n\'est pas valide', requireTld: false)] /*
        - Sans 'requireTld: false', une installation sur adresse IP ou sur un nom d'hôte interne ('http://webpesee/') serait refusée. Exiger un domaine public n'apporte rien : l'URL vient du site web lui-même, pas de l'utilisateur.
    */
    #[Groups(['write:User:oubli'])]
    public ?string $urlReinitialisation = null;
}
