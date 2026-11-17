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
use App\Domain\Enum\StatutPaiement;
use App\Domain\Enum\StatutReglement;
use App\Entity\Interface\SiteOwnedInterface;
use App\Repository\OperationRepository;
use App\State\Provider\MontantEnVolProvider;
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
            provider: MontantEnVolProvider::class, // Pose 'montantenvol' sur toute la page en une requête groupée
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
        - Prix du produit au moment du calcul, figé sur la pesée pour que l'historique reste lisible même si les tarifs changent ensuite. 'null' veut dire « produit pas encore tarifé », et la pesée ne vaut alors rien.
    */
    #[ORM\Column(nullable: true)]
    #[Groups(['read:Operation'])]
    private ?int $prixunitaire = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:Operation'])]
    private ?int $montantdu = null; // La RÉCOLTE seule. Le transport est à part, juste en dessous

    /*
        - Le transport, figé comme le reste : le prix au kilo du planteur au moment de la pesée, et ce qu'il donne sur ce poids-là. Certains planteurs amènent eux-mêmes leur récolte, et ce trajet leur est payé EN PLUS d'elle.

        - Il n'entre PAS dans 'montantdu', et c'est la décision centrale : 'montantdu' reste ce que vaut la récolte. C'est sur elle que se calcule la mise en compte, et c'est elle que comptent la production et le bilan — un transport mélangé à la valeur du produit fausserait les trois. Le transport s'ajoute au PAYABLE, c'est-à-dire à ce qu'on remet au guichet.

        - 'montanttransport' n'est pas nullable, contrairement à 'montantdu' : zéro et « pas de transport » sont ici la même chose, alors qu'un montant dû nul veut dire « produit pas encore tarifé ». Il n'y a donc rien à distinguer.
    */
    #[ORM\Column(nullable: true)]
    #[Groups(['read:Operation'])]
    private ?int $prixtransport = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['read:Operation'])]
    private int $montanttransport = 0;

    /*
        - Le cumul des paiements CONFIRMÉS, et sa ventilation. Dénormalisés : sans eux, lister des pesées imposerait une sous-requête de somme par ligne.

        - DEUX PARTS, parce qu'une pesée se règle désormais par la récolte, par le transport, ou par les deux, chacune en plusieurs fois. 'montantpaye' reste leur somme : il est seul à figurer dans les agrégats du bilan et dans les filtres de retarification, et le découper partout n'apprendrait rien à ces écrans-là.

        - Les trois sont posés ENSEMBLE par 'PaiementService::recalculerLeReglement()', en une requête : trois requêtes séparées laisseraient une fenêtre où un webhook confirme entre deux, et la base porterait 'montantpaye ≠ produit + transport' sans que rien ne le signale.
    */
    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['read:Operation'])]
    private int $montantpaye = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['read:Operation'])]
    private int $montantpayeproduit = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['read:Operation'])]
    private int $montantpayetransport = 0;

    #[ORM\Column(enumType: StatutReglement::class, options: ['default' => 'NON_PAYE'])]
    #[Groups(['read:Operation'])]
    private StatutReglement $statutReglement = StatutReglement::NON_PAYE;

    /*
        - Ce qui est mis de côté sur l'épargne du planteur, et le taux au kilo qui l'a produit. Le taux est figé à côté du montant pour la même raison que 'prixunitaire' : une retenue de 7 500 F dont personne ne sait si c'était 15 F × 500 kg ou 5 F × 1 500 kg est un chiffre inexplicable devant un planteur qui conteste.

        - NULLABLE, et c'est structurant : 'null' veut dire « jamais arbitrée », '0' veut dire « arbitrée à taux nul ». Avec un défaut à zéro on ne distingue plus les deux, et un lot rejoué par le poste créditerait deux fois — ou jamais. C'est la leçon déjà payée sur le prix d'un produit, rappelée plus bas.

        - Écrites par 'EpargneService' SEUL, via 'figerLaRetenue()'. Il n'existe pas de setter, tout comme il n'existe ni 'setMontantdu()' ni 'setPrixunitaire()' : ces valeurs-là ne se posent pas à la main.
    */
    #[ORM\Column(nullable: true)]
    #[Groups(['read:Operation'])]
    private ?int $tauxRetenue = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:Operation'])]
    private ?int $retenue = null;

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

    /*
        - Posés par 'MontantEnVolProvider' sur une LISTE : une seule requête groupée pour toute la
          page, sur le modèle de 'Produit::$peseesCount'. Nuls sur une fiche, où le repli additionne
          les versements déjà chargés pour l'échéancier.
        - PAR PART, comme le reste : un versement en vol sur le transport ne doit pas amputer ce
          qu'on peut encore engager sur la récolte. Sans cette séparation, l'API refuserait « un
          versement est déjà en cours » pour une part sur laquelle rien n'est parti.
    */
    private ?int $montantenvolproduit = null;
    private ?int $montantenvoltransport = null;

    public function setMontantsenvol(int $produit, int $transport): static
    {
        $this->montantenvolproduit = $produit;
        $this->montantenvoltransport = $transport;

        return $this;
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

    public function getMontantpayeproduit(): int
    {
        return $this->montantpayeproduit;
    }

    public function getMontantpayetransport(): int
    {
        return $this->montantpayetransport;
    }

    /*
        - LES TROIS D'UN SEUL GESTE, et c'est pour ça qu'il n'existe pas de 'setMontantpaye()'. Les
          poser séparément permettrait d'écrire un total qui ne soit pas la somme de ses parts, ce
          qu'aucun écran ne saurait montrer et qu'aucune erreur ne lèverait.
    */
    public function setMontantspayes(int $produit, int $transport): static
    {
        $this->montantpayeproduit = $produit;
        $this->montantpayetransport = $transport;
        $this->montantpaye = $produit + $transport;

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
        - La valorisation vit ICI et non dans un service : elle ne dépend que de la pesée et de ses deux relations. La règle du patron : quand on a le poids net, la récolte vaut le prix du produit au kilo, et le transport du planteur s'ajoute par-dessus.

        - DEUX nombres, jamais un seul. Les additionner ici ferait disparaître le transport des écrans, gonflerait la valeur de la récolte dans la production et le bilan, et surtout élargirait l'assiette de la mise en compte — on prélèverait l'épargne du planteur sur son propre transport.

        - Appelée par la synchronisation, et par tout écran qui retarife un produit ou un planteur.

        - DEUX VERROUS, UN PAR PART, et non plus un seul sur le cumul. Payer le transport d'une pesée dont le produit n'est pas encore tarifé est désormais permis : avec un verrou global, ce versement gèlerait POUR TOUJOURS la valorisation de sa récolte, et la tarification faite au bureau n'arriverait jamais jusqu'à elle.

        - Chaque verrou porte sur l'ENGAGÉ et non sur le seul confirmé : un versement parti sans réponse de la passerelle a déjà quitté la caisse sur la base de ce montant-là.

        - UNE PESÉE ARBITRÉE NE SE REVALORISE PLUS, retenue nulle comprise : une promesse a été écrite au planteur sur la base de ce montant. Ce garde-fou ne vivait jusqu'ici que dans 'findARevaloriser()', donc côté web seulement ; le poste, lui, réécrivait le montant d'une pesée arbitrée à chaque renvoi de son lot. Les deux chemins tranchent désormais pareil.

        @return bool vrai si quelque chose a changé
    */
    public function calculerMontant(): bool
    {
        if($this->retenue !== null) {
            return false;
        }

        $gelProduit = $this->montantpayeproduit > 0 || $this->getMontantenvolproduit() > 0;
        $gelTransport = $this->montantpayetransport > 0 || $this->getMontantenvoltransport() > 0;

        $terminee = $this->estTerminee();

        $prixunitaire = $gelProduit ? $this->prixunitaire : ($terminee ? $this->produit?->getPrix() : null);
        $prixtransport = $gelTransport ? $this->prixtransport : ($terminee ? $this->fournisseur?->getPrixtransport() : null);

        $montantdu = $gelProduit
            ? $this->montantdu
            : (($prixunitaire !== null && $this->poidsnet !== null) ? $this->poidsnet * $prixunitaire : null);

        $montanttransport = $gelTransport
            ? $this->montanttransport
            : (($prixtransport !== null && $this->poidsnet !== null) ? $this->poidsnet * $prixtransport : 0);

        if($this->prixunitaire === $prixunitaire && $this->montantdu === $montantdu
            && $this->prixtransport === $prixtransport && $this->montanttransport === $montanttransport) {
            return false;
        }

        $this->prixunitaire = $prixunitaire;
        $this->montantdu = $montantdu;
        $this->prixtransport = $prixtransport;
        $this->montanttransport = $montanttransport;

        // SUR LES CHAMPS ÉCRITS, et non sur les variables locales : avec des verrous par part, les deux peuvent désormais diverger
        $this->statutReglement = StatutReglement::pourLesParts(
            $this->montantdu,
            $this->getPayableproduit(),
            $this->montantpayeproduit,
            $this->getPayabletransport(),
            $this->montantpayetransport,
        );

        return true;
    }

    public function getPrixtransport(): ?int
    {
        return $this->prixtransport;
    }

    public function getMontanttransport(): int
    {
        return $this->montanttransport;
    }

    public function getTauxRetenue(): ?int
    {
        return $this->tauxRetenue;
    }

    public function getRetenue(): ?int
    {
        return $this->retenue;
    }

    /*
        - Arbitre la part mise en compte, UNE FOIS POUR TOUTES. Appelée par 'EpargneService' seul, et après l'écriture de la ligne du livre d'épargne : ce champ n'est qu'une dénormalisation de cette ligne, comme 'montantpaye' l'est de la somme des versements.

        - Elle LÈVE au second appel au lieu de revenir en silence. Un second appel signifie qu'on s'apprêtait à écrire une deuxième ligne sur la même pesée, donc à créditer deux fois le planteur : une exception ici donne un bien meilleur message que la violation d'index qui suivrait.

        - Un taux nul est une réponse comme une autre : elle ferme la pesée. C'est ainsi que la règle « seulement les pesées à venir » tient sans la moindre comparaison de dates — une pesée arbitrée à zéro ne sera jamais reprise.
    */
    public function figerLaRetenue(?int $taux, int $retenue): static
    {
        if($this->retenue !== null) {
            throw new \LogicException(sprintf('La retenue de la pesée #%d est déjà arbitrée : elle ne se réécrit pas.', (int) $this->id));
        }

        $this->tauxRetenue = $taux;
        $this->retenue = max(0, $retenue);

        return $this;
    }

    /*
        - Vrai quand la pesée attend qu'on tranche sa retenue. Le prédicat vit ici, où toutes les données sont sous la main ; l'écriture vit dans le service, qui seul sait verrouiller et transacter.

        - Il exige un montant dû : une pesée dont le produit n'est pas tarifé ne vaut rien, et on ne prélève pas sur rien. Elle restera non arbitrée jusqu'à ce qu'elle soit valorisée — et commencera alors à épargner, ce qui est bien ce qu'on veut.
    */
    public function attendUnArbitrage(): bool
    {
        return $this->retenue === null
            && $this->estTerminee()
            && $this->poidsnet !== null
            && $this->fournisseur !== null
            && $this->montantdu !== null
            && $this->montantdu > 0;
    }

    /*
        - DEUX PAYABLES, parce qu'on règle désormais la récolte, le transport, ou les deux. Ce sont eux qui bornent les versements — un par part.

        - La retenue ne mord que sur la récolte, nulle part ailleurs : le transport, le planteur l'a avancé de sa poche. C'est la même règle que celle du plafond de 'EpargneService', vue de l'autre bout.

        - Un produit NON TARIFÉ ferme la part produit et elle seule. Son transport, lui, se paie : le planteur a brûlé son carburant que la récolte ait un prix ou non, et le faire patienter jusqu'à ce que le bureau tarife était précisément ce qu'on a voulu supprimer. Ce qui empêche alors la pesée de disparaître de la file du guichet, ce n'est plus ce zéro, c'est 'StatutReglement' qui refuse de la dire soldée.

        - Le test porte sur '<= 0' et non sur 'null' : un produit créé à la volée par le peseur naît à 'prix = 0' et non à 'null'. La pesée vaut donc zéro franc sans être « non calculée », et les deux cas doivent se traiter pareil.
    */
    #[Groups(['read:Operation'])]
    public function getPayableproduit(): int
    {
        if($this->montantdu === null || $this->montantdu <= 0) {
            return 0;
        }

        return max(0, $this->montantdu - ($this->retenue ?? 0));
    }

    #[Groups(['read:Operation'])]
    public function getPayabletransport(): int
    {
        return max(0, $this->montanttransport);
    }

    /* La somme des deux, et rien d'autre : ce qu'on remettra au planteur au guichet, toutes parts confondues. */
    #[Groups(['read:Operation'])]
    public function getMontantpayable(): int
    {
        return $this->getPayableproduit() + $this->getPayabletransport();
    }

    /*
        - Ce qu'il reste à verser au planteur. Jamais négatif : si un tarif a baissé après un versement, le trop-perçu ne doit pas se transformer en dette du planteur.
        - Calculé sur le PAYABLE et non sur le montant dû : la part retenue sur l'épargne ne lui sera pas remise au guichet, elle ne peut donc pas rester dans ce qui reste à payer.
    */
    #[Groups(['read:Operation'])]
    public function getResteapayerproduit(): int
    {
        return max(0, $this->getPayableproduit() - $this->montantpayeproduit);
    }

    #[Groups(['read:Operation'])]
    public function getResteapayertransport(): int
    {
        return max(0, $this->getPayabletransport() - $this->montantpayetransport);
    }

    /* La somme des deux restes, et non le payable total moins le payé total : un trop-perçu sur une part ne doit pas éponger ce qui reste dû sur l'autre. */
    #[Groups(['read:Operation'])]
    public function getResteapayer(): int
    {
        return $this->getResteapayerproduit() + $this->getResteapayertransport();
    }

    /*
        - Ce qui a quitté la caisse du poste sans que la passerelle ait encore répondu. 'montantpaye' ne compte que le CONFIRMÉ, et c'est juste : tant qu'un versement n'est pas confirmé, il n'est pas versé. Mais l'argent, lui, est déjà sorti — et sans ce chiffre la file du guichet affichait le même reste avant et après un versement, comme si le geste n'avait rien produit.

        - Sur une liste, la valeur vient du provider, qui l'a obtenue en UNE requête groupée pour toute la page. Le repli ne sert qu'à la fiche, dont l'échéancier a de toute façon chargé les versements.
    */
    #[Groups(['read:Operation'])]
    public function getMontantenvolproduit(): int
    {
        return $this->montantenvolproduit ?? $this->sommerLesVersementsEnVol(true);
    }

    #[Groups(['read:Operation'])]
    public function getMontantenvoltransport(): int
    {
        return $this->montantenvoltransport ?? $this->sommerLesVersementsEnVol(false);
    }

    #[Groups(['read:Operation'])]
    public function getMontantenvol(): int
    {
        return $this->getMontantenvolproduit() + $this->getMontantenvoltransport();
    }

    /* La même définition du « en vol » que la requête groupée du dépôt : les deux doivent trancher pareil. */
    private function sommerLesVersementsEnVol(bool $produit): int
    {
        $total = 0;

        foreach($this->paiements as $versement) {
            if(in_array($versement->getStatut(), StatutPaiement::enCours(), true)) {
                $total += $produit ? $versement->getMontantproduit() : $versement->getMontanttransport();
            }
        }

        return $total;
    }

    /*
        - Ce qu'on peut ENCORE engager, c'est-à-dire ce que l'API acceptera. C'est le chiffre à montrer à l'opérateur : le reste comptable moins ce qui est déjà parti sans réponse.

        - Distinct de 'getResteapayer()', qui reste la créance du planteur : une pesée dont tous les versements sont en vol ne doit rien montrer à engager, sans pour autant passer pour soldée — elle ne le sera que quand la passerelle aura confirmé.
    */
    #[Groups(['read:Operation'])]
    public function getResteaengagerproduit(): int
    {
        return max(0, $this->getResteapayerproduit() - $this->getMontantenvolproduit());
    }

    #[Groups(['read:Operation'])]
    public function getResteaengagertransport(): int
    {
        return max(0, $this->getResteapayertransport() - $this->getMontantenvoltransport());
    }

    #[Groups(['read:Operation'])]
    public function getResteaengager(): int
    {
        return $this->getResteaengagerproduit() + $this->getResteaengagertransport();
    }

    /*
        - LES DEUX REFUS D'ARGENT D'UNE PART, écrits une seule fois. 'PaiementService' les compose
          lui aussi — depuis les chiffres de SA requête, qui sont à jour là où la collection de
          versements de cette entité ne l'est pas encore — et l'écran doit annoncer mot pour mot ce
          que le serveur répondra.
        - « La part %s » et non « %s » : ça évite d'accorder au féminin pour la récolte et au
          masculin pour le transport, donc d'avoir deux phrases là où une suffit.
    */
    public const PART_RECOLTE = 'récolte';
    public const PART_TRANSPORT = 'transport';
    public const MOTIF_PART_REGLEE = 'La part %s de cette pesée est déjà réglée.';
    public const MOTIF_PART_EN_COURS = 'Un versement est déjà en cours sur la part %s de cette pesée.';

    /*
        - Ce qui ferme les DEUX parts à la fois : rien de tout cela ne parle d'argent, et aucun versement ne peut partir tant que l'un d'eux tient. C'est le seul endroit où ces quatre refus sont écrits.
    */
    private function motifCommun(): ?string
    {
        if($this->deletedAt !== null) {
            return 'Cette pesée est à la corbeille.';
        }

        if(!$this->estTerminee()) {
            return 'Cette pesée n\'est pas terminée : le camion n\'est pas encore ressorti.';
        }

        if($this->fournisseur === null) {
            return 'Cette pesée n\'est rattachée à aucun planteur : personne à payer.';
        }

        // Ce qui interdit tout règlement, quel qu'en soit le mode — la suspension, et elle seule
        return $this->fournisseur->getMotifBloquant();
    }

    /*
        - Pourquoi la RÉCOLTE de cette pesée ne peut pas être réglée, ou null si elle peut l'être.

        - UN MONTANT NUL N'EST PAS UNE RÉCOLTE RÉGLÉE, et les confondre était un piège : un produit créé à la volée par le peseur naît sans prix ('Produit::$prix' vaut 0), ses pesées valent donc zéro franc. Le versement était refusé d'un « Cette pesée est déjà soldée » — l'opérateur partait chercher un versement qui n'avait jamais eu lieu, alors qu'il suffisait d'aller tarifer le produit.

        - L'ordre va du plus structurel au plus réparable : ce qui ne se rattrape pas d'abord, ce qui se corrige au bureau ensuite. Le produit non tarifé vient donc AVANT la retenue, et la retenue avant « déjà réglée », sans quoi le reste valant zéro, la branche suivante avalerait le cas.
    */
    #[Groups(['read:Operation'])]
    public function getMotifNonPayableProduit(): ?string
    {
        if(($commun = $this->motifCommun()) !== null) {
            return $commun;
        }

        if($this->montantdu === null || $this->montantdu <= 0) {
            return $this->produit === null
                ? 'Cette pesée n\'est rattachée à aucun produit : son montant ne peut pas être calculé.'
                : sprintf('Le produit « %s » n\'a pas de prix : cette pesée ne vaut rien tant qu\'il n\'est pas tarifé.', (string) $this->produit->getLibelle());
        }

        if($this->getPayableproduit() <= 0) {
            return sprintf(
                'Les %s F CFA de récolte de cette pesée sont intégralement retenus sur l\'épargne de %s : il n\'y a rien à lui verser sur la récolte.',
                number_format((int) $this->montantdu, 0, ',', ' '),
                $this->fournisseur?->getNomComplet() ?? 'ce planteur'
            );
        }

        // Mot pour mot les refus de 'PaiementService' : l'écran annonce ce que le serveur répondra, il ne le paraphrase pas
        if($this->getResteapayerproduit() <= 0) {
            return sprintf(self::MOTIF_PART_REGLEE, self::PART_RECOLTE);
        }

        if($this->getResteaengagerproduit() <= 0) {
            return sprintf(self::MOTIF_PART_EN_COURS, self::PART_RECOLTE);
        }

        return null;
    }

    /*
        - Pourquoi le TRANSPORT de cette pesée ne peut pas être réglé, ou null s'il peut l'être. La plupart des planteurs n'en ont aucun : le premier refus est donc le cas courant, pas une anomalie.
        - Aucun refus lié à la retenue ici, et c'est la règle de fond : l'épargne ne mord jamais sur le transport.
    */
    #[Groups(['read:Operation'])]
    public function getMotifNonPayableTransport(): ?string
    {
        if(($commun = $this->motifCommun()) !== null) {
            return $commun;
        }

        if($this->getPayabletransport() <= 0) {
            return 'Aucun transport n\'est payé à ce planteur sur cette pesée.';
        }

        if($this->getResteapayertransport() <= 0) {
            return sprintf(self::MOTIF_PART_REGLEE, self::PART_TRANSPORT);
        }

        if($this->getResteaengagertransport() <= 0) {
            return sprintf(self::MOTIF_PART_EN_COURS, self::PART_TRANSPORT);
        }

        return null;
    }

    /*
        - Conditions pour qu'un versement soit possible, toutes parts confondues. Le contrôle du solde du site n'est pas ici : il dépend de la caisse, pas de la pesée.
    */
    public function estPayable(): bool
    {
        return $this->getMotifNonPayable() === null;
    }

    /*
        - Le motif GLOBAL : null dès qu'UNE part est ouverte. C'est lui que lit le bouton « Verser », et c'est tout ce qu'il a besoin de savoir — l'écran de versement, lui, affiche le motif de chaque part.

        - Les deux parts fermées, il faut en choisir un, et un message qui nomme quelque chose à corriger gagne sur un message qui ne fait que constater : c'est toujours celui de la récolte, sauf quand la pesée est réellement soldée des deux côtés.

        - « Déjà soldée » exige que la pesée soit TARIFÉE et qu'il y ait eu quelque chose à verser. Sans ces deux gardes, une récolte sans prix dont on vient de payer le transport s'annoncerait soldée, et l'opérateur repartirait chercher un versement qui n'a jamais eu lieu — exactement le piège que ce code existe pour fermer.

        - Le motif MOBILE du planteur — numéro absent ou illisible — ne figure volontairement nulle part ici : il ferme un mode de règlement, pas le règlement. Un planteur sans téléphone se paie en espèces.

        - Le préfixe 'get' n'est pas un caprice : le sérialiseur ignore les groupes posés sur une méthode qui n'en porte pas.
    */
    #[Groups(['read:Operation'])]
    public function getMotifNonPayable(): ?string
    {
        $produit = $this->getMotifNonPayableProduit();
        $transport = $this->getMotifNonPayableTransport();

        if($produit === null || $transport === null) {
            return null;
        }

        if(($commun = $this->motifCommun()) !== null) {
            return $commun;
        }

        if($this->montantdu !== null && $this->montantdu > 0
            && $this->getMontantpayable() > 0 && $this->getResteapayer() <= 0) {
            return 'Cette pesée est déjà soldée.';
        }

        return $produit;
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
