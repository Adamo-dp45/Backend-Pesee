<?php

namespace App\Domain\Enum;

/*
    - D'OÙ VIENT LE PRIX QU'UNE PESÉE A RETENU. Trois sauts possibles, et sans cette trace ils sont
      indiscernables : deux pesées valorisées à 470 F le kilo peuvent l'être parce que le planteur a
      son propre tarif, parce que sa catégorie en a un, ou parce que c'est le prix du produit. La
      question « pourquoi mon voisin touche 500 et moi 470 » n'a alors aucune réponse dans les
      données, et personne au guichet ne peut la trancher.

    - FIGÉE SUR LA PESÉE à côté de 'prixunitaire', et pour la même raison : le prix retenu est un
      fait daté. Supprimer le tarif d'un planteur au profit d'un tarif de catégorie identique
      laisserait, sans ça, une origine qui ment sur un montant pourtant juste.

    - 'PRODUIT' n'est pas un échec : c'est le cas de la quasi-totalité des planteurs, ceux qu'aucune
      catégorie ne distingue. Un prix absent se lit sur 'prixunitaire' à null, pas ici.
*/
enum OrigineTarif: string
{
    case PLANTEUR = 'PLANTEUR';   // Un tarif propre à ce planteur, qui l'emporte sur tout le reste
    case CATEGORIE = 'CATEGORIE'; // Le tarif de sa catégorie pour ce produit
    case PRODUIT = 'PRODUIT';     // Le prix du produit, qui vaut pour l'entreprise entière
    /*
        - LA QUATRIÈME ORIGINE N'EST PAS UN SAUT : elle dit qu'aucun des trois n'a décidé, et qu'un
          administrateur a écrit le prix sur CETTE pesée. C'est le cas de la prime — une récolte
          particulièrement propre, un planteur qu'on veut retenir — et il est par nature invisible
          dans les référentiels : rien, ni sur le produit, ni sur la catégorie, ni sur le planteur,
          n'expliquerait le montant.

        - ELLE FIGE LA PART RÉCOLTE. Une main l'emporte sur les trois sauts, sans quoi la prochaine
          tarification du produit effacerait la prime sans que personne ne la voie partir.
    */
    case MANUEL = 'MANUEL';

    public function libelle(): string
    {
        return match($this) {
            self::PLANTEUR => 'Tarif du planteur',
            self::CATEGORIE => 'Tarif de sa catégorie',
            self::PRODUIT => 'Prix du produit',
            self::MANUEL => 'Prix saisi sur la pesée',
        };
    }

    /** Ce qu'on affiche sous un montant pour expliquer d'où il sort. */
    public function explication(): string
    {
        return match($this) {
            self::PLANTEUR => 'Un tarif négocié avec ce planteur, qui l\'emporte sur celui de sa catégorie.',
            self::CATEGORIE => 'Le tarif de la catégorie à laquelle ce planteur appartient.',
            self::PRODUIT => 'Le prix du produit, celui de tous les planteurs sans tarif particulier.',
            self::MANUEL => 'Un prix écrit à la main sur cette pesée seule, au-dessus des tarifs. Il ne bougera plus.',
        };
    }
}
