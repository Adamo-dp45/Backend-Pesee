<?php

namespace App\State\Provider;

use ApiPlatform\Doctrine\Orm\State\CollectionProvider;
use ApiPlatform\Metadata\Operation as ApiOperation;
use ApiPlatform\State\Pagination\PaginatorInterface;
use ApiPlatform\State\ProviderInterface;
use App\Entity\CategorieFournisseur;
use App\Repository\FournisseurRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/*
    - Pose le nombre de planteurs sur toute une page de catégories, EN UNE SEULE requête groupée.
      Même motif que 'CompteurPeseesProvider', et pour la même raison : sans lui, chaque ligne
      compterait pour elle-même, et une page de vingt-cinq catégories lancerait vingt-cinq 'COUNT'.

    - Pourquoi un provider de plus plutôt qu'une branche dans l'autre : celui-là compte des PESÉES,
      dans une seule table, pour trois référentiels qui y pointent tous. Ici la question porte sur
      une autre table et une autre colonne. Les mêler aurait donné un provider qui change de table
      selon la classe reçue — le genre de code où l'on finit par compter la mauvaise chose.

    - Le chiffre sert à décider : une catégorie sans planteur se supprime sans conséquence, et c'est
      lui que le refus de suppression nomme quand il y en a.
*/

/**
 * @implements ProviderInterface<CategorieFournisseur>
 */
final class CompteurPlanteursProvider implements ProviderInterface
{
    /** @param ProviderInterface<CategorieFournisseur> $collection */
    public function __construct(
        #[Autowire(service: CollectionProvider::class)] private readonly ProviderInterface $collection,
        private readonly FournisseurRepository $fournisseurs
    )
    {
    }

    public function provide(ApiOperation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $page = $this->collection->provide($operation, $uriVariables, $context);

        // Le paginateur ne se parcourt qu'une fois de façon fiable : on matérialise pour écrire dedans, puis on rend l'original, qui porte le total
        $lignes = $page instanceof PaginatorInterface || $page instanceof \Traversable
            ? iterator_to_array($page)
            : (array) $page;

        if($lignes === []) {
            return $page;
        }

        $compteurs = $this->fournisseurs->compterParCategorie(
            array_map(static fn(CategorieFournisseur $ligne) => (int) $ligne->getId(), $lignes)
        );

        foreach($lignes as $ligne) {
            $ligne->setPlanteursCount($compteurs[(int) $ligne->getId()] ?? 0);
        }

        return $page;
    }
}
