<?php

namespace App\Domain\Support;

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

    - ELLE ALLAIT CHERCHER UNE TROISIÈME CHOSE, et c'est ce qu'on a retiré : la DISTANCE du
      planteur, dont les barèmes par tranches avaient besoin pour choisir leur intervalle. Personne
      ne la saisissait — le poste crée les planteurs avec un libellé et rien d'autre — donc le
      barème ne savait pas répondre, et il fallait une troisième réponse, « RIEN », distincte de
      zéro, plus un motif pour l'expliquer, plus une validation pour l'empêcher. Les bornes vivent
      maintenant sur le barème et ne décident plus d'aucun montant : il ne reste que deux réponses.

    - DEUX RÉPONSES, DONC, ET ELLES RESTENT DISTINCTES :
      · zéro parce qu'il n'y a pas de barème — on ne paie pas de transport à ce planteur ;
      · un montant, parce qu'un barème a répondu.
*/
final class TransportApplicable
{
    private function __construct(
        public readonly ?BaremeTransport $bareme,
        public readonly OrigineTransport $origine,
    )
    {
    }

    public static function pour(?Fournisseur $planteur): self
    {
        if($planteur === null) {
            return new self(null, OrigineTransport::AUCUN);
        }

        $sien = $planteur->getBaremeTransport();

        if($sien !== null && $sien->getDeletedAt() === null) {
            return new self($sien, OrigineTransport::PLANTEUR);
        }

        $deSaCategorie = $planteur->getCategorie()?->getBaremeTransport();

        if($deSaCategorie !== null && $deSaCategorie->getDeletedAt() === null) {
            return new self($deSaCategorie, OrigineTransport::CATEGORIE);
        }

        return new self(null, OrigineTransport::AUCUN);
    }

    /*
        - Ce que vaut le transport de cette pesée. Zéro sans barème ; 'null' ne subsiste que pour un
          poids absent, c'est-à-dire une pesée non terminée — que le calcul écarte déjà en amont.
    */
    public function montant(?int $poidsnet): ?int
    {
        if($this->bareme === null) {
            return 0;
        }

        return $this->bareme->montantPour($poidsnet);
    }

    /*
        - LE PRIX AU KILO N'EXISTE QUE POUR UN MODE SUR DEUX. Un forfait donne un montant sans prix
          unitaire ; y figer un prix inventé ferait mentir toute multiplication.
    */
    public function prixAuKilo(): ?int
    {
        return $this->bareme?->getMode()?->donneUnPrixAuKilo() === true
            ? $this->bareme->getPrixAuKilo()
            : null;
    }

    /*
        - LES BORNES DU BARÈME, EN TOUTES LETTRES et non par clé étrangère. Elles s'éditent ; une
          référence rendrait demain un intervalle qui n'a jamais servi à ce montant-là.
    */
    public function libelleBornes(): ?string
    {
        return $this->bareme?->libelleBornes();
    }
}
