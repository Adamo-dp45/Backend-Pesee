<?php

namespace App\Entity\Dto;

use App\Entity\Produit;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/* Une case de la grille : un produit, et ce qu'il vaut. */
class TarifLotLigneInput
{
    #[Groups(['write:TarifLot'])]
    #[Assert\NotNull(message: 'Chaque ligne vise un produit')]
    public ?Produit $produit = null;

    /*
        - 'null' VEUT DIRE « RETIRER LA LIGNE », et c'est la seule façon de vider une case. Elle ne
          dit pas « gratuit » : elle fait retomber la valorisation sur le saut suivant — le tarif de
          la catégorie pour un planteur, le prix du produit pour une catégorie.

        - Zéro est refusé pour cette raison exacte. Une case à zéro serait lue comme un prix, et
          toutes les pesées de ces planteurs vaudraient subitement zéro franc.
    */
    #[Groups(['write:TarifLot'])]
    #[Assert\Positive(message: 'Un tarif doit être supérieur à zéro. Laissez la case vide pour retirer la ligne.')]
    public ?int $prix = null;
}
