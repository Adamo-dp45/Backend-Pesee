<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation;
use App\Domain\Enum\TypeActivite;
use App\Entity\Interface\EntrepriseOwnedInterface;
use App\Repository\ActiviteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/*
    - Le journal des décisions : qui a fait quoi, quand, et sur quoi. C'est le pendant du grand livre,
      qui ne retient que l'argent — ici on retient les gestes qui n'en déplacent pas mais qui
      engagent : un prix changé, un poste coupé, un compte suspendu, un référentiel purgé.

    - Une ligne ne se modifie ni ne se supprime : on ne corrige pas un journal, on y écrit la suite.
      Aucune opération d'écriture n'est donc exposée — 'ActiviteLogger' est le seul auteur.

    - Rattachée à l'ENTREPRISE et non au site, parce que la moitié des gestes tracés ne visent pas un
      pont bascule (un compte, un rôle, l'entreprise elle-même). Le marqueur suffit à ce que
      'PerimetreExtension' cloisonne le journal comme le reste.

    - Le libellé est une PHRASE, composée au moment du geste, et non un gabarit rejoué à l'affichage :
      elle doit rester lisible même si le produit qu'elle nomme a été renommé ou purgé depuis. C'est
      tout l'intérêt d'un journal.
*/
#[ORM\Entity(repositoryClass: ActiviteRepository::class)]
#[ORM\Index(name: 'idx_activite_entreprise_date', fields: ['entreprise', 'createdAt'])]
#[ORM\Index(name: 'idx_activite_type', fields: ['type'])]
#[ORM\Index(name: 'idx_activite_cible', fields: ['cibleType', 'cibleId'])]
#[ApiResource(
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    normalizationContext: ['groups' => ['read:Activite', 'read:Base']],
    paginationItemsPerPage: 50,
    paginationClientItemsPerPage: true,
    order: ['createdAt' => 'DESC'],
    operations: [ // Lecture seule : un journal ne se réécrit pas
        new GetCollection(
            security: "is_granted('VOIR', 'Activite')",
            openapi: new Operation(
                summary: 'Journal des activités',
                description: 'Qui a changé un prix, bloqué un poste, suspendu un compte ou vidé la corbeille',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get(
            security: "is_granted('VOIR', object)",
            requirements: ['id' => '\d+'],
            openapi: new Operation(
                summary: 'L\'activité',
                description: 'Permet de voir une ligne du journal et sa cible',
                security: [['bearerAuth' => []]]
            )
        ),
    ],
    openapi: new Operation(security: [['bearerAuth' => []]])
)]
#[ApiFilter(SearchFilter::class, properties: [
    'type' => 'exact',
    'cibleType' => 'exact',
    'cibleId' => 'exact',
    'auteur' => 'exact',
])]
#[ApiFilter(DateFilter::class, properties: ['createdAt'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'])]
class Activite extends EntityBase implements EntrepriseOwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:Activite'])]
    private ?int $id = null;

    #[ORM\Column(enumType: TypeActivite::class)]
    #[Groups(['read:Activite'])]
    private ?TypeActivite $type = null;

    #[ORM\Column(length: 500)]
    #[Groups(['read:Activite'])]
    private ?string $libelle = null;

    /*
        - La cible est désignée par son NOM COURT et son identifiant, sans relation Doctrine. Une clé
          étrangère imposerait une colonne par type visé, et surtout empêcherait de journaliser une
          purge : la ligne visée n'existe plus, et la contrainte ferait échouer l'écriture du journal
          au moment précis où elle est la plus utile.
    */
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['read:Activite'])]
    private ?string $cibleType = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:Activite'])]
    private ?int $cibleId = null;

    /*
        - 'SET NULL' : supprimer un compte ne doit pas emporter ce qu'il a fait. L'auteur devient
          anonyme, la ligne reste.
    */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['read:Activite'])]
    private ?User $auteur = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Entreprise $entreprise = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ?TypeActivite
    {
        return $this->type;
    }

    public function setType(TypeActivite $type): static
    {
        $this->type = $type;

        return $this;
    }

    /* Le dictionnaire des types vit dans l'énumération : le frontend n'a pas à le recopier. */
    #[Groups(['read:Activite'])]
    public function getTypeLibelle(): ?string
    {
        return $this->type?->libelle();
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getCibleType(): ?string
    {
        return $this->cibleType;
    }

    public function setCibleType(?string $cibleType): static
    {
        $this->cibleType = $cibleType;

        return $this;
    }

    public function getCibleId(): ?int
    {
        return $this->cibleId;
    }

    public function setCibleId(?int $cibleId): static
    {
        $this->cibleId = $cibleId;

        return $this;
    }

    public function getAuteur(): ?User
    {
        return $this->auteur;
    }

    public function setAuteur(?User $auteur): static
    {
        $this->auteur = $auteur;

        return $this;
    }

    /* Le nom de l'auteur est FIGÉ dans le libellé au moment du geste ; celui-ci n'est là que pour filtrer. */
    #[Groups(['read:Activite'])]
    public function getAuteurNom(): ?string
    {
        return $this->auteur?->getNomComplet();
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
}
