<?php

namespace App\Entity;

use App\Repository\TrancheRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - UN INTERVALLE DE KILOMÈTRES, ET CE QU'IL COÛTE. « De 0 à 20 km : 5 000 F », « de 20 à 50 km :
      12 000 F ». C'est le mode 'TRANCHES' d'un barème, et la seule façon de faire payer la route
      plutôt que le tonnage.

    - BORNES SEMI-OUVERTES : '[kmMin, kmMax[', minimum INCLUS, maximum EXCLU. Ce n'est pas un détail
      de présentation. Avec deux bornes incluses, un planteur à exactement 20 km tombe dans « 0–20 »
      ET dans « 20–50 » : deux montants pour une même distance, départagés par l'ordre de la
      requête, c'est-à-dire par rien. Le montant devient alors une question de chance, et personne
      ne s'en aperçoit — les deux réponses sont plausibles.

    - 'kmMax' NUL = LA DERNIÈRE, SANS LIMITE. Il en faut exactement une, sinon toute distance
      au-delà du dernier intervalle ne trouve aucun montant : un transport à zéro, figé, puis
      arbitré, sans qu'une seule erreur n'apparaisse.

    - La cohérence de la SUITE — pas de trou, pas de chevauchement, départ à zéro — ne se vérifie
      pas ici : une tranche seule ne voit pas ses voisines. C'est 'BaremeTransport' qui s'en charge,
      sur la collection entière.
*/
#[ORM\Entity(repositoryClass: TrancheRepository::class)]
#[ORM\Index(name: 'idx_tranche_bareme_min', fields: ['bareme', 'kmMin'])]
class Tranche extends EntityBase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:BaremeTransport:item'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tranches')]
    #[ORM\JoinColumn(nullable: false)]
    private ?BaremeTransport $bareme = null; // Jamais sérialisé : on ne lit cette ligne qu'à travers lui

    /* Kilomètre de départ, INCLUS. Zéro pour la première : un trajet ne part pas d'ailleurs. */
    #[ORM\Column]
    #[Assert\PositiveOrZero(message: 'Le kilomètre de départ ne peut pas être négatif')]
    #[Groups(['read:BaremeTransport:item', 'write:BaremeTransport'])]
    private int $kmMin = 0;

    /* Kilomètre de fin, EXCLU. Nul pour la dernière tranche, celle qui prend tout ce qui dépasse. */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'Le kilomètre de fin doit être supérieur à zéro')]
    #[Groups(['read:BaremeTransport:item', 'write:BaremeTransport'])]
    private ?int $kmMax = null;

    /* En francs, pour le trajet entier. Pas au kilo : c'est le mode « au kilo » qui fait ça. */
    #[ORM\Column]
    #[Assert\PositiveOrZero(message: 'Le montant d\'une tranche ne peut pas être négatif')]
    #[Groups(['read:BaremeTransport:item', 'write:BaremeTransport'])]
    private int $montant = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBareme(): ?BaremeTransport
    {
        return $this->bareme;
    }

    public function setBareme(?BaremeTransport $bareme): static
    {
        $this->bareme = $bareme;

        return $this;
    }

    public function getKmMin(): int
    {
        return $this->kmMin;
    }

    public function setKmMin(int $kmMin): static
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

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function setMontant(int $montant): static
    {
        $this->montant = $montant;

        return $this;
    }

    /*
        - CONTIENT-ELLE CETTE DISTANCE ? Minimum inclus, maximum EXCLU — la même règle que celle qui
          interdit les chevauchements à l'écriture. Les deux lectures doivent dire la même chose,
          sinon une borne exacte tombe dans une tranche que la validation croyait disjointe.
    */
    public function contient(int $km): bool
    {
        return $km >= $this->kmMin && ($this->kmMax === null || $km < $this->kmMax);
    }

    /*
        - CE QU'ON FIGE SUR LA PESÉE, en toutes lettres. Pas une clé étrangère : éditer la tranche
          plus tard réécrirait le « pourquoi » d'un montant déjà payé, et l'historique dirait une
          borne qui n'a jamais servi.
    */
    public function __toString(): string
    {
        return $this->kmMax === null
            ? sprintf('%d km et au-delà', $this->kmMin)
            : sprintf('de %d à %d km', $this->kmMin, $this->kmMax);
    }
}
