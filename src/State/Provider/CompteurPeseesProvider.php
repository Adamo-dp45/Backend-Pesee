<?php

namespace App\State\Provider;

use ApiPlatform\Doctrine\Orm\State\CollectionProvider;
use ApiPlatform\Metadata\Operation as ApiOperation;
use ApiPlatform\State\Pagination\PaginatorInterface;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Fournisseur;
use App\Entity\Produit;
use App\Entity\Site;
use App\Repository\CompteEpargneRepository;
use App\Repository\OperationRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/*
    - Pose le nombre de pesées sur toute une page de référentiels, EN UNE SEULE requête groupée.

    - Sans lui, chaque ligne calculait son compteur pour elle-même : une page de vingt-cinq produits
      lançait vingt-cinq 'COUNT'. Le motif reste celui de la convention — la liste ne reçoit qu'un
      compteur, jamais la collection — mais il se paie une requête au lieu de vingt-cinq.

    - Il se branche sur le provider de collection NATIF : sécurité, extensions de périmètre,
      filtres et pagination restent ceux d'API Platform. On ne fait qu'enrichir ce qu'il rend.

    - Un seul provider pour les trois référentiels : ils posent la même question à la même table, et
      trois classes identiques à un mot près ne s'expliqueraient pas. La fiche, elle, n'en a pas
      besoin : une ligne seule calcule son compteur à la demande.
*/

/**
 * @implements ProviderInterface<Produit|Fournisseur|Site>
 */
final class CompteurPeseesProvider implements ProviderInterface
{
    /** @param ProviderInterface<Produit|Fournisseur|Site> $collection */
    public function __construct(
        #[Autowire(service: CollectionProvider::class)] private readonly ProviderInterface $collection,
        private readonly OperationRepository $operations,
        private readonly CompteEpargneRepository $epargnes
    )
    {
    }

    public function provide(ApiOperation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $page = $this->collection->provide($operation, $uriVariables, $context);

        // Le paginateur ne se parcourt qu'une fois de façon fiable : on matérialise pour pouvoir écrire dedans, puis on rend l'original, qui porte le total
        $lignes = $page instanceof PaginatorInterface || $page instanceof \Traversable
            ? iterator_to_array($page)
            : (array) $page;

        if($lignes === []) {
            return $page;
        }

        $champ = match($lignes[array_key_first($lignes)]::class) {
            Produit::class => 'produit',
            Fournisseur::class => 'fournisseur',
            Site::class => 'site',
            default => null,
        };

        if($champ === null) {
            return $page;
        }

        $compteurs = $this->operations->compterParReferentiel($champ, array_map(static fn($ligne) => (int) $ligne->getId(), $lignes));

        foreach($lignes as $ligne) {
            $ligne->setPeseesCount($compteurs[(int) $ligne->getId()] ?? 0);
        }

        /*
            - Même raison, autre question : le solde d'épargne se lit à travers une relation, donc
              sans ce passage groupé la liste des planteurs tirerait une requête par ligne. Zéro par
              défaut, car un planteur qui n'a jamais épargné n'a pas de compte — et l'absence de
              compte se lit comme un solde nul, pas comme une donnée manquante.
        */
        if($champ === 'fournisseur') {
            $comptes = $this->epargnes->parFournisseurs(array_map(static fn($ligne) => (int) $ligne->getId(), $lignes));

            foreach($lignes as $ligne) {
                /*
                    - La clé est lue À PART, et ce n'est pas du zèle : '??' n'étend ses semblants
                      d'isset qu'aux chaînes de propriétés, jamais à un APPEL de méthode. Écrit
                      '$comptes[$id]?->getSolde() ?? 0', PHP lisait donc la case avant de pouvoir
                      se rabattre, et criait « Undefined array key » sur chaque planteur sans
                      compte — c'est-à-dire sur la quasi-totalité d'entre eux.
                */
                $compte = $comptes[(int) $ligne->getId()] ?? null;

                $ligne->setSoldeEpargne($compte?->getSolde() ?? 0);
            }
        }

        return $page;
    }
}
