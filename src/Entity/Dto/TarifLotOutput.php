<?php

namespace App\Entity\Dto;

use Symfony\Component\Serializer\Attribute\Groups;

/*
    - LE BILAN D'UNE SAISIE DE GRILLE, et ce que l'écran en fait : il l'annonce. Un 200 muet
      laisserait croire que tout est passé alors qu'une ligne a pu ne rien changer, et la différence
      entre « 12 créés » et « 12 inchangés » est précisément ce qu'on veut lire après avoir rempli
      une colonne entière.

    - 'peseesConcernees' EST LE CHIFFRE QUI COMPTE. C'est lui qu'on montre AVANT d'écrire, parce que
      derrière chacune de ces pesées il y a une valorisation qui change, et parfois une retenue
      d'épargne qu'on fige pour toujours. Un tarif saisi de travers sur une catégorie de deux cents
      planteurs n'a aucun chemin de retour.

    - 'applique' dit lequel des deux modes a répondu. Sans lui, un écran ne saurait pas distinguer
      « voici ce qui se passerait » de « voilà ce qui vient de se passer », et les deux phrases
      n'appellent pas le même bouton.
*/
class TarifLotOutput
{
    public function __construct(
        /** Cases qui n'avaient pas de tarif et viennent d'en recevoir un. */
        #[Groups(['read:TarifLot'])]
        public readonly int $creees = 0,

        /** Cases déjà tarifées dont le montant change. */
        #[Groups(['read:TarifLot'])]
        public readonly int $modifiees = 0,

        /** Cases renvoyées avec le MÊME montant : rien à faire, et il faut le dire. */
        #[Groups(['read:TarifLot'])]
        public readonly int $inchangees = 0,

        /** Cases vidées : elles retombent sur le saut suivant de la résolution. */
        #[Groups(['read:TarifLot'])]
        public readonly int $retirees = 0,

        /** Pesées non payées et non arbitrées que ces changements touchent. */
        #[Groups(['read:TarifLot'])]
        public readonly int $peseesConcernees = 0,

        /** Faux pour un aperçu : rien n'a été écrit. */
        #[Groups(['read:TarifLot'])]
        public readonly bool $applique = false,
    ) {
    }
}
