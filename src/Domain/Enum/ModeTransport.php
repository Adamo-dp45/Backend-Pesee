<?php

namespace App\Domain\Enum;

/*
    - COMMENT SE FACTURE UN TRAJET. Jusqu'ici il n'y avait qu'une façon : tant de francs par kilo
      transporté. C'est juste pour un planteur qui livre dix tonnes, absurde pour celui qui en
      apporte deux cents kilos depuis le même village — le camion a fait la même route.

    - DEUX MODES, ET UN SEUL S'APPLIQUE À LA FOIS. Un barème porte son mode et le champ de ce
      mode-là ; l'autre reste vide. Les mêler — un prix au kilo ET un forfait sur la même ligne —
      ouvrirait la question « lequel gagne ? », à laquelle aucune réponse n'est bonne.

    - IL Y EN AVAIT TROIS, et le troisième s'appelait « par tranches ». Il tenait une suite
      d'intervalles dans une entité à part, et allait chercher sur la fiche du planteur une
      DISTANCE que personne ne saisissait jamais — le poste crée les planteurs avec un libellé et
      rien d'autre. Le montant valait donc zéro, en silence, sur des pesées qui méritaient un
      transport. Une tranche est désormais le barème lui-même : « de 10 à 20 km : 1 000 F » est un
      FORFAIT de 1 000 F qui porte ses bornes, le planteur pointe dessus, et il n'y a plus rien à
      aller chercher ailleurs.

    - LE MODE EST FIGÉ SUR LA PESÉE, à côté du montant. Sans lui, « forfait à 0 F » et « aucun
      barème » sont la même ligne en base : un montant nul et rien pour dire pourquoi.
*/
enum ModeTransport: string
{
    case AU_KILO = 'AU_KILO'; // prix au kilo × poids net : le montant suit le tonnage
    case FORFAIT = 'FORFAIT'; // un montant, quel que soit le tonnage : c'est le voyage qu'on paie

    public function libelle(): string
    {
        return match($this) {
            self::AU_KILO => 'Au kilo',
            self::FORFAIT => 'Forfait',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::AU_KILO => 'Tant de francs par kilo transporté : le montant suit le tonnage.',
            self::FORFAIT => 'Un montant fixe par pesée, quel que soit le tonnage. C\'est le trajet qu\'on paie.',
        };
    }

    /*
        - Le seul mode qui produise un PRIX AU KILO figeable sur la pesée. Le forfait rend un
          montant sans prix unitaire : 'Operation::$prixtransport' y reste nul, et c'est
          'montanttransport' qui fait foi. Un lecteur qui recalculerait l'un par l'autre
          afficherait zéro sur un montant bien réel.
    */
    public function donneUnPrixAuKilo(): bool
    {
        return $this === self::AU_KILO;
    }
}
