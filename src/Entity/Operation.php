<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
// Aliasé : 'Operation' est le nom de cette entité même
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Domain\Enum\StatutOperation;
use App\Domain\Enum\StatutReglement;
use App\Entity\Interface\SiteOwnedInterface;
use App\Repository\OperationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/*
    - Une pesée. Le camion monte une première fois sur le pont (poids1), repart chargé ou vidé, remonte une seconde fois (poids2) : la différence donne le poids net facturé au planteur.

    - Jamais écrite depuis le web : elle arrive exclusivement par la synchronisation du logiciel de pesée. Seuls les champs financiers ('montantpaye', 'statutReglement') évoluent ensuite, au fil des paiements.

    NOMMAGE — deux champs se ressemblent et désignaient la même chose dans l'ancien modèle :
      · 'Site::code'   → le code du pont bascule, attribué à l'installation ('SOF010') ;
      · 'refDesktop'   → la clé primaire de CETTE pesée dans la base locale du poste.
      'refDesktop' est renvoyé tel quel dans l'accusé de réception : c'est ce qui permet au poste de marquer ses lignes comme synchronisées. Il ne doit jamais être altéré.

    DATES — le poste envoie la date et l'heure séparément ('datepesee1' + 'temps1'). On les recompose en un seul horodatage : l'écran « BILAN STANDARD » filtre sur une plage date + heure, ce qui serait pénible avec quatre colonnes distinctes.
*/
#[ORM\Entity(repositoryClass: OperationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_operation_codesecret', fields: ['codesecret'])]
#[ORM\Index(name: 'idx_operation_site_pesee2', fields: ['site', 'peseeAt2'])]
#[ORM\Index(name: 'idx_operation_site_statut', fields: ['site', 'statut'])]
#[ORM\Index(name: 'idx_operation_site_reglement', fields: ['site', 'statutReglement'])]
#[ORM\Index(name: 'idx_operation_fournisseur', fields: ['fournisseur'])]
#[ORM\Index(name: 'idx_operation_produit', fields: ['produit'])]
#[ApiResource(
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    normalizationContext: ['groups' => ['read:Operation', 'read:Base'], 'skip_null_values' => false],
    paginationItemsPerPage: 25,
    paginationClientItemsPerPage: true,
    order: ['peseeAt2' => 'DESC'],
    operations: [ // Lecture seule : une pesée n'arrive que par la synchronisation du poste
        new GetCollection(
            security: "is_granted('VOIR', 'Operation')",
            openapi: new OpenApiOperation(
                summary: 'Liste des pesées',
                description: 'Alimente la page Bilan. Filtres calqués sur l\'écran « Bilan standard » du logiciel de pesée',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get(
            security: "is_granted('VOIR', object)",
            requirements: ['id' => '\d+'],
            normalizationContext: ['groups' => ['read:Operation', 'read:Operation:item', 'read:Base'], 'skip_null_values' => false],
            openapi: new OpenApiOperation(
                summary: 'La pesée',
                description: 'Fiche complète, avec le détail du règlement',
                security: [['bearerAuth' => []]]
            )
        ),
    ],
    openapi: new OpenApiOperation(security: [['bearerAuth' => []]])
)]
/*
    - Filtres repris de l'écran « Bilan standard » : Opération, Destination, Provenance, Produit, Véhicule, Fournisseur, Transporteur, Client, Chauffeur, plage de dates. S'y ajoute le site, qui n'existe pas côté poste puisque chacun ne voit que le sien.
*/
#[ApiFilter(SearchFilter::class, properties: [
    'mouvement' => 'exact',
    'client' => 'exact',
    'destination' => 'exact',
    'provenance' => 'exact',
    'transporteur' => 'exact',
    'chauffeur' => 'exact',
    'immatriculation' => 'partial',
    'numticket' => 'partial',
    'site' => 'exact',
    'produit' => 'exact',
    'fournisseur' => 'exact',
    'statut' => 'exact',
    'statutReglement' => 'exact',
])]
#[ApiFilter(DateFilter::class, properties: ['peseeAt2'])]
#[ApiFilter(OrderFilter::class, properties: ['peseeAt2', 'poidsnet', 'montantdu', 'numticket'])]
class Operation extends EntityBase implements SiteOwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:Operation', 'read:Paiement'])]
    private ?int $id = null;

    /*
        - Clé d'idempotence de la synchronisation, de la forme 'code_refDesktop_codepesee'. Son index unique est ce qui garantit que rejouer un lot ne crée aucun doublon — il manquait dans le modèle précédent, où seul un 'findOneBy' protégeait l'insertion.
    */
    #[ORM\Column(length: 255)]
    private ?string $codesecret = null;

    #[ORM\Column(length: 100)]
    #[Groups(['read:Operation', 'read:Operation:item'])]
    private ?string $refDesktop = null; // Chaîne et non entier : renvoyée telle quelle au poste dans l'accusé, sans reformatage

    /* 'inversedBy' rend les trois relations bidirectionnelles : c'est ce qui permet aux référentiels de compter leurs pesées. Aucune colonne ne change, seule la cartographie. */
    #[ORM\ManyToOne(inversedBy: 'pesees')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['read:Operation'])]
    private ?Site $site = null;

    #[ORM\ManyToOne(inversedBy: 'pesees')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['read:Operation'])]
    private ?Produit $produit = null;

    #[ORM\ManyToOne(inversedBy: 'pesees')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['read:Operation'])]
    private ?Fournisseur $fournisseur = null;

    #[ORM\Column(length: 100)]
    #[Groups(['read:Operation', 'read:Operation:item'])]
    private ?string $codepesee = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['read:Operation', 'read:Paiement'])]
    private ?string $numticket = null; // Émis au second passage : une pesée en attente n'en a pas encore

    /* ---- Référentiels libres, saisis sur le poste ---------------------------------------
       Le patron n'a demandé d'extraire que le produit et le fournisseur. Les autres restent du
       texte : créés à la volée par le peseur, ils ne portent aucune règle métier. */

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['read:Operation'])]
    private ?string $mouvement = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['read:Operation'])]
    private ?string $client = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['read:Operation'])]
    private ?string $destination = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['read:Operation'])]
    private ?string $provenance = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['read:Operation'])]
    private ?string $transporteur = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['read:Operation'])]
    private ?string $chauffeur = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['read:Operation'])]
    private ?string $immatriculation = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['read:Operation', 'read:Operation:item'])]
    private ?string $remorque = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['read:Operation', 'read:Operation:item'])]
    private ?string $peseur = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['read:Operation', 'read:Operation:item'])]
    private ?string $contactPl = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['read:Operation', 'read:Operation:item'])]
    private ?string $conteneur = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['read:Operation', 'read:Operation:item'])]
    private ?string $plomb = null;

    /* ---- Pesage ------------------------------------------------------------------------- */

    #[ORM\Column(nullable: true)]
    #[Groups(['read:Operation'])]
    private ?int $poids1 = null;

    /*
        - Nullables : tant que le camion n'est pas ressorti, le second poids — donc le brut et le net — n'existe pas. C'est le panneau « PESEE EN ATTENTE » du logiciel de pesée.
    */
    #[ORM\Column(nullable: true)]
    #[Groups(['read:Operation'])]
    private ?int $poids2 = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:Operation'])]
    private ?int $poidsbrut = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:Operation'])]
    private ?int $poidsnet = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['read:Operation'])]
    private ?\DateTimeImmutable $peseeAt1 = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['read:Operation'])]
    private ?\DateTimeImmutable $peseeAt2 = null;

    #[ORM\Column(enumType: StatutOperation::class, options: ['default' => 'EN_COURS'])]
    #[Groups(['read:Operation'])]
    private StatutOperation $statut = StatutOperation::EN_COURS;

    /* ---- Valorisation ------------------------------------------------------------------- */

    /*
        - Prix retenu au moment du calcul : prix spécial du planteur s'il existe, sinon prix du produit. Figé sur la pesée pour que l'historique reste lisible même si les tarifs changent ensuite.
    */
    #[ORM\Column(nullable: true)]
    #[Groups(['read:Operation'])]
    private ?int $prixunitaire = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:Operation'])]
    private ?int $montantdu = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['read:Operation'])]
    private int $montantpaye = 0; // Cumul des paiements confirmés. Dénormalisé : sans lui, lister des pesées imposerait une sous-requête de somme par ligne

    #[ORM\Column(enumType: StatutReglement::class, options: ['default' => 'NON_PAYE'])]
    #[Groups(['read:Operation'])]
    private StatutReglement $statutReglement = StatutReglement::NON_PAYE;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['read:Operation', 'read:Operation:item'])]
    private ?\DateTimeImmutable $syncAt = null;

    /**
     * Réservée à la FICHE : la liste ne doit jamais hydrater les versements de chaque ligne.
     *
     * @var Collection<int, Paiement>
     */
    #[ORM\OneToMany(targetEntity: Paiement::class, mappedBy: 'operation')]
    #[Groups(['read:Operation:item'])]
    private Collection $paiements;

    public function __construct()
    {
        $this->paiements = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodesecret(): ?string
    {
        return $this->codesecret;
    }

    public function setCodesecret(string $codesecret): static
    {
        $this->codesecret = $codesecret;

        return $this;
    }

    public function getRefDesktop(): ?string
    {
        return $this->refDesktop;
    }

    public function setRefDesktop(string $refDesktop): static
    {
        $this->refDesktop = $refDesktop;

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

    public function getProduit(): ?Produit
    {
        return $this->produit;
    }

    public function setProduit(?Produit $produit): static
    {
        $this->produit = $produit;

        return $this;
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

    public function getCodepesee(): ?string
    {
        return $this->codepesee;
    }

    public function setCodepesee(string $codepesee): static
    {
        $this->codepesee = $codepesee;

        return $this;
    }

    public function getNumticket(): ?string
    {
        return $this->numticket;
    }

    public function setNumticket(?string $numticket): static
    {
        $this->numticket = $numticket;

        return $this;
    }

    public function getMouvement(): ?string
    {
        return $this->mouvement;
    }

    public function setMouvement(?string $mouvement): static
    {
        $this->mouvement = $mouvement;

        return $this;
    }

    public function getClient(): ?string
    {
        return $this->client;
    }

    public function setClient(?string $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getDestination(): ?string
    {
        return $this->destination;
    }

    public function setDestination(?string $destination): static
    {
        $this->destination = $destination;

        return $this;
    }

    public function getProvenance(): ?string
    {
        return $this->provenance;
    }

    public function setProvenance(?string $provenance): static
    {
        $this->provenance = $provenance;

        return $this;
    }

    public function getTransporteur(): ?string
    {
        return $this->transporteur;
    }

    public function setTransporteur(?string $transporteur): static
    {
        $this->transporteur = $transporteur;

        return $this;
    }

    public function getChauffeur(): ?string
    {
        return $this->chauffeur;
    }

    public function setChauffeur(?string $chauffeur): static
    {
        $this->chauffeur = $chauffeur;

        return $this;
    }

    public function getImmatriculation(): ?string
    {
        return $this->immatriculation;
    }

    public function setImmatriculation(?string $immatriculation): static
    {
        $this->immatriculation = $immatriculation;

        return $this;
    }

    public function getRemorque(): ?string
    {
        return $this->remorque;
    }

    public function setRemorque(?string $remorque): static
    {
        $this->remorque = $remorque;

        return $this;
    }

    public function getPeseur(): ?string
    {
        return $this->peseur;
    }

    public function setPeseur(?string $peseur): static
    {
        $this->peseur = $peseur;

        return $this;
    }

    public function getContactPl(): ?string
    {
        return $this->contactPl;
    }

    public function setContactPl(?string $contactPl): static
    {
        $this->contactPl = $contactPl;

        return $this;
    }

    public function getConteneur(): ?string
    {
        return $this->conteneur;
    }

    public function setConteneur(?string $conteneur): static
    {
        $this->conteneur = $conteneur;

        return $this;
    }

    public function getPlomb(): ?string
    {
        return $this->plomb;
    }

    public function setPlomb(?string $plomb): static
    {
        $this->plomb = $plomb;

        return $this;
    }

    public function getPoids1(): ?int
    {
        return $this->poids1;
    }

    public function setPoids1(?int $poids1): static
    {
        $this->poids1 = $poids1;

        return $this;
    }

    public function getPoids2(): ?int
    {
        return $this->poids2;
    }

    public function setPoids2(?int $poids2): static
    {
        $this->poids2 = $poids2;

        return $this;
    }

    public function getPoidsbrut(): ?int
    {
        return $this->poidsbrut;
    }

    public function setPoidsbrut(?int $poidsbrut): static
    {
        $this->poidsbrut = $poidsbrut;

        return $this;
    }

    public function getPoidsnet(): ?int
    {
        return $this->poidsnet;
    }

    public function setPoidsnet(?int $poidsnet): static
    {
        $this->poidsnet = $poidsnet;

        return $this;
    }

    public function getPeseeAt1(): ?\DateTimeImmutable
    {
        return $this->peseeAt1;
    }

    public function setPeseeAt1(?\DateTimeImmutable $peseeAt1): static
    {
        $this->peseeAt1 = $peseeAt1;

        return $this;
    }

    public function getPeseeAt2(): ?\DateTimeImmutable
    {
        return $this->peseeAt2;
    }

    public function setPeseeAt2(?\DateTimeImmutable $peseeAt2): static
    {
        $this->peseeAt2 = $peseeAt2;

        return $this;
    }

    public function getStatut(): StatutOperation
    {
        return $this->statut;
    }

    public function setStatut(StatutOperation $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function estTerminee(): bool
    {
        return $this->statut->estTerminee();
    }

    public function getPrixunitaire(): ?int
    {
        return $this->prixunitaire;
    }

    public function getMontantdu(): ?int
    {
        return $this->montantdu;
    }

    public function getMontantpaye(): int
    {
        return $this->montantpaye;
    }

    public function setMontantpaye(int $montantpaye): static
    {
        $this->montantpaye = $montantpaye;

        return $this;
    }

    public function getStatutReglement(): StatutReglement
    {
        return $this->statutReglement;
    }

    public function setStatutReglement(StatutReglement $statutReglement): static
    {
        $this->statutReglement = $statutReglement;

        return $this;
    }

    /*
        - La valorisation vit ICI et non dans un service : elle ne dépend que de la pesée et de ses deux relations. La règle du patron : « quand on a le poids net on calcule par le prix unitaire du produit, mais on vérifie d'abord si le fournisseur a un prix spécial ».
        - Appelée par la synchronisation, et par tout écran qui retarife un produit ou un planteur.

        @return bool vrai si le montant a changé
    */
    public function calculerMontant(): bool
    {
        if($this->montantpaye > 0) {
            return false; // Déjà réglée, même partiellement : de l'argent est parti sur la base de ce montant, un changement de tarif ne réécrit pas l'histoire
        }

        // Le prix négocié avec le planteur l'emporte sur le tarif du produit
        $prixunitaire = $this->estTerminee()
            ? ($this->fournisseur?->getPrixspeciale() ?? $this->produit?->getPrix())
            : null;

        $montantdu = ($prixunitaire !== null && $this->poidsnet !== null) ? $this->poidsnet * $prixunitaire : null;

        if($this->prixunitaire === $prixunitaire && $this->montantdu === $montantdu) {
            return false;
        }

        $this->prixunitaire = $prixunitaire;
        $this->montantdu = $montantdu;
        $this->statutReglement = StatutReglement::depuis($montantdu, $this->montantpaye);

        return true;
    }

    /*
        - Ce qu'il reste à verser au planteur. Jamais négatif : si un tarif a baissé après un versement, le trop-perçu ne doit pas se transformer en dette du planteur.
    */
    #[Groups(['read:Operation'])]
    public function getResteapayer(): int
    {
        return $this->montantdu === null ? 0 : max(0, $this->montantdu - $this->montantpaye);
    }

    /*
        - Conditions pour qu'un versement soit possible. Le contrôle du solde du site n'est pas ici : il dépend de la caisse, pas de la pesée.
    */
    public function estPayable(): bool
    {
        return $this->estTerminee()
            && $this->deletedAt === null
            && $this->getResteapayer() > 0
            && $this->fournisseur !== null
            && $this->fournisseur->estPayable();
    }

    /**
     * @return Collection<int, Paiement>
     */
    public function getPaiements(): Collection
    {
        return $this->paiements;
    }

    public function getSyncAt(): ?\DateTimeImmutable
    {
        return $this->syncAt;
    }

    public function setSyncAt(?\DateTimeImmutable $syncAt): static
    {
        $this->syncAt = $syncAt;

        return $this;
    }

    #[Groups(['read:Operation'])]
    public function getStatutLibelle(): string
    {
        return $this->statut->libelle();
    }

    #[Groups(['read:Operation'])]
    public function getStatutReglementLibelle(): string
    {
        return $this->statutReglement->libelle();
    }

    public function __toString(): string
    {
        return $this->numticket ?? $this->codepesee ?? '';
    }
}
