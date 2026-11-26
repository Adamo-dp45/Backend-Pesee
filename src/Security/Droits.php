<?php

namespace App\Security;

use App\Domain\Enum\Role;

/*
    - Qui peut quoi, par rôle et par ressource. C'est la SEULE source de vérité des droits grossiers
      de l'application — API et frontend confondus.

    - La table est lue à DEUX endroits, et c'est tout l'intérêt de l'avoir sortie du voter :
        · 'AccesVoter', qui tranche chaque appel à l'API ;
        · '/me', qui la renvoie À PLAT au frontend pour qu'il sache quels boutons afficher.
      Le frontend en tenait jusqu'ici une copie manuscrite, à reporter à chaque changement. Une
      copie finit par diverger, et celle-ci divergeait en silence : un bouton manquant ne lève
      aucune erreur, il laisse seulement croire que le droit n'existe pas.

    - Ce que la table ne sait PAS dire reste ailleurs : le périmètre ('Perimetre') et la hiérarchie
      de gestion des comptes ('UserGuard'). Un droit accordé ici peut donc être refusé sur un objet
      précis. C'est pourquoi ce que reçoit le frontend n'est pas une autorisation mais une
      PLAUSIBILITÉ : l'API refait le calcul complet à chaque appel.
*/
final class Droits
{
    public const VOIR = 'VOIR';
    public const CREER = 'CREER';
    public const MODIFIER = 'MODIFIER';
    public const SUPPRIMER = 'SUPPRIMER';
    public const BLOQUER = 'BLOQUER';
    public const ATTRIBUER = 'ATTRIBUER';
    public const RECHARGER = 'RECHARGER';
    public const TRAITER = 'TRAITER';
    public const PAYER = 'PAYER';
    public const PROMOUVOIR = 'PROMOUVOIR';
    public const PRELEVER = 'PRELEVER'; // Ouvrir ou arrêter la mise en compte d'un planteur
    /*
        - JOINDRE une pièce à une ligne d'argent — un reçu signé, un bordereau de banque, le talon
          d'un chèque. C'est une action à part et non 'MODIFIER' : une ligne d'argent ne se modifie
          JAMAIS, et confondre les deux ferait croire qu'on peut corriger un versement.

        - LA LECTURE, ELLE, N'A PAS D'ACTION. Une pièce se lit avec la ligne qui la porte : le
          contrôle se fait sur 'VOIR' de la CIBLE, et rien n'est à tenir d'accord ici. Qui voit le
          versement voit son justificatif, par construction et non par une seconde table.
    */
    public const JOINDRE = 'JOINDRE';

    /** @var list<string> */
    public const ACTIONS = [
        self::VOIR, self::CREER, self::MODIFIER, self::SUPPRIMER, self::BLOQUER,
        self::ATTRIBUER, self::RECHARGER, self::TRAITER, self::PAYER, self::PROMOUVOIR,
        self::PRELEVER, self::JOINDRE,
    ];

    /*
        - Une ressource absente de la ligne d'un rôle lui est fermée, et un rôle absent de la table
          n'a aucun droit — c'est ce qui écarte le compte machine ('ROLE_SITE'), qui ne sait que
          déposer des pesées.

        - Les ressources des étapes suivantes sont déjà listées : la table se lit d'un bloc, et
          l'oubli d'une ligne se voit tout de suite.
    */
    private const TABLE = [
        Role::SUPER_ADMIN->value => [
            'Entreprise' => [self::VOIR, self::CREER, self::MODIFIER, self::BLOQUER],
            'User' => [self::VOIR, self::CREER, self::MODIFIER, self::SUPPRIMER, self::BLOQUER, self::PROMOUVOIR],
            /*
                - 'BLOQUER' lui revient au même titre qu'à l'administrateur du client : c'est lui qui
                  met les postes en service, donc lui qu'on appelle quand l'un d'eux déraille — et il
                  peut avoir à le couper sans attendre que le client s'en aperçoive.
                - Il n'a en revanche ni 'ATTRIBUER' ni les ressources d'exploitation : couper un poste
                  n'est pas toucher à la caisse qu'il tient.
            */
            'Site' => [self::VOIR, self::CREER, self::MODIFIER, self::BLOQUER],
        ],
        Role::ADMIN->value => [
            'Entreprise' => [self::VOIR, self::MODIFIER, self::RECHARGER],
            'User' => [self::VOIR, self::CREER, self::MODIFIER, self::SUPPRIMER, self::BLOQUER, self::PROMOUVOIR],
            'Site' => [self::VOIR, self::MODIFIER, self::BLOQUER, self::ATTRIBUER],
            'Produit' => [self::VOIR, self::CREER, self::MODIFIER, self::SUPPRIMER],
            'Fournisseur' => [self::VOIR, self::CREER, self::MODIFIER, self::SUPPRIMER, self::PRELEVER], // Lui seul ouvre et arrête une mise en compte, et pose un tarif propre
            /*
                - CRÉER ET TARIFER une catégorie est à lui seul : sa grille vaut pour tous ses planteurs,
                  sur tous les postes, et chaque case revalorise des pesées.
                - CLASSER un planteur, en revanche, suit le droit de modifier sa fiche — l'opérateur
                  l'a, comme il a déjà le prix du transport et même le prix d'un produit. Lui refuser
                  le classement serait le seul prix qu'on lui interdirait, sans raison.
            */
            'CategorieFournisseur' => [self::VOIR, self::CREER, self::MODIFIER, self::SUPPRIMER],
            /*
                - Un barème décide de ce qu'un trajet rapporte, pour tous ceux qui le suivent à la
                  fois. Même nature qu'un prix, mêmes mains.
            */
            'BaremeTransport' => [self::VOIR, self::CREER, self::MODIFIER, self::SUPPRIMER],
            /*
                - 'MODIFIER' SUR UNE PESÉE NE VEUT DIRE QU'UNE CHOSE : écrire son prix au kilo, la
                  prime. Rien d'autre d'une pesée ne se modifie depuis le web — les poids, le
                  ticket et les dates viennent du pont bascule et n'en bougent plus.

                - À L'ADMINISTRATEUR SEUL, comme tous les autres prix. L'opérateur du guichet a le
                  planteur devant lui au moment où celui-ci réclame sa prime : lui donner le droit
                  ferait négocier le prix à la barrière, ce que la refonte du référentiel a
                  précisément voulu supprimer.
            */
            'Operation' => [self::VOIR, self::MODIFIER],
            /* 'JOINDRE' sur les lignes d'argent : c'est lui qui tient la comptabilité et qui classe les bordereaux. */
            'Paiement' => [self::VOIR, self::PAYER, self::JOINDRE],
            'MouvementCaisse' => [self::VOIR, self::JOINDRE],
            'MouvementEpargne' => [self::VOIR],
            'DemandeSolde' => [self::VOIR, self::TRAITER, self::JOINDRE],
            'DemandePrelevement' => [self::VOIR, self::TRAITER, self::JOINDRE],
            'DemandeRetrait' => [self::VOIR, self::TRAITER, self::JOINDRE], // Lui seul accorde un retrait : l'argent qu'on gardait sort de la caisse
            'SynchronisationLot' => [self::VOIR],
            'Activite' => [self::VOIR], // Le journal des decisions : une lecture de supervision
        ],
        Role::AGENT->value => [
            'Entreprise' => [self::VOIR],
            'User' => [self::VOIR, self::CREER, self::MODIFIER, self::BLOQUER, self::PROMOUVOIR], // Sur des opérateurs seulement : c'est 'UserGuard' qui borne la cible
            'Site' => [self::VOIR],
            'Produit' => [self::VOIR],
            'Fournisseur' => [self::VOIR],
            'CategorieFournisseur' => [self::VOIR], // Il supervise des montants : il doit pouvoir lire d'où ils sortent
            'BaremeTransport' => [self::VOIR],
            'Operation' => [self::VOIR],
            'Paiement' => [self::VOIR],
            'MouvementCaisse' => [self::VOIR],
            'MouvementEpargne' => [self::VOIR],
            'DemandeSolde' => [self::VOIR],
            'DemandePrelevement' => [self::VOIR],
            'DemandeRetrait' => [self::VOIR],
            'SynchronisationLot' => [self::VOIR],
            'Activite' => [self::VOIR],
        ],
        Role::OPERATEUR->value => [
            'Site' => [self::VOIR],
            /*
                - EN LECTURE SEULE SUR LES DEUX RÉFÉRENTIELS. Il les voyait et les écrivait : il
                  tarifait un produit, créait un planteur et corrigeait sa fiche. Le référentiel
                  appartient désormais à l'ENTREPRISE, pas au poste — un prix saisi à une barrière
                  vaut à toutes les autres, et une fiche corrigée l'est pour tout le monde.

                - CE QUE ÇA CHANGE AU GUICHET, et il faut le savoir : un planteur qui se présente
                  pour la première fois ne se crée plus depuis le web par l'opérateur, et un numéro
                  de téléphone faux ne se corrige plus par lui. Le poste, lui, continue de créer
                  produits et planteurs en synchronisant : il porte 'ROLE_SITE', qui n'est pas dans
                  cette table et ne passe pas par le voter.
            */
            'Produit' => [self::VOIR],
            'Fournisseur' => [self::VOIR],
            /*
                - EN LECTURE, et cette lecture n'est pas optionnelle : la classe d'un planteur
                  décide de ce qu'il touche au kilo, et c'est l'opérateur qui a le planteur devant
                  lui quand celui-ci conteste un montant. Sans le droit, la fiche afficherait un
                  prix sans jamais pouvoir dire d'où il vient.
            */
            'CategorieFournisseur' => [self::VOIR],
            /* Même raison : le barème décide de ce que son trajet rapporte. */
            'BaremeTransport' => [self::VOIR],
            'Operation' => [self::VOIR],
            /*
                - 'JOINDRE' SUR CE QU'IL PRODUIT, et c'est le cœur du besoin : le reçu signé, c'est
                  lui qui l'a entre les mains, au guichet, avec le planteur devant lui. Le faire
                  remonter à un administrateur pour qu'il le scanne ferait voyager du papier.
                - PAS sur 'MouvementCaisse' : il ne crée aucune écriture de caisse, elles sont la
                  CONSÉQUENCE de ses gestes. Il n'a aucun bordereau à y joindre.
            */
            'Paiement' => [self::VOIR, self::PAYER, self::JOINDRE],
            'MouvementCaisse' => [self::VOIR],
            'MouvementEpargne' => [self::VOIR],
            'DemandeSolde' => [self::VOIR, self::CREER, self::MODIFIER, self::JOINDRE], // Il corrige et annule la sienne ; « tant qu'elle n'est pas traitée » est vérifié par les processors
            'DemandePrelevement' => [self::VOIR, self::CREER, self::MODIFIER, self::JOINDRE], // Il a le planteur devant lui : c'est à lui qu'on demande d'épargner
            'DemandeRetrait' => [self::VOIR, self::CREER, self::MODIFIER, self::JOINDRE], // Il a le planteur devant lui, qui vient chercher son argent
        ],
    ];

    /**
     * Les actions que ce rôle porte sur cette ressource, désignée par son nom court d'entité.
     *
     * @return list<string>
     */
    public static function actions(?Role $role, string $ressource): array
    {
        return self::TABLE[$role?->value][$ressource] ?? [];
    }

    /*
        - Tous les droits d'un rôle, à plat et DANS LE VOCABULAIRE DU FRONTEND :
          'MouvementCaisse' + 'VOIR' donne 'MOUVEMENTCAISSE_VOIR'. C'est la forme sous laquelle il
          les interroge, donc celle qu'on lui envoie — il n'a plus rien à transformer, donc plus
          aucune occasion de se tromper.
        - Un rôle hors table repart avec une liste vide, et le frontend n'affiche alors aucun
          bouton : le refus par défaut vaut mieux qu'un écran qui s'ouvre pour échouer.
    */
    /** @return list<string> */
    public static function aPlat(?Role $role): array
    {
        $droits = [];

        foreach(self::TABLE[$role?->value] ?? [] as $ressource => $actions) {
            foreach($actions as $action) {
                $droits[] = strtoupper($ressource) . '_' . $action;
            }
        }

        return $droits;
    }
}
