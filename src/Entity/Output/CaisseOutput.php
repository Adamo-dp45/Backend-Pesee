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

    /** @var list<array{siteId: int, site: string, solde: int, immobilise: int, operateur: string|null}> */
    #[Groups(['read:Statistique'])]
    public array $parSite = [];
}
