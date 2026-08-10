<?php

namespace App\Domain\Paiement;

use App\Entity\Paiement;

/*
    - Frontière avec l'agrégateur mobile money. Le domaine ne connaît que cette interface : brancher CinetPay, PayDunya ou un autre revient à écrire une classe de plus, sans toucher ni à la caisse ni aux paiements.
    - La passerelle active est désignée par un alias dans 'services.yaml'. En changer, c'est changer cette ligne — pas besoin d'un registre ni d'une variable d'environnement tant qu'il n'y en a qu'une à la fois.
*/
interface PasserellePaiementInterface
{
    /** Le nom conservé sur le versement, pour savoir plus tard qui l'a traité. */
    public function nom(): string;

    /**
     * Demande le versement. Ne lève PAS d'exception sur un refus de l'agrégateur : un refus est une réponse, pas un incident, et il doit produire un remboursement propre.
     */
    public function verser(Paiement $paiement): ResultatVersement;

    /**
     * Réinterroge l'état d'un versement. Utilisé par la réconciliation quand aucun webhook n'est arrivé : sans cela, un webhook perdu immobiliserait les fonds indéfiniment.
     */
    public function verifier(string $reference): ResultatVersement;

    /**
     * Vérifie l'authenticité d'une notification entrante. L'agrégateur n'a pas de compte chez nous : c'est la signature du corps qui l'authentifie, jamais un jeton.
     *
     * @param array<string, mixed> $corps
     */
    public function notificationAuthentique(array $corps, ?string $signature): bool;

    /**
     * Traduit une notification en résultat.
     *
     * @param array<string, mixed> $corps
     */
    public function lireNotification(array $corps): ResultatVersement;
}
