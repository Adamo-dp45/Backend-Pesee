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
use App\Domain\Enum\SensMouvement;
use App\Domain\Enum\TypeMouvementEpargne;
use App\Entity\Operation as Pesee; // Aliasé : 'Operation' désigne déjà celle d'OpenAPI dans ce fichier
use App\Entity\Interface\EntrepriseOwnedInterface;
use App\Entity\Interface\SiteOwnedInterface;
use App\Repository\MouvementEpargneRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/*
    - Le livre d'épargne, construit sur la même discipline que le grand livre des caisses :
      'soldeAvant' et 'soldeApres' sont stockés, et pour un même compte le 'soldeApres' d'une ligne
      doit être le 'soldeAvant' de la suivante. C'est ce qui rend une écriture sauvage détectable.

    - Un livre SÉPARÉ de 'MouvementCaisse', parce qu'un prélèvement ne déplace aucun argent : au
      moment où il s'écrit, le planteur n'est pas encore passé au guichet. Le mêler aux caisses
      aurait rompu leur chaînage et fait apparaître de l'argent là où il n'y en a pas.

    - 'operation' porte une contrainte d'UNICITÉ, et c'est la seule barrière qui tienne contre la
      concurrence : le poste renvoie son lot quand l'accusé se perd, deux requêtes peuvent lire la
      même pesée non arbitrée et vouloir la créditer toutes les deux. Les gardes applicatives les
      laisseraient passer ; la base, non.

    - Jamais modifiée ni supprimée après écriture : on ne corrige pas une écriture, on en passe une
      nouvelle.
*/
#[ORM\Entity(repositoryClass: MouvementEpargneRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_mouvement_epargne_operation', fields: ['operation'])]
#[ORM\Index(name: 'idx_mouvement_epargne_compte_date', fields: ['compte', 'createdAt'])]
#[ApiResource(
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    normalizationContext: ['groups' => ['read:MouvementEpargne', 'read:Base'], 'skip_null_values' => false],
    paginationItemsPerPage: 50,
    paginationClientItemsPerPage: true,
    order: ['createdAt' => 'DESC'],
    operations: [ // Lecture seule, comme le grand livre : une écriture ne naît que du service d'épargne
        new GetCollection(
            security: "is_granted('VOIR', 'MouvementEpargne')",
            openapi: new Operation(
                summary: 'Le livre d\'épargne',
                description: 'Journal des mises en compte. Un opérateur ne voit que les planteurs de ses ponts bascules',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get(
            security: "is_granted('VOIR', object)",
            requirements: ['id' => '\d+'],
            openapi: new Operation(
                summary: 'Le mouvement d\'épargne',
                description: 'Permet de voir une écriture, avec le solde avant et après',
                security: [['bearerAuth' => []]]
            )
        ),
    ],
    openapi: new Operation(security: [['bearerAuth' => []]])
)]
#[ApiFilter(SearchFilter::class, properties: [
    'compte' => 'exact',
    'compte.fournisseur' => 'exact', // Le relevé d'un planteur : c'est la lecture la plus courante
    'type' => 'exact',
    'site' => 'exact',
])]
#[ApiFilter(DateFilter::class, properties: ['createdAt'])]
#[ApiFilter(OrderFilter::class, properties: ['montant', 'createdAt'])]
class MouvementEpargne extends EntityBase implements EntrepriseOwnedInterface, SiteOwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:MouvementEpargne'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['read:MouvementEpargne'])]
    private ?CompteEpargne $compte = null;

    /*
        - La pesée qui a causé l'écriture. Nullable, et pas seulement pour la forme : le futur
          retrait n'aura aucune pesée derrière lui. MySQL tolère plusieurs NULL sur un index unique,
          la contrainte laisse donc la place sans rien relâcher sur les prélèvements.
    */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['read:MouvementEpargne'])]
    private ?Pesee $operation = null;

    #[ORM\Column(enumType: TypeMouvementEpargne::class)]
    #[Groups(['read:MouvementEpargne'])]
    private TypeMouvementEpargne $type = TypeMouvementEpargne::PRELEVEMENT;

    #[ORM\Column(enumType: SensMouvement::class)]
    #[Groups(['read:MouvementEpargne'])]
    private SensMouvement $sens = SensMouvement::CREDIT;

    #[ORM\Column]
    #[Groups(['read:MouvementEpargne'])]
    private int $montant = 0; // Toujours positif : c'est 'sens' qui porte la direction

    #[ORM\Column]
    #[Groups(['read:MouvementEpargne'])]
    private int $soldeAvant = 0;

    #[ORM\Column]
    #[Groups(['read:MouvementEpargne'])]
    private int $soldeApres = 0;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['read:MouvementEpargne'])]
    private ?string $motif = null;

    /* Recopiés du compte pour que le cloisonnement filtre sans jointure, comme sur 'MouvementCaisse' */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Entreprise $entreprise = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['read:MouvementEpargne'])]
    private ?Site $site = null;

    /* Qui a provoqué l'écriture. Pour un prélèvement c'est le compte machine du poste, puisqu'il naît d'une synchronisation ; le futur retrait portera l'opérateur du guichet. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['read:MouvementEpargne'])]
    private ?User $effectuePar = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompte(): ?CompteEpargne
    {
        return $this->compte;
    }

    public function setCompte(?CompteEpargne $compte): static
    {
        $this->compte = $compte;

        return $this;
    }

    public function getOperation(): ?Pesee
    {
        return $this->operation;
    }

    public function setOperation(?Pesee $operation): static
    {
        $this->operation = $operation;

        return $this;
    }

    public function getType(): TypeMouvementEpargne
    {
        return $this->type;
    }

    public function setType(TypeMouvementEpargne $type): static
    {
        $this->type = $type;

        return $this;
    }

    #[Groups(['read:MouvementEpargne'])]
    public function getTypeLibelle(): string
    {
        return $this->type->libelle();
    }

    public function getSens(): SensMouvement
    {
        return $this->sens;
    }

    public function setSens(SensMouvement $sens): static
    {
        $this->sens = $sens;

        return $this;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function setMontant(int $montant): static
    {
        $this->montant = $montant;

        return $this;
    }

    public function getSoldeAvant(): int
    {
        return $this->soldeAvant;
    }

    public function setSoldeAvant(int $soldeAvant): static
    {
        $this->soldeAvant = $soldeAvant;

        return $this;
    }

    public function getSoldeApres(): int
    {
        return $this->soldeApres;
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

    public function getEffectuePar(): ?User
    {
        return $this->effectuePar;
    }

    public function setEffectuePar(?User $effectuePar): static
    {
        $this->effectuePar = $effectuePar;

        return $this;
    }
}
