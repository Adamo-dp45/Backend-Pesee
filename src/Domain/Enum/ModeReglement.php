<?php

namespace App\Domain\Enum;

/*
    - Comment le planteur est réglé. Le mode n'est pas un libellé décoratif : il décide de TOUT le reste du versement — la caisse qui se vide, la nécessité d'un numéro de téléphone, le passage ou non par l'agrégateur, et le moment où le versement est confirmé.

    - C'est pour cela que la correspondance avec la caisse vit ici et nulle part ailleurs : un mode ajouté plus tard (virement) se déclare dans ce fichier et le reste suit. La dispersion de cette règle dans les services était le défaut de l'ancien modèle, où « payer » voulait dire « mobile money » sans que ce soit écrit nulle part.

    - LE CHÈQUE EST LE PREMIER MODE QUI NE VIDE PAS LA CAISSE DU POSTE. Il est tiré sur le compte en banque de l'entreprise : le tiroir de l'opérateur et son flotte ne bougent pas, et c'est le solde bancaire de l'entreprise qui baisse. Voir 'TypeCaisse::celles()' et 'TypeMouvement::pourUnVersement()'.
*/
enum ModeReglement: string
{
    case ESPECES = 'ESPECES';
    case MOBILE_MONEY = 'MOBILE_MONEY';
    case CHEQUE = 'CHEQUE';

    public function libelle(): string
    {
        return match($this) {
            self::ESPECES => 'Espèces',
            self::MOBILE_MONEY => 'Mobile money',
            self::CHEQUE => 'Chèque',
        };
    }

    /** La caisse qui se vide. C'est LA règle que porte ce fichier. */
    public function caisse(): TypeCaisse
    {
        return match($this) {
            self::ESPECES => TypeCaisse::PHYSIQUE,
            self::MOBILE_MONEY => TypeCaisse::NUMERIQUE,
            self::CHEQUE => TypeCaisse::BANCAIRE,
        };
    }

    /*
        - Un versement en espèces ne part chez personne : les billets changent de main devant le poste, et l'opérateur n'enregistre que ce qui vient d'avoir lieu. Il est donc confirmé tout de suite — pas de passerelle, pas de webhook, pas de réconciliation, et rien n'est jamais « en vol ».

        - LE CHÈQUE EST DANS LE MÊME CAS, avec une réserve qu'il faut connaître : remettre le chèque solde la pesée sur-le-champ, alors que l'encaissement, lui, prendra des jours. L'application ne saura jamais s'il a été encaissé — un chèque sans provision ne reviendra pas ici. C'est un compromis assumé, pas un oubli.
    */
    public function passeParLaPasserelle(): bool
    {
        return $this === self::MOBILE_MONEY;
    }

    /** Vrai si un numéro de téléphone est indispensable : sans lui, l'agrégateur n'a nulle part où envoyer. */
    public function exigeUnNumero(): bool
    {
        return $this->passeParLaPasserelle();
    }

    /*
        - Le numéro du chèque est la SEULE référence qui permettra le rapprochement avec le relevé bancaire : sans lui, deux chèques du même montant au même planteur sont indiscernables le jour où la banque est consultée.
    */
    public function exigeUnNumeroDeCheque(): bool
    {
        return $this === self::CHEQUE;
    }

    /*
        - LES MODES OUVERTS À LA SAISIE, et c'est une liste plus courte que l'énumération.

        - LE MOBILE MONEY EST FERMÉ tant qu'un vrai agrégateur n'est pas en place : la passerelle
          actuelle est simulée, et proposer le mode ferait sortir de l'argent de la caisse pour un
          envoi qui n'arrive nulle part. La ligne est COMMENTÉE et non supprimée — c'est elle
          qu'on décommentera le jour de la bascule, et rien d'autre.

        - LE CAS DE L'ÉNUMÉRATION RESTE, lui, et c'est indispensable : les versements déjà partis
          le portent en base, le webhook et la réconciliation continuent de les traiter, et les
          écrans savent toujours les nommer. Fermer un mode n'est pas effacer son histoire.
    */
    /** @return list<self> */
    public static function toutes(): array
    {
        return [
            self::ESPECES,
            // self::MOBILE_MONEY, — rouvrir ici quand l'agrégateur sera en place
            self::CHEQUE,
        ];
    }

    /** Les mêmes en chaînes, pour les contraintes de validation et les listes déroulantes. */
    /** @return list<string> */
    public static function valeurs(): array
    {
        return array_map(static fn(self $mode) => $mode->value, self::toutes());
    }
}
