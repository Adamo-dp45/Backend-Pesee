<?php

namespace App\Entity\Output\Corbeille;

use Symfony\Component\Serializer\Attribute\Groups;

/*
    - Une ligne de la corbeille. Ce n'est pas l'entité supprimée : c'est ce qu'il faut savoir pour
      décider de son sort — d'où elle vient, qui l'a supprimée, et si elle peut disparaître pour de bon.

    - 'supprimePar' porte un NOM et non l'identifiant brut : 'deletedBy' est un entier, illisible tel
      quel, et c'est justement la question qu'on se pose devant une corbeille.
*/
final class CorbeilleItemDto
{
    #[Groups(['read:Corbeille'])]
    public string $type = '';

    #[Groups(['read:Corbeille'])]
    public string $typeLibelle = '';

    #[Groups(['read:Corbeille'])]
    public int $id = 0;

    #[Groups(['read:Corbeille'])]
    public string $libelle = '';

    /** Le pont bascule d'où venait l'élément : sans lui, deux « Hévéa » de deux postes se confondent. */
    #[Groups(['read:Corbeille'])]
    public ?string $contexte = null;

    #[Groups(['read:Corbeille'])]
    public ?string $entreprise = null;

    #[Groups(['read:Corbeille'])]
    public ?int $entrepriseId = null;

    #[Groups(['read:Corbeille'])]
    public ?string $supprimeLe = null;

    #[Groups(['read:Corbeille'])]
    public ?string $supprimePar = null;

    /*
        - Le nombre de pesées qui référencent encore l'élément. C'est ce chiffre qui décide de la
          purge : un produit à trois mille pesées porte tout un historique de montants.
    */
    #[Groups(['read:Corbeille'])]
    public int $peseesCount = 0;

    #[Groups(['read:Corbeille'])]
    public bool $purgeable = false;

    /*
        - Pourquoi la purge est impossible, ou null si elle l'est. Un motif plutôt qu'un booléen seul :
          l'écran doit expliquer, pas se contenter de griser un bouton.
    */
    #[Groups(['read:Corbeille'])]
    public ?string $motifNonPurgeable = null;

    /*
        - Pourquoi la restauration est impossible, ou null. Le cas existe : un référentiel homonyme a
          pu être recréé — à la main ou par le poste — pendant que celui-ci dormait à la corbeille.
    */
    #[Groups(['read:Corbeille'])]
    public ?string $motifNonRestaurable = null;

    #[Groups(['read:Corbeille'])]
    public bool $restaurable = true;
}
