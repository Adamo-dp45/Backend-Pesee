<?php

namespace App\Domain\Support;

use App\Domain\Enum\ModeTransport;
use App\Domain\Enum\OrigineTransport;
use App\Entity\BaremeTransport;
use App\Entity\Fournisseur;

/*
    - LA règle du transport, et le seul endroit où elle est écrite. Deux sauts :

        le barème rattaché au planteur
        ?? celui de sa catégorie
        ?? aucun — et « aucun » n'est pas un échec : c'est le cas de la plupart des planteurs.

    - STATIQUE ET SANS REQUÊTE, comme 'TarifApplicable', et pour la même raison : elle est appelée
      par pesée dans la boucle de synchronisation, sur des lots de deux cents.

    - TROIS RÉPONSES, ET IL FAUT LES TENIR DISTINCTES :

      · zéro parce qu'il n'y a pas de barème — on ne paie pas de transport à ce planteur ;
      · un montant, parce qu'un barème a su répondre ;
      · RIEN, parce qu'un barème existe mais ne peut pas répondre — un barème par tranches sur un
        planteur sans distance. Le confondre avec zéro figerait un transport nul sur une pesée qui
        en mérite un, et personne ne verrait jamais passer l'information. C'est à ça que sert
        'motifIndisponible()' : la pesée le dit au lieu de se taire.
*/
final class TransportApplicable
{
    private function __construct(
        public readonly ?BaremeTransport $bareme,
        public readonly OrigineTransport $origine,
        /** La distance du planteur, telle qu'elle était au moment du calcul. */
        public readonly ?int $distanceKm,
    )
    {
    }

    public static function pour(?Fournisseur $planteur): self
    {
        if($planteur === null) {
            return new self(null, OrigineTransport::AUCUN, null);
        }

        $distance = $planteur->getDistanceKm();
        $sien = $planteur->getBaremeTransport();

        if($sien !== null && $sien->getDeletedAt() === null) {
            return new self($sien, OrigineTransport::PLANTEUR, $distance);
        }

        $deSaCategorie = $planteur->getCategorie()?->getBaremeTransport();

        if($deSaCategorie !== null && $deSaCategorie->getDeletedAt() === null) {
            return new self($deSaCategorie, OrigineTransport::CATEGORIE, $distance);
        }

        return new self(null, OrigineTransport::AUCUN, $distance);
    }

    /*
        - Ce que vaut le transport de cette pesée. Zéro sans barème, 'null' si un barème existe mais
          ne sait pas répondre — l'appelant doit alors refuser de figer quoi que ce soit.
    */
    public function montant(?int $poidsnet): ?int
    {
        if($this->bareme === null) {
            return 0;
        }

        return $this->bareme->montantPour($poidsnet, $this->distanceKm);
    }

    /*
        - LE PRIX AU KILO N'EXISTE QUE POUR UN MODE SUR TROIS. Un forfait et une tranche donnent un
          montant sans prix unitaire ; y figer un prix inventé ferait mentir toute multiplication.
    */
    public function prixAuKilo(): ?int
    {
        return $this->bareme?->getMode()?->donneUnPrixAuKilo() === true
            ? $this->bareme->getPrixAuKilo()
            : null;
    }

    /*
        - LA TRANCHE RETENUE, EN TOUTES LETTRES et non par clé étrangère. Les bornes d'une tranche
          s'éditent ; une clé rendrait demain un intervalle qui n'a jamais servi à ce montant-là.
    */
    public function libelleTranche(): ?string
    {
        if($this->bareme?->getMode() !== ModeTransport::TRANCHES || $this->distanceKm === null) {
            return null;
        }

        /*
            - AUCUNE TRANCHE NE RÉPOND : impossible sur une suite cohérente, mais la cohérence est
              tenue par une validation, et une validation se contourne (une migration, une reprise,
              une tranche mise à la corbeille). Un transtypage direct figerait alors la chaîne VIDE,
              qui s'affiche comme une tranche sans bornes et ne se distingue plus d'un libellé
              perdu. 'null' dit « on n'a pas retenu de tranche », ce qui est la vérité.
        */
        return $this->bareme->tranchePour($this->distanceKm)?->__toString();
    }

    /*
        - POURQUOI CE BARÈME NE PEUT PAS RÉPONDRE, ou null s'il le peut. Un seul cas aujourd'hui, et
          il est entièrement dû au terrain : le poste crée les planteurs avec un libellé et rien
          d'autre, donc sans distance. Un barème par tranches sur un tel planteur ne trouve aucun
          intervalle.

        - La phrase nomme ce qu'il faut corriger et où. « Transport à 0 F » ne le dirait pas.
    */
    public function motifIndisponible(): ?string
    {
        if($this->bareme === null) {
            return null;
        }

        if($this->bareme->getMode()?->exigeUneDistance() === true && $this->distanceKm === null) {
            return sprintf(
                'Le barème « %s » se calcule par tranches de kilomètres, et ce planteur n\'a pas de distance. Renseignez-la sur sa fiche.',
                (string) $this->bareme
            );
        }

        return null;
    }
}
