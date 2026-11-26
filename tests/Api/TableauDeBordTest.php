<?php

namespace App\Tests\Api;

use App\Domain\Enum\Role;
use App\Tests\InvariantTestCase;

/*
    - L'ACCUEIL NE DÉCIDE RIEN, MAIS IL ANNONCE DES CHIFFRES, et un chiffre faux sur la page
      d'ouverture oriente toute la journée. C'est la seule chose que ces cas gardent : non pas des
      invariants d'argent — l'écran est en lecture seule — mais l'ACCORD entre ce qu'il affiche et
      ce que les autres écrans disent de la même chose.

    - TROIS ACCORDS, ET CHACUN A UNE FAÇON DE SE ROMPRE EN SILENCE :
      · la créance de l'accueil contre celle du guichet — deux requêtes pour une même question ;
      · la créance contre le reste de la PÉRIODE — deux questions pour un même mot ;
      · ce qu'un opérateur reçoit — une dette d'entreprise servie à qui tient un tiroir.
*/
final class TableauDeBordTest extends InvariantTestCase
{
    /*
        - LE MÊME NOMBRE DES DEUX CÔTÉS, et c'est l'accord qui compte le plus : l'accueil annonce
          « créance totale », le guichet annonce « reste », et les deux écrans sont ouverts côte à
          côte toute la journée. Une divergence ne lèverait rien — deux chiffres plausibles, et
          personne pour savoir lequel croire.

        - Elle est tenue par construction, puisque le provider appelle 'totauxAPayer()' sans
          critères, exactement la méthode du guichet. Ce cas existe pour que ça reste vrai : le jour
          où quelqu'un écrit ici une requête « plus directe », il tombe.
    */
    public function testLaCreanceEstCelleDuGuichet(): void
    {
        $entreprise = $this->creerEntreprise('ACME');
        $site = $this->creerSite($entreprise);
        $admin = $this->creerUtilisateur($entreprise, Role::ADMIN, 'patron@acme.test');

        $hevea = $this->creerProduit($site, 'Hévéa', 400);
        $sansPrix = $this->creerProduit($site, 'Cacao', 0);
        $planteur = $this->creerPlanteur($site, 'Kouame');

        $this->creerPesee($site, $hevea, $planteur, 1_000, '1');
        $this->creerPesee($site, $hevea, $planteur, 2_000, '2');
        $this->creerPesee($site, $sansPrix, $planteur, 3_000, '3');

        $this->em->flush();

        $this->appeler('GET', '/api/stats/tableau-de-bord', $admin);
        $accueil = $this->reponse()['creance'];

        $this->appeler('GET', '/api/stats/a-payer', $admin);
        $guichet = $this->reponse()['totaux'];

        self::assertSame($guichet['reste'], $accueil['total'], 'La créance de l\'accueil n\'est plus celle du guichet');
        self::assertSame($guichet['resteProduit'], $accueil['produit']);
        self::assertSame($guichet['resteTransport'], $accueil['transport']);
        self::assertSame($guichet['nbPesees'], $accueil['nbPesees']);
        self::assertSame($guichet['nbSansPrix'], $accueil['nbSansPrix']);

        /* Le produit non tarifé doit être COMPTÉ et ne rien PESER : c'est la bande d'alerte de l'accueil. */
        self::assertSame(1, $accueil['nbSansPrix']);
        self::assertSame(1_200_000, $accueil['total']);
    }

    /*
        - DEUX QUESTIONS, DEUX NOMBRES, ET ILS PORTAIENT LE MÊME NOM. « Reste sur la période » ne
          compte que les pesées du mois affiché ; « créance totale » compte tout ce qui n'est pas
          soldé, quelle que soit la date. Une pesée de l'an dernier est due aujourd'hui.

        - CE CAS EST LA RAISON D'ÊTRE DES DEUX TUILES. Tant qu'elles donnent le même chiffre sur le
          jeu de démonstration, rien ne prouve qu'elles mesurent deux choses — ici, elles diffèrent.
    */
    public function testLaCreanceIgnoreLaPeriodeQueLeResteSuit(): void
    {
        $entreprise = $this->creerEntreprise('ACME');
        $site = $this->creerSite($entreprise);
        $admin = $this->creerUtilisateur($entreprise, Role::ADMIN, 'patron@acme.test');

        $hevea = $this->creerProduit($site, 'Hévéa', 400);
        $planteur = $this->creerPlanteur($site, 'Kouame');

        $this->creerPesee($site, $hevea, $planteur, 1_000, '1');

        /* Hors de la période par défaut, qui est le mois en cours — et toujours due. */
        $ancienne = $this->creerPesee($site, $hevea, $planteur, 2_000, '2');
        $ancienne->setPeseeAt2(new \DateTimeImmutable('-14 months'));

        $this->em->flush();

        $this->appeler('GET', '/api/stats/tableau-de-bord', $admin);
        $reponse = $this->reponse();

        self::assertSame(400_000, $reponse['totaux']['montantRestant'], 'Le reste de la période a ramassé une pesée d\'un autre mois');
        self::assertSame(1_200_000, $reponse['creance']['total'], 'La créance s\'est laissé borner par la période');
        self::assertSame(2, $reponse['creance']['nbPesees']);
    }

    /*
        - L'ÉPARGNE DUE NE SE RETRANCHE DE RIEN, et c'est le piège que ce cas garde. Au prélèvement,
          pas un billet ne bouge : l'argent reste dans le tiroir et c'est une DETTE qui naît à côté.
          La soustraire du disponible ferait disparaître deux fois des espèces qui sont bien là, et
          l'opérateur refuserait de payer un planteur avec de l'argent qu'il a sous la main.

        - Sans versement en vol, 'disponible' vaut donc EXACTEMENT le solde, dette ou pas.
    */
    public function testLEpargneDueNeSeRetranchePasDuDisponible(): void
    {
        $entreprise = $this->creerEntreprise('ACME', solde: 0, soldePhysique: 5_000_000);
        $site = $this->creerSite($entreprise, soldePhysique: 2_000_000);
        $admin = $this->creerUtilisateur($entreprise, Role::ADMIN, 'patron@acme.test');

        $hevea = $this->creerProduit($site, 'Hévéa', 400);
        $planteur = $this->creerPlanteur($site, 'Kouame');

        $this->ouvrirPrelevement($planteur, $hevea, 20);
        $this->creerPeseeArbitree($site, $hevea, $planteur, 1_000, '1');

        $this->em->flush();

        $this->appeler('GET', '/api/stats/tableau-de-bord', $admin);
        $soldes = $this->reponse()['soldes'];

        self::assertSame(20_000, $soldes['epargneDue'], 'La dette d\'épargne n\'est pas celle qu\'on a ouverte');
        self::assertSame(0, $soldes['immobilise'], 'Rien n\'est parti chez une passerelle dans ce cas');
        self::assertSame($soldes['sites'], $soldes['disponible'], 'L\'épargne due a été retranchée du disponible');
    }

    /*
        - UN OPÉRATEUR TIENT UN TIROIR, PAS UNE ENTREPRISE. Le solde global et la dette d'épargne ne
          le regardent pas, et ça se filtre dans le provider : laissés au gabarit, les deux montants
          voyageraient quand même dans le JSON, où ils se lisent en deux clics.

        - NULL ET NON ZÉRO : un zéro se lirait « l'entreprise n'a plus rien », ce qui est une tout
          autre nouvelle. C'est aussi ce que l'écran teste pour masquer la tuile.
    */
    public function testUnOperateurNeVoitNiLaTresorerieNiLaDette(): void
    {
        $entreprise = $this->creerEntreprise('ACME', soldePhysique: 5_000_000);
        $site = $this->creerSite($entreprise, soldePhysique: 2_000_000);
        $operateur = $this->creerUtilisateur($entreprise, Role::OPERATEUR, 'awa@acme.test');
        $this->affecterOperateur($site, $operateur);

        $hevea = $this->creerProduit($site, 'Hévéa', 400);
        $planteur = $this->creerPlanteur($site, 'Kouame');
        $this->ouvrirPrelevement($planteur, $hevea, 20);
        $this->creerPeseeArbitree($site, $hevea, $planteur, 1_000, '1');

        $this->em->flush();

        $this->appeler('GET', '/api/stats/tableau-de-bord', $operateur);
        $soldes = $this->reponse()['soldes'];

        self::assertNull($soldes['entreprise'], 'La trésorerie de l\'entreprise est servie à un opérateur');
        self::assertNull($soldes['epargneDue'], 'La dette d\'épargne est servie à un opérateur');

        /* Ce qu'il DOIT voir, en revanche : son tiroir, et ce qu'il peut en sortir. */
        self::assertSame(2_000_000, $soldes['sites']);
        self::assertSame(2_000_000, $soldes['disponible']);
    }
}
