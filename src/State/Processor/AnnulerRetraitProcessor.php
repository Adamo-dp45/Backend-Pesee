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
    - L'auteur retire sa demande tant qu'elle n'est pas traitée, sans mobiliser un administrateur
      pour une demande devenue sans objet — le planteur a changé d'avis, ou s'est trompé de montant.
*/

/**
 * @implements ProcessorInterface<mixed, DemandeRetrait>
 */
class AnnulerRetraitProcessor implements ProcessorInterface
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
            throw new UnprocessableEntityHttpException('Cette demande a déjà été traitée : elle ne peut plus être annulée.');
        }

        $auteur = $this->security->getUser();

        $demande
            ->setStatut(StatutDemande::ANNULEE)
            ->setTraiteAt(new \DateTimeImmutable())
            ->setUpdatedBy($auteur instanceof User ? $auteur->getId() : null);

        $this->em->flush();

        return $demande;
    }
}
