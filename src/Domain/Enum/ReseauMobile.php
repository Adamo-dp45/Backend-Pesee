<?php

namespace App\Domain\Enum;

/*
    - Réseau mobile money du planteur, nécessaire pour router le versement : la passerelle attend cette information, car un même numéro peut exister chez plusieurs opérateurs.
    - La liste couvre l'Afrique de l'Ouest ; ajouter un réseau se limite à un 'case' de plus, les passerelles se chargeant de la correspondance avec leurs propres codes.
*/
enum ReseauMobile: string
{
    case ORANGE = 'ORANGE';
    case MTN = 'MTN';
    case MOOV = 'MOOV';
    case WAVE = 'WAVE';

    public function libelle(): string
    {
        return match($this) {
            self::ORANGE => 'Orange Money',
            self::MTN => 'MTN MoMo',
            self::MOOV => 'Moov Money',
            self::WAVE => 'Wave',
        };
    }

    /** @return list<string> */
    public static function valeurs(): array
    {
        return array_column(self::cases(), 'value');
    }
}
