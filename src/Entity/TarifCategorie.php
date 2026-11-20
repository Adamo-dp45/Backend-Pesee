<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/*
    - Le prix d'un produit POUR UNE CATÉGORIE de planteurs. Il l'emporte sur 'Produit::$prix', et
      cède devant un tarif propre au planteur.

    - La ligne se SUPPRIME logiquement plutôt que de disparaître. Son absence fait retomber la
      valorisation sur le prix du produit : savoir qui a retiré le tarif hévéa de la catégorie B, et
      quand, est exactement la question qu'on posera le jour où un planteur verra sa paie baisser.
*/
#[ORM\Entity]
#[ORM\Index(name: 'idx_tarif_categorie_produit', fields: ['categorie', 'produit'])]
class TarifCategorie extends TarifProduit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:CategorieFournisseur', 'read:CategorieFournisseur:item'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tarifs')]
    #[ORM\JoinColumn(nullable: false)]
    private ?CategorieFournisseur $categorie = null; // Jamais sérialisé : on ne lit cette ligne qu'à travers elle

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategorie(): ?CategorieFournisseur
    {
        return $this->categorie;
    }

    public function setCategorie(?CategorieFournisseur $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }
}
