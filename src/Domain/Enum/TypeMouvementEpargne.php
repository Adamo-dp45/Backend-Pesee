<?php

namespace App\Domain\Enum;

/*
    - Motif d'une ligne du livre d'épargne. Volontairement SÉPARÉ de 'TypeMouvement', qui règne sur
      les caisses : là-bas tout mouvement va d'un compte à un autre, parce que l'argent se déplace
      sans jamais se créer. Ici, rien ne se déplace.

    - Un prélèvement ne sort d'aucune caisse : au moment où il est écrit, le planteur n'est pas
      encore passé au guichet. On ne fait que couper en deux ce que l'entreprise lui doit — une
      part qu'on lui remettra en main, une part qu'on lui garde. Inscrire cela dans le grand livre
      des caisses aurait rompu le chaînage des soldes et fait apparaître de l'argent.

    - 'RETRAIT' viendra avec le retrait de l'épargne, et débitera. Il n'existe pas encore : un
      compte ne fait, pour l'instant, que se remplir.
*/
enum TypeMouvementEpargne: string
{
    case PRELEVEMENT = 'PRELEVEMENT'; // Mise en compte d'une part de ce que vaut une pesée

    public function libelle(): string
    {
        return match($this) {
            self::PRELEVEMENT => 'Mise en compte',
        };
    }

    public function sens(): SensMouvement
    {
        return match($this) {
            self::PRELEVEMENT => SensMouvement::CREDIT,
        };
    }
}
