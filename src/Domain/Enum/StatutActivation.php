<?php

namespace App\Domain\Enum;

/*
    - Statut des comptes utilisateurs. L'entreprise et le site ont les leurs : leur suspension n'a ni les mêmes conséquences, ni le même vocabulaire côté utilisateur.
*/
enum StatutActivation: string
{
    case ACTIF = 'ACTIF';
    case SUSPENDU = 'SUSPENDU'; // Connexion refusée, et un jeton déjà émis ne survit pas

    public function libelle(): string
    {
        return match($this) {
            self::ACTIF => 'Actif',
            self::SUSPENDU => 'Suspendu',
        };
    }

    public function estActif(): bool
    {
        return $this === self::ACTIF;
    }

    public function inverse(): self
    {
        return $this === self::ACTIF ? self::SUSPENDU : self::ACTIF;
    }
}
