<?php

namespace App\Entity\Output;

use Symfony\Component\Serializer\Attribute\Groups;

/*
    - CE QUE CE PLANTEUR TOUCHE AU KILO, produit par produit, et D'OÙ ÇA VIENT. Une ligne par produit
      de son entreprise, tarifé ou non — ce sont les cases vides qu'on vient regarder.

    - POURQUOI ÇA NE SE CALCULE PAS CÔTÉ WEB. La résolution à trois sauts est une règle métier ; la
      recopier dans un gabarit, c'est accepter qu'elle diverge le jour où un quatrième saut
      apparaît. Et elle a besoin de la grille de la CATÉGORIE, que la fiche du planteur ne porte pas.

    - CE QUE ÇA RÉPARE, concrètement : la liste déroulante de mise en compte affichait le PRIX DU
      PRODUIT en annonçant que c'est lui qui borne le taux. Depuis les catégories, c'est faux — un
      planteur payé 450 F se voyait proposer « Hévéa — 400 F le kilo », et l'API acceptait pourtant
      449. L'écran disait une chose, le serveur en faisait une autre.
*/
final class TarifsApplicablesOutput
{
    /** @var list<LigneTarifApplicable> */
    #[Groups(['read:TarifsApplicables'])]
    public array $lignes = [];
}
