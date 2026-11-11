<?php

namespace App\State\Provider;

use ApiPlatform\Doctrine\Orm\State\CollectionProvider;
use ApiPlatform\Doctrine\Orm\State\ItemProvider;
use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation as ApiOperation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\User;
use App\Security\Droits;
use App\Security\UserGuard;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/*
    - Pose sur chaque compte rendu ce que l'utilisateur CONNECTÉ peut y faire, et pourquoi il ne
      peut rien quand c'est le cas. Deux questions croisées : le rôle a-t-il l'action sur la
      ressource « User » (la table de 'Droits') ? et peut-on l'exercer sur CE compte-là (la
      hiérarchie de 'UserGuard') ?

    - Pourquoi côté API : le frontend tenait sa propre copie de la hiérarchie, avec un ⚠ en
      commentaire pour rappeler de la reporter. Elle avait déjà divergé — la règle qui renvoie les
      comptes de pont bascule vers leur fiche n'y était jamais arrivée. Sans conséquence, parce que
      'UserExtension' écarte ces comptes de la ressource : c'est précisément ainsi qu'une copie
      s'écarte sans que personne ne s'en aperçoive, en attendant la règle suivante.

    - Il se branche sur les providers NATIFS : sécurité, périmètre, filtres et pagination restent
      ceux d'API Platform. On ne fait qu'enrichir ce qu'ils rendent.
*/

/**
 * @implements ProviderInterface<User>
 */
final class GestionCompteProvider implements ProviderInterface
{
    /**
     * @param ProviderInterface<User> $collection
     * @param ProviderInterface<User> $item
     */
    public function __construct(
        #[Autowire(service: CollectionProvider::class)] private readonly ProviderInterface $collection,
        #[Autowire(service: ItemProvider::class)] private readonly ProviderInterface $item,
        private readonly Security $security,
        private readonly UserGuard $guard
    )
    {
    }

    public function provide(ApiOperation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $source = $operation instanceof CollectionOperationInterface ? $this->collection : $this->item;
        $resultat = $source->provide($operation, $uriVariables, $context);

        $acteur = $this->security->getUser();

        if(!$acteur instanceof User) {
            return $resultat;
        }

        if($resultat instanceof User) {
            $this->poser($acteur, $resultat);

            return $resultat;
        }

        // Le paginateur ne se parcourt qu'une fois de façon fiable : on matérialise pour écrire dedans, puis on rend l'original, qui porte le total
        foreach($resultat instanceof \Traversable ? iterator_to_array($resultat) : (array) $resultat as $ligne) {
            if($ligne instanceof User) {
                $this->poser($acteur, $ligne);
            }
        }

        return $resultat;
    }

    private function poser(User $acteur, User $cible): void
    {
        $actions = [];

        foreach(Droits::actions($acteur->getRolePrincipal(), 'User') as $action) {
            if($action === Droits::CREER) {
                continue; // Créer n'a pas de cible : l'annoncer sur un compte existant n'aurait aucun sens
            }

            if($this->guard->peutGerer($acteur, $cible, $action)) {
                $actions[] = $action;
            }
        }

        /*
            - Le motif est celui de 'MODIFIER' : c'est le geste le plus courant, et sa raison de
              refus est celle qui vaut pour la fiche entière — « c'est le vôtre », « c'est un
              compte machine », « vous n'encadrez pas ce rôle ».
        */
        $cible->setDroitsSurCeCompte($actions)
            ->setMotifNonGerable($this->guard->motifRefus($acteur, $cible, Droits::MODIFIER));
    }
}
