<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\PartPesee;
use App\Domain\Enum\TypeActivite;
use App\Domain\Service\ActiviteLogger;
use App\Entity\BaremeTransport;
use App\Entity\User;
use App\Repository\OperationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/*
    - UN BARÈME MODIFIÉ REVALORISE LE TRANSPORT de toutes les pesées qui le suivent — celles des
      planteurs qui le portent, et celles des planteurs d'une catégorie qui le porte. Sans cette
      passe, corriger un forfait de 5 000 à 6 000 F ne toucherait que les pesées à venir, et les
      pesées en cours resteraient à l'ancien montant sans que rien ne l'indique.

    - PAS D'APERÇU AVANT ÉCRITURE, contrairement à la grille de tarifs, et la différence est réelle :
      le transport n'entre pas dans 'montantdu'. Il n'ouvre donc aucune mise en compte et ne fige
      aucune retenue — rien d'irréversible ne se déclenche. Une erreur de barème se corrige en
      ressaisissant, ce qui n'est pas vrai d'un tarif.

    - LA PART EST LE TRANSPORT, et le garde-fou porte sur elle seule : une pesée dont la RÉCOLTE est
      déjà payée garde un transport revalorisable, et c'est le cas courant depuis qu'on règle les
      deux séparément.
*/

/**
 * @implements ProcessorInterface<BaremeTransport, BaremeTransport>
 */
final class BaremeTransportProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private OperationRepository $operations,
        private ActiviteLogger $activites,
        private Security $security,
        // Retient l'auteur puis persiste : on encadre son travail, on ne le remplace pas
        private AuditProcessor $audit
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $resultat = $this->audit->process($data, $operation, $uriVariables, $context);

        if(!$data instanceof BaremeTransport) {
            return $resultat;
        }

        $this->journaliser($data);
        $this->revaloriser($data);

        return $resultat;
    }

    private function journaliser(BaremeTransport $bareme): void
    {
        $auteur = $this->security->getUser();

        $this->activites->log(
            TypeActivite::BAREME_TRANSPORT,
            sprintf(
                '%s a revu le bareme de transport %s (%s)',
                $auteur instanceof User ? $auteur->getNomComplet() : 'Le systeme',
                (string) $bareme,
                (string) $bareme->getMode()?->libelle()
            ),
            $bareme
        );
    }

    /*
        - Même forme que les autres passes de revalorisation : recalculer, puis ouvrir la mise en
          compte de ce qui vient d'acquérir une valeur. Elle ne devrait rien ouvrir ici — le
          transport ne compte pas dans 'montantdu' — mais on passe par le même chemin pour que
          toutes les revalorisations se ressemblent, et qu'aucune ne devienne un cas à part.

        - Transaction à part, après celle d'API Platform : ne JAMAIS imbriquer deux
          'wrapInTransaction()', DBAL ne pose pas de savepoint.
    */
    private function revaloriser(BaremeTransport $bareme): void
    {
        $touchees = 0;

        foreach($this->operations->findARevaloriser($bareme, PartPesee::TRANSPORT) as $pesee) {
            if($pesee->calculerMontant()) {
                ++$touchees;
            }
        }

        if($touchees === 0) {
            return;
        }

        $this->em->wrapInTransaction(function(): void {
            $this->em->flush();
        });
    }
}
