<?php

namespace App\Tests\Caisse;

use App\Domain\Enum\ModeReglement;
use App\Domain\Enum\Role;
use App\Domain\Enum\StatutReglement;
use App\Entity\Entreprise;
use App\Entity\Fournisseur;
use App\Entity\Operation;
use App\Entity\Paiement;
use App\Entity\Produit;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - PLUSIEURS PESÉES D'UN MÊME PLANTEUR, EN UN GESTE. Au guichet il se présente avec trois
      tickets ; les régler un par un, c'est trois fois l'écran et trois fois le risque d'en oublier
      un. Cocher veut dire régler EN ENTIER : aucun montant ne se saisit, donc aucune répartition
      ne se décide, donc aucune façon de se tromper d'un franc.

    - CE QUE CES CAS GARDENT, et qui ne se voit sur aucun écran :

      · L'ATOMICITÉ. Trois appels séparés laisseraient, sur une caisse à sec à la troisième, les
        deux premières payées — l'opérateur croirait son geste refusé alors que l'argent est sorti.
        C'est pour ça que 'ecrireEtDebiter()' ne transacte pas et que la boucle vit sous une seule
        enveloppe ; ce fichier est ce qui empêche quelqu'un de remettre un 'wrapInTransaction' là.

      · UN SEUL PLANTEUR. Un versement sort d'une caisse vers UNE personne. Mélanger deux planteurs
        produirait une ligne qui ne veut rien dire, et le refus doit tomber avant d'écrire.

      · LA BORNE EST CE QUI RESTE À ENGAGER, versements en vol compris. Une pesée dont le reste est
        déjà parti chez la passerelle est écartée EN SILENCE plutôt que de faire échouer le lot :
        elle a pu être réglée à l'autre guichet pendant que l'opérateur cochait.
*/
final class VersementGroupeTest extends InvariantTestCase
{
    private const PRIX = 200;
    private const POIDS = 500;
    private const RECOLTE = self::PRIX * self::POIDS; // 100 000 F
    private const TRANSPORT = 15;
    private const CARBURANT = self::TRANSPORT * self::POIDS; // 7 500 F

    private Entreprise $entreprise;
    private Site $site;
    private Produit $produit;
    private Fournisseur $planteur;
    private User $operateur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = $this->creerEntreprise('ACME', 5_000_000, 5_000_000);
        $this->site = $this->creerSite($this->entreprise, 'ACM001', 5_000_000, 5_000_000);
        $this->produit = $this->creerProduit($this->site, 'Hévéa', self::PRIX);

        $this->planteur = $this->creerPlanteur($this->site, 'Traore', '0700000001');
        $this->planteur->setBaremeTransport($this->creerBaremeAuKilo($this->entreprise, self::TRANSPORT));

        $this->operateur = $this->creerUtilisateur($this->entreprise, Role::OPERATEUR, 'operateur@acme.test');
        $this->affecterOperateur($this->site, $this->operateur);

        $this->em->flush();
    }

    /* ---- Le geste ----------------------------------------------------------------------------- */

    /*
        - LE CAS CENTRAL : deux pesées, un geste, deux versements. Un par pesée, et c'est ce qui
          permet à tous les écrans existants de continuer à lire ce qu'ils lisent — un versement
          pointe sur une pesée, hier comme aujourd'hui.
    */
    public function testDeuxPeseesSeReglentEnUnGeste(): void
    {
        $une = $this->creerPeseeArbitree($this->site, $this->produit, $this->planteur, self::POIDS, '1');
        $deux = $this->creerPeseeArbitree($this->site, $this->produit, $this->planteur, self::POIDS, '2');

        $this->appeler('POST', '/api/paiements/lot', $this->operateur, [
            'operationIds' => [$une->getId(), $deux->getId()],
            'modeReglement' => ModeReglement::ESPECES->value,
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $sortie = $this->reponse();
        self::assertSame(2, $sortie['nombre']);
        self::assertSame(2 * (self::RECOLTE + self::CARBURANT), $sortie['montant']);
        self::assertSame(2 * self::RECOLTE, $sortie['montantProduit']);
        self::assertSame(2 * self::CARBURANT, $sortie['montantTransport']);
        self::assertCount(2, $sortie['references']);

        self::assertSame(StatutReglement::SOLDE, $this->relire($une)->getStatutReglement());
        self::assertSame(StatutReglement::SOLDE, $this->relire($deux)->getStatutReglement());
    }

    /*
        - LA PART SEULE. Un planteur réclame parfois son carburant le jour même et sa récolte plus
          tard : le transport part, la récolte reste entièrement due.
    */
    public function testLaPartTransportNeTouchePasLaRecolte(): void
    {
        $une = $this->creerPeseeArbitree($this->site, $this->produit, $this->planteur, self::POIDS, '1');
        $deux = $this->creerPeseeArbitree($this->site, $this->produit, $this->planteur, self::POIDS, '2');

        $this->appeler('POST', '/api/paiements/lot', $this->operateur, [
            'operationIds' => [$une->getId(), $deux->getId()],
            'part' => 'TRANSPORT',
            'modeReglement' => ModeReglement::ESPECES->value,
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $sortie = $this->reponse();
        self::assertSame(0, $sortie['montantProduit']);
        self::assertSame(2 * self::CARBURANT, $sortie['montantTransport']);

        $une = $this->relire($une);
        self::assertSame(0, $une->getResteapayertransport());
        self::assertSame(self::RECOLTE, $une->getResteapayerproduit(), 'La récolte reste entièrement due');
        self::assertSame(StatutReglement::PARTIEL, $une->getStatutReglement());
    }

    /* ---- Les refus ---------------------------------------------------------------------------- */

    /*
        - UN VERSEMENT VA À UN PLANTEUR. La règle tombe avant d'écrire : un lot mélangé produirait
          des lignes qui ne veulent rien dire, et c'est l'écran qui aurait l'air de fonctionner.
    */
    public function testUnLotNeMelangePasDeuxPlanteurs(): void
    {
        $sien = $this->creerPeseeArbitree($this->site, $this->produit, $this->planteur, self::POIDS, '1');

        $autre = $this->creerPlanteur($this->site, 'Bamba', '0700000002');
        $this->em->flush();
        $laSienne = $this->creerPeseeArbitree($this->site, $this->produit, $autre, self::POIDS, '2');

        $this->appeler('POST', '/api/paiements/lot', $this->operateur, [
            'operationIds' => [$sien->getId(), $laSienne->getId()],
            'modeReglement' => ModeReglement::ESPECES->value,
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('UN planteur', (string) ($this->reponse()['detail'] ?? ''));
        self::assertCount(0, $this->em->getRepository(Paiement::class)->findAll(), 'Rien ne doit être écrit');
    }

    /*
        - L'ATOMICITÉ, et c'est le cas qui vaut tout ce fichier. La caisse tient la première pesée
          mais pas la seconde : le lot entier tombe, et PAS UN FRANC ne sort. Trois appels séparés
          auraient laissé la première payée, et l'opérateur l'aurait découvert au comptage du soir.
    */
    public function testUneCaisseInsuffisanteAnnuleToutLeLot(): void
    {
        $pauvre = $this->creerEntreprise('PAUVRE', 1_000_000, 1_000_000);
        $poste = $this->creerSite($pauvre, 'PAU001', 150_000, 150_000); // De quoi tenir UNE pesée, pas deux
        $produit = $this->creerProduit($poste, 'Cacao', self::PRIX);
        $planteur = $this->creerPlanteur($poste, 'Koffi', '0700000003');
        $guichet = $this->creerUtilisateur($pauvre, Role::OPERATEUR, 'guichet@pauvre.test');
        $this->affecterOperateur($poste, $guichet);
        $this->em->flush();

        $une = $this->creerPeseeArbitree($poste, $produit, $planteur, self::POIDS, '1');
        $deux = $this->creerPeseeArbitree($poste, $produit, $planteur, self::POIDS, '2');

        $this->appeler('POST', '/api/paiements/lot', $guichet, [
            'operationIds' => [$une->getId(), $deux->getId()],
            'part' => 'RECOLTE',
            'modeReglement' => ModeReglement::ESPECES->value,
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('insuffisante', (string) ($this->reponse()['detail'] ?? ''));

        self::assertCount(0, $this->em->getRepository(Paiement::class)->findAll(), 'Le lot tombe en entier');
        self::assertSame(self::RECOLTE, $this->relire($une)->getResteapayerproduit());

        /*
            - LA CAISSE SE RELIT DEPUIS LA BASE, et c'est le piège de ce cas : après un retour
              arrière, l'objet gardé en mémoire porte encore la valeur débitée — Doctrine annule
              la transaction, pas l'état des entités déjà chargées. L'affirmer sur l'objet aurait
              donné un cas qui échoue alors que la base est juste.
        */
        $relu = $this->em->find(Site::class, $poste->getId());
        self::assertNotNull($relu);
        self::assertSame(150_000, $relu->getSoldePhysique(), 'La caisse n a pas bougé');
    }

    /* Le mobile money passe par une passerelle : trois envois sous une transaction, dont le deuxième échoue, laisseraient un lot à moitié parti. */
    public function testUnLotNePasseParAucunePasserelle(): void
    {
        $pesee = $this->creerPeseeArbitree($this->site, $this->produit, $this->planteur, self::POIDS, '1');

        $this->appeler('POST', '/api/paiements/lot', $this->operateur, [
            'operationIds' => [$pesee->getId()],
            'modeReglement' => 'MOBILE_MONEY',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $this->em->getRepository(Paiement::class)->findAll());
    }

    /* Une liste vide n'est pas un geste : le refus est de validation, avant même d'atteindre le service. */
    public function testUnLotVideEstRefuse(): void
    {
        $this->appeler('POST', '/api/paiements/lot', $this->operateur, [
            'operationIds' => [],
            'modeReglement' => ModeReglement::ESPECES->value,
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    /* ---- Ce qu'on écarte en silence ------------------------------------------------------------ */

    /*
        - UNE PESÉE SANS RIEN À ENGAGER NE FAIT PAS TOMBER LE LOT. Elle a pu être réglée à l'autre
          guichet pendant que l'opérateur cochait : refuser tout le geste pour ça lui ferait
          recommencer sa sélection sans qu'il comprenne pourquoi.

        - ET LE COMPTE RENDU DIT CE QUI EST VRAIMENT SORTI : une pesée, pas deux. L'écran annonce
          donc le bon nombre, et non celui qui avait été demandé.
    */
    public function testUnePeseeDejaRegleeEstEcarteeSansFaireTomberLeLot(): void
    {
        $soldee = $this->creerPeseeArbitree($this->site, $this->produit, $this->planteur, self::POIDS, '1');
        $ouverte = $this->creerPeseeArbitree($this->site, $this->produit, $this->planteur, self::POIDS, '2');

        $this->appeler('POST', '/api/paiements', $this->operateur, [
            'operationId' => $soldee->getId(),
            'modeReglement' => ModeReglement::ESPECES->value,
            'montantProduit' => self::RECOLTE,
            'montantTransport' => self::CARBURANT,
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $this->appeler('POST', '/api/paiements/lot', $this->operateur, [
            'operationIds' => [$soldee->getId(), $ouverte->getId()],
            'modeReglement' => ModeReglement::ESPECES->value,
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $sortie = $this->reponse();
        self::assertSame(1, $sortie['nombre'], 'Une seule pesée avait encore quelque chose à engager');
        self::assertSame(self::RECOLTE + self::CARBURANT, $sortie['montant']);
    }

    /* Tout est déjà réglé : là, il n'y a plus de geste du tout, et le dire vaut mieux que de rendre un lot vide. */
    public function testUnLotEntierementRegleEstRefuse(): void
    {
        $pesee = $this->creerPeseeArbitree($this->site, $this->produit, $this->planteur, self::POIDS, '1');

        $this->appeler('POST', '/api/paiements/lot', $this->operateur, [
            'operationIds' => [$pesee->getId()],
            'modeReglement' => ModeReglement::ESPECES->value,
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $this->appeler('POST', '/api/paiements/lot', $this->operateur, [
            'operationIds' => [$pesee->getId()],
            'modeReglement' => ModeReglement::ESPECES->value,
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('déjà réglé', (string) ($this->reponse()['detail'] ?? ''));
    }

    /* ---- Outils ------------------------------------------------------------------------------- */

    private function relire(Operation $pesee): Operation
    {
        $id = $pesee->getId();
        $this->em->clear();

        $relue = $this->em->find(Operation::class, $id);
        self::assertNotNull($relue);

        return $relue;
    }
}
