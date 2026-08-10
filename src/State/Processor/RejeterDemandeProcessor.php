<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\StatutDemande;
use App\Domain\Service\MailerService;
use App\Entity\DemandeSolde;
use App\Entity\Input\TraiterDemandeInput;
use App\Entity\User;
use App\Repository\DemandeSoldeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

// Rejet d'une demande. Aucun argent ne bouge : seule la demande change d'état

/**
 * @implements ProcessorInterface<TraiterDemandeInput, DemandeSolde>
 */
class RejeterDemandeProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private DemandeSoldeRepository $demandes,
        private MailerService $mailer,
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
            throw new UnprocessableEntityHttpException('Cette demande a déjà été traitée.');
        }

        if(trim((string) $data->motifRejet) === '') {
            throw new UnprocessableEntityHttpException('Un rejet doit être motivé : sans explication, l\'opérateur redemandera la même chose.'); /*
                - La règle est ici et non dans le DTO : le même DTO sert à l'approbation, où le motif n'a pas lieu d'être. Une contrainte '#[Assert\NotBlank]' refuserait aussi les approbations.
            */
        }

        $auteur = $this->security->getUser();
        $auteur = $auteur instanceof User ? $auteur : null;

        $demande
            ->setStatut(StatutDemande::REJETEE)
            ->setMotifRejet($data->motifRejet)
            ->setTraitePar($auteur)
            ->setTraiteAt(new \DateTimeImmutable())
            ->setUpdatedBy($auteur?->getId());

        $this->em->flush();

        // Après le flush : le message annonce une décision déjà enregistrée
        $this->mailer->demandeTraitee($demande);

        return $demande;
    }
}
