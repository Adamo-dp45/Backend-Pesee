<?php

namespace App\Entity\Output;

use Symfony\Component\Serializer\Attribute\Groups;

/*
    - Tout ce qu'affiche le tableau de bord, en une seule réponse. C'est délibéré : l'écran
      d'accueil sur une connexion de terrain ne doit pas enchaîner six appels.
*/
final class TableauDeBordOutput
{
    /** @var array{du: string|null, au: string|null} */
    #[Groups(['read:Statistique'])]
    public array $periode = ['du' => null, 'au' => null];

    /** @var array{nbPesees: int, nbEnCours: int, tonnageNet: int, montantDu: int, montantPaye: int, montantRestant: int} */
    #[Groups(['read:Statistique'])]
    public array $totaux = [
        'nbPesees' => 0,
        'nbEnCours' => 0,
        'tonnageNet' => 0,
        'montantDu' => 0,
        'montantTransport' => 0,
        'retenue' => 0,
        'montantPaye' => 0,
        'montantRestant' => 0,
    ];

    /*
        - LES TROIS CHIFFRES D'UN POSTE, et non le seul solde. Un opérateur ne peut pas dépenser
          tout ce que sa caisse affiche : l'immobilisé en est déjà sorti sans réponse de la
          passerelle, et c'est 'disponible' qu'il regarde avant d'engager un versement. L'accueil
          n'annonçait que le solde brut, donc plus d'argent qu'il n'y en a.

        - L'ÉPARGNE DUE NE SE SOUSTRAIT PAS, et c'est le piège de ce bloc. Elle n'est dans aucune
          caisse : au prélèvement, pas un billet ne bouge, l'argent reste là et c'est une DETTE qui
          naît à côté. La retrancher du disponible ferait disparaître deux fois des espèces qui
          sont bien dans le tiroir. Elle se lit à côté, jamais dedans — même règle que 'CaisseOutput'.

        - 'entreprise' et 'epargneDue' valent null pour un opérateur : il tient la caisse d'un poste,
          la trésorerie globale et la dette de l'entreprise ne le regardent pas. Null et non zéro,
          qui se lirait « l'entreprise n'a plus rien ».
    */
    /** @var array{entreprise: int|null, sites: int, immobilise: int, disponible: int, epargneDue: int|null} */
    #[Groups(['read:Statistique'])]
    public array $soldes = [
        'entreprise' => null,
        'sites' => 0,
        'immobilise' => 0,
        'disponible' => 0,
        'epargneDue' => null,
    ];

    /*
        - LA CRÉANCE, ET CE N'EST PAS 'totaux.montantRestant'. Celui-là ne compte que les pesées de
          la période affichée ; celle-ci compte TOUTE la file du guichet, quelle que soit la date.
          Les deux portaient le même nom sur deux écrans et ne donnaient pas le même nombre, sans
          que rien ne le dise. C'est la créance qui dimensionne la trésorerie, pas le reste du mois.

        - ELLE SORT DE 'totauxAPayer()', LA MÊME MÉTHODE QUE L'ÉCRAN « À PAYER » — pas une requête
          qui lui ressemble. Deux façons de compter ce qu'on doit finissent toujours par diverger,
          et c'est 'TotauxAPayerTest' qui tient celle-ci contre les cas limites.

        - 'nbSansPrix' EST LE SEUL BLOCAGE QU'ON SACHE COMPTER EXACTEMENT : une pesée dont le
          produit n'est pas tarifé ne vaut rien, ne sort jamais de la file, et personne ne la
          remarque tant qu'un planteur ne réclame pas. Les autres motifs tiennent à une règle à
          branches que recopier en SQL ferait diverger.
    */
    /** @var array{total: int, produit: int, transport: int, nbPesees: int, nbSansPrix: int} */
    #[Groups(['read:Statistique'])]
    public array $creance = [
        'total' => 0,
        'produit' => 0,
        'transport' => 0,
        'nbPesees' => 0,
        'nbSansPrix' => 0,
    ];

    /*
        - CE QUI ATTEND UNE DÉCISION. Trois guichets de demandes — doter une caisse, ouvrir une
          mise en compte, rendre son épargne à un planteur — et il fallait ouvrir trois écrans pour
          savoir s'il y avait quelque chose à faire. Une demande qui dort ne lève rien : l'opérateur
          attend ses fonds, le planteur attend son argent, et personne n'est prévenu.

        - LE DÉTAIL ET NON LE TOTAL SEUL : les trois ne se traitent pas au même endroit, et « 4
          demandes » n'indique pas quel écran ouvrir.
    */
    /** @var array{solde: int, prelevement: int, retrait: int, total: int} */
    #[Groups(['read:Statistique'])]
    public array $demandes = ['solde' => 0, 'prelevement' => 0, 'retrait' => 0, 'total' => 0];

    /** @var list<array{jour: string, tonnageNet: int, nbPesees: int}> */
    #[Groups(['read:Statistique'])]
    public array $parJour = [];

    /** @var list<array{produit: string, tonnageNet: int, montantDu: int, nbPesees: int}> */
    #[Groups(['read:Statistique'])]
    public array $parProduit = [];

    /** @var list<array{siteId: int, site: string, tonnageNet: int, montantDu: int, montantPaye: int, nbPesees: int, solde: int}> */
    #[Groups(['read:Statistique'])]
    public array $parSite = [];

    /** @var list<array<string, mixed>> */
    #[Groups(['read:Statistique'])]
    public array $dernieresPesees = [];

    /*
        - Les ponts bascules dont on n'a rien reçu depuis plusieurs jours. Sans cette alerte, un
          poste en panne ne se remarque qu'en s'étonnant d'un tonnage anormalement bas, des
          semaines plus tard.
        - Deux cas distincts : le poste s'est tu ('joursSansEnvoi'), ou il n'a jamais été mis en
          service alors qu'il est déclaré depuis un moment ('joursDepuisCreation').
    */
    /** @var list<array{site: string, dernierEnvoi: string|null, joursSansEnvoi: int|null, joursDepuisCreation: int|null}> */
    #[Groups(['read:Statistique'])]
    public array $postesSilencieux = [];
}
