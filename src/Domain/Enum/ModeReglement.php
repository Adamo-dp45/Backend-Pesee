<?php

namespace App\Domain\Enum;

/*
    - Comment le planteur est réglé. Le mode n'est pas un libellé décoratif : il décide de TOUT le reste du versement — la caisse qui se vide, la nécessité d'un numéro de téléphone, le passage ou non par l'agrégateur, et le moment où le versement est confirmé.

    - C'est pour cela que la correspondance avec la caisse vit ici et nulle part ailleurs : un mode ajouté plus tard (chèque, virement) se déclare dans ce fichier et le reste suit. La dispersion de cette règle dans les services était le défaut de l'ancien modèle, où « payer » voulait dire « mobile money » sans que ce soit écrit nulle part.
*/
enum ModeReglement: string
{
    case ESPECES = 'ESPECES';
    case MOBILE_MONEY = 'MOBILE_MONEY';

    public function libelle(): string
    {
        return match($this) {
            self::ESPECES => 'Espèces',
            self::MOBILE_MONEY => 'Mobile money',
        };
    }

    /** La caisse qui se vide. C'est LA règle que porte ce fichier. */
    public function caisse(): TypeCaisse
    {
        return match($this) {
            self::ESPECES => TypeCaisse::PHYSIQUE,
            self::MOBILE_MONEY => TypeCaisse::NUMERIQUE,
        };
    }

    /*
        - Un versement en espèces ne part chez personne : les billets changent de main devant le poste, et l'opérateur n'enregistre que ce qui vient d'avoir lieu. Il est donc confirmé tout de suite — pas de passerelle, pas de webhook, pas de réconciliation, et rien n'est jamais « en vol ».
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

    /** @return list<self> */
    public static function toutes(): array
    {
        return [self::ESPECES, self::MOBILE_MONEY];
    }

    /** Les mêmes en chaînes, pour les contraintes de validation et les listes déroulantes. */
    /** @return list<string> */
    public static function valeurs(): array
    {
        return array_map(static fn(self $mode) => $mode->value, self::toutes());
    }
}
