<?php

namespace App\Entity\Input;

use App\Domain\Enum\Role;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class AttribuerRoleInput
{
    /*
        - Un seul rôle, choisi parmi ceux qu'une entreprise attribue. Ni super administrateur — il se pose en base à l'installation — ni compte machine, qui naît avec son pont bascule.
    */
    #[Assert\NotBlank(message: 'Le rôle est obligatoire')]
    #[Assert\Choice(
        callback: [Role::class, 'valeursAttribuables'],
        message: 'Ce rôle ne peut pas être attribué depuis cette interface'
    )]
    #[Groups(['write:User:role'])]
    public ?string $role = null;
}
