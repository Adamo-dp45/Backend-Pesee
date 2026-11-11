<?php

namespace App\Domain\Enum;

/*
    - État du règlement d'une pesée vis-à-vis du planteur. Le patron a demandé que le paiement puisse se faire « en un temps ou en plusieurs temps » : c'est ce qui justifie l'état intermédiaire 'PARTIEL'.
    - 'RETENU' est le cas des pesées dont l'épargne a tout absorbé : il n'y a rien à verser au guichet, et ce n'est pourtant ni un impayé ni un solde. Sans lui, ces pesées resteraient 'NON_PAYE' pour toujours et s'accumuleraient dans la file du guichet sans que rien ne soit à corriger.
*/
enum StatutReglement: string
{
    case NON_PAYE = 'NON_PAYE';
    case PARTIEL = 'PARTIEL';
    case SOLDE = 'SOLDE';
    case RETENU = 'RETENU';

    public function libelle(): string
    {
        return match($this) {
            self::NON_PAYE => 'Non payée',
            self::PARTIEL => 'Partiellement payée',
            self::SOLDE => 'Soldée',
            self::RETENU => 'Retenue sur épargne',
        };
    }

    /*
        - La règle de transition vit ici plutôt que dans un service : elle est appelée depuis la synchronisation (recalcul du montant dû) et depuis la confirmation d'un paiement, et les deux doivent trancher exactement pareil.
        - Elle se compare au montant PAYABLE et non au montant dû : une part de la pesée peut être retenue sur l'épargne du planteur, et elle ne lui sera pas versée au guichet. La pesée est soldée quand on a versé ce qu'on devait VERSER, pas ce que la pesée valait.
        - La méthode s'appelait 'depuis(montantdu, montantpaye)'. Le changement de nom est délibéré : il fait casser les trois appelants à la compilation. Gardé, l'ancien nom les aurait laissés passer le montant dû en silence, et une pesée intégralement réglée sur son payable serait restée 'PARTIEL' — la file du guichet ne se serait plus jamais vidée.
        - '>=' et non '===' : si un tarif baisse après un paiement, le montant déjà versé peut dépasser le payable. La pesée est alors soldée, pas bloquée dans un état incohérent.
        - La garde 'montantdu > 0' sur 'RETENU' n'est pas décorative : sans elle, une pesée dont le produit n'est pas tarifé — montant dû nul, donc payable nul — basculerait en 'RETENU' et ferait croire à une épargne là où il n'y a qu'un prix manquant.
    */
    public static function pour(?int $montantdu, int $montantpayable, int $montantpaye): self
    {
        if($montantpaye <= 0) {
            return $montantdu !== null && $montantdu > 0 && $montantpayable <= 0
                ? self::RETENU
                : self::NON_PAYE;
        }

        return $montantpaye >= $montantpayable ? self::SOLDE : self::PARTIEL;
    }
}
