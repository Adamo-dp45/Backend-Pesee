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
use App\Domain\Support\NumeroMobile;
use App\Entity\Interface\SiteOwnedInterface;
use App\Entity\Operation as Pesee; // Aliasé : 'Operation' désigne déjà celle d'OpenAPI dans ce fichier
use App\Repository\FournisseurRepository;
use App\State\Provider\CompteurPeseesProvider;
use App\Entity\Input\PrelevementInput;
use App\State\Processor\BasculerPrelevementProcessor;
use App\State\Processor\AuditProcessor;
use App\State\Processor\TarificationProcessor;
use App\State\Processor\SuppressionLogiqueProcessor;
use App\Validator\NumeroMobileValide;
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
        new Get( /*
            - La fiche embarque les prélèvements, que la LISTE n'a pas : une collection sérialisée sur
              chaque ligne tirerait une requête par planteur. C'est le même raisonnement que pour le
              solde d'épargne, qui passe par un provider groupé sur la liste.
        */
            security: "is_granted('VOIR', object)",
            requirements: ['id' => '\d+'],
            normalizationContext: ['groups' => ['read:Fournisseur', 'read:Fournisseur:item', 'read:Base'], 'skip_null_values' => false],
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
#[ApiFilter(OrderFilter::class, properties: ['codefournisseur', 'nom', 'prixtransport', 'createdAt'])]
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
    #[Groups(['read:Fournisseur', 'write:Fournisseur', 'read:Operation', 'read:Paiement'])]
    private ?string $contact1 = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    #[NumeroMobileValide]
    #[Groups(['read:Fournisseur', 'write:Fournisseur'])]
    private ?string $contact2 = null;

    #[ORM\Column(enumType: ReseauMobile::class, nullable: true)]
    #[Groups(['read:Fournisseur', 'write:Fournisseur', 'read:Operation', 'read:Paiement'])]
    private ?ReseauMobile $reseau = null;

    /*
        - Le prix du transport, en francs PAR KILO. Certains planteurs amènent eux-mêmes leur récolte au pont bascule : on leur paie le transport EN PLUS de ce que vaut la récolte. 'null' veut dire qu'on ne paie pas de transport à ce planteur, et c'est le cas de la plupart d'entre eux.

        - Ce n'est pas un prix de remplacement : la récolte garde sa valeur, le transport s'ajoute par-dessus. Voir 'Operation::calculerMontant()', où les deux restent deux nombres distincts jusqu'au bout.

        - Même arbitrage poste/web que 'Produit::prix' : le poste l'envoie s'il le connaît, et sans lui c'est la valeur saisie sur le web qui tient. Un planteur n'est donc jamais bloqué parce que le logiciel de pesée ignore son transport.
    */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le prix du transport ne peut pas être négatif')]
    #[Groups(['read:Fournisseur', 'write:Fournisseur'])]
    private ?int $prixtransport = null;

    #[ORM\Column(nullable: true)]
    private ?int $prixtransportPoste = null; // Arbitre entre les deux sources, voir 'Produit::prixPoste'

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

    #[Groups(['read:Fournisseur', 'read:Operation', 'read:Paiement', 'read:DemandePrelevement', 'read:MouvementEpargne'])]
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

    public function getPrixtransport(): ?int
    {
        return $this->prixtransport;
    }

    public function setPrixtransport(?int $prixtransport): static
    {
        $this->prixtransport = $prixtransport;

        return $this;
    }

    /*
        - Renvoie vrai si le prix de transport EN VIGUEUR a bougé. Copie conforme de 'Produit::appliquerPrixDuPoste()', y compris le refus du 'null'.

        - Ce refus est tout l'intérêt : le poste qui ne connaît pas le transport d'un planteur n'envoie rien, et ne doit surtout pas effacer le prix saisi sur le web. Seule une valeur explicite — 0 compris, qui veut dire « plus de transport » — l'emporte.
    */
    public function appliquerPrixTransportDuPoste(?int $prix): bool
    {
        if($prix === null || $prix < 0 || $prix === $this->prixtransportPoste) {
            return false;
        }

        $this->prixtransportPoste = $prix;

        if($prix === $this->prixtransport) {
            return false;
        }

        $this->prixtransport = $prix;

        return true;
    }

    public function getPrixtransportPoste(): ?int
    {
        return $this->prixtransportPoste;
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
    #[Groups(['read:Fournisseur', 'read:Operation', 'read:Paiement'])]
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
    #[Groups(['read:Fournisseur', 'read:DemandePrelevement'])]
    public function getSoldeEpargne(): int
    {
        return $this->soldeEpargne ?? $this->compteEpargne?->getSolde() ?? 0;
    }

    public function __toString(): string
    {
        return $this->getNomComplet();
    }
}
