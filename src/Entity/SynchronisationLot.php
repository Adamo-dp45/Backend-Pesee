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
use App\Entity\Interface\SiteOwnedInterface;
use App\Repository\SynchronisationLotRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/*
    - Le journal d'un envoi. Sans lui, un poste qui échoue en silence depuis trois semaines ne se remarque qu'en s'étonnant d'un tonnage anormalement bas.
    - C'est aussi ce qui permet de diagnostiquer un pont bascule à distance : combien de lignes reçues, combien créées, quelles erreurs, depuis quelle adresse, en combien de temps.
*/
#[ORM\Entity(repositoryClass: SynchronisationLotRepository::class)]
#[ORM\Index(name: 'idx_lot_site_date', fields: ['site', 'createdAt'])]
#[ApiResource(
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    normalizationContext: ['groups' => ['read:SynchronisationLot', 'read:Base']],
    paginationItemsPerPage: 50,
    paginationClientItemsPerPage: true,
    order: ['createdAt' => 'DESC'],
    operations: [ // Lecture seule : un journal ne se réécrit pas
        new GetCollection(
            security: "is_granted('VOIR', 'SynchronisationLot')",
            openapi: new Operation(
                summary: 'Journal des synchronisations',
                description: 'Un envoi par ligne. Sert à repérer un poste muet ou en erreur',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get(
            security: "is_granted('VOIR', object)",
            requirements: ['id' => '\d+'],
            openapi: new Operation(
                summary: 'Le lot de synchronisation',
                description: 'Permet de voir le détail des erreurs d\'un envoi',
                security: [['bearerAuth' => []]]
            )
        ),
    ],
    openapi: new Operation(security: [['bearerAuth' => []]])
)]
#[ApiFilter(SearchFilter::class, properties: ['site' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['createdAt'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'nbErreurs'])]
class SynchronisationLot extends EntityBase implements SiteOwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:SynchronisationLot'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['read:SynchronisationLot'])]
    private ?Site $site = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['read:SynchronisationLot'])]
    private int $nbRecus = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['read:SynchronisationLot'])]
    private int $nbCrees = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['read:SynchronisationLot'])]
    private int $nbModifiees = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['read:SynchronisationLot'])]
    private int $nbErreurs = 0;

    /**
     * @var list<array{id: string|null, message: string}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['read:SynchronisationLot'])]
    private ?array $erreurs = null;

    #[ORM\Column(length: 45, nullable: true)]
    #[Groups(['read:SynchronisationLot'])]
    private ?string $ip = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:SynchronisationLot'])]
    private ?int $dureeMs = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getNbRecus(): int
    {
        return $this->nbRecus;
    }

    public function setNbRecus(int $nbRecus): static
    {
        $this->nbRecus = $nbRecus;

        return $this;
    }

    public function getNbCrees(): int
    {
        return $this->nbCrees;
    }

    public function setNbCrees(int $nbCrees): static
    {
        $this->nbCrees = $nbCrees;

        return $this;
    }

    public function getNbModifiees(): int
    {
        return $this->nbModifiees;
    }

    public function setNbModifiees(int $nbModifiees): static
    {
        $this->nbModifiees = $nbModifiees;

        return $this;
    }

    public function getNbErreurs(): int
    {
        return $this->nbErreurs;
    }

    public function setNbErreurs(int $nbErreurs): static
    {
        $this->nbErreurs = $nbErreurs;

        return $this;
    }

    /**
     * @return list<array{id: string|null, message: string}>|null
     */
    public function getErreurs(): ?array
    {
        return $this->erreurs;
    }

    /**
     * @param list<array{id: string|null, message: string}>|null $erreurs
     */
    public function setErreurs(?array $erreurs): static
    {
        $this->erreurs = $erreurs;

        return $this;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): static
    {
        $this->ip = $ip;

        return $this;
    }

    public function getDureeMs(): ?int
    {
        return $this->dureeMs;
    }

    public function setDureeMs(?int $dureeMs): static
    {
        $this->dureeMs = $dureeMs;

        return $this;
    }
}
