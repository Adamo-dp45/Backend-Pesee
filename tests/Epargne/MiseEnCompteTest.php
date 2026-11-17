<?php

namespace App\Tests\Epargne;

use App\Domain\Enum\StatutReglement;
use App\Domain\Service\Epargne\EpargneService;
use App\Entity\CompteEpargne;
use App\Entity\Fournisseur;
use App\Entity\MouvementCaisse;
use App\Entity\MouvementEpargne;
use App\Entity\Operation;
use App\Entity\Produit;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - La mise en compte : une part de ce que vaut la pesée part sur l'épargne du planteur au lieu de
      lui être remise au guichet. Ce qui est surveillé ici n'est pas le calcul — une multiplication
      ne se trompe pas — mais les quatre endroits où l'argent peut se dédoubler ou disparaître :

        · la pesée ne doit être arbitrée QU'UNE FOIS, même si le poste rejoue son lot ;
        · la retenue ne doit JAMAIS dépasser ce que vaut la pesée, sinon elle crée une dette ;
        · le guichet ne doit pas pouvoir verser ce qui vient d'être mis de côté ;
        · aucune caisse ne doit bouger, puisque pas un billet n'a changé de main.
*/
final class MiseEnCompteTest extends InvariantTestCase
{
    private const PRIX = 200;   // F/kg
    private const POIDS = 500;  // kg, donc 100 000 F de pesée
    private const TAUX = 20;    // F/kg, donc 10 000 F mis de côté

    private Site $site;
    private Produit $produit;
    private User $operateur;
    private EpargneService $epargne;

    protected function setUp(): void
    {
        parent::setUp();

        $entreprise = $this->creerEntreprise('ACME', 2_000_000, 2_000_000);
        $this->site = $this->creerSite($entreprise, 'ACM001', 1_000_000, 1_000_000);
        $this->produit = $this->creerProduit($this->site, 'Hévéa', self::PRIX);

        $this->operateur = $this->creerUtilisateur($entreprise, \App\Domain\Enum\Role::OPERATEUR, 'operateur@acme.test');
        $this->affecterOperateur($this->site, $this->operateur);

        $this->epargne = self::getContainer()->get(EpargneService::class);
    }

    /* Le prélèvement porte sur le produit du cas, qui est aussi celui de ses pesées : c'est lui qui décide du taux, pas le planteur. */
    private function planteurQuiEpargne(int $taux = self::TAUX, string $nom = 'Kouassi'): Fournisseur
    {
        $planteur = $this->creerPlanteur($this->site, $nom);

        $this->ouvrirPrelevement($planteur, $this->produit, $taux);

        return $planteur;
    }

    /*
        - Le cas nominal, et il dit tout : la pesée garde sa valeur, le compte se remplit, le
          payable baisse d'autant, et LA CAISSE NE BOUGE PAS. C'est cette dernière assertion qui
          porte la promesse faite au patron : au moment du prélèvement, le planteur n'est pas encore
          passé au guichet.
    */
    public function testUnePeseeCrediteLeCompteSansToucherAAucuneCaisse(): void
    {
        $planteur = $this->planteurQuiEpargne();
        $ecrituresAvant = count($this->em->getRepository(MouvementCaisse::class)->findAll());

        $pesee = $this->creerPeseeArbitree($this->site, $this->produit, $planteur);

        self::assertSame(100_000, $pesee->getMontantdu(), 'La pesée garde sa valeur pleine');
        self::assertSame(10_000, $pesee->getRetenue());
        self::assertSame(self::TAUX, $pesee->getTauxRetenue(), 'Le taux est figé à côté du montant, pour pouvoir l\'expliquer');
        self::assertSame(90_000, $pesee->getMontantpayable());
        self::assertSame(90_000, $pesee->getResteapayer());

        $this->em->clear();

        $compte = $this->em->getRepository(CompteEpargne::class)->findOneBy([]);
        self::assertNotNull($compte, 'Le compte est créé au premier prélèvement');
        self::assertSame(10_000, $compte->getSolde());

        $ecritures = $this->em->getRepository(MouvementEpargne::class)->findAll();
        self::assertCount(1, $ecritures);
        self::assertSame(0, $ecritures[0]->getSoldeAvant());
        self::assertSame(10_000, $ecritures[0]->getSoldeApres());
        self::assertSame(10_000, $ecritures[0]->getMontant());

        self::assertCount(
            $ecrituresAvant,
            $this->em->getRepository(MouvementCaisse::class)->findAll(),
            'Un prélèvement ne déplace aucun argent : le grand livre des caisses ne doit pas avoir bougé'
        );
    }

    /*
        - Le poste renvoie son lot dès qu'un accusé se perd, et c'est fréquent. Une pesée rejouée ne
          doit pas créditer une seconde fois : le planteur verrait son épargne doubler sans avoir
          rien livré de plus.
    */
    public function testUnLotRejoueNeCrediteJamaisDeuxFois(): void
    {
        $planteur = $this->planteurQuiEpargne();
        $pesee = $this->creerPeseeArbitree($this->site, $this->produit, $planteur);

        // Le second passage, exactement comme si le poste avait renvoyé sa ligne
        $this->epargne->preleverSur($pesee);
        $this->em->flush();
        $this->em->clear();

        self::assertCount(1, $this->em->getRepository(MouvementEpargne::class)->findAll());
        self::assertSame(10_000, $this->em->getRepository(CompteEpargne::class)->findOneBy([])?->getSolde());
    }

    /*
        - Sur une petite livraison, le taux au kilo peut dépasser ce que vaut la pesée. Le planteur
          repart alors sans liquide — c'est son choix — mais il ne doit RIEN à l'entreprise. Un
          prélèvement ne crée jamais de dette.
    */
    public function testLaRetenueNeDepasseJamaisCeQueVautLaPesee(): void
    {
        $planteur = $this->planteurQuiEpargne(taux: 500); // 500 F/kg sur un produit à 200 F/kg
        $pesee = $this->creerPeseeArbitree($this->site, $this->produit, $planteur);

        self::assertSame(100_000, $pesee->getMontantdu());
        self::assertSame(100_000, $pesee->getRetenue(), 'Plafonnée à la valeur de la pesée');
        self::assertSame(0, $pesee->getMontantpayable());
        self::assertSame(0, $pesee->getResteapayer());
        self::assertSame(StatutReglement::RETENU, $pesee->getStatutReglement());

        $motif = $pesee->getMotifNonPayable();

        self::assertNotNull($motif);
        self::assertStringContainsString('épargne', $motif);
        self::assertStringContainsString('Kouassi', $motif, 'Le motif nomme le planteur : l\'opérateur l\'a devant lui');
        self::assertStringNotContainsString('soldée', $motif, 'Rien n\'a été versé : ce n\'est pas un solde');
    }

    /*
        - La règle « seulement les pesées à venir ». Une pesée arbitrée avant l'ouverture du
          prélèvement porte une retenue nulle, et un renvoi du poste ne doit pas la rouvrir : le
          planteur a déjà vu son montant, il ne changera pas dans son dos.
    */
    public function testUnePeseeAnterieureALOuvertureNEstPasReprise(): void
    {
        $planteur = $this->creerPlanteur($this->site, 'Bamba'); // Sans prélèvement
        $pesee = $this->creerPeseeArbitree($this->site, $this->produit, $planteur);

        self::assertSame(0, $pesee->getRetenue(), 'Arbitrée à zéro : la pesée est close');
        self::assertSame(100_000, $pesee->getMontantpayable());

        // Le planteur ouvre un prélèvement APRÈS, et le poste renvoie son lot
        $this->ouvrirPrelevement($planteur, $this->produit, self::TAUX);

        $this->epargne->preleverSur($pesee);
        $this->em->flush();
        $this->em->clear();

        self::assertCount(0, $this->em->getRepository(MouvementEpargne::class)->findAll());
        self::assertSame(100_000, $this->em->find(Operation::class, $pesee->getId())?->getMontantpayable());
    }

    /*
        - LA borne qui compte : celle au-delà de laquelle l'argent sort de la caisse. Si elle
          repartait du montant dû, le guichet verserait au planteur ce qu'on venait de lui garder,
          et l'entreprise paierait deux fois la même pesée.
    */
    public function testLeGuichetNePeutPasVerserCeQuiEstMisDeCote(): void
    {
        $planteur = $this->planteurQuiEpargne();
        $pesee = $this->creerPeseeArbitree($this->site, $this->produit, $planteur);

        $this->appeler('POST', '/api/paiements', $this->operateur, [
            'operationId' => $pesee->getId(),
            'modeReglement' => 'ESPECES',
            'montantProduit' => 100_000, // Ce que vaut la pesée, et non ce qu'on peut lui verser
            'montantTransport' => 0,
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('90 000', (string) ($this->reponse()['detail'] ?? ''));

        // Le payable exact, lui, passe — et solde la pesée
        $this->appeler('POST', '/api/paiements', $this->operateur, [
            'operationId' => $pesee->getId(),
            'modeReglement' => 'ESPECES',
            'montantProduit' => 90_000,
            'montantTransport' => 0,
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        $pesee = $this->em->find(Operation::class, $pesee->getId());

        self::assertSame(90_000, $pesee?->getMontantpaye());
        self::assertSame(StatutReglement::SOLDE, $pesee?->getStatutReglement(), 'Soldée sur le payable, pas sur le montant dû');
    }

    /*
        - Une pesée intégralement retenue n'a rien à verser, et doit le DIRE. L'annoncer « déjà
          soldée » enverrait l'opérateur chercher un versement qui n'a jamais eu lieu — le piège
          que la pesée non tarifée avait déjà tendu une fois.
    */
    public function testUnePeseeIntegralementRetenueEstRefuseeAvecSonMotif(): void
    {
        $planteur = $this->planteurQuiEpargne(taux: 500);
        $pesee = $this->creerPeseeArbitree($this->site, $this->produit, $planteur);

        $this->appeler('POST', '/api/paiements', $this->operateur, [
            'operationId' => $pesee->getId(),
            'modeReglement' => 'ESPECES',
            'montantProduit' => 1_000,
            'montantTransport' => 0,
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $detail = (string) ($this->reponse()['detail'] ?? '');

        self::assertStringContainsString('épargne', $detail);
        self::assertStringNotContainsString('soldée', $detail);
    }

    /*
        - Deux pesées de suite sur le même planteur : la chaîne des soldes doit se tenir, le solde
          d'arrivée de la première étant le solde de départ de la seconde. C'est ce qui rend une
          écriture passée hors du service détectable.
    */
    public function testLaChaineDesSoldesSeTient(): void
    {
        $planteur = $this->planteurQuiEpargne();

        $this->creerPeseeArbitree($this->site, $this->produit, $planteur, self::POIDS, '1');
        $this->creerPeseeArbitree($this->site, $this->produit, $planteur, self::POIDS, '2');

        $this->em->clear();

        $ecritures = $this->em->getRepository(MouvementEpargne::class)->findBy([], ['id' => 'ASC']);

        self::assertCount(2, $ecritures);
        self::assertSame(0, $ecritures[0]->getSoldeAvant());
        self::assertSame(10_000, $ecritures[0]->getSoldeApres());
        self::assertSame(10_000, $ecritures[1]->getSoldeAvant(), 'Le solde d\'arrivée de la première est le solde de départ de la seconde');
        self::assertSame(20_000, $ecritures[1]->getSoldeApres());

        self::assertSame(20_000, $this->em->getRepository(CompteEpargne::class)->findOneBy([])?->getSolde());
    }
}
