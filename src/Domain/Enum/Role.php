<?php

namespace App\Domain\Enum;

/*
    - Les valeurs sont directement les chaînes attendues par Symfony, ce qui évite de semer des
      littéraux 'ROLE_ADMIN' dans le code.
    - Pas de 'role_hierarchy' : chaque compte porte exactement un rôle, et c'est 'AccesVoter' qui
      dit ce que ce rôle peut faire.
*/
enum Role: string
{
    case SUPER_ADMIN = 'ROLE_SUPER_ADMIN'; // Provisionne les entreprises et met les postes en service, n'accède ni aux pesées ni à la caisse
    case ADMIN = 'ROLE_ADMIN';             // Gère son entreprise : sites, comptes, référentiels, soldes et paiements
    case AGENT = 'ROLE_AGENT';             // Consulte toute l'entreprise et gère les opérateurs, sans toucher aux soldes
    case OPERATEUR = 'ROLE_OPERATEUR';     // Tient la caisse des sites qui lui sont affectés et paie les planteurs
    case SITE = 'ROLE_SITE';               // Compte machine d'un pont bascule, utilisé par le seul logiciel de pesée

    public function libelle(): string
    {
        return match($this) {
            self::SUPER_ADMIN => 'Super administrateur',
            self::ADMIN => 'Administrateur',
            self::AGENT => 'Agent',
            self::OPERATEUR => 'Opérateur',
            self::SITE => 'Pont bascule',
        };
    }

    /*
        - Ce qu'une entreprise peut attribuer à un humain. 'SUPER_ADMIN' ne s'attribue pas depuis
          une entreprise, et 'SITE' est engendré avec son pont bascule.
    */
    /** @return list<self> */
    public static function attribuables(): array
    {
        return [self::ADMIN, self::AGENT, self::OPERATEUR];
    }

    /** Les mêmes en chaînes, pour les contraintes de validation et les listes déroulantes. */
    /** @return list<string> */
    public static function valeursAttribuables(): array
    {
        return array_map(fn(self $role) => $role->value, self::attribuables());
    }
}
