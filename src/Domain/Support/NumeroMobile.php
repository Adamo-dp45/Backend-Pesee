<?php

namespace App\Domain\Support;

/*
    - Un numéro de téléphone destiné au mobile money. Il entre par TROIS portes qui ne se ressemblent
      pas : la fiche saisie sur le web, le libellé poussé par le poste de pesée, et le numéro de
      circonstance tapé au moment d'un versement. Chacune donne des formes différentes du même
      numéro — « 07 01 02 03 04 », « +225 0701020304 », « 002250701020304 », « 07.01.02.03.04 ».

    - Les agrégateurs, eux, n'en acceptent qu'une. Sans normalisation, l'écart ne se découvre qu'au
      versement, planteur devant soi, un refus à la fois. On ramène donc tout à une forme canonique
      dès l'entrée : E.164, indicatif compris — '+2250701020304'.

    - Côte d'Ivoire par défaut, et c'est assumé : le produit s'y déploie, les montants sont en francs
      CFA et les quatre réseaux proposés sont ivoiriens. L'indicatif est une constante pour qu'un
      déploiement ailleurs soit un changement d'une ligne, pas une chasse dans le code.

    - Ce qui n'est pas ici non plus : la mise en forme pour l'œil. L'API rend du JSON, et la valeur
      qui en sort doit rester canonique — c'est celle que le formulaire renverra. Le regroupement
      lisible appartient aux écrans, côté 'Frontend-Webpesee' : filtre Twig 'numero' et son pendant
      React dans 'cellules.tsx'.

    - Ce qui n'est PAS vérifié ici : que le numéro existe, ni qu'il appartienne au réseau annoncé.
      Seul l'agrégateur le sait, et il le dira. On vérifie la FORME, ce qui suffit à éliminer les
      fautes de frappe et les champs à moitié remplis.
*/
final class NumeroMobile
{
    /** Côte d'Ivoire. Un déploiement ailleurs change cette ligne et la longueur ci-dessous. */
    public const INDICATIF = '225';

    /** Longueur du numéro national, indicatif exclu. Dix chiffres depuis la renumérotation de 2021. */
    private const LONGUEUR_NATIONALE = 10;

    /*
        - Ramène un numéro à sa forme canonique, ou rend null s'il n'y arrive pas.

        - Null plutôt qu'une exception, et c'est délibéré : cette méthode est appelée depuis les
          setters, y compris pendant la synchronisation d'un lot de pesées. Un numéro mal saisi sur le
          poste ne doit pas faire échouer l'envoi — la pesée est le fait important, le numéro se
          corrige ensuite depuis le web. C'est la VALIDATION qui refuse, pas la normalisation.
    */
    public static function normaliser(?string $valeur): ?string
    {
        if($valeur === null) {
            return null;
        }

        // Tout ce qui n'est ni chiffre ni '+' est du décor : espaces, points, tirets, parenthèses
        $chiffres = preg_replace('/[^0-9+]/', '', $valeur) ?? '';

        // '00225…' et '+225…' désignent la même chose : on ramène les deux à l'indicatif nu
        $chiffres = preg_replace('/^(?:\+|00)/', '', $chiffres) ?? '';
        $chiffres = preg_replace('/[^0-9]/', '', $chiffres) ?? '';

        if($chiffres === '') {
            return null;
        }

        if(str_starts_with($chiffres, self::INDICATIF)) {
            $national = substr($chiffres, strlen(self::INDICATIF));

            return strlen($national) === self::LONGUEUR_NATIONALE ? '+' . self::INDICATIF . $national : null;
        }

        /*
            - Un numéro national nu : c'est ce que tape un opérateur, et ce que le poste envoie. On lui
              ajoute l'indicatif plutôt que de le refuser — sinon on refuserait la forme la plus
              courante du terrain.
        */
        return strlen($chiffres) === self::LONGUEUR_NATIONALE ? '+' . self::INDICATIF . $chiffres : null;
    }

    /**
     * Vrai si la valeur donne un numéro exploitable. Un champ vide n'est pas invalide : il est
     * absent, ce qui est un autre problème, traité ailleurs.
     */
    public static function estValide(?string $valeur): bool
    {
        return $valeur === null || trim($valeur) === '' || self::normaliser($valeur) !== null;
    }
}
