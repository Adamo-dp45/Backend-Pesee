<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Domain\Enum\Role;
use App\Entity\Entreprise;
use App\Entity\EntityBase;
use App\Entity\Interface\EntrepriseOwnedInterface;
use App\Entity\Interface\SiteOwnedInterface;
use App\Entity\Site;
use App\Entity\User;
use App\Security\Perimetre;
use Doctrine\ORM\QueryBuilder;

/*
    - Cloisonne automatiquement toutes les lectures API Platform : la corbeille d'abord, puis
      l'entreprise, puis les ponts bascules quand l'utilisateur est opérateur.

    - Le soft-delete est traité ICI et non dans une seconde extension : une entité qui hérite d''EntityBase' porte 'deletedAt', et l'héritage suffit à le dire. V1 avait une extension ET une interface dédiées pour ajouter une clause de quatre mots.
    - 'User' n'en hérite pas : un compte ne se met pas à la corbeille, il se suspend. Il échappe donc naturellement à cette clause, tout en restant borné à son entreprise par le marqueur ci-dessous.

    - Deux marqueurs, deux règles :
        · 'EntrepriseOwnedInterface' → l'entité porte son entreprise ;
        · 'SiteOwnedInterface'       → l'entité est rattachée à un pont bascule, on filtre sur la
          liste des sites autorisés. Une liste plutôt qu'une jointure vers l'entreprise : un parc
          compte quelques dizaines de sites, la clause reste courte, elle utilise l'index de clé
          étrangère, et surtout elle ne change pas la cardinalité du résultat.

    - ATTENTION : les providers et repositories personnalisés ne passent pas par ici. Toute requête
      d'agrégat doit appliquer 'Perimetre::siteIds()' elle-même.
*/
final class PerimetreExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** Les seules ressources que le super administrateur traverse : il provisionne, il n'exploite pas. */
    private const SUPER_ADMIN = [Entreprise::class, User::class, Site::class];

    public function __construct(
        private Perimetre $perimetre
    )
    {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void
    {
        $this->filtrer($queryBuilder, $resourceClass);
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
        $this->filtrer($queryBuilder, $resourceClass);
    }

    private function filtrer(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        $alias = $queryBuilder->getRootAliases()[0];

        if(is_subclass_of($resourceClass, EntityBase::class)) {
            $queryBuilder->andWhere("$alias.deletedAt IS NULL");
        }

        $parEntreprise = is_subclass_of($resourceClass, EntrepriseOwnedInterface::class);
        $parSite = is_subclass_of($resourceClass, SiteOwnedInterface::class);
        $estLEntreprise = $resourceClass === Entreprise::class;

        if(!$parEntreprise && !$parSite && !$estLEntreprise) {
            return;
        }

        $user = $this->perimetre->utilisateur();

        if($user === null) {
            $this->rienRenvoyer($queryBuilder);

            return;
        }

        if($user->aLeRole(Role::SUPER_ADMIN)) {
            if(!in_array($resourceClass, self::SUPER_ADMIN, true)) {
                $this->rienRenvoyer($queryBuilder);
            }

            return;
        }

        $entreprise = $user->getEntreprise();

        if($entreprise === null) {
            $this->rienRenvoyer($queryBuilder); // Compte sans entreprise et sans le rôle qui l'autorise : état incohérent

            return;
        }

        /*
            - 'Entreprise' ne PORTE pas l'entreprise, elle EST l'entreprise : elle n'a donc pas de champ 'entreprise' sur lequel filtrer, et sans ce cas explicite un administrateur listerait tous les clients de la plateforme.
        */
        if($estLEntreprise) {
            $queryBuilder
                ->andWhere("$alias.id = :perimetre_entreprise")
                ->setParameter('perimetre_entreprise', $entreprise->getId());

            return;
        }

        if($parEntreprise) {
            $queryBuilder
                ->andWhere("$alias.entreprise = :perimetre_entreprise")
                ->setParameter('perimetre_entreprise', $entreprise);
        }

        $estOperateur = $user->aLeRole(Role::OPERATEUR);

        /*
            - Le pont bascule porte le marqueur « entreprise », il est donc déjà borné au bon
              client — mais un opérateur ne tient pas toutes les caisses. On le borne alors sur
              l'identifiant de l'entité elle-même, faute d'un champ 'site' qu'elle ne possède pas.
        */
        if($resourceClass === Site::class) {
            if($estOperateur) {
                $this->bornerAuxSites($queryBuilder, "$alias.id IN (:perimetre_sites)");
            }

            return;
        }

        if(!$parSite) {
            return;
        }

        /*
            - Les parenthèses ne sont pas cosmétiques : 'andWhere' concatène en AND sans
              parenthéser. Sans elles la clause deviendrait « entreprise = :e AND site IN (...) OR
              site IS NULL », et le OR ferait remonter les lignes sans site de TOUTES les
              entreprises.
            - Le cas « sans site » couvre les mouvements de la caisse de l'entreprise. Il n'est pas
              ouvert à un opérateur : la trésorerie globale ne le regarde pas.
        */
        $this->bornerAuxSites($queryBuilder, $estOperateur
            ? "$alias.site IN (:perimetre_sites)"
            : "($alias.site IN (:perimetre_sites) OR $alias.site IS NULL)");
    }

    private function bornerAuxSites(QueryBuilder $queryBuilder, string $clause): void
    {
        $siteIds = $this->perimetre->siteIds();

        if($siteIds === []) {
            $this->rienRenvoyer($queryBuilder);

            return;
        }

        $queryBuilder
            ->andWhere($clause)
            ->setParameter('perimetre_sites', $siteIds);
    }

    /*
        - Une condition impossible plutôt qu'une exception : une collection vide est une réponse
          légitime, et un oubli de configuration ne se traduit pas par une fuite de données.
    */
    private function rienRenvoyer(QueryBuilder $queryBuilder): void
    {
        $queryBuilder->andWhere('1 = 0');
    }
}
