<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/*
    - Le prix d'un produit POUR CE PLANTEUR, et lui seul. C'est le dernier mot : il l'emporte sur le
      tarif de sa catégorie, qui l'emporte lui-même sur le prix du produit.

    - À quoi il sert : un planteur qu'on a négocié à part sans vouloir créer une catégorie pour lui
      tout seul, ou un cas que sa catégorie décrit mal. Il reste l'exception — si l'on en saisit
      trente identiques, c'est une catégorie qui manquait.

    - Ni site ni entreprise, comme 'Prelevement' : cette ligne n'est jamais interrogée en collection
      par l'API, elle ne se lit qu'à travers son planteur, qui est déjà cloisonné.
*/
#[ORM\Entity]
#[ORM\Index(name: 'idx_tarif_fournisseur_produit', fields: ['fournisseur', 'produit'])]
class TarifFournisseur extends TarifProduit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:Fournisseur:item', 'read:Fournisseur:tarifs'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tarifs')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Fournisseur $fournisseur = null; // Jamais sérialisé : on ne lit cette ligne qu'à travers lui

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFournisseur(): ?Fournisseur
    {
        return $this->fournisseur;
    }

    public function setFournisseur(?Fournisseur $fournisseur): static
    {
        $this->fournisseur = $fournisseur;

        return $this;
    }
}
