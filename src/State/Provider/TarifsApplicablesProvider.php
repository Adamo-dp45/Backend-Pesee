<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation as ApiOperation;
use ApiPlatform\State\ProviderInterface;
use App\Domain\Support\TarifApplicable;
use App\Entity\Fournisseur;
use App\Entity\Output\LigneTarifApplicable;
use App\Entity\Output\TarifsApplicablesOutput;
use App\Repository\FournisseurRepository;
use App\Repository\ProduitRepository;
use App\Security\Perimetre;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
    - LA GRILLE VUE DEPUIS UN PLANTEUR : ce qu'il touche sur chaque produit, et lequel des trois
      sauts a répondu. C'est 'TarifApplicable' qui tranche, ici comme à la valorisation — un seul
      endroit où la règle est écrite, et donc un seul à corriger.

    - UNE LIGNE PAR PRODUIT DE L'ENTREPRISE, tarifé ou non. Partir de ses seuls tarifs n'afficherait
      que ce qu'on lui a déjà accordé, et les cases vides — là où il suit sa catégorie ou le prix du
      produit sans qu'on l'ait décidé pour lui — resteraient invisibles.

    - 'prixSansTarifPropre' est le chiffre que l'écran met sous une case vide. Y mettre le prix du
      produit, comme on le faisait, induit en erreur dès qu'une catégorie paie autrement : vider la
      case d'un « Gros livreur » ne le ramène pas à 400 F mais à 450.
*/

/**
 * @implements ProviderInterface<TarifsApplicablesOutput>
 */
final class TarifsApplicablesProvider implements ProviderInterface
{
    public function __construct(
        private readonly FournisseurRepository $fournisseurs,
        private readonly ProduitRepository $produits,
        private readonly Perimetre $perimetre
    )
    {
    }

    public function provide(ApiOperation $operation, array $uriVariables = [], array $context = []): TarifsApplicablesOutput
    {
        $planteur = $this->fournisseurs->find($uriVariables['id'] ?? 0);

        /*
            - Le 404 plutôt qu'un 403 : dire « interdit » révélerait que la ligne existe. On charge
              par 'find()', donc le cloisonnement de l'API ne s'applique pas — il se vérifie ici.
        */
        if(!$planteur instanceof Fournisseur
            || $planteur->getDeletedAt() !== null
            || !$this->perimetre->voitLeSite($planteur->getSite())) {
            throw new NotFoundHttpException('Ce planteur est introuvable.');
        }

        $sortie = new TarifsApplicablesOutput();
        $site = $planteur->getSite();
        $entreprise = $site?->getEntreprise();

        if($entreprise === null) {
            return $sortie;
        }

        foreach($this->produits->findPourLePoste($site) as $produit) {
            $applicable = TarifApplicable::pour($planteur, $produit);

            /*
                - Ce qu'il aurait SANS son tarif propre : on redemande la résolution au saut suivant.
                  Celui de sa catégorie répond s'il existe, sinon le prix du produit — et c'est
                  exactement ce que vider la case produirait.
            */
            $sansLeSien = $planteur->getCategorie()?->tarifPour($produit)?->getPrix() ?? $produit->getPrix();

            $sortie->lignes[] = LigneTarifApplicable::depuis(
                (int) $produit->getId(),
                (string) $produit->getLibelle(),
                $produit->getPrix(),
                (int) ($applicable->prix ?? 0),
                $applicable->origine,
                $sansLeSien
            );
        }

        return $sortie;
    }
}
