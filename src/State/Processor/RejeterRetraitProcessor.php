<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\StatutDemande;
use App\Entity\DemandeRetrait;
use App\Entity\User;
use App\Repository\DemandeRetraitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Refuser à quelqu'un l'argent qu'on lui garde demande une explication, et c'est pour cela que
      le motif est obligatoire : l'opérateur a le planteur devant lui, et doit pouvoir lui dire
      pourquoi.
*/

/**
 * @implements ProcessorInterface<\App\Entity\Input\TraiterDemandeInput, DemandeRetrait>
 */
class RejeterRetraitProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private DemandeRetraitRepository $demandes,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DemandeRetrait
    {
        $demande = $this->demandes->find($uriVariables['id'] ?? 0);

        if(!$demande instanceof DemandeRetrait) {
            throw new NotFoundHttpException('Cette demande est introuvable.');
        }

        if($demande->estTraitee()) {
            throw new UnprocessableEntityHttpException('Cette demande a déjà été traitée.');
        }

        $motif = trim((string) ($data->motifRejet ?? ''));

        if($motif === '') {
            throw new UnprocessableEntityHttpException('Un rejet doit être motivé : l\'opérateur doit pouvoir expliquer au planteur pourquoi il ne récupère pas son épargne.');
        }

        $auteur = $this->security->getUser();
        $auteur = $auteur instanceof User ? $auteur : null;

        $demande
            ->setStatut(StatutDemande::REJETEE)
            ->setMotifRejet($motif)
            ->setTraitePar($auteur)
            ->setTraiteAt(new \DateTimeImmutable())
            ->setUpdatedBy($auteur?->getId());

        $this->em->flush();

        return $demande;
    }
}
