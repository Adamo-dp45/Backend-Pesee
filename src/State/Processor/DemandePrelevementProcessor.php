<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\DemandePrelevement;
use App\Entity\User;
use App\Repository\DemandePrelevementRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Dépôt d'une demande de mise en compte par l'opérateur, qui a le planteur devant lui.

    - Une seule en attente par planteur : sans cette règle, deux demandes approuvées coup sur coup
      laissent deux taux, et personne ne sait lequel s'applique aux pesées suivantes.

    - Le pont bascule est recopié depuis le planteur et jamais saisi : c'est ce qui permet au
      cloisonnement de filtrer les demandes, et cela évite qu'une demande puisse viser un poste qui
      n'est pas celui du planteur.
*/

/**
 * @implements ProcessorInterface<DemandePrelevement, DemandePrelevement>
 */
class DemandePrelevementProcessor implements ProcessorInterface
{
    public function __construct(
        private ProcessorInterface $processor, // Le processor natif décoré : c'est lui qui persiste
        private DemandePrelevementRepository $demandes,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DemandePrelevement
    {
        $planteur = $data->getFournisseur();

        if($planteur === null) {
            throw new UnprocessableEntityHttpException('La demande doit viser un planteur.');
        }

        if($this->demandes->findEnAttentePourFournisseur($planteur) !== null) {
            throw new UnprocessableEntityHttpException('Une demande est déjà en attente pour ce planteur. Corrigez-la ou annulez-la avant d\'en déposer une autre.');
        }

        if($planteur->epargneEnCours()) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Un prélèvement de %d F par kilo est déjà en cours pour ce planteur. Il doit d\'abord être arrêté.',
                (int) $planteur->getTauxEpargne()
            ));
        }

        $data->setSite($planteur->getSite());

        $auteur = $this->security->getUser();

        if($auteur instanceof User) {
            $data->setDemandePar($auteur)->setCreatedBy($auteur->getId());
        }

        return $this->processor->process($data, $operation, $uriVariables, $context);
    }
}
