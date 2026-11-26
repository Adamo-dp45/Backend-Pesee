<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Service\Caisse\CaisseService;
use App\Entity\Input\MontantInput;
use App\Entity\Output\EcritureOutput;
use App\Entity\Site;
use App\Repository\SiteRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

// Transfère de l'argent de l'entreprise vers la caisse d'un pont bascule

/**
 * @implements ProcessorInterface<MontantInput, EcritureOutput>
 */
class DoterSiteProcessor implements ProcessorInterface
{
    public function __construct(
        private CaisseService $caisse,
        private SiteRepository $siteRepository
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EcritureOutput
    {
        $site = $this->siteRepository->find($uriVariables['id'] ?? 0);

        if(!$site instanceof Site) {
            throw new NotFoundHttpException('Ce pont bascule est introuvable.');
        }

        if($data->caisse === null) {
            throw new UnprocessableEntityHttpException('Précisez la caisse à doter.');
        }

        [$sortie, $entree] = $this->caisse->doterSite($site, $data->caisse, (int) $data->montant, $data->motif);

        /*
            - L'ENTRÉE EN TÊTE, la sortie en contrepartie : le bordereau suit l'argent jusqu'au poste
              qui le reçoit. L'opérateur lit les écritures de son poste, jamais celles de la caisse
              de l'entreprise — accrocher la pièce à la sortie l'aurait rendue invisible à celui-là
              même qui a signé la décharge.
        */
        return EcritureOutput::depuis($entree, $sortie);
    }
}
