<?php

namespace App\Domain\Enum;

/*
    - LES DEUX MOITIÉS D'UNE PESÉE : ce que vaut la récolte, et ce que coûte le transport. Elles se
      règlent séparément depuis qu'un planteur peut être payé de l'une sans l'autre, et elles se
      revalorisent séparément pour la même raison.

    - CE QUE CET ENUM EXISTE POUR EMPÊCHER. Le garde-fou de la revalorisation — « ne pas réécrire
      une part déjà payée » — se choisissait sur le TYPE PHP de la cause : un 'Produit' gardait la
      récolte, un 'Fournisseur' gardait le transport. C'était vrai tant qu'un planteur ne portait
      qu'un prix de transport. Avec un tarif propre et une catégorie, un 'Fournisseur' touche
      désormais la RÉCOLTE — et le garde-fou aurait regardé la mauvaise colonne : une pesée dont le
      transport est déjà payé aurait été écartée, sa récolte jamais revalorisée.

    - Un type n'est pas une intention. L'appelant dit ce qu'il change ; il est le seul à le savoir.
*/
enum PartPesee: string
{
    case RECOLTE = 'RECOLTE';     // 'montantdu', borné par 'montantpayeproduit'
    case TRANSPORT = 'TRANSPORT'; // 'montanttransport', borné par 'montantpayetransport'

    /** La colonne du déjà-payé qui protège cette part d'une réécriture. */
    public function champPaye(): string
    {
        return match($this) {
            self::RECOLTE => 'montantpayeproduit',
            self::TRANSPORT => 'montantpayetransport',
        };
    }

    public function libelle(): string
    {
        return match($this) {
            self::RECOLTE => 'la récolte',
            self::TRANSPORT => 'le transport',
        };
    }
}
