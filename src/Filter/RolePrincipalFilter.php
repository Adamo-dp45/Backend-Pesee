<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Domain\Enum\Role;
use Doctrine\ORM\QueryBuilder;

/*
    - FILTRER LES COMPTES PAR RÔLE. Un écran de gestion des comptes sert surtout à retrouver
      quelqu'un dans une catégorie — « montre-moi mes opérateurs » — et la liste n'offrait que
      l'état actif ou suspendu.

    - POURQUOI UN FILTRE À LA MAIN : 'roles' est une colonne JSON, pas une relation ni une chaîne.
      Le 'SearchFilter' natif compare des colonnes scalaires et ne saurait pas y regarder — il
      rendrait une liste vide sans rien dire, ce qui est la pire des réponses.

    - LES GUILLEMETS DU JSON FONT L'EXACTITUDE. Le tableau est stocké tel que '["ROLE_ADMIN"]' :
      chercher 'ROLE_ADMIN' sans eux ramènerait aussi les 'ROLE_SUPER_ADMIN', et un super
      administrateur passerait pour un administrateur du client. Avec eux, la correspondance est
      franche.

    - UNE VALEUR INCONNUE NE FILTRE RIEN plutôt que de vider la liste : une liste vide ressemble
      en tout point à « personne ne correspond », et on cherche longtemps avant de soupçonner le
      filtre lui-même.
*/
final class RolePrincipalFilter implements FilterInterface
{
    public const CLE = 'role';

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $valeur = $context['filters'][self::CLE] ?? null;

        if(!is_string($valeur) || Role::tryFrom($valeur) === null) {
            return;
        }

        $racine = $queryBuilder->getRootAliases()[0];
        $parametre = $queryNameGenerator->generateParameterName(self::CLE);

        $queryBuilder
            ->andWhere(sprintf('%s.roles LIKE :%s', $racine, $parametre))
            ->setParameter($parametre, '%"' . $valeur . '"%');
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            self::CLE => [
                'property' => 'roles',
                'type' => 'string',
                'required' => false,
                'description' => 'Les comptes qui portent ce rôle, par sa valeur : ROLE_ADMIN, ROLE_AGENT, ROLE_OPERATEUR…',
            ],
        ];
    }
}
