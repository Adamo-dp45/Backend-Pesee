<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\ModeReglement;
use App\Domain\Enum\StatutDemande;
use App\Domain\Service\Caisse\PaiementService;
use App\Entity\DemandeRetrait;
use App\Entity\Input\VersementRetraitInput;
use App\Entity\User;
use App\Repository\DemandeRetraitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Le guichet sert un retrait que l'administrateur a accordé. C'est ICI, et seulement ici, que
      l'argent bouge : l'accord n'avait rien écrit.

    - LE MONTANT VIENT DE L'ACCORD, jamais du corps de la requête. Le laisser saisir rendrait la
      décision de l'administrateur décorative, et rouvrirait au guichet la porte qu'il vient de
      fermer en accordant moins que demandé.

    - Deux livres bougent dans le même geste, et c'est 'PaiementService::initierRetrait()' qui les
      tient ensemble : l'épargne du planteur et la caisse qui lui remet l'argent. Si la caisse n'a
      pas la provision, rien n'est écrit nulle part.

    - La demande garde le versement qui l'a servie : c'est ce qui distingue « accordé » de « servi »,
      et ce qui empêche de la servir deux fois.
*/

/**
 * @implements ProcessorInterface<VersementRetraitInput, DemandeRetrait>
 */
class VerserRetraitProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private DemandeRetraitRepository $demandes,
        private PaiementService $paiements,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DemandeRetrait
    {
        $auteur = $this->security->getUser();

        if(!$auteur instanceof User) {
            throw new AccessDeniedHttpException('Authentification requise.');
        }

        $demande = $this->demandes->find($uriVariables['id'] ?? 0);

        if(!$demande instanceof DemandeRetrait) {
            throw new NotFoundHttpException('Cette demande est introuvable.');
        }

        if($demande->getStatut() !== StatutDemande::APPROUVEE) {
            throw new UnprocessableEntityHttpException('Seule une demande accordée peut être servie au guichet.');
        }

        if($demande->getPaiement() !== null) {
            throw new UnprocessableEntityHttpException('Ce retrait a déjà été versé : il ne peut pas l\'être une seconde fois.');
        }

        $planteur = $demande->getFournisseur();

        if($planteur === null) {
            throw new UnprocessableEntityHttpException('Cette demande ne vise aucun planteur.');
        }

        $paiement = $this->paiements->initierRetrait(
            $planteur,
            (int) $demande->getMontantAccorde(),
            $data->modeReglement ?? ModeReglement::ESPECES, // La validation l'exige déjà ; ce repli n'est là que pour le typage
            $auteur,
            $data->numeroDestinataire,
            $data->reseau,
            $data->numeroCheque
        );

        $demande->setPaiement($paiement)->setUpdatedBy($auteur->getId());

        $this->em->flush();

        return $demande;
    }
}
