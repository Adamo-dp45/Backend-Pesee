<?php

namespace App\Entity\Interface;

use App\Domain\Enum\TypeCaisse;

/*
    - Ce que 'CaisseService' a besoin de savoir d'un compte, et rien de plus : lire et poser le solde d'une de ses deux caisses. 'Entreprise' et 'Site' l'implémentent tous les deux — c'est ce qui permet d'écrire les mouvements une seule fois au lieu d'une version par niveau.

    - Aucune méthode pour DÉPLACER de l'argent : un compte ne se crédite pas lui-même. Tout passe par 'CaisseService', qui verrouille, contrôle et écrit la ligne de grand livre correspondante. Un setter public de solde existe toujours sur les entités, mais la règle du projet est inchangée : personne ne l'appelle en dehors de ce service.
*/
interface CompteInterface
{
    public function getSoldeDe(TypeCaisse $caisse): int;

    public function setSoldeDe(TypeCaisse $caisse, int $montant): static;

    /** Le libellé du compte tel qu'il apparaît dans un message d'erreur destiné à l'opérateur. */
    public function getLibelleCompte(): string;
}
