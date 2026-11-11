<?php

namespace App\Domain\Enum;

/*
    - Deux natures d'argent, qui ne se mélangent jamais : les billets que l'opérateur a dans son tiroir, et le flotte mobile money du poste. Elles ne se valent pas et ne se convertissent pas l'une en l'autre — recharger le flotte passe par l'agrégateur, et vider le tiroir passe par la banque. Aucun des deux gestes n'a lieu dans cette application.

    - Chaque compte en porte donc DEUX, à chaque niveau : l'entreprise comme chaque pont bascule. Un seul solde par compte obligeait à payer un planteur en espèces sur le même argent qui servait au mobile money, et le découvert ne se voyait qu'au moment d'ouvrir le tiroir.

    - 'CompteMouvement' dit de QUI est la caisse (l'entreprise ou le poste), celui-ci dit LAQUELLE des deux. Les deux ensemble désignent un solde, et un seul.
*/
enum TypeCaisse: string
{
    case PHYSIQUE = 'PHYSIQUE';   // Les espèces, dans le tiroir de l'opérateur
    case NUMERIQUE = 'NUMERIQUE'; // Le flotte mobile money, chez l'agrégateur

    public function libelle(): string
    {
        return match($this) {
            self::PHYSIQUE => 'Caisse physique',
            self::NUMERIQUE => 'Caisse numérique',
        };
    }

    /** Ce qu'on dit à l'écran pour expliquer ce qu'elle paie. */
    public function description(): string
    {
        return match($this) {
            self::PHYSIQUE => 'Les espèces remises en main propre',
            self::NUMERIQUE => 'Le flotte mobile money',
        };
    }

    /** @return list<self> */
    public static function toutes(): array
    {
        return [self::PHYSIQUE, self::NUMERIQUE];
    }
}
