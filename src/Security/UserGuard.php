<?php

namespace App\Security;

use App\Domain\Enum\Role;
use App\Entity\User;
use App\Security\Voter\AccesVoter;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - La hiérarchie de gestion des comptes. 'AccesVoter' dit qui a le droit d'agir sur la ressource « User » ; ici on répond à « sur QUI », ce qu'une table de droits ne sait pas exprimer.

    - Deux surfaces pour la même règle, et c'est le but : 'peutGerer()' répond oui/non au voter, donc au frontend qui décide d'afficher un bouton ; 'assertPeutGerer()' lève une exception avec un motif lisible pour le processor. Les deux ne peuvent pas diverger puisque la seconde appelle la première logique.
*/
final class UserGuard
{
    /*
        - Vrai si l'acteur peut poser cette action sur ce compte. La cible est nulle sur une collection ou une création : il n'y a alors personne à protéger, c'est la table de droits qui tranche.
    */
    public function peutGerer(User $acteur, ?User $cible, string $action): bool
    {
        return $this->motifRefus($acteur, $cible, $action) === null;
    }

    public function assertPeutGerer(User $acteur, ?User $cible, string $action): void
    {
        if(($motif = $this->motifRefus($acteur, $cible, $action)) !== null) {
            throw new UnprocessableEntityHttpException($motif);
        }
    }

    /*
        - Le rôle demandé, lui, dépend du corps de la requête : le voter ne peut pas le connaître, il n'a que l'objet visé. Un agent encadre des opérateurs — lui laisser fabriquer un administrateur reviendrait à lui donner les clés de l'entreprise par un détour.
    */
    public function assertPeutAttribuerRole(User $acteur, string $role): void
    {
        if($acteur->aLeRole(Role::AGENT) && $role !== Role::OPERATEUR->value) {
            throw new UnprocessableEntityHttpException('Un agent ne peut attribuer que le rôle opérateur.');
        }
    }

    private function motifRefus(User $acteur, ?User $cible, string $action): ?string
    {
        if($cible === null || $action === AccesVoter::VOIR) {
            return null; // Voir un compte de son périmètre est toujours permis, le reste se protège
        }

        if($cible->estCompteMachine()) {
            return 'Le compte d\'un pont bascule se gère depuis sa fiche, pas depuis les utilisateurs.';
        }

        if($cible->getId() !== null && $cible->getId() === $acteur->getId()) {
            return 'Vous ne pouvez pas effectuer cette action sur votre propre compte. Passez par votre profil.';
        }

        if($cible->aLeRole(Role::SUPER_ADMIN)) {
            return 'Un super administrateur ne se gère pas depuis cette interface.';
        }

        /*
            - Le fondateur — celui qui a inscrit l'entreprise — n'est ni suspendable, ni supprimable, ni rétrogradable par un pair. Sans ce garde-fou, deux administrateurs se neutralisent et l'entreprise se verrouille hors de son propre outil, sans autre issue qu'une intervention en base.
        */
        $retire = in_array($action, [AccesVoter::BLOQUER, AccesVoter::SUPPRIMER, AccesVoter::PROMOUVOIR], true);

        if($cible->isFondateur() && $retire && !$acteur->aLeRole(Role::SUPER_ADMIN)) {
            return 'Le compte fondateur ne peut être ni suspendu, ni supprimé, ni rétrogradé par un autre administrateur.';
        }

        // L'agent ne remonte pas la hiérarchie : il n'agit que sur des opérateurs
        if($acteur->aLeRole(Role::AGENT) && !$cible->aLeRole(Role::OPERATEUR)) {
            return 'Un agent ne gère que les comptes opérateur.';
        }

        return null;
    }
}
