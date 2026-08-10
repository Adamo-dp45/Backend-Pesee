<?php

namespace App\Domain\Enum;

/*
    - Cycle de vie d'un versement mobile money vers un planteur :
      EN_ATTENTE (créé, fonds réservés) → ENVOYE (accepté par la passerelle)
                                        → REUSSI  (confirmé par le webhook)
                                        → ECHOUE / ANNULE (fonds rendus à la caisse du site)
*/
enum StatutPaiement: string
{
    case EN_ATTENTE = 'EN_ATTENTE';
    case ENVOYE = 'ENVOYE';
    case REUSSI = 'REUSSI';
    case ECHOUE = 'ECHOUE';
    case ANNULE = 'ANNULE';

    public function libelle(): string
    {
        return match($this) {
            self::EN_ATTENTE => 'En attente',
            self::ENVOYE => 'Envoyé',
            self::REUSSI => 'Réussi',
            self::ECHOUE => 'Échoué',
            self::ANNULE => 'Annulé',
        };
    }

    /*
        - Un statut final ne bouge plus. Le webhook s'en sert pour être idempotent : recevoir deux fois la même notification ne doit pas rejouer le mouvement de caisse.
    */
    public function estFinal(): bool
    {
        return in_array($this, [self::REUSSI, self::ECHOUE, self::ANNULE], true);
    }

    /*
        - Vrai tant que l'argent est sorti de la caisse du site. Les fonds sont réservés dès la création, donc 'EN_ATTENTE' immobilise déjà : c'est ce qui empêche de dépenser deux fois la même somme pendant qu'un versement est en vol.
    */
    public function immobiliseLesFonds(): bool
    {
        return in_array($this, [self::EN_ATTENTE, self::ENVOYE, self::REUSSI], true);
    }

    /*
        - Les versements que la réconciliation doit réinterroger auprès de la passerelle si aucun webhook n'est arrivé.
    */
    /** @return list<self> */
    public static function enCours(): array
    {
        return [self::EN_ATTENTE, self::ENVOYE];
    }
}
