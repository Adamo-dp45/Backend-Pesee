<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation as ApiOperation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Output\ProductionOutput;
use App\Repository\OperationRepository;
use App\Security\Perimetre;
use Symfony\Component\HttpFoundation\RequestStack;

/*
    - Le croisement site × produit d'une période, avec ses totaux. C'est ce qui alimente la page
      Bilan et ses exports.
*/

/**
 * @implements ProviderInterface<ProductionOutput>
 */
final class ProductionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Perimetre $perimetre,
        private readonly OperationRepository $operations,
        private readonly RequestStack $requetes
    )
    {
    }

    public function provide(ApiOperation $operation, array $uriVariables = [], array $context = []): ProductionOutput
    {
        $sortie = new ProductionOutput();
        $periode = Periode::depuis($this->requetes);
        $sortie->periode = $periode->versTableau();

        $siteIds = $this->perimetre->siteIds();

        if($siteIds === []) {
            return $sortie;
        }

        $sortie->lignes = $this->operations->parSiteEtProduit($siteIds, $periode->du, $periode->au);

        // Totaux calculés sur les lignes déjà en mémoire, pour ne pas relancer les agrégats
        foreach($sortie->lignes as $ligne) {
            $sortie->totaux['nbPesees'] += $ligne['nbPesees'];
            $sortie->totaux['tonnageNet'] += $ligne['tonnageNet'];
            $sortie->totaux['montantDu'] += $ligne['montantDu'];
            $sortie->totaux['montantTransport'] += $ligne['montantTransport'];
            $sortie->totaux['retenue'] += $ligne['retenue'];
            $sortie->totaux['montantPaye'] += $ligne['montantPaye'];
        }

        // Ce que le guichet sortira encore : la récolte, PLUS le transport, MOINS ce qui part en épargne
        $sortie->totaux['montantRestant'] = max(0, $sortie->totaux['montantDu'] + $sortie->totaux['montantTransport'] - $sortie->totaux['retenue'] - $sortie->totaux['montantPaye']);

        return $sortie;
    }
}
