<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\StatutDemande;
use App\Entity\DemandeSolde;
use App\Entity\User;
use App\Repository\DemandeSoldeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - L'auteur retire sa demande. Rien ne bouge côté caisse : la demande sort simplement de l'attente, ce qui libère le pont bascule pour en déposer une nouvelle.
    - 'traitePar' reste vide, contrairement à une approbation ou un rejet : personne n'a tranché, l'auteur s'est ravisé.
*/

/**
 * @implements ProcessorInterface<null, DemandeSolde>
 */
class AnnulerDemandeProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private DemandeSoldeRepository $demandes,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DemandeSolde
    {
        $demande = $this->demandes->find($uriVariables['id'] ?? 0);

        if(!$demande instanceof DemandeSolde) {
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
