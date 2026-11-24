<?php

namespace App\Domain\Support;

use App\Entity\Fournisseur;

/*
    - COMMENT ON RETROUVE UN PLANTEUR DANS UN LOT DE DEUX CENTS PESÉES, sans une requête par ligne.
      Deux index en mémoire, et l'ordre dans lequel on les interroge EST la règle d'identité.

    - LE CODE D'ABORD, LE LIBELLÉ ENSUITE. Le poste envoie les deux ; jusqu'ici seul le libellé
      comptait, normalisé en clé de rapprochement. Deux conséquences, invisibles l'une et l'autre :

      · DEUX HOMONYMES N'EN FAISAIENT QU'UN. « Kouamé » désigne trois personnes dans un village ;
        leurs pesées, leurs versements et leur épargne atterrissaient sur une seule fiche.
      · UN PLANTEUR RENOMMÉ EN DEVENAIT DEUX. Corriger « Traore » en « Traoré » sur le poste créait
        une seconde fiche, et la première gardait l'historique — personne ne voyait la coupure.

    - LE LIBELLÉ RESTE, EN SECOND, et c'est ce qui rend la bascule possible : les planteurs créés
      avant les codes n'en portent pas. On les retrouve par leur nom, on leur pose le code au
      passage, et la fois suivante c'est le code qui répond. Aucune reprise de données à écrire.

    - ON N'ÉCRASE PAS LE NOM d'une fiche retrouvée par son code. Le poste n'est plus la source du
      référentiel : un nom corrigé au bureau ne doit pas être défait par le prochain envoi, pas
      plus que le numéro de téléphone ne l'est.
*/
final class IndexFournisseurs
{
    /**
     * @param array<string, Fournisseur> $parCode
     * @param array<string, Fournisseur> $parCle
     */
    public function __construct(
        private array $parCode = [],
        private array $parCle = [],
    )
    {
    }

    /*
        - LE REPLI SUR LE NOM NE VAUT QUE VERS UNE FICHE SANS CODE. C'est la nuance qui fait tout :
          un code inconnu qui retombe sur le premier homônyme venu RECRÉERAIT la confusion qu'on
          vient de retirer — « Kouamé F-002 » atterrirait sur la fiche de « Kouamé F-001 », et
          lui volerait son code au passage.

        - Une fiche qui porte DÉJÀ un autre code est donc quelqu'un d'autre, et on en crée une
          nouvelle. Une fiche sans code, elle, est une fiche d'avant la bascule : c'est exactement
          celle qu'on veut retrouver et marquer.
    */
    public function trouver(?string $code, ?string $cle): ?Fournisseur
    {
        $parNom = $cle === null ? null : ($this->parCle[$cle] ?? null);

        if($code === null || $code === '') {
            return $parNom;
        }

        if(isset($this->parCode[$code])) {
            return $this->parCode[$code];
        }

        $sien = $parNom?->getCodefournisseur();

        return $sien === null || $sien === '' ? $parNom : null;
    }

    /*
        - UNE FICHE S'INSCRIT DANS LES DEUX INDEX, y compris celle qu'on vient de créer : deux lignes
          du même lot portent le même planteur, et la seconde doit retrouver la première AVANT le
          'flush()'. Sans ça, un envoi de dix pesées d'un même planteur en créait dix.
    */
    public function ajouter(Fournisseur $fournisseur): void
    {
        $code = $fournisseur->getCodefournisseur();

        if($code !== null && $code !== '') {
            $this->parCode[$code] = $fournisseur;
        }

        $cle = $fournisseur->getCle();

        if($cle !== null && $cle !== '') {
            $this->parCle[$cle] = $fournisseur;
        }
    }
}
