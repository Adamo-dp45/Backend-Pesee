<?php

namespace App\Domain\Enum;

/*
    - Motif d'une ligne du grand livre. Chaque type impose son compte ET son sens : le service de caisse ne reçoit que le type et en déduit le reste, il est donc impossible d'écrire une dotation en débit sur un site ou un paiement en crédit.
    - Il n'existe volontairement PAS d'ajustement libre : il écrirait une ligne isolée, et ferait donc apparaître ou disparaître de l'argent. Tout mouvement va d'un compte à un autre.

    - LES DEUX CAS BANCAIRES SONT LES SEULS PAIEMENTS IMPUTÉS À L'ENTREPRISE. Un chèque est tiré sur le compte en banque, que l'entreprise seule détient : le tiroir du poste ne perd pas un billet, et le débiter aurait fait mentir le contrôle de caisse de l'opérateur. Deux cas de plus plutôt qu'un 'compte()' qui dépendrait d'un paramètre : ce fichier tient à ce que le TYPE décide seul du compte et du sens, et c'est ce qui rend impossible d'écrire un paiement dans le mauvais sens.
*/
enum TypeMouvement: string
{
    case RECHARGE = 'RECHARGE';           // L'entreprise met de l'argent dans le système
    case ATTRIBUTION = 'ATTRIBUTION';     // Sortie de l'entreprise vers un site — contrepartie de DOTATION
    case DOTATION = 'DOTATION';           // Entrée dans la caisse du site
    case PAIEMENT = 'PAIEMENT';           // Sortie de la caisse du site vers un planteur
    case REMBOURSEMENT = 'REMBOURSEMENT'; // Retour en caisse d'un versement échoué
    case PAIEMENT_BANCAIRE = 'PAIEMENT_BANCAIRE';           // Règlement par chèque : sortie de la banque de l'ENTREPRISE
    case REMBOURSEMENT_BANCAIRE = 'REMBOURSEMENT_BANCAIRE'; // Retour en banque d'un règlement par chèque annulé

    public function libelle(): string
    {
        return match($this) {
            self::RECHARGE => 'Recharge du solde de l\'entreprise',
            self::ATTRIBUTION => 'Montant attribué à un pont bascule',
            self::DOTATION => 'Dotation reçue de l\'entreprise',
            self::PAIEMENT => 'Paiement d\'un planteur',
            self::REMBOURSEMENT => 'Remboursement d\'un paiement échoué',
            self::PAIEMENT_BANCAIRE => 'Paiement d\'un planteur par chèque',
            self::REMBOURSEMENT_BANCAIRE => 'Remboursement d\'un chèque annulé',
        };
    }

    // Sur quelle caisse la ligne s'impute
    public function compte(): CompteMouvement
    {
        return match($this) {
            self::RECHARGE, self::ATTRIBUTION, self::PAIEMENT_BANCAIRE, self::REMBOURSEMENT_BANCAIRE => CompteMouvement::ENTREPRISE,
            self::DOTATION, self::PAIEMENT, self::REMBOURSEMENT => CompteMouvement::SITE,
        };
    }

    /*
        - Le type à écrire pour un versement, et celui de son remboursement, selon la caisse que le mode de règlement vide. Les deux vivent ici, à côté du compte et du sens qu'ils imposent : les disperser dans le service de caisse aurait rouvert la porte que ce fichier ferme.

        @return array{0: self, 1: self} le débit, puis sa contrepartie en cas d'échec
    */
    public static function pourUnVersement(TypeCaisse $caisse): array
    {
        return $caisse === TypeCaisse::BANCAIRE
            ? [self::PAIEMENT_BANCAIRE, self::REMBOURSEMENT_BANCAIRE]
            : [self::PAIEMENT, self::REMBOURSEMENT];
    }

    public function sens(): SensMouvement
    {
        return match($this) {
            self::RECHARGE, self::DOTATION, self::REMBOURSEMENT, self::REMBOURSEMENT_BANCAIRE => SensMouvement::CREDIT,
            self::ATTRIBUTION, self::PAIEMENT, self::PAIEMENT_BANCAIRE => SensMouvement::DEBIT,
        };
    }
}
