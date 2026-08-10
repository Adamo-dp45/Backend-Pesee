<?php

namespace App\Domain\Enum;

/*
    - L'écran « PESEE OPERATEUR » du logiciel de pesée possède un panneau « PESEE EN ATTENTE » : un camion peut être entré (premier passage, poids1) sans être encore ressorti. Une pesée arrive donc parfois incomplète, et la synchronisation suivante la complète.
    - Tant qu'elle est 'EN_COURS' elle n'a ni poids net, ni montant, ni créance : visible, mais exclue des statistiques de production et des paiements.
*/
enum StatutOperation: string
{
    case EN_COURS = 'EN_COURS';   // En attente du second passage
    case TERMINEE = 'TERMINEE';

    public function libelle(): string
    {
        return match($this) {
            self::EN_COURS => 'En attente du second passage',
            self::TERMINEE => 'Terminée',
        };
    }

    public function estTerminee(): bool
    {
        return $this === self::TERMINEE;
    }

    /*
        - Le signal de fin est le SECOND POIDS, et surtout pas la date de second passage.

          L'écran « PESEE OPERATEUR » pré-remplit Date Pesée 2 et Heure Pesée 2 avec celles du premier passage dès la création de la pesée, alors que Poids 2 vaut encore 0. Se fier à la date marquerait donc « terminée » toute pesée dès son premier envoi, lui inventerait un poids net et une créance, et rendrait payable un camion encore sur le pont.

        - Un second passage réel produit toujours un poids strictement positif, que le camion reparte chargé (expédition) ou vidé (réception).
    */
    public static function depuis(?int $poids2): self
    {
        return ($poids2 !== null && $poids2 > 0) ? self::TERMINEE : self::EN_COURS;
    }
}
