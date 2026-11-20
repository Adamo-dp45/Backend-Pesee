<?php

namespace App\Domain\Enum;

/*
    - COMMENT SE FACTURE UN TRAJET. Jusqu'ici il n'y avait qu'une façon : tant de francs par kilo
      transporté. C'est juste pour un planteur qui livre dix tonnes, absurde pour celui qui en
      apporte deux cents kilos depuis le même village — le camion a fait la même route.

    - TROIS MODES, ET UN SEUL S'APPLIQUE À LA FOIS. Un barème porte son mode et les champs de ce
      mode-là ; les autres restent vides. Les mêler — un prix au kilo ET un forfait sur la même
      ligne — ouvrirait la question « lequel gagne ? », à laquelle aucune réponse n'est bonne.

    - LE MODE EST FIGÉ SUR LA PESÉE, à côté du montant. Sans lui, « forfait à 0 F » et « aucun
      barème » sont la même ligne en base : un montant nul et rien pour dire pourquoi.
*/
enum ModeTransport: string
{
    case AU_KILO = 'AU_KILO';   // prix au kilo × poids net — ce que faisait l'application avant les barèmes
    case FORFAIT = 'FORFAIT';   // un montant, quel que soit le tonnage : c'est le voyage qu'on paie
    case TRANCHES = 'TRANCHES'; // le montant de la tranche qui contient la distance du planteur

    public function libelle(): string
    {
        return match($this) {
            self::AU_KILO => 'Au kilo',
            self::FORFAIT => 'Forfait',
            self::TRANCHES => 'Par tranches de kilomètres',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::AU_KILO => 'Tant de francs par kilo transporté : le montant suit le tonnage.',
            self::FORFAIT => 'Un montant fixe par pesée, quel que soit le tonnage. C\'est le trajet qu\'on paie.',
            self::TRANCHES => 'Un montant par intervalle de kilomètres. Il faut une distance sur la fiche du planteur.',
        };
    }

    /** Vrai si ce mode a besoin de la distance du planteur pour produire un montant. */
    public function exigeUneDistance(): bool
    {
        return $this === self::TRANCHES;
    }

    /*
        - Le seul mode qui produise un PRIX AU KILO figeable sur la pesée. Les deux autres rendent un
          montant sans prix unitaire : 'Operation::$prixtransport' y reste nul, et c'est
          'montanttransport' qui fait foi. Un lecteur qui recalculerait l'un par l'autre afficherait
          zéro sur un montant bien réel.
    */
    public function donneUnPrixAuKilo(): bool
    {
        return $this === self::AU_KILO;
    }
}
