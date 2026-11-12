<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Support\TauxPrelevement;
use App\Entity\DemandePrelevement;
use App\Entity\User;
use App\Repository\DemandePrelevementRepository;
use App\Repository\PrelevementRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Dépôt d'une demande de mise en compte par l'opérateur, qui a le planteur devant lui.

    - Une seule en attente par planteur ET PAR PRODUIT : sans cette règle, deux demandes approuvées
      coup sur coup sur le même produit laissent deux taux, et personne ne sait lequel s'applique aux
      pesées suivantes. Le cacao et l'hévéa, eux, ne se gênent pas.

    - Le taux est refusé s'il atteint le prix du produit. C'est la règle du patron : au-dessus, le
      planteur repartirait les mains vides de chaque livraison.

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
        private PrelevementRepository $prelevements,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DemandePrelevement
    {
        $planteur = $data->getFournisseur();
        $produit = $data->getProduit();

        if($planteur === null) {
            throw new UnprocessableEntityHttpException('La demande doit viser un planteur.');
        }

        if($produit === null) {
            throw new UnprocessableEntityHttpException('La demande doit viser un produit : c\'est son prix au kilo qui borne le taux.');
        }

        /*
            - Le produit doit être celui du poste du planteur. Les référentiels sont LOCAUX : « Cacao »
              existe une fois par pont bascule, avec son propre prix. Un prélèvement qui viserait le
              cacao d'un autre poste ne se déclencherait sur aucune pesée d'ici.
        */
        if($produit->getSite()?->getId() !== $planteur->getSite()?->getId()) {
            throw new UnprocessableEntityHttpException('Ce produit n\'appartient pas au pont bascule de ce planteur.');
        }

        if(($motif = TauxPrelevement::motifDuRefus($produit, $data->getTauxDemande())) !== null) {
            throw new UnprocessableEntityHttpException($motif);
        }

        if($this->demandes->findEnAttentePour($planteur, $produit) !== null) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Une demande est déjà en attente pour ce planteur sur « %s ». Corrigez-la ou annulez-la avant d\'en déposer une autre.',
                (string) $produit->getLibelle()
            ));
        }

        if(($enCours = $this->prelevements->findActifPour($planteur, $produit)) !== null) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Un prélèvement de %d F par kilo est déjà en cours pour ce planteur sur « %s ». Il doit d\'abord être arrêté.',
                $enCours->getTaux(),
                (string) $produit->getLibelle()
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
