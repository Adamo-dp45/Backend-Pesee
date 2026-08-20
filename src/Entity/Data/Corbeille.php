<?php

namespace App\Entity\Data;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model\Operation;
use App\Entity\Output\Corbeille\CorbeilleOutput;
use App\State\Processor\PurgerCorbeilleProcessor;
use App\State\Processor\RestaurerCorbeilleProcessor;
use App\State\Provider\CorbeilleProvider;
use App\State\Provider\CorbeilleVideProvider;

/*
    - La corbeille. Ressource NON persistée : aucune table derrière, chaque opération a son provider
      ou son processor. Le modèle est celui de 'Statistique'.

    - RÉSERVÉE AU SUPER ADMINISTRATEUR, et c'est le seul écran de l'application dans ce cas. La raison
      n'est pas hiérarchique mais pratique : restaurer ou purger un référentiel touche à l'historique
      des pesées de tout un client, et c'est un geste de dépannage — celui qu'on demande à qui a mis
      les postes en service, pas celui qu'on laisse à portée de clic dans l'écran quotidien.

    - Conséquence assumée : elle lit PAR-DESSUS le cloisonnement, puisque le super administrateur n'a
      aucun droit sur les produits ni les planteurs. C'est pour cela que 'CorbeilleService' écrit ses
      requêtes à la main plutôt que de passer par les extensions Doctrine, qui ne lui rendraient rien.

    - Pas de « vider la corbeille » en lot, et c'est délibéré : la purge est refusée dès qu'une pesée
      référence l'élément, ce qui sera le cas de la plupart. Un vidage global échouerait presque
      toujours en bloc, ou pire, ne purgerait qu'une partie sans que personne ne sache laquelle. La
      liste porte déjà, pour chaque ligne, si elle est purgeable et pourquoi.
*/
#[ApiResource(
    security: "is_granted('ROLE_SUPER_ADMIN')",
    securityMessage: 'Seul le super administrateur accède à la corbeille.',
    operations: [
        new Get(
            uriTemplate: '/corbeille',
            provider: CorbeilleProvider::class,
            input: false,
            output: CorbeilleOutput::class,
            normalizationContext: ['groups' => ['read:Corbeille'], 'skip_null_values' => false],
            openapi: new Operation(
                summary: 'La corbeille',
                description: 'Produits et planteurs supprimés, tous clients confondus. Filtres : ?type[]=produit&entreprise=3',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch(
            uriTemplate: '/corbeille/{type}/{id}/restauration',
            requirements: ['id' => '\d+'],
            provider: CorbeilleVideProvider::class,
            processor: RestaurerCorbeilleProcessor::class,
            input: false,
            output: false,
            openapi: new Operation(
                summary: 'Restauration d\'un élément',
                description: 'Le remet en service. Refusé si un homonyme vivant est né entre-temps sur le même pont bascule',
                security: [['bearerAuth' => []]]
            )
        ),
        new Delete(
            uriTemplate: '/corbeille/{type}/{id}',
            requirements: ['id' => '\d+'],
            provider: CorbeilleVideProvider::class,
            processor: PurgerCorbeilleProcessor::class,
            openapi: new Operation(
                summary: 'Suppression définitive d\'un élément',
                description: 'La ligne quitte la base. Refusé tant qu\'une pesée la référence',
                security: [['bearerAuth' => []]]
            )
        ),
    ]
)]
final class Corbeille
{
}
