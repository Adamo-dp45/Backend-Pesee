<?php

namespace App\Security\Voter;

use App\Domain\Enum\Role;
use App\Entity\Entreprise;
use App\Entity\Interface\EntrepriseOwnedInterface;
use App\Entity\Interface\SiteOwnedInterface;
use App\Entity\Site;
use App\Entity\User;
use App\Security\Perimetre;
use App\Security\UserGuard;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/*
    - Un seul voter pour toute l'application. Les droits sont une TABLE, pas une hiérarchie de
      classes : V1 avait un voter abstrait et onze sous-classes qui répétaient la même mécanique,
      et répondre à « qui peut faire quoi » demandait d'ouvrir douze fichiers.

    - Deux questions, dans cet ordre :
        1. le rôle porte-t-il cette action sur cette ressource ?      → la table 'DROITS'
        2. l'objet visé est-il dans le périmètre de l'utilisateur ?   → 'Perimetre'

    - Les règles fines (on ne suspend pas un fondateur, un agent ne gère que des opérateurs…) vivent dans des GARDES, pas ici. Le voter leur demande simplement leur avis : la garde expose un booléen pour lui — donc pour l'affichage des boutons — et un 'assert' pour le processor, qui lui doit un motif lisible. Un voter ne sait répondre que oui ou non, et un « non » muet n'aide personne.

    - Convention d'appel, reprise de Transport :
        · is_granted('VOIR', 'Site')  → collection ou création, le sujet est le nom court
        · is_granted('VOIR', $site)   → élément, le sujet est l'entité
*/
final class AccesVoter extends Voter
{
    public const VOIR = 'VOIR';
    public const CREER = 'CREER';
    public const MODIFIER = 'MODIFIER';
    public const SUPPRIMER = 'SUPPRIMER';
    public const BLOQUER = 'BLOQUER';
    public const ATTRIBUER = 'ATTRIBUER';
    public const RECHARGER = 'RECHARGER';
    public const TRAITER = 'TRAITER';
    public const PAYER = 'PAYER';
    public const PROMOUVOIR = 'PROMOUVOIR';

    /*
        - Qui peut quoi, par rôle et par ressource. C'est la SEULE source de vérité des droits
          grossiers : une ressource absente de la ligne d'un rôle lui est fermée, et un rôle absent
          de la table n'a aucun droit — c'est ce qui écarte le compte machine ('ROLE_SITE'), qui ne
          sait que déposer des pesées.

        - Les ressources des étapes suivantes sont déjà listées : la table se lit d'un bloc, et
          l'oubli d'une ligne se voit tout de suite.
    */
    private const DROITS = [
        Role::SUPER_ADMIN->value => [
            'Entreprise' => [self::VOIR, self::CREER, self::MODIFIER, self::BLOQUER],
            'User' => [self::VOIR, self::CREER, self::MODIFIER, self::SUPPRIMER, self::BLOQUER, self::PROMOUVOIR],
            /*
                - 'BLOQUER' lui revient au même titre qu'à l'administrateur du client : c'est lui qui
                  met les postes en service, donc lui qu'on appelle quand l'un d'eux déraille — et il
                  peut avoir à le couper sans attendre que le client s'en aperçoive.
                - Il n'a en revanche ni 'ATTRIBUER' ni les ressources d'exploitation : couper un poste
                  n'est pas toucher à la caisse qu'il tient.
            */
            'Site' => [self::VOIR, self::CREER, self::MODIFIER, self::BLOQUER],
        ],
        Role::ADMIN->value => [
            'Entreprise' => [self::VOIR, self::MODIFIER, self::RECHARGER],
            'User' => [self::VOIR, self::CREER, self::MODIFIER, self::SUPPRIMER, self::BLOQUER, self::PROMOUVOIR],
            'Site' => [self::VOIR, self::MODIFIER, self::BLOQUER, self::ATTRIBUER],
            'Produit' => [self::VOIR, self::CREER, self::MODIFIER, self::SUPPRIMER],
            'Fournisseur' => [self::VOIR, self::CREER, self::MODIFIER, self::SUPPRIMER],
            'Operation' => [self::VOIR],
            'Paiement' => [self::VOIR, self::PAYER],
            'MouvementCaisse' => [self::VOIR],
            'DemandeSolde' => [self::VOIR, self::TRAITER],
            'SynchronisationLot' => [self::VOIR],
            'Activite' => [self::VOIR], // Le journal des decisions : une lecture de supervision
        ],
        Role::AGENT->value => [
            'Entreprise' => [self::VOIR],
            'User' => [self::VOIR, self::CREER, self::MODIFIER, self::BLOQUER, self::PROMOUVOIR], // Sur des opérateurs seulement : c'est 'UserGuard' qui borne la cible
            'Site' => [self::VOIR],
            'Produit' => [self::VOIR],
            'Fournisseur' => [self::VOIR],
            'Operation' => [self::VOIR],
            'Paiement' => [self::VOIR],
            'MouvementCaisse' => [self::VOIR],
            'DemandeSolde' => [self::VOIR],
            'SynchronisationLot' => [self::VOIR],
            'Activite' => [self::VOIR],
        ],
        Role::OPERATEUR->value => [
            'Site' => [self::VOIR],
            'Produit' => [self::VOIR, self::MODIFIER],                  // Il tarifie
            'Fournisseur' => [self::VOIR, self::CREER, self::MODIFIER], // Il fixe les prix spéciaux
            'Operation' => [self::VOIR],
            'Paiement' => [self::VOIR, self::PAYER],
            'MouvementCaisse' => [self::VOIR],
            'DemandeSolde' => [self::VOIR, self::CREER, self::MODIFIER], // Il corrige et annule la sienne ; « tant qu'elle n'est pas traitée » est vérifié par les processors
        ],
    ];

    public function __construct(
        private Perimetre $perimetre,
        private UserGuard $userGuard
    )
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [
            self::VOIR, self::CREER, self::MODIFIER, self::SUPPRIMER, self::BLOQUER,
            self::ATTRIBUER, self::RECHARGER, self::TRAITER, self::PAYER, self::PROMOUVOIR,
        ], true) && (is_string($subject) || is_object($subject));
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

        $droits = self::DROITS[$this->role($user)][$this->ressource($subject)] ?? [];

        if(!in_array($attribute, $droits, true)) {
            return false;
        }

        if(!$this->userGuard->peutGerer($user, $subject instanceof User ? $subject : null, $attribute)) {
            return false; // La hiérarchie de gestion des comptes, que la table ne sait pas exprimer
        }

        return is_string($subject) || $this->dansMonPerimetre($user, $subject);
    }

    /** Chaque compte porte exactement un rôle métier : il n'y a pas de 'role_hierarchy'. */
    private function role(User $user): string
    {
        foreach($user->getRoles() as $role) {
            if(isset(self::DROITS[$role])) {
                return $role;
            }
        }

        return '';
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
