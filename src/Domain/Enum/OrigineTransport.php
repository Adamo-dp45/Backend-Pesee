<?php

namespace App\Domain\Enum;

/*
    - D'OÙ VIENT LE BARÈME QU'UNE PESÉE A RETENU, exactement comme 'OrigineTarif' le fait pour la
      récolte. Deux sauts seulement — un planteur, sa catégorie — et un troisième état qui n'est pas
      un échec : la plupart des planteurs ne se font pas payer de transport.

    - 'AUCUN' SE DISTINGUE D'UN FORFAIT À ZÉRO, et c'est tout l'intérêt. Sans cette trace, un
      montant de transport nul ne dit pas s'il n'y a pas de barème, si le barème donne zéro, ou si
      quelqu'un a oublié d'en rattacher un. Trois situations, une seule ligne en base.
*/
enum OrigineTransport: string
{
    case PLANTEUR = 'PLANTEUR';   // Le barème rattaché à ce planteur, qui l'emporte
    case CATEGORIE = 'CATEGORIE'; // Celui de sa catégorie
    case AUCUN = 'AUCUN';         // Ni l'un ni l'autre : on ne lui paie pas de transport
    /*
        - LA QUATRIÈME ORIGINE N'EST PAS UN SAUT : elle dit qu'aucun barème n'a décidé, et qu'un
          administrateur a écrit le montant sur CETTE pesée. Un détour imprévu, une route coupée,
          un second voyage — des choses qu'aucun barème ne saurait porter sans les donner à tout
          le monde.

        - ELLE FIGE LA PART TRANSPORT, sans quoi la prochaine retouche du barème effacerait le
          montant écrit à la main sans que personne ne le voie partir.
    */
    case MANUEL = 'MANUEL';

    public function libelle(): string
    {
        return match($this) {
            self::PLANTEUR => 'Barème du planteur',
            self::CATEGORIE => 'Barème de sa catégorie',
            self::AUCUN => 'Aucun transport',
            self::MANUEL => 'Montant saisi sur la pesée',
        };
    }
}
