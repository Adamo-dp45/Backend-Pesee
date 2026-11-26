<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Service\Caisse\CaisseService;
use App\Entity\Input\MontantInput;
use App\Entity\Output\EcritureOutput;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Recharge du solde global. Toujours SON entreprise, jamais un identifiant transmis : c'est ce qui empêche de créditer la caisse d'un autre client.
*/

/**
 * @implements ProcessorInterface<MontantInput, EcritureOutput>
 */
class RechargerEntrepriseProcessor implements ProcessorInterface
{
    public function __construct(
        private CaisseService $caisse,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EcritureOutput
    {
        $auteur = $this->security->getUser();
        $entreprise = $auteur instanceof User ? $auteur->getEntreprise() : null;

        if($entreprise === null) {
            throw new UnprocessableEntityHttpException('Vous n\'êtes rattaché à aucune entreprise.');
        }

        if($data->caisse === null) {
            throw new UnprocessableEntityHttpException('Précisez la caisse à alimenter.');
        }

        /*
            - On rend L'ÉCRITURE et non l'entreprise : le solde se relit à tout moment, la ligne qui
              vient de naître, non. C'est à elle que l'écran rattache son bordereau.
        */
        return EcritureOutput::depuis(
            $this->caisse->rechargerEntreprise($entreprise, $data->caisse, (int) $data->montant, $data->motif)
        );
    }
}
