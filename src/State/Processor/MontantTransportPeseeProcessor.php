<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\TypeActivite;
use App\Domain\Service\ActiviteLogger;
use App\Entity\Input\MontantTransportInput;
use App\Entity\Operation as Pesee;
use App\Repository\OperationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - ÉCRIT LE MONTANT DU TRANSPORT D'UNE PESÉE, et rien d'autre. Le pendant exact de
      'PrixUnitairePeseeProcessor' sur l'autre part : un détour imprévu, une route coupée, un
      second voyage — des choses qu'aucun barème ne saurait porter sans les donner à tous ceux qui
      le suivent.

    - PLUS SIMPLE QUE LA RÉCOLTE, D'UNE CHOSE : la mise en compte ne mord jamais sur le transport,
      que le planteur a avancé de sa poche. Il n'y a donc pas d'épargne à consulter, et aucun
      refus possible de ce côté-là.

    - LA FERMETURE EST SUR LA PESÉE, pas ici : 'isTransportModifiable()' porte la règle, parce que
      l'écran a besoin de la poser avant d'afficher le formulaire. Ce processor ne fait que la
      vérifier — un écran n'est jamais une garde, il la double.
*/

/**
 * @implements ProcessorInterface<MontantTransportInput, Pesee>
 */
final class MontantTransportPeseeProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private OperationRepository $operations,
        private ActiviteLogger $activites
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Pesee
    {
        $pesee = $this->operations->find($uriVariables['id'] ?? 0);

        if(!$pesee instanceof Pesee) {
            throw new NotFoundHttpException('Cette pesée est introuvable.');
        }

        if(!$pesee->isTransportModifiable()) {
            throw new UnprocessableEntityHttpException($this->pourquoiFermee($pesee));
        }

        $montant = (int) $data->montant;
        $avant = $pesee->getMontanttransport();

        // Le même montant n'est pas une erreur, c'est un non-événement
        if($avant === $montant) {
            return $pesee;
        }

        $pesee->fixerLeTransport($montant);

        $this->activites->log(
            TypeActivite::PESEE_TRANSPORT,
            sprintf(
                'Transport de la pesee %s (%s) : %s -> %s%s',
                (string) $pesee->getNumticket(),
                (string) $pesee->getFournisseur()?->getNomComplet(),
                $this->montant($avant),
                $this->montant($montant),
                $data->motif === null || trim($data->motif) === '' ? '' : ' — ' . trim($data->motif)
            ),
            $pesee
        );

        $this->em->flush();

        return $pesee;
    }

    /* Dire laquelle des conditions a fermé la porte : un « impossible » sans raison renvoie vers le support. */
    private function pourquoiFermee(Pesee $pesee): string
    {
        if($pesee->getMontantpayetransport() > 0 || $pesee->getMontantenvoltransport() > 0) {
            return 'Un versement a déjà été fait sur le transport de cette pesée : son montant ne se change plus.';
        }

        if(!$pesee->estTerminee()) {
            return 'Cette pesée n\'est pas terminée : le camion n\'est pas repassé sur le pont.';
        }

        if($pesee->getFournisseur() === null) {
            return 'Cette pesée n\'a pas de planteur identifié : il n\'y a personne à qui payer un trajet.';
        }

        return 'Le transport de cette pesée ne peut pas être modifié.';
    }

    private function montant(int $valeur): string
    {
        return number_format($valeur, 0, ',', ' ') . ' F';
    }
}
