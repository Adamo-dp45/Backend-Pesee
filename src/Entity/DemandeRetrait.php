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
use App\Entity\Input\VersementRetraitInput;
use App\Entity\Interface\SiteOwnedInterface;
use App\Repository\DemandeRetraitRepository;
use App\State\Processor\AnnulerRetraitProcessor;
use App\State\Processor\ApprouverRetraitProcessor;
use App\State\Processor\CorrigerRetraitProcessor;
use App\State\Processor\DemandeRetraitProcessor;
use App\State\Processor\RejeterRetraitProcessor;
use App\State\Processor\VerserRetraitProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - Le planteur vient chercher ce qu'on lui gardait. L'opérateur, qui l'a devant lui, dépose la
      demande ; l'administrateur accorde ou refuse.

    - L'ACCORD N'EST PAS LE VERSEMENT, et c'est la différence avec la demande de solde, qui dote la
      caisse sur-le-champ. L'administrateur n'a ni le planteur en face de lui, ni son numéro, ni le
      choix du mode de règlement : il AUTORISE, et l'opérateur verse ensuite au guichet, dans la
      limite accordée. D'où le 'paiement' en fin de fiche — tant qu'il est nul, la demande est
      accordée mais le planteur n'a rien touché.

    - 'montantAccorde' peut être inférieur au demandé, comme sur une demande de solde : c'est le cas
      quand la caisse du poste ne couvre pas tout. L'administrateur n'a pas à refuser en bloc une
      demande qu'il peut servir à moitié.

    - UNE SEULE demande en attente par planteur. Sans cette règle, deux demandes approuvées coup sur
      coup autorisent deux versements qui, ensemble, dépassent l'épargne — et c'est le solde qui
      arbitrerait, au guichet, devant le planteur.
*/
#[ORM\Entity(repositoryClass: DemandeRetraitRepository::class)]
#[ORM\Index(name: 'idx_demande_retrait_statut', fields: ['fournisseur', 'statut'])]
#[ApiResource(
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    normalizationContext: ['groups' => ['read:DemandeRetrait', 'read:Base'], 'skip_null_values' => false],
    denormalizationContext: ['groups' => ['write:DemandeRetrait']],
    paginationItemsPerPage: 25,
    paginationClientItemsPerPage: true,
    order: ['createdAt' => 'DESC'],
    operations: [
        new GetCollection(
            security: "is_granted('VOIR', 'DemandeRetrait')",
            openapi: new Operation(
                summary: 'Liste des demandes de retrait',
                description: 'Les demandes des planteurs de son périmètre',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get(
            security: "is_granted('VOIR', object)",
            requirements: ['id' => '\d+'],
            openapi: new Operation(
                summary: 'La demande de retrait',
                description: 'Permet de voir une demande, son traitement et le versement qui l\'a servie',
                security: [['bearerAuth' => []]]
            )
        ),
        new Post(
            security: "is_granted('CREER', 'DemandeRetrait')",
            processor: DemandeRetraitProcessor::class,
            openapi: new Operation(
                summary: 'Demande de retrait d\'épargne',
                description: 'L\'opérateur demande, pour le planteur qu\'il a devant lui, de lui rendre une part de son épargne. Une seule demande en attente par planteur',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch( /*
            - Corriger sa demande tant qu'elle attend : un montant mal saisi se rectifie sans annuler puis recommencer.
        */
            security: "is_granted('MODIFIER', object)",
            requirements: ['id' => '\d+'],
            denormalizationContext: ['groups' => ['write:DemandeRetrait:correction']],
            processor: CorrigerRetraitProcessor::class,
            openapi: new Operation(
                summary: 'Correction de sa demande',
                description: 'L\'opérateur ajuste le montant ou le motif tant que la demande n\'est pas traitée',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch( /*
            - L'accord. Il n'écrit AUCUN mouvement : il autorise un versement, que l'opérateur fera au guichet. C'est pour cela qu'il est journalisé — rien d'autre ne garderait trace de la décision.
        */
            uriTemplate: '/demande_retraits/{id}/approbation',
            security: "is_granted('TRAITER', object)",
            requirements: ['id' => '\d+'],
            input: TraiterDemandeInput::class,
            denormalizationContext: ['groups' => ['write:Demande:traitement']],
            processor: ApprouverRetraitProcessor::class,
            openapi: new Operation(
                summary: 'Accord d\'une demande de retrait',
                description: 'Autorise le versement, au montant demandé ou à un montant inférieur. L\'argent ne bouge qu\'au guichet',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch(
            uriTemplate: '/demande_retraits/{id}/rejet',
            security: "is_granted('TRAITER', object)",
            requirements: ['id' => '\d+'],
            input: TraiterDemandeInput::class,
            denormalizationContext: ['groups' => ['write:Demande:traitement']],
            processor: RejeterRetraitProcessor::class,
            openapi: new Operation(
                summary: 'Rejet d\'une demande de retrait',
                description: 'Le motif est obligatoire : sans explication, l\'opérateur ne sait pas quoi dire au planteur',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch( /*
            - Le guichet sert le retrait accordé. C'est la SEULE opération de cette ressource qui déplace de l'argent : tout ce qui précède n'a fait qu'autoriser.
            - Le montant n'est pas dans le corps : il vient de l'accord. Le laisser saisir rendrait la décision de l'administrateur décorative.
        */
            uriTemplate: '/demande_retraits/{id}/versement',
            security: "is_granted('PAYER', 'Paiement')",
            requirements: ['id' => '\d+'],
            input: VersementRetraitInput::class,
            denormalizationContext: ['groups' => ['write:Retrait:versement']],
            processor: VerserRetraitProcessor::class,
            openapi: new Operation(
                summary: 'Versement d\'un retrait accordé',
                description: 'Remet l\'argent au planteur, au montant accordé. Débite son épargne ET la caisse, dans le même geste',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch(
            uriTemplate: '/demande_retraits/{id}/annulation',
            security: "is_granted('MODIFIER', object)",
            requirements: ['id' => '\d+'],
            input: false,
            processor: AnnulerRetraitProcessor::class,
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
#[ApiFilter(OrderFilter::class, properties: ['montantDemande', 'createdAt'])]
class DemandeRetrait extends EntityBase implements SiteOwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:DemandeRetrait'])]
    private ?int $id = null;

    /*
        - Ce que le planteur réclame sur son épargne. Il n'est PAS borné ici par le solde : entre le dépôt et l'accord, une pesée peut avoir alimenté le compte, ou un autre retrait l'avoir vidé. La seule borne qui compte est celle du guichet, lue sous verrou au moment d'écrire.
    */
    #[ORM\Column]
    #[Assert\NotNull(message: 'Le montant demandé est obligatoire')]
    #[Assert\Positive(message: 'Le montant demandé doit être supérieur à zéro')]
    #[Groups(['read:DemandeRetrait', 'write:DemandeRetrait', 'write:DemandeRetrait:correction'])]
    private int $montantDemande = 0;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:DemandeRetrait'])]
    private ?int $montantAccorde = null; // Renseigné à l'accord seulement : il peut être inférieur au demandé

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 500)]
    #[Groups(['read:DemandeRetrait', 'write:DemandeRetrait', 'write:DemandeRetrait:correction'])]
    private ?string $motif = null;

    #[ORM\Column(enumType: StatutDemande::class, options: ['default' => 'EN_ATTENTE'])]
    #[Groups(['read:DemandeRetrait'])]
    private StatutDemande $statut = StatutDemande::EN_ATTENTE;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['read:DemandeRetrait', 'write:DemandeRetrait'])]
    private ?Fournisseur $fournisseur = null; // Écrit au dépôt puis figé : déplacer une demande viderait l'épargne d'un autre

    /*
        - Recopié du planteur par le processor, et jamais saisi : c'est ce qui permet au cloisonnement de filtrer les demandes sans jointure, comme sur le livre d'épargne.
    */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['read:DemandeRetrait'])]
    private ?Site $site = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['read:DemandeRetrait'])]
    private ?User $demandePar = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['read:DemandeRetrait'])]
    private ?User $traitePar = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:DemandeRetrait'])]
    private ?\DateTimeImmutable $traiteAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['read:DemandeRetrait'])]
    private ?string $motifRejet = null; // Ce que l'opérateur lit avant de redemander

    /*
        - Le versement qui a servi cette demande, une fois l'argent remis. Tant qu'il est nul, la demande est accordée mais le planteur n'a rien touché — c'est ce qui distingue « autorisé » de « servi », et c'est la file de travail du guichet.
    */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['read:DemandeRetrait'])]
    private ?Paiement $paiement = null;

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

    public function estTraitee(): bool
    {
        return $this->statut->estTraitee();
    }

    /* Accordée mais pas encore servie : c'est la file de travail du guichet. */
    public function attendLeVersement(): bool
    {
        return $this->statut === StatutDemande::APPROUVEE && $this->paiement === null;
    }

    /*
        - Le même prédicat, sous le nom que le sérialiseur sait lire. API Platform LÈVE — il ne se
          contente pas d'ignorer — sur un groupe posé sur une méthode qui n'est ni 'get', ni 'is',
          ni 'has', ni 'can', ni 'set'. Le piège est déjà connu pour 'Prelevement::getActif()', à
          ceci près qu'il était silencieux là-bas et qu'il casse toute l'API ici.
    */
    #[Groups(['read:DemandeRetrait'])]
    public function getAttenteDeVersement(): bool
    {
        return $this->attendLeVersement();
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

    public function getPaiement(): ?Paiement
    {
        return $this->paiement;
    }

    public function setPaiement(?Paiement $paiement): static
    {
        $this->paiement = $paiement;

        return $this;
    }

    public function __toString(): string
    {
        return sprintf('Retrait de %s F pour %s', number_format($this->montantDemande, 0, ',', ' '), (string) $this->fournisseur?->getNomComplet());
    }
}
