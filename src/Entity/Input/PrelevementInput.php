<?php

namespace App\Entity\Input;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - Le taux de la mise en compte d'un planteur, posé directement par l'administrateur. Un taux nul
      ou absent ARRÊTE le prélèvement : c'est le même geste, dans l'autre sens, et deux opérations
      distinctes pour ouvrir et fermer n'auraient rien clarifié.

    - Ce qui a déjà été mis de côté n'est pas touché par un arrêt. L'épargne reste au planteur ;
      seul le robinet se ferme.
*/
final class PrelevementInput
{
    #[Assert\PositiveOrZero(message: 'Le taux de prélèvement ne peut pas être négatif')]
    #[Groups(['write:Fournisseur:epargne'])]
    public ?int $taux = null;
}
