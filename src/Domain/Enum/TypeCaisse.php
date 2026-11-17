<?php

namespace App\Domain\Enum;

/*
    - Trois natures d'argent, qui ne se mélangent jamais : les billets que l'opérateur a dans son tiroir, le flotte mobile money du poste, et le compte en banque de l'entreprise. Aucune ne se convertit en une autre — recharger le flotte passe par l'agrégateur, vider le tiroir passe par la banque. Aucun de ces gestes n'a lieu dans cette application.

    - ELLES NE VIVENT PAS TOUTES AU MÊME NIVEAU, et c'est la règle que porte ce fichier depuis l'arrivée du chèque. L'entreprise tient les trois ; un pont bascule n'en tient que deux. Un carnet de chèques ne se dote pas à une barrière de pesage : il n'y a rien à y remettre, et « doter un poste en banque » ne veut rien dire.

    - C'est pourquoi 'toutes()' ne suffit plus et que 'celles()' existe. Toute boucle qui construit des colonnes, des totaux ou une liste déroulante doit passer par la seconde, en disant de quel niveau elle parle. Avec 'toutes()', un poste afficherait une caisse bancaire éternellement à zéro, que personne ne pourrait alimenter — et un opérateur pourrait la réclamer.

    - 'CompteMouvement' dit de QUI est la caisse (l'entreprise ou le poste), celui-ci dit LAQUELLE. Les deux ensemble désignent un solde, et un seul.
*/
enum TypeCaisse: string
{
    case PHYSIQUE = 'PHYSIQUE';   // Les espèces, dans le tiroir de l'opérateur
    case NUMERIQUE = 'NUMERIQUE'; // Le flotte mobile money, chez l'agrégateur
    case BANCAIRE = 'BANCAIRE';   // Le compte en banque, sur lequel les chèques sont tirés. Entreprise SEULEMENT

    public function libelle(): string
    {
        return match($this) {
            self::PHYSIQUE => 'Caisse physique',
            self::NUMERIQUE => 'Caisse numérique',
            self::BANCAIRE => 'Caisse bancaire',
        };
    }

    /** Ce qu'on dit à l'écran pour expliquer ce qu'elle paie. */
    public function description(): string
    {
        return match($this) {
            self::PHYSIQUE => 'Les espèces remises en main propre',
            self::NUMERIQUE => 'Le flotte mobile money',
            self::BANCAIRE => 'Le compte en banque, pour les règlements par chèque',
        };
    }

    /* Vrai si un pont bascule peut la tenir. La banque est à l'entreprise, et à elle seule. */
    public function vitAuNiveauDuSite(): bool
    {
        return $this !== self::BANCAIRE;
    }

    /** @return list<self> */
    public static function toutes(): array
    {
        return [self::PHYSIQUE, self::NUMERIQUE, self::BANCAIRE];
    }

    /*
        - Les caisses que ce niveau de compte tient RÉELLEMENT. C'est elle qu'il faut appeler, et non 'toutes()', partout où l'on énumère pour afficher ou pour faire choisir.
    */
    /** @return list<self> */
    public static function celles(CompteMouvement $compte): array
    {
        return $compte === CompteMouvement::ENTREPRISE
            ? self::toutes()
            : array_values(array_filter(self::toutes(), static fn(self $caisse) => $caisse->vitAuNiveauDuSite()));
    }
}
