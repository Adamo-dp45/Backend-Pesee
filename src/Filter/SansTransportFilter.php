<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/*
    - DEUX ÉTATS QUI SE RESSEMBLENT EN BASE, et qu'il faut pourtant pouvoir séparer à l'écran :
      « on ne lui paie pas de transport », qui est le cas de la plupart des planteurs et qui est
      voulu, et « on a oublié de lui en mettre un », qui ne l'est pas. Les deux donnent la même
      ligne — aucun barème — et aucun écran ne les distinguera jamais tout seul. Ce filtre ne
      tranche pas entre les deux : il RASSEMBLE les candidats pour qu'un humain le fasse.

    - IL SUIT LA RÈGLE DE RÉSOLUTION, et c'est là qu'un filtre naïf se trompe : 'exists[bareme
      Transport]=false' rendrait aussi tous les planteurs d'une catégorie qui, elle, en porte un —
      des gens parfaitement réglés. La liste se remplirait de faux oublis, on cesserait de la
      regarder, et les vrais oublis s'y cacheraient. « Sans transport » veut dire : ni le sien, ni
      celui de sa catégorie.

    - Le LEFT JOIN est obligatoire : un INNER écarterait les planteurs sans catégorie, qui sont
      précisément ceux qu'on cherche le plus souvent.
*/
final class SansTransportFilter implements FilterInterface
{
    public const CLE = 'sansTransport';

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $valeur = $context['filters'][self::CLE] ?? null;

        if($valeur === null || !in_array($valeur, ['1', 'true', 1, true, '0', 'false', 0, false], true)) {
            return; // Absent ou illisible : on ne filtre rien plutôt que de vider la liste en silence
        }

        $sans = in_array($valeur, ['1', 'true', 1, true], true);

        $racine = $queryBuilder->getRootAliases()[0];
        $categorie = $queryNameGenerator->generateJoinAlias('categorie');

        $queryBuilder->leftJoin(sprintf('%s.categorie', $racine), $categorie);

        $aucun = sprintf(
            '%1$s.baremeTransport IS NULL AND (%2$s.id IS NULL OR %2$s.baremeTransport IS NULL)',
            $racine,
            $categorie
        );

        $queryBuilder->andWhere($sans ? $aucun : sprintf('NOT (%s)', $aucun));
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            self::CLE => [
                'property' => 'baremeTransport',
                'type' => 'bool',
                'required' => false,
                'description' => 'true : les planteurs qui ne touchent aucun transport, ni par eux-mêmes ni par leur catégorie.',
            ],
        ];
    }
}
