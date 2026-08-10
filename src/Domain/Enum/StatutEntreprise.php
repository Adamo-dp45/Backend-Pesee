<?php

namespace App\Domain\Enum;

/*
    - Désactiver une entreprise bloque la connexion de TOUS ses utilisateurs, administrateurs compris. C'est le levier du super administrateur, typiquement en cas d'impayé.
    - La vérification se fait à deux endroits : 'UserChecker' à la connexion, et 'JWTSubscriber' sur chaque requête authentifiée, sinon un jeton émis avant la désactivation resterait valable jusqu'à son expiration.
*/
enum StatutEntreprise: string
{
    case ACTIVE = 'ACTIVE';
    case DESACTIVEE = 'DESACTIVEE';

    public function libelle(): string
    {
        return match($this) {
            self::ACTIVE => 'Active',
            self::DESACTIVEE => 'Désactivée',
        };
    }

    public function estActive(): bool
    {
        return $this === self::ACTIVE;
    }

    public function inverse(): self
    {
        return $this === self::ACTIVE ? self::DESACTIVEE : self::ACTIVE;
    }
}
