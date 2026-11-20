<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation as ApiOperation;
use ApiPlatform\State\ProviderInterface;
use App\Domain\Enum\CompteMouvement;
use App\Domain\Enum\Role;
use App\Domain\Enum\SensMouvement;
use App\Domain\Enum\TypeCaisse;
use App\Domain\Enum\TypeMouvement;
use App\Entity\Output\BilanCaissesOutput;
use App\Repository\MouvementCaisseRepository;
use App\Security\Perimetre;
use Symfony\Component\HttpFoundation\RequestStack;

/*
    - Le bilan des caisses d'une période : les flux, pas les soldes. Les soldes du jour se lisent
      sur '/stats/caisse', et les confondre serait la faute à ne pas commettre — une caisse peut
      n'avoir rien reçu du mois et être pleine.

    - Les agrégats viennent de la BASE. Additionner des pages de '/api/mouvement_caisses' donnerait
      un total faux dès la cent-unième écriture, sans qu'aucune erreur ne le dise.
*/

/**
 * @implements ProviderInterface<BilanCaissesOutput>
 */
final class BilanCaissesProvider implements ProviderInterface
{
    public function __construct(
        private readonly Perimetre $perimetre,
        private readonly MouvementCaisseRepository $mouvements,
        private readonly RequestStack $requetes
    )
    {
    }

    public function provide(ApiOperation $operation, array $uriVariables = [], array $context = []): BilanCaissesOutput
    {
        $sortie = new BilanCaissesOutput();
        $periode = Periode::depuis($this->requetes);
        $sortie->periode = $periode->versTableau();

        $siteIds = $this->perimetre->siteIds();

        /*
            - Les écritures du compte de l'entreprise ne sont montrées qu'à ceux qui la pilotent :
              un opérateur tient la caisse d'un poste, la trésorerie globale ne le regarde pas.
              Même découpage que '/stats/caisse'.
        */
        $entrepriseId = $this->perimetre->utilisateur()?->aLeRole(Role::OPERATEUR)
            ? null
            : $this->perimetre->entreprise()?->getId();

        if($siteIds === [] && $entrepriseId === null) {
            return $sortie;
        }

        $sortie->parCaisse = $this->parCaisse($siteIds, $entrepriseId, $periode);
        $sortie->parType = $this->parType($siteIds, $entrepriseId, $periode);
        $sortie->parSite = $this->parSite($siteIds, $periode);

        foreach($sortie->parCaisse as $ligne) {
            $sortie->totaux['nbMouvements'] += $ligne['nombre'];
            $sortie->totaux['entrees'] += $ligne['entrees'];
            $sortie->totaux['sorties'] += $ligne['sorties'];
        }

        $sortie->totaux['variation'] = $sortie->totaux['entrees'] - $sortie->totaux['sorties'];

        return $sortie;
    }

    /*
        - Une ligne par (compte, caisse), avec ses deux sens séparés. Le compte en fait partie parce
          qu'une dotation sort de la caisse physique de l'ENTREPRISE pour entrer dans celle du
          POSTE : les fondre ensemble annulerait le mouvement et afficherait zéro.

        @param list<int> $siteIds

        @return list<array{compte: string, libelleCompte: string, caisse: string, libelle: string, nombre: int, entrees: int, sorties: int, variation: int}>
     */
    private function parCaisse(array $siteIds, ?int $entrepriseId, Periode $periode): array
    {
        $caisses = [];

        foreach($this->mouvements->bilanParCaisse($siteIds, $entrepriseId, $periode->du, $periode->au) as $ligne) {
            $cle = $ligne['compte'] . '|' . $ligne['caisse'];

            $caisses[$cle] ??= [
                'compte' => $ligne['compte'],
                'libelleCompte' => CompteMouvement::tryFrom($ligne['compte'])?->libelle() ?? $ligne['compte'],
                'caisse' => $ligne['caisse'],
                'libelle' => TypeCaisse::tryFrom($ligne['caisse'])?->libelle() ?? $ligne['caisse'],
                'nombre' => 0,
                'entrees' => 0,
                'sorties' => 0,
                'variation' => 0,
            ];

            $caisses[$cle]['nombre'] += $ligne['nombre'];
            $caisses[$cle][$ligne['sens'] === SensMouvement::CREDIT->value ? 'entrees' : 'sorties'] += $ligne['montant'];
        }

        foreach($caisses as $cle => $caisse) {
            $caisses[$cle]['variation'] = $caisse['entrees'] - $caisse['sorties'];
        }

        return array_values($caisses);
    }

    /*
        - Par CAUSE : dotation, versement, remboursement, recharge. Le sens est porté par le type et
          ne varie pas — un paiement est toujours un débit — mais il reste dans la ligne pour que
          l'écran sache de quel côté le ranger sans retenir la règle.

        @param list<int> $siteIds

        @return list<array{type: string, libelle: string, sens: string, nombre: int, montant: int}>
     */
    private function parType(array $siteIds, ?int $entrepriseId, Periode $periode): array
    {
        return array_map(static fn(array $ligne) => [
            'type' => $ligne['type'],
            'libelle' => TypeMouvement::tryFrom($ligne['type'])?->libelle() ?? $ligne['type'],
            'sens' => $ligne['sens'],
            'nombre' => $ligne['nombre'],
            'montant' => $ligne['montant'],
        ], $this->mouvements->bilanParType($siteIds, $entrepriseId, $periode->du, $periode->au));
    }

    /*
        - Poste par poste, tous types de caisse confondus : c'est la vue « qu'est-ce qui est passé
          par cette barrière », celle qu'on ouvre quand un opérateur dit ne plus rien avoir.

        @param list<int> $siteIds

        @return list<array{siteId: int, site: string, entrees: int, sorties: int, variation: int}>
     */
    private function parSite(array $siteIds, Periode $periode): array
    {
        $postes = [];

        foreach($this->mouvements->bilanParSite($siteIds, $periode->du, $periode->au) as $ligne) {
            $id = $ligne['siteId'];

            $postes[$id] ??= ['siteId' => $id, 'site' => $ligne['site'], 'entrees' => 0, 'sorties' => 0, 'variation' => 0];

            $postes[$id][$ligne['sens'] === SensMouvement::CREDIT->value ? 'entrees' : 'sorties'] += $ligne['montant'];
        }

        foreach($postes as $id => $poste) {
            $postes[$id]['variation'] = $poste['entrees'] - $poste['sorties'];
        }

        return array_values($postes);
    }
}
