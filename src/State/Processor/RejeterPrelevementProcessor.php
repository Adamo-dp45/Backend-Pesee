<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\StatutDemande;
use App\Entity\DemandePrelevement;
use App\Entity\Input\TraiterDemandeInput;
use App\Entity\User;
use App\Repository\DemandePrelevementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Le rejet n'ouvre rien et ne touche à aucun taux : il ferme la demande, avec une explication.

    - Le motif est OBLIGATOIRE, et la règle vit ici plutôt que dans le DTO parce que le même DTO
      sert ailleurs à des traitements qui n'en exigent pas. Sans explication, l'opérateur ne sait
      pas quoi dire au planteur qui lui a demandé d'épargner, et il redemandera la même chose.
*/

/**
 * @implements ProcessorInterface<TraiterDemandeInput, DemandePrelevement>
 */
class RejeterPrelevementProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private DemandePrelevementRepository $demandes,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DemandePrelevement
    {
        $demande = $this->demandes->find($uriVariables['id'] ?? 0);

        if(!$demande instanceof DemandePrelevement) {
            throw new NotFoundHttpException('Cette demande est introuvable.');
        }

        if($demande->estTraitee()) {
            throw new UnprocessableEntityHttpException('Cette demande a déjà été traitée.');
        }

        $motif = trim((string) ($data->motifRejet ?? ''));

        if($motif === '') {
            throw new UnprocessableEntityHttpException('Un rejet doit être motivé : l\'opérateur doit pouvoir expliquer au planteur pourquoi sa demande n\'a pas abouti.');
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
