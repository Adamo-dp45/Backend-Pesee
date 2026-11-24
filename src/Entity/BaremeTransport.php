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

    - LE MODE DÉCIDE DU CHAMP QUI COMPTE, et l'autre reste vide. Un barème au kilo n'a pas de
      forfait. Les laisser coexister ouvrirait « lequel gagne ? », question à laquelle aucune
      réponse n'est bonne.

    - UNE TRANCHE EST UN BARÈME, et non une ligne dans une suite. « De 10 à 20 km : 1 000 F » est un
      forfait de 1 000 F qui porte ses bornes ; le planteur pointe dessus, et on sait ce qu'il
      touche. L'ancienne forme tenait une collection d'intervalles ici, et allait chercher la
      DISTANCE sur la fiche du planteur — une distance que personne ne saisissait, parce que le
      poste crée les planteurs avec un libellé et rien d'autre. Le barème ne savait alors rien
      répondre, le transport valait zéro, et ce zéro se figeait sur la pesée.

    - LES BORNES NE CALCULENT RIEN, elles NOMMENT. Deux barèmes à 1 000 F ne se distinguent que par
      elles ; elles trient la liste et apparaissent sur la fiche de la pesée. Aucune règle ne les
      lit pour choisir un montant — c'est précisément ce qu'on a retiré.
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
                description: 'Le barème et, s\'il porte des bornes, le kilométrage couvert',
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
                description: 'Le mode, son montant et ses bornes de kilométrage',
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
        - LES BORNES DU TRAJET, facultatives et purement descriptives. Elles disent à qui ce barème
          s'adresse — « de 10 à 20 km » — et c'est ce qui distingue deux forfaits de même montant
          dans une liste. Aucun calcul ne les lit.

        - LES DEUX SONT NULLABLES, et les quatre combinaisons ont un sens : aucune borne (un barème
          qui ne parle pas de distance), les deux (un intervalle), 'kmMin' seul (« au-delà de 50 km »),
          'kmMax' seul (« moins de 10 km »). Les rendre obligatoires forcerait à inventer un zéro ou
          un plafond là où l'entreprise n'a rien décidé.

        - 'PositiveOrZero' et non 'Positive' sur le départ : un barème de proximité commence bien à
          zéro kilomètre.
    */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le kilométrage de départ ne peut pas être négatif')]
    #[Groups(['read:BaremeTransport', 'write:BaremeTransport', 'read:Fournisseur:item'])]
    private ?int $kmMin = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'Le kilométrage de fin doit être supérieur à zéro')]
    #[Groups(['read:BaremeTransport', 'write:BaremeTransport', 'read:Fournisseur:item'])]
    private ?int $kmMax = null;

    /*
        - LA COHÉRENCE DU BARÈME, et il n'en reste que deux façons de la perdre. Le fichier en
          tenait quatre de plus — trou dans la suite, chevauchement, aucune tranche ouverte, départ
          ailleurs qu'à zéro — et toutes les quatre ont disparu avec la suite elle-même. Un barème
          ne répond plus que pour lui.

        - IL RESTE L'INTERVALLE À L'ENVERS, « de 50 à 10 km », qui ne désigne aucun planteur et se
          lirait pourtant comme une ligne valide dans la liste.
    */
    public function verifierLaCoherence(ExecutionContextInterface $contexte): void
    {
        if($this->mode === null) {
            return; // 'NotNull' le dit déjà : ne pas répéter le même refus deux fois
        }

        $this->verifierLeChampDuMode($contexte);

        if($this->kmMin !== null && $this->kmMax !== null && $this->kmMax <= $this->kmMin) {
            $contexte->buildViolation('Le kilométrage de fin doit dépasser celui de départ.')
                ->atPath('kmMax')
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

    /*
        - CE QUE CE BARÈME DONNE pour un poids, ou null s'il ne peut rien donner. 'null' n'est PAS
          zéro : il veut dire « ce barème ne sait pas répondre », et l'appelant doit le dire au lieu
          de figer un transport nul.

        - LE SEUL CAS QUI RESTE est un poids absent, c'est-à-dire une pesée non terminée — que le
          calcul écarte déjà en amont. Le forfait, lui, répond toujours.
    */
    public function montantPour(?int $poidsnet): ?int
    {
        return match($this->mode) {
            ModeTransport::AU_KILO => $poidsnet === null || $this->prixAuKilo === null ? null : $poidsnet * $this->prixAuKilo,
            ModeTransport::FORFAIT => $this->montant,
            default => null,
        };
    }

    /*
        - LES BORNES EN TOUTES LETTRES, ou null quand le barème n'en porte pas. C'est ce qui se fige
          sur la pesée : les bornes s'éditent, et une référence au barème rendrait demain un
          intervalle qui n'a jamais servi à ce montant-là.
    */
    public function libelleBornes(): ?string
    {
        if($this->kmMin === null && $this->kmMax === null) {
            return null;
        }

        if($this->kmMin === null) {
            return sprintf('moins de %d km', $this->kmMax);
        }

        if($this->kmMax === null) {
            return sprintf('au-delà de %d km', $this->kmMin);
        }

        return sprintf('de %d à %d km', $this->kmMin, $this->kmMax);
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

    public function getKmMin(): ?int
    {
        return $this->kmMin;
    }

    public function setKmMin(?int $kmMin): static
    {
        $this->kmMin = $kmMin;

        return $this;
    }

    public function getKmMax(): ?int
    {
        return $this->kmMax;
    }

    public function setKmMax(?int $kmMax): static
    {
        $this->kmMax = $kmMax;

        return $this;
    }

    /* Le libellé suivi de ses bornes : c'est sous cette forme que le barème se nomme partout. */
    #[Groups(['read:BaremeTransport', 'read:Fournisseur:item', 'read:CategorieFournisseur'])]
    public function getLibelleComplet(): string
    {
        $bornes = $this->libelleBornes();

        return $bornes === null ? (string) $this->libelle : sprintf('%s (%s)', (string) $this->libelle, $bornes);
    }

    public function __toString(): string
    {
        return (string) $this->libelle;
    }
}
