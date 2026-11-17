<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\StatutDemande;
use App\Domain\Enum\TypeActivite;
use App\Domain\Service\ActiviteLogger;
use App\Entity\DemandeRetrait;
use App\Entity\User;
use App\Repository\DemandeRetraitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - L'ACCORD N'EST PAS LE VERSEMENT, et c'est toute la différence avec l'approbation d'une demande
      de solde, qui dote la caisse sur-le-champ. L'administrateur n'a ni le planteur en face de lui,
      ni son numéro, ni le choix du mode de règlement : il AUTORISE. L'opérateur verse ensuite au
      guichet, dans la limite accordée.

    - Aucun argent ne bouge ici, donc aucune ligne de grand livre. C'EST PRÉCISÉMENT POURQUOI ON
      JOURNALISE : sans cette trace, décider qu'un planteur peut reprendre son épargne — ou ne le
      peut qu'à moitié — ne laisserait rien.

    - Le montant accordé peut être inférieur au demandé, comme sur une demande de solde : c'est le
      cas quand la caisse du poste ne couvre pas tout. Il n'est pas borné par le solde d'épargne,
      qui aura peut-être changé d'ici le guichet — c'est là, sous verrou, que la borne mord.
*/

/**
 * @implements ProcessorInterface<mixed, DemandeRetrait>
 */
class ApprouverRetraitProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private DemandeRetraitRepository $demandes,
        private ActiviteLogger $activites,
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

        $planteur = $demande->getFournisseur();

        if($planteur === null) {
            throw new UnprocessableEntityHttpException('Cette demande ne vise aucun planteur.');
        }

        $accorde = $data->montantAccorde ?? $demande->getMontantDemande(); // Absent = on accorde ce qui a été demandé

        if($accorde > $demande->getMontantDemande()) {
            throw new UnprocessableEntityHttpException(sprintf(
                'On n\'accorde pas plus que ce qui a été demandé : %s F CFA au maximum.',
                number_format($demande->getMontantDemande(), 0, ',', ' ')
            ));
        }

        $auteur = $this->security->getUser();
        $auteur = $auteur instanceof User ? $auteur : null;

        $demande
            ->setStatut(StatutDemande::APPROUVEE)
            ->setMontantAccorde($accorde)
            ->setTraitePar($auteur)
            ->setTraiteAt(new \DateTimeImmutable())
            ->setUpdatedBy($auteur?->getId());

        // Avant le flush : la trace part dans la même transaction que la décision
        $this->activites->log(
            TypeActivite::RETRAIT_ACCORDE,
            sprintf(
                '%s a accordé un retrait de %s F CFA sur l\'épargne de %s%s',
                $auteur?->getNomComplet() ?? 'Le système',
                number_format($accorde, 0, ',', ' '),
                $planteur->getNomComplet(),
                $accorde < $demande->getMontantDemande()
                    ? sprintf(' (il en demandait %s)', number_format($demande->getMontantDemande(), 0, ',', ' '))
                    : ''
            ),
            $planteur
        );

        $this->em->flush();

        return $demande;
    }
}
