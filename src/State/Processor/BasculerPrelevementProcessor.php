<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\TypeActivite;
use App\Domain\Service\ActiviteLogger;
use App\Domain\Support\TauxPrelevement;
use App\Entity\Fournisseur;
use App\Entity\Input\PrelevementInput;
use App\Entity\Prelevement;
use App\Entity\User;
use App\Repository\FournisseurRepository;
use App\Repository\PrelevementRepository;
use App\Repository\ProduitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - L'ouverture et l'arrêt directs par l'administrateur, sans passer par une demande — comme il
      peut doter une caisse sans que personne l'ait demandé. La demande reste le chemin normal,
      celui qui garde trace de qui a demandé quoi.

    - UN PRODUIT À LA FOIS. Un planteur peut mettre de côté sur plusieurs cultures, à des taux
      différents : 20 F par kilo de cacao, 50 F par kilo d'hévéa. Chacune s'ouvre et s'arrête de son
      côté, et le produit est donc obligatoire dans les deux sens.

    - Un taux nul ou absent ferme le robinet de CE produit. Ce qui est déjà mis de côté ne bouge
      pas : l'épargne appartient au planteur, l'arrêt ne fait que cesser d'y ajouter. Et l'arrêt ne
      supprime pas la ligne, il la date — rouvrir crée un nouveau prélèvement, avec sa propre date.

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
        private ProduitRepository $produits,
        private PrelevementRepository $prelevements,
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

        $produit = $this->produits->find($data->produit ?? 0);

        if($produit === null || $produit->getDeletedAt() !== null) {
            throw new UnprocessableEntityHttpException('Ce produit est introuvable.');
        }

        // Les référentiels sont LOCAUX : « Cacao » existe une fois par pont bascule, avec son prix. Un prélèvement sur le cacao d'un autre poste ne se déclencherait sur aucune pesée d'ici
        if($produit->getSite()?->getId() !== $planteur->getSite()?->getId()) {
            throw new UnprocessableEntityHttpException('Ce produit n\'appartient pas au pont bascule de ce planteur.');
        }

        $taux = ($data->taux ?? 0) > 0 ? (int) $data->taux : null;
        $auteur = $this->security->getUser();
        $auteur = $auteur instanceof User ? $auteur : null;
        $nomAuteur = $auteur?->getNomComplet() ?? 'Le système';
        $enCours = $this->prelevements->findActifPour($planteur, $produit);

        if($taux === null) {
            if($enCours === null) {
                throw new UnprocessableEntityHttpException(sprintf(
                    'Aucun prélèvement n\'est en cours pour ce planteur sur « %s ».',
                    (string) $produit->getLibelle()
                ));
            }

            $enCours->arreter($auteur)->setUpdatedBy($auteur?->getId());
        }
        else {
            if(($motif = TauxPrelevement::motifDuRefus($produit, $taux)) !== null) {
                throw new UnprocessableEntityHttpException($motif);
            }

            /*
                - Changer un taux, c'est arrêter l'ancien et en ouvrir un nouveau. Réécrire le taux
                  sur place effacerait l'histoire : la fiche prétendrait qu'il a toujours épargné à
                  50 F alors que les pesées de mars portent 20 F figés, et plus rien n'expliquerait
                  l'écart devant un planteur qui compte.
            */
            $enCours?->arreter($auteur);

            $planteur->ajouterPrelevement(
                (new Prelevement())
                    ->setProduit($produit)
                    ->setTaux($taux)
                    ->setCreatedBy($auteur?->getId())
            );
        }

        // Avant le flush : la trace part dans la même transaction que la décision
        $this->activites->log(
            $taux === null ? TypeActivite::FOURNISSEUR_PRELEVEMENT_ARRET : TypeActivite::FOURNISSEUR_PRELEVEMENT,
            $taux === null
                ? sprintf('%s a arrêté la mise en compte de %s sur %s', $nomAuteur, $planteur->getNomComplet(), (string) $produit->getLibelle())
                : sprintf('%s a fixé la mise en compte de %s sur %s à %d F par kilo', $nomAuteur, $planteur->getNomComplet(), (string) $produit->getLibelle(), $taux),
            $planteur
        );

        $planteur->setUpdatedBy($auteur?->getId());

        $this->em->flush();

        return $planteur;
    }
}
