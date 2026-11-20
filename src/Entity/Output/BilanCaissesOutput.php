<?php

namespace App\Entity\Output;

use Symfony\Component\Serializer\Attribute\Groups;

/*
    - Le bilan des CAISSES d'une période : ce qui est entré, ce qui est sorti, et par quoi.

    - UN MONTANT DE MOUVEMENT EST TOUJOURS POSITIF — c'est le sens qui porte la direction. Les
      sommer sans distinguer donnerait un nombre qui n'est ni un flux ni un solde. D'où deux
      colonnes partout, 'entrees' et 'sorties', et leur différence nommée 'variation'.

    - CE N'EST PAS UN SOLDE. La variation dit ce que la période a fait bouger, pas ce que la caisse
      contient : l'état de la trésorerie se lit sur '/stats/caisse', qui donne les soldes du jour.

    - RIEN NE TRAVERSE entre deux caisses : une dotation sort de la caisse physique de l'entreprise
      et entre dans celle du poste. Elle apparaît donc DEUX fois, une par compte — c'est exact, et
      c'est pourquoi le compte fait partie du groupement.
*/
final class BilanCaissesOutput
{
    /** @var array{du: string|null, au: string|null} */
    #[Groups(['read:Statistique'])]
    public array $periode = ['du' => null, 'au' => null];

    /** @var array{nbMouvements: int, entrees: int, sorties: int, variation: int} */
    #[Groups(['read:Statistique'])]
    public array $totaux = [
        'nbMouvements' => 0,
        'entrees' => 0,
        'sorties' => 0,
        'variation' => 0,
    ];

    /** @var list<array{compte: string, libelleCompte: string, caisse: string, libelle: string, nombre: int, entrees: int, sorties: int, variation: int}> */
    #[Groups(['read:Statistique'])]
    public array $parCaisse = [];

    /** @var list<array{type: string, libelle: string, sens: string, nombre: int, montant: int}> */
    #[Groups(['read:Statistique'])]
    public array $parType = [];

    /** @var list<array{siteId: int, site: string, entrees: int, sorties: int, variation: int}> */
    #[Groups(['read:Statistique'])]
    public array $parSite = [];
}
