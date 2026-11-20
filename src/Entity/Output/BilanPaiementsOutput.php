<?php

namespace App\Entity\Output;

use Symfony\Component\Serializer\Attribute\Groups;

/*
    - Le bilan des VERSEMENTS d'une période : ce qui est sorti des caisses, par quel moyen, depuis
      quel poste, et ce qui n'est pas encore confirmé.

    - Trois familles qu'il ne faut jamais additionner : le CONFIRMÉ, l'EN VOL et l'ÉCHOUÉ. Un
      versement en vol a quitté la caisse mais peut encore revenir ; un versement échoué a été
      remboursé et ne doit compter nulle part. Un total unique les mélangerait, et il serait faux
      dans les deux sens à la fois.
*/
final class BilanPaiementsOutput
{
    /** @var array{du: string|null, au: string|null} */
    #[Groups(['read:Statistique'])]
    public array $periode = ['du' => null, 'au' => null];

    /**
     * @var array{nbVersements: int, montantVerse: int, montantProduit: int, montantTransport: int, nbEnVol: int, montantEnVol: int, nbRembourse: int, montantRembourse: int, nbRetraits: int, montantRetraits: int}
     */
    #[Groups(['read:Statistique'])]
    public array $totaux = [
        'nbVersements' => 0,     // Confirmés seulement : c'est ce qui a réellement changé de main
        'montantVerse' => 0,
        'montantProduit' => 0,   // La part récolte du confirmé
        'montantTransport' => 0, // La part transport du confirmé
        'nbEnVol' => 0,          // Parti de la caisse, sans réponse de la passerelle
        'montantEnVol' => 0,
        'nbRembourse' => 0,      // Échoué ou annulé : dans les deux cas la caisse a retrouvé son argent
        'montantRembourse' => 0,
        'nbRetraits' => 0,       // Les versements SANS pesée : l'épargne qu'on rend au planteur
        'montantRetraits' => 0,
    ];

    /** @var list<array{mode: string, libelle: string, nombre: int, montant: int, nbEnVol: int, montantEnVol: int}> */
    #[Groups(['read:Statistique'])]
    public array $parMode = [];

    /** @var list<array{siteId: int, site: string, nombre: int, montant: int, nbEnVol: int, montantEnVol: int}> */
    #[Groups(['read:Statistique'])]
    public array $parSite = [];
}
