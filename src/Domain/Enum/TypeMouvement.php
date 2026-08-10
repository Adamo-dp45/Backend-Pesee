<?php

namespace App\Domain\Enum;

/*
    - Motif d'une ligne du grand livre. Chaque type impose son compte ET son sens : le service de caisse ne reçoit que le type et en déduit le reste, il est donc impossible d'écrire une dotation en débit sur un site ou un paiement en crédit.
    - Il n'existe volontairement PAS d'ajustement libre : il écrirait une ligne isolée, et ferait donc apparaître ou disparaître de l'argent. Tout mouvement va d'un compte à un autre.
*/
enum TypeMouvement: string
{
    case RECHARGE = 'RECHARGE';           // L'entreprise met de l'argent dans le système
    case ATTRIBUTION = 'ATTRIBUTION';     // Sortie de l'entreprise vers un site — contrepartie de DOTATION
    case DOTATION = 'DOTATION';           // Entrée dans la caisse du site
    case PAIEMENT = 'PAIEMENT';           // Sortie de la caisse du site vers un planteur
    case REMBOURSEMENT = 'REMBOURSEMENT'; // Retour en caisse d'un versement échoué

    public function libelle(): string
    {
        return match($this) {
            self::RECHARGE => 'Recharge du solde de l\'entreprise',
            self::ATTRIBUTION => 'Montant attribué à un pont bascule',
            self::DOTATION => 'Dotation reçue de l\'entreprise',
            self::PAIEMENT => 'Paiement d\'un planteur',
            self::REMBOURSEMENT => 'Remboursement d\'un paiement échoué',
        };
    }

    // Sur quelle caisse la ligne s'impute
    public function compte(): CompteMouvement
    {
        return match($this) {
            self::RECHARGE, self::ATTRIBUTION => CompteMouvement::ENTREPRISE,
            self::DOTATION, self::PAIEMENT, self::REMBOURSEMENT => CompteMouvement::SITE,
        };
    }

    public function sens(): SensMouvement
    {
        return match($this) {
            self::RECHARGE, self::DOTATION, self::REMBOURSEMENT => SensMouvement::CREDIT,
            self::ATTRIBUTION, self::PAIEMENT => SensMouvement::DEBIT,
        };
    }
}
