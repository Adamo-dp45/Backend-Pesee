<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - CE QUE VAUT UN PRODUIT POUR QUELQU'UN : un prix au kilo, et le produit qu'il vise. Deux
      porteurs s'en servent — une catégorie de planteurs ('TarifCategorie') et un planteur en propre
      ('TarifFournisseur') — et ils n'ont strictement rien d'autre à dire. D'où cette classe mère,
      qui n'existe que pour que les deux ne divergent pas : un jour où l'on ajouterait une date de
      prise d'effet, l'oublier d'un côté donnerait deux tarifications qui ne se ressemblent plus.

    - UN PRIX COMPLET, PAS UN ÉCART. « Le cacao vaut 500 F pour cette catégorie » et non « +30 F par
      rapport au prix du produit » : un écart oblige à relire le prix de base pour savoir ce qu'on
      paie, et il se périme en silence le jour où ce prix bouge — personne ne verrait que la
      catégorie vient de gagner trente francs.

    - 'MappedSuperclass' et non une table d'héritage : les deux enfants ont leur propre table, leur
      propre index, et ne sont jamais interrogés ensemble. Rien ne justifierait de les mêler.

    - JAMAIS UNE RESSOURCE API À ELLE SEULE, sur le modèle de 'Prelevement' : une ligne ne se lit
      qu'à travers son porteur, déjà cloisonné, et ne se sérialise que sur une FICHE — une
      collection par ligne de liste ferait une requête par ligne.
*/
#[ORM\MappedSuperclass]
abstract class TarifProduit extends EntityBase
{
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['read:CategorieFournisseur', 'read:CategorieFournisseur:item', 'read:Fournisseur:item', 'read:Fournisseur:tarifs'])]
    protected ?Produit $produit = null;

    /*
        - En francs par kilo, comme 'Produit::$prix'. Strictement positif : un tarif à zéro ne veut
          rien dire ici. Il ne signifierait pas « gratuit » mais « je n'ai pas encore décidé », et il
          vaudrait alors mieux ne pas avoir de ligne du tout — c'est l'absence qui fait retomber sur
          le prix du produit, et c'est ce qu'on veut dans ce cas.
    */
    #[ORM\Column]
    #[Assert\Positive(message: 'Un tarif doit être supérieur à zéro. Pour revenir au prix du produit, retirez la ligne.')]
    #[Groups(['read:CategorieFournisseur', 'read:CategorieFournisseur:item', 'read:Fournisseur:item', 'read:Fournisseur:tarifs'])]
    protected int $prix = 0;

    public function getProduit(): ?Produit
    {
        return $this->produit;
    }

    public function setProduit(?Produit $produit): static
    {
        $this->produit = $produit;

        return $this;
    }

    public function getPrix(): int
    {
        return $this->prix;
    }

    public function setPrix(int $prix): static
    {
        $this->prix = $prix;

        return $this;
    }

    /*
        - Vise-t-il ce produit ? L'IDENTITÉ D'ABORD, l'identifiant ensuite, et l'ordre n'est pas
          décoratif : avant le 'flush()' deux 'getId()' valent 'null' et se croiraient égaux, si
          bien que la PREMIÈRE ligne de la collection répondrait pour toutes les cultures. Le piège
          a déjà coûté une pesée de palme payée au prix de l'hévéa, voir 'prelevementPour()'.
    */
    public function vise(?Produit $produit): bool
    {
        if($produit === null || $this->produit === null) {
            return false;
        }

        $id = $produit->getId();

        return $this->produit === $produit || ($id !== null && $this->produit->getId() === $id);
    }

    public function __toString(): string
    {
        return sprintf('%s F/kg sur %s', $this->prix, (string) $this->produit?->getLibelle());
    }
}
