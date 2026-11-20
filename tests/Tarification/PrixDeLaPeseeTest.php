<?php

namespace App\Tests\Tarification;

use App\Domain\Enum\ModeReglement;
use App\Domain\Enum\OrigineTarif;
use App\Domain\Enum\Role;
use App\Domain\Enum\StatutReglement;
use App\Domain\Enum\TypeActivite;
use App\Entity\Activite;
use App\Entity\CompteEpargne;
use App\Entity\Entreprise;
use App\Entity\Fournisseur;
use App\Entity\MouvementEpargne;
use App\Entity\Operation;
use App\Entity\Produit;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - CE QUE CES CAS TIENNENT : la prime. Un prix au kilo écrit sur UNE pesée, au-dessus des trois
      sauts de la résolution, parce qu'un geste commercial ne se range dans aucun référentiel — sur
      le produit il irait à tout le monde, sur le planteur à toutes ses livraisons à venir.

    - ET SURTOUT CE QUI SE CASSERAIT SANS EUX, car rien de tout cela ne se voit à l'écran :

      · LA FERMETURE AU PREMIER VERSEMENT. Un planteur reparti avec son argent ne doit pas voir le
        montant de sa pesée bouger dans son dos. Une garde qui fuit ici crée une dette que personne
        n'a décidée, dans un sens ou dans l'autre.

      · LA SURVIE DE LA PRIME. La prochaine tarification du produit la balaierait sans bruit si
        'MANUEL' ne figeait pas la part récolte : le planteur toucherait autre chose que ce qu'on lui
        a annoncé, et aucun écran ne dirait pourquoi.

      · LA MISE EN COMPTE QUI SUIT. Elle vaut 'taux x poids' et ne bouge donc presque jamais avec le
        prix — sauf quand le plafond mordait, et c'est précisément le cas qu'on n'irait pas chercher
        à la main. Dans l'autre sens elle ne redescend pas : reprendre une part déjà mise de côté est
        un retrait, qui passe par la demande du planteur.
*/
final class PrixDeLaPeseeTest extends InvariantTestCase
{
    private const PRIX = 200;  // Le kilo du produit
    private const POIDS = 500; // Donc 100 000 F de récolte au tarif ordinaire

    private Entreprise $entreprise;
    private Site $site;
    private Produit $produit;
    private Fournisseur $planteur;
    private User $admin;
    private User $operateur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = $this->creerEntreprise('ACME', 5_000_000, 5_000_000);
        $this->site = $this->creerSite($this->entreprise, 'ACM001', 5_000_000, 5_000_000);
        $this->produit = $this->creerProduit($this->site, 'Hévéa', self::PRIX);
        $this->planteur = $this->creerPlanteur($this->site, 'Kouassi', '0700000001');

        $this->admin = $this->creerUtilisateur($this->entreprise, Role::ADMIN, 'admin@acme.test');
        $this->operateur = $this->creerUtilisateur($this->entreprise, Role::OPERATEUR, 'operateur@acme.test');
        $this->affecterOperateur($this->site, $this->operateur);
    }

    /* ---- Le geste ----------------------------------------------------------------------------- */

    /*
        - LE CAS CENTRAL. La récolte valait 200 F le kilo parce que c'est le prix du produit ; elle
          passe à 260 pour cette livraison-là. Trois choses suivent d'un bloc : le montant dû,
          l'origine du prix, et ce qui reste payable au guichet.
    */
    public function testLAdministrateurEcritUnePrimeSurUnePesee(): void
    {
        $pesee = $this->creerPeseeArbitree($this->site, $this->produit, $this->planteur, self::POIDS);

        self::assertSame(self::PRIX * self::POIDS, $pesee->getMontantdu());

        $this->appeler('PATCH', $this->url($pesee), $this->admin, [
            'prixunitaire' => 260,
            'motif' => 'Récolte très propre',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $pesee = $this->relire($pesee);
        self::assertSame(260, $pesee->getPrixunitaire());
        self::assertSame(260 * self::POIDS, $pesee->getMontantdu());
        self::assertSame('Prix saisi sur la pesée', $pesee->getOrigineTarifLibelle());
        self::assertSame(130_000, $pesee->getPayableproduit(), 'Sans mise en compte, tout le montant dû est payable');
    }

    /* Le motif se relit dans le journal le jour où un autre planteur demandera pourquoi son voisin a touché plus. */
    public function testLaPrimeEtSonMotifSontJournalises(): void
    {
        $pesee = $this->creerPeseeArbitree($this->site, $this->produit, $this->planteur, self::POIDS);

        $this->appeler('PATCH', $this->url($pesee), $this->admin, [
            'prixunitaire' => 260,
            'motif' => 'Récolte très propre',
        ]);

        $lignes = $this->em->getRepository(Activite::class)->findBy(['type' => TypeActivite::PESEE_PRIX]);

        self::assertCount(1, $lignes);
        self::assertStringContainsString('200 F -> 260 F', (string) $lignes[0]->getLibelle());
        self::assertStringContainsString('Récolte très propre', (string) $lignes[0]->getLibelle());
    }

    /* Réécrire le prix qui s'y trouve déjà n'est pas un geste : rien à recalculer, rien à journaliser. */
    public function testLeMemePrixNeJournaliseRien(): void
    {
        $pesee = $this->creerPeseeArbitree($this->site, $this->produit, $this->planteur, self::POIDS);

        $this->appeler('PATCH', $this->url($pesee), $this->admin, ['prixunitaire' => self::PRIX]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $this->em->getRepository(Activite::class)->findBy(['type' => TypeActivite::PESEE_PRIX]));
    }

    /* ---- La fermeture ------------------------------------------------------------------------- */

    /*
        - LA GARDE QUI COMPTE. Le planteur est reparti avec une partie de sa récolte : le prix est
          clos. Le relever créerait une dette de l'entreprise que personne n'a décidée, le baisser en
          créerait une de lui.
    */
    public function testUnVersementSurLaRecolteFermeLePrix(): void
    {
        $pesee = $this->creerPeseeArbitree($this->site, $this->produit, $this->planteur, self::POIDS);

        $this->verser($pesee, produit: 10_000);

        $this->appeler('PATCH', $this->url($pesee), $this->admin, ['prixunitaire' => 260]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('ne se change plus', (string) ($this->reponse()['detail'] ?? ''));
        self::assertSame(self::PRIX, $this->relire($pesee)->getPrixunitaire(), 'Le prix n a pas bougé');
    }

    /*
        - LE TRANSPORT NE FERME PAS LA RÉCOLTE, et c'est tout l'intérêt : un planteur peut s'être
          fait payer son trajet sur une pesée dont le produit n'était pas tarifé. C'est exactement
          celle-là qu'on veut pouvoir tarifer ensuite.
    */
    public function testLeTransportDejaPayeNeFermePasLePrix(): void
    {
        $cacao = $this->creerProduit($this->site, 'Cacao', 0);
        $this->planteur->setBaremeTransport($this->creerBaremeAuKilo($this->entreprise, 15));
        $this->em->flush();

        $pesee = $this->creerPeseeArbitree($this->site, $cacao, $this->planteur, self::POIDS, '2');

        self::assertSame(0, $pesee->getPayableproduit(), 'Le produit n a pas de prix');

        $this->verser($pesee, transport: 15 * self::POIDS);

        $this->appeler('PATCH', $this->url($pesee), $this->admin, ['prixunitaire' => 300]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $pesee = $this->relire($pesee);
        self::assertSame(300 * self::POIDS, $pesee->getMontantdu());
        self::assertSame(StatutReglement::PARTIEL, $pesee->getStatutReglement(), 'La récolte redevient due');
    }

    /* ---- La survie de la prime ---------------------------------------------------------------- */

    /*
        - UNE RETARIFICATION DU PRODUIT NE BALAIE PAS LA PRIME. Sans le gel que porte 'MANUEL', le
          prochain prix du produit la remplacerait en silence : le planteur toucherait autre chose
          que ce qu'on lui a annoncé, et la fiche ne saurait plus dire pourquoi.
    */
    public function testUneRetarificationDuProduitNeBalaiePasLaPrime(): void
    {
        $pesee = $this->creerPesee($this->site, $this->produit, $this->planteur, self::POIDS);

        $this->appeler('PATCH', $this->url($pesee), $this->admin, ['prixunitaire' => 260]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $pesee = $this->relire($pesee);

        self::assertFalse($pesee->calculerMontant(), 'Le calcul ne doit plus rien changer sur cette pesée');
        self::assertSame(260, $pesee->getPrixunitaire());
        self::assertSame(OrigineTarif::MANUEL, $pesee->getOrigineTarif());
    }

    /* ---- Les refus ---------------------------------------------------------------------------- */

    /*
        - L'OPÉRATEUR NE TARIFE PAS. Il a le planteur devant lui au moment où celui-ci réclame sa
          prime : lui ouvrir ce champ ferait négocier le prix à la barrière, ce que la refonte du
          référentiel a précisément voulu supprimer.
    */
    public function testLOperateurNeTarifePasUnePesee(): void
    {
        $pesee = $this->creerPeseeArbitree($this->site, $this->produit, $this->planteur, self::POIDS);

        $this->appeler('PATCH', $this->url($pesee), $this->operateur, ['prixunitaire' => 260]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(self::PRIX, $this->relire($pesee)->getPrixunitaire());
    }

    /* Un prix nul n'est pas une prime : ce serait annuler une récolte depuis un écran de tarification. */
    public function testUnPrixNulEstRefuse(): void
    {
        $pesee = $this->creerPeseeArbitree($this->site, $this->produit, $this->planteur, self::POIDS);

        $this->appeler('PATCH', $this->url($pesee), $this->admin, ['prixunitaire' => 0]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(self::PRIX, $this->relire($pesee)->getPrixunitaire());
    }

    /* ---- La mise en compte -------------------------------------------------------------------- */

    /*
        - L'ARBITRAGE EST DÉFINITIF, et la hausse va au GUICHET. Un taux de 250 F sur un produit à
          200 : le plafond a mordu et la mise en compte a pris toute la récolte, 100 000 F au lieu
          des 125 000 que le taux appelait. Porter le prix à 300 ne rouvre rien — la part mise de
          côté reste celle qui a été tranchée, et les 50 000 F de plus reviennent au planteur en
          main propre.

        - CE QUI L'INTERDIT N'EST PAS UN CHOIX DE STYLE : 'mouvement_epargne.operation_id' porte un
          index UNIQUE, la première des quatre barrières contre le double crédit. Une pesée a UNE
          ligne d'épargne. Un complément écrit ici lèverait une violation d'index à l'insertion —
          c'est exactement ce que ce cas a attrapé.
    */
    public function testLArbitrageEstDefinitifEtLaHausseVaAuGuichet(): void
    {
        $this->ouvrirPrelevement($this->planteur, $this->produit, 250);

        $pesee = $this->creerPeseeArbitree($this->site, $this->produit, $this->planteur, self::POIDS);

        self::assertSame(100_000, $pesee->getRetenue(), 'Rabotée par ce que valait la récolte');
        self::assertSame(0, $pesee->getPayableproduit(), 'Le planteur ne touche rien au guichet');

        $this->appeler('PATCH', $this->url($pesee), $this->admin, ['prixunitaire' => 300]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $pesee = $this->relire($pesee);
        self::assertSame(150_000, $pesee->getMontantdu());
        self::assertSame(100_000, $pesee->getRetenue(), 'La part mise de côté ne bouge pas');
        self::assertSame(50_000, $pesee->getPayableproduit(), 'Toute la hausse revient au guichet');

        $ecritures = $this->em->getRepository(MouvementEpargne::class)->findAll();
        self::assertCount(1, $ecritures, 'Une pesée porte UNE ligne d épargne, et une seule');
        self::assertSame(100_000, $this->em->getRepository(CompteEpargne::class)->findOneBy([])?->getSolde());
    }

    /*
        - ON NE REPREND PAS SUR L'ÉPARGNE. Baisser le prix au point que la récolte vaille moins que
          ce qui a déjà été mis de côté voudrait dire retirer une part du compte du planteur. C'est un
          retrait, qui passe par sa demande et l'accord d'un administrateur.
    */
    public function testOnNeReprendPasCeQuiEstDejaMisEnCompte(): void
    {
        $this->ouvrirPrelevement($this->planteur, $this->produit, 250);

        $pesee = $this->creerPeseeArbitree($this->site, $this->produit, $this->planteur, self::POIDS);
        self::assertSame(100_000, $pesee->getRetenue());

        $this->appeler('PATCH', $this->url($pesee), $this->admin, ['prixunitaire' => 100]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('demande de retrait', (string) ($this->reponse()['detail'] ?? ''));

        $pesee = $this->relire($pesee);
        self::assertSame(self::PRIX, $pesee->getPrixunitaire(), 'Un prix refusé ne laisse aucune trace');
        self::assertSame(100_000, $pesee->getRetenue());
        self::assertCount(1, $this->em->getRepository(MouvementEpargne::class)->findAll());
    }

    /* ---- Outils ------------------------------------------------------------------------------- */

    private function url(Operation $pesee): string
    {
        return '/api/operations/' . $pesee->getId() . '/prix-unitaire';
    }

    private function verser(Operation $pesee, int $produit = 0, int $transport = 0): void
    {
        $this->appeler('POST', '/api/paiements', $this->operateur, [
            'operationId' => $pesee->getId(),
            'modeReglement' => ModeReglement::ESPECES->value,
            'montantProduit' => $produit,
            'montantTransport' => $transport,
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    private function relire(Operation $pesee): Operation
    {
        $id = $pesee->getId();
        $this->em->clear();

        $relue = $this->em->find(Operation::class, $id);
        self::assertNotNull($relue);

        return $relue;
    }
}
