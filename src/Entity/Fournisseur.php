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
use App\Domain\Enum\ReseauMobile;
use App\Domain\Enum\StatutActivation;
use App\Domain\Support\Cle;
use App\Entity\Interface\SiteOwnedInterface;
use App\Entity\Operation as Pesee; // Aliasé : 'Operation' désigne déjà celle d'OpenAPI dans ce fichier
use App\Repository\FournisseurRepository;
use App\State\Provider\CompteurPeseesProvider;
use App\State\Processor\AuditProcessor;
use App\State\Processor\SuppressionLogiqueProcessor;
use App\Validator\UniqueParSite;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - Le planteur. Le patron emploie indifféremment « planteur » et « fournisseur » ; on garde « fournisseur » côté code puisque c'est le vocabulaire du logiciel de pesée et de sa charge utile.

    - Créé automatiquement à la synchronisation, à partir du seul libellé envoyé par le poste : d'où des contraintes volontairement souples. Aucun 'NotBlank' sur le prénom ni sur le contact, contrairement au modèle précédent qui rendait invalides les fiches issues de la synchronisation.

    - Le numéro de téléphone conditionne le paiement mobile. Une pesée dont le planteur n'a pas de numéro reste visible mais non payable, avec un motif affiché à l'écran plutôt qu'un échec au moment du versement.
*/
#[ORM\Entity(repositoryClass: FournisseurRepository::class)]
#[ORM\Index(name: 'idx_fournisseur_site_cle', fields: ['site', 'cle'])]
// Ni index unique en base, ni '#[UniqueEntity]' : voir 'Produit'
#[UniqueParSite(
    fields: ['cle'],
    message: 'Ce planteur existe déjà sur ce pont bascule (les accents et la casse ne comptent pas).',
    errorPath: 'nom'
)]
#[ApiResource(
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    normalizationContext: ['groups' => ['read:Fournisseur', 'read:Base'], 'skip_null_values' => false],
    denormalizationContext: ['groups' => ['write:Fournisseur']],
    paginationItemsPerPage: 25,
    paginationClientItemsPerPage: true,
    order: ['nom' => 'ASC'],
    operations: [
        new GetCollection(
            security: "is_granted('VOIR', 'Fournisseur')",
            provider: CompteurPeseesProvider::class, // Pose 'peseesCount' sur toute la page en une requête groupée
            openapi: new Operation(
                summary: 'Liste des planteurs',
                description: 'Les planteurs des ponts bascules de son périmètre',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get(
            security: "is_granted('VOIR', object)",
            requirements: ['id' => '\d+'],
            openapi: new Operation(
                summary: 'Le planteur',
                description: 'Permet de voir un planteur, son numéro mobile money et son prix spécial',
                security: [['bearerAuth' => []]]
            )
        ),
        new Post(
            security: "is_granted('CREER', 'Fournisseur')",
            processor: AuditProcessor::class,
            openapi: new Operation(
                summary: 'Création d\'un planteur',
                description: 'Le référentiel arrive du logiciel de pesée ; on le crée ici quand il faut renseigner un numéro avant la première pesée',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch(
            security: "is_granted('MODIFIER', object)",
            requirements: ['id' => '\d+'],
            processor: AuditProcessor::class,
            openapi: new Operation(
                summary: 'Modification d\'un planteur',
                description: 'C\'est ici qu\'on renseigne le numéro mobile money, sans lequel aucun versement n\'est possible, et le prix spécial',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch( // Verbe dédié, jamais DELETE : les pesées et les paiements référencent ce planteur
            uriTemplate: '/fournisseurs/{id}/suppression',
            security: "is_granted('SUPPRIMER', object)",
            requirements: ['id' => '\d+'],
            input: false,
            processor: SuppressionLogiqueProcessor::class,
            openapi: new Operation(
                summary: 'Mise à la corbeille d\'un planteur',
                description: 'Suppression logique. Le planteur disparaît des écrans mais ses pesées et ses versements restent intacts',
                security: [['bearerAuth' => []]]
            )
        ),
    ],
    openapi: new Operation(security: [['bearerAuth' => []]])
)]
#[ApiFilter(SearchFilter::class, properties: [
    'nom' => 'partial',
    'prenom' => 'partial',
    'contact1' => 'partial',
    'site' => 'exact',
    'statut' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['codefournisseur', 'nom', 'prixspeciale', 'createdAt'])]
class Fournisseur extends EntityBase implements SiteOwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:Fournisseur', 'read:Operation', 'read:Paiement'])]
    private ?int $id = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['read:Fournisseur', 'write:Fournisseur'])]
    private ?string $codefournisseur = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le nom du planteur est obligatoire')]
    #[Assert\Length(min: 1, max: 255)]
    #[Groups(['read:Fournisseur', 'write:Fournisseur', 'read:Operation', 'read:Paiement'])]
    private ?string $nom = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Groups(['read:Fournisseur', 'write:Fournisseur', 'read:Operation', 'read:Paiement'])]
    private ?string $prenom = null;

    #[ORM\Column(length: 255)]
    private ?string $cle = null; // Dérivée du nom ET du prénom, voir 'Cle'

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(min: 8, max: 30, minMessage: 'Le numéro doit faire au moins {{ limit }} chiffres')]
    #[Groups(['read:Fournisseur', 'write:Fournisseur', 'read:Operation', 'read:Paiement'])]
    private ?string $contact1 = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(min: 8, max: 30)]
    #[Groups(['read:Fournisseur', 'write:Fournisseur'])]
    private ?string $contact2 = null;

    #[ORM\Column(enumType: ReseauMobile::class, nullable: true)]
    #[Groups(['read:Fournisseur', 'write:Fournisseur', 'read:Operation', 'read:Paiement'])]
    private ?ReseauMobile $reseau = null;

    /*
        - Prix négocié avec ce planteur. Renseigné, il l'emporte sur le prix du produit — c'est la règle donnée par le patron. Même arbitrage poste/web que 'Produit::prix'.
    */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le prix spécial ne peut pas être négatif')]
    #[Groups(['read:Fournisseur', 'write:Fournisseur'])]
    private ?int $prixspeciale = null;

    #[ORM\Column(nullable: true)]
    private ?int $prixspecialePoste = null; // Arbitre entre les deux sources, voir 'Produit::prixPoste'

    #[ORM\Column(enumType: StatutActivation::class, options: ['default' => 'ACTIF'])]
    #[Groups(['read:Fournisseur', 'write:Fournisseur'])]
    private StatutActivation $statut = StatutActivation::ACTIF;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['read:Fournisseur', 'write:Fournisseur'])]
    private ?Site $site = null;

    /** @var Collection<int, Pesee> Jamais sérialisée : seul son compteur l'est */
    #[ORM\OneToMany(mappedBy: 'fournisseur', targetEntity: Pesee::class)]
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

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodefournisseur(): ?string
    {
        return $this->codefournisseur;
    }

    public function setCodefournisseur(?string $codefournisseur): static
    {
        $this->codefournisseur = $codefournisseur;

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this->rafraichirLaCle();
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(?string $prenom): static
    {
        $this->prenom = $prenom;

        return $this->rafraichirLaCle();
    }

    public function getCle(): ?string
    {
        return $this->cle;
    }

    #[Groups(['read:Fournisseur', 'read:Operation', 'read:Paiement'])]
    public function getNomComplet(): string
    {
        return trim(($this->nom ?? '') . ' ' . ($this->prenom ?? ''));
    }

    public function getContact1(): ?string
    {
        return $this->contact1;
    }

    public function setContact1(?string $contact1): static
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

    public function getReseau(): ?ReseauMobile
    {
        return $this->reseau;
    }

    public function setReseau(?ReseauMobile $reseau): static
    {
        $this->reseau = $reseau;

        return $this;
    }

    public function getPrixspeciale(): ?int
    {
        return $this->prixspeciale;
    }

    public function setPrixspeciale(?int $prixspeciale): static
    {
        $this->prixspeciale = $prixspeciale;

        return $this;
    }

    /** Renvoie vrai si le prix spécial EN VIGUEUR a bougé. Voir 'Produit::appliquerPrixDuPoste()'. */
    public function appliquerPrixSpecialDuPoste(?int $prix): bool
    {
        if($prix === $this->prixspecialePoste) {
            return false;
        }

        $this->prixspecialePoste = $prix;

        if($prix === $this->prixspeciale) {
            return false;
        }

        $this->prixspeciale = $prix;

        return true;
    }

    public function getPrixspecialePoste(): ?int
    {
        return $this->prixspecialePoste;
    }

    public function getStatut(): StatutActivation
    {
        return $this->statut;
    }

    public function setStatut(StatutActivation $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function estPayable(): bool
    {
        return $this->getMotifNonPayable() === null;
    }

    /*
        - La raison pour laquelle ce planteur ne peut pas être réglé, ou null s'il peut l'être. Un motif plutôt qu'un booléen : l'opérateur a le planteur en face de lui et doit savoir quoi corriger, pas seulement que « ça ne marche pas ». C'est aussi pour ça qu'il est sérialisé.
        - Le préfixe 'get' n'est pas un caprice : le sérialiseur ignore les groupes posés sur une méthode qui n'en porte pas.
    */
    #[Groups(['read:Fournisseur', 'read:Operation', 'read:Paiement'])]
    public function getMotifNonPayable(): ?string
    {
        if(!$this->statut->estActif()) {
            return 'Ce planteur est suspendu.';
        }

        if($this->contact1 === null || $this->contact1 === '') {
            return 'Ce planteur n\'a pas de numéro de téléphone : le paiement mobile est impossible.';
        }

        return null;
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

    // Le nom et le prénom composent la clé : elle doit suivre les deux
    private function rafraichirLaCle(): static
    {
        $this->cle = Cle::depuis($this->nom, $this->prenom);

        return $this;
    }

    /*
        - Le nombre de pesées de ce planteur. Devant un référentiel, c'est la seule question qui compte : est-il utilisé ? Un planteur à zéro pesée se supprime sans conséquence.
        - Sur une liste, la valeur vient du provider, qui l'a obtenue en UNE requête groupée pour toute la page. Le repli ci-dessous ne sert qu'à la fiche : 'matching()' sur une collection non chargée fait un COUNT SQL, et n'hydrate jamais les pesées.
    */
    #[Groups(['read:Fournisseur'])]
    public function getPeseesCount(): int
    {
        return $this->peseesCount
            ?? $this->pesees->matching(Criteria::create()->where(Criteria::expr()->isNull('deletedAt')))->count();
    }

    public function __toString(): string
    {
        return $this->getNomComplet();
    }
}
