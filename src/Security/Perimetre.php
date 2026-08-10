<?php

namespace App\Security;

use App\Domain\Enum\Role;
use App\Entity\Entreprise;
use App\Entity\Site;
use App\Entity\User;
use App\Repository\SiteRepository;
use Symfony\Bundle\SecurityBundle\Security;

/*
    - Le périmètre de données de l'utilisateur courant, calculé une fois par requête.

    - ATTENTION : 'PerimetreExtension' cloisonne les ressources API Platform, mais PAS les
      providers ni les requêtes écrites à la main (tableau de bord, statistiques, bilan, exports).
      Toute requête d'agrégat doit appliquer 'siteIds()' elle-même, sinon elle sort du
      cloisonnement sans que rien ne le signale.
*/
final class Perimetre
{
    /** @var list<int>|null */
    private ?array $siteIds = null;

    /*
        - Le périmètre mémorisé vaut pour UN utilisateur. En HTTP la question ne se pose pas — une requête, un utilisateur — mais une commande console ou un worker qui enchaîne plusieurs comptes réutiliserait le même service : sans cette clé, le second hériterait du périmètre du premier.
    */
    private ?string $calculePour = null;

    public function __construct(
        private Security $security,
        private SiteRepository $siteRepository
    )
    {
    }

    public function utilisateur(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    public function entreprise(): ?Entreprise
    {
        return $this->utilisateur()?->getEntreprise();
    }

    /**
     * Les ponts bascules que l'utilisateur a le droit de consulter : toute son entreprise pour un
     * administrateur ou un agent, ses seules caisses pour un opérateur, son propre poste pour un
     * compte machine.
     *
     * Un tableau vide signifie « aucun site accessible » et doit produire un résultat vide —
     * surtout pas l'absence de filtre.
     *
     * @return list<int>
     */
    public function siteIds(): array
    {
        $user = $this->utilisateur();
        $cle = $user?->getUserIdentifier();

        if($this->siteIds !== null && $this->calculePour === $cle) {
            return $this->siteIds;
        }

        $this->calculePour = $cle;

        if($user === null || $user->getEntreprise() === null) {
            return $this->siteIds = []; // Le super administrateur passe ici : il n'exploite aucun site
        }

        if($user->estCompteMachine()) {
            $site = $user->getSiteMachine();

            return $this->siteIds = $site?->getId() !== null ? [$site->getId()] : [];
        }

        return $this->siteIds = $user->aLeRole(Role::OPERATEUR)
            ? $this->siteRepository->idsParOperateur($user)
            : $this->siteRepository->idsParEntreprise($user->getEntreprise());
    }

    public function voitLeSite(?Site $site): bool
    {
        return $site?->getId() !== null && in_array($site->getId(), $this->siteIds(), true);
    }
}
