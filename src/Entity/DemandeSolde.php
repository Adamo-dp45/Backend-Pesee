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
use App\Domain\Enum\CompteMouvement;
use App\Domain\Enum\StatutDemande;
use App\Domain\Enum\TypeCaisse;
use App\Entity\Input\TraiterDemandeInput;
use App\Entity\Interface\SiteOwnedInterface;
use App\Repository\DemandeSoldeRepository;
use App\State\Processor\AnnulerDemandeProcessor;
use App\State\Processor\ApprouverDemandeProcessor;
use App\State\Processor\CorrigerDemandeProcessor;
use App\State\Processor\DemandeSoldeProcessor;
use App\State\Processor\RejeterDemandeProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - Le chemin normal pour remettre de l'argent dans la caisse d'un poste : l'opérateur, qui est devant les planteurs, dit qu'il n'a plus de quoi payer ; l'administrateur, qui tient le solde de l'entreprise, décide. Une dotation directe reste possible depuis la fiche du site, mais elle ne laisse aucune trace de la demande.

    - UNE SEULE demande en attente par pont bascule. Sans cette règle, un opérateur impatient en dépose trois, un administrateur les approuve toutes, et le site reçoit le triple de ce qu'il fallait.

    - L'approbation DÉPLACE DE L'ARGENT : elle passe par 'CaisseService', qui verrouille, contrôle le solde de l'entreprise et écrit les deux lignes du journal. Jamais d'écriture de solde ailleurs.
*/
#[ORM\Entity(repositoryClass: DemandeSoldeRepository::class)]
#[ORM\Index(name: 'idx_demande_site_statut', fields: ['site', 'statut'])]
#[ApiResource(
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    normalizationContext: ['groups' => ['read:DemandeSolde', 'read:Base']],
    denormalizationContext: ['groups' => ['write:DemandeSolde']],
    paginationItemsPerPage: 25,
    paginationClientItemsPerPage: true,
    order: ['createdAt' => 'DESC'],
    operations: [
        new GetCollection(
            security: "is_granted('VOIR', 'DemandeSolde')",
            openapi: new Operation(
                summary: 'Liste des demandes de réapprovisionnement',
                description: 'Les demandes des ponts bascules de son périmètre',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get(
            security: "is_granted('VOIR', object)",
            requirements: ['id' => '\d+'],
            openapi: new Operation(
                summary: 'La demande de réapprovisionnement',
                description: 'Permet de voir une demande et son traitement',
                security: [['bearerAuth' => []]]
            )
        ),
        new Post(
            security: "is_granted('CREER', 'DemandeSolde')",
            processor: DemandeSoldeProcessor::class,
            openapi: new Operation(
                summary: 'Demande de réapprovisionnement',
                description: 'L\'opérateur demande à recharger la caisse de son pont bascule. Une seule demande en attente par site',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch( /*
            - Corriger sa demande tant qu'elle attend : un montant mal saisi ou un motif trop vague se rectifie sans annuler puis recommencer. Le processor ferme la porte dès qu'elle est traitée — sinon on réécrirait la demande sur laquelle un administrateur a déjà versé.
        */
            security: "is_granted('MODIFIER', object)",
            requirements: ['id' => '\d+'],
            denormalizationContext: ['groups' => ['write:DemandeSolde:correction']],
            processor: CorrigerDemandeProcessor::class,
            openapi: new Operation(
                summary: 'Correction de sa demande',
                description: 'L\'opérateur ajuste le montant ou le motif tant que la demande n\'est pas traitée',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch(
            uriTemplate: '/demande_soldes/{id}/approbation',
            security: "is_granted('TRAITER', object)",
            requirements: ['id' => '\d+'],
            input: TraiterDemandeInput::class,
            denormalizationContext: ['groups' => ['write:Demande:traitement']],
            processor: ApprouverDemandeProcessor::class,
            openapi: new Operation(
                summary: 'Approbation d\'une demande',
                description: 'Dote la caisse du site depuis le solde de l\'entreprise. Un montant accordé absent signifie « le montant demandé »',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch(
            uriTemplate: '/demande_soldes/{id}/rejet',
            security: "is_granted('TRAITER', object)",
            requirements: ['id' => '\d+'],
            input: TraiterDemandeInput::class,
            denormalizationContext: ['groups' => ['write:Demande:traitement']],
            processor: RejeterDemandeProcessor::class,
            openapi: new Operation(
                summary: 'Rejet d\'une demande',
                description: 'Le motif est obligatoire : sans explication, l\'opérateur ne sait pas quoi corriger et redemandera la même chose',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch( /*
            - L'opérateur retire sa demande devenue sans objet — une dotation directe est arrivée entre-temps, ou il s'est trompé de poste. Sans elle, il faut mobiliser un administrateur pour rejeter une demande que plus personne ne veut, et le site reste bloqué pour en déposer une autre.
        */
            uriTemplate: '/demande_soldes/{id}/annulation',
            security: "is_granted('MODIFIER', object)",
            requirements: ['id' => '\d+'],
            input: false,
            processor: AnnulerDemandeProcessor::class,
            openapi: new Operation(
                summary: 'Annulation de sa demande',
                description: 'L\'auteur retire sa demande tant qu\'elle n\'est pas traitée. Le pont bascule peut alors en déposer une nouvelle',
                security: [['bearerAuth' => []]]
            )
        ),
    ],
    openapi: new Operation(security: [['bearerAuth' => []]])
)]
#[ApiFilter(SearchFilter::class, properties: ['statut' => 'exact', 'site' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['createdAt'])]
#[ApiFilter(OrderFilter::class, properties: ['montantDemande', 'createdAt'])]
class DemandeSolde extends EntityBase implements SiteOwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /* 'read:MouvementCaisse' : l'écriture porte un lien vers la demande qui l'a déclenchée, et ce lien ne rendait AUCUN champ exploitable. */
    #[Groups(['read:DemandeSolde', 'read:MouvementCaisse'])]
    private ?int $id = null;

    #[ORM\Column]
    #[Assert\NotNull(message: 'Le montant demandé est obligatoire')]
    #[Assert\Positive(message: 'Le montant demandé doit être supérieur à zéro')]
    #[Groups(['read:DemandeSolde', 'write:DemandeSolde', 'write:DemandeSolde:correction'])]
    private int $montantDemande = 0;

    /*
        - QUELLE caisse l'opérateur demande à voir réapprovisionner. Les deux ne se remplacent pas : être à court de billets pendant que le flotte déborde est un cas courant, et une demande qui ne disait pas laquelle obligeait l'administrateur à deviner — ou à doter les deux.
        - Corrigeable tant que la demande est en attente, au même titre que le montant : on se trompe de caisse aussi facilement que de chiffre.
    */
    #[ORM\Column(enumType: TypeCaisse::class, options: ['default' => 'NUMERIQUE'])]
    #[Assert\NotNull(message: 'La caisse à réapprovisionner est obligatoire')]
    #[Assert\Choice(callback: 'caissesDUnPoste', message: 'Un pont bascule ne tient pas cette caisse : elle est à l\'entreprise seule.')]
    #[Groups(['read:DemandeSolde', 'write:DemandeSolde', 'write:DemandeSolde:correction'])]
    private TypeCaisse $caisse = TypeCaisse::NUMERIQUE;

    /*
        - Les caisses qu'une demande peut nommer. La banque est à l'entreprise seule : sans ce refus, la
          demande s'enregistre, attend un administrateur, et meurt à l'approbation — 'doterSite()' la
          refuse, mais après que deux personnes s'en sont occupées.
    */
    /** @return list<TypeCaisse> */
    public static function caissesDUnPoste(): array
    {
        return TypeCaisse::celles(CompteMouvement::SITE);
    }

    #[ORM\Column(nullable: true)]
    #[Groups(['read:DemandeSolde'])]
    private ?int $montantAccorde = null; // Renseigné à l'approbation seulement : il peut être inférieur au demandé

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 500)]
    #[Groups(['read:DemandeSolde', 'write:DemandeSolde', 'write:DemandeSolde:correction'])]
    private ?string $motif = null;

    #[ORM\Column(enumType: StatutDemande::class, options: ['default' => 'EN_ATTENTE'])]
    #[Groups(['read:DemandeSolde'])]
    private StatutDemande $statut = StatutDemande::EN_ATTENTE;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['read:DemandeSolde', 'write:DemandeSolde'])]
    private ?Site $site = null; // Écrit à la création puis figé : déplacer une demande la ferait payer par la mauvaise caisse

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['read:DemandeSolde'])]
    private ?User $demandePar = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['read:DemandeSolde'])]
    private ?User $traitePar = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:DemandeSolde'])]
    private ?\DateTimeImmutable $traiteAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['read:DemandeSolde'])]
    private ?string $motifRejet = null; // Ce que l'opérateur lit avant de redemander

    /* La pièce qui motive la demande, s'il y en a une. Elle porte la clé ; d'ici on ne fait que la lire. */
    #[ORM\OneToOne(mappedBy: 'demandeSolde')]
    #[Groups(['read:DemandeSolde'])]
    private ?PieceJointe $pieceJointe = null;

    public function getPieceJointe(): ?PieceJointe
    {
        return $this->pieceJointe;
    }

    public function setPieceJointe(?PieceJointe $pieceJointe): static
    {
        $this->pieceJointe = $pieceJointe;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMontantDemande(): int
    {
        return $this->montantDemande;
    }

    public function setMontantDemande(int $montantDemande): static
    {
        $this->montantDemande = $montantDemande;

        return $this;
    }

    public function getCaisse(): TypeCaisse
    {
        return $this->caisse;
    }

    public function setCaisse(TypeCaisse $caisse): static
    {
        $this->caisse = $caisse;

        return $this;
    }

    #[Groups(['read:DemandeSolde'])]
    public function getCaisseLibelle(): string
    {
        return $this->caisse->libelle();
    }

    public function getMontantAccorde(): ?int
    {
        return $this->montantAccorde;
    }

    public function setMontantAccorde(?int $montantAccorde): static
    {
        $this->montantAccorde = $montantAccorde;

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

    #[Groups(['read:DemandeSolde'])]
    public function getStatutLibelle(): string
    {
        return $this->statut->libelle();
    }

    public function estTraitee(): bool
    {
        return $this->statut->estTraitee();
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
