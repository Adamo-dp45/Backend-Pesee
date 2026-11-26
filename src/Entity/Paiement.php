<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
// Aliasé : 'Operation' désigne déjà une entité du domaine, la pesée
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Domain\Enum\ModeReglement;
use App\Domain\Enum\ReseauMobile;
use App\Domain\Enum\StatutPaiement;
use App\Domain\Enum\TypeCaisse;
use App\Entity\Input\PaiementInput;
use App\Entity\Input\PaiementLotInput;
use App\Entity\Output\PaiementLotOutput;
use App\Entity\Interface\SiteOwnedInterface;
use App\Repository\PaiementRepository;
use App\State\Processor\PaiementLotProcessor;
use App\State\Processor\PaiementProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/*
    - Un versement mobile money vers un planteur, adossé à une pesée précise.

    - Le paiement en plusieurs fois tombe naturellement : plusieurs 'Paiement' pointent la même 'Operation', dont le 'statutReglement' passe de NON_PAYE à PARTIEL puis SOLDE.

    - Les fonds sont débités de la caisse du site dès la création, AVANT même l'appel à la passerelle. C'est délibéré : tant qu'un versement est en vol, la somme ne doit pas pouvoir être engagée une seconde fois. En cas d'échec, une ligne de remboursement la restitue.

    - Cette entité n'est jamais supprimée, même logiquement : c'est une pièce comptable.
*/
#[ORM\Entity(repositoryClass: PaiementRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_paiement_reference', fields: ['reference'])] /*
    - Index UNIQUE assumé ici, contrairement aux référentiels : aucun soft-delete sur une pièce comptable, et c'est cet index qui rend le webhook idempotent
*/
#[ORM\Index(name: 'idx_paiement_site_statut', fields: ['site', 'statut'])]
#[ORM\Index(name: 'idx_paiement_operation', fields: ['operation'])]
#[ORM\Index(name: 'idx_paiement_fournisseur', fields: ['fournisseur'])]
#[ApiResource(
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    normalizationContext: ['groups' => ['read:Paiement', 'read:Base']],
    paginationItemsPerPage: 25,
    paginationClientItemsPerPage: true,
    order: ['createdAt' => 'DESC'],
    operations: [ // Ni Patch ni Delete : un versement est une pièce comptable, il ne se réécrit pas
        new GetCollection(
            security: "is_granted('VOIR', 'Paiement')",
            openapi: new OpenApiOperation(
                summary: 'Liste des paiements',
                description: 'Les versements des ponts bascules de son périmètre',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get(
            security: "is_granted('VOIR', object)",
            requirements: ['id' => '\d+'],
            openapi: new OpenApiOperation(
                summary: 'Le paiement',
                description: 'Permet de suivre un versement et son état chez l\'opérateur mobile',
                security: [['bearerAuth' => []]]
            )
        ),
        new Post(
            security: "is_granted('PAYER', 'Paiement')",
            input: PaiementInput::class,
            denormalizationContext: ['groups' => ['write:Paiement']],
            processor: PaiementProcessor::class,
            openapi: new OpenApiOperation(
                summary: 'Paiement d\'un planteur',
                description: 'Verse tout ou partie du montant dû sur une pesée. Les fonds sont réservés sur la caisse du site dès la création, et rendus si le versement échoue',
                security: [['bearerAuth' => []]]
            )
        ),
        /*
            - PLUSIEURS PESÉES D'UN MÊME PLANTEUR, en un geste et sous UNE transaction. Une route à
              part plutôt qu'un tableau facultatif sur la précédente : les deux ne prennent pas les
              mêmes champs — celle-ci n'accepte AUCUN montant, puisque cocher une pesée veut dire
              la régler en entier — et les mêler aurait donné un corps de requête dont la moitié
              des champs est ignorée selon l'autre moitié.

            - ELLE REND LA LISTE des versements écrits, un par pesée : c'est ce que le guichet
              imprime, et c'est aussi ce qui permet à l'écran de dire combien de lignes sont parties.
        */
        new Post(
            uriTemplate: '/paiements/lot',
            security: "is_granted('PAYER', 'Paiement')",
            input: PaiementLotInput::class,
            denormalizationContext: ['groups' => ['write:Paiement:lot']],
            output: PaiementLotOutput::class,
            processor: PaiementLotProcessor::class,
            openapi: new OpenApiOperation(
                summary: 'Versement groupé',
                description: 'Règle en une fois plusieurs pesées du même planteur, chacune pour la totalité de ce qui lui reste. La part choisie borne le geste : la récolte seule, le transport seul, ou les deux',
                security: [['bearerAuth' => []]]
            )
        ),
    ],
    openapi: new OpenApiOperation(security: [['bearerAuth' => []]])
)]
#[ApiFilter(SearchFilter::class, properties: [
    'reference' => 'partial',
    'statut' => 'exact',
    'modeReglement' => 'exact',
    'site' => 'exact',
    'fournisseur' => 'exact',
    'operation' => 'exact',
])]
#[ApiFilter(DateFilter::class, properties: ['createdAt'])]
#[ApiFilter(OrderFilter::class, properties: ['montant', 'createdAt'])]
class Paiement extends EntityBase implements SiteOwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:Paiement', 'read:MouvementCaisse', 'read:Operation:item'])]
    private ?int $id = null;

    /*
        - Référence marchand transmise à la passerelle, et clé d'idempotence du webhook : recevoir deux fois la même notification ne doit pas rejouer le mouvement de caisse.
        - Volontairement lisible et dictable au téléphone ('WP260802A1B2C3D4') plutôt qu'un ULID : en cas de litige, c'est ce que l'opérateur épellera au service client de l'agrégateur.
    */
    #[ORM\Column(length: 40)]
    #[Groups(['read:Paiement', 'read:MouvementCaisse', 'read:Operation:item'])]
    private ?string $reference = null;

    /*
        - LE TOTAL, et il le reste. C'est lui, et lui seul, que voient la caisse, la passerelle, le grand livre, le remboursement sur échec et la réconciliation : un versement est un mouvement d'argent, et un mouvement d'argent n'a qu'un montant.

        - Sa VENTILATION en dessous, parce qu'une pesée se règle désormais par la récolte, par le transport, ou par les deux. Payer les deux d'un geste reste UN versement : une référence, une sortie de caisse, un seul envoi mobile money — donc un seul frais.

        - INVARIANT : 'montant = montantproduit + montanttransport', toujours, sans exception. Un retrait d'épargne n'a pas de pesée et porte tout son montant sur la part produit : c'est ce qui permet de tenir l'invariant par une contrainte en base plutôt que par un commentaire.

        - Les trois se posent ENSEMBLE, par 'setMontants()'. Un setter par champ laisserait écrire un total qui ne soit pas la somme de ses parts.
    */
    #[ORM\Column]
    #[Groups(['read:Paiement', 'read:MouvementCaisse', 'read:Operation:item'])]
    private int $montant = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['read:Paiement', 'read:Operation:item'])]
    private int $montantproduit = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['read:Paiement', 'read:Operation:item'])]
    private int $montanttransport = 0;

    /*
        - NULLABLE depuis le retrait d'épargne : un versement ne pointe pas toujours sur une pesée. Le planteur qui vient chercher ce qu'on lui gardait est réglé par le même circuit — même référence, même passerelle, même « en vol », même remboursement sur échec — mais il n'y a aucune pesée derrière.
        - C'est ce qui a évité de réécrire une seconde fois toute la machinerie des versements pour une poignée de retraits, avec les divergences que deux chemins finissent toujours par produire.
    */
    #[ORM\ManyToOne(inversedBy: 'paiements')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['read:Paiement'])]
    private ?Operation $operation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['read:Paiement'])]
    private ?Fournisseur $fournisseur = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['read:Paiement'])]
    private ?Site $site = null;

    /*
        - COMMENT le planteur a été réglé, et par là même quelle caisse s'est vidée : espèces pour la caisse physique, mobile money pour le flotte. Voir 'ModeReglement', qui porte la correspondance.
        - Figé sur la pièce comptable : il décide du cycle de vie du versement, et le relire plus tard doit donner la même histoire. Un versement en espèces est confirmé sur-le-champ, un versement mobile attend l'agrégateur.
    */
    #[ORM\Column(enumType: ModeReglement::class, options: ['default' => 'MOBILE_MONEY'])]
    #[Groups(['read:Paiement', 'read:Operation:item'])]
    private ModeReglement $modeReglement = ModeReglement::MOBILE_MONEY;

    /*
        - Copiés du planteur au moment du versement plutôt que lus à travers la relation : si son numéro change plus tard, la pièce comptable doit continuer de dire où l'argent est parti.
        - NULLABLE depuis les espèces : des billets ne vont à aucun numéro. Exiger quand même un numéro aurait obligé à en inventer un sur les versements de la main à la main, et la pièce comptable aurait menti.
    */
    #[ORM\Column(length: 30, nullable: true)]
    #[Groups(['read:Paiement'])]
    private ?string $numeroDestinataire = null;

    #[ORM\Column(enumType: ReseauMobile::class, nullable: true)]
    #[Groups(['read:Paiement'])]
    private ?ReseauMobile $reseau = null;

    /*
        - Le numéro du chèque remis, et rien d'autre : la SEULE référence qui permettra de rapprocher
          ce versement du relevé bancaire. Deux chèques du même montant au même planteur sont
          autrement indiscernables le jour où la banque est consultée.
        - Nullable parce que les deux autres modes n'en ont pas. C'est le service qui l'exige quand
          le mode est le chèque, comme il exige un numéro de téléphone pour le mobile money.
    */
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['read:Paiement'])]
    private ?string $numeroCheque = null;

    #[ORM\Column(enumType: StatutPaiement::class, options: ['default' => 'EN_ATTENTE'])]
    #[Groups(['read:Paiement', 'read:Operation:item'])]
    private StatutPaiement $statut = StatutPaiement::EN_ATTENTE;

    #[ORM\Column(length: 50)]
    private ?string $gateway = null; // Qui a traité ce versement, pour les recherches a posteriori

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['read:Paiement'])]
    private ?string $gatewayTransactionId = null;

    /**
     * Dernière charge utile reçue de la passerelle, conservée telle quelle pour le diagnostic.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $gatewayPayload = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['read:Paiement'])]
    private ?string $messageErreur = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['read:Paiement'])]
    private ?User $initiePar = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['read:Paiement'])]
    private ?\DateTimeImmutable $confirmeAt = null;

    /*
        - Fabrique la référence unique. La part aléatoire fait 4 octets : sur le volume attendu — quelques centaines de versements par jour et par entreprise — une collision le même jour est négligeable, et l'index unique la rattraperait de toute façon.
    */
    public static function genererReference(): string
    {
        return 'WP' . date('ymd') . strtoupper(bin2hex(random_bytes(4)));
    }

    /*
        - LE JUSTIFICATIF DE CE VERSEMENT, s'il y en a un : le reçu signé du planteur, le talon du
          chèque. C'est la PIÈCE qui porte la clé — d'ici on ne fait que la lire, et aucune colonne
          ne s'ajoute à cette table d'argent.
        - Les champs de la pièce portent 'read:Paiement', sans quoi la relation ne rendrait que son
          IRI et l'écran afficherait un tiret là où il y a un document.
    */
    #[ORM\OneToOne(mappedBy: 'paiement')]
    #[Groups(['read:Paiement'])]
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

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function getMontantproduit(): int
    {
        return $this->montantproduit;
    }

    public function getMontanttransport(): int
    {
        return $this->montanttransport;
    }

    /*
        - Le seul moyen de poser un montant, et il pose les trois. Le total n'est pas un paramètre : il EST la somme, et le laisser saisir rouvrirait la porte à un versement dont les parts ne font pas le compte.
    */
    public function setMontants(int $produit, int $transport): static
    {
        $this->montantproduit = $produit;
        $this->montanttransport = $transport;
        $this->montant = $produit + $transport;

        return $this;
    }

    public function getOperation(): ?Operation
    {
        return $this->operation;
    }

    public function setOperation(?Operation $operation): static
    {
        $this->operation = $operation;

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

    public function getSite(): ?Site
    {
        return $this->site;
    }

    public function setSite(?Site $site): static
    {
        $this->site = $site;

        return $this;
    }

    public function getNumeroDestinataire(): ?string
    {
        return $this->numeroDestinataire;
    }

    public function setNumeroDestinataire(?string $numeroDestinataire): static
    {
        $this->numeroDestinataire = $numeroDestinataire;

        return $this;
    }

    public function getModeReglement(): ModeReglement
    {
        return $this->modeReglement;
    }

    public function setModeReglement(ModeReglement $modeReglement): static
    {
        $this->modeReglement = $modeReglement;

        return $this;
    }

    #[Groups(['read:Paiement', 'read:Operation:item'])]
    public function getModeReglementLibelle(): string
    {
        return $this->modeReglement->libelle();
    }

    /** La caisse d'où l'argent est sorti. Déduite du mode, jamais stockée à part : deux sources finiraient par diverger. */
    public function getCaisse(): TypeCaisse
    {
        return $this->modeReglement->caisse();
    }

    public function getReseau(): ?ReseauMobile
    {
        return $this->reseau;
    }

    public function setReseau(?ReseauMobile $reseau): static
    {
        $this->reseau = $reseau;

        return $this;
    }

    public function getNumeroCheque(): ?string
    {
        return $this->numeroCheque;
    }

    public function setNumeroCheque(?string $numeroCheque): static
    {
        $numeroCheque = $numeroCheque === null ? null : trim($numeroCheque);

        $this->numeroCheque = $numeroCheque === '' ? null : $numeroCheque;

        return $this;
    }

    public function getStatut(): StatutPaiement
    {
        return $this->statut;
    }

    public function setStatut(StatutPaiement $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function estFinal(): bool
    {
        return $this->statut->estFinal();
    }

    #[Groups(['read:Paiement', 'read:Operation:item'])]
    public function getStatutLibelle(): string
    {
        return $this->statut->libelle();
    }

    public function getGateway(): ?string
    {
        return $this->gateway;
    }

    public function setGateway(string $gateway): static
    {
        $this->gateway = $gateway;

        return $this;
    }

    public function getGatewayTransactionId(): ?string
    {
        return $this->gatewayTransactionId;
    }

    public function setGatewayTransactionId(?string $gatewayTransactionId): static
    {
        $this->gatewayTransactionId = $gatewayTransactionId;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getGatewayPayload(): ?array
    {
        return $this->gatewayPayload;
    }

    /**
     * @param array<string, mixed>|null $gatewayPayload
     */
    public function setGatewayPayload(?array $gatewayPayload): static
    {
        $this->gatewayPayload = $gatewayPayload;

        return $this;
    }

    public function getMessageErreur(): ?string
    {
        return $this->messageErreur;
    }

    public function setMessageErreur(?string $messageErreur): static
    {
        $this->messageErreur = $messageErreur;

        return $this;
    }

    public function getInitiePar(): ?User
    {
        return $this->initiePar;
    }

    public function setInitiePar(?User $initiePar): static
    {
        $this->initiePar = $initiePar;

        return $this;
    }

    public function getConfirmeAt(): ?\DateTimeImmutable
    {
        return $this->confirmeAt;
    }

    public function setConfirmeAt(?\DateTimeImmutable $confirmeAt): static
    {
        $this->confirmeAt = $confirmeAt;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->reference;
    }
}
