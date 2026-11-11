<?php

namespace App\Entity;

use App\Entity\Interface\EntrepriseOwnedInterface;
use App\Entity\Interface\SiteOwnedInterface;
use App\Repository\CompteEpargneRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/*
    - L'épargne d'un planteur : ce que l'entreprise lui garde, et donc ce qu'elle lui doit. Créé au
      premier prélèvement, jamais avant — un planteur qui n'épargne pas n'a pas de compte.

    - Une table à part plutôt qu'une colonne sur 'Fournisseur', et ce n'est pas un détail : la
      synchronisation ÉCRIT déjà sur la ligne du planteur pendant la transaction d'un lot (son code,
      son numéro de téléphone). Y poser en plus le verrou d'écriture du solde créerait une contention
      gratuite, et surtout un ordre de verrou imprévisible entre deux lots concurrents. Cette
      ligne-ci n'est touchée que par 'EpargneService'.

    - Pas de ressource API : le solde se lit sur la fiche du planteur, et les écritures se lisent
      dans 'MouvementEpargne'. Un compte n'a rien à dire de lui-même.
*/
#[ORM\Entity(repositoryClass: CompteEpargneRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_compte_epargne_fournisseur', fields: ['fournisseur'])]
class CompteEpargne extends EntityBase implements EntrepriseOwnedInterface, SiteOwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:CompteEpargne'])]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'compteEpargne')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Fournisseur $fournisseur = null;

    /*
        - Le site et l'entreprise sont recopiés ici alors que le planteur les porte déjà : c'est ce
          qui permet au cloisonnement de filtrer sans jointure, exactement comme sur le grand livre
          des caisses.
    */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Entreprise $entreprise = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Site $site = null;

    /* Toujours positif ou nul : sans retrait, un compte ne fait que se remplir. */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    #[Groups(['read:CompteEpargne'])]
    private int $solde = 0;

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

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): static
    {
        $this->entreprise = $entreprise;

        return $this;
    }

    public function getSite(): ?Site
    {
        return $this->site;
    }

    public function setSite(?Site $site): static
    {
        $this->site = $site;

        return $this;
    }

    public function getSolde(): int
    {
        return (int) $this->solde;
    }

    /* Écrit par 'EpargneService' seul, sous verrou : c'est lui qui tient la chaîne des soldes. */
    public function setSolde(int $solde): static
    {
        $this->solde = $solde;

        return $this;
    }
}
