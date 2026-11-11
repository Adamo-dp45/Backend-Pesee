<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\User;
use App\Security\Droits;
use App\Security\UserGuard;
use Symfony\Bundle\SecurityBundle\Security;

/*
    - Fournit l'utilisateur connecté aux opérations '/me', en lecture comme en modification : sans '{id}' dans l'URI, API Platform ne sait pas quel objet mettre à jour et répond 404. C'est ce provider qui le lui désigne.
    - Encore faut-il que l'opération déclare 'uriVariables: []'. Sans cette déclaration la charge utile est désérialisée dans un compte NEUF : le nom et le prénom repartaient vides, et la contrainte d'unicité voyait le compte de l'intéressé comme un doublon.
*/

/**
 * @implements ProviderInterface<User>
 */
class MeProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private UserGuard $guard
    )
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?User
    {
        $utilisateur = $this->security->getUser();

        if(!$utilisateur instanceof User) {
            return null;
        }

        /*
            - Les droits sont posés ICI, et '/me' est la seule fenêtre par laquelle le frontend lit
              la table de 'Droits'. Sans elle il lui faudrait une copie, et une copie diverge — en
              silence, puisqu'un bouton manquant ne lève aucune erreur.
            - Rien n'est persisté : la propriété est transitoire, et le PATCH de '/me' ne la publie
              pas — son contexte de sérialisation ne porte pas le groupe 'read:User:me'.
        */
        $roles = [];
        $gereDesComptes = array_intersect([Droits::CREER, Droits::PROMOUVOIR], Droits::actions($utilisateur->getRolePrincipal(), 'User')) !== [];

        /*
            - Vide pour qui ne gère aucun compte : un opérateur n'ouvre jamais ces écrans, et lui
              annoncer qu'il peut nommer un administrateur serait un mensonge sans conséquence —
              donc un mensonge qu'un lecteur futur prendrait pour une règle.
        */
        foreach($gereDesComptes ? $this->guard->rolesAttribuablesPar($utilisateur) : [] as $role) {
            $roles[$role->value] = $role->libelle();
        }

        return $utilisateur
            ->setDroits(Droits::aPlat($utilisateur->getRolePrincipal()))
            ->setRolesAttribuables($roles); // La liste déroulante des rôles, décidée là où la règle vit
    }
}
