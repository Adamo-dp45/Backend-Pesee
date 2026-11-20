<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\FreeTextQueryFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use App\Domain\Support\Cle;
use App\Entity\Dto\TarifLotInput;
use App\Entity\Dto\TarifLotOutput;
use App\Entity\Interface\EntrepriseOwnedInterface;
use App\Repository\CategorieFournisseurRepository;
use App\State\Processor\TarificationProcessor;
use App\State\Processor\PerimetreEntrepriseProcessor;
use App\State\Processor\SuppressionLogiqueProcessor;
use App\State\Processor\TarifLotProcessor;
use App\State\Provider\CompteurPlanteursProvider;
use App\Validator\UniqueParEntreprise;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - UNE CLASSE DE PLANTEURS, et le prix qu'elle obtient. Tous les planteurs d'une entreprise étaient
      payés au même prix pour un même produit ; ceux qui livrent dix tonnes par passage et ceux qui en
      livrent deux cents kilos ne négocient pourtant pas la même chose. La catégorie porte cette
      différence une seule fois, au lieu d'un tarif à ressaisir planteur par planteur.

    - UNE LISTE PLATE, pas un arbre. Une hiérarchie demanderait de résoudre un héritage de tarifs sur
      plusieurs niveaux, de se défendre contre les cycles, et de répondre à « ce planteur hérite de
      quoi, au juste ? » — pour un gain que personne n'a demandé. Le jour où deux niveaux manqueront,
      ce sera un changement de modèle, pas une rustine.

    - À L'ENTREPRISE, jamais au pont bascule. C'est tout l'intérêt : un tarif de catégorie se saisit
      une fois et vaut partout. C'est aussi ce qui a obligé le PRODUIT à remonter au même niveau —
      sinon un tarif aurait visé « le cacao de Soubré », puis « celui de Gagnoa », à tenir d'accord à
      la main.

    - LA DESCRIPTION DIT SUR QUELLE BASE ON Y ENTRE : « les planteurs qui livrent plus de dix tonnes
      par passage », « les coopératives sous contrat annuel ». Facultative, mais c'est elle que lit
      celui qui classe un planteur. « Catégorie B » ne répond pas tout seul à « pourquoi celui-là et
      pas l'autre », et c'est pourtant la décision qui change ce qu'un homme touche au guichet.
*/
#[ORM\Entity(repositoryClass: CategorieFournisseurRepository::class)]
#[ORM\Index(name: 'idx_categorie_entreprise_cle', fields: ['entreprise', 'cle'])]
/*
    - Pas d'index UNIQUE en base, comme pour 'Produit' et 'Fournisseur' : une ligne à la corbeille
      garde sa clé et interdirait de recréer une catégorie supprimée.
*/
#[UniqueParEntreprise(
    fields: ['cle'],
    message: 'Cette catégorie existe déjà (les accents et la casse ne comptent pas).',
    errorPath: 'libelle'
)]
#[ApiResource(
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    normalizationContext: ['groups' => ['read:CategorieFournisseur', 'read:Base']],
    denormalizationContext: ['groups' => ['write:CategorieFournisseur']],
    paginationItemsPerPage: 25,
    paginationClientItemsPerPage: true,
    order: ['libelle' => 'ASC'],
    operations: [
        new GetCollection(
            security: "is_granted('VOIR', 'CategorieFournisseur')",
            provider: CompteurPlanteursProvider::class, // Pose 'planteursCount' sur toute la page en une requête
            openapi: new Operation(
                summary: 'Liste des catégories de planteurs',
                description: 'Les catégories de son entreprise, et le nombre de planteurs de chacune',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get(
            security: "is_granted('VOIR', object)",
            requirements: ['id' => '\d+'],
            normalizationContext: ['groups' => ['read:CategorieFournisseur', 'read:CategorieFournisseur:item', 'read:Base']],
            openapi: new Operation(
                summary: 'La catégorie',
                description: 'La catégorie, sa description et sa grille de tarifs',
                security: [['bearerAuth' => []]]
            )
        ),
        new Post(
            security: "is_granted('CREER', 'CategorieFournisseur')",
            processor: PerimetreEntrepriseProcessor::class, // Pose l'entreprise du créateur, puis délègue à 'AuditProcessor'
            openapi: new Operation(
                summary: 'Création d\'une catégorie',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch(
            security: "is_granted('MODIFIER', object)",
            requirements: ['id' => '\d+'],
            processor: TarificationProcessor::class, // Le barème de transport est ici, et il revalorise
            openapi: new Operation(
                summary: 'Modification d\'une catégorie',
                description: 'Le libellé et la description. La grille de tarifs passe par « /categorie_fournisseurs/{id}/tarifs »',
                security: [['bearerAuth' => []]]
            )
        ),
        /*
            - LA GRILLE SE SAISIT D'UN BLOC, et jamais case par case. Deux raisons, et la seconde
              est la vraie : le nombre de cases croît au produit du nombre de catégories par celui
              des produits, et surtout CHAQUE case écrite revalorise des pesées. Dix envois
              séparés, c'est dix passes de revalorisation dont aucune ne s'annule.

            - SANS 'confirmer', RIEN N'EST ÉCRIT : la réponse dit ce qui changerait et combien de
              pesées seraient touchées. L'écran le montre, et ne rappelle qu'après un oui. C'est le
              seul garde-fou possible devant un arbitrage d'épargne, qui est irréversible.

            - 'read: false' : la réponse n'est pas la ressource mais un BILAN. Le processor charge
              lui-même le porteur et vérifie son périmètre — un POST ne lit pas la ressource de son
              URI, donc 'is_granted(..., object)' n'aurait rien à juger.
        */
        new Post(
            uriTemplate: '/categorie_fournisseurs/{id}/tarifs',
            security: "is_granted('MODIFIER', 'CategorieFournisseur')",
            requirements: ['id' => '\\d+'],
            input: TarifLotInput::class,
            output: TarifLotOutput::class,
            read: false,
            normalizationContext: ['groups' => ['read:TarifLot']],
            denormalizationContext: ['groups' => ['write:TarifLot']],
            processor: TarifLotProcessor::class,
            openapi: new Operation(
                summary: 'Saisie groupée de la grille d\'une catégorie',
                description: 'Sans « confirmer », rend le bilan et le nombre de pesées concernées sans rien écrire',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch( // Verbe dédié, jamais DELETE : des planteurs et des pesées s'y réfèrent
            uriTemplate: '/categorie_fournisseurs/{id}/suppression',
            security: "is_granted('SUPPRIMER', object)",
            requirements: ['id' => '\d+'],
            input: false,
            processor: SuppressionLogiqueProcessor::class,
            openapi: new Operation(
                summary: 'Mise à la corbeille d\'une catégorie',
                description: 'Refusé tant que des planteurs y sont rattachés : le motif nomme leur nombre',
                security: [['bearerAuth' => []]]
            )
        ),
    ],
    openapi: new Operation(security: [['bearerAuth' => []]])
)]
/*
    - Un champ de saisie, deux colonnes en OU : les écrans annoncent « Libellé ou description », et
      un filtre par colonne n'en interrogerait qu'une, sans jamais le dire.
*/
#[QueryParameter(
    key: 'recherche',
    filter: new FreeTextQueryFilter(new OrFilter(new PartialSearchFilter())),
    properties: ['libelle', 'description'],
)]
#[ApiFilter(OrderFilter::class, properties: ['libelle', 'createdAt'])]
class CategorieFournisseur extends EntityBase implements EntrepriseOwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:CategorieFournisseur', 'read:Fournisseur', 'read:Fournisseur:item'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le libellé de la catégorie est obligatoire')]
    #[Assert\Length(min: 1, max: 255)]
    #[Groups(['read:CategorieFournisseur', 'write:CategorieFournisseur', 'read:Fournisseur', 'read:Fournisseur:item'])]
    private ?string $libelle = null;

    /* Sur quelle base on entre dans la catégorie. Facultative, et c'est pourtant elle qui rend la décision relisible. */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 1000)]
    #[Groups(['read:CategorieFournisseur', 'write:CategorieFournisseur'])]
    private ?string $description = null;

    #[ORM\Column(length: 255)]
    private ?string $cle = null; // Clé de rapprochement normalisée dérivée du libellé, voir 'Cle'

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Entreprise $entreprise = null; // Posée par 'PerimetreEntrepriseProcessor', jamais saisie

    /*
        - SON BARÈME DE TRANSPORT, le second saut de la résolution : un planteur sans barème propre
          prend celui de sa catégorie. C'est l'intérêt même de la catégorie, appliqué au trajet —
          « les planteurs du nord » paient tous la même route, saisie une fois.

        - Facultatif, et indépendant de la grille de tarifs : une catégorie peut décider du prix de
          la récolte sans rien dire du transport, et l'inverse.
    */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['read:CategorieFournisseur', 'write:CategorieFournisseur'])]
    private ?BaremeTransport $baremeTransport = null;

    /*
        - LA GRILLE : un prix par produit, pour les planteurs de cette catégorie. Sérialisée sur la
          FICHE seulement — une collection par ligne de liste ferait une requête par ligne.

        - 'cascade: persist' pour que le verbe de saisie groupée écrive la grille entière en une fois.
    */
    /** @var Collection<int, TarifCategorie> */
    #[ORM\OneToMany(mappedBy: 'categorie', targetEntity: TarifCategorie::class, cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $tarifs;

    /** @var Collection<int, Fournisseur> Jamais sérialisée : seul son compteur l'est */
    #[ORM\OneToMany(mappedBy: 'categorie', targetEntity: Fournisseur::class)]
    private Collection $fournisseurs;

    public function __construct()
    {
        $this->tarifs = new ArrayCollection();
        $this->fournisseurs = new ArrayCollection();
    }

    /*
        - Posé par 'CompteurPlanteursProvider' sur une LISTE, en une requête groupée. C'est le seul
          chiffre qui compte sur cet écran : une catégorie à zéro se supprime sans conséquence, une
          catégorie à deux cents décide de ce que touchent deux cents hommes.
    */
    private ?int $planteursCount = null;

    public function setPlanteursCount(int $planteursCount): static
    {
        $this->planteursCount = $planteursCount;

        return $this;
    }

    #[Groups(['read:CategorieFournisseur'])]
    public function getPlanteursCount(): int
    {
        return $this->planteursCount ?? $this->fournisseurs->filter(
            static fn(Fournisseur $planteur) => $planteur->getDeletedAt() === null
        )->count();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getCle(): ?string
    {
        return $this->cle;
    }

    public function getBaremeTransport(): ?BaremeTransport
    {
        return $this->baremeTransport;
    }

    public function setBaremeTransport(?BaremeTransport $baremeTransport): static
    {
        $this->baremeTransport = $baremeTransport;

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

    /** @return Collection<int, TarifCategorie> */
    public function getTarifs(): Collection
    {
        return $this->tarifs;
    }

    /**
     * LA GRILLE VIVANTE, et c'est elle qu'on publie.
     *
     * La collection brute porte aussi les lignes RETIRÉES : une ligne supprimée reste en base, et
     * c'est voulu — savoir qui a retiré le tarif hévéa de cette catégorie, et quand, est
     * exactement la question qu'on posera le jour où un planteur verra sa paie baisser.
     *
     * Mais elle ne s'applique plus : 'tarifPour()' l'écarte, comme toute la valorisation. La
     * publier telle quelle faisait afficher à l'écran de saisie un prix que PERSONNE ne touche —
     * on retirait un tarif, la grille continuait de le montrer, et les planteurs de la catégorie
     * étaient payés au prix du produit sans que rien ne le dise. Aucune erreur, deux lectures du
     * même état qui se contredisent.
     *
     * @return list<TarifCategorie>
     */
    /* Sur la LISTE aussi : l'écran des tarifs montre la grille de chaque catégorie, et elles se comptent sur les doigts d'une main. */
    #[Groups(['read:CategorieFournisseur', 'read:CategorieFournisseur:item'])]
    #[SerializedName('tarifs')]
    public function getTarifsVivants(): array
    {
        return array_values(array_filter(
            $this->tarifs->toArray(),
            static fn(TarifCategorie $tarif): bool => $tarif->getDeletedAt() === null
        ));
    }

    public function addTarif(TarifCategorie $tarif): static
    {
        if(!$this->tarifs->contains($tarif)) {
            $this->tarifs->add($tarif);
            $tarif->setCategorie($this);
        }

        return $this;
    }

    public function removeTarif(TarifCategorie $tarif): static
    {
        $this->tarifs->removeElement($tarif);

        return $this;
    }

    /** @return Collection<int, Fournisseur> */
    public function getFournisseurs(): Collection
    {
        return $this->fournisseurs;
    }

    /*
        - Le tarif de cette catégorie POUR CE PRODUIT, ou null. Parcourt la collection déjà chargée
          plutôt que de requêter : la valorisation traite des lots entiers, et la même catégorie y
          revient autant de fois qu'elle a de planteurs.
    */
    public function tarifPour(?Produit $produit): ?TarifCategorie
    {
        foreach($this->tarifs as $tarif) {
            if($tarif->getDeletedAt() === null && $tarif->vise($produit)) {
                return $tarif;
            }
        }

        return null;
    }

    public function __toString(): string
    {
        return (string) $this->libelle;
    }
}
