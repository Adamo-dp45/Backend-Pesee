<?php

namespace App\Tests\Synchronisation;

use App\Domain\Enum\StatutOperation;
use App\Domain\Enum\StatutSite;
use App\Entity\Entreprise;
use App\Entity\Fournisseur;
use App\Entity\Operation;
use App\Entity\Produit;
use App\Entity\Site;
use App\Entity\SynchronisationLot;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - La synchronisation est le seul chemin par lequel une pesée entre en base, et elle vient d'un
      poste sur le terrain : liaison coupée en cours d'envoi, lot rejoué le lendemain, second passage
      qui complète une ligne déjà reçue. Deux invariants la tiennent.

      1. IDEMPOTENCE. Rejouer un lot ne crée aucun doublon : la clé 'codesecret' est unique. Sans
         elle, un poste qui n'a pas reçu l'accusé renvoie tout, et le planteur est payé deux fois
         pour un seul chargement.

      2. LE SITE VIENT DU COMPTE MACHINE. Le champ 'code' de la charge utile n'est plus qu'une
         vérification de cohérence. C'est le correctif de fond par rapport au modèle précédent, où ce
         champ suffisait à décider chez quel client les pesées atterrissaient.
*/
final class SynchronisationTest extends InvariantTestCase
{
    private Entreprise $entreprise;
    private Site $site;
    private User $poste;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = $this->creerEntreprise('ACME');
        $this->site = $this->creerSite($this->entreprise, 'SOF010');
        $this->poste = $this->creerCompteMachine($this->site);
    }

    public function testRejouerUnLotNeCreeAucunDoublon(): void
    {
        $lot = [$this->ligne('101'), $this->ligne('102')];

        $this->synchroniser($lot);

        $accuse = $this->reponse();
        self::assertSame(1, $accuse['code']);
        self::assertSame(2, $accuse['success']);
        self::assertCount(2, $this->pesees());

        // Le poste n'a pas reçu l'accusé : il renvoie tout
        $this->synchroniser($lot);

        $accuse = $this->reponse();
        self::assertSame(1, $accuse['code']);
        self::assertSame(2, $accuse['success'], 'Le poste doit pouvoir marquer ses lignes comme synchronisées');
        self::assertSame(0, $accuse['fail']);

        $this->em->clear();
        self::assertCount(2, $this->pesees(), 'Le rejeu a dupliqué des pesées');
    }

    /*
        - L'accusé de réception est un contrat de fiabilité : la référence renvoyée est la clé de la
          pesée dans la base LOCALE du poste, et c'est elle qui lui permet d'effacer sa file
          d'attente. Y faire figurer une ligne non enregistrée efface une pesée sur le terrain.
    */
    public function testLAccuseNeRenvoieQueLesReferencesReellementEnregistrees(): void
    {
        $this->synchroniser([
            $this->ligne('101'),
            ['id' => '102'], // Ligne incomplète : ni code ni codepesee
            $this->ligne('103'),
        ]);

        $accuse = $this->reponse();

        self::assertSame(['101', '103'], array_column($accuse['operation'], 'id'));
        self::assertSame(1, $accuse['fail']);
        self::assertSame('102', $accuse['erreurs'][0]['id']);
        self::assertCount(2, $this->pesees());
    }

    /*
        - Le cœur du correctif : un poste ne peut écrire que chez lui. Un code étranger dans la charge
          utile signale un fichier de configuration déposé sur le mauvais poste, ou une tentative
          d'écriture chez un voisin — dans les deux cas la ligne est refusée.
    */
    public function testUnPosteNePeutPasEcrireDansUnAutreSite(): void
    {
        $voisin = $this->creerSite($this->creerEntreprise('RIVALE'), 'RIV001');

        $this->synchroniser([$this->ligne('101', ['code' => $voisin->getCode()])]);

        $accuse = $this->reponse();

        self::assertSame(2, $accuse['code'], 'Le lot devrait être en échec');
        self::assertSame(0, $accuse['success']);
        self::assertStringContainsString('RIV001', $accuse['erreurs'][0]['message']);

        self::assertCount(0, $this->pesees());
        self::assertCount(0, $this->em->getRepository(Operation::class)->findBy(['site' => $voisin]));
    }

    /*
        - Produits et planteurs sont créés à la volée : le peseur les saisit sur son écran, le serveur
          ne peut qu'enregistrer ce qui arrive. Le rapprochement se fait sur une clé normalisée, sinon
          « HEVEA », « Hevea » et « Hévéa » créeraient trois produits.
    */
    public function testLesReferentielsSeCreentUneSeuleFoisMalgreLesVariantesDEcriture(): void
    {
        $this->synchroniser([
            $this->ligne('101', ['libelleproduit' => 'HEVEA', 'libellefournisseur' => 'KOUASSI']),
            $this->ligne('102', ['libelleproduit' => 'Hévéa', 'libellefournisseur' => 'Kouassi']),
            $this->ligne('103', ['libelleproduit' => 'hevea', 'libellefournisseur' => 'kouassi']),
        ]);

        self::assertSame(3, $this->reponse()['success']);

        $this->em->clear();

        self::assertCount(1, $this->em->getRepository(Produit::class)->findAll());
        self::assertCount(1, $this->em->getRepository(Fournisseur::class)->findAll());

        // Le premier libellé reçu fait foi : on n'écrase pas ce que le peseur a saisi
        self::assertSame('HEVEA', $this->em->getRepository(Produit::class)->findAll()[0]->getLibelle());
    }

    /*
        - Le signal de fin est le SECOND POIDS, jamais la date de second passage : le poste pré-remplit
          celle-ci avec la date du premier dès la création. S'y fier marquerait « terminée » toute
          pesée dès son premier envoi, lui inventerait un poids net et rendrait payable un camion
          encore sur le pont.
    */
    public function testUnCamionEncoreSurLePontResteEnAttenteEtSansMontant(): void
    {
        $this->synchroniser([$this->ligne('101', [
            'poids2' => 0,
            'poidsbrut' => 0,
            'poidsnet' => 0,
            'datepesee2' => '2026-08-09', // Pré-remplie par le poste alors que le camion n'est pas ressorti
            'temps2' => '08:00:00',
        ])]);

        $this->em->clear();
        $pesee = $this->pesees()[0];

        self::assertSame(StatutOperation::EN_COURS, $pesee->getStatut());
        self::assertNull($pesee->getPoids2());
        self::assertNull($pesee->getPoidsnet());
        self::assertNull($pesee->getMontantdu());
        self::assertNull($pesee->getPeseeAt2(), 'Une heure de sortie a été inventée');
        self::assertFalse($pesee->estPayable());
    }

    /*
        - Le second envoi COMPLÈTE la ligne au lieu d'en créer une nouvelle : c'est le déroulement
          normal d'une pesée, entrée puis sortie. La clé d'idempotence est la même aux deux passages.
    */
    public function testLeSecondPassageCompleteLaPeseeEtLaValorise(): void
    {
        $this->creerProduit($this->site, 'Hévéa', 200);

        $this->synchroniser([$this->ligne('101', ['poids2' => 0, 'poidsnet' => 0])]);
        $this->synchroniser([$this->ligne('101', ['poids2' => 1_500, 'poidsnet' => 500])]);

        $this->em->clear();

        self::assertCount(1, $this->pesees());

        $pesee = $this->pesees()[0];
        self::assertSame(StatutOperation::TERMINEE, $pesee->getStatut());
        self::assertSame(500, $pesee->getPoidsnet());
        self::assertSame(200, $pesee->getPrixunitaire());
        self::assertSame(100_000, $pesee->getMontantdu()); // 500 kg × 200 F
    }

    /*
        - LE PREMIER PASSAGE EST UN FAIT DE BASCULE, pas une donnée que le second envoi rapporte. Le
          poids d'entrée est relevé quand le camion monte sur le pont ; au second passage, le poste
          le recopie de sa base locale, et rien ne l'a repesé.

        - Ce qui arrive quand on laisse le second envoi l'écraser : une valeur corrigée à la main sur
          le poste, ou un champ revenu vide, réécrit après coup un relevé de bascule — et il n'existe
          aucune trace de ce qu'il valait. L'heure d'entrée suit la même règle, sans quoi on garderait
          un poids relevé au premier passage avec une heure venue du second.
    */
    public function testLeSecondPassageNeReecritNiLePremierPoidsNiSonHeure(): void
    {
        $this->synchroniser([$this->ligne('101', [
            'poids1' => 1_000,
            'datepesee1' => '2026-08-09',
            'temps1' => '08:00:00',
            'poids2' => 0,
            'poidsnet' => 0,
        ])]);

        // Le poste renvoie la ligne complétée, avec un premier poids et une heure d'entrée différents
        $this->synchroniser([$this->ligne('101', [
            'poids1' => 7_777,
            'datepesee1' => '2026-08-09',
            'temps1' => '23:59:00',
            'poids2' => 1_500,
            'poidsnet' => 500,
        ])]);

        $this->em->clear();
        $pesee = $this->pesees()[0];

        self::assertSame(1_000, $pesee->getPoids1(), 'Le poids d\'entrée a été réécrit par le second passage');
        self::assertSame('08:00', $pesee->getPeseeAt1()?->format('H:i'), 'L\'heure d\'entrée a été réécrite par le second passage');

        // Le second passage, lui, apporte bien ce qui lui appartient
        self::assertSame(1_500, $pesee->getPoids2());
        self::assertSame(500, $pesee->getPoidsnet());
    }

    /*
        - Figer le premier passage ne doit pas refuser ce qu'on ignore encore : un premier envoi qui
          n'apporte pas le poids d'entrée — champ absent, poste mal configuré — laisse la place, et le
          passage suivant la remplit. On fige ce qui est acquis, pas ce qui manque.
    */
    public function testUnPremierPoidsAbsentPeutEncoreArriverAuPassageSuivant(): void
    {
        $this->synchroniser([$this->ligne('101', ['poids1' => null, 'poids2' => 0, 'poidsnet' => 0])]);

        $this->em->clear();
        self::assertNull($this->pesees()[0]->getPoids1());

        $this->synchroniser([$this->ligne('101', ['poids1' => 1_000, 'poids2' => 1_500, 'poidsnet' => 500])]);

        $this->em->clear();
        self::assertSame(1_000, $this->pesees()[0]->getPoids1());
    }

    /*
        - Bloquer un poste coupe l'arrivée de ses pesées. Le poste continue d'enregistrer localement :
          elles remonteront au déblocage, et l'idempotence garantit qu'aucune ne sera perdue en double.
    */
    public function testUnPosteBloqueNEcritPlusRien(): void
    {
        $this->site->setStatut(StatutSite::BLOQUE);
        $this->em->flush();

        $this->synchroniser([$this->ligne('101')]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $this->pesees());
    }

    /*
        - Le journal garde une trace de chaque envoi, réussi ou non. C'est lui qui répond à « pourquoi
          ce poste ne remonte plus rien depuis trois semaines ».
    */
    public function testChaqueEnvoiLaisseUneTraceAuJournal(): void
    {
        $this->synchroniser([$this->ligne('101'), ['id' => '102']]);

        $this->em->clear();
        $lots = $this->em->getRepository(SynchronisationLot::class)->findAll();

        self::assertCount(1, $lots);
        self::assertSame(2, $lots[0]->getNbRecus());
        self::assertSame(1, $lots[0]->getNbCrees());
        self::assertSame(1, $lots[0]->getNbErreurs());
        self::assertSame($this->site->getId(), $lots[0]->getSite()?->getId());
    }

    /* ---- Outils ------------------------------------------------------------------------------ */

    /**
     * Une ligne au format du logiciel de pesée. Les noms de champs sont ceux du poste, pas ceux des
     * colonnes : c'est le contrat qu'on ne change pas.
     *
     * @param array<string, mixed> $surcharges
     *
     * @return array<string, mixed>
     */
    /*
        - LE POSTE PESE, IL NE TARIFE PAS. Les logiciels de pesée déployés envoient toujours 'prix'
          et 'prixtransport' dans leur charge utile — on ne va pas les mettre à jour tous en même
          temps, et les refuser ferait échouer des lots entiers. Ces champs sont donc LUS ET JETÉS.

        - Ce que ce cas garde : le prix du bureau survit à la synchronisation, ET la pesée est
          valorisée avec LUI. Si l'un des deux lâchait, personne ne le verrait : la pesée s'affiche
          avec un montant plausible, simplement calculé sur un prix que le bureau n'a pas décidé.
    */
    public function testUnPrixEnvoyeParLePosteEstIgnore(): void
    {
        $produit = $this->creerProduit($this->site, 'Hévéa', 400);
        $planteur = $this->creerPlanteur($this->site, 'Kouassi');
        $planteur->setPrixtransport(30);
        $this->em->flush();

        $this->synchroniser([$this->ligne('101', ['prix' => 999, 'prixtransport' => 777])]);
        self::assertSame(1, $this->reponse()['success'] ?? null, (string) $this->client->getResponse()->getContent());

        $this->em->clear();

        self::assertSame(400, $this->em->find(Produit::class, $produit->getId())?->getPrix(), 'Le poste a écrasé le prix du bureau');
        self::assertSame(30, $this->em->find(Fournisseur::class, $planteur->getId())?->getPrixtransport(), 'Le poste a écrasé le transport du bureau');

        // 500 kg : la récolte au prix du bureau, le transport au sien aussi
        $pesee = $this->pesees()[0];
        self::assertSame(500 * 400, $pesee->getMontantdu());
        self::assertSame(500 * 30, $pesee->getMontanttransport());
    }

    /*
        - Le corollaire : un produit créé par le peseur naît À ZÉRO et le reste. C'était déjà vrai
          quand le poste se taisait, c'est maintenant la règle — et c'est pourquoi la liste des
          produits sait filtrer ceux qui attendent leur prix.
    */
    public function testUnProduitCreeParLePosteNaitSansPrixMemeSiLeLotEnPorteUn(): void
    {
        $this->synchroniser([$this->ligne('101', ['libelleproduit' => 'Cacao', 'prix' => 650])]);
        self::assertSame(1, $this->reponse()['success'] ?? null);

        $this->em->clear();

        $produit = $this->em->getRepository(Produit::class)->findOneBy(['libelle' => 'Cacao']);
        self::assertNotNull($produit, 'Le référentiel doit toujours se créer à la volée');
        self::assertSame(0, $produit->getPrix());

        // Et la pesée ne vaut rien, ce qui est préférable à un montant que personne n'a décidé
        self::assertSame(0, $this->pesees()[0]->getMontantpayable());
    }

    private function ligne(string $refDesktop, array $surcharges = []): array
    {
        return $surcharges + [
            'id' => $refDesktop,
            'code' => $this->site->getCode(),
            'codepesee' => 'P' . $refDesktop,
            'numticket' => 'T' . $refDesktop,
            'libellemouvement' => 'RECEPTION',
            'libelleclient' => 'Client test',
            'libelleproduit' => 'Hévéa',
            'libellefournisseur' => 'Kouassi',
            'immatriculation' => '1234 AB 01',
            'chauffeur' => 'Yao',
            'poids1' => 1_000,
            'datepesee1' => '2026-08-09',
            'temps1' => '08:00:00',
            'poids2' => 1_500,
            'poidsbrut' => 1_500,
            'poidsnet' => 500,
            'datepesee2' => '2026-08-09',
            'temps2' => '10:30:00',
        ];
    }

    /**
     * @param list<array<string, mixed>> $pesees
     */
    private function synchroniser(array $pesees): void
    {
        $this->appeler('POST', '/api/synchronisation', $this->poste, ['pesees' => $pesees]);
    }

    /** @return list<Operation> */
    private function pesees(): array
    {
        return $this->em->getRepository(Operation::class)->findBy([], ['id' => 'ASC']);
    }
}
