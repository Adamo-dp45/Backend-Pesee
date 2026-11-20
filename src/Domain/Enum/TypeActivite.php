<?php

namespace App\Domain\Enum;

/*
    - Les gestes que le journal d'audit retient. La liste n'est pas « tout ce qu'on peut faire » : le
      grand livre trace déjà l'argent — qui, combien, solde avant, solde après — et le redoubler ici
      donnerait deux versions d'une même vérité, qui finiraient par diverger.

    - N'y figure donc que ce qui ne laissait AUCUNE trace exploitable. Les entités portent bien un
      'updatedBy', mais il ne dit ni quand, ni quoi, ni depuis quelle valeur : il est écrasé par la
      modification suivante. Renouveler les identifiants d'un poste ne laissait même pas cela.

    - Un cas par SENS ('SITE_BLOQUE' et 'SITE_DEBLOQUE') plutôt qu'un seul type à lire dans le
      libellé : c'est ce qui permet de filtrer « montre-moi les blocages » sans fouiller du texte.
*/
enum TypeActivite: string
{
    /*
        - Le plus important des trois groupes. Un prix décide de ce que touche un planteur, et il
          change la valeur des pesées non encore payées. Rien ne conservait l'ancien.
    */
    case PRODUIT_TARIFE = 'PRODUIT_TARIFE';         // Prix au kilo d'un produit
    case FOURNISSEUR_TARIFE = 'FOURNISSEUR_TARIFE'; // Prix du transport payé à un planteur
    case TARIF_CATEGORIE = 'TARIF_CATEGORIE';             // La grille d'une catégorie de planteurs
    case TARIF_FOURNISSEUR = 'TARIF_FOURNISSEUR';         // Un tarif négocié avec un planteur en propre
    case FOURNISSEUR_CATEGORIE = 'FOURNISSEUR_CATEGORIE'; // Le classement d'un planteur : il change ses prix d'un coup
    case BAREME_TRANSPORT = 'BAREME_TRANSPORT';           // La façon dont un trajet se facture, pour tous ceux qui suivent ce barème
    case PESEE_PRIX = 'PESEE_PRIX';                       // Le prix écrit à la main sur UNE pesée : la prime

    /*
        - La mise en compte. Elle décide de ce qu'un planteur touche au guichet, exactement comme un
          prix — mais contrairement à l'argent qui bouge, elle n'écrit RIEN dans le grand livre des
          caisses, puisqu'aucun billet ne change de main. Sans ces deux cas, ouvrir ou fermer le
          robinet de l'épargne de quelqu'un ne laisserait aucune trace.
    */
    case FOURNISSEUR_PRELEVEMENT = 'FOURNISSEUR_PRELEVEMENT'; // Ouverture ou changement du taux
    case FOURNISSEUR_PRELEVEMENT_ARRET = 'FOURNISSEUR_PRELEVEMENT_ARRET';
    case RETRAIT_ACCORDE = 'RETRAIT_ACCORDE'; // Accorder un retrait n'écrit aucun mouvement : seul ce journal en garde trace

    // Ce qui coupe ou rouvre un accès
    case SITE_BLOQUE = 'SITE_BLOQUE';
    case SITE_DEBLOQUE = 'SITE_DEBLOQUE';
    case SITE_OPERATEUR = 'SITE_OPERATEUR';         // Qui tient la caisse de ce poste
    case SITE_IDENTIFIANTS = 'SITE_IDENTIFIANTS';   // Renouvellement du mot de passe du poste
    case USER_SUSPENDU = 'USER_SUSPENDU';
    case USER_REACTIVE = 'USER_REACTIVE';
    case USER_ROLE = 'USER_ROLE';
    case ENTREPRISE_DESACTIVEE = 'ENTREPRISE_DESACTIVEE';
    case ENTREPRISE_REACTIVEE = 'ENTREPRISE_REACTIVEE';

    /*
        - La corbeille. 'deletedBy' et 'deletedAt' disent qui a supprimé — mais la restauration les
          efface, et la purge efface la ligne entière. Sans ces trois cas, un référentiel peut
          disparaître définitivement sans que rien n'en garde le souvenir.
    */
    case CORBEILLE_SUPPRESSION = 'CORBEILLE_SUPPRESSION';
    case CORBEILLE_RESTAURATION = 'CORBEILLE_RESTAURATION';
    case CORBEILLE_PURGE = 'CORBEILLE_PURGE';

    public function libelle(): string
    {
        return match($this) {
            self::PRODUIT_TARIFE => 'Tarification d\'un produit',
            self::FOURNISSEUR_TARIFE => 'Prix du transport d\'un planteur',
            self::TARIF_CATEGORIE => 'Tarifs d\'une catégorie',
            self::TARIF_FOURNISSEUR => 'Tarifs propres d\'un planteur',
            self::FOURNISSEUR_CATEGORIE => 'Classement d\'un planteur',
            self::BAREME_TRANSPORT => 'Barème de transport',
            self::PESEE_PRIX => 'Prix au kilo d\'une pesée',
            self::FOURNISSEUR_PRELEVEMENT => 'Ouverture d\'une mise en compte',
            self::FOURNISSEUR_PRELEVEMENT_ARRET => 'Arrêt d\'une mise en compte',
            self::RETRAIT_ACCORDE => 'Accord d\'un retrait d\'épargne',
            self::SITE_BLOQUE => 'Blocage d\'un pont bascule',
            self::SITE_DEBLOQUE => 'Déblocage d\'un pont bascule',
            self::SITE_OPERATEUR => 'Affectation de l\'opérateur',
            self::SITE_IDENTIFIANTS => 'Renouvellement des identifiants d\'un poste',
            self::USER_SUSPENDU => 'Suspension d\'un compte',
            self::USER_REACTIVE => 'Réactivation d\'un compte',
            self::USER_ROLE => 'Changement de rôle',
            self::ENTREPRISE_DESACTIVEE => 'Désactivation d\'une entreprise',
            self::ENTREPRISE_REACTIVEE => 'Réactivation d\'une entreprise',
            self::CORBEILLE_SUPPRESSION => 'Mise à la corbeille',
            self::CORBEILLE_RESTAURATION => 'Restauration depuis la corbeille',
            self::CORBEILLE_PURGE => 'Suppression définitive',
        };
    }
}
