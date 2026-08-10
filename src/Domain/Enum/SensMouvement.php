<?php

namespace App\Domain\Enum;

enum SensMouvement: string
{
    case CREDIT = 'CREDIT';
    case DEBIT = 'DEBIT';

    public function libelle(): string
    {
        return match($this) {
            self::CREDIT => 'Entrée',
            self::DEBIT => 'Sortie',
        };
    }

    /*
        - Permet d'écrire 'soldeApres = soldeAvant + $sens->signe() * $montant' au lieu de dupliquer un 'if' dans chaque méthode du service de caisse. Les montants sont donc toujours stockés positifs, le sens portant l'information.
    */
    public function signe(): int
    {
        return $this === self::CREDIT ? 1 : -1;
    }
}
