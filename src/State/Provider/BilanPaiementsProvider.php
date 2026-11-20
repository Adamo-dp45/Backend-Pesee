<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation as ApiOperation;
use ApiPlatform\State\ProviderInterface;
use App\Domain\Enum\ModeReglement;
use App\Domain\Enum\StatutPaiement;
use App\Entity\Output\BilanPaiementsOutput;
use App\Repository\PaiementRepository;
use App\Security\Perimetre;
use Symfony\Component\HttpFoundation\RequestStack;

/*
    - Le bilan des versements d'une période. Les agrégats sont calculés par la BASE, jamais en
      recomposant des pages de '/api/paiements' : l'API en rend cent au plus et tronque en silence,
      un total additionné ligne à ligne serait faux sans qu'aucune erreur ne le dise.

    - Trois familles, et elles ne s'additionnent pas : le confirmé, l'en-vol, le remboursé.
*/

/**
 * @implements ProviderInterface<BilanPaiementsOutput>
 */
final class BilanPaiementsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Perimetre $perimetre,
        private readonly PaiementRepository $paiements,
        private readonly RequestStack $requetes
    )
    {
    }

    public function provide(ApiOperation $operation, array $uriVariables = [], array $context = []): BilanPaiementsOutput
    {
        $sortie = new BilanPaiementsOutput();
        $periode = Periode::depuis($this->requetes);
        $sortie->periode = $periode->versTableau();

        $siteIds = $this->perimetre->siteIds();

        if($siteIds === []) {
            return $sortie;
        }

        $parMode = [];

        foreach($this->paiements->bilanParModeEtStatut($siteIds, $periode->du, $periode->au) as $ligne) {
            $mode = ModeReglement::tryFrom($ligne['mode']);
            $cle = $ligne['mode'];

            $parMode[$cle] ??= [
                'mode' => $cle,
                'libelle' => $mode?->libelle() ?? $cle,
                'nombre' => 0,
                'montant' => 0,
                'nbEnVol' => 0,
                'montantEnVol' => 0,
            ];

            if($ligne['statut'] === StatutPaiement::REUSSI->value) {
                $sortie->totaux['nbVersements'] += $ligne['nombre'];
                $sortie->totaux['montantVerse'] += $ligne['montant'];
                $sortie->totaux['montantProduit'] += $ligne['produit'];
                $sortie->totaux['montantTransport'] += $ligne['transport'];
                /*
                    - Les retraits d'épargne sont comptés sur le CONFIRMÉ, comme le versé : ce sont
                      les mêmes lignes, vues autrement. Les compter sur toutes les familles les
                      ferait apparaître deux fois le jour où un retrait en vol échoue.
                */
                $sortie->totaux['nbRetraits'] += $ligne['retraits'];
                $sortie->totaux['montantRetraits'] += $ligne['montantRetraits'];

                $parMode[$cle]['nombre'] += $ligne['nombre'];
                $parMode[$cle]['montant'] += $ligne['montant'];

                continue;
            }

            if(in_array($ligne['statut'], array_map(static fn(StatutPaiement $s) => $s->value, StatutPaiement::enCours()), true)) {
                $sortie->totaux['nbEnVol'] += $ligne['nombre'];
                $sortie->totaux['montantEnVol'] += $ligne['montant'];

                $parMode[$cle]['nbEnVol'] += $ligne['nombre'];
                $parMode[$cle]['montantEnVol'] += $ligne['montant'];

                continue;
            }

            // Échoué ou annulé : la caisse a retrouvé son argent, rien n'est sorti
            $sortie->totaux['nbRembourse'] += $ligne['nombre'];
            $sortie->totaux['montantRembourse'] += $ligne['montant'];
        }

        $sortie->parMode = array_values($parMode);
        $sortie->parSite = $this->parSite($siteIds, $periode);

        return $sortie;
    }

    /*
        - Le même découpage, poste par poste. Un poste dont tous les versements sont en vol ne doit
          pas afficher le même total qu'un poste qui a réellement payé : les deux colonnes restent
          séparées jusqu'à l'écran.

        @param list<int> $siteIds

        @return list<array{siteId: int, site: string, nombre: int, montant: int, nbEnVol: int, montantEnVol: int}>
     */
    private function parSite(array $siteIds, Periode $periode): array
    {
        $postes = [];
        $enCours = array_map(static fn(StatutPaiement $s) => $s->value, StatutPaiement::enCours());

        foreach($this->paiements->bilanParSite($siteIds, $periode->du, $periode->au) as $ligne) {
            $id = $ligne['siteId'];

            $postes[$id] ??= [
                'siteId' => $id,
                'site' => $ligne['site'],
                'nombre' => 0,
                'montant' => 0,
                'nbEnVol' => 0,
                'montantEnVol' => 0,
            ];

            if($ligne['statut'] === StatutPaiement::REUSSI->value) {
                $postes[$id]['nombre'] += $ligne['nombre'];
                $postes[$id]['montant'] += $ligne['montant'];
            } elseif(in_array($ligne['statut'], $enCours, true)) {
                $postes[$id]['nbEnVol'] += $ligne['nombre'];
                $postes[$id]['montantEnVol'] += $ligne['montant'];
            }
        }

        return array_values($postes);
    }
}
