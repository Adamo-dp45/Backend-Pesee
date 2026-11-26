<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\ModeReglement;
use App\Domain\Service\Caisse\PaiementService;
use App\Entity\Input\PaiementLotInput;
use App\Entity\Operation as Pesee;
use App\Entity\Output\PaiementLotOutput;
use App\Entity\User;
use App\Repository\OperationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
    - LE VERSEMENT GROUPÉ : plusieurs pesées d'un même planteur, réglées en un geste. La règle vit
      dans 'PaiementService::initierLot()', qui seul sait borner, débiter et confirmer ; ce
      processor ne fait que traduire des identifiants en pesées, et vérifier qu'on a le droit de
      les voir.

    - LE PÉRIMÈTRE SE CONTRÔLE ICI, pesée par pesée, et pas seulement par le voter : celui-ci ne
      voit que le type 'Paiement', pas les pesées visées, qui arrivent par identifiant dans le
      corps de la requête. Sans ce contrôle, une liste d'identifiants devinés permettrait de payer
      sur la caisse d'un voisin.

    - 404 ET NON 403 sur une pesée qu'on n'a pas le droit de voir : on ne révèle pas qu'elle existe
      ailleurs. Et le message ne nomme pas laquelle — l'énumérer reviendrait au même.

    - LES DOUBLONS SONT ÉCARTÉS. Deux fois le même identifiant paierait deux fois la même pesée :
      la seconde serait bornée à zéro et écartée par le service, mais compter sur ce rattrapage
      serait compter sur un effet de bord.
*/

/**
 * @implements ProcessorInterface<PaiementLotInput, PaiementLotOutput>
 */
final class PaiementLotProcessor implements ProcessorInterface
{
    public function __construct(
        private PaiementService $paiements,
        private OperationRepository $operations,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PaiementLotOutput
    {
        $auteur = $this->security->getUser();

        if(!$auteur instanceof User) {
            throw new AccessDeniedHttpException('Authentification requise.');
        }

        $pesees = [];

        foreach(array_unique($data->operationIds) as $id) {
            $pesee = $this->operations->find($id);

            if(!$pesee instanceof Pesee || !$this->security->isGranted('VOIR', $pesee)) {
                throw new NotFoundHttpException('Une des pesées choisies est introuvable.');
            }

            $pesees[] = $pesee;
        }

        $paiements = $this->paiements->initierLot(
            $pesees,
            $data->part,
            $data->modeReglement ?? ModeReglement::ESPECES, // La validation l'exige déjà ; ce repli n'est là que pour le typage
            $auteur,
            $data->numeroCheque
        );

        /*
            - LES TOTAUX SE RECOMPOSENT ICI, à partir des versements écrits, et non de ce qui avait
              été demandé : une pesée réglée à l'autre guichet pendant que l'opérateur cochait est
              écartée en silence par le service, et l'écran doit annoncer ce qui est VRAIMENT sorti.
        */
        $sortie = new PaiementLotOutput();

        foreach($paiements as $paiement) {
            ++$sortie->nombre;
            $sortie->montant += $paiement->getMontant();
            $sortie->montantProduit += $paiement->getMontantproduit();
            $sortie->montantTransport += $paiement->getMontanttransport();
            $sortie->references[] = (string) $paiement->getReference();
            /* C'est à ces lignes-là que l'écran accrochera la décharge signée pour le lot entier */
            $sortie->ids[] = (int) $paiement->getId();
        }

        return $sortie;
    }
}
