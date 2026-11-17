<?php

namespace App\Domain\Enum;

/*
    - Motif d'une ligne du livre d'épargne. Volontairement SÉPARÉ de 'TypeMouvement', qui règne sur
      les caisses : là-bas tout mouvement va d'un compte à un autre, parce que l'argent se déplace
      sans jamais se créer. Ici, le compte d'un planteur n'a pas de contrepartie dans ce livre.

    - Un prélèvement ne sort d'aucune caisse : au moment où il est écrit, le planteur n'est pas
      encore passé au guichet. On ne fait que couper en deux ce que l'entreprise lui doit — une
      part qu'on lui remettra en main, une part qu'on lui garde. Inscrire cela dans le grand livre
      des caisses aurait rompu le chaînage des soldes et fait apparaître de l'argent.

    - LE RETRAIT, LUI, A BIEN UNE CONTREPARTIE — mais dans l'AUTRE livre : la caisse qui remet
      l'argent au planteur. Les deux écritures sont faites dans le même geste, et dans le même
      ordre que pour un versement sur pesée : on débite d'abord, on rend si la passerelle échoue.

    - 'ANNULATION_RETRAIT' est à 'RETRAIT' ce que 'REMBOURSEMENT' est à 'PAIEMENT' : une
      contrepartie, jamais une rature. Ce livre ne se réécrit pas plus que l'autre.
*/
enum TypeMouvementEpargne: string
{
    case PRELEVEMENT = 'PRELEVEMENT';               // Mise en compte d'une part de ce que vaut une pesée
    case RETRAIT = 'RETRAIT';                       // Le planteur reprend tout ou partie de ce qu'on lui gardait
    case ANNULATION_RETRAIT = 'ANNULATION_RETRAIT'; // Le versement a échoué : ce qui avait été retiré lui revient

    public function libelle(): string
    {
        return match($this) {
            self::PRELEVEMENT => 'Mise en compte',
            self::RETRAIT => 'Retrait',
            self::ANNULATION_RETRAIT => 'Retrait annulé',
        };
    }

    public function sens(): SensMouvement
    {
        return match($this) {
            self::PRELEVEMENT, self::ANNULATION_RETRAIT => SensMouvement::CREDIT,
            self::RETRAIT => SensMouvement::DEBIT,
        };
    }
}
