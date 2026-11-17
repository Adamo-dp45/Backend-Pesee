<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\StatutDemande;
use App\Entity\DemandeRetrait;
use App\Entity\User;
use App\Repository\DemandeRetraitRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Dépôt d'une demande de retrait par l'opérateur, qui a le planteur devant lui.

    - Une seule en attente par planteur : sans cette règle, deux demandes accordées coup sur coup
      autorisent deux versements qui, ensemble, dépassent l'épargne. Le refus tomberait alors au
      guichet, devant le planteur, pour une décision prise au bureau.

    - LES DEMANDES DÉJÀ ACCORDÉES ET PAS ENCORE SERVIES COMPTENT AUSSI. Elles n'ont rien débité —
      l'épargne ne bouge qu'au versement — mais elles sont autorisées : les ignorer laisserait
      empiler des autorisations dont la somme dépasse le solde.

    - Le montant n'est pas borné par le solde ici : entre le dépôt et le versement, une pesée peut
      alimenter le compte. La seule borne qui engage est celle du guichet, lue sous verrou.

    - Le pont bascule est recopié depuis le planteur et jamais saisi : c'est ce qui permet au
      cloisonnement de filtrer les demandes.
*/

/**
 * @implements ProcessorInterface<DemandeRetrait, DemandeRetrait>
 */
class DemandeRetraitProcessor implements ProcessorInterface
{
    public function __construct(
        private ProcessorInterface $processor, // Le processor natif décoré : c'est lui qui persiste
        private DemandeRetraitRepository $demandes,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DemandeRetrait
    {
        $planteur = $data->getFournisseur();

        if($planteur === null) {
            throw new UnprocessableEntityHttpException('La demande doit viser un planteur.');
        }

        if($this->demandes->findEnAttentePourFournisseur($planteur) !== null) {
            throw new UnprocessableEntityHttpException('Une demande de retrait est déjà en attente pour ce planteur. Corrigez-la ou annulez-la avant d\'en déposer une autre.');
        }

        $dejaAutorise = array_sum(array_map(
            static fn(DemandeRetrait $demande): int => (int) $demande->getMontantAccorde(),
            $this->demandes->findAccordeesNonServies($planteur)
        ));

        if($dejaAutorise > 0) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Un retrait de %s F CFA est déjà accordé pour ce planteur et n\'a pas encore été versé. Servez-le au guichet avant d\'en demander un autre.',
                number_format($dejaAutorise, 0, ',', ' ')
            ));
        }

        $data->setSite($planteur->getSite());

        $auteur = $this->security->getUser();

        if($auteur instanceof User) {
            $data->setDemandePar($auteur)->setCreatedBy($auteur->getId());
        }

        $data->setStatut(StatutDemande::EN_ATTENTE);

        return $this->processor->process($data, $operation, $uriVariables, $context);
    }
}
