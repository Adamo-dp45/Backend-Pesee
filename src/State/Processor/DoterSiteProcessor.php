<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Service\Caisse\CaisseService;
use App\Entity\Input\MontantInput;
use App\Entity\Site;
use App\Repository\SiteRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

// Transfère de l'argent de l'entreprise vers la caisse d'un pont bascule

/**
 * @implements ProcessorInterface<MontantInput, Site>
 */
class DoterSiteProcessor implements ProcessorInterface
{
    public function __construct(
        private CaisseService $caisse,
        private SiteRepository $siteRepository
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Site
    {
        $site = $this->siteRepository->find($uriVariables['id'] ?? 0);

        if(!$site instanceof Site) {
            throw new NotFoundHttpException('Ce pont bascule est introuvable.');
        }

        if($data->caisse === null) {
            throw new UnprocessableEntityHttpException('Précisez la caisse à doter.');
        }

        $this->caisse->doterSite($site, $data->caisse, (int) $data->montant, $data->motif);

        return $site;
    }
}
