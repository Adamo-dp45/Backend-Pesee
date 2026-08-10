<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\StatutDemande;
use App\Domain\Service\Caisse\CaisseService;
use App\Domain\Service\MailerService;
use App\Entity\DemandeSolde;
use App\Entity\Input\TraiterDemandeInput;
use App\Entity\User;
use App\Repository\DemandeSoldeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Approuver n'est pas valider une ligne : ça DÉPLACE DE L'ARGENT. La dotation passe par 'CaisseService', seul endroit où un solde change — il verrouille l'entreprise puis le site, contrôle le solde disponible et écrit les deux écritures chaînées du journal. S'il refuse, rien n'est écrit sur la demande non plus : son message remonte tel quel.
*/

/**
 * @implements ProcessorInterface<TraiterDemandeInput, DemandeSolde>
 */
class ApprouverDemandeProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private CaisseService $caisse,
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

        $site = $demande->getSite();

        if($site === null) {
            throw new UnprocessableEntityHttpException('Cette demande ne vise aucun pont bascule.');
        }

        $accorde = $data->montantAccorde ?? $demande->getMontantDemande(); // Absent = on accorde ce qui a été demandé

        $this->caisse->doterSite($site, $accorde, 'Demande de réapprovisionnement approuvée', $demande);

        $auteur = $this->security->getUser();
        $auteur = $auteur instanceof User ? $auteur : null;

        $demande
            ->setStatut(StatutDemande::APPROUVEE)
            ->setMontantAccorde($accorde)
            ->setTraitePar($auteur)
            ->setTraiteAt(new \DateTimeImmutable())
            ->setUpdatedBy($auteur?->getId());

        $this->em->flush();

        // Après le flush : le message annonce une décision déjà enregistrée
        $this->mailer->demandeTraitee($demande);

        return $demande;
    }
}
