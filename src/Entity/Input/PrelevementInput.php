<?php

namespace App\Entity\Input;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - Une mise en compte posée directement par l'administrateur, SUR UN PRODUIT. Un taux nul ou absent
      ARRÊTE le prélèvement de ce produit : c'est le même geste, dans l'autre sens, et deux opérations
      distinctes pour ouvrir et fermer n'auraient rien clarifié.

    - Le produit est obligatoire dans les deux sens. Un planteur peut épargner sur plusieurs cultures,
      et « arrêter la mise en compte de Kouassi » ne veut plus rien dire sans préciser laquelle.

    - Ce qui a déjà été mis de côté n'est pas touché par un arrêt. L'épargne reste au planteur ; seul
      le robinet se ferme.
*/
final class PrelevementInput
{
    /** L'identifiant du produit. Le prix de ce produit borne le taux, voir 'TauxPrelevement'. */
    #[Assert\NotNull(message: 'Le produit est obligatoire : un prélèvement porte toujours sur une culture')]
    #[Assert\Positive(message: 'Le produit est obligatoire')]
    #[Groups(['write:Fournisseur:epargne'])]
    public ?int $produit = null;

    #[Assert\PositiveOrZero(message: 'Le taux de prélèvement ne peut pas être négatif')]
    #[Groups(['write:Fournisseur:epargne'])]
    public ?int $taux = null;
}
