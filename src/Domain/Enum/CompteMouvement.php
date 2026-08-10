<?php

namespace App\Domain\Enum;

/*
    - Il y a deux niveaux de caisse : le solde global de l'entreprise, et le solde de chaque pont bascule. Un seul grand livre ('MouvementCaisse') les enregistre tous les deux, et c'est ce champ qui dit sur lequel porte la ligne.
    - Doter un site écrit donc DEUX lignes : un DEBIT sur le compte ENTREPRISE et un CREDIT sur le compte SITE. L'argent ne se crée jamais, il se déplace.
*/
enum CompteMouvement: string
{
    case ENTREPRISE = 'ENTREPRISE';
    case SITE = 'SITE';

    public function libelle(): string
    {
        return match($this) {
            self::ENTREPRISE => 'Caisse de l\'entreprise',
            self::SITE => 'Caisse du pont bascule',
        };
    }
}
