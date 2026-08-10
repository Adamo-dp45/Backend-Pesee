<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;

/*
    - Écarte les comptes machine de l'écran des utilisateurs. Ils appartiennent bien à l'entreprise, donc 'PerimetreExtension' les laisse passer : sans cette extension, l'administrateur voit 'sof010@poste.webpesee.local' au milieu de son personnel, et peut ouvrir sa fiche.
    - On filtre sur 'siteMachine' et non sur le rôle : c'est la relation qui DÉFINIT un compte machine, alors qu'un rôle est une chaîne dans une colonne JSON — inexploitable en SQL sans bricolage.
    - C'est la seule règle propre à 'User', d'où une extension à part plutôt qu'un cas de plus dans 'PerimetreExtension' : 'User' est aussi la seule entité que 'EntityBase' ne couvre pas, un compte se suspendant au lieu de se supprimer.
*/
class UserExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void
    {
        $this->ecarterLesComptesMachine($queryBuilder, $resourceClass);
    }

    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = []
    ): void
    {
        $this->ecarterLesComptesMachine($queryBuilder, $resourceClass); // Sur la fiche aussi : sinon l'URL devinée l'affiche quand même
    }

    private function ecarterLesComptesMachine(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if($resourceClass !== User::class) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $queryBuilder->andWhere("$alias.siteMachine IS NULL");
    }
}
