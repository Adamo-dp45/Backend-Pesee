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
use App\Domain\Enum\ReseauMobile;
use App\Domain\Enum\StatutActivation;
use App\Domain\Support\Cle;
use App\Domain\Support\TransportApplicable;
use App\Domain\Enum\OrigineTransport;
use App\Domain\Support\NumeroMobile;
use App\Entity\Dto\TarifLotInput;
use App\Entity\Dto\TarifLotOutput;
use App\Entity\Interface\SiteOwnedInterface;
use App\Entity\Output\TarifsApplicablesOutput;
use App\Filter\AvecTarifPropreFilter;
use App\Filter\SansTransportFilter;
use App\Entity\Operation as Pesee; // Aliasé : 'Operation' désigne déjà celle d'OpenAPI dans ce fichier
use App\Repository\FournisseurRepository;
use App\State\Provider\CompteurPeseesProvider;
use App\State\Provider\TarifsApplicablesProvider;
use App\Entity\Input\PrelevementInput;
use App\State\Processor\BasculerPrelevementProcessor;
use App\State\Processor\AuditProcessor;
use App\State\Processor\TarificationProcessor;
use App\State\Processor\SuppressionLogiqueProcessor;
use App\State\Processor\TarifLotProcessor;
use App\Validator\NumeroMobileValide;
use App\Validator\UniqueParSite;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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
    normalizationContext: ['groups' => ['read:Fournisseur', 'read:Base']],
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
        /*
            - LA LISTE DES PLANTEURS TARIFÉS À PART, avec leurs tarifs. Une opération à elle seule,
              et non un champ de plus sur la collection ordinaire : la grille d'un planteur se lit
              à travers une relation, donc la publier partout ferait une requête par ligne sur
              CHAQUE liste de planteurs — listes déroulantes de filtres comprises, qui les chargent
              tous.

            - Elle se lit avec 'avecTarifPropre=true', qui écarte aussi les tarifs RETIRÉS. Sans le
              filtre, l'opération rendrait tous les planteurs et retomberait dans le défaut qu'elle
              existe pour éviter.
        */
        new GetCollection(
            uriTemplate: '/fournisseurs/tarifes',
            security: "is_granted('VOIR', 'Fournisseur')",
            normalizationContext: ['groups' => ['read:Fournisseur', 'read:Fournisseur:tarifs', 'read:Base']],
            provider: CompteurPeseesProvider::class,
            openapi: new Operation(
                summary: 'Les planteurs et leurs tarifs propres',
                description: 'La même liste que /fournisseurs, enrichie de la grille propre de chaque planteur. À lire avec avecTarifPropre=true',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get( /*
            - La fiche embarque les prélèvements, que la LISTE n'a pas : une collection sérialisée sur
              chaque ligne tirerait une requête par planteur. C'est le même raisonnement que pour le
              solde d'épargne, qui passe par un provider groupé sur la liste.
        */
            security: "is_granted('VOIR', object)",
            requirements: ['id' => '\d+'],
            normalizationContext: ['groups' => ['read:Fournisseur', 'read:Fournisseur:item', 'read:Base']],
            openapi: new Operation(
                summary: 'Le planteur',
                description: 'Permet de voir un planteur, son numéro mobile money, son prix de transport et ses mises en compte',
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
            processor: TarificationProcessor::class, // Retient le prix AVANT, pour le journal des activités
            openapi: new Operation(
                summary: 'Modification d\'un planteur',
                description: 'C\'est ici qu\'on renseigne le numéro mobile money, sans lequel aucun versement n\'est possible, et le prix du transport',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch( /*
            - L'ouverture et l'arrêt de la mise en compte, réservés à l'administrateur. Un verbe à part plutôt qu'un champ du formulaire de modification : décider qu'un planteur touchera moins au guichet n'est pas du même ordre que corriger son numéro de téléphone, et ne se donne pas aux mêmes personnes.
            - Un taux absent ou nul arrête le prélèvement. Ce qui est déjà mis de côté reste au planteur.
        */
            uriTemplate: '/fournisseurs/{id}/epargne',
            security: "is_granted('PRELEVER', object)",
            requirements: ['id' => '\d+'],
            input: PrelevementInput::class,
            denormalizationContext: ['groups' => ['write:Fournisseur:epargne']],
            processor: BasculerPrelevementProcessor::class,
            openapi: new Operation(
                summary: 'Ouverture ou arrêt d\'une mise en compte',
                description: 'Fixe le taux prélevé sur chaque pesée du planteur, en francs par kilo. Un taux absent arrête le prélèvement',
                security: [['bearerAuth' => []]]
            )
        ),
        /*
            - CE QU'IL TOUCHE SUR CHAQUE PRODUIT, et lequel des trois sauts a répondu. Une lecture,
              posée à côté de l'écriture qui la suit : on regarde la grille, puis on la corrige.

            - Elle existe parce que la fiche du planteur ne porte QUE ses tarifs propres : la
              grille de sa catégorie n'y est pas, et sans elle aucun écran ne peut dire ce qu'il
              touche réellement. On l'a appris en laissant une liste déroulante annoncer le prix
              du produit comme borne du taux de mise en compte — faux dès qu'une catégorie paie
              autrement, et l'API acceptait pourtant davantage.
        */
        new Get(
            uriTemplate: '/fournisseurs/{id}/tarifs',
            /*
                - SUR LA CLASSE, pas sur l'objet : avec 'read: false' il n'y a pas d'objet à juger,
                  et la règle tombait en 403 pour tout le monde. Le périmètre se vérifie dans le
                  provider, qui charge le planteur lui-même.
            */
            security: "is_granted('VOIR', 'Fournisseur')",
            requirements: ['id' => '\\d+'],
            output: TarifsApplicablesOutput::class,
            /* Surtout PAS de 'read: false' ici : il court-circuite le provider, et la réponse sort à 'null' */
            normalizationContext: ['groups' => ['read:TarifsApplicables']],
            provider: TarifsApplicablesProvider::class,
            openapi: new Operation(
                summary: 'La grille applicable a un planteur',
                description: 'Un produit par ligne, le prix qu\'il touche et d\'ou il vient',
                security: [['bearerAuth' => []]]
            )
        ),
        /*
            - SES TARIFS PROPRES, saisis d'un bloc comme ceux d'une catégorie, et par le même
              chemin : les deux portent exactement la même chose, un prix par produit. Deux
              mécanismes auraient fini par diverger sur ce qui compte le plus, la revalorisation.

            - SANS 'confirmer', RIEN N'EST ÉCRIT : la réponse dit ce qui changerait et combien de
              pesées seraient touchées. La portée est plus étroite que pour une catégorie — un seul
              planteur — mais l'arbitrage d'épargne y est tout aussi irréversible.

            - À l'administrateur seul, comme la mise en compte : décider qu'un planteur sera payé
              autrement que ses voisins n'est pas du même ordre que corriger son numéro.
        */
        new Post(
            uriTemplate: '/fournisseurs/{id}/tarifs',
            security: "is_granted('PRELEVER', 'Fournisseur')",
            requirements: ['id' => '\\d+'],
            input: TarifLotInput::class,
            output: TarifLotOutput::class,
            read: false,
            normalizationContext: ['groups' => ['read:TarifLot']],
            denormalizationContext: ['groups' => ['write:TarifLot']],
            processor: TarifLotProcessor::class,
            openapi: new Operation(
                summary: 'Saisie groupée des tarifs propres d\'un planteur',
                description: 'Sans « confirmer », rend le bilan et le nombre de pesées concernées sans rien écrire',
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
/*
    - UN champ de saisie, PLUSIEURS colonnes en OU. Les écrans annoncent « Nom ou code » dans leur
      champ de recherche ; avec un filtre par colonne, une seule répondait et le code ne trouvait
      jamais rien — sans message, comme s'il n'existait pas.
    - 'FreeTextQueryFilter' rassemble les colonnes, 'OrFilter' les met en OU plutôt qu'en ET, et
      'PartialSearchFilter' cherche le fragment sans tenir compte de la casse.
*/
#[QueryParameter(
    key: 'recherche',
    filter: new FreeTextQueryFilter(new OrFilter(new PartialSearchFilter())),
    properties: ['nom', 'prenom', 'codefournisseur'],
)]
#[ApiFilter(SearchFilter::class, properties: ['categorie' => 'exact'])]
/*
    - « QUI NE TOUCHE AUCUN TRANSPORT ? », la question que 'baremeTransport IS NULL' ne sait pas
      poser : elle rendrait aussi les planteurs d'une catégorie qui en porte un. Le filtre suit
      la règle de résolution entière — voir 'SansTransportFilter'.
*/
#[ApiFilter(SansTransportFilter::class)]
#[ApiFilter(AvecTarifPropreFilter::class)]
#[ApiFilter(OrderFilter::class, properties: ['codefournisseur', 'nom', 'distanceKm', 'createdAt'])]
#[Assert\Callback('verifierSonTransport')]
class Fournisseur extends EntityBase implements SiteOwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:Fournisseur', 'read:Operation', 'read:Paiement', 'read:DemandeRetrait'])]
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

    /*
        - La contrainte ne vaut que pour les écritures par l'API, c'est-à-dire le formulaire web. La
          synchronisation, elle, écrit par l'EntityManager sans passer par le validateur : un numéro
          mal saisi au poste entre quand même, et c'est voulu — perdre une pesée pour une faute de
          frappe serait hors de proportion.
        - Le web est le seul endroit où le numéro peut être corrigé, et le formulaire montre toujours
          ce champ : refuser ici, c'est faire corriger celui qui a la fiche sous les yeux plutôt que
          celui qui a le planteur devant lui.
    */
    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    #[NumeroMobileValide]
    #[Groups(['read:Fournisseur', 'write:Fournisseur', 'read:Operation', 'read:Paiement', 'read:DemandeRetrait'])]
    private ?string $contact1 = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    #[NumeroMobileValide]
    #[Groups(['read:Fournisseur', 'write:Fournisseur'])]
    private ?string $contact2 = null;

    #[ORM\Column(enumType: ReseauMobile::class, nullable: true)]
    #[Groups(['read:Fournisseur', 'write:Fournisseur', 'read:Operation', 'read:Paiement', 'read:DemandeRetrait'])]
    private ?ReseauMobile $reseau = null;

    /*
        - SON BARÈME DE TRANSPORT, s'il s'en fait payer un. Certains planteurs amènent eux-mêmes
          leur récolte : le trajet leur est payé EN PLUS de ce qu'elle vaut. 'null' veut dire qu'on
          ne leur paie pas de transport, et c'est le cas de la plupart d'entre eux.

        - IL REMPLACE UN PRIX AU KILO écrit sur cette fiche. Ce prix était juste pour qui livre dix
          tonnes et absurde pour qui en apporte deux cents kilos du même village — le camion a fait
          la même route. Et il ne se partageait pas : vingt planteurs du même village donnaient
          vingt saisies à tenir d'accord à la main.

        - Ce n'est pas un prix de remplacement : la récolte garde sa valeur, le transport s'ajoute
          par-dessus. Voir 'Operation::calculerMontant()', où les deux restent deux nombres
          distincts jusqu'au bout.

        - 'SET NULL' à la purge du barème : le planteur perd son transport, ce qui se voit au
          guichet. L'emporter avec serait une catastrophe muette.
    */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['read:Fournisseur', 'write:Fournisseur', 'read:Fournisseur:item'])]
    private ?BaremeTransport $baremeTransport = null;

    /*
        - LA DISTANCE DE SON TRAJET, en kilomètres. Elle ne sert qu'aux barèmes par TRANCHES, et
          elle leur est indispensable : sans elle, aucune tranche ne contient quoi que ce soit.

        - Nullable, et elle le restera : le poste crée les planteurs avec un libellé et rien
          d'autre. C'est pour ça que rattacher un barème par tranches à un planteur sans distance
          est refusé — sinon son transport vaudrait zéro, se figerait, et personne n'aurait vu
          passer l'information.
    */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: 'La distance ne peut pas être négative')]
    #[Groups(['read:Fournisseur', 'write:Fournisseur', 'read:Fournisseur:item'])]
    private ?int $distanceKm = null;

    /*
        - Les mises en compte du planteur, UNE PAR PRODUIT : à chaque pesée, le taux du produit pesé décide de la part qui va sur son épargne au lieu de lui être remise au guichet. La grande majorité des planteurs n'en a aucune.

        - Par produit, et non une seule pour tout le monde, parce que les cultures ne se valent pas : 20 F par kilo de cacao et 50 F par kilo d'hévéa sont deux décisions différentes, prises à des moments différents, et qui s'arrêtent séparément.

        - Elles ne s'écrivent pas au fil de l'eau : l'administrateur les ouvre et les arrête, sur demande de l'opérateur qui a le planteur devant lui. Le poste de pesée ne les connaît pas et n'a aucun moyen de les poser.

        - Sérialisées sur la FICHE seulement ('read:Fournisseur:item'), jamais sur la liste : une collection par ligne ferait une requête par ligne.
    */
    /** @var Collection<int, Prelevement> */
    #[ORM\OneToMany(mappedBy: 'fournisseur', targetEntity: Prelevement::class, cascade: ['persist'])]
    #[ORM\OrderBy(['arreteAt' => 'ASC', 'id' => 'DESC'])]
    #[Groups(['read:Fournisseur:item'])]
    private Collection $prelevements;

    /*
        - SA CATÉGORIE, si on lui en a donné une. Facultative, et elle le restera : la quasi-totalité
          des planteurs n'en a pas besoin, et les forcer dans une catégorie « ordinaire » n'apporterait
          qu'une ligne de plus à tenir.

        - Sérialisée sur la liste ET sur la fiche : c'est une colonne de tri mental pour qui cherche
          « qui est en catégorie A ? », et la première chose qu'on vérifie quand un montant surprend.

        - 'SET NULL' à la suppression définitive : perdre sa catégorie rend le planteur au prix du
          produit, ce qui est une conséquence visible. Le supprimer avec elle serait une catastrophe
          silencieuse — ses pesées, son épargne et ses versements pendent de lui.
    */
    #[ORM\ManyToOne(inversedBy: 'fournisseurs')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['read:Fournisseur', 'write:Fournisseur', 'read:Fournisseur:item'])]
    private ?CategorieFournisseur $categorie = null;

    /*
        - SES TARIFS PROPRES, qui l'emportent sur ceux de sa catégorie. L'exception, pas la règle :
          trente tarifs identiques saisis planteur par planteur sont une catégorie qui manquait.

        - Sur la FICHE seulement, comme les prélèvements : une collection par ligne de liste ferait
          une requête par ligne.
    */
    /** @var Collection<int, TarifFournisseur> */
    #[ORM\OneToMany(mappedBy: 'fournisseur', targetEntity: TarifFournisseur::class, cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $tarifs;

    /* Jamais sérialisé : c'est le solde qui est publié, pas le compte. Nul tant que rien n'a été prélevé. */
    #[ORM\OneToOne(mappedBy: 'fournisseur', targetEntity: CompteEpargne::class)]
    private ?CompteEpargne $compteEpargne = null;

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
        $this->prelevements = new ArrayCollection();
        $this->tarifs = new ArrayCollection();
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

    /* Même mécanique que ci-dessus, pour le solde d'épargne : sans elle, la liste des planteurs tirerait une requête par ligne pour traverser la relation. */
    private ?int $soldeEpargne = null;

    public function setSoldeEpargne(int $soldeEpargne): static
    {
        $this->soldeEpargne = $soldeEpargne;

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

    #[Groups(['read:Fournisseur', 'read:Operation', 'read:Paiement', 'read:DemandePrelevement', 'read:MouvementEpargne', 'read:DemandeRetrait'])]
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
        $this->contact1 = $this->numero($contact1);

        return $this;
    }

    public function getContact2(): ?string
    {
        return $this->contact2;
    }

    public function setContact2(?string $contact2): static
    {
        $this->contact2 = $this->numero($contact2);

        return $this;
    }

    /*
        - Le numéro est ramené à sa forme canonique DANS le setter, au même titre que l'adresse e-mail
          est mise en minuscules ailleurs. C'est ce qui fait que les trois portes d'entrée — la fiche
          web, le poste de pesée, le versement de circonstance — aboutissent à la même écriture.

        - Une saisie qu'on ne sait pas normaliser est CONSERVÉE telle quelle plutôt que rejetée ici :
          la synchronisation ne valide pas, et perdre une pesée parce qu'un numéro est mal tapé serait
          hors de proportion. L'écran la montre, 'getMotifNonPayable()' dit pourquoi elle ne vaut rien,
          et la validation la refuse sur le chemin web.
    */
    private function numero(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        if($valeur === '') {
            return null;
        }

        return NumeroMobile::normaliser($valeur) ?? $valeur;
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

    public function getBaremeTransport(): ?BaremeTransport
    {
        return $this->baremeTransport;
    }

    public function setBaremeTransport(?BaremeTransport $baremeTransport): static
    {
        $this->baremeTransport = $baremeTransport;

        return $this;
    }

    /*
        - UN BARÈME PAR TRANCHES SANS DISTANCE NE PEUT RIEN DONNER, et le refus tombe ICI, au
          moment où on les met ensemble. Sans lui, le rattachement passe, la pesée suivante fige
          un transport à zéro, et ce zéro s'arbitre — personne n'aura vu passer l'information.

        - Le refus ne couvre QUE le barème propre du planteur. Celui de sa catégorie peut devenir
          « par tranches » après coup, sans que personne ne touche à sa fiche : ce cas-là se dit
          sur la pesée, par 'getMotifTransportIncalculable()'. Les deux se complètent.
    */
    public function verifierSonTransport(ExecutionContextInterface $contexte): void
    {
        if($this->baremeTransport?->getMode()?->exigeUneDistance() === true && $this->distanceKm === null) {
            $contexte->buildViolation(
                'Le barème « %bareme% » se calcule par tranches de kilomètres : renseignez la distance de ce planteur, sinon son transport resterait à zéro.'
            )
                ->setParameter('%bareme%', (string) $this->baremeTransport)
                ->atPath('distanceKm')
                ->addViolation();
        }
    }

    /*
        - CE QUE CE PLANTEUR TOUCHE VRAIMENT, en une phrase, barème de sa catégorie compris. Sans
          elle, la liste affichait « Aucun » pour un planteur parfaitement réglé par sa catégorie —
          la colonne mentait sur le seul cas qu'elle existe pour signaler.

        - L'origine est DITE, pas seulement appliquée : « par sa catégorie » prévient que corriger
          ce transport-là se fait ailleurs, et touchera tous les autres planteurs du groupe.
    */
    #[Groups(['read:Fournisseur', 'read:Fournisseur:item'])]
    public function getTransportApplique(): ?string
    {
        $transport = TransportApplicable::pour($this);

        if($transport->bareme === null) {
            return null;
        }

        return $transport->origine === OrigineTransport::CATEGORIE
            ? sprintf('%s (par sa catégorie)', (string) $transport->bareme)
            : (string) $transport->bareme;
    }

    /*
        - LE SEUL BARÈME QUI NE PAIE RIEN : par tranches, sur un planteur sans distance. Sa propre
          fiche refuse déjà ce couple ('verifierSonTransport'), mais la catégorie peut passer aux
          tranches après coup sans que personne ne rouvre la fiche — et alors plus rien ne le dit.
    */
    #[Groups(['read:Fournisseur', 'read:Fournisseur:item'])]
    public function getMotifTransportIndisponible(): ?string
    {
        return TransportApplicable::pour($this)->motifIndisponible();
    }

    public function getDistanceKm(): ?int
    {
        return $this->distanceKm;
    }

    public function setDistanceKm(?int $distanceKm): static
    {
        $this->distanceKm = $distanceKm;

        return $this;
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

    /** Payable PAR MOBILE MONEY. Depuis que les espèces existent, ce n'est plus la même question que « peut-on le régler ». */
    public function estPayable(): bool
    {
        return $this->getMotifNonPayable() === null;
    }

    /*
        - Ce qui interdit TOUT règlement, quel qu'en soit le mode. La suspension est de cette nature : on ne paie pas un planteur suspendu, pas plus en billets qu'en mobile money.
        - Séparé du motif ci-dessous depuis l'arrivée des espèces : un planteur sans téléphone n'est pas un planteur qu'on refuse de payer, c'est un planteur qu'on paie autrement. Confondre les deux le rendait impayable tout court — et c'était précisément celui qu'on règle en liquide.
    */
    #[Groups(['read:Fournisseur', 'read:Operation', 'read:Paiement'])]
    public function getMotifBloquant(): ?string
    {
        return $this->statut->estActif() ? null : 'Ce planteur est suspendu.';
    }

    /*
        - La raison pour laquelle ce planteur ne peut pas être réglé PAR MOBILE MONEY, ou null s'il peut l'être. Un motif plutôt qu'un booléen : l'opérateur a le planteur en face de lui et doit savoir quoi corriger, pas seulement que « ça ne marche pas ». C'est aussi pour ça qu'il est sérialisé.
        - Il n'empêche plus de verser : il empêche de verser SUR UN TÉLÉPHONE. L'écran de versement s'en sert pour fermer le mode mobile, pas pour fermer le versement.
        - Le préfixe 'get' n'est pas un caprice : le sérialiseur ignore les groupes posés sur une méthode qui n'en porte pas.
    */
    #[Groups(['read:Fournisseur', 'read:Operation', 'read:Paiement', 'read:DemandeRetrait'])]
    public function getMotifNonPayable(): ?string
    {
        if(($bloquant = $this->getMotifBloquant()) !== null) {
            return $bloquant;
        }

        if($this->contact1 === null || $this->contact1 === '') {
            return 'Ce planteur n\'a pas de numéro de téléphone : le paiement mobile est impossible.';
        }

        /*
            - Un numéro que l'agrégateur refusera. Le dire ICI plutôt qu'au versement change tout :
              l'opérateur le corrige depuis la fiche, au calme, au lieu de le découvrir avec le
              planteur devant lui. Le cas vient surtout du poste, qui accepte n'importe quelle saisie.
        */
        if(!NumeroMobile::estValide($this->contact1)) {
            return sprintf('Le numéro « %s » n\'est pas un numéro mobile reconnu : corrigez-le avant de payer.', $this->contact1);
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

    /** @return Collection<int, Prelevement> */
    public function getPrelevements(): Collection
    {
        return $this->prelevements;
    }

    public function ajouterPrelevement(Prelevement $prelevement): static
    {
        if(!$this->prelevements->contains($prelevement)) {
            $this->prelevements->add($prelevement);
            $prelevement->setFournisseur($this);
        }

        return $this;
    }

    public function getCategorie(): ?CategorieFournisseur
    {
        return $this->categorie;
    }

    public function setCategorie(?CategorieFournisseur $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }

    /** @return Collection<int, TarifFournisseur> */
    public function getTarifs(): Collection
    {
        return $this->tarifs;
    }

    /**
     * SES TARIFS EN VIGUEUR, et c'est la seule forme qu'on publie.
     *
     * La collection brute porte aussi les lignes RETIRÉES : une ligne supprimée reste en base, et
     * c'est voulu — savoir qui a retiré le tarif hévéa de ce planteur, et quand, est exactement la
     * question qu'on posera le jour où il verra sa paie baisser. Mais elle ne s'applique plus :
     * 'tarifPour()' l'écarte, comme toute la valorisation.
     *
     * Même piège que sur 'CategorieFournisseur', et il s'est déjà montré là-bas : publier la
     * collection entière faisait afficher un prix que PERSONNE ne touche, sans une erreur.
     *
     * @return list<TarifFournisseur>
     */
    #[Groups(['read:Fournisseur:item', 'read:Fournisseur:tarifs'])]
    #[SerializedName('tarifs')]
    public function getTarifsVivants(): array
    {
        return array_values(array_filter(
            $this->tarifs->toArray(),
            static fn(TarifFournisseur $tarif): bool => $tarif->getDeletedAt() === null
        ));
    }

    public function addTarif(TarifFournisseur $tarif): static
    {
        if(!$this->tarifs->contains($tarif)) {
            $this->tarifs->add($tarif);
            $tarif->setFournisseur($this);
        }

        return $this;
    }

    public function removeTarif(TarifFournisseur $tarif): static
    {
        $this->tarifs->removeElement($tarif);

        return $this;
    }

    /*
        - Son tarif propre SUR CE PRODUIT, ou null. Même parcours en mémoire que
          'prelevementPour()', et pour la même raison : la valorisation traite des lots, et le
          même planteur y revient autant de fois qu'il a livré.
    */
    public function tarifPour(?Produit $produit): ?TarifFournisseur
    {
        foreach($this->tarifs as $tarif) {
            if($tarif->getDeletedAt() === null && $tarif->vise($produit)) {
                return $tarif;
            }
        }

        return null;
    }

    /*
        - Le prélèvement en cours SUR CE PRODUIT, ou null. C'est lui qui décide ce qu'une pesée met de côté, et rien d'autre : un prélèvement sur le cacao ne touche pas une livraison d'hévéa.

        - Parcourt la collection déjà chargée plutôt que de requêter : l'arbitrage traite des lots entiers, et un planteur y revient autant de fois qu'il a livré. Le repository rend le même service aux processors, qui n'ont pas le planteur hydraté sous la main.

        - L'IDENTITÉ D'ABORD, l'identifiant ensuite, et l'ordre n'est pas décoratif : comparer deux 'getId()' avant le 'flush()' compare 'null' à 'null', et le PREMIER prélèvement de la liste répond alors pour toutes les cultures. Les fixtures l'ont montré — une pesée de palme repartait avec le taux de l'hévéa. Doctrine garantit un seul objet par entité chargée, proxy compris : le '===' est donc fiable, et le repli par identifiant ne sert qu'aux objets venus de deux chargements distincts.
    */
    public function prelevementPour(?Produit $produit): ?Prelevement
    {
        if($produit === null) {
            return null;
        }

        $id = $produit->getId();

        foreach($this->prelevements as $prelevement) {
            if(!$prelevement->estActif()) {
                continue;
            }

            $sien = $prelevement->getProduit();

            if($sien === $produit || ($id !== null && $sien?->getId() === $id)) {
                return $prelevement;
            }
        }

        return null;
    }

    /* Au moins un prélèvement en cours, tous produits confondus. */
    public function epargneEnCours(): bool
    {
        foreach($this->prelevements as $prelevement) {
            if($prelevement->estActif()) {
                return true;
            }
        }

        return false;
    }

    public function getCompteEpargne(): ?CompteEpargne
    {
        return $this->compteEpargne;
    }

    public function setCompteEpargne(?CompteEpargne $compteEpargne): static
    {
        $this->compteEpargne = $compteEpargne;

        return $this;
    }

    /*
        - Ce que l'entreprise garde pour ce planteur. Sur une liste, la valeur vient du provider, qui l'a obtenue en une requête groupée ; le repli ne sert qu'à la fiche.
        - Zéro pour un planteur sans compte, et c'est juste : n'avoir jamais épargné et avoir tout retiré reviennent au même pour celui qui regarde.
    */
    #[Groups(['read:Fournisseur', 'read:DemandePrelevement', 'read:DemandeRetrait'])]
    public function getSoldeEpargne(): int
    {
        return $this->soldeEpargne ?? $this->compteEpargne?->getSolde() ?? 0;
    }

    public function __toString(): string
    {
        return $this->getNomComplet();
    }
}
