<?php

namespace App\Domain\Enum;

/*
    - Bloquer un pont bascule ne coupe pas la consultation de ses données : ça coupe la synchronisation. Le logiciel de pesée continue d'enregistrer localement mais ses envois sont refusés, et plus aucun paiement ne peut partir de la caisse du site.
*/
enum StatutSite: string
{
    case ACTIF = 'ACTIF';
    case BLOQUE = 'BLOQUE';

    public function libelle(): string
    {
        return match($this) {
            self::ACTIF => 'Actif',
            self::BLOQUE => 'Bloqué',
        };
    }

    public function accepteLaSynchronisation(): bool
    {
        return $this === self::ACTIF;
    }

    public function inverse(): self
    {
        return $this === self::ACTIF ? self::BLOQUE : self::ACTIF;
    }
}
