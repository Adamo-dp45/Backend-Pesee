<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\FreeTextQueryFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrFilter;
use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Domain\Enum\StatutSite;
use App\Domain\Enum\CompteMouvement;
use App\Domain\Enum\TypeCaisse;
use App\Entity\Input\AssignerOperateurInput;
use App\Entity\Input\MontantInput;
use App\Entity\Interface\CompteInterface;
use App\Entity\Interface\EntrepriseOwnedInterface;
use App\Repository\SiteRepository;
use App\State\Provider\CompteurPeseesProvider;
use App\State\Processor\AssignerOperateurProcessor;
use App\State\Processor\AuditProcessor;
use App\State\Processor\BasculerStatutSiteProcessor;
use App\State\Processor\DoterSiteProcessor;
use App\State\Processor\RegenererIdentifiantsProcessor;
use App\State\Processor\SiteProcessor;
use App\Entity\Operation as Pesee; // Aliasé : 'Operation' désigne déjà celle d'OpenAPI dans ce fichier
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - Un pont bascule. Le 'code' est attribué par l'installateur à la mise en service du logiciel
      de pesée (par exemple 'SOF010') et sert de clé de rapprochement avec le poste.

    - Piège de nommage hérité de l'ancien modèle : ici 'code' désigne le pont bascule, alors que
      'Operation::refDesktop' désignera l'identifiant d'une pesée dans la base locale du poste.
      Les deux s'appelaient 'codesite'.

    - Le 'solde' est la caisse physique tenue par l'opérateur : c'est avec cet argent qu'il paie
      les planteurs. Elle est alimentée par les dotations de l'entreprise.
*/
#[ORM\Entity(repositoryClass: SiteRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_site_code', fields: ['code'])]
#[ORM\Index(name: 'idx_site_entreprise', fields: ['entreprise'])]
#[UniqueEntity(fields: ['code'], message: 'Ce code de pont bascule est déjà utilisé')]
#[ApiResource(
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    normalizationContext: ['groups' => ['read:Site', 'read:Base']],
    denormalizationContext: ['groups' => ['write:Site']],
    paginationItemsPerPage: 25,
    paginationClientItemsPerPage: true,
    order: ['libellesite' => 'ASC'],
    operations: [
        new GetCollection(
            security: "is_granted('VOIR', 'Site')",
            provider: CompteurPeseesProvider::class, // Pose 'peseesCount' sur toute la page en une requête groupée
            openapi: new Operation(
                summary: 'Liste des ponts bascules',
                description: 'Un opérateur ne voit que ceux dont il tient la caisse',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get(
            security: "is_granted('VOIR', object)",
            requirements: ['id' => '\d+'],
            openapi: new Operation(
                summary: 'Le pont bascule',
                description: 'Permet de voir un pont bascule, son solde et son opérateur',
                security: [['bearerAuth' => []]]
            )
        ),
        new Post( // Groupe de lecture élargi : la réponse porte les identifiants du poste, une seule fois
            security: "is_granted('CREER', 'Site')",
            normalizationContext: ['groups' => ['read:Site', 'read:Site:machine', 'read:Base']],
            processor: SiteProcessor::class,
            openapi: new Operation(
                summary: 'Mise en service d\'un pont bascule',
                description: 'Déclare le pont bascule et engendre le compte machine du poste. Le mot de passe n\'est renvoyé qu\'ici, il n\'est stocké nulle part en clair',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch(
            security: "is_granted('MODIFIER', object)",
            requirements: ['id' => '\d+'],
            processor: AuditProcessor::class,
            openapi: new Operation(
                summary: 'Modification d\'un pont bascule',
                description: 'Permet de modifier son libellé et sa localité. Ni son code, ni son entreprise : les déplacer emporterait son historique et sa caisse',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch(
            uriTemplate: '/sites/{id}/blocage',
            security: "is_granted('BLOQUER', object)",
            requirements: ['id' => '\d+'],
            input: false,
            processor: BasculerStatutSiteProcessor::class,
            openapi: new Operation(
                summary: 'Blocage ou déblocage d\'un pont bascule',
                description: 'Bloquer coupe l\'arrivée des pesées et gèle sa caisse. Le poste continue d\'enregistrer localement',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch(
            uriTemplate: '/sites/{id}/operateur',
            security: "is_granted('ATTRIBUER', object)",
            requirements: ['id' => '\d+'],
            input: AssignerOperateurInput::class,
            denormalizationContext: ['groups' => ['write:Site:operateur']],
            processor: AssignerOperateurProcessor::class,
            openapi: new Operation(
                summary: 'Affectation de l\'opérateur',
                description: 'Désigne qui tient la caisse. Un identifiant nul libère le site',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch( /*
            - 'Patch' et non 'Post' : sur un 'Post', API Platform ne charge pas l'élément, et 'object' serait nul dans l'expression de sécurité
        */
            uriTemplate: '/sites/{id}/dotation',
            security: "is_granted('ATTRIBUER', object)",
            requirements: ['id' => '\d+'],
            input: MontantInput::class,
            denormalizationContext: ['groups' => ['write:Montant']],
            processor: DoterSiteProcessor::class,
            openapi: new Operation(
                summary: 'Dotation de la caisse d\'un pont bascule',
                description: 'Transfère de l\'argent de l\'entreprise vers la caisse du site. Deux écritures : l\'argent se déplace, il ne se crée pas',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch( // Le mot de passe n'étant montré qu'une fois, le perdre se règle en le régénérant
            uriTemplate: '/sites/{id}/identifiants',
            security: "is_granted('CREER', 'Site')",
            requirements: ['id' => '\d+'],
            input: false,
            normalizationContext: ['groups' => ['read:Site', 'read:Site:machine', 'read:Base']],
            processor: RegenererIdentifiantsProcessor::class,
            openapi: new Operation(
                summary: 'Renouvellement des identifiants du poste',
                description: 'Produit un nouveau mot de passe. Les jetons déjà émis restent valables : pour couper un poste immédiatement, utiliser le blocage',
                security: [['bearerAuth' => []]]
            )
        ),
    ],
    openapi: new Operation(security: [['bearerAuth' => []]])
)]
#[ApiFilter(SearchFilter::class, properties: [
    'code' => 'partial',
    'libellesite' => 'partial',
    'localite' => 'partial',
    'statut' => 'exact',
    'entreprise' => 'exact',
    'operateur' => 'exact',
])]
// Le tri porte sur les colonnes, donc sur chaque caisse : 'solde' est un cumul calculé, que SQL ne sait pas classer
/*
    - UN champ de saisie, PLUSIEURS colonnes en OU. Les écrans annoncent « Code ou libellé » dans leur
      champ de recherche ; avec un filtre par colonne, une seule répondait et le code ne trouvait
      jamais rien — sans message, comme s'il n'existait pas.
    - 'FreeTextQueryFilter' rassemble les colonnes, 'OrFilter' les met en OU plutôt qu'en ET, et
      'PartialSearchFilter' cherche le fragment sans tenir compte de la casse.
*/
#[QueryParameter(
    key: 'recherche',
    filter: new FreeTextQueryFilter(new OrFilter(new PartialSearchFilter())),
    properties: ['libellesite', 'code'],
)]
#[ApiFilter(OrderFilter::class, properties: ['code', 'libellesite', 'soldePhysique', 'soldeNumerique', 'createdAt'])]
class Site extends EntityBase implements EntrepriseOwnedInterface, CompteInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:Site', 'read:MouvementCaisse', 'read:Operation', 'read:SynchronisationLot', 'read:Fournisseur', 'read:DemandeSolde', 'read:DemandePrelevement', 'read:MouvementEpargne', 'read:Paiement', 'read:DemandeRetrait'])]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Le code du pont bascule est obligatoire')]
    #[Assert\Length(min: 2, max: 50)]
    #[Groups(['read:Site', 'write:Site', 'read:MouvementCaisse', 'read:Operation', 'read:SynchronisationLot', 'read:Fournisseur', 'read:DemandeSolde', 'read:DemandePrelevement', 'read:MouvementEpargne', 'read:Paiement', 'read:DemandeRetrait'])]
    private ?string $code = null; // Modifiable à la création seulement : c'est la clé de rapprochement avec le poste

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le libellé du pont bascule est obligatoire')]
    #[Assert\Length(min: 2, max: 255)]
    #[Groups(['read:Site', 'write:Site', 'read:MouvementCaisse', 'read:Operation', 'read:SynchronisationLot', 'read:Fournisseur', 'read:DemandeSolde', 'read:DemandePrelevement', 'read:MouvementEpargne', 'read:Paiement', 'read:DemandeRetrait'])]
    private ?string $libellesite = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['read:Site', 'write:Site'])]
    private ?string $localite = null;

    #[ORM\ManyToOne(inversedBy: 'sites')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['read:Site', 'write:Site'])]
    private ?Entreprise $entreprise = null; // Fixé à la création : déplacer un site emporterait son historique et sa caisse

    /** @var Collection<int, Pesee> Jamais sérialisée : seul son compteur l'est */
    #[ORM\OneToMany(mappedBy: 'site', targetEntity: Pesee::class)]
    private Collection $pesees;

    public function __construct()
    {
        $this->pesees = new ArrayCollection();
    }

    /*
        - Posé par 'CompteurPeseesProvider' sur une LISTE : une seule requête groupée pour toute la
          page. Nul sur une fiche, où le repli de 'getPeseesCount()' calcule à la demande — une ligne
          seule ne justifie pas un détour par le provider.
    */
    private ?int $peseesCount = null;

    public function setPeseesCount(int $peseesCount): static
    {
        $this->peseesCount = $peseesCount;

        return $this;
    }

    /*
        - L'opérateur qui tient la caisse. Un site n'a qu'un caissier, mais un opérateur peut
          gérer plusieurs sites : d'où un ManyToOne côté site.
    */
    #[ORM\ManyToOne(inversedBy: 'sites')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['read:Site'])]
    private ?User $operateur = null;

    /*
        - Le compte machine du poste. Côté inverse : c'est 'User::siteMachine' qui porte la clé,
          parce qu'un compte machine sans site n'a aucun sens alors qu'un site peut être déclaré
          avant que son poste ne soit installé.
    */
    #[ORM\OneToOne(mappedBy: 'siteMachine')]
    private ?User $compteMachine = null;

    /*
        - Les DEUX caisses du poste : les billets dans le tiroir de l'opérateur, et le flotte mobile money. Elles ne communiquent pas — voir 'TypeCaisse'. Un seul solde obligeait à payer un planteur en espèces sur l'argent du flotte, et l'écart ne se voyait qu'en ouvrant le tiroir.
        - Les deux sont montrées à l'écran de traitement d'une demande : on décide en voyant ce que le poste a déjà, caisse par caisse.
    */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    #[Groups(['read:Site', 'read:DemandeSolde'])]
    private int $soldePhysique = 0;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    #[Groups(['read:Site', 'read:DemandeSolde'])]
    private int $soldeNumerique = 0;

    #[ORM\Column(enumType: StatutSite::class, options: ['default' => 'ACTIF'])]
    #[Groups(['read:Site'])]
    private StatutSite $statut = StatutSite::ACTIF;

    /*
        - Non persisté. Porte le mot de passe du poste le temps d'une seule réponse HTTP, à la mise en service ou au renouvellement des identifiants.
        - Le groupe 'read:Site:machine' n'est activé que par ces deux opérations : il ne peut donc pas fuiter dans une liste ni dans une fiche ordinaire.
    */
    #[Groups(['read:Site:machine'])]
    private ?string $motDePasseMachine = null;

    /*
        - L'identifiant du compte machine, posé par les mêmes opérations et dans le même groupe que le mot de passe ci-dessus : les deux vont ensemble, un écran qui affiche l'un sans l'autre ne remet rien d'utilisable à l'installateur.
        - Il se déduit du code du site, mais la règle de composition appartient à 'CompteMachineService' : l'entité le REÇOIT plutôt que de recopier le domaine, sinon les deux se contrediraient au premier changement.
    */
    #[Groups(['read:Site:machine'])]
    private ?string $identifiantMachine = null;

    /*
        - Le fichier de configuration à déposer sur le poste, composé par 'CompteMachineService' et posé ici par les deux mêmes opérations. Non persisté lui aussi : il ne contient rien que le mot de passe ci-dessus ne rende déjà sensible.
     */
    /** @var array<string, mixed>|null */
    #[Groups(['read:Site:machine'])]
    private ?array $configuration = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = strtoupper(trim($code));

        return $this;
    }

    public function getLibellesite(): ?string
    {
        return $this->libellesite;
    }

    public function setLibellesite(string $libellesite): static
    {
        $this->libellesite = $libellesite;

        return $this;
    }

    public function getLocalite(): ?string
    {
        return $this->localite;
    }

    public function setLocalite(?string $localite): static
    {
        $this->localite = $localite;

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

    public function getOperateur(): ?User
    {
        return $this->operateur;
    }

    public function setOperateur(?User $operateur): static
    {
        $this->operateur = $operateur;

        return $this;
    }

    public function getCompteMachine(): ?User
    {
        return $this->compteMachine;
    }

    public function setCompteMachine(?User $compteMachine): static
    {
        $this->compteMachine = $compteMachine;

        return $this;
    }

    public function getSoldePhysique(): int
    {
        return (int) $this->soldePhysique;
    }

    public function getSoldeNumerique(): int
    {
        return (int) $this->soldeNumerique;
    }

    /*
        - UN POSTE NE TIENT PAS DE CAISSE BANCAIRE. Le 'match' sans défaut levait déjà, mais en 'UnhandledMatchError' : une erreur de moteur, au milieu d'une transaction, sans un mot sur ce qui a été demandé. Le refus est le même, il se nomme maintenant.
    */
    public function getSoldeDe(TypeCaisse $caisse): int
    {
        $this->exigerUneCaisseDeSite($caisse);

        return match($caisse) {
            TypeCaisse::PHYSIQUE => $this->getSoldePhysique(),
            TypeCaisse::NUMERIQUE => $this->getSoldeNumerique(),
            default => 0,
        };
    }

    public function setSoldeDe(TypeCaisse $caisse, int $montant): static
    {
        $this->exigerUneCaisseDeSite($caisse);

        match($caisse) {
            TypeCaisse::PHYSIQUE => $this->soldePhysique = $montant,
            TypeCaisse::NUMERIQUE => $this->soldeNumerique = $montant,
            default => null,
        };

        return $this;
    }

    private function exigerUneCaisseDeSite(TypeCaisse $caisse): void
    {
        if(!$caisse->vitAuNiveauDuSite()) {
            throw new \LogicException(sprintf(
                'Un pont bascule ne tient pas de %s : cette caisse est à l\'entreprise seule.',
                strtolower($caisse->libelle())
            ));
        }
    }

    /*
        - Ce que tient le poste, toutes caisses confondues. Il se lit, il ne se dépense pas : un versement va chercher la caisse de son mode de règlement, jamais ce total. Pas de setter, pour la même raison.
    */
    #[Groups(['read:Site', 'read:DemandeSolde'])]
    public function getSolde(): int
    {
        return array_sum(array_map($this->getSoldeDe(...), TypeCaisse::celles(CompteMouvement::SITE)));
    }

    public function getLibelleCompte(): string
    {
        return (string) $this->libellesite;
    }

    public function getStatut(): StatutSite
    {
        return $this->statut;
    }

    public function setStatut(StatutSite $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    #[Groups(['read:Site'])]
    public function getStatutLibelle(): string
    {
        return $this->statut->libelle();
    }

    public function accepteLaSynchronisation(): bool
    {
        return $this->statut->accepteLaSynchronisation() && $this->deletedAt === null;
    }

    public function getMotDePasseMachine(): ?string
    {
        return $this->motDePasseMachine;
    }

    public function setMotDePasseMachine(?string $motDePasseMachine): static
    {
        $this->motDePasseMachine = $motDePasseMachine;

        return $this;
    }

    public function getIdentifiantMachine(): ?string
    {
        return $this->identifiantMachine;
    }

    public function setIdentifiantMachine(?string $identifiantMachine): static
    {
        $this->identifiantMachine = $identifiantMachine;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getConfiguration(): ?array
    {
        return $this->configuration;
    }

    /**
     * @param array<string, mixed>|null $configuration
     */
    public function setConfiguration(?array $configuration): static
    {
        $this->configuration = $configuration;

        return $this;
    }

    /*
        - Le nombre de pesées reçues par ce poste. C'est le signe de vie du pont bascule : un poste à zéro pesée n'a jamais synchronisé, et le solde de sa caisse ne dit rien de son activité.
        - Sur une liste, la valeur vient du provider, qui l'a obtenue en UNE requête groupée pour toute la page. Le repli ci-dessous ne sert qu'à la fiche : 'matching()' sur une collection non chargée fait un COUNT SQL, et n'hydrate jamais les pesées.
    */
    #[Groups(['read:Site'])]
    public function getPeseesCount(): int
    {
        return $this->peseesCount
            ?? $this->pesees->matching(Criteria::create()->where(Criteria::expr()->isNull('deletedAt')))->count();
    }

    public function __toString(): string
    {
        return (string) $this->libellesite;
    }
}
