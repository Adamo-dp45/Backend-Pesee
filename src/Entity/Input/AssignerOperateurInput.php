<?php

namespace App\Entity\Input;

use Symfony\Component\Serializer\Attribute\Groups;

/*
    - Affecter — ou retirer — l'opérateur qui tient la caisse d'un pont bascule.
    - 'null' est une valeur valable : elle libère le site, par exemple quand l'opérateur quitte l'entreprise. Le site reste consultable mais plus personne n'y encaisse.
*/
final class AssignerOperateurInput
{
    #[Groups(['write:Site:operateur'])]
    public ?int $operateurId = null;
}
