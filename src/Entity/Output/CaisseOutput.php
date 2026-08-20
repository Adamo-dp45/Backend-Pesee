<?php

namespace App\Entity\Output;

use Symfony\Component\Serializer\Attribute\Groups;

/*
    - L'état de la trésorerie : ce qu'il reste à l'entreprise, ce que tient chaque pont bascule,
      et ce qui est immobilisé par des versements en cours.
*/
final class CaisseOutput
{
    // null pour un opérateur : la trésorerie globale ne le regarde pas, et zéro se lirait
    // « l'entreprise n'a plus rien »
    #[Groups(['read:Statistique'])]
    public ?int $soldeEntreprise = null;

    #[Groups(['read:Statistique'])]
    public int $soldeSites = 0;

    /*
        - Montant sorti des caisses mais pas encore confirmé par l'agrégateur. Il explique
          l'écart entre ce que l'opérateur croit avoir et ce qu'il peut réellement engager.
    */
    #[Groups(['read:Statistique'])]
    public int $montantImmobilise = 0;

    #[Groups(['read:Statistique'])]
    public int $demandesEnAttente = 0;

    /*
        - Combien de versements attendent encore une réponse de l'agrégateur, et depuis combien de
          minutes pour le plus ancien. Ce n'est pas une statistique de gestion : c'est le témoin de
          la commande de réconciliation. Elle est planifiée, donc elle peut s'arrêter sans bruit —
          et alors les fonds restent immobilisés jusqu'à ce qu'un opérateur se plaigne de ne plus
          pouvoir payer. Un versement en vol depuis des heures ne peut vouloir dire que ça.
        - 'null' quand rien n'est en vol : zéro minute se lirait comme « à l'instant ».
    */
    #[Groups(['read:Statistique'])]
    public int $versementsEnVol = 0;

    #[Groups(['read:Statistique'])]
    public ?int $attenteMinutes = null;

    /** @var list<array{siteId: int, site: string, solde: int, immobilise: int, operateur: string|null}> */
    #[Groups(['read:Statistique'])]
    public array $parSite = [];
}
