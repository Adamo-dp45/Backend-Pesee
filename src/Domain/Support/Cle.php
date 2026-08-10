<?php

namespace App\Domain\Support;

/*
    - Le logiciel de pesée envoie ses référentiels sous forme de libellés libres, saisis à la main par le peseur. « HEVEA », « Hevea » et « Hévéa » désignent le même produit, et « KOUAME  YAO » le même planteur que « Kouame Yao ».

    - On stocke donc, à côté du libellé affiché, une clé normalisée qui porte l'index unique par site. C'est ce qui empêche la synchronisation de créer un référentiel de plus à chaque variation de frappe.

    - Un index unique MySQL ne suffirait pas seul : dès qu'une colonne de l'index vaut NULL (le prénom d'un planteur par exemple), MySQL laisse passer les doublons. La clé est donc non nulle par construction.

    ATTENTION — la translittération passe par une table explicite, et surtout PAS par 'iconv(…, "ASCII//TRANSLIT")' : son résultat dépend de l'implémentation installée. Sur Windows « Hévéa » ressort en "H'EV'EA" (l'accent devient une apostrophe), sur la glibc en "HEVEA". La clé aurait donc différé entre le poste de développement et le serveur, et chaque référentiel se serait dédoublé au premier déploiement, sans le moindre message d'erreur.
*/
final class Cle
{
    /**
     * Caractères accentués rencontrés dans les libellés produits et les noms de planteurs.
     *
     * @var array<string, string>
     */
    private const TRANSLITTERATION = [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'Ç' => 'C', 'ç' => 'c',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'Ñ' => 'N', 'ñ' => 'n',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'Ý' => 'Y', 'ý' => 'y', 'ÿ' => 'y',
        'Œ' => 'OE', 'œ' => 'oe', 'Æ' => 'AE', 'æ' => 'ae', 'ß' => 'ss',
    ];

    /*
        - Assemble et normalise : accents retirés, puis TOUT ce qui n'est ni lettre ni chiffre est SUPPRIMÉ — espaces compris —, le reste en majuscules. Les fragments vides sont ignorés, ce qui permet d'appeler 'Cle::depuis($nom, $prenom)' même quand le prénom est absent.

        - Supprimer et non remplacer par une espace, c'est le point important. Remplacer laissait passer les doublons les plus fréquents du terrain : « N'GUESSAN » donnait « N GUESSAN » et « NGUESSAN » restait « NGUESSAN », donc deux planteurs pour la même personne. Idem « KOUAME-YAO » contre « KOUAME YAO ». En supprimant, les trois convergent.

        - Conséquence assumée : « MARIE CLAIRE » et « MARIECLAIRE » deviennent le même planteur. C'est exactement ce qu'on veut d'un référentiel saisi à la main sur un pont bascule.
    */
    public static function depuis(?string ...$fragments): string
    {
        $parties = array_filter(
            array_map(static fn(?string $fragment) => trim((string) $fragment), $fragments),
            static fn(string $fragment) => $fragment !== ''
        );

        $valeur = strtr(implode('', $parties), self::TRANSLITTERATION);

        return mb_strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $valeur) ?? $valeur);
    }
}
