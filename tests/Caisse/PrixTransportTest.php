<?php

namespace App\Tests\Caisse;

use App\Domain\Enum\Role;
use App\Entity\Fournisseur;
use App\Entity\Operation;
use App\Entity\Produit;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - Le prix du transport : certains planteurs amènent eux-mêmes leur récolte au pont bascule, et ce
      trajet leur est payé EN PLUS d'elle.

    - Ce qui est surveillé ici n'est pas la multiplication, mais la SÉPARATION des deux nombres. Les
      additionner quelque part se verrait à peine et fausserait trois choses d'un coup :

        · la valeur de la récolte, que montrent la production et le bilan ;
        · l'assiette de la mise en compte — on prélèverait l'épargne du planteur sur son transport ;
        · le ticket du planteur, qui annoncerait une récolte qu'il n'a pas livrée.

    - Et deux règles d'arbitrage : le poste muet n'efface pas le prix saisi sur le web, et une pesée
      dont le produit n'est pas tarifé ne paie rien, transport compris.
*/
final class PrixTransportTest extends InvariantTestCase
{
    private const PRIX = 200;       // F/kg de récolte
    private const TRANSPORT = 15;   // F/kg de transport
    private const POIDS = 500;      // kg

    private Site $site;
    private Produit $produit;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $entreprise = $this->creerEntreprise('ACME', 2_000_000, 2_000_000);
        $this->site = $this->creerSite($entreprise, 'ACM001', 1_000_000, 1_000_000);
        $this->produit = $this->creerProduit($this->site, 'Hévéa', self::PRIX);
        $this->admin = $this->creerUtilisateur($entreprise, Role::ADMIN, 'admin@acme.test');
    }

    private function planteurTransporteur(int $transport = self::TRANSPORT, string $nom = 'Kouassi'): Fournisseur
    {
        $planteur = $this->creerPlanteur($this->site, $nom);
        $planteur->setPrixtransport($transport);

        $this->em->flush();

        return $planteur;
    }

    /*
        - Le cas nominal. 500 kg à 200 F valent 100 000 F de récolte ; le transport à 15 F/kg ajoute
          7 500 F. Le planteur touche 107 500 F, et la récolte vaut toujours 100 000 F.
    */
    public function testLeTransportSAjouteAuPayableSansToucherALaValeurDeLaRecolte(): void
    {
        $pesee = $this->creerPesee($this->site, $this->produit, $this->planteurTransporteur());

        self::assertSame(100_000, $pesee->getMontantdu(), 'La récolte vaut ce qu\'elle vaut : le transport n\'entre pas dedans');
        self::assertSame(self::PRIX, $pesee->getPrixunitaire());
        self::assertSame(self::TRANSPORT, $pesee->getPrixtransport(), 'Le prix au kilo est figé à côté du montant, pour pouvoir l\'expliquer');
        self::assertSame(7_500, $pesee->getMontanttransport());
        self::assertSame(107_500, $pesee->getMontantpayable());
        self::assertSame(107_500, $pesee->getResteapayer());
        self::assertNull($pesee->getMotifNonPayable());
    }

    /** Sans transport, rien ne change pour personne — et c'est le cas de la plupart des planteurs. */
    public function testUnPlanteurSansTransportEstInchange(): void
    {
        $pesee = $this->creerPesee($this->site, $this->produit, $this->creerPlanteur($this->site));

        self::assertNull($pesee->getPrixtransport());
        self::assertSame(0, $pesee->getMontanttransport());
        self::assertSame(100_000, $pesee->getMontantpayable());
    }

    /*
        - LA règle qui ne doit pas glisser : la mise en compte mord sur la récolte SEULE. Le planteur
          épargne sur ce qu'il a vendu, pas sur le trajet qu'il a fait — celui-ci lui est remboursé.

        - 20 F/kg sur 500 kg font 10 000 F de retenue, prise sur les 100 000 F de récolte et non sur
          les 107 500 F du total. Le guichet verse donc 97 500 F.
    */
    public function testLaMiseEnCompteNeMordQueSurLaRecolte(): void
    {
        $planteur = $this->planteurTransporteur();
        $this->ouvrirPrelevement($planteur, $this->produit, 20);

        $pesee = $this->creerPeseeArbitree($this->site, $this->produit, $planteur);

        self::assertSame(10_000, $pesee->getRetenue(), 'Calculée sur la récolte : 20 F x 500 kg');
        self::assertSame(97_500, $pesee->getMontantpayable(), '100 000 de récolte + 7 500 de transport - 10 000 de retenue');
    }

    /*
        - Le plafond de la retenue reste la RÉCOLTE, pas le total. Avec un taux délirant, le planteur
          repart sans rien de sa récolte — c'est son choix — mais son transport, lui, lui est bien
          remis : il a avancé le carburant.
    */
    public function testUneRetenueQuiAbsorbeLaRecolteLaisseLeTransportPayable(): void
    {
        $planteur = $this->planteurTransporteur();
        // 500 F/kg sur un produit à 200 F/kg : les processors refuseraient, on le pose à la main pour éprouver le plafond
        $this->ouvrirPrelevement($planteur, $this->produit, 500);

        $pesee = $this->creerPeseeArbitree($this->site, $this->produit, $planteur);

        self::assertSame(100_000, $pesee->getRetenue(), 'Plafonnée à la valeur de la récolte');
        self::assertSame(7_500, $pesee->getMontantpayable(), 'Il reste le transport à lui verser');
        self::assertNull($pesee->getMotifNonPayable(), 'Une pesée qui a encore quelque chose à verser reste payable');
    }

    /*
        - Une pesée dont le produit n'est pas tarifé ne vaut rien, ET NE PAIE RIEN, transport compris.
          Régler le seul transport laisserait une pesée à moitié payée que plus personne ne rouvre, et
          le motif doit continuer de nommer ce qu'il y a à corriger : aller tarifer le produit.
    */
    public function testUnProduitNonTarifeNePaiePasMemeLeTransport(): void
    {
        $sansPrix = $this->creerProduit($this->site, 'Cacao', 0);
        $pesee = $this->creerPesee($this->site, $sansPrix, $this->planteurTransporteur());

        self::assertSame(0, $pesee->getMontantpayable());
        self::assertStringContainsString('Cacao', (string) $pesee->getMotifNonPayable());
        self::assertStringContainsString('tarifé', (string) $pesee->getMotifNonPayable());
    }

    /*
        - L'arbitrage poste/web, dans le sens qui compte : le logiciel de pesée ne connaît pas le
          transport de ce planteur et n'envoie rien. La valeur saisie au bureau doit TENIR — sans
          quoi corriger une fiche sur le web serait inutile, la prochaine synchronisation l'effaçant.
    */
    public function testLePosteMuetNEffacePasLePrixSaisiSurLeWeb(): void
    {
        $planteur = $this->planteurTransporteur();

        self::assertFalse($planteur->appliquerPrixTransportDuPoste(null));
        self::assertSame(self::TRANSPORT, $planteur->getPrixtransport());

        // Une valeur explicite, elle, l'emporte — 0 compris, qui veut dire « plus de transport »
        self::assertTrue($planteur->appliquerPrixTransportDuPoste(0));
        self::assertSame(0, $planteur->getPrixtransport());
    }

    /*
        - Le trou que cet écran existe pour boucher. Un produit créé à la volée par le peseur naît
          sans prix : ses pesées valent zéro et ne sont pas payables. On va le tarifer depuis le
          bureau — et jusqu'ici ce PATCH ne faisait que journaliser, laissant les pesées à zéro
          jusqu'au prochain changement de prix côté poste.

        - La mise en compte du planteur doit démarrer dans la foulée : la pesée vient seulement
          d'acquérir une valeur, c'est maintenant qu'elle est arbitrable.
    */
    public function testTariferUnProduitSurLeWebRevaloriseSesPeseesEnAttente(): void
    {
        $sansPrix = $this->creerProduit($this->site, 'Cacao', 0);
        $planteur = $this->planteurTransporteur();
        $this->ouvrirPrelevement($planteur, $sansPrix, 20);

        $pesee = $this->creerPesee($this->site, $sansPrix, $planteur);
        self::assertSame(0, $pesee->getMontantdu());
        self::assertNull($pesee->getRetenue(), 'Rien à arbitrer tant que la pesée ne vaut rien');

        $this->appeler('PATCH', '/api/produits/' . $sansPrix->getId(), $this->admin, ['prix' => 300]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $pesee = $this->em->getRepository(Operation::class)->find($pesee->getId());

        self::assertNotNull($pesee);
        self::assertSame(150_000, $pesee->getMontantdu(), '500 kg a 300 F, revalorisés par le PATCH');
        self::assertSame(10_000, $pesee->getRetenue(), 'La mise en compte démarre dans la foulée');
        self::assertSame(147_500, $pesee->getMontantpayable(), '150 000 + 7 500 de transport - 10 000 de retenue');
    }

    /*
        - Le même mécanisme côté planteur : changer son transport au bureau revalorise ses pesées pas
          encore payées. Une pesée DÉJÀ arbitrée, elle, n'est pas reprise — une promesse a été écrite
          sur son épargne, et aucun retrait n'existe pour la défaire.
    */
    public function testChangerLeTransportRevaloriseLesPeseesPasEncorePayees(): void
    {
        $planteur = $this->planteurTransporteur();

        $enAttente = $this->creerPesee($this->site, $this->produit, $planteur, self::POIDS, '1');
        $arbitree = $this->creerPeseeArbitree($this->site, $this->produit, $planteur, self::POIDS, '2');

        $this->appeler('PATCH', '/api/fournisseurs/' . $planteur->getId(), $this->admin, ['prixtransport' => 30]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $depot = $this->em->getRepository(Operation::class);

        self::assertSame(15_000, $depot->find($enAttente->getId())?->getMontanttransport(), '30 F x 500 kg');
        self::assertSame(7_500, $depot->find($arbitree->getId())?->getMontanttransport(), 'Déjà arbitrée : on n\'y touche plus');
    }

    /*
        - Ce que le poste reçoit en retour. 'montant' reste la récolte seule, parce que c'est ce que
          le logiciel de pesée imprime déjà sur le ticket ; le transport et le payable sont ajoutés à
          côté, pour le jour où il saura les lire.
    */
    public function testLAccuseRenvoyeAuPosteSepareLesTroisNombres(): void
    {
        $planteur = $this->planteurTransporteur();
        $pesee = $this->creerPesee($this->site, $this->produit, $planteur);

        $machine = $this->creerCompteMachine($this->site);
        $this->appeler('POST', '/api/lister', $machine, []);

        $accuse = $this->reponse()['rows'][0];

        self::assertSame($pesee->getNumticket(), $accuse['numticket']);
        self::assertSame(100_000, $accuse['montant'], 'Inchangé : la récolte seule');
        self::assertSame(self::TRANSPORT, $accuse['prixtransport']);
        self::assertSame(7_500, $accuse['montanttransport']);
        self::assertSame(107_500, $accuse['montantpayable']);
    }
}
