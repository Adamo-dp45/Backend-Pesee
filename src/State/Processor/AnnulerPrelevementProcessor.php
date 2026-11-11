<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\StatutDemande;
use App\Entity\DemandePrelevement;
use App\Entity\User;
use App\Repository\DemandePrelevementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - L'opérateur retire sa demande devenue sans objet — le planteur a changé d'avis, ou s'est
      trompé de taux. Sans elle, il faut mobiliser un administrateur pour rejeter une demande que
      plus personne ne veut, et le planteur reste bloqué pour en déposer une autre.

    - 'traitePar' reste vide : ce n'est pas une décision d'administrateur, c'est un retrait.
*/

/**
 * @implements ProcessorInterface<mixed, DemandePrelevement>
 */
class AnnulerPrelevementProcessor implements ProcessorInterface
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
