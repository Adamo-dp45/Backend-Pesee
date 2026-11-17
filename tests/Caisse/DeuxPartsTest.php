<?php

namespace App\Tests\Caisse;

use App\Domain\Enum\ModeReglement;
use App\Domain\Enum\Role;
use App\Domain\Enum\StatutPaiement;
use App\Domain\Enum\StatutReglement;
use App\Domain\Enum\TypeCaisse;
use App\Entity\Entreprise;
use App\Entity\Fournisseur;
use App\Entity\Operation;
use App\Entity\Paiement;
use App\Entity\Produit;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - UNE PESÉE VAUT DEUX CHOSES, et jusqu'ici elles se confondaient au guichet dans un seul chiffre.
      La récolte, c'est ce que le produit vaut ; le transport, c'est le carburant que le planteur a
      avancé de sa poche pour l'amener. On règle désormais l'une, l'autre, ou les deux — et chacune
      en plusieurs fois.

    - CE QUE CES CAS GARDENT, et qui ne se voit sur aucun écran : les deux bornes. Il n'y en avait
      qu'une, et le README l'appelait « la seule ligne de l'application où la borne d'un versement se
      décide ». Elles sont deux maintenant, et une borne qui fuit ne lève rien — elle laisse sortir
      de l'argent qu'on ne devait pas.

    - Les espèces sont confirmées sur-le-champ, sans passerelle : c'est ce qui permet de lire les
      cumuls juste après l'appel. Le mobile money sert aux cas « en vol ».
*/
final class DeuxPartsTest extends InvariantTestCase
{
    private const PRIX = 200;       // Le kilo de récolte
    private const TRANSPORT = 15;   // Le kilo de transport
    private const POIDS = 500;      // Donc 100 000 F de récolte et 7 500 F de transport

    private const RECOLTE = self::PRIX * self::POIDS;
    private const CARBURANT = self::TRANSPORT * self::POIDS;

    private Entreprise $entreprise;
    private Site $site;
    private Produit $produit;
    private User $operateur;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = $this->creerEntreprise('ACME', 5_000_000, 5_000_000);
        $this->site = $this->creerSite($this->entreprise, 'ACM001', 5_000_000, 5_000_000);
        $this->produit = $this->creerProduit($this->site, 'Hévéa', self::PRIX);

        $this->operateur = $this->creerUtilisateur($this->entreprise, Role::OPERATEUR, 'operateur@acme.test');
        $this->affecterOperateur($this->site, $this->operateur);
        $this->admin = $this->creerUtilisateur($this->entreprise, Role::ADMIN, 'admin@acme.test');
    }

    /* ---- Les trois gestes --------------------------------------------------------------------- */

    /* Le geste le plus courant : la récolte seule, parce que la plupart des planteurs n'ont pas de transport. */
    public function testOnPeutNeReglerQueLaRecolte(): void
    {
        $pesee = $this->peseeAvecTransport();

        $this->verser($pesee, produit: self::RECOLTE, transport: 0);

        $pesee = $this->relire($pesee);
        self::assertSame(self::RECOLTE, $pesee->getMontantpayeproduit());
        self::assertSame(0, $pesee->getMontantpayetransport());
        self::assertSame(0, $pesee->getResteapayerproduit());
        self::assertSame(self::CARBURANT, $pesee->getResteapayertransport(), 'Le transport reste dû');
        self::assertSame(StatutReglement::PARTIEL, $pesee->getStatutReglement());
    }

    /*
        - LE GESTE QUI N'EXISTAIT PAS : le transport seul. Le planteur a brûlé son carburant, il
          repart avec. Sa récolte reste due, et le dit.
    */
    public function testOnPeutNeReglerQueLeTransport(): void
    {
        $pesee = $this->peseeAvecTransport();

        $this->verser($pesee, produit: 0, transport: self::CARBURANT);

        $pesee = $this->relire($pesee);
        self::assertSame(0, $pesee->getMontantpayeproduit());
        self::assertSame(self::CARBURANT, $pesee->getMontantpayetransport());
        self::assertSame(self::RECOLTE, $pesee->getResteapayerproduit());
        self::assertSame(StatutReglement::PARTIEL, $pesee->getStatutReglement());
        self::assertStringContainsString('La part transport de cette pesée est déjà réglée', (string) $pesee->getMotifNonPayableTransport());
        self::assertNull($pesee->getMotifNonPayableProduit(), 'La récolte doit rester ouverte');
    }

    /*
        - Les deux d'un geste : UN SEUL VERSEMENT. Une référence, une sortie de caisse, un seul frais
          mobile money. Deux paiements auraient coûté deux fois au planteur.
    */
    public function testPayerLesDeuxPartsNeFaitQuUnSeulVersement(): void
    {
        $pesee = $this->peseeAvecTransport();
        $avant = $this->especesDuSite();

        $this->verser($pesee, produit: self::RECOLTE, transport: self::CARBURANT);

        $pesee = $this->relire($pesee);
        self::assertSame(StatutReglement::SOLDE, $pesee->getStatutReglement());
        self::assertCount(1, $this->em->getRepository(Paiement::class)->findAll(), 'Un geste, un versement');

        $versement = $this->em->getRepository(Paiement::class)->findOneBy([]);
        self::assertSame(self::RECOLTE, $versement?->getMontantproduit());
        self::assertSame(self::CARBURANT, $versement?->getMontanttransport());
        self::assertSame(self::RECOLTE + self::CARBURANT, $versement?->getMontant(), 'Le total est la somme des parts');

        // Une seule sortie de caisse, du total
        self::assertSame($avant - (self::RECOLTE + self::CARBURANT), $this->especesDuSite());
    }

    /* ---- Chaque part en plusieurs fois --------------------------------------------------------- */

    public function testChaquePartSeRegleEnPlusieursFois(): void
    {
        $pesee = $this->peseeAvecTransport();

        $this->verser($pesee, produit: 60_000, transport: 2_500);
        $this->verser($pesee, produit: 40_000, transport: 5_000);

        $pesee = $this->relire($pesee);
        self::assertSame(self::RECOLTE, $pesee->getMontantpayeproduit());
        self::assertSame(self::CARBURANT, $pesee->getMontantpayetransport());
        self::assertSame(StatutReglement::SOLDE, $pesee->getStatutReglement());
    }

    /*
        - LA BORNE DE CHAQUE PART, prise séparément. Verser le payable de la récolte sur le transport
          ferait sortir dix fois ce qu'on doit, et aucun total ne s'en apercevrait : la somme des deux
          parts reste inférieure au payable global.
    */
    public function testLaBorneDUnePartNeSeNourritPasDeLAutre(): void
    {
        $pesee = $this->peseeAvecTransport();

        $this->appeler('POST', '/api/paiements', $this->operateur, [
            'operationId' => $pesee->getId(),
            'modeReglement' => ModeReglement::ESPECES->value,
            'montantProduit' => 0,
            'montantTransport' => self::RECOLTE, // Le payable de la RÉCOLTE, versé sur le transport
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('la part transport', (string) ($this->reponse()['detail'] ?? ''));
        self::assertStringContainsString('7 500', (string) ($this->reponse()['detail'] ?? ''));

        self::assertSame(0, $this->relire($pesee)->getMontantpaye(), 'Rien ne doit être sorti');
    }

    /* Un refus sur une part fait échouer tout l'appel : déverser en silence sur l'autre paierait autre chose que ce qui a été saisi. */
    public function testUnRefusSurUnePartAnnuleToutLAppel(): void
    {
        $pesee = $this->peseeAvecTransport();

        $this->appeler('POST', '/api/paiements', $this->operateur, [
            'operationId' => $pesee->getId(),
            'modeReglement' => ModeReglement::ESPECES->value,
            'montantProduit' => self::RECOLTE,       // Tenable
            'montantTransport' => self::CARBURANT * 3, // Ne l'est pas
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $pesee = $this->relire($pesee);
        self::assertSame(0, $pesee->getMontantpaye());
        self::assertCount(0, $this->em->getRepository(Paiement::class)->findAll());
    }

    /* Les deux à zéro ne sont pas un versement : l'écran laisse les champs vides, le serveur tranche. */
    public function testDeuxMontantsNulsSontRefuses(): void
    {
        $pesee = $this->peseeAvecTransport();

        $this->appeler('POST', '/api/paiements', $this->operateur, [
            'operationId' => $pesee->getId(),
            'modeReglement' => ModeReglement::ESPECES->value,
            'montantProduit' => 0,
            'montantTransport' => 0,
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('rien à verser', (string) ($this->reponse()['detail'] ?? ''));
    }

    /* ---- La pesée non tarifée, qui est tout l'objet du chantier -------------------------------- */

    /*
        - LE CAS CENTRAL. Un produit créé à la volée par le peseur naît sans prix : sa récolte ne vaut
          rien, mais le planteur a bien brûlé son carburant. Il doit pouvoir être payé sans attendre
          que le bureau tarife.
    */
    public function testLeTransportSePaieMemeSiLaRecolteNEstPasTarifee(): void
    {
        $pesee = $this->peseeAvecTransport(prixDuProduit: 0);

        self::assertSame(0, $pesee->getPayableproduit());
        self::assertSame(self::CARBURANT, $pesee->getPayabletransport());

        $this->verser($pesee, produit: 0, transport: self::CARBURANT);

        $pesee = $this->relire($pesee);
        self::assertSame(self::CARBURANT, $pesee->getMontantpayetransport());

        /*
            - ET SURTOUT : elle n'est PAS soldée. Son produit n'a toujours pas de prix, et l'annoncer
              soldée la ferait disparaître de la file du guichet — l'opérateur partirait chercher un
              versement de récolte qui n'a jamais eu lieu.
        */
        self::assertSame(StatutReglement::PARTIEL, $pesee->getStatutReglement());
        self::assertStringNotContainsString('soldée', (string) $pesee->getMotifNonPayable());
        self::assertStringContainsString('tarifé', (string) $pesee->getMotifNonPayable());
    }

    /*
        - LA SUITE DU MÊME CAS, et le piège qu'un verrou global aurait tendu : une fois le transport
          payé, tarifer le produit doit encore valoriser la récolte. Avec l'ancien verrou — « un franc
          versé et plus rien ne bouge » — cette pesée serait restée à zéro pour toujours.
    */
    public function testTariferApresAvoirPayeLeTransportValoriseLaRecolteSansYToucher(): void
    {
        $sansPrix = $this->creerProduit($this->site, 'Cacao', 0);
        $pesee = $this->peseeAvecTransport(produit: $sansPrix);

        $this->verser($pesee, produit: 0, transport: self::CARBURANT);

        // Le bureau tarife enfin
        $this->appeler('PATCH', '/api/produits/' . $sansPrix->getId(), $this->admin, ['prix' => self::PRIX]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $pesee = $this->relire($pesee);
        self::assertSame(self::RECOLTE, $pesee->getMontantdu(), 'La récolte doit enfin valoir quelque chose');
        self::assertSame(self::CARBURANT, $pesee->getMontanttransport(), 'Le transport déjà payé ne doit pas bouger');
        self::assertSame(self::RECOLTE, $pesee->getResteapayerproduit());
        self::assertSame(0, $pesee->getResteapayertransport());
    }

    /* ---- Le vol, et l'échec --------------------------------------------------------------------- */

    /*
        - Un versement en vol sur le transport ne doit RIEN fermer sur la récolte. Avec un « en vol »
          global, l'API refusait « un versement est déjà en cours » pour une part sur laquelle pas un
          franc n'était parti.
    */
    public function testUnVersementEnVolSurLeTransportNeFermePasLaRecolte(): void
    {
        $pesee = $this->peseeAvecTransport();

        $this->appeler('POST', '/api/paiements', $this->operateur, [
            'operationId' => $pesee->getId(),
            'modeReglement' => ModeReglement::MOBILE_MONEY->value,
            'montantProduit' => 0,
            'montantTransport' => self::CARBURANT,
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $pesee = $this->relire($pesee);
        self::assertSame(self::CARBURANT, $pesee->getMontantenvoltransport());
        self::assertSame(0, $pesee->getMontantenvolproduit());
        self::assertSame(self::RECOLTE, $pesee->getResteaengagerproduit(), 'La récolte doit rester entièrement engageable');
        self::assertSame(0, $pesee->getResteaengagertransport());

        // Et la récolte s'engage effectivement, sans que le vol du transport la gêne
        $this->verser($pesee, produit: self::RECOLTE, transport: 0);
        self::assertSame(self::RECOLTE, $this->relire($pesee)->getMontantpayeproduit());
    }

    /* ---- Montage ------------------------------------------------------------------------------- */

    private function peseeAvecTransport(?Produit $produit = null, ?int $prixDuProduit = null): Operation
    {
        if($prixDuProduit !== null) {
            $produit = $this->creerProduit($this->site, 'Palme', $prixDuProduit);
        }

        $planteur = $this->creerPlanteur($this->site, 'Kouassi');
        $planteur->setPrixtransport(self::TRANSPORT);
        $this->em->flush();

        return $this->creerPesee($this->site, $produit ?? $this->produit, $planteur, self::POIDS);
    }

    private function verser(Operation $pesee, int $produit, int $transport): void
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

    private function especesDuSite(): int
    {
        $id = $this->site->getId();
        $this->em->clear();

        return (int) $this->em->find(Site::class, $id)?->getSoldeDe(TypeCaisse::PHYSIQUE);
    }
}
