<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Service\Caisse\CaisseService;
use App\Entity\Entreprise;
use App\Entity\Input\MontantInput;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Recharge du solde global. Toujours SON entreprise, jamais un identifiant transmis : c'est ce qui empêche de créditer la caisse d'un autre client.
*/

/**
 * @implements ProcessorInterface<MontantInput, Entreprise>
 */
class RechargerEntrepriseProcessor implements ProcessorInterface
{
    public function __construct(
        private CaisseService $caisse,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Entreprise
    {
        $auteur = $this->security->getUser();
        $entreprise = $auteur instanceof User ? $auteur->getEntreprise() : null;

        if($entreprise === null) {
            throw new UnprocessableEntityHttpException('Vous n\'êtes rattaché à aucune entreprise.');
        }

        $this->caisse->rechargerEntreprise($entreprise, (int) $data->montant, $data->motif);

        return $entreprise;
    }
}
