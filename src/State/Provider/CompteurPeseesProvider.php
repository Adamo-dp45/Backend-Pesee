<?php

namespace App\State\Provider;

use ApiPlatform\Doctrine\Orm\State\CollectionProvider;
use ApiPlatform\Metadata\Operation as ApiOperation;
use ApiPlatform\State\Pagination\PaginatorInterface;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Fournisseur;
use App\Entity\Produit;
use App\Entity\Site;
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
        private readonly OperationRepository $operations
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

        return $page;
    }
}
