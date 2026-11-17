<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Domain\Enum\StatutEntreprise;
use App\Domain\Enum\CompteMouvement;
use App\Domain\Enum\TypeCaisse;
use App\Entity\Input\MontantInput;
use App\Entity\Interface\CompteInterface;
use App\Repository\EntrepriseRepository;
use App\State\Processor\AuditProcessor;
use App\State\Processor\BasculerStatutEntrepriseProcessor;
use App\State\Processor\RechargerEntrepriseProcessor;
use App\State\Provider\MeEntrepriseProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - Le client final : une société qui exploite un ou plusieurs ponts bascules. C'est la frontière
      de cloisonnement de toute l'application.
    - Le 'codeentreprise' est l'identifiant lisible remis au client. Il ne rattache PAS les sites :
      ce lien passe par le compte machine qui pousse les pesées, sinon un code mal saisi à
      l'installation verse les pesées chez le mauvais client.
*/
#[ORM\Entity(repositoryClass: EntrepriseRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_entreprise_code', fields: ['codeentreprise'])]
#[UniqueEntity(fields: ['codeentreprise'], message: 'Ce code entreprise est déjà utilisé')]
#[ApiResource(
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    normalizationContext: ['groups' => ['read:Entreprise', 'read:Base'], 'skip_null_values' => false],
    denormalizationContext: ['groups' => ['write:Entreprise']],
    paginationItemsPerPage: 25,
    paginationClientItemsPerPage: true,
    order: ['nom' => 'ASC'],
    operations: [
        new GetCollection(
            security: "is_granted('VOIR', 'Entreprise')",
            openapi: new Operation(
                summary: 'Liste des entreprises',
                description: 'Le super administrateur les voit toutes ; un autre compte ne voit que la sienne',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get(
            security: "is_granted('VOIR', object)",
            requirements: ['id' => '\d+'],
            openapi: new Operation(
                summary: 'L\'entreprise',
                description: 'Permet de voir une entreprise et son solde global',
                security: [['bearerAuth' => []]]
            )
        ),
        /*
            - Pas de 'Post' ici, et c'est délibéré : une entreprise ne naît qu'avec son premier
              administrateur, par '/register'. La créer seule laissait un client que personne ne
              pouvait ouvrir, et qu'il fallait rattraper par une seconde manipulation.
            - Le droit 'CREER' sur cette ressource existe toujours : c'est lui que '/register' exige.
        */
        new Patch(
            security: "is_granted('MODIFIER', object)",
            requirements: ['id' => '\d+'],
            processor: AuditProcessor::class,
            openapi: new Operation(
                summary: 'Modification d\'une entreprise',
                description: 'Permet de modifier son identité et ses contacts',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch( // Bascule : action réversible et sans paramètre, donc 'input: false'
            uriTemplate: '/entreprises/{id}/activation',
            security: "is_granted('BLOQUER', object)",
            requirements: ['id' => '\d+'],
            input: false,
            processor: BasculerStatutEntrepriseProcessor::class,
            openapi: new Operation(
                summary: 'Activation ou désactivation d\'une entreprise',
                description: 'Désactiver bloque la connexion de tous ses utilisateurs, administrateurs compris. La synchronisation des postes n\'est pas interrompue',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get(
            uriTemplate: '/me/entreprise',
            security: "is_granted('ROLE_USER')",
            provider: MeEntrepriseProvider::class,
            openapi: new Operation(
                summary: 'Mon entreprise',
                description: 'Permet de voir son entreprise et son solde sans en connaître l\'identifiant',
                security: [['bearerAuth' => []]]
            )
        ),
        new Post(
            uriTemplate: '/me/entreprise/recharge',
            security: "is_granted('RECHARGER', 'Entreprise')",
            input: MontantInput::class,
            denormalizationContext: ['groups' => ['write:Montant']],
            processor: RechargerEntrepriseProcessor::class,
            openapi: new Operation(
                summary: 'Recharge du solde de l\'entreprise',
                description: 'Crédite le solde global et écrit la ligne de grand livre correspondante. C\'est de cet argent que sortent les dotations aux ponts bascules',
                security: [['bearerAuth' => []]]
            )
        ),
    ],
    openapi: new Operation(security: [['bearerAuth' => []]])
)]
#[ApiFilter(SearchFilter::class, properties: [
    'nom' => 'partial',
    'codeentreprise' => 'partial',
    'statut' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['nom', 'createdAt'])]
class Entreprise extends EntityBase implements CompteInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:Entreprise', 'read:User', 'read:Site'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le nom de l\'entreprise est obligatoire')]
    #[Assert\Length(min: 2, max: 255)]
    #[Groups(['read:Entreprise', 'write:Entreprise', 'read:User', 'read:Site'])]
    private ?string $nom = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank(message: 'Le code entreprise est obligatoire')]
    #[Assert\Regex(
        pattern: '/^[A-Z0-9]{2,20}$/',
        message: 'Le code entreprise ne peut contenir que des majuscules et des chiffres'
    )]
    #[Groups(['read:Entreprise', 'write:Entreprise', 'read:User'])]
    private ?string $codeentreprise = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Groups(['read:Entreprise', 'write:Entreprise'])]
    private ?string $adresse = null;

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank(message: 'Un contact est obligatoire')]
    #[Assert\Length(min: 8, max: 30)]
    #[Groups(['read:Entreprise', 'write:Entreprise'])]
    private ?string $contact1 = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(min: 8, max: 30)]
    #[Groups(['read:Entreprise', 'write:Entreprise'])]
    private ?string $contact2 = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: 'Cette adresse e-mail n\'est pas valide')]
    #[Groups(['read:Entreprise', 'write:Entreprise'])]
    private ?string $email = null;

    /*
        - En lecture seule côté API : un solde ne se pose pas, il résulte des mouvements de caisse. Le recharger passe par un verbe dédié qui écrit la ligne de grand livre correspondante.

        - DEUX soldes et non un : les espèces et le flotte mobile money ne se convertissent pas l'un en l'autre, et les additionner laissait croire qu'on pouvait doter le flotte d'un poste avec des billets. Voir 'TypeCaisse'.

        - En francs CFA entiers. 'BIGINT' : un solde d'entreprise dépasse vite les 2 milliards d'un INT.
    */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    #[Groups(['read:Entreprise'])]
    private int $soldePhysique = 0;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    #[Groups(['read:Entreprise'])]
    private int $soldeNumerique = 0;

    /*
        - Le compte en banque, sur lequel les chèques sont tirés. Il n'existe qu'ICI : un pont bascule n'en tient pas, et 'TypeCaisse::celles()' dit pourquoi.
        - Comme les deux autres, il ne s'écrit que par 'CaisseService' : aucun écran ne pose un solde.
    */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    #[Groups(['read:Entreprise'])]
    private int $soldeBancaire = 0;

    #[ORM\Column(enumType: StatutEntreprise::class, options: ['default' => 'ACTIVE'])]
    #[Groups(['read:Entreprise'])]
    private StatutEntreprise $statut = StatutEntreprise::ACTIVE;

    /**
     * @var Collection<int, Site>
     */
    #[ORM\OneToMany(targetEntity: Site::class, mappedBy: 'entreprise')]
    private Collection $sites;

    /**
     * @var Collection<int, User>
     */
    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'entreprise')]
    private Collection $users;

    public function __construct()
    {
        $this->sites = new ArrayCollection();
        $this->users = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getCodeentreprise(): ?string
    {
        return $this->codeentreprise;
    }

    public function setCodeentreprise(string $codeentreprise): static
    {
        $this->codeentreprise = strtoupper(trim($codeentreprise));

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getContact1(): ?string
    {
        return $this->contact1;
    }

    public function setContact1(string $contact1): static
    {
        $this->contact1 = $contact1;

        return $this;
    }

    public function getContact2(): ?string
    {
        return $this->contact2;
    }

    public function setContact2(?string $contact2): static
    {
        $this->contact2 = $contact2;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

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

    public function getSoldeBancaire(): int
    {
        return (int) $this->soldeBancaire;
    }

    public function getSoldeDe(TypeCaisse $caisse): int
    {
        return match($caisse) {
            TypeCaisse::PHYSIQUE => $this->getSoldePhysique(),
            TypeCaisse::NUMERIQUE => $this->getSoldeNumerique(),
            TypeCaisse::BANCAIRE => $this->getSoldeBancaire(),
        };
    }

    public function setSoldeDe(TypeCaisse $caisse, int $montant): static
    {
        match($caisse) {
            TypeCaisse::PHYSIQUE => $this->soldePhysique = $montant,
            TypeCaisse::NUMERIQUE => $this->soldeNumerique = $montant,
            TypeCaisse::BANCAIRE => $this->soldeBancaire = $montant,
        };

        return $this;
    }

    /*
        - Le cumul des TROIS caisses. Il se LIT — sur un tableau de bord, « ce que pèse l'entreprise » — mais il ne se DÉPENSE pas : aucune dépense ne tire sur ce total, chacune va chercher sa caisse. C'est pour cela qu'il n'a pas de setter.

        - Il est bâti sur 'celles()' plutôt que sur une addition écrite à la main : la version codée en dur avait deux termes, et l'arrivée de la banque en aurait fait un total qui n'est plus la somme des parts affichées — l'écart se serait lu sur la même ligne, sans qu'aucune erreur ne se lève.
    */
    #[Groups(['read:Entreprise'])]
    public function getSolde(): int
    {
        return array_sum(array_map($this->getSoldeDe(...), TypeCaisse::celles(CompteMouvement::ENTREPRISE)));
    }

    public function getLibelleCompte(): string
    {
        return (string) $this->nom;
    }

    public function getStatut(): StatutEntreprise
    {
        return $this->statut;
    }

    public function setStatut(StatutEntreprise $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function estActive(): bool
    {
        return $this->statut->estActive();
    }

    #[Groups(['read:Entreprise'])]
    public function getStatutLibelle(): string
    {
        return $this->statut->libelle();
    }

    /*
        - Un compteur, jamais la collection : afficher la liste des entreprises ne doit pas hydrater tous les sites de chacune. Doctrine émet un 'COUNT' SQL sur une collection non chargée.
        - Les sites à la corbeille en sont exclus, sinon une entreprise annoncerait des postes qu'aucun écran ne montre.
    */
    #[Groups(['read:Entreprise'])]
    public function getSitesCount(): int
    {
        return $this->sites->matching(Criteria::create()->where(Criteria::expr()->isNull('deletedAt')))->count();
    }

    /*
        - Le nombre de comptes humains. Les comptes machine des postes en sont exclus : ils ne se gèrent pas depuis l'écran des utilisateurs, et les compter donnerait un total que personne ne retrouve.
    */
    #[Groups(['read:Entreprise'])]
    public function getComptesCount(): int
    {
        return $this->users->matching(Criteria::create()->where(Criteria::expr()->isNull('siteMachine')))->count();
    }

    /**
     * @return Collection<int, Site>
     */
    public function getSites(): Collection
    {
        return $this->sites;
    }

    /**
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function __toString(): string
    {
        return (string) $this->nom;
    }
}
