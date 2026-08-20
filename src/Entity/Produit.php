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
use App\Domain\Support\Cle;
use App\Entity\Interface\SiteOwnedInterface;
use App\Entity\Operation as Pesee; // Aliasé : 'Operation' désigne déjà celle d'OpenAPI dans ce fichier
use App\Repository\ProduitRepository;
use App\State\Provider\CompteurPeseesProvider;
use App\State\Processor\AuditProcessor;
use App\State\Processor\TarificationProcessor;
use App\State\Processor\SuppressionLogiqueProcessor;
use App\Validator\UniqueParSite;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - Produit pesé (hévéa, cacao, palme…). Rattaché au SITE et non à l'entreprise : le peseur crée ses produits localement, depuis le bouton « Créer » de l'écran de pesée. Le référentiel appartient donc au logiciel de pesée, et le web ne fait que l'enrichir — le prix, essentiellement.

    - PROPRIÉTÉ DU PRIX : les deux côtés font autorité. Le poste envoie son prix à chaque synchronisation, et le web peut le modifier. Sans arbitrage, la tarification faite en ligne serait écrasée au premier envoi suivant, et la bidirectionnalité ne marcherait que dans un sens.

      L'arbitrage se fait par détection de changement : 'prixPoste' mémorise la dernière valeur reçue du poste, et on ne réapplique celle du poste que si elle a bougé depuis. Le dernier qui modifie l'emporte, et rien à changer côté logiciel de pesée.

      Exemple : le poste envoie 400 (appliqué, prixPoste = 400) · le web passe à 500 (prix = 500, prixPoste toujours 400) · le poste renvoie 400 → ignoré, le web est préservé · le poste passe à 470 → appliqué, la valeur a changé de son côté.
*/
#[ORM\Entity(repositoryClass: ProduitRepository::class)]
#[ORM\Index(name: 'idx_produit_site_cle', fields: ['site', 'cle'])]
/*
    - Pas d'index UNIQUE en base, et c'est voulu : une ligne à la corbeille garde sa clé et entrerait en collision avec la nouvelle, ce qui interdirait de recréer un produit supprimé. L'unicité est portée par 'UniqueParSite', qui ne compare qu'aux lignes vivantes.
*/
#[UniqueParSite(
    fields: ['cle'],
    message: 'Ce produit existe déjà sur ce pont bascule (les accents et la casse ne comptent pas).',
    errorPath: 'libelle'
)]
#[ApiResource(
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    normalizationContext: ['groups' => ['read:Produit', 'read:Base'], 'skip_null_values' => false],
    denormalizationContext: ['groups' => ['write:Produit']],
    paginationItemsPerPage: 25,
    paginationClientItemsPerPage: true,
    order: ['libelle' => 'ASC'],
    operations: [
        new GetCollection(
            security: "is_granted('VOIR', 'Produit')",
            provider: CompteurPeseesProvider::class, // Pose 'peseesCount' sur toute la page en une requête groupée
            openapi: new Operation(
                summary: 'Liste des produits',
                description: 'Les produits des ponts bascules de son périmètre',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get(
            security: "is_granted('VOIR', object)",
            requirements: ['id' => '\d+'],
            openapi: new Operation(
                summary: 'Le produit',
                description: 'Permet de voir un produit et son prix au kilogramme',
                security: [['bearerAuth' => []]]
            )
        ),
        new Post(
            security: "is_granted('CREER', 'Produit')",
            processor: AuditProcessor::class,
            openapi: new Operation(
                summary: 'Création d\'un produit',
                description: 'Rarement utile : le référentiel arrive du logiciel de pesée et se crée à la synchronisation',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch(
            security: "is_granted('MODIFIER', object)",
            requirements: ['id' => '\d+'],
            processor: TarificationProcessor::class, // Retient le prix AVANT, pour le journal des activités
            openapi: new Operation(
                summary: 'Tarification d\'un produit',
                description: 'Le prix saisi ici l\'emporte, jusqu\'à ce que le poste envoie une valeur différente de la sienne',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch( // Verbe dédié, jamais DELETE : les pesées enregistrées référencent ce produit
            uriTemplate: '/produits/{id}/suppression',
            security: "is_granted('SUPPRIMER', object)",
            requirements: ['id' => '\d+'],
            input: false,
            processor: SuppressionLogiqueProcessor::class,
            openapi: new Operation(
                summary: 'Mise à la corbeille d\'un produit',
                description: 'Suppression logique. Le produit disparaît des écrans mais l\'historique des pesées reste intact',
                security: [['bearerAuth' => []]]
            )
        ),
    ],
    openapi: new Operation(security: [['bearerAuth' => []]])
)]
#[ApiFilter(SearchFilter::class, properties: ['libelle' => 'partial', 'site' => 'exact', 'actif' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['codeproduit', 'libelle', 'prix', 'createdAt'])]
class Produit extends EntityBase implements SiteOwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:Produit', 'read:Operation'])]
    private ?int $id = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['read:Produit', 'write:Produit'])]
    private ?string $codeproduit = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le libellé du produit est obligatoire')]
    #[Assert\Length(min: 1, max: 255)]
    #[Groups(['read:Produit', 'write:Produit', 'read:Operation'])]
    private ?string $libelle = null;

    #[ORM\Column(length: 255)]
    private ?string $cle = null; // Clé de rapprochement normalisée dérivée du libellé, voir 'Cle'. C'est elle qui porte l'index unique

    /*
        - Prix unitaire au kilogramme, en francs CFA. Zéro à la création automatique : tant qu'il n'est pas renseigné, les pesées de ce produit affichent un montant nul et restent non payables, ce qui vaut mieux qu'un montant faux.
    */
    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero(message: 'Le prix ne peut pas être négatif')]
    #[Groups(['read:Produit', 'write:Produit', 'read:Operation'])]
    private int $prix = 0;

    #[ORM\Column(nullable: true)]
    private ?int $prixPoste = null; // Dernier prix reçu du poste. Sert d'ARBITRE, n'est jamais affiché, et ne doit pas être confondu avec le prix en vigueur

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['read:Produit', 'write:Produit'])]
    private bool $actif = true;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['read:Produit', 'write:Produit'])]
    private ?Site $site = null; // Écrit à la création puis figé : déplacer un produit détacherait les pesées qui le référencent de leur référentiel local

    /** @var Collection<int, Pesee> Jamais sérialisée : seul son compteur l'est */
    #[ORM\OneToMany(mappedBy: 'produit', targetEntity: Pesee::class)]
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

    public function getCodeproduit(): ?string
    {
        return $this->codeproduit;
    }

    public function setCodeproduit(?string $codeproduit): static
    {
        $this->codeproduit = $codeproduit;

        return $this;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;
        $this->cle = Cle::depuis($libelle); // La clé suit toujours le libellé : elle n'a pas de setter propre

        return $this;
    }

    public function getCle(): ?string
    {
        return $this->cle;
    }

    public function getPrix(): int
    {
        return $this->prix;
    }

    /*
        - Tarification depuis l'interface web. Ne touche PAS à 'prixPoste' : c'est précisément cet écart qui permettra d'ignorer le prochain envoi du poste s'il n'a pas changé.
    */
    public function setPrix(int $prix): static
    {
        $this->prix = $prix;

        return $this;
    }

    /*
        - Prix reçu du logiciel de pesée, appliqué seulement s'il a changé depuis le dernier envoi. Sans cette comparaison, chaque synchronisation réécraserait la tarification faite en ligne avec une valeur pourtant inchangée côté poste.
        - Renvoie vrai si le prix EN VIGUEUR a bougé, ce qui permet à la synchronisation de ne recalculer les montants des pesées que lorsque c'est nécessaire.
    */
    public function appliquerPrixDuPoste(?int $prix): bool
    {
        if($prix === null || $prix < 0 || $prix === $this->prixPoste) {
            return false;
        }

        $this->prixPoste = $prix;

        if($prix === $this->prix) {
            return false;
        }

        $this->prix = $prix;

        return true;
    }

    public function getPrixPoste(): ?int
    {
        return $this->prixPoste;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

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

    /*
        - Le nombre de pesées qui référencent ce produit. Il répond à la seule question qu'on se pose devant un référentiel : est-il utilisé ? Un produit à zéro pesée se supprime sans conséquence, un produit à trois mille pesées porte tout un historique de montants.
        - Sur une liste, la valeur vient du provider, qui l'a obtenue en UNE requête groupée pour toute la page. Le repli ci-dessous ne sert qu'à la fiche : 'matching()' sur une collection non chargée fait un COUNT SQL, et n'hydrate jamais les pesées.
    */
    #[Groups(['read:Produit'])]
    public function getPeseesCount(): int
    {
        return $this->peseesCount ?? $this->pesees->matching(Criteria::create()->where(Criteria::expr()->isNull('deletedAt')))->count();
    }

    public function __toString(): string
    {
        return (string) $this->libelle;
    }
}
