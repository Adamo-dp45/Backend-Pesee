<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation as ApiOperation;
use ApiPlatform\State\ProviderInterface;
use App\Domain\Enum\Role;
use App\Domain\Enum\StatutDemande;
use App\Entity\Output\CaisseOutput;
use App\Repository\CompteEpargneRepository;
use App\Repository\DemandeSoldeRepository;
use App\Repository\PaiementRepository;
use App\Repository\SiteRepository;
use App\Security\Perimetre;

/*
    - L'état de la trésorerie. Le MONTANT IMMOBILISÉ est le point non évident : ce sont les
      versements déjà sortis des caisses mais pas encore confirmés par la passerelle. Il explique
      l'écart entre ce qu'un opérateur croit avoir et ce qu'il peut réellement engager.
*/

/**
 * @implements ProviderInterface<CaisseOutput>
 */
final class CaisseProvider implements ProviderInterface
{
    public function __construct(
        private readonly Perimetre $perimetre,
        private readonly SiteRepository $sites,
        private readonly PaiementRepository $paiements,
        private readonly DemandeSoldeRepository $demandes,
        private readonly CompteEpargneRepository $epargnes
    )
    {
    }

    public function provide(ApiOperation $operation, array $uriVariables = [], array $context = []): CaisseOutput
    {
        $sortie = new CaisseOutput();
        $siteIds = $this->perimetre->siteIds();

        /*
            - Le solde de l'entreprise n'est montré qu'à ceux qui la pilotent : un opérateur tient la caisse d'un poste, la trésorerie globale ne le regarde pas. Il reste à 'null' et non à zéro, qui se lirait « l'entreprise n'a plus rien ».
        */
        if(!$this->perimetre->utilisateur()?->aLeRole(Role::OPERATEUR)) {
            $entreprise = $this->perimetre->entreprise();

            $sortie->soldeEntreprise = $entreprise?->getSolde() ?? 0;
            $sortie->soldeEntreprisePhysique = $entreprise?->getSoldePhysique() ?? 0;
            $sortie->soldeEntrepriseNumerique = $entreprise?->getSoldeNumerique() ?? 0;
            $sortie->soldeEntrepriseBancaire = $entreprise?->getSoldeBancaire() ?? 0;

            // L'épargne des planteurs est une dette de l'entreprise, pas une caisse : elle se lit à côté des soldes, jamais dedans
            $sortie->epargneDue = $entreprise !== null ? $this->epargnes->totalDu($entreprise) : 0;
        }

        if($siteIds === []) {
            return $sortie;
        }

        $immobiliseParSite = $this->paiements->montantsImmobilises($siteIds);

        foreach($this->sites->findBy(['id' => $siteIds, 'deletedAt' => null]) as $site) {
            $id = (int) $site->getId();
            $immobilise = $immobiliseParSite[$id] ?? 0;

            $sortie->soldeSites += $site->getSolde();
            $sortie->soldeSitesPhysique += $site->getSoldePhysique();
            $sortie->soldeSitesNumerique += $site->getSoldeNumerique();
            $sortie->montantImmobilise += $immobilise;

            $sortie->parSite[] = [
                'siteId' => $id,
                'site' => (string) $site->getLibellesite(),
                'solde' => $site->getSolde(),
                'soldePhysique' => $site->getSoldePhysique(),
                'soldeNumerique' => $site->getSoldeNumerique(),
                'immobilise' => $immobilise, // Toujours sur le numérique : les espèces ne restent pas en vol
                'operateur' => $site->getOperateur()?->getNomComplet(),
            ];
        }

        $sortie->demandesEnAttente = $this->demandes->compterParStatut($siteIds, StatutDemande::EN_ATTENTE);

        // Le témoin de la réconciliation : sans lui, un cron arrêté ne se remarque qu'au moment où un opérateur ne peut plus payer
        $enVol = $this->paiements->versementsEnVol($siteIds);

        $sortie->versementsEnVol = $enVol['nombre'];
        $sortie->attenteMinutes = $enVol['depuis'] === null
            ? null
            : max(0, intdiv(time() - $enVol['depuis']->getTimestamp(), 60));

        return $sortie;
    }
}
