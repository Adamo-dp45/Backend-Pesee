<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation as ApiOperation;
use ApiPlatform\State\ProviderInterface;
use App\Domain\Enum\Role;
use App\Domain\Enum\StatutDemande;
use App\Entity\Operation;
use App\Entity\Output\TableauDeBordOutput;
use App\Repository\CompteEpargneRepository;
use App\Repository\DemandePrelevementRepository;
use App\Repository\DemandeRetraitRepository;
use App\Repository\DemandeSoldeRepository;
use App\Repository\OperationRepository;
use App\Repository\PaiementRepository;
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
        private readonly PaiementRepository $paiements,
        private readonly CompteEpargneRepository $epargnes,
        private readonly DemandeSoldeRepository $demandesSolde,
        private readonly DemandePrelevementRepository $demandesPrelevement,
        private readonly DemandeRetraitRepository $demandesRetrait,
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

        /*
            - « Reste à payer » est ce que le GUICHET va encore sortir, pas ce que vaut la récolte :
              le transport s'ajoute, la mise en compte se retranche. C'est sur ce chiffre qu'un
              opérateur dimensionne sa demande de fonds — le laisser sur le seul montant dû le ferait
              demander trop peu, durablement et sans le moindre signal.
        */
        $totaux = $this->operations->totaux($siteIds, $periode->du, $periode->au);
        $totaux['montantRestant'] = max(0, $totaux['montantDu'] + $totaux['montantTransport'] - $totaux['retenue'] - $totaux['montantPaye']);
        $sortie->totaux = $totaux;

        $sortie->parJour = $this->operations->parJour($siteIds, $periode->du, $periode->au);
        $sortie->parProduit = $this->operations->parProduit($siteIds, $periode->du, $periode->au);

        $this->remplirSites($sortie, $siteIds, $periode);
        $this->remplirCreance($sortie, $siteIds);
        $this->remplirDemandes($sortie, $siteIds);

        $sortie->dernieresPesees = array_map($this->resumerPesee(...), $this->operations->dernieres($siteIds, 10));

        return $sortie;
    }

    /*
        - LA FILE DU GUICHET, SANS AUCUN FILTRE : la créance ne connaît pas de période. Une pesée
          de mars non réglée est due aujourd'hui, et 'totaux.montantRestant' — qui s'arrête au mois
          affiché — ne la voit pas. Les deux chiffres cohabitent désormais sur l'écran, nommés à
          part, parce qu'ils répondent à deux questions : « combien ce mois-ci » et « combien en
          tout ».

        - LA MÊME MÉTHODE QUE L'ÉCRAN « À PAYER », appelée avec un tableau de critères vide. Écrire
          ici une requête qui lui ressemble donnerait deux créances, et c'est toujours celle qu'on
          ne regarde pas qui est juste.

        @param list<int> $siteIds
    */
    private function remplirCreance(TableauDeBordOutput $sortie, array $siteIds): void
    {
        $file = $this->operations->totauxAPayer($siteIds, []);

        $sortie->creance = [
            'total' => $file['resteProduit'] + $file['resteTransport'],
            'produit' => $file['resteProduit'],
            'transport' => $file['resteTransport'],
            'nbPesees' => $file['nbPesees'],
            'nbSansPrix' => $file['nbSansPrix'],
        ];
    }

    /*
        - TROIS COMPTEURS, TROIS ÉCRANS. Ils ne s'additionnent que pour la pastille ; le détail est
          ce qui dit où aller. Un opérateur les voit aussi : ce sont ses demandes à lui qui dorment,
          et savoir qu'elles n'ont pas été tranchées lui évite de redemander.

        @param list<int> $siteIds
    */
    private function remplirDemandes(TableauDeBordOutput $sortie, array $siteIds): void
    {
        $solde = $this->demandesSolde->compterParStatut($siteIds, StatutDemande::EN_ATTENTE);
        $prelevement = $this->demandesPrelevement->compterParStatut($siteIds, StatutDemande::EN_ATTENTE);
        $retrait = $this->demandesRetrait->compterParStatut($siteIds, StatutDemande::EN_ATTENTE);

        $sortie->demandes = [
            'solde' => $solde,
            'prelevement' => $prelevement,
            'retrait' => $retrait,
            'total' => $solde + $prelevement + $retrait,
        ];
    }

    /** @param list<int> $siteIds */
    private function remplirSites(TableauDeBordOutput $sortie, array $siteIds, Periode $periode): void
    {
        $production = [];

        foreach($this->operations->parSite($siteIds, $periode->du, $periode->au) as $ligne) {
            $production[$ligne['siteId']] = $ligne;
        }

        $soldeSites = 0;
        $immobiliseTotal = 0;
        $lignes = [];
        $silencieux = [];
        $maintenant = new \DateTimeImmutable();

        /* Une requête groupée pour tous les postes, et non une par ligne : c'est la même source que l'écran Trésorerie. */
        $immobiliseParSite = $this->paiements->montantsImmobilises($siteIds);

        foreach($this->sites->findBy(['id' => $siteIds, 'deletedAt' => null]) as $site) {
            $id = (int) $site->getId();
            $soldeSites += $site->getSolde();
            $immobiliseTotal += $immobiliseParSite[$id] ?? 0;

            /* Un poste sans pesée sur la période reste dans la liste, à zéro : son absence se remarque, sa disparition non. */
            $lignes[] = ($production[$id] ?? [
                'siteId' => $id,
                'site' => (string) $site->getLibellesite(),
                'tonnageNet' => 0,
                'montantDu' => 0,
                'montantTransport' => 0,
                'retenue' => 0,
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
            - L'ÉPARGNE DUE SUIT LA MÊME PORTE : c'est une dette de l'entreprise envers les planteurs, pas un chiffre de poste, et elle se lit sur le compte de l'entreprise entière.
        */
        $operateur = $this->perimetre->utilisateur()?->aLeRole(Role::OPERATEUR) ?? false;
        $entreprise = $operateur ? null : $this->perimetre->entreprise();

        $sortie->soldes = [
            'entreprise' => $operateur ? null : ($entreprise?->getSolde() ?? 0),
            'sites' => $soldeSites,
            'immobilise' => $immobiliseTotal,
            /* Ce qu'on peut encore engager aujourd'hui. L'épargne due n'en est PAS retranchée : elle n'est dans aucune caisse, les billets sont bien là, et la soustraire les ferait disparaître deux fois. */
            'disponible' => max(0, $soldeSites - $immobiliseTotal),
            'epargneDue' => $operateur ? null : ($entreprise === null ? 0 : $this->epargnes->totalDu($entreprise)),
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
