<?php

namespace App\Entity\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - LA GRILLE ENTIÈRE EN UN ENVOI : un porteur — une catégorie, ou un planteur — et le prix de
      chaque produit qu'on veut tarifer.

    - POURQUOI UN LOT. Le nombre de cases croît au produit du nombre de catégories par le nombre de
      produits. Les saisir une par une, chacune avec son formulaire et son aller-retour, est la
      corvée qu'on abandonne en route — et une grille à trous ne se voit pas : elle paie simplement
      au prix du produit, ce qui ressemble à une décision.

    - ET SURTOUT : chaque case écrite revalorise des pesées, et chaque pesée revalorisée peut être
      arbitrée — irréversiblement. Dix envois séparés, c'est dix passes de revalorisation dont on ne
      peut annuler aucune. Un seul envoi, une seule passe, un seul chiffre à annoncer d'avance.

    - 'confirmer' À FAUX NE REGARDE RIEN DE MOINS, ET N'ÉCRIT RIEN. C'est le même calcul, rendu sans
      être appliqué : l'écran obtient le bilan ET le nombre de pesées concernées, les montre, et ne
      rappelle avec 'confirmer' qu'après un oui. Sans ce mode, l'administrateur découvrirait après
      coup qu'il vient de figer la retenue de trois mille pesées.
*/
class TarifLotInput
{
    /**
     * Les cases de la grille. Une case absente n'est pas touchée ; une case à prix nul est retirée.
     *
     * @var TarifLotLigneInput[]
     */
    #[Groups(['write:TarifLot'])]
    #[Assert\Count(min: 1, minMessage: 'Aucun tarif à enregistrer')]
    #[Assert\Valid]
    public array $lignes = [];

    /** Faux : on rend le bilan sans rien écrire. Vrai : on applique. */
    #[Groups(['write:TarifLot'])]
    public bool $confirmer = false;
}
