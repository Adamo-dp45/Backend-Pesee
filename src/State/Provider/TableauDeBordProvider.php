<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation as ApiOperation;
use ApiPlatform\State\ProviderInterface;
use App\Domain\Enum\Role;
use App\Entity\Operation;
use App\Entity\Output\TableauDeBordOutput;
use App\Repository\OperationRepository;
use App\Repository\SiteRepository;
use App\Repository\SynchronisationLotRepository;
use App\Security\Perimetre;
use Symfony\Component\HttpFoundation\RequestStack;

/*
    - Tout l'écran d'accueil en UNE seule réponse : totaux de la période, courbe journalière,
      répartition par produit et par pont bascule, dernières pesées et postes silencieux. C'est
      délibéré — sur une connexion de terrain, enchaîner six appels rend l'écran inutilisable.
*/

/**
 * @implements ProviderInterface<TableauDeBordOutput>
 */
final class TableauDeBordProvider implements ProviderInterface
{
    /* Au-delà, on considère qu'un poste s'est tu. Trois jours laissent passer un week-end sans crier au loup. */
    private const JOURS_AVANT_ALERTE = 3;

    public function __construct(
        private readonly Perimetre $perimetre,
        private readonly OperationRepository $operations,
        private readonly SiteRepository $sites,
        private readonly SynchronisationLotRepository $lots,
        private readonly RequestStack $requetes
    )
    {
    }

    public function provide(ApiOperation $operation, array $uriVariables = [], array $context = []): TableauDeBordOutput
    {
        $sortie = new TableauDeBordOutput();
        $periode = Periode::depuis($this->requetes);
        $sortie->periode = $periode->versTableau();

        $siteIds = $this->perimetre->siteIds();

        if($siteIds === []) {
            return $sortie;
        }

        $totaux = $this->operations->totaux($siteIds, $periode->du, $periode->au);
        $totaux['montantRestant'] = max(0, $totaux['montantDu'] - $totaux['montantPaye']);
        $sortie->totaux = $totaux;

        $sortie->parJour = $this->operations->parJour($siteIds, $periode->du, $periode->au);
        $sortie->parProduit = $this->operations->parProduit($siteIds, $periode->du, $periode->au);

        $this->remplirSites($sortie, $siteIds, $periode);

        $sortie->dernieresPesees = array_map($this->resumerPesee(...), $this->operations->dernieres($siteIds, 10));

        return $sortie;
    }

    /** @param list<int> $siteIds */
    private function remplirSites(TableauDeBordOutput $sortie, array $siteIds, Periode $periode): void
    {
        $production = [];

        foreach($this->operations->parSite($siteIds, $periode->du, $periode->au) as $ligne) {
            $production[$ligne['siteId']] = $ligne;
        }

        $soldeSites = 0;
        $lignes = [];
        $silencieux = [];
        $maintenant = new \DateTimeImmutable();

        foreach($this->sites->findBy(['id' => $siteIds, 'deletedAt' => null]) as $site) {
            $id = (int) $site->getId();
            $soldeSites += $site->getSolde();

            /* Un poste sans pesée sur la période reste dans la liste, à zéro : son absence se remarque, sa disparition non. */
            $lignes[] = ($production[$id] ?? [
                'siteId' => $id,
                'site' => (string) $site->getLibellesite(),
                'tonnageNet' => 0,
                'montantDu' => 0,
                'montantPaye' => 0,
                'nbPesees' => 0,
            ]) + ['solde' => $site->getSolde()];

            /*
                - Un poste dont on n'a rien reçu depuis plusieurs jours est probablement en panne ou déconnecté. Sans cette alerte, ça ne se remarque qu'en s'étonnant d'un tonnage anormalement bas, des semaines plus tard.
                - Un site JAMAIS synchronisé n'est pas silencieux tant qu'il est récent : il est déclaré, pas encore installé. On compte donc les jours depuis sa déclaration, sinon toute création affiche une alerte le jour même.
            */
            $dernier = $this->lots->findDernierPourSite($site)?->getCreatedAt();
            $reference = $dernier ?? $site->getCreatedAt();
            $jours = $reference === null ? null : (int) $reference->diff($maintenant)->days;

            if($jours !== null && $jours >= self::JOURS_AVANT_ALERTE) {
                $silencieux[] = [
                    'site' => (string) $site->getLibellesite(),
                    'dernierEnvoi' => $dernier?->format(\DateTimeInterface::ATOM),
                    'joursSansEnvoi' => $dernier === null ? null : $jours,
                    'joursDepuisCreation' => $dernier === null ? $jours : null,
                ];
            }
        }

        usort($lignes, static fn(array $a, array $b) => $b['tonnageNet'] <=> $a['tonnageNet']);

        $sortie->parSite = $lignes;
        $sortie->postesSilencieux = $silencieux;

        /*
            - Même règle que 'CaisseProvider' : un opérateur tient la caisse d'un poste, la trésorerie globale de l'entreprise ne le regarde pas.
            - 'null' et non zéro, qui se lirait « l'entreprise n'a plus rien ». Et ça se filtre ICI, pas dans le gabarit : sinon la valeur voyage quand même dans la réponse JSON, où elle se lit en deux clics.
        */
        $sortie->soldes = [
            'entreprise' => $this->perimetre->utilisateur()?->aLeRole(Role::OPERATEUR)
                ? null
                : ($this->perimetre->entreprise()?->getSolde() ?? 0),
            'sites' => $soldeSites,
        ];
    }

    /** @return array<string, mixed> */
    private function resumerPesee(Operation $pesee): array
    {
        return [
            'id' => $pesee->getId(),
            'numticket' => $pesee->getNumticket(),
            'site' => $pesee->getSite()?->getLibellesite(),
            'produit' => $pesee->getProduit()?->getLibelle(),
            'planteur' => $pesee->getFournisseur()?->getNomComplet(),
            'immatriculation' => $pesee->getImmatriculation(),
            'poidsnet' => $pesee->getPoidsnet(),
            'montantdu' => $pesee->getMontantdu(),
            'statutReglement' => $pesee->getStatutReglement()->value,
            'peseeAt2' => $pesee->getPeseeAt2()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
