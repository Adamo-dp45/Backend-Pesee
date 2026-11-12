<?php

namespace App\Entity;

use App\Repository\PrelevementRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/*
    - Un prélèvement ouvert : « ce planteur met 20 F par kilo de côté SUR LE CACAO ». Un planteur en
      a autant qu'il a de cultures concernées — rien n'oblige à épargner au même rythme sur l'hévéa
      et sur le cacao, et le patron veut pouvoir les régler séparément.

    - LE TAUX EST STRICTEMENT INFÉRIEUR AU PRIX DU PRODUIT. Au-dessus, le planteur repartirait les
      mains vides de chaque livraison ; égal, il repartirait avec exactement zéro. La règle se
      vérifie au dépôt de la demande, à sa correction ET à son approbation — le prix du produit a pu
      bouger entre-temps, et c'est le prix du jour qui compte. Voir 'Taux::verifier()'.

    - Pas de solde ici : le compte d'épargne reste UN par planteur. C'est de l'argent qu'on lui doit,
      pas une récolte ; le découper par produit n'aurait aucun sens au moment de le lui rendre.

    - Ni site ni entreprise, contrairement au livre d'épargne : cette ligne n'est jamais interrogée
      en collection par l'API, elle ne se lit qu'à travers son planteur, qui est déjà cloisonné.

    - L'arrêt ne supprime pas la ligne, il la DATE. Savoir qu'un planteur a épargné sur le cacao de
      mars à juin est précisément ce qu'on cherche le jour où il conteste son solde.
*/
#[ORM\Entity(repositoryClass: PrelevementRepository::class)]
#[ORM\Index(name: 'idx_prelevement_fournisseur_produit', fields: ['fournisseur', 'produit'])]
class Prelevement extends EntityBase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:Fournisseur:item'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'prelevements')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Fournisseur $fournisseur = null; // Jamais sérialisé : on ne lit cette ligne qu'à travers lui

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['read:Fournisseur:item'])]
    private ?Produit $produit = null;

    /* En francs par kilo. Figé sur chaque pesée au moment de l'arbitrage ('Operation::tauxRetenue') : changer ce taux ne réécrit aucune retenue déjà passée. */
    #[ORM\Column]
    #[Groups(['read:Fournisseur:item'])]
    private int $taux = 0;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:Fournisseur:item'])]
    private ?\DateTimeImmutable $arreteAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $arretePar = null;

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

    public function getProduit(): ?Produit
    {
        return $this->produit;
    }

    public function setProduit(?Produit $produit): static
    {
        $this->produit = $produit;

        return $this;
    }

    public function getTaux(): int
    {
        return $this->taux;
    }

    public function setTaux(int $taux): static
    {
        $this->taux = $taux;

        return $this;
    }

    public function getArreteAt(): ?\DateTimeImmutable
    {
        return $this->arreteAt;
    }

    public function getArretePar(): ?User
    {
        return $this->arretePar;
    }

    /* Un prélèvement arrêté ne prélève plus rien, mais reste lisible sur la fiche du planteur. */
    public function estActif(): bool
    {
        return $this->arreteAt === null && $this->deletedAt === null;
    }

    /*
        - Le même prédicat, sous le nom que le sérialiseur sait lire. Les groupes posés sur une
          méthode sans préfixe 'get' / 'is' / 'has' sont IGNORÉS, sans la moindre erreur : 'estActif'
          serait simplement absent du JSON, et l'écran afficherait tous les prélèvements comme
          arrêtés. Le piège est déjà documenté pour 'getMotifNonPayable()'.
    */
    #[Groups(['read:Fournisseur:item'])]
    public function getActif(): bool
    {
        return $this->estActif();
    }

    /*
        - L'arrêt est irréversible : rouvrir, c'est ouvrir un NOUVEAU prélèvement, qui aura sa propre
          date de début. Sans quoi la fiche afficherait une ligne unique prétendant courir depuis
          mars alors qu'elle a été coupée deux mois au milieu.
    */
    public function arreter(?User $auteur): static
    {
        if($this->arreteAt !== null) {
            return $this;
        }

        $this->arreteAt = new \DateTimeImmutable();
        $this->arretePar = $auteur;

        return $this;
    }

    public function __toString(): string
    {
        return sprintf('%d F/kg sur %s', $this->taux, (string) $this->produit?->getLibelle());
    }
}
