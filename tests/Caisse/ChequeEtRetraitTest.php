<?php

namespace App\Tests\Caisse;

use App\Domain\Enum\CompteMouvement;
use App\Domain\Enum\ModeReglement;
use App\Domain\Enum\Role;
use App\Domain\Enum\StatutDemande;
use App\Domain\Enum\StatutPaiement;
use App\Domain\Enum\TypeCaisse;
use App\Domain\Enum\TypeMouvementEpargne;
use App\Domain\Service\Epargne\EpargneService;
use App\Entity\CompteEpargne;
use App\Entity\DemandeRetrait;
use App\Entity\Entreprise;
use App\Entity\Fournisseur;
use App\Entity\MouvementCaisse;
use App\Entity\MouvementEpargne;
use App\Entity\Produit;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - Deux chantiers, un seul fichier, parce qu'ils partagent le même circuit de versement.

    - LE CHÈQUE est le premier règlement qui ne vide pas la caisse du poste : il est tiré sur le
      compte en banque de l'entreprise. Ce qui est surveillé ici n'est pas l'arithmétique mais le
      NIVEAU — un chèque qui débiterait le tiroir de l'opérateur ferait mentir son contrôle de
      caisse, sans qu'aucune erreur ne se lève.

    - LE RETRAIT est un versement sans pesée. Ce qui est surveillé, c'est que les DEUX livres
      bougent ensemble : l'épargne du planteur et la caisse qui lui remet l'argent. Un échec doit
      les ramener tous les deux, pas un seul.
*/
final class ChequeEtRetraitTest extends InvariantTestCase
{
    private const PRIX = 200;
    private const POIDS = 500; // La pesée vaut donc 100 000 F

    private Entreprise $entreprise;
    private Site $site;
    private Produit $produit;
    private User $operateur;
    private User $admin;
    private EpargneService $epargne;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = $this->creerEntreprise('ACME', 2_000_000, 2_000_000);
        $this->site = $this->creerSite($this->entreprise, 'ACM001', 1_000_000, 1_000_000);
        $this->produit = $this->creerProduit($this->site, 'Hévéa', self::PRIX);

        $this->operateur = $this->creerUtilisateur($this->entreprise, Role::OPERATEUR, 'operateur@acme.test');
        $this->affecterOperateur($this->site, $this->operateur);
        $this->admin = $this->creerUtilisateur($this->entreprise, Role::ADMIN, 'admin@acme.test');

        $this->epargne = self::getContainer()->get(EpargneService::class);
    }

    private function approvisionnerLaBanque(int $montant): void
    {
        $this->entreprise->setSoldeDe(TypeCaisse::BANCAIRE, $montant);
        $this->em->flush();
    }

    /* ---- Le chèque --------------------------------------------------------------------------- */

    /*
        - LE cas du chèque : l'argent sort de la banque de l'entreprise, et le poste ne perd rien.
          Si cette assertion tombe, le contrôle de caisse de l'opérateur cesse de tomber juste — il
          compte ses billets et en trouve plus que l'écran n'en annonce.
    */
    public function testUnChequeDebiteLaBanqueDeLEntrepriseEtPasLePoste(): void
    {
        $this->approvisionnerLaBanque(5_000_000);

        $planteur = $this->creerPlanteur($this->site);
        $pesee = $this->creerPesee($this->site, $this->produit, $planteur, self::POIDS);

        $especesAvant = $this->site->getSoldeDe(TypeCaisse::PHYSIQUE);
        $flotteAvant = $this->site->getSoldeDe(TypeCaisse::NUMERIQUE);

        $this->appeler('POST', '/api/paiements', $this->operateur, [
            'operationId' => $pesee->getId(),
            'montantProduit' => 100_000,
            'montantTransport' => 0,
            'modeReglement' => ModeReglement::CHEQUE->value,
            'numeroCheque' => 'CHQ-004512',
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->em->clear();

        $entreprise = $this->em->find(Entreprise::class, $this->entreprise->getId());
        $site = $this->em->find(Site::class, $this->site->getId());

        self::assertSame(4_900_000, $entreprise?->getSoldeDe(TypeCaisse::BANCAIRE), 'La banque n\'a pas été débitée');
        self::assertSame($especesAvant, $site?->getSoldeDe(TypeCaisse::PHYSIQUE), 'Le tiroir de l\'opérateur a bougé alors qu\'aucun billet n\'est sorti');
        self::assertSame($flotteAvant, $site?->getSoldeDe(TypeCaisse::NUMERIQUE), 'Le flotte a bougé alors que rien n\'est parti par mobile');

        // La ligne du grand livre s'impute à l'ENTREPRISE, et garde le poste où le chèque a été remis
        $ligne = $this->em->getRepository(MouvementCaisse::class)->findOneBy(['caisse' => TypeCaisse::BANCAIRE, 'compte' => CompteMouvement::ENTREPRISE], ['id' => 'DESC']);

        self::assertNotNull($ligne);
        self::assertSame(CompteMouvement::ENTREPRISE, $ligne->getCompte());
        self::assertSame($this->site->getId(), $ligne->getSite()?->getId(), 'Le poste où le chèque a été remis a disparu de la pièce');
    }

    /*
        - Sans son numéro, un chèque est introuvable sur le relevé bancaire. Le refus tombe AVANT
          que la banque soit débitée : une pièce sans référence vaut moins qu'un refus.
    */
    public function testUnChequeSansNumeroEstRefuseAvantDeDebiter(): void
    {
        $this->approvisionnerLaBanque(5_000_000);

        $planteur = $this->creerPlanteur($this->site);
        $pesee = $this->creerPesee($this->site, $this->produit, $planteur, self::POIDS);

        $this->appeler('POST', '/api/paiements', $this->operateur, [
            'operationId' => $pesee->getId(),
            'montantProduit' => 100_000,
            'montantTransport' => 0,
            'modeReglement' => ModeReglement::CHEQUE->value,
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('relevé bancaire', (string) ($this->reponse()['detail'] ?? ''));

        $this->em->clear();
        self::assertSame(5_000_000, $this->em->find(Entreprise::class, $this->entreprise->getId())?->getSoldeDe(TypeCaisse::BANCAIRE));
    }

    /*
        - Un poste ne tient pas de caisse bancaire : il ne peut donc pas en réclamer une dotation.
          Le refus est un message métier, pas une erreur de moteur — sans lui, la demande traversait
          l'écran de l'administrateur et mourait au milieu d'une transaction qui déplace de l'argent.
    */
    public function testUnPosteNePeutPasDemanderUneDotationBancaire(): void
    {
        $this->appeler('PATCH', '/api/sites/' . $this->site->getId() . '/dotation', $this->admin, [
            'caisse' => TypeCaisse::BANCAIRE->value,
            'montant' => 100_000,
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('ne tient pas', (string) ($this->reponse()['detail'] ?? ''));
    }

    /* ---- Le retrait -------------------------------------------------------------------------- */

    /*
        - La notification d'échec de l'agrégateur, signée comme la vraie : la signature porte sur le
          CORPS BRUT, et le secret se lit dans l'environnement de test plutôt que recopié ici — une
          valeur en double finit toujours par diverger de celle que le code lit.
    */
    private function notifierEchec(string $reference): void
    {
        $corps = json_encode([
            'reference' => $reference,
            'statut' => StatutPaiement::ECHOUE->value,
            'transaction' => 'SIM-' . $reference,
            'message' => 'Numéro injoignable',
        ], JSON_THROW_ON_ERROR);

        $secret = (string) ($_ENV['PASSERELLE_SECRET'] ?? $_SERVER['PASSERELLE_SECRET'] ?? '');

        $this->client->request('POST', '/api/webhooks/paiement', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $corps, $secret),
        ], $corps);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    private function planteurAvecEpargne(int $solde): Fournisseur
    {
        $planteur = $this->creerPlanteur($this->site, 'Kouassi');

        $this->ouvrirPrelevement($planteur, $this->produit, 100); // 100 F/kg sur un produit à 200
        $this->creerPeseeArbitree($this->site, $this->produit, $planteur, (int) ($solde / 100), '9');

        $this->em->clear();

        $planteur = $this->em->find(Fournisseur::class, $planteur->getId());

        self::assertSame($solde, $planteur?->getSoldeEpargne(), 'Le planteur n\'a pas l\'épargne attendue');

        return $planteur;
    }

    private function accorder(Fournisseur $planteur, int $demande, ?int $accorde = null): int
    {
        $this->appeler('POST', '/api/demande_retraits', $this->operateur, [
            'fournisseur' => '/api/fournisseurs/' . $planteur->getId(),
            'montantDemande' => $demande,
            'motif' => 'Il vient chercher son argent',
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $id = (int) ($this->reponse()['id'] ?? 0);

        $this->appeler('PATCH', '/api/demande_retraits/' . $id . '/approbation', $this->admin, $accorde === null ? [] : ['montantAccorde' => $accorde]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return $id;
    }

    /*
        - LE cas nominal, et il dit tout : les deux livres baissent du MÊME montant, dans le même
          geste. L'épargne du planteur et la caisse qui lui remet l'argent — l'un sans l'autre et
          l'entreprise paie deux fois, ou le planteur perd ce qu'on lui gardait.
    */
    public function testUnRetraitBaisseLEpargneEtLaCaisseDuMemeMontant(): void
    {
        $planteur = $this->planteurAvecEpargne(200_000);
        $id = $this->accorder($planteur, 120_000);

        $especesAvant = $this->em->find(Site::class, $this->site->getId())?->getSoldeDe(TypeCaisse::PHYSIQUE);

        $this->appeler('PATCH', '/api/demande_retraits/' . $id . '/versement', $this->operateur, [
            'modeReglement' => ModeReglement::ESPECES->value,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->em->clear();

        self::assertSame(80_000, $this->em->getRepository(CompteEpargne::class)->findOneBy([])?->getSolde(), 'L\'épargne n\'a pas baissé du montant versé');
        self::assertSame($especesAvant - 120_000, $this->em->find(Site::class, $this->site->getId())?->getSoldeDe(TypeCaisse::PHYSIQUE));

        // La demande porte le versement qui l'a servie : c'est ce qui distingue « accordé » de « servi »
        $demande = $this->em->find(DemandeRetrait::class, $id);
        self::assertNotNull($demande?->getPaiement());
        self::assertFalse($demande->attendLeVersement());
    }

    /*
        - LE CAS QUI NE SE VOIT PAS. Un retrait mobile part en vol : l'épargne est déjà débitée, la
          caisse aussi. Si la passerelle refuse, la caisse est remboursée par le même chemin que
          n'importe quel versement — mais l'épargne, elle, n'a pas de chemin commun : les deux
          livres sont séparés, et rien ne les rapproche.
        - Sans la ligne d'annulation, le planteur perd définitivement une somme qu'il n'a jamais
          touchée, l'entreprise la garde sans le savoir, et AUCUN écran ne le signale : le total
          des caisses retombe juste, c'est la dette envers le planteur qui est fausse.
    */
    public function testUnRetraitMobileEchoueRendLArgentAuxDeuxLivres(): void
    {
        $this->exigeLeMobileMoney();

        $planteur = $this->planteurAvecEpargne(200_000);
        $id = $this->accorder($planteur, 150_000);

        $flotteAvant = $this->em->find(Site::class, $this->site->getId())?->getSoldeDe(TypeCaisse::NUMERIQUE);

        $this->appeler('PATCH', '/api/demande_retraits/' . $id . '/versement', $this->operateur, [
            'modeReglement' => ModeReglement::MOBILE_MONEY->value,
            'numeroDestinataire' => '0700000001',
            'reseau' => 'ORANGE',
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->em->clear();

        /* En vol : les deux livres ont DÉJÀ baissé, et c'est voulu — sinon deux retraits du même solde partiraient ensemble. */
        $demande = $this->em->find(DemandeRetrait::class, $id);
        $paiement = $demande?->getPaiement();
        self::assertNotNull($paiement);
        self::assertSame(StatutPaiement::ENVOYE, $paiement->getStatut(), 'Le cas suppose un versement resté en vol');
        self::assertSame(50_000, $this->em->getRepository(CompteEpargne::class)->findOneBy([])?->getSolde());
        self::assertSame($flotteAvant - 150_000, $this->em->find(Site::class, $this->site->getId())?->getSoldeDe(TypeCaisse::NUMERIQUE));

        $this->notifierEchec((string) $paiement->getReference());
        $this->em->clear();

        self::assertSame(200_000, $this->em->getRepository(CompteEpargne::class)->findOneBy([])?->getSolde(), 'L\'épargne n\'a pas été rendue au planteur');
        self::assertSame($flotteAvant, $this->em->find(Site::class, $this->site->getId())?->getSoldeDe(TypeCaisse::NUMERIQUE), 'La caisse n\'a pas été remboursée');

        /* Et le compte d'épargne porte les DEUX lignes : le retrait, puis son annulation. Un solde revenu sans trace serait un solde corrigé à la main. */
        $types = array_map(
            static fn(MouvementEpargne $m) => $m->getType(),
            $this->em->getRepository(MouvementEpargne::class)->findBy([], ['id' => 'ASC'])
        );
        self::assertContains(TypeMouvementEpargne::RETRAIT, $types);
        self::assertContains(TypeMouvementEpargne::ANNULATION_RETRAIT, $types);
    }

    /*
        - LE RELEVÉ D'UN PLANTEUR DOIT SE CHAÎNER À L'ŒIL : le « solde après » d'une ligne est le
          « solde avant » de la suivante, et c'est ainsi qu'on vérifie qu'aucune écriture n'a été
          passée hors du service. La fiche le promet en toutes lettres.
        - Trié sur la seule date, deux écritures de la MÊME SECONDE sortent dans un ordre indéfini,
          et la promesse tombe : un solde qui ne reprend pas le précédent se lit comme une écriture
          perdue. Au guichet, un prélèvement arbitré puis un retrait servi dans la foulée tiennent
          dans la même seconde.
        - D'où 'id' en départageur, et ce cas qui le fige.
    */
    public function testLeReleveDEpargneSeChaineQuandDeuxEcrituresPartagentLaSeconde(): void
    {
        $planteur = $this->planteurAvecEpargne(200_000);
        $id = $this->accorder($planteur, 100_000);

        $this->appeler('PATCH', '/api/demande_retraits/' . $id . '/versement', $this->operateur, [
            'modeReglement' => ModeReglement::ESPECES->value,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        // Les deux écritures à la même seconde : c'est le cas que le tri doit savoir départager
        $memeInstant = new \DateTimeImmutable('2026-11-16 12:00:00');

        foreach($this->em->getRepository(MouvementEpargne::class)->findAll() as $mouvement) {
            $mouvement->setCreatedAt($memeInstant);
        }

        $this->em->flush();
        $this->em->clear();

        $this->appeler(
            'GET',
            '/api/mouvement_epargnes?compte.fournisseur=' . $planteur->getId() . '&order%5BcreatedAt%5D=desc&order%5Bid%5D=desc',
            $this->admin
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $lignes = $this->reponse()['member'] ?? $this->reponse()['hydra:member'] ?? [];
        self::assertCount(2, $lignes, 'Le relevé doit porter le prélèvement et le retrait');

        // La plus récente d'abord : le retrait, qui a vidé une partie du compte
        self::assertSame(TypeMouvementEpargne::RETRAIT->value, $lignes[0]['type']);
        self::assertSame(TypeMouvementEpargne::PRELEVEMENT->value, $lignes[1]['type']);

        // ET LA CHAÎNE : ce sur quoi on lit un relevé, ligne après ligne
        self::assertSame(
            $lignes[1]['soldeApres'],
            $lignes[0]['soldeAvant'],
            'Le relevé ne se chaîne plus : deux écritures de la même seconde sont sorties dans le désordre'
        );
    }

    /* Servir deux fois le même accord doublerait le retrait sur un seul feu vert. */
    public function testUnRetraitDejaVerseNeSeVersePasUneSecondeFois(): void
    {
        $planteur = $this->planteurAvecEpargne(200_000);
        $id = $this->accorder($planteur, 100_000);

        $this->appeler('PATCH', '/api/demande_retraits/' . $id . '/versement', $this->operateur, ['modeReglement' => ModeReglement::ESPECES->value]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->appeler('PATCH', '/api/demande_retraits/' . $id . '/versement', $this->operateur, ['modeReglement' => ModeReglement::ESPECES->value]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('déjà été versé', (string) ($this->reponse()['detail'] ?? ''));

        $this->em->clear();
        self::assertSame(100_000, $this->em->getRepository(CompteEpargne::class)->findOneBy([])?->getSolde());
    }

    /*
        - La borne du guichet, et non celle du bureau : entre l'accord et le versement, l'épargne a
          pu fondre. C'est le solde lu SOUS VERROU qui tranche, pas le montant accordé.
    */
    public function testUnRetraitNePeutPasDepasserLEpargneAuMomentDuGuichet(): void
    {
        $planteur = $this->planteurAvecEpargne(200_000);
        $id = $this->accorder($planteur, 200_000);

        // L'épargne fond entre l'accord et le guichet
        $this->epargne->retirer($this->em->find(Fournisseur::class, $planteur->getId()), 150_000, 'Retrait antérieur');
        $this->em->flush();
        $this->em->clear();

        $this->appeler('PATCH', '/api/demande_retraits/' . $id . '/versement', $this->operateur, ['modeReglement' => ModeReglement::ESPECES->value]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('supérieur à l\'épargne', (string) ($this->reponse()['detail'] ?? ''));

        $this->em->clear();
        self::assertSame(50_000, $this->em->getRepository(CompteEpargne::class)->findOneBy([])?->getSolde(), 'Le refus a quand même touché à l\'épargne');
    }

    /* Deux demandes en attente autoriseraient deux versements dont la somme dépasse l'épargne. */
    public function testUneSeuleDemandeDeRetraitEnAttenteParPlanteur(): void
    {
        $planteur = $this->planteurAvecEpargne(200_000);

        $this->appeler('POST', '/api/demande_retraits', $this->operateur, [
            'fournisseur' => '/api/fournisseurs/' . $planteur->getId(),
            'montantDemande' => 50_000,
            'motif' => 'Première',
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $this->appeler('POST', '/api/demande_retraits', $this->operateur, [
            'fournisseur' => '/api/fournisseurs/' . $planteur->getId(),
            'montantDemande' => 50_000,
            'motif' => 'Seconde',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('déjà en attente', (string) ($this->reponse()['detail'] ?? ''));
    }

    /*
        - Un accord non servi immobilise l'épargne sans l'avoir débitée. Déposer une seconde demande
          par-dessus laisserait empiler des autorisations dont la somme dépasse le solde — et le
          refus tomberait au guichet, devant le planteur.
    */
    public function testUnAccordNonServiBloqueUneNouvelleDemande(): void
    {
        $planteur = $this->planteurAvecEpargne(200_000);
        $this->accorder($planteur, 100_000);

        $this->appeler('POST', '/api/demande_retraits', $this->operateur, [
            'fournisseur' => '/api/fournisseurs/' . $planteur->getId(),
            'montantDemande' => 50_000,
            'motif' => 'Une autre',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('déjà accordé', (string) ($this->reponse()['detail'] ?? ''));
    }

    /* On n'accorde pas plus que ce qui a été demandé : l'accord borne, il n'invente pas. */
    public function testOnNAccordePasPlusQueLeMontantDemande(): void
    {
        $planteur = $this->planteurAvecEpargne(200_000);

        $this->appeler('POST', '/api/demande_retraits', $this->operateur, [
            'fournisseur' => '/api/fournisseurs/' . $planteur->getId(),
            'montantDemande' => 50_000,
            'motif' => 'Un peu',
        ]);
        $id = (int) ($this->reponse()['id'] ?? 0);

        $this->appeler('PATCH', '/api/demande_retraits/' . $id . '/approbation', $this->admin, ['montantAccorde' => 150_000]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('plus que ce qui a été demandé', (string) ($this->reponse()['detail'] ?? ''));
    }

    /*
        - L'ACCORD NE DÉPLACE RIEN. C'est toute la différence avec une demande de solde, qui dote la
          caisse sur-le-champ. Si cette assertion tombe, l'épargne est débitée deux fois : à l'accord
          puis au guichet.
    */
    public function testLAccordNeTouchePasAuxLivres(): void
    {
        $planteur = $this->planteurAvecEpargne(200_000);

        $ecrituresAvant = count($this->em->getRepository(MouvementCaisse::class)->findAll());
        $epargneAvant = count($this->em->getRepository(MouvementEpargne::class)->findAll());

        $this->accorder($planteur, 100_000);
        $this->em->clear();

        self::assertCount($ecrituresAvant, $this->em->getRepository(MouvementCaisse::class)->findAll(), 'Un accord a écrit au grand livre des caisses');
        self::assertCount($epargneAvant, $this->em->getRepository(MouvementEpargne::class)->findAll(), 'Un accord a écrit au livre d\'épargne');
        self::assertSame(200_000, $this->em->getRepository(CompteEpargne::class)->findOneBy([])?->getSolde());
    }
}
