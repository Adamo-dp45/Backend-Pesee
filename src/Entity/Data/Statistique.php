<?php

namespace App\Entity\Data;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use App\Entity\Output\CaisseOutput;
use App\Entity\Output\ProductionOutput;
use App\Entity\Output\ReferentielsOutput;
use App\Entity\Output\TableauDeBordOutput;
use App\State\Provider\CaisseProvider;
use App\State\Provider\ProductionProvider;
use App\State\Provider\ReferentielsProvider;
use App\State\Provider\TableauDeBordProvider;

/*
    - Classe sans persistance ni propriété : elle n'existe que pour porter les ressources de
      lecture agrégée. Chaque opération a son provider et son 'output' dédiés.

    - Volontairement des 'Get' et non des 'GetCollection' : sur une collection, API Platform
      attend une liste et enveloppe la réponse dans 'member', alors qu'on renvoie ici un objet
      unique.

    - Ces providers écrivent leurs requêtes à la main et ne passent donc PAS par les extensions
      Doctrine de cloisonnement. Chacun applique 'Perimetre::siteIds()' lui-même : l'oublier ne
      lève aucune erreur, il fait juste sortir des chiffres d'un autre client.
*/
#[ApiResource(
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    normalizationContext: ['groups' => ['read:Statistique'], 'skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/stats/tableau-de-bord',
            security: "is_granted('VOIR', 'Operation')",
            input: false,
            output: TableauDeBordOutput::class,
            provider: TableauDeBordProvider::class,
            openapi: new Operation(
                security: [['bearerAuth' => []]],
                summary: 'Tableau de bord',
                description: 'Totaux de la période, courbe journalière, répartition par produit et par pont bascule, dernières pesées et postes silencieux. Une seule réponse pour tout l\'écran d\'accueil'
            )
        ),
        new Get(
            uriTemplate: '/stats/production',
            security: "is_granted('VOIR', 'Operation')",
            input: false,
            output: ProductionOutput::class,
            provider: ProductionProvider::class,
            openapi: new Operation(
                security: [['bearerAuth' => []]],
                summary: 'Production par site et par produit',
                description: 'Croisement site × produit sur une période. Alimente la page Bilan et ses exports'
            )
        ),
        new Get(
            uriTemplate: '/stats/caisse',
            security: "is_granted('VOIR', 'MouvementCaisse')",
            input: false,
            output: CaisseOutput::class,
            provider: CaisseProvider::class,
            openapi: new Operation(
                security: [['bearerAuth' => []]],
                summary: 'État de la trésorerie',
                description: 'Solde de l\'entreprise, caisse de chaque pont bascule, montant immobilisé par des versements en cours et demandes en attente'
            )
        ),
        new Get(
            uriTemplate: '/stats/referentiels',
            security: "is_granted('VOIR', 'Operation')",
            input: false,
            output: ReferentielsOutput::class,
            provider: ReferentielsProvider::class,
            openapi: new Operation(
                security: [['bearerAuth' => []]],
                summary: 'Valeurs des filtres du bilan',
                description: 'Valeurs distinctes rencontrées dans les pesées du périmètre : opérations, clients, destinations, provenances, transporteurs, chauffeurs, véhicules'
            )
        ),
    ],
    openapi: new Operation(security: [['bearerAuth' => []]])
)]
class Statistique
{
}
