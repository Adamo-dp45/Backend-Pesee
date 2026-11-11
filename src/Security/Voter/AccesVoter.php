<?php

namespace App\Security\Voter;

use App\Domain\Enum\Role;
use App\Entity\Entreprise;
use App\Entity\Interface\EntrepriseOwnedInterface;
use App\Entity\Interface\SiteOwnedInterface;
use App\Entity\Site;
use App\Entity\User;
use App\Security\Droits;
use App\Security\Perimetre;
use App\Security\UserGuard;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/*
    - Un seul voter pour toute l'application. Les droits sont une TABLE, pas une hiérarchie de
      classes : V1 avait un voter abstrait et onze sous-classes qui répétaient la même mécanique,
      et répondre à « qui peut faire quoi » demandait d'ouvrir douze fichiers.

    - La table elle-même vit dans 'Droits', pas ici : le frontend a besoin de la LIRE pour savoir
      quels boutons afficher, et une table enfermée dans un voter ne se lit pas — c'est ce qui
      l'avait fait recopier à la main de l'autre côté.

    - Trois questions, dans cet ordre :
        1. le rôle porte-t-il cette action sur cette ressource ?      → 'Droits'
        2. la hiérarchie de gestion des comptes l'autorise-t-elle ?   → 'UserGuard'
        3. l'objet visé est-il dans le périmètre de l'utilisateur ?   → 'Perimetre'

    - Les règles fines (on ne suspend pas un fondateur, un agent ne gère que des opérateurs…) vivent dans des GARDES, pas ici. Le voter leur demande simplement leur avis : la garde expose un booléen pour lui — donc pour l'affichage des boutons — et un 'assert' pour le processor, qui lui doit un motif lisible. Un voter ne sait répondre que oui ou non, et un « non » muet n'aide personne.

    - Convention d'appel, reprise de Transport :
        · is_granted('VOIR', 'Site')  → collection ou création, le sujet est le nom court
        · is_granted('VOIR', $site)   → élément, le sujet est l'entité
*/
final class AccesVoter extends Voter
{
    public function __construct(
        private Perimetre $perimetre,
        private UserGuard $userGuard
    )
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, Droits::ACTIONS, true) && (is_string($subject) || is_object($subject));
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null
    ): bool
    {
        $user = $token->getUser();

        if(!$user instanceof User || !$user->estActif()) {
            return false;
        }

        /* Chaque compte porte exactement un rôle métier : il n'y a pas de 'role_hierarchy'. */
        if(!in_array($attribute, Droits::actions($user->getRolePrincipal(), $this->ressource($subject)), true)) {
            return false;
        }

        if(!$this->userGuard->peutGerer($user, $subject instanceof User ? $subject : null, $attribute)) {
            return false; // La hiérarchie de gestion des comptes, que la table ne sait pas exprimer
        }

        return is_string($subject) || $this->dansMonPerimetre($user, $subject);
    }

    private function ressource(mixed $subject): string
    {
        if(is_string($subject)) {
            return $subject;
        }

        return substr(strrchr(get_class($subject), '\\'), 1); /*
            - Plus rapide que 'ReflectionClass', et le nom court d'un proxy Doctrine reste celui de
              l'entité ('Proxies\__CG__\App\Entity\Site' → 'Site')
        */
    }

    /*
        - Le même découpage que 'PerimetreExtension', appliqué cette fois à l'objet que le provider
          a déjà chargé. On ne refait pas la requête, on vérifie le résultat : une opération
          personnalisée peut très bien charger son élément sans passer par les extensions.
    */
    private function dansMonPerimetre(User $user, object $sujet): bool
    {
        if($user->aLeRole(Role::SUPER_ADMIN)) {
            return true; // Il n'atteint que 'Entreprise', 'User' et 'Site' : la table s'en charge
        }

        $entreprise = $user->getEntreprise();

        if($sujet instanceof Entreprise) {
            return $entreprise !== null && $sujet->getId() === $entreprise->getId(); // Elle EST le périmètre, comme 'Site' pour un opérateur
        }

        if($sujet instanceof EntrepriseOwnedInterface) {
            if($entreprise === null || $sujet->getEntreprise()?->getId() !== $entreprise->getId()) {
                return false;
            }
        }

        if($sujet instanceof Site) {
            return $this->perimetre->voitLeSite($sujet); /*
                - C'est LUI le périmètre : il n'a pas de champ 'site', on le compare à sa propre
                  liste, sinon un opérateur verrait les ponts bascules de ses collègues
            */
        }

        if($sujet instanceof SiteOwnedInterface) {
            $site = $sujet->getSite();

            return $site === null
                ? !$user->aLeRole(Role::OPERATEUR) // Sans site : une écriture de la caisse de l'entreprise
                : $this->perimetre->voitLeSite($site);
        }

        return true;
    }
}
