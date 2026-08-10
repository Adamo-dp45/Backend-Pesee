<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Service\MailerService;
use App\Entity\DemandeSolde;
use App\Entity\User;
use App\Repository\DemandeSoldeRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Dépôt d'une demande de réapprovisionnement.
    - Une seule en attente par pont bascule : sans cette règle, un opérateur impatient en dépose trois, un administrateur les approuve toutes, et le site reçoit le triple de ce qu'il fallait.
*/

/**
 * @implements ProcessorInterface<DemandeSolde, DemandeSolde>
 */
class DemandeSoldeProcessor implements ProcessorInterface
{
    public function __construct(
        private ProcessorInterface $processor, // Le processor natif décoré : c'est lui qui persiste
        private DemandeSoldeRepository $demandes,
        private MailerService $mailer,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DemandeSolde
    {
        $site = $data->getSite();

        if($site === null) {
            throw new UnprocessableEntityHttpException('La demande doit viser un pont bascule.');
        }

        if($this->demandes->findEnAttentePourSite($site) !== null) {
            throw new UnprocessableEntityHttpException('Une demande est déjà en attente pour ce pont bascule. Corrigez-la ou annulez-la avant d\'en déposer une autre.');
        }

        $auteur = $this->security->getUser();

        if($auteur instanceof User) {
            $data->setDemandePar($auteur)->setCreatedBy($auteur->getId());
        }

        $demande = $this->processor->process($data, $operation, $uriVariables, $context);

        // Après la persistance : on ne prévient pas d'une demande qui n'a pas été enregistrée
        $this->mailer->demandeDeposee($demande);

        return $demande;
    }
}
