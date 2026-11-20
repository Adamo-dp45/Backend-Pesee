<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Service\GrilleTarifaireService;
use App\Entity\CategorieFournisseur;
use App\Entity\Dto\TarifLotInput;
use App\Entity\Dto\TarifLotOutput;
use App\Entity\Fournisseur;
use App\Security\Perimetre;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
    - L'ENTRÉE DE LA SAISIE GROUPÉE, pour les deux porteurs de grille. Un seul processor : la
      ressource visée se lit sur l'opération elle-même, et le travail est identique des deux côtés.

    - IL CHARGE LE PORTEUR LUI-MÊME, et vérifie son périmètre à la main. Un POST ne lit pas la
      ressource de son URI, si bien que 'is_granted(..., object)' n'a pas d'objet à juger et que
      l'extension de cloisonnement ne s'applique pas à un 'find()'. Sans ce contrôle, un
      administrateur pourrait tarifer la catégorie d'un autre client en devinant un identifiant.

    - La réponse est un BILAN, pas une ressource : c'est ce que l'écran doit annoncer. Un 200 muet
      laisserait croire que tout est passé alors qu'une ligne a pu ne rien changer.
*/

/**
 * @implements ProcessorInterface<TarifLotInput, TarifLotOutput>
 */
final class TarifLotProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private GrilleTarifaireService $grille,
        private Perimetre $perimetre
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TarifLotOutput
    {
        if(!$data instanceof TarifLotInput) {
            throw new NotFoundHttpException();
        }

        $classe = $operation->getClass();
        $porteur = $classe === null ? null : $this->em->getRepository($classe)->find($uriVariables['id'] ?? 0);

        if(!$porteur instanceof CategorieFournisseur && !$porteur instanceof Fournisseur) {
            throw new NotFoundHttpException('Cet élément est introuvable.');
        }

        if($porteur->getDeletedAt() !== null) {
            throw new NotFoundHttpException('Cet élément est à la corbeille : sa grille ne se modifie plus.');
        }

        $entreprise = $porteur instanceof CategorieFournisseur
            ? $porteur->getEntreprise()
            : $porteur->getSite()?->getEntreprise();

        /*
            - Le 404 plutôt qu'un 403 : dire « interdit » révélerait que la ligne existe, et donc
              combien de clients compte la plateforme. C'est la règle déjà tenue ailleurs, et
              'CloisonnementTest' la vérifie.
        */
        if($entreprise === null || $entreprise->getId() !== $this->perimetre->entreprise()?->getId()) {
            throw new NotFoundHttpException('Cet élément est introuvable.');
        }

        return $this->grille->appliquer($porteur, $data);
    }
}
