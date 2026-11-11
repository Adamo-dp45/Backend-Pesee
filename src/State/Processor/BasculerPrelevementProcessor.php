<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\TypeActivite;
use App\Domain\Service\ActiviteLogger;
use App\Entity\Fournisseur;
use App\Entity\Input\PrelevementInput;
use App\Entity\User;
use App\Repository\FournisseurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - L'ouverture et l'arrêt directs par l'administrateur, sans passer par une demande — comme il
      peut doter une caisse sans que personne l'ait demandé. La demande reste le chemin normal,
      celui qui garde trace de qui a demandé quoi.

    - Un taux nul ou absent ferme le robinet. Ce qui est DÉJÀ mis de côté ne bouge pas : l'épargne
      appartient au planteur, l'arrêt ne fait que cesser d'y ajouter.

    - Journalisé dans les deux sens. Rien n'écrit au grand livre des caisses ici — aucun billet ne
      bouge — donc sans cette trace, décider qu'un planteur touchera moins au guichet ne laisserait
      aucune empreinte.
*/

/**
 * @implements ProcessorInterface<PrelevementInput, Fournisseur>
 */
class BasculerPrelevementProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private FournisseurRepository $fournisseurs,
        private ActiviteLogger $activites,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Fournisseur
    {
        $planteur = $this->fournisseurs->find($uriVariables['id'] ?? 0);

        if(!$planteur instanceof Fournisseur) {
            throw new NotFoundHttpException('Ce planteur est introuvable.');
        }

        if($planteur->getDeletedAt() !== null) {
            throw new UnprocessableEntityHttpException('Ce planteur est à la corbeille : son épargne ne se modifie pas.');
        }

        $taux = ($data->taux ?? 0) > 0 ? (int) $data->taux : null;
        $auteur = $this->security->getUser();
        $nomAuteur = $auteur instanceof User ? $auteur->getNomComplet() : 'Le système';

        $planteur->setTauxEpargne($taux);

        // Avant le flush : la trace part dans la même transaction que la décision
        $this->activites->log(
            $taux === null ? TypeActivite::FOURNISSEUR_PRELEVEMENT_ARRET : TypeActivite::FOURNISSEUR_PRELEVEMENT,
            $taux === null
                ? sprintf('%s a arrêté la mise en compte de %s', $nomAuteur, $planteur->getNomComplet())
                : sprintf('%s a fixé la mise en compte de %s à %d F par kilo', $nomAuteur, $planteur->getNomComplet(), $taux),
            $planteur
        );

        if($auteur instanceof User) {
            $planteur->setUpdatedBy($auteur->getId());
        }

        $this->em->flush();

        return $planteur;
    }
}
