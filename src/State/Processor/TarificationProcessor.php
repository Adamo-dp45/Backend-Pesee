<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\TypeActivite;
use App\Domain\Service\ActiviteLogger;
use App\Domain\Service\Epargne\EpargneService;
use App\Entity\EntityBase;
use App\Entity\Fournisseur;
use App\Entity\Produit;
use App\Entity\User;
use App\Repository\OperationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/*
    - Remplace 'AuditProcessor' sur la modification d'un produit et d'un planteur, pour les deux
      raisons qui justifient un processor dédié : retenir le prix AVANT, et revaloriser APRÈS.

    - Un prix n'est pas un champ comme un autre. Il décide de ce que touche un planteur, et le
      changer doit revaloriser les pesées de ce produit ou de ce planteur qui ne sont pas encore
      payées. Jusqu'ici il ne restait qu'un 'updatedBy', écrasé à la modification suivante :
      impossible de répondre à « qui a mis ce produit à 470, et depuis quelle valeur ? ».

    - La revalorisation, elle, manquait tout court : seul un prix venu du POSTE déclenchait un
      recalcul. Une pesée de produit créé à la volée par le peseur restait donc à zéro même après
      qu'on l'eut tarifée depuis le bureau, et la mise en compte de son planteur ne démarrait
      jamais dessus. C'est l'écran qu'on ouvre PRÉCISÉMENT pour réparer ce cas : il doit réparer.

    - L'ancienne valeur vient de l'instantané que Doctrine a pris au CHARGEMENT
      ('getOriginalEntityData'). Elle est encore intacte ici : API Platform a désérialisé la requête
      dans l'entité, mais le calcul des changements n'a pas eu lieu — il attend le 'flush()'.

    - Le reste de la modification n'est pas tracé : le libellé ou le numéro de téléphone se lisent
      sur la fiche, et journaliser chaque frappe noierait les décisions qui comptent.
*/

/**
 * @implements ProcessorInterface<EntityBase, EntityBase>
 */
final class TarificationProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<mixed, mixed> $processor Persistance native d'API Platform
     */
    public function __construct(
        private ProcessorInterface $processor,
        private EntityManagerInterface $em,
        private ActiviteLogger $activites,
        private Security $security,
        private OperationRepository $operations,
        private EpargneService $epargne
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $auteur = $this->security->getUser();

        if($data instanceof EntityBase && $auteur instanceof User) {
            $data->setUpdatedBy($auteur->getId());
        }

        /*
            - Le journal se prépare AVANT la persistance, parce qu'il a besoin de l'instantané du
              chargement ; la revalorisation vient APRÈS, parce qu'elle a besoin du nouveau prix
              écrit et des pesées relues. Les deux partagent la même question : « le prix a-t-il
              bougé ? », posée une seule fois.
        */
        $aBouge = $this->journaliserLePrix($data, $auteur);
        $resultat = $this->processor->process($data, $operation, $uriVariables, $context);

        if($aBouge && ($data instanceof Produit || $data instanceof Fournisseur)) {
            $this->revaloriser($data);
        }

        return $resultat;
    }

    /*
        - Recalcule les pesées que ce tarif concerne, et ouvre la mise en compte de celles qui
          viennent seulement d'acquérir une valeur. C'est la même passe que l'étape 4 de la
          synchronisation, dans la même forme et pour la même raison : une pesée valorisée et jamais
          arbitrée ne doit pas attendre le prochain envoi du poste pour épargner.

        - Transaction à part, après celle d'API Platform : ne JAMAIS imbriquer deux
          'wrapInTransaction()', DBAL ne pose pas de savepoint et l'échec interne rend l'externe
          illisible. 'preleverSurLot()' suppose justement la transaction ouverte par l'appelant.
    */
    private function revaloriser(Produit|Fournisseur $cause): void
    {
        $aArbitrer = [];

        foreach($this->operations->findARevaloriser($cause) as $pesee) {
            $pesee->calculerMontant();

            if($pesee->attendUnArbitrage()) {
                $aArbitrer[] = $pesee;
            }
        }

        $this->em->wrapInTransaction(function() use ($aArbitrer): void {
            $this->em->flush();

            $this->epargne->preleverSurLot($aArbitrer);

            $this->em->flush();
        });
    }

    /** @return bool vrai si le prix a réellement changé */
    private function journaliserLePrix(mixed $data, ?object $auteur): bool
    {
        [$champ, $type, $quoi] = match(true) {
            $data instanceof Produit => ['prix', TypeActivite::PRODUIT_TARIFE, 'le prix au kilo de'],
            $data instanceof Fournisseur => ['prixtransport', TypeActivite::FOURNISSEUR_TARIFE, 'le prix du transport de'],
            default => [null, null, null],
        };

        if($champ === null) {
            return false;
        }

        $origine = $this->em->getUnitOfWork()->getOriginalEntityData($data);

        // Création, ou entité non suivie : il n'y a pas d'« avant » à comparer
        if($origine === [] || !array_key_exists($champ, $origine)) {
            return false;
        }

        $avant = $origine[$champ];
        $apres = $data instanceof Produit ? $data->getPrix() : $data->getPrixtransport();

        if($avant === $apres) {
            return false; // Une modification qui ne touche pas au prix n'a rien à faire dans ce journal, ni rien à revaloriser
        }

        $this->activites->log(
            $type,
            sprintf(
                '%s a change %s %s : %s -> %s',
                $auteur instanceof User ? $auteur->getNomComplet() : 'Le systeme',
                $quoi,
                (string) $data,
                $this->montant($avant),
                $this->montant($apres)
            ),
            $data
        );

        return true;
    }

    private function montant(mixed $valeur): string
    {
        return $valeur === null ? 'aucun prix' : number_format((int) $valeur, 0, ',', ' ') . ' F';
    }
}
