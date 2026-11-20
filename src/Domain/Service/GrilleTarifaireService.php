<?php

namespace App\Domain\Service;

use App\Domain\Enum\PartPesee;
use App\Domain\Enum\TypeActivite;
use App\Domain\Service\Epargne\EpargneService;
use App\Entity\CategorieFournisseur;
use App\Entity\Dto\TarifLotInput;
use App\Entity\Dto\TarifLotOutput;
use App\Entity\Entreprise;
use App\Entity\Fournisseur;
use App\Entity\Operation as Pesee;
use App\Entity\Produit;
use App\Entity\TarifCategorie;
use App\Entity\TarifFournisseur;
use App\Entity\TarifProduit;
use App\Entity\User;
use App\Repository\OperationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - LA GRILLE SE SAISIT D'UN BLOC, et ce service est le seul endroit où elle s'écrit — pour une
      catégorie de planteurs comme pour un planteur en propre. Les deux portent exactement la même
      chose : un prix par produit. Deux chemins d'écriture auraient fini par diverger sur ce qui
      compte le plus ici, la revalorisation.

    - DEUX MODES, UN SEUL CALCUL. Sans confirmation, on rend le bilan et le nombre de pesées
      touchées sans rien écrire ; avec, on applique. C'est le même code qui répond aux deux, sans
      quoi l'aperçu finirait par mentir sur ce que l'écriture fait vraiment.

    - POURQUOI CET APERÇU EXISTE. Écrire une case revalorise des pesées, et une pesée revalorisée
      qui acquiert une valeur est ARBITRÉE : sa retenue d'épargne est figée, et 'figerLaRetenue()'
      lève au second appel. Il n'y a aucun chemin d'annulation. Un tarif saisi de travers sur une
      catégorie de deux cents planteurs fige définitivement des milliers de retenues, et personne ne
      l'aurait vu venir.

    - UN RENVOI À L'IDENTIQUE NE DÉCLENCHE RIEN, et ce n'est pas du confort. L'écran renvoie la
      grille entière, cases inchangées comprises ; les compter comme des modifications relancerait
      la revalorisation de toutes les pesées de la catégorie à chaque enregistrement, pour rien — et
      chaque passe peut arbitrer.

    - TOUT OU RIEN. Une grille à moitié écrite parce que la huitième ligne est invalide serait pire
      que pas de grille : personne ne saurait où reprendre. Les refus tombent donc AVANT la première
      écriture.
*/
final class GrilleTarifaireService
{
    public function __construct(
        private EntityManagerInterface $em,
        private OperationRepository $operations,
        private EpargneService $epargne,
        private ActiviteLogger $activites,
        private Security $security
    )
    {
    }

    public function appliquer(CategorieFournisseur|Fournisseur $porteur, TarifLotInput $lot): TarifLotOutput
    {
        $entreprise = $porteur instanceof CategorieFournisseur
            ? $porteur->getEntreprise()
            : $porteur->getSite()?->getEntreprise();

        $changements = $this->preparer($porteur, $lot, $entreprise);

        $peseesConcernees = 0;

        foreach($changements['produits'] as $produit) {
            $peseesConcernees += count($this->operations->findARevaloriser($porteur, PartPesee::RECOLTE, $produit));
        }

        if(!$lot->confirmer) {
            return new TarifLotOutput(
                $changements['creees'],
                $changements['modifiees'],
                $changements['inchangees'],
                $changements['retirees'],
                $peseesConcernees,
                false
            );
        }

        $this->ecrire($porteur, $changements['gestes']);
        $this->journaliser($porteur, $changements);
        $this->revaloriser($porteur, $changements['produits']);

        return new TarifLotOutput(
            $changements['creees'],
            $changements['modifiees'],
            $changements['inchangees'],
            $changements['retirees'],
            $peseesConcernees,
            true
        );
    }

    /*
        - Tout ce qui peut refuser est ici, AVANT la moindre écriture, et le bilan se construit dans
          la même passe : les deux posent la même question à chaque ligne.

        @return array{creees: int, modifiees: int, inchangees: int, retirees: int, produits: list<Produit>, gestes: list<array{0: string, 1: Produit, 2: int|null, 3: TarifProduit|null}>}
    */
    private function preparer(CategorieFournisseur|Fournisseur $porteur, TarifLotInput $lot, ?Entreprise $entreprise): array
    {
        $creees = 0;
        $modifiees = 0;
        $inchangees = 0;
        $retirees = 0;

        /** @var list<Produit> $produits */
        $produits = [];
        $gestes = [];
        $vus = [];

        foreach($lot->lignes as $index => $ligne) {
            $produit = $ligne->produit;

            if($produit === null) {
                throw new UnprocessableEntityHttpException(sprintf('Ligne %d : aucun produit.', $index + 1));
            }

            /*
                - Un produit d'un autre client n'a rien à faire dans cette grille. On résout par IRI,
                  donc le périmètre de l'API l'écarte déjà ; ce contrôle tient quand même le cas où
                  l'entité arrive d'ailleurs, et il nomme la raison au lieu d'un « introuvable ».
            */
            if($entreprise === null || $produit->getEntreprise()?->getId() !== $entreprise->getId()) {
                throw new UnprocessableEntityHttpException(sprintf(
                    'Le produit « %s » n\'appartient pas à cette entreprise.',
                    (string) $produit->getLibelle()
                ));
            }

            /*
                - LE MÊME PRODUIT DEUX FOIS : la seconde case écraserait la première sans qu'on sache
                  laquelle a gagné. On refuse avant d'écrire quoi que ce soit.
            */
            $id = (int) $produit->getId();

            if(isset($vus[$id])) {
                throw new UnprocessableEntityHttpException(sprintf(
                    'Le produit « %s » apparaît deux fois dans cette grille.',
                    (string) $produit->getLibelle()
                ));
            }

            $vus[$id] = true;

            $existant = $porteur->tarifPour($produit);
            $voulu = $ligne->prix;

            if($voulu === null) {
                if($existant === null) {
                    ++$inchangees; // Case vide qui le reste : rien à faire, et il faut le dire

                    continue;
                }

                ++$retirees;
                $produits[] = $produit;
                $gestes[] = ['retirer', $produit, null, $existant];

                continue;
            }

            if($existant === null) {
                ++$creees;
                $produits[] = $produit;
                $gestes[] = ['creer', $produit, $voulu, null];

                continue;
            }

            if($existant->getPrix() === $voulu) {
                ++$inchangees;

                continue;
            }

            ++$modifiees;
            $produits[] = $produit;
            $gestes[] = ['modifier', $produit, $voulu, $existant];
        }

        return [
            'creees' => $creees,
            'modifiees' => $modifiees,
            'inchangees' => $inchangees,
            'retirees' => $retirees,
            'produits' => $produits,
            'gestes' => $gestes,
        ];
    }

    /** @param list<array{0: string, 1: Produit, 2: int|null, 3: TarifProduit|null}> $gestes */
    private function ecrire(CategorieFournisseur|Fournisseur $porteur, array $gestes): void
    {
        $auteur = $this->security->getUser();
        $auteurId = $auteur instanceof User ? $auteur->getId() : null;

        foreach($gestes as [$geste, $produit, $prix, $existant]) {
            if($geste === 'retirer') {
                /*
                    - On DATE la suppression, on n'efface pas. La ligne disparaît de la résolution,
                      mais « qui a retiré le tarif hévéa de la catégorie B, et quand » reste lisible
                      — c'est exactement la question qu'on posera le jour où un planteur verra sa
                      paie baisser sans explication.
                */
                $existant->setDeletedAt(new \DateTimeImmutable());
                $existant->setDeletedBy($auteurId);

                continue;
            }

            if($geste === 'modifier') {
                $existant->setPrix((int) $prix);
                $existant->setUpdatedBy($auteurId);

                continue;
            }

            $tarif = $porteur instanceof CategorieFournisseur ? new TarifCategorie() : new TarifFournisseur();
            $tarif->setProduit($produit)->setPrix((int) $prix);
            $tarif->setCreatedBy($auteurId);

            $porteur->addTarif($tarif);
            $this->em->persist($tarif);
        }

        $this->em->flush();
    }

    /** @param array{creees: int, modifiees: int, retirees: int, produits: list<Produit>} $changements */
    private function journaliser(CategorieFournisseur|Fournisseur $porteur, array $changements): void
    {
        if($changements['produits'] === []) {
            return;
        }

        $auteur = $this->security->getUser();

        $this->activites->log(
            $porteur instanceof CategorieFournisseur ? TypeActivite::TARIF_CATEGORIE : TypeActivite::TARIF_FOURNISSEUR,
            sprintf(
                '%s a revu la grille de %s : %d cree(s), %d modifie(s), %d retire(s) sur %s',
                $auteur instanceof User ? $auteur->getNomComplet() : 'Le systeme',
                (string) $porteur,
                $changements['creees'],
                $changements['modifiees'],
                $changements['retirees'],
                implode(', ', array_map(static fn(Produit $p) => (string) $p->getLibelle(), $changements['produits']))
            ),
            $porteur
        );
    }

    /*
        - Même passe que 'TarificationProcessor' et que l'étape 4 de la synchronisation : recalculer,
          puis ouvrir la mise en compte de ce qui vient d'acquérir une valeur.

        - RESSERRÉE PAR PRODUIT. Sans le produit, on revaloriserait toutes les pesées de tous les
          planteurs de la catégorie, toutes cultures confondues — des milliers de recalculs sans
          objet, dont chacun peut arbitrer une épargne.

        @param list<Produit> $produits
    */
    private function revaloriser(CategorieFournisseur|Fournisseur $porteur, array $produits): void
    {
        /** @var array<int, Pesee> $pesees */
        $pesees = [];

        foreach($produits as $produit) {
            foreach($this->operations->findARevaloriser($porteur, PartPesee::RECOLTE, $produit) as $pesee) {
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
}
