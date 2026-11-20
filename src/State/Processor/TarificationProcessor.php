<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\PartPesee;
use App\Domain\Enum\TypeActivite;
use App\Domain\Service\ActiviteLogger;
use App\Domain\Service\Epargne\EpargneService;
use App\Entity\BaremeTransport;
use App\Entity\CategorieFournisseur;
use App\Entity\EntityBase;
use App\Entity\Fournisseur;
use App\Entity\Operation as Pesee;
use App\Entity\Produit;
use App\Entity\User;
use App\Repository\OperationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/*
    - Remplace 'AuditProcessor' sur la modification d'un produit et d'un planteur, pour les deux
      raisons qui justifient un processor dédié : retenir la valeur AVANT, et revaloriser APRÈS.

    - Un prix n'est pas un champ comme un autre. Il décide de ce que touche un planteur, et le
      changer doit revaloriser les pesées qui ne sont pas encore payées. Jusqu'ici il ne restait
      qu'un 'updatedBy', écrasé à la modification suivante : impossible de répondre à « qui a mis ce
      produit à 470, et depuis quelle valeur ? ».

    - LE CLASSEMENT D'UN PLANTEUR EST UN PRIX. Le mettre en catégorie « Gros livreurs » change ce
      que vaut sa récolte sur toutes ses pesées non payées, exactement comme une tarification. Ça ne
      se voyait nulle part : c'était un champ parmi d'autres sur sa fiche, qui ne journalisait rien
      et ne revalorisait rien. Un planteur pouvait changer de catégorie sans qu'une seule de ses
      pesées en cours ne suive.

    - DEUX CHANGEMENTS PEUVENT TOMBER DANS LE MÊME PATCH — le prix du transport et la catégorie — et
      ils ne touchent pas la même part. D'où une LISTE de parts et non une seule : n'en traiter
      qu'une laisserait l'autre sans effet, en silence.

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
              chargement ; la revalorisation vient APRÈS, parce qu'elle a besoin des nouvelles
              valeurs écrites et des pesées relues. Les deux partagent la même question — « qu'est-ce
              qui a bougé ? » — posée une seule fois.
        */
        $parts = $this->journaliserLesDecisions($data, $auteur instanceof User ? $auteur : null);
        $resultat = $this->processor->process($data, $operation, $uriVariables, $context);

        if($parts !== [] && ($data instanceof Produit || $data instanceof Fournisseur || $data instanceof CategorieFournisseur)) {
            $this->revaloriser($data, $parts);
        }

        return $resultat;
    }

    /*
        - Recalcule les pesées que ce changement concerne, et ouvre la mise en compte de celles qui
          viennent seulement d'acquérir une valeur. C'est la même passe que l'étape 4 de la
          synchronisation, dans la même forme et pour la même raison : une pesée valorisée et jamais
          arbitrée ne doit pas attendre le prochain envoi du poste pour épargner.

        - Les pesées sont DÉDOUBLONNÉES entre les parts. Changer le transport et la catégorie d'un
          planteur d'un seul geste sélectionne deux fois les mêmes lignes ; 'calculerMontant()' est
          idempotent, mais une pesée comptée deux fois serait aussi arbitrée deux fois, et
          'figerLaRetenue()' lève au second appel.

        - Transaction à part, après celle d'API Platform : ne JAMAIS imbriquer deux
          'wrapInTransaction()', DBAL ne pose pas de savepoint et l'échec interne rend l'externe
          illisible. 'preleverSurLot()' suppose justement la transaction ouverte par l'appelant.

        @param list<PartPesee> $parts
    */
    private function revaloriser(Produit|Fournisseur|CategorieFournisseur $cause, array $parts): void
    {
        /** @var array<int, Pesee> $pesees */
        $pesees = [];

        foreach($parts as $part) {
            foreach($this->operations->findARevaloriser($cause, $part) as $pesee) {
                $pesees[spl_object_id($pesee)] = $pesee;
            }
        }

        $aArbitrer = [];

        foreach($pesees as $pesee) {
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

    /**
     * Journalise ce qui a bougé et rend les parts à revaloriser.
     *
     * @return list<PartPesee>
     */
    private function journaliserLesDecisions(mixed $data, ?User $auteur): array
    {
        $origine = $data instanceof EntityBase
            ? $this->em->getUnitOfWork()->getOriginalEntityData($data)
            : [];

        // Création, ou entité non suivie : il n'y a pas d'« avant » à comparer
        if($origine === []) {
            return [];
        }

        if($data instanceof Produit) {
            return $this->journaliserLePrix($data, $auteur, $origine);
        }

        if($data instanceof Fournisseur) {
            return [...$this->journaliserLeTransport($data, $auteur, $origine), ...$this->journaliserLaCategorie($data, $auteur, $origine)];
        }

        if($data instanceof CategorieFournisseur) {
            return $this->journaliserLeBaremeDeLaCategorie($data, $auteur, $origine);
        }

        return [];
    }

    /**
     * @param array<string, mixed> $origine
     *
     * @return list<PartPesee>
     */
    private function journaliserLePrix(Produit $produit, ?User $auteur, array $origine): array
    {
        if(!array_key_exists('prix', $origine) || $origine['prix'] === $produit->getPrix()) {
            return [];
        }

        /*
            - La date du dernier changement de prix se pose ICI, là où l'on vient de répondre à « le
              prix a-t-il bougé ? ». Ailleurs, il faudrait reposer la question.
        */
        $produit->marquerLePrixModifie();

        $this->activites->log(
            TypeActivite::PRODUIT_TARIFE,
            sprintf(
                '%s a change le prix au kilo de %s : %s -> %s',
                $auteur?->getNomComplet() ?? 'Le systeme',
                (string) $produit,
                $this->montant($origine['prix']),
                $this->montant($produit->getPrix())
            ),
            $produit
        );

        return [PartPesee::RECOLTE];
    }

    /**
     * @param array<string, mixed> $origine
     *
     * @return list<PartPesee>
     */
    private function journaliserLeTransport(Fournisseur $planteur, ?User $auteur, array $origine): array
    {
        /*
            - DEUX CHAMPS DÉCIDENT DU TRANSPORT depuis les barèmes, et non plus un prix au kilo :
              le barème rattaché, et la DISTANCE. Changer l'un ou l'autre change ce que le
              planteur touche — une distance corrigée de 18 à 25 km le fait changer de tranche.
              N'en surveiller qu'un laisserait l'autre sans effet, en silence.
        */
        $baremeAvant = $origine['baremeTransport'] ?? null;
        $baremeAvant = $baremeAvant instanceof BaremeTransport ? $baremeAvant : null;
        $baremeApres = $planteur->getBaremeTransport();

        $bougeLeBareme = array_key_exists('baremeTransport', $origine)
            && $baremeAvant?->getId() !== $baremeApres?->getId();

        $bougeLaDistance = array_key_exists('distanceKm', $origine)
            && $origine['distanceKm'] !== $planteur->getDistanceKm();

        if(!$bougeLeBareme && !$bougeLaDistance) {
            return [];
        }

        $this->activites->log(
            TypeActivite::FOURNISSEUR_TARIFE,
            sprintf(
                '%s a change le transport de %s : bareme %s -> %s, distance %s -> %s',
                $auteur?->getNomComplet() ?? 'Le systeme',
                (string) $planteur,
                $baremeAvant === null ? 'aucun' : (string) $baremeAvant,
                $baremeApres === null ? 'aucun' : (string) $baremeApres,
                $this->kilometres($origine['distanceKm'] ?? null),
                $this->kilometres($planteur->getDistanceKm())
            ),
            $planteur
        );

        return [PartPesee::TRANSPORT];
    }

    private function kilometres(mixed $valeur): string
    {
        return $valeur === null ? 'inconnue' : $valeur . ' km';
    }

    /**
     * @param array<string, mixed> $origine
     *
     * @return list<PartPesee>
     */
    private function journaliserLaCategorie(Fournisseur $planteur, ?User $auteur, array $origine): array
    {
        if(!array_key_exists('categorie', $origine)) {
            return [];
        }

        /*
            - L'instantané rend l'OBJET de la relation, pas son identifiant — et un proxy Doctrine
              plutôt que l'entité elle-même, selon ce qui a été chargé. Comparer par identifiant est
              donc la seule comparaison fiable ici, là où un '===' dirait « changé » sur deux
              représentations du même enregistrement.
        */
        $avant = $origine['categorie'] instanceof CategorieFournisseur ? $origine['categorie'] : null;
        $apres = $planteur->getCategorie();

        if($avant?->getId() === $apres?->getId()) {
            return [];
        }

        $this->activites->log(
            TypeActivite::FOURNISSEUR_CATEGORIE,
            sprintf(
                '%s a classe %s : %s -> %s',
                $auteur?->getNomComplet() ?? 'Le systeme',
                (string) $planteur,
                $avant === null ? 'aucune categorie' : (string) $avant,
                $apres === null ? 'aucune categorie' : (string) $apres
            ),
            $planteur
        );

        return [PartPesee::RECOLTE];
    }

    /**
     * LE BARÈME D'UNE CATÉGORIE DÉCIDE DU TRANSPORT DE TOUS SES PLANTEURS — sauf ceux qui en portent
     * un à eux, qui l'emporte. Le changer sans revaloriser laisserait les pesées en cours à l'ancien
     * montant, et seules les suivantes suivraient.
     *
     * Le libellé et la description, eux, ne revalorisent rien : renommer une catégorie ne change
     * aucun chiffre.
     *
     * @param array<string, mixed> $origine
     *
     * @return list<PartPesee>
     */
    private function journaliserLeBaremeDeLaCategorie(CategorieFournisseur $categorie, ?User $auteur, array $origine): array
    {
        if(!array_key_exists('baremeTransport', $origine)) {
            return [];
        }

        /* L'instantané rend l'OBJET, proxy compris : on compare par identifiant, jamais par '==='. */
        $avant = $origine['baremeTransport'] instanceof BaremeTransport ? $origine['baremeTransport'] : null;
        $apres = $categorie->getBaremeTransport();

        if($avant?->getId() === $apres?->getId()) {
            return [];
        }

        $this->activites->log(
            TypeActivite::BAREME_TRANSPORT,
            sprintf(
                '%s a change le bareme de transport de la categorie %s : %s -> %s',
                $auteur?->getNomComplet() ?? 'Le systeme',
                (string) $categorie,
                $avant === null ? 'aucun' : (string) $avant,
                $apres === null ? 'aucun' : (string) $apres
            ),
            $categorie
        );

        return [PartPesee::TRANSPORT];
    }

    private function montant(mixed $valeur): string
    {
        return $valeur === null ? 'aucun prix' : number_format((int) $valeur, 0, ',', ' ') . ' F';
    }
}
