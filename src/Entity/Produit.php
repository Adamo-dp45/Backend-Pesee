<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\FreeTextQueryFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrFilter;
use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\NumericFilter;
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

    - PROPRIÉTÉ DU PRIX : LE WEB, ET LUI SEUL. Le poste pese ; il ne tarife pas. Un prix qui arriverait dans une charge utile de synchronisation est lu et jeté — la valorisation se fait ici, avec le prix d'ici.

      Les deux côtés ont longtemps fait autorité, arbitrés par détection de changement sur un 'prixPoste' qui mémorisait le dernier envoi. Cet arbitrage a disparu avec sa raison d'être : le paiement se décide sur le web, donc le prix qui le fonde aussi. Deux sources pour un chiffre qui sort de l'argent, c'était une divergence qu'aucun écran n'affichait.

      CE QUE ÇA CHANGE AU QUOTIDIEN : un produit créé à la volée par le peseur naît à zéro et ses pesées ne valent rien tant que personne ne l'a tarifé au bureau. C'était déjà vrai quand le poste se taisait ; c'est désormais la règle. D'où le filtre « non tarifés » sur la liste des produits, qui est là pour qu'on ne les découvre pas au guichet.
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
#[ApiFilter(SearchFilter::class, properties: ['libelle' => 'partial', 'site' => 'exact'])]
/*
    - 'actif' est un BOOLÉEN, et 'SearchFilter' n'en sait rien : il comparait la chaîne reçue à ce
      que porte la colonne, un tinyint. 'actif=1' répondait donc, 'actif=true' et 'actif=false'
      — les deux valeurs que l'écran envoie — ne correspondaient à rien, et la liste se vidait
      sur les DEUX choix. Aucune erreur nulle part : un filtre qui ne rend rien ressemble à un
      référentiel vide.
    - 'BooleanFilter' est fait pour ça, et accepte aussi bien 'true'/'false' que '1'/'0'.
*/
#[ApiFilter(BooleanFilter::class, properties: ['actif'])]
/*
    - Le prix en filtre sert à UNE question, devenue quotidienne depuis que le poste ne tarife
      plus : quels produits attendent leur prix ? Ils naissent tous à zéro, leurs pesées ne valent
      rien, et personne ne s'en aperçoit avant le guichet. 'prix=0' les rassemble.
*/
#[ApiFilter(NumericFilter::class, properties: ['prix'])]
/*
    - UN champ de saisie, PLUSIEURS colonnes en OU. Les écrans annoncent « Libellé ou code » dans leur
      champ de recherche ; avec un filtre par colonne, une seule répondait et le code ne trouvait
      jamais rien — sans message, comme s'il n'existait pas.
    - 'FreeTextQueryFilter' rassemble les colonnes, 'OrFilter' les met en OU plutôt qu'en ET, et
      'PartialSearchFilter' cherche le fragment sans tenir compte de la casse.
*/
#[QueryParameter(
    key: 'recherche',
    filter: new FreeTextQueryFilter(new OrFilter(new PartialSearchFilter())),
    properties: ['libelle', 'codeproduit'],
)]
#[ApiFilter(OrderFilter::class, properties: ['codeproduit', 'libelle', 'prix', 'createdAt'])]
class Produit extends EntityBase implements SiteOwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:Produit', 'read:Operation', 'read:Fournisseur:item', 'read:DemandePrelevement'])]
    private ?int $id = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['read:Produit', 'write:Produit'])]
    private ?string $codeproduit = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le libellé du produit est obligatoire')]
    #[Assert\Length(min: 1, max: 255)]
    #[Groups(['read:Produit', 'write:Produit', 'read:Operation', 'read:Fournisseur:item', 'read:DemandePrelevement'])]
    private ?string $libelle = null;

    #[ORM\Column(length: 255)]
    private ?string $cle = null; // Clé de rapprochement normalisée dérivée du libellé, voir 'Cle'. C'est elle qui porte l'index unique

    /*
        - Prix unitaire au kilogramme, en francs CFA. Zéro à la création automatique : tant qu'il n'est pas renseigné, les pesées de ce produit affichent un montant nul et restent non payables, ce qui vaut mieux qu'un montant faux.
    */
    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero(message: 'Le prix ne peut pas être négatif')]
    #[Groups(['read:Produit', 'write:Produit', 'read:Operation', 'read:Fournisseur:item', 'read:DemandePrelevement'])]
    private int $prix = 0;

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
        - Tarification depuis l'interface web, seul endroit où un prix se fixe.
    */
    public function setPrix(int $prix): static
    {
        $this->prix = $prix;

        return $this;
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
