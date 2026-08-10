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
        'montantPaye' => 0,
        'montantRestant' => 0,
    ];

    /*
        - 'entreprise' vaut null pour un opérateur : il ne voit que la caisse de ses ponts bascules.
    */
    /** @var array{entreprise: int|null, sites: int} */
    #[Groups(['read:Statistique'])]
    public array $soldes = ['entreprise' => null, 'sites' => 0];

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
