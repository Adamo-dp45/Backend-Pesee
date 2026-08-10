<?php

namespace App\Domain\Enum;

/*
    - État du règlement d'une pesée vis-à-vis du planteur. Le patron a demandé que le paiement puisse se faire « en un temps ou en plusieurs temps » : c'est ce qui justifie l'état intermédiaire 'PARTIEL'.
*/
enum StatutReglement: string
{
    case NON_PAYE = 'NON_PAYE';
    case PARTIEL = 'PARTIEL';
    case SOLDE = 'SOLDE';

    public function libelle(): string
    {
        return match($this) {
            self::NON_PAYE => 'Non payée',
            self::PARTIEL => 'Partiellement payée',
            self::SOLDE => 'Soldée',
        };
    }

    /*
        - La règle de transition vit ici plutôt que dans un service : elle est appelée depuis la synchronisation (recalcul du montant dû) et depuis la confirmation d'un paiement, et les deux doivent trancher exactement pareil.
        - '>=' et non '===' : si un tarif baisse après un paiement, le montant déjà versé peut dépasser le montant dû. La pesée est alors soldée, pas bloquée dans un état incohérent.
    */
    public static function depuis(?int $montantdu, int $montantpaye): self
    {
        if($montantpaye <= 0) {
            return self::NON_PAYE;
        }

        if($montantdu !== null && $montantpaye >= $montantdu) {
            return self::SOLDE;
        }

        return self::PARTIEL;
    }
}
