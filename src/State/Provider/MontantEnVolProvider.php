<?php

namespace App\State\Provider;

use ApiPlatform\Doctrine\Orm\State\CollectionProvider;
use ApiPlatform\Metadata\Operation as ApiOperation;
use ApiPlatform\State\Pagination\PaginatorInterface;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Operation;
use App\Repository\PaiementRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/*
    - Pose sur toute une page de pesées ce qui est parti de la caisse sans réponse de la passerelle,
      EN UNE SEULE requête groupée. Même mécanique que 'CompteurPeseesProvider', autre question.

    - Pourquoi ce chiffre a sa place dans la LISTE et pas seulement sur la fiche : la file du guichet
      affiche un reste calculé sur le seul montant CONFIRMÉ. Tant que la passerelle n'a pas répondu,
      une pesée qu'on vient de payer y montre donc le même reste qu'avant le versement. L'opérateur
      en conclut que son geste n'est pas passé et recommence — l'API refuse, mais il aura fallu
      cliquer pour l'apprendre.

    - Sans le passage par ce provider, chaque ligne additionnerait ses propres versements : une page
      de vingt-cinq pesées hydraterait vingt-cinq collections de paiements, précisément ce que
      'Operation::$paiements' réserve à la fiche.

    - Il se branche sur le provider de collection NATIF : sécurité, extensions de périmètre, filtres
      et pagination restent ceux d'API Platform. On ne fait qu'enrichir ce qu'il rend.
*/

/**
 * @implements ProviderInterface<Operation>
 */
final class MontantEnVolProvider implements ProviderInterface
{
    /** @param ProviderInterface<Operation> $collection */
    public function __construct(
        #[Autowire(service: CollectionProvider::class)] private readonly ProviderInterface $collection,
        private readonly PaiementRepository $paiements
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

        $enVol = $this->paiements->montantsEnVolParPesee(
            array_map(static fn(Operation $ligne) => (int) $ligne->getId(), $lignes)
        );

        /*
            - Zéro par défaut, et c'est ce qui compte : une pesée sans versement en vol doit porter la
              valeur 0 et non rester nulle, sinon le repli de 'getMontantenvol()' se déclencherait et
              hydraterait ses paiements — on aurait payé la requête groupée pour rien.
        */
        foreach($lignes as $ligne) {
            $parts = $enVol[(int) $ligne->getId()] ?? ['produit' => 0, 'transport' => 0];
            $ligne->setMontantsenvol($parts['produit'], $parts['transport']);
        }

        return $page;
    }
}
