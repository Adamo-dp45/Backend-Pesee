<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\TypeActivite;
use App\Domain\Service\ActiviteLogger;
use App\Entity\EntityBase;
use App\Entity\Fournisseur;
use App\Entity\Produit;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/*
    - Remplace 'AuditProcessor' sur la modification d'un produit et d'un planteur, pour la seule
      raison qui justifie un processor dédié : retenir le prix AVANT.

    - Un prix n'est pas un champ comme un autre. Il décide de ce que touche un planteur, et le
      changer revalorise toutes les pesées non encore payées du poste. Jusqu'ici il ne restait qu'un
      'updatedBy', écrasé à la modification suivante : impossible de répondre à « qui a mis ce
      produit à 470, et depuis quelle valeur ? ».

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
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $auteur = $this->security->getUser();

        if($data instanceof EntityBase && $auteur instanceof User) {
            $data->setUpdatedBy($auteur->getId());
        }

        $this->journaliserLePrix($data, $auteur);

        return $this->processor->process($data, $operation, $uriVariables, $context);
    }

    private function journaliserLePrix(mixed $data, ?object $auteur): void
    {
        [$champ, $type, $quoi] = match(true) {
            $data instanceof Produit => ['prix', TypeActivite::PRODUIT_TARIFE, 'le prix au kilo de'],
            $data instanceof Fournisseur => ['prixspeciale', TypeActivite::FOURNISSEUR_TARIFE, 'le prix special de'],
            default => [null, null, null],
        };

        if($champ === null) {
            return;
        }

        $origine = $this->em->getUnitOfWork()->getOriginalEntityData($data);

        // Création, ou entité non suivie : il n'y a pas d'« avant » à comparer
        if($origine === [] || !array_key_exists($champ, $origine)) {
            return;
        }

        $avant = $origine[$champ];
        $apres = $data instanceof Produit ? $data->getPrix() : $data->getPrixspeciale();

        if($avant === $apres) {
            return; // Une modification qui ne touche pas au prix n'a rien à faire dans ce journal
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
    }

    private function montant(mixed $valeur): string
    {
        return $valeur === null ? 'aucun prix' : number_format((int) $valeur, 0, ',', ' ') . ' F';
    }
}
