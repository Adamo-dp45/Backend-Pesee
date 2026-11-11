<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Domain\Enum\StatutDemande;
use App\Entity\Input\TraiterDemandeInput;
use App\Entity\Interface\SiteOwnedInterface;
use App\Repository\DemandePrelevementRepository;
use App\State\Processor\AnnulerPrelevementProcessor;
use App\State\Processor\ApprouverPrelevementProcessor;
use App\State\Processor\CorrigerPrelevementProcessor;
use App\State\Processor\DemandePrelevementProcessor;
use App\State\Processor\RejeterPrelevementProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - Le chemin normal pour ouvrir une mise en compte : le planteur demande à épargner, l'opérateur
      qui l'a devant lui dépose la demande avec le taux réclamé, l'administrateur décide. Il peut
      aussi ouvrir directement depuis la fiche du planteur, comme il peut doter une caisse sans
      demande — mais alors personne ne garde trace de qui a demandé quoi.

    - UNE SEULE demande en attente par planteur. Sans cette règle, un opérateur en dépose trois,
      l'administrateur les approuve toutes, et le dernier taux approuvé écrase les autres sans que
      personne ne sache lequel s'applique.

    - L'approbation ne déplace AUCUN argent : elle ne fait qu'ouvrir le robinet. Ce sont les pesées
      suivantes qui mettront de côté, une à une. C'est pourquoi, contrairement à la demande de
      solde, elle ne passe par aucun service de caisse — et c'est aussi pourquoi elle doit être
      journalisée : le grand livre, lui, ne la verra jamais.

    - Le taux approuvé est CELUI QUI A ÉTÉ DEMANDÉ. L'administrateur approuve ou rejette, il ne
      corrige pas : le chiffre vient du planteur, par la bouche de l'opérateur, et le retoucher
      depuis un bureau reviendrait à décider de l'épargne de quelqu'un d'autre.
*/
#[ORM\Entity(repositoryClass: DemandePrelevementRepository::class)]
#[ORM\Index(name: 'idx_demande_prelevement_statut', fields: ['fournisseur', 'statut'])]
#[ApiResource(
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    normalizationContext: ['groups' => ['read:DemandePrelevement', 'read:Base'], 'skip_null_values' => false],
    denormalizationContext: ['groups' => ['write:DemandePrelevement']],
    paginationItemsPerPage: 25,
    paginationClientItemsPerPage: true,
    order: ['createdAt' => 'DESC'],
    operations: [
        new GetCollection(
            security: "is_granted('VOIR', 'DemandePrelevement')",
            openapi: new Operation(
                summary: 'Liste des demandes de mise en compte',
                description: 'Les demandes des planteurs de son périmètre',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get(
            security: "is_granted('VOIR', object)",
            requirements: ['id' => '\d+'],
            openapi: new Operation(
                summary: 'La demande de mise en compte',
                description: 'Permet de voir une demande et son traitement',
                security: [['bearerAuth' => []]]
            )
        ),
        new Post(
            security: "is_granted('CREER', 'DemandePrelevement')",
            processor: DemandePrelevementProcessor::class,
            openapi: new Operation(
                summary: 'Demande de mise en compte',
                description: 'L\'opérateur demande l\'ouverture d\'un prélèvement pour un planteur. Une seule demande en attente par planteur',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch( /*
            - Corriger sa demande tant qu'elle attend : un taux mal saisi se rectifie sans annuler puis recommencer. Le processor ferme la porte dès qu'elle est traitée.
        */
            security: "is_granted('MODIFIER', object)",
            requirements: ['id' => '\d+'],
            denormalizationContext: ['groups' => ['write:DemandePrelevement:correction']],
            processor: CorrigerPrelevementProcessor::class,
            openapi: new Operation(
                summary: 'Correction de sa demande',
                description: 'L\'opérateur ajuste le taux ou le motif tant que la demande n\'est pas traitée',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch( /*
            - Aucun corps : il n'y a rien à décider d'autre que oui ou non. Le taux approuvé est celui qui a été demandé.
        */
            uriTemplate: '/demande_prelevements/{id}/approbation',
            security: "is_granted('TRAITER', object)",
            requirements: ['id' => '\d+'],
            input: false,
            processor: ApprouverPrelevementProcessor::class,
            openapi: new Operation(
                summary: 'Approbation d\'une demande',
                description: 'Ouvre le prélèvement sur le planteur, au taux demandé. Les pesées suivantes mettront de côté',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch(
            uriTemplate: '/demande_prelevements/{id}/rejet',
            security: "is_granted('TRAITER', object)",
            requirements: ['id' => '\d+'],
            input: TraiterDemandeInput::class,
            denormalizationContext: ['groups' => ['write:Demande:traitement']],
            processor: RejeterPrelevementProcessor::class,
            openapi: new Operation(
                summary: 'Rejet d\'une demande',
                description: 'Le motif est obligatoire : sans explication, l\'opérateur ne sait pas quoi dire au planteur',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch(
            uriTemplate: '/demande_prelevements/{id}/annulation',
            security: "is_granted('MODIFIER', object)",
            requirements: ['id' => '\d+'],
            input: false,
            processor: AnnulerPrelevementProcessor::class,
            openapi: new Operation(
                summary: 'Annulation de sa demande',
                description: 'L\'auteur retire sa demande tant qu\'elle n\'est pas traitée. Le planteur peut alors en déposer une nouvelle',
                security: [['bearerAuth' => []]]
            )
        ),
    ],
    openapi: new Operation(security: [['bearerAuth' => []]])
)]
#[ApiFilter(SearchFilter::class, properties: ['statut' => 'exact', 'fournisseur' => 'exact', 'site' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['createdAt'])]
#[ApiFilter(OrderFilter::class, properties: ['tauxDemande', 'createdAt'])]
class DemandePrelevement extends EntityBase implements SiteOwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:DemandePrelevement'])]
    private ?int $id = null;

    /*
        - Le taux réclamé par le planteur, en francs par kilo. C'est lui qui s'appliquera tel quel à l'approbation : le montant mis de côté sur une pesée en découle, taux × poids net, plafonné à ce que vaut la pesée.
    */
    #[ORM\Column]
    #[Assert\NotNull(message: 'Le taux demandé est obligatoire')]
    #[Assert\Positive(message: 'Le taux demandé doit être supérieur à zéro')]
    #[Groups(['read:DemandePrelevement', 'write:DemandePrelevement', 'write:DemandePrelevement:correction'])]
    private int $tauxDemande = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 500)]
    #[Groups(['read:DemandePrelevement', 'write:DemandePrelevement', 'write:DemandePrelevement:correction'])]
    private ?string $motif = null;

    #[ORM\Column(enumType: StatutDemande::class, options: ['default' => 'EN_ATTENTE'])]
    #[Groups(['read:DemandePrelevement'])]
    private StatutDemande $statut = StatutDemande::EN_ATTENTE;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['read:DemandePrelevement', 'write:DemandePrelevement'])]
    private ?Fournisseur $fournisseur = null; // Écrit à la création puis figé : déplacer une demande ouvrirait l'épargne du mauvais planteur

    /*
        - Recopié du planteur par le processor, et jamais saisi : c'est ce qui permet au cloisonnement de filtrer les demandes sans jointure, comme sur le livre d'épargne.
    */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['read:DemandePrelevement'])]
    private ?Site $site = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['read:DemandePrelevement'])]
    private ?User $demandePar = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['read:DemandePrelevement'])]
    private ?User $traitePar = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:DemandePrelevement'])]
    private ?\DateTimeImmutable $traiteAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['read:DemandePrelevement'])]
    private ?string $motifRejet = null; // Ce que l'opérateur lit avant de redemander

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTauxDemande(): int
    {
        return $this->tauxDemande;
    }

    public function setTauxDemande(int $tauxDemande): static
    {
        $this->tauxDemande = $tauxDemande;

        return $this;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(?string $motif): static
    {
        $this->motif = $motif;

        return $this;
    }

    public function getStatut(): StatutDemande
    {
        return $this->statut;
    }

    public function setStatut(StatutDemande $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    #[Groups(['read:DemandePrelevement'])]
    public function getStatutLibelle(): string
    {
        return $this->statut->libelle();
    }

    public function estTraitee(): bool
    {
        return $this->statut->estTraitee();
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

    public function getSite(): ?Site
    {
        return $this->site;
    }

    public function setSite(?Site $site): static
    {
        $this->site = $site;

        return $this;
    }

    public function getDemandePar(): ?User
    {
        return $this->demandePar;
    }

    public function setDemandePar(?User $demandePar): static
    {
        $this->demandePar = $demandePar;

        return $this;
    }

    public function getTraitePar(): ?User
    {
        return $this->traitePar;
    }

    public function setTraitePar(?User $traitePar): static
    {
        $this->traitePar = $traitePar;

        return $this;
    }

    public function getTraiteAt(): ?\DateTimeImmutable
    {
        return $this->traiteAt;
    }

    public function setTraiteAt(?\DateTimeImmutable $traiteAt): static
    {
        $this->traiteAt = $traiteAt;

        return $this;
    }

    public function getMotifRejet(): ?string
    {
        return $this->motifRejet;
    }

    public function setMotifRejet(?string $motifRejet): static
    {
        $this->motifRejet = $motifRejet;

        return $this;
    }
}
