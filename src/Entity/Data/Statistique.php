<?php

namespace App\Entity\Data;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use App\Entity\Output\APayerOutput;
use App\Entity\Output\BilanCaissesOutput;
use App\Entity\Output\BilanPaiementsOutput;
use App\Entity\Output\CaisseOutput;
use App\Entity\Output\ProductionOutput;
use App\Entity\Output\ReferentielsOutput;
use App\Entity\Output\TableauDeBordOutput;
use App\State\Provider\APayerProvider;
use App\State\Provider\BilanCaissesProvider;
use App\State\Provider\BilanPaiementsProvider;
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
    normalizationContext: ['groups' => ['read:Statistique']],
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
        /*
            - CE QUE LA FILE DU GUICHET PÈSE. Il répond à la même sélection que la liste de l'écran
              « À payer », parce qu'il lit les MÊMES noms de filtres que la collection des pesées.
        */
        new Get(
            uriTemplate: '/stats/a-payer',
            security: "is_granted('PAYER', 'Paiement')",
            input: false,
            output: APayerOutput::class,
            provider: APayerProvider::class,
            openapi: new Operation(
                security: [['bearerAuth' => []]],
                summary: 'Totaux de la file à payer',
                description: 'Reste à verser, déjà versé, en vol et nombre de pesées en attente, sur les filtres posés par le web'
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
        /*
            - LES DEUX BILANS D'ARGENT. Ils répondent à deux questions distinctes : « qu'a-t-on
              versé aux planteurs », et « qu'est-ce qui est entré et sorti des caisses ». Un
              versement est une sortie de caisse, mais toutes les sorties ne sont pas des
              versements — une dotation n'en est pas une.
        */
        new Get(
            uriTemplate: '/stats/paiements',
            security: "is_granted('VOIR', 'Paiement')",
            input: false,
            output: BilanPaiementsOutput::class,
            provider: BilanPaiementsProvider::class,
            openapi: new Operation(
                security: [['bearerAuth' => []]],
                summary: 'Bilan des versements',
                description: 'Ce qui est sorti vers les planteurs sur une période : confirmé, en vol et remboursé, ventilés par mode de règlement et par pont bascule'
            )
        ),
        new Get(
            uriTemplate: '/stats/caisses',
            security: "is_granted('VOIR', 'MouvementCaisse')",
            input: false,
            output: BilanCaissesOutput::class,
            provider: BilanCaissesProvider::class,
            openapi: new Operation(
                security: [['bearerAuth' => []]],
                summary: 'Bilan des caisses',
                description: 'Entrées et sorties de chaque caisse sur une période, par cause et par pont bascule. Ce sont des FLUX : les soldes du jour se lisent sur /stats/caisse'
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
