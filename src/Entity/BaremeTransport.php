<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\FreeTextQueryFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use App\Domain\Enum\ModeTransport;
use App\Domain\Support\Cle;
use App\Entity\Interface\EntrepriseOwnedInterface;
use App\Repository\BaremeTransportRepository;
use App\State\Processor\BaremeTransportProcessor;
use App\State\Processor\PerimetreEntrepriseProcessor;
use App\State\Processor\SuppressionLogiqueProcessor;
use App\Validator\UniqueParEntreprise;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/*
    - COMMENT ON PAIE UN TRAJET. Jusqu'ici il n'y avait qu'une réponse, écrite sur la fiche du
      planteur : tant de francs par kilo transporté. C'est juste pour qui livre dix tonnes, absurde
      pour qui en apporte deux cents kilos depuis le même village — le camion a fait la même route.

    - UN BARÈME SE PARTAGE, un prix au kilo ne se partageait pas. Vingt planteurs du même village
      obtenaient vingt saisies à tenir d'accord à la main ; ils pointent maintenant sur une ligne.

    - À L'ENTREPRISE, ET SEULEMENT À ELLE. Lui donner un pont bascule le rendrait invisible à
      l'opérateur, qui ne voit que les ressources de ses postes — et un barème n'a pas de poste.

    - LE MODE DÉCIDE DES CHAMPS QUI COMPTENT, et les autres restent vides. Un barème au kilo n'a pas
      de forfait, un forfait n'a pas de tranches. Les laisser coexister ouvrirait « lequel gagne ? »,
      question à laquelle aucune réponse n'est bonne.
*/
#[ORM\Entity(repositoryClass: BaremeTransportRepository::class)]
#[ORM\Index(name: 'idx_bareme_entreprise_cle', fields: ['entreprise', 'cle'])]
/*
    - Pas d'index UNIQUE en base, comme partout ailleurs : une ligne à la corbeille garde sa clé et
      interdirait de recréer un barème supprimé.
*/
#[UniqueParEntreprise(
    fields: ['cle'],
    message: 'Ce barème existe déjà (les accents et la casse ne comptent pas).',
    errorPath: 'libelle'
)]
#[ApiResource(
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    normalizationContext: ['groups' => ['read:BaremeTransport', 'read:Base']],
    denormalizationContext: ['groups' => ['write:BaremeTransport']],
    paginationItemsPerPage: 25,
    paginationClientItemsPerPage: true,
    order: ['libelle' => 'ASC'],
    operations: [
        new GetCollection(
            security: "is_granted('VOIR', 'BaremeTransport')",
            openapi: new Operation(
                summary: 'Liste des barèmes de transport',
                description: 'Les barèmes de son entreprise',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get(
            security: "is_granted('VOIR', object)",
            requirements: ['id' => '\d+'],
            normalizationContext: ['groups' => ['read:BaremeTransport', 'read:BaremeTransport:item', 'read:Base']],
            openapi: new Operation(
                summary: 'Le barème',
                description: 'Le barème et, s\'il est par tranches, la suite de ses intervalles',
                security: [['bearerAuth' => []]]
            )
        ),
        new Post(
            security: "is_granted('CREER', 'BaremeTransport')",
            processor: PerimetreEntrepriseProcessor::class, // Pose l'entreprise du créateur, puis délègue à 'AuditProcessor'
            openapi: new Operation(
                summary: 'Création d\'un barème',
                security: [['bearerAuth' => []]]
            )
        ),
        /*
            - LES TRANCHES S'ÉCRIVENT AVEC LE BARÈME, d'un seul envoi, et c'est la seule façon :
              leur cohérence — ni trou, ni chevauchement, un départ à zéro, une seule tranche
              ouverte — ne se juge que sur la suite ENTIÈRE. Une tranche seule ne voit pas ses
              voisines, et l'enregistrer une par une laisserait la suite incohérente entre deux
              saisies, le temps que quelqu'un valorise une pesée.

            - Pas d'aperçu avant écriture, contrairement à la grille de tarifs, et la différence est
              réelle : changer un transport ne touche pas 'montantdu', donc n'ouvre aucune mise en
              compte et ne fige aucune retenue. Une erreur de barème se corrige en ressaisissant.
        */
        new Patch(
            security: "is_granted('MODIFIER', object)",
            requirements: ['id' => '\d+'],
            processor: BaremeTransportProcessor::class, // Revalorise le transport des pesées concernées
            openapi: new Operation(
                summary: 'Modification d\'un barème',
                description: 'Le mode, ses montants et, pour les tranches, la suite entière',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch( // Verbe dédié, jamais DELETE : des planteurs et des pesées s'y réfèrent
            uriTemplate: '/bareme_transports/{id}/suppression',
            security: "is_granted('SUPPRIMER', object)",
            requirements: ['id' => '\d+'],
            input: false,
            processor: SuppressionLogiqueProcessor::class,
            openapi: new Operation(
                summary: 'Mise à la corbeille d\'un barème',
                description: 'Refusé tant qu\'un planteur ou une catégorie s\'y réfère : le motif nomme le nombre',
                security: [['bearerAuth' => []]]
            )
        ),
    ],
    openapi: new Operation(security: [['bearerAuth' => []]])
)]
#[ApiFilter(SearchFilter::class, properties: ['mode' => 'exact'])]
#[QueryParameter(
    key: 'recherche',
    filter: new FreeTextQueryFilter(new OrFilter(new PartialSearchFilter())),
    properties: ['libelle'],
)]
#[ApiFilter(OrderFilter::class, properties: ['libelle', 'createdAt'])]
#[Assert\Callback('verifierLaCoherence')]
class BaremeTransport extends EntityBase implements EntrepriseOwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:BaremeTransport', 'read:Fournisseur', 'read:Fournisseur:item', 'read:CategorieFournisseur'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le libellé du barème est obligatoire')]
    #[Assert\Length(min: 1, max: 255)]
    #[Groups(['read:BaremeTransport', 'write:BaremeTransport', 'read:Fournisseur', 'read:Fournisseur:item', 'read:CategorieFournisseur'])]
    private ?string $libelle = null;

    #[ORM\Column(length: 255)]
    private ?string $cle = null; // Clé de rapprochement normalisée dérivée du libellé, voir 'Cle'

    #[ORM\Column(enumType: ModeTransport::class)]
    #[Assert\NotNull(message: 'Le mode de facturation est obligatoire')]
    #[Groups(['read:BaremeTransport', 'write:BaremeTransport', 'read:Fournisseur:item'])]
    private ?ModeTransport $mode = null;

    /* En francs par kilo. Mode 'AU_KILO' seulement — nul partout ailleurs. */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'Le prix au kilo doit être supérieur à zéro')]
    #[Groups(['read:BaremeTransport', 'write:BaremeTransport', 'read:Fournisseur:item'])]
    private ?int $prixAuKilo = null;

    /* En francs, pour le trajet entier. Mode 'FORFAIT' seulement — nul partout ailleurs. */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'Le forfait doit être supérieur à zéro')]
    #[Groups(['read:BaremeTransport', 'write:BaremeTransport', 'read:Fournisseur:item'])]
    private ?int $montant = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Entreprise $entreprise = null; // Posée par 'PerimetreEntrepriseProcessor', jamais saisie

    /*
        - LA SUITE DES INTERVALLES, mode 'TRANCHES' seulement. Ordonnée par kilomètre de départ :
          c'est l'ordre dans lequel on la lit, l'ordre dans lequel on la vérifie, et l'ordre dans
          lequel l'écran doit l'afficher.

        - 'orphanRemoval' : retirer une tranche de la collection la SUPPRIME. Sans lui, elle
          resterait en base, détachée de rien, et continuerait de compter dans les contrôles de
          chevauchement du prochain enregistrement.
    */
    /** @var Collection<int, Tranche> */
    #[ORM\OneToMany(mappedBy: 'bareme', targetEntity: Tranche::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['kmMin' => 'ASC'])]
    #[Assert\Valid]
    #[Groups(['read:BaremeTransport:item', 'write:BaremeTransport'])]
    private Collection $tranches;

    public function __construct()
    {
        $this->tranches = new ArrayCollection();
    }

    /*
        - LA COHÉRENCE DE LA SUITE, et c'est le cœur de ce fichier. Quatre façons de produire un
          montant faux sans la moindre erreur, et chacune a sa ligne ici :

          · UN TROU — « 0 à 20 », puis « 30 à 50 » : un planteur à 25 km ne trouve aucune tranche,
            son transport vaut zéro, et ce zéro se fige puis s'arbitre ;
          · UN CHEVAUCHEMENT — deux montants pour une même distance, départagés par l'ordre de la
            requête, c'est-à-dire par rien ;
          · AUCUNE TRANCHE OUVERTE — tout ce qui dépasse la dernière borne ne trouve rien, même
            silence ;
          · UN DÉPART AILLEURS QU'À ZÉRO — un planteur à 3 km d'une suite qui commence à 5 tombe
            dans le même vide.

        - La vérification porte sur la collection ENTIÈRE : une contrainte de champ ne voit qu'une
          tranche à la fois, et aucune des quatre n'est visible depuis une seule ligne.
    */
    public function verifierLaCoherence(ExecutionContextInterface $contexte): void
    {
        if($this->mode === null) {
            return; // 'NotNull' le dit déjà : ne pas répéter le même refus deux fois
        }

        $tranches = $this->tranchesOrdonnees();

        if($this->mode !== ModeTransport::TRANCHES) {
            if($tranches !== []) {
                $contexte->buildViolation('Un barème « %mode% » ne porte pas de tranches. Retirez-les, ou changez de mode.')
                    ->setParameter('%mode%', $this->mode->libelle())
                    ->atPath('tranches')
                    ->addViolation();
            }

            $this->verifierLeChampDuMode($contexte);

            return;
        }

        $this->verifierLeChampDuMode($contexte);

        if($tranches === []) {
            $contexte->buildViolation('Un barème par tranches en demande au moins une.')
                ->atPath('tranches')
                ->addViolation();

            return;
        }

        if($tranches[0]->getKmMin() !== 0) {
            $contexte->buildViolation('La première tranche doit partir de 0 km : sinon un planteur plus proche ne trouve aucun montant.')
                ->atPath('tranches')
                ->addViolation();
        }

        $ouvertes = 0;

        foreach($tranches as $rang => $tranche) {
            if($tranche->getKmMax() === null) {
                ++$ouvertes;
            } elseif($tranche->getKmMax() <= $tranche->getKmMin()) {
                $contexte->buildViolation('« %tranche% » : le kilomètre de fin doit dépasser celui de départ.')
                    ->setParameter('%tranche%', (string) $tranche)
                    ->atPath('tranches')
                    ->addViolation();
            }

            $suivante = $tranches[$rang + 1] ?? null;

            if($suivante === null) {
                continue;
            }

            /*
                - LA SEULE FORME SANS TROU NI CHEVAUCHEMENT : la fin de l'une EST le départ de la
                  suivante. Avec des bornes semi-ouvertes, « 0 à 20 » puis « 20 à 50 » se touchent
                  sans se recouvrir — 20 appartient à la seconde, et à elle seule.
            */
            if($tranche->getKmMax() !== $suivante->getKmMin()) {
                $contexte->buildViolation(
                    $tranche->getKmMax() === null || $tranche->getKmMax() < $suivante->getKmMin()
                        ? 'Il manque un intervalle entre « %a% » et « %b% » : une distance entre les deux ne trouverait aucun montant.'
                        : 'Les intervalles « %a% » et « %b% » se chevauchent : une même distance aurait deux montants.'
                )
                    ->setParameter('%a%', (string) $tranche)
                    ->setParameter('%b%', (string) $suivante)
                    ->atPath('tranches')
                    ->addViolation();
            }
        }

        if($ouvertes !== 1) {
            $contexte->buildViolation(
                $ouvertes === 0
                    ? 'Il faut une dernière tranche SANS kilomètre de fin : sans elle, un trajet plus long ne trouve aucun montant.'
                    : 'Une seule tranche peut rester ouverte, et c\'est forcément la dernière.'
            )
                ->atPath('tranches')
                ->addViolation();
        }
    }

    /* Le champ que le mode exige, et celui qu'il interdit. Les laisser coexister ouvrirait « lequel gagne ? ». */
    private function verifierLeChampDuMode(ExecutionContextInterface $contexte): void
    {
        $exige = match($this->mode) {
            ModeTransport::AU_KILO => ['prixAuKilo', $this->prixAuKilo, 'un prix au kilo'],
            ModeTransport::FORFAIT => ['montant', $this->montant, 'un montant'],
            default => null,
        };

        if($exige !== null && $exige[1] === null) {
            $contexte->buildViolation('Un barème « %mode% » demande %quoi%.')
                ->setParameter('%mode%', $this->mode->libelle())
                ->setParameter('%quoi%', $exige[2])
                ->atPath($exige[0])
                ->addViolation();
        }

        foreach([['prixAuKilo', $this->prixAuKilo, ModeTransport::AU_KILO], ['montant', $this->montant, ModeTransport::FORFAIT]] as [$champ, $valeur, $mode]) {
            if($valeur !== null && $this->mode !== $mode) {
                $contexte->buildViolation('Un barème « %mode% » ne porte pas ce champ. Videz-le, ou changez de mode.')
                    ->setParameter('%mode%', $this->mode->libelle())
                    ->atPath($champ)
                    ->addViolation();
            }
        }
    }

    /** @return list<Tranche> */
    public function tranchesOrdonnees(): array
    {
        $vivantes = [];

        foreach($this->tranches as $tranche) {
            if($tranche->getDeletedAt() === null) {
                $vivantes[] = $tranche;
            }
        }

        usort($vivantes, static fn(Tranche $a, Tranche $b) => $a->getKmMin() <=> $b->getKmMin());

        return $vivantes;
    }

    /*
        - CE QUE CE BARÈME DONNE pour un poids et une distance, ou null s'il ne peut rien donner.
          'null' n'est PAS zéro : il veut dire « ce barème ne sait pas répondre », et l'appelant doit
          le dire au lieu de figer un transport nul.
    */
    public function montantPour(?int $poidsnet, ?int $distanceKm): ?int
    {
        return match($this->mode) {
            ModeTransport::AU_KILO => $poidsnet === null || $this->prixAuKilo === null ? null : $poidsnet * $this->prixAuKilo,
            ModeTransport::FORFAIT => $this->montant,
            ModeTransport::TRANCHES => $distanceKm === null ? null : $this->tranchePour($distanceKm)?->getMontant(),
            default => null,
        };
    }

    /* La tranche qui contient cette distance. Avec une suite cohérente, il y en a toujours exactement une. */
    public function tranchePour(int $distanceKm): ?Tranche
    {
        foreach($this->tranchesOrdonnees() as $tranche) {
            if($tranche->contient($distanceKm)) {
                return $tranche;
            }
        }

        return null;
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

    public function getCle(): ?string
    {
        return $this->cle;
    }

    public function getMode(): ?ModeTransport
    {
        return $this->mode;
    }

    public function setMode(?ModeTransport $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    #[Groups(['read:BaremeTransport', 'read:Fournisseur:item', 'read:CategorieFournisseur'])]
    public function getModeLibelle(): ?string
    {
        return $this->mode?->libelle();
    }

    public function getPrixAuKilo(): ?int
    {
        return $this->prixAuKilo;
    }

    public function setPrixAuKilo(?int $prixAuKilo): static
    {
        $this->prixAuKilo = $prixAuKilo;

        return $this;
    }

    public function getMontant(): ?int
    {
        return $this->montant;
    }

    public function setMontant(?int $montant): static
    {
        $this->montant = $montant;

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

    /** @return Collection<int, Tranche> */
    public function getTranches(): Collection
    {
        return $this->tranches;
    }

    public function addTranche(Tranche $tranche): static
    {
        if(!$this->tranches->contains($tranche)) {
            $this->tranches->add($tranche);
            $tranche->setBareme($this);
        }

        return $this;
    }

    public function removeTranche(Tranche $tranche): static
    {
        $this->tranches->removeElement($tranche);

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->libelle;
    }
}
