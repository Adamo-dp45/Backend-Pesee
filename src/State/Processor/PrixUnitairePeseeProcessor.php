<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\TypeActivite;
use App\Domain\Service\ActiviteLogger;
use App\Domain\Service\Epargne\EpargneService;
use App\Entity\Input\PrixUnitaireInput;
use App\Entity\Operation as Pesee;
use App\Repository\OperationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - ÉCRIT LE PRIX AU KILO D'UNE PESÉE, et rien d'autre. C'est la prime : un geste décidé au bureau
      sur une livraison précise, qui ne se range dans aucun référentiel — ni le produit, ni la
      catégorie, ni le tarif propre du planteur ne la porteraient sans la donner à toutes les autres.

    - TROIS CHOSES SUIVENT LE PRIX, et c'est pour les tenir ensemble que ce processor existe :
      le montant dû, qui est l'assiette de tout le reste ; l'état du règlement, qui décide si la
      pesée reparaît dans la file du guichet ; et la mise en compte, qui peut avoir été rabotée par
      un montant dû plus petit.

    - LA FERMETURE EST SUR LA PESÉE, pas ici : 'isPrixModifiable()' porte la règle, parce que l'écran
      a besoin de la poser avant d'afficher le bouton. Ce processor ne fait que la vérifier — un
      écran n'est jamais une garde, il la double.
*/

/**
 * @implements ProcessorInterface<PrixUnitaireInput, Pesee>
 */
final class PrixUnitairePeseeProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private OperationRepository $operations,
        private EpargneService $epargne,
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

        if(!$pesee->isPrixModifiable()) {
            throw new UnprocessableEntityHttpException($this->pourquoiFermee($pesee));
        }

        $prix = (int) $data->prixunitaire;
        $avant = $pesee->getPrixunitaire();

        // Le même prix n'est pas une erreur, c'est un non-événement : rien à écrire, rien à journaliser
        if($avant === $prix) {
            return $pesee;
        }

        $pesee->fixerLePrixUnitaire($prix);

        /*
            - L'ÉPARGNE AVANT LE FLUSH, et ce n'est pas un détail d'ordre : le refus de reprendre une
              part déjà mise en compte est levé là-bas, et il doit l'être AVANT que quoi que ce soit
              ne soit écrit. Un prix rejeté ne laisse donc aucune trace.
        */
        $this->epargne->ajusterApresPrix($pesee);

        $this->activites->log(
            TypeActivite::PESEE_PRIX,
            sprintf(
                'Prix au kilo de la pesee %s (%s) : %s -> %s. Montant du : %s F%s',
                (string) $pesee->getNumticket(),
                (string) $pesee->getProduit()?->getLibelle(),
                $avant === null ? 'aucun prix' : $this->montant($avant),
                $this->montant($prix),
                number_format((int) $pesee->getMontantdu(), 0, ',', ' '),
                $data->motif === null || trim($data->motif) === '' ? '' : ' — ' . trim($data->motif)
            ),
            $pesee
        );

        $this->em->flush();

        return $pesee;
    }

    /*
        - DIRE LAQUELLE DES CONDITIONS A FERMÉ LA PORTE. Un « impossible » sans raison renvoie
          l'utilisateur vers le support, et la raison est toujours connue ici.
    */
    private function pourquoiFermee(Pesee $pesee): string
    {
        if($pesee->getMontantpayeproduit() > 0 || $pesee->getMontantenvolproduit() > 0) {
            return 'Un versement a déjà été fait sur la récolte de cette pesée : son prix ne se change plus.';
        }

        if(!$pesee->estTerminee()) {
            return 'Cette pesée n\'est pas terminée : le camion n\'est pas repassé sur le pont.';
        }

        if(($pesee->getPoidsnet() ?? 0) <= 0) {
            return 'Cette pesée n\'a pas de poids net : un prix au kilo ne donnerait rien.';
        }

        if($pesee->getProduit() === null || $pesee->getFournisseur() === null) {
            return 'Cette pesée n\'a ni produit ni planteur identifié : elle ne se tarife pas.';
        }

        return 'Le prix de cette pesée ne peut pas être modifié.';
    }

    private function montant(int $valeur): string
    {
        return number_format($valeur, 0, ',', ' ') . ' F';
    }
}
