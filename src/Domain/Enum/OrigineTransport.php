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

    public function libelle(): string
    {
        return match($this) {
            self::PLANTEUR => 'Barème du planteur',
            self::CATEGORIE => 'Barème de sa catégorie',
            self::AUCUN => 'Aucun transport',
        };
    }
}
