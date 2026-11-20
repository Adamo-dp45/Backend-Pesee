<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\TarifFournisseur;
use Doctrine\ORM\QueryBuilder;

/*
    - LES PLANTEURS QUI ONT NÉGOCIÉ À PART. Un tarif propre est l'exception — trente tarifs
      identiques saisis planteur par planteur sont une catégorie qui manquait — et c'est pour cette
      raison qu'on veut les voir ENSEMBLE : leur nombre est le signal, pas chaque ligne.

    - POURQUOI PAS 'exists[tarifs]=true'. Le filtre natif ne regarde que l'existence d'une ligne,
      pas son état : un planteur dont le seul tarif a été RETIRÉ y répondrait encore oui. La liste
      se remplirait de planteurs sans aucun tarif en vigueur, et on cesserait de la croire.

    - 'EXISTS' et non une jointure : une jointure rendrait le planteur autant de fois qu'il a de
      tarifs, et le compte affiché à côté serait faux sans que rien ne le dise.
*/
final class AvecTarifPropreFilter implements FilterInterface
{
    public const CLE = 'avecTarifPropre';

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $valeur = $context['filters'][self::CLE] ?? null;

        if($valeur === null || !in_array($valeur, ['1', 'true', 1, true, '0', 'false', 0, false], true)) {
            return; // Absent ou illisible : on ne filtre rien plutôt que de vider la liste en silence
        }

        $avec = in_array($valeur, ['1', 'true', 1, true], true);

        $racine = $queryBuilder->getRootAliases()[0];
        $tarif = $queryNameGenerator->generateJoinAlias('tarif');

        $existe = sprintf(
            'EXISTS (SELECT 1 FROM %s %s WHERE %s.fournisseur = %s AND %s.deletedAt IS NULL)',
            TarifFournisseur::class,
            $tarif,
            $tarif,
            $racine,
            $tarif
        );

        $queryBuilder->andWhere($avec ? $existe : sprintf('NOT %s', $existe));
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            self::CLE => [
                'property' => 'tarifs',
                'type' => 'bool',
                'required' => false,
                'description' => 'true : les planteurs qui ont au moins un tarif propre EN VIGUEUR. Un tarif retiré ne compte pas.',
            ],
        ];
    }
}
