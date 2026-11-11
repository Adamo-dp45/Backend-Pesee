<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation;
use App\Domain\Enum\CompteMouvement;
use App\Domain\Enum\SensMouvement;
use App\Domain\Enum\TypeCaisse;
use App\Domain\Enum\TypeMouvement;
use App\Entity\Interface\EntrepriseOwnedInterface;
use App\Entity\Interface\SiteOwnedInterface;
use App\Repository\MouvementCaisseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/*
    - Le grand livre. Toute variation de solde, au niveau de l'entreprise comme d'un pont bascule, écrit une ligne ici.

    - Une seule table pour les deux niveaux de caisse plutôt que deux : l'historique complet d'un mouvement d'argent se lit alors d'une seule requête. Doter un site produit deux lignes — un DEBIT sur l'entreprise, un CREDIT sur le site — parce que l'argent se déplace mais ne se crée pas.

    - 'soldeAvant' et 'soldeApres' sont stockés, et pas seulement le montant. C'est ce qui permet de vérifier après coup que la chaîne est intacte : pour un même compte, le 'soldeApres' d'une ligne doit être le 'soldeAvant' de la suivante. Un écart signale immédiatement une écriture passée hors du service de caisse.

    - Jamais modifiée ni supprimée après écriture : on ne corrige pas une écriture, on en passe une nouvelle.
*/
#[ORM\Entity(repositoryClass: MouvementCaisseRepository::class)]
#[ORM\Index(name: 'idx_mouvement_entreprise_date', fields: ['entreprise', 'createdAt'])]
#[ORM\Index(name: 'idx_mouvement_site_date', fields: ['site', 'createdAt'])]
#[ORM\Index(name: 'idx_mouvement_type', fields: ['type'])]
#[ApiResource(
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    normalizationContext: ['groups' => ['read:MouvementCaisse', 'read:Base'], 'skip_null_values' => false],
    paginationItemsPerPage: 50,
    paginationClientItemsPerPage: true,
    order: ['createdAt' => 'DESC'],
    operations: [ // Lecture seule : ni Post, ni Patch, ni Delete — une écriture comptable ne s'écrit que par le service de caisse
        new GetCollection(
            security: "is_granted('VOIR', 'MouvementCaisse')",
            openapi: new Operation(
                summary: 'Le grand livre',
                description: 'Journal des mouvements de caisse. Un opérateur ne voit que ses ponts bascules, jamais la caisse de l\'entreprise',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get(
            security: "is_granted('VOIR', object)",
            requirements: ['id' => '\d+'],
            openapi: new Operation(
                summary: 'Le mouvement de caisse',
                description: 'Permet de voir une écriture, avec le solde avant et après',
                security: [['bearerAuth' => []]]
            )
        ),
    ],
    openapi: new Operation(security: [['bearerAuth' => []]])
)]
#[ApiFilter(SearchFilter::class, properties: [
    'compte' => 'exact',
    'caisse' => 'exact', // Lire le journal d'une seule caisse : c'est ainsi qu'on vérifie que sa chaîne de soldes se tient
    'type' => 'exact',
    'sens' => 'exact',
    'site' => 'exact',
])]
#[ApiFilter(DateFilter::class, properties: ['createdAt'])]
#[ApiFilter(OrderFilter::class, properties: ['montant', 'createdAt'])]
/*
    - Porte les DEUX marqueurs de cloisonnement, et c'est indispensable : le site est facultatif (les mouvements de la caisse de l'entreprise n'en ont pas), donc seul le rattachement direct à l'entreprise garantit qu'une ligne sans site ne traverse pas la frontière du client.
*/
class MouvementCaisse extends EntityBase implements EntrepriseOwnedInterface, SiteOwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:MouvementCaisse'])]
    private ?int $id = null;

    #[ORM\Column(enumType: CompteMouvement::class)]
    #[Groups(['read:MouvementCaisse'])]
    private ?CompteMouvement $compte = null;

    /*
        - 'compte' dit de QUI est la caisse — l'entreprise ou le poste — et celui-ci dit LAQUELLE de ses deux. Les deux ensemble désignent un solde, et un seul : c'est ce qui permet de rejouer le grand livre d'une caisse donnée et de retrouver exactement son solde.
        - Sans ce champ, les lignes des deux caisses d'un même poste se suivaient dans le journal avec des 'soldeAvant'/'soldeApres' qui ne s'enchaînaient pas — la chaîne paraissait rompue à chaque alternance, et rien ne permettait de dire laquelle des deux avait bougé.
    */
    #[ORM\Column(enumType: TypeCaisse::class, options: ['default' => 'NUMERIQUE'])]
    #[Groups(['read:MouvementCaisse'])]
    private TypeCaisse $caisse = TypeCaisse::NUMERIQUE;

    /*
        - Toujours renseignée, y compris pour un mouvement de site : ça permet de sortir le journal complet d'une entreprise sans passer par une jointure sur les sites.
    */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Entreprise $entreprise = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['read:MouvementCaisse'])]
    private ?Site $site = null; // Nul pour les mouvements du compte de l'entreprise (recharge, attribution)

    #[ORM\Column(enumType: SensMouvement::class)]
    #[Groups(['read:MouvementCaisse'])]
    private ?SensMouvement $sens = null;

    #[ORM\Column(enumType: TypeMouvement::class)]
    #[Groups(['read:MouvementCaisse'])]
    private ?TypeMouvement $type = null;

    #[ORM\Column(type: Types::BIGINT)]
    #[Groups(['read:MouvementCaisse'])]
    private int $montant = 0; // Toujours positif : c'est 'sens' qui porte la direction

    #[ORM\Column(type: Types::BIGINT)]
    #[Groups(['read:MouvementCaisse'])]
    private int $soldeAvant = 0;

    #[ORM\Column(type: Types::BIGINT)]
    #[Groups(['read:MouvementCaisse'])]
    private int $soldeApres = 0;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['read:MouvementCaisse'])]
    private ?string $motif = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['read:MouvementCaisse'])]
    private ?Paiement $paiement = null; // Renseigné sur un PAIEMENT et son REMBOURSEMENT : c'est ce qui relie une écriture au versement qui l'a causée

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['read:MouvementCaisse'])]
    private ?DemandeSolde $demandeSolde = null; // Renseigné sur les deux écritures d'une dotation approuvée : c'est ce qui relie l'argent à la demande qui l'a déclenché

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['read:MouvementCaisse'])]
    private ?User $effectuePar = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompte(): ?CompteMouvement
    {
        return $this->compte;
    }

    public function setCompte(CompteMouvement $compte): static
    {
        $this->compte = $compte;

        return $this;
    }

    public function getCaisse(): TypeCaisse
    {
        return $this->caisse;
    }

    public function setCaisse(TypeCaisse $caisse): static
    {
        $this->caisse = $caisse;

        return $this;
    }

    #[Groups(['read:MouvementCaisse'])]
    public function getCaisseLibelle(): string
    {
        return $this->caisse->libelle();
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

    public function getSite(): ?Site
    {
        return $this->site;
    }

    public function setSite(?Site $site): static
    {
        $this->site = $site;

        return $this;
    }

    public function getSens(): ?SensMouvement
    {
        return $this->sens;
    }

    public function setSens(SensMouvement $sens): static
    {
        $this->sens = $sens;

        return $this;
    }

    public function getType(): ?TypeMouvement
    {
        return $this->type;
    }

    public function setType(TypeMouvement $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getMontant(): int
    {
        return (int) $this->montant;
    }

    public function setMontant(int $montant): static
    {
        $this->montant = $montant;

        return $this;
    }

    public function getSoldeAvant(): int
    {
        return (int) $this->soldeAvant;
    }

    public function setSoldeAvant(int $soldeAvant): static
    {
        $this->soldeAvant = $soldeAvant;

        return $this;
    }

    public function getSoldeApres(): int
    {
        return (int) $this->soldeApres;
    }

    public function setSoldeApres(int $soldeApres): static
    {
        $this->soldeApres = $soldeApres;

        return $this;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(?string $motif): static
    {
        $this->motif = $motif;

        return $this;
    }

    public function getPaiement(): ?Paiement
    {
        return $this->paiement;
    }

    public function setPaiement(?Paiement $paiement): static
    {
        $this->paiement = $paiement;

        return $this;
    }

    public function getDemandeSolde(): ?DemandeSolde
    {
        return $this->demandeSolde;
    }

    public function setDemandeSolde(?DemandeSolde $demandeSolde): static
    {
        $this->demandeSolde = $demandeSolde;

        return $this;
    }

    public function getEffectuePar(): ?User
    {
        return $this->effectuePar;
    }

    public function setEffectuePar(?User $effectuePar): static
    {
        $this->effectuePar = $effectuePar;

        return $this;
    }

    #[Groups(['read:MouvementCaisse'])]
    public function getTypeLibelle(): string
    {
        return $this->type?->libelle() ?? '';
    }

    #[Groups(['read:MouvementCaisse'])]
    public function getSensLibelle(): string
    {
        return $this->sens?->libelle() ?? '';
    }
}
