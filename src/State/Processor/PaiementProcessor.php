<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Service\Caisse\PaiementService;
use App\Entity\Input\PaiementInput;
use App\Entity\Paiement;
use App\Entity\User;
use App\Repository\OperationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
    - Verse tout ou partie de ce qui est dû à un planteur.
    - Le contrôle de périmètre se fait ICI et pas seulement par le voter : celui-ci ne voit que le type 'Paiement', pas la pesée visée, qui arrive par identifiant dans le corps de la requête.
*/

/**
 * @implements ProcessorInterface<PaiementInput, Paiement>
 */
class PaiementProcessor implements ProcessorInterface
{
    public function __construct(
        private PaiementService $paiements,
        private OperationRepository $operationRepository,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Paiement
    {
        $auteur = $this->security->getUser();

        if(!$auteur instanceof User) {
            throw new AccessDeniedHttpException('Authentification requise.');
        }

        $pesee = $this->operationRepository->find($data->operationId);

        // Sans ce contrôle, un identifiant deviné permettrait de payer sur la caisse d'un voisin. Et 404 plutôt que 403 : on ne révèle pas qu'une pesée existe ailleurs
        if($pesee === null || !$this->security->isGranted('VOIR', $pesee)) {
            throw new NotFoundHttpException('Cette pesée est introuvable.');
        }

        return $this->paiements->initier($pesee, (int) $data->montant, $auteur, $data->numeroDestinataire, $data->reseau);
    }
}
