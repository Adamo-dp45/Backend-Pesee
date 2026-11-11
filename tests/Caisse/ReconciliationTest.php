<?php

namespace App\Tests\Caisse;

use App\Domain\Enum\Role;
use App\Domain\Enum\ModeReglement;
use App\Domain\Enum\StatutPaiement;
use App\Domain\Service\Caisse\PaiementService;
use App\Entity\Paiement;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - La réconciliation est le filet des versements : elle réinterroge l'agrégateur pour ceux dont
      aucune notification n'est arrivée. Sans elle, un webhook perdu — coupure réseau, redéploiement
      au mauvais moment, panne chez l'agrégateur — laisserait les fonds immobilisés pour toujours.

    - Elle tourne par un cron ('make cron' donne la ligne). Or un cron s'arrête sans rien dire :
      personne ne surveille le surveillant. C'est ce que rend visible le TÉMOIN de l'écran Caisse —
      combien de versements sont en vol, et depuis quand pour le plus ancien. Un versement qui
      attend depuis des heures ne peut vouloir dire que deux choses : le cron est mort, ou
      l'agrégateur ne répond plus. Les deux demandent qu'on regarde.
*/
final class ReconciliationTest extends InvariantTestCase
{
    private const CAISSE_INITIALE = 100_000;

    /*
        - Le délai protège les versements qui viennent de partir : les réinterroger tout de suite
          ferait doublon avec la notification qui est peut-être déjà en route.
    */
    public function testUnVersementRecentNEstPasReinterroge(): void
    {
        [$paiement] = $this->engagerUnVersement();

        self::assertSame(0, self::getContainer()->get(PaiementService::class)->reconcilier(15));

        $this->em->clear();

        self::assertSame(StatutPaiement::ENVOYE, $this->relire($paiement)->getStatut());
    }

    public function testUnVersementOublieEstRegularise(): void
    {
        [$paiement] = $this->engagerUnVersement(vieuxDe: '-2 hours');

        self::assertSame(1, self::getContainer()->get(PaiementService::class)->reconcilier(15));

        $this->em->clear();

        self::assertSame(StatutPaiement::REUSSI, $this->relire($paiement)->getStatut());
    }

    /*
        - Le témoin. Il ne sert à rien d'autre qu'à faire remarquer une panne muette : sans lui, un
          cron arrêté ne se découvre qu'au moment où un opérateur ne peut plus payer, ses fonds étant
          retenus par des versements que plus personne ne tranche.
    */
    public function testLEcranCaisseSignaleLesVersementsRestesEnVol(): void
    {
        [, $operateur] = $this->engagerUnVersement(vieuxDe: '-3 hours');

        $this->appeler('GET', '/api/stats/caisse', $operateur);

        $caisse = $this->reponse();

        self::assertSame(1, $caisse['versementsEnVol'] ?? null);
        self::assertGreaterThanOrEqual(179, $caisse['attenteMinutes'] ?? 0, 'L\'ancienneté du plus ancien est ce qui distingue une lenteur d\'une panne');
    }

    /* Rien en vol : l'attente reste à 'null'. Zéro minute se lirait « à l'instant », et une alerte s'afficherait en permanence. */
    public function testSansVersementEnVolAucuneAttenteNEstAnnoncee(): void
    {
        [, $operateur] = $this->engagerUnVersement(vieuxDe: '-2 hours');

        // Le cron a fait son travail : le témoin doit s'éteindre, sinon l'alerte resterait à l'écran une fois le problème résolu
        self::getContainer()->get(PaiementService::class)->reconcilier(15);

        $this->appeler('GET', '/api/stats/caisse', $operateur);

        $caisse = $this->reponse();

        self::assertSame(0, $caisse['versementsEnVol'] ?? null);
        self::assertArrayHasKey('attenteMinutes', $caisse);
        self::assertNull($caisse['attenteMinutes']);
    }

    /* ---- Montage ----------------------------------------------------------------------------- */

    /** @return array{0: Paiement, 1: User, 2: Site} */
    private function engagerUnVersement(?string $vieuxDe = null): array
    {
        $entreprise = $this->creerEntreprise('ACME');
        $site = $this->creerSite($entreprise, 'SOF010', self::CAISSE_INITIALE);
        $operateur = $this->creerUtilisateur($entreprise, Role::OPERATEUR, 'operateur@acme.test');
        $this->affecterOperateur($site, $operateur);

        $produit = $this->creerProduit($site, 'Hévéa', 200);
        $planteur = $this->creerPlanteur($site);
        $pesee = $this->creerPesee($site, $produit, $planteur, 1_000);

        $paiement = self::getContainer()->get(PaiementService::class)->initier($pesee, 20_000, ModeReglement::MOBILE_MONEY, $operateur);

        self::assertSame(StatutPaiement::ENVOYE, $paiement->getStatut(), 'Le décor suppose un versement en vol');

        if($vieuxDe !== null) {
            // Le vieillissement passe par SQL : 'createdAt' est posé par le cycle de vie, un setter serait réécrit au flush suivant
            $this->em->getConnection()->executeStatement(
                'UPDATE paiement SET created_at = :quand WHERE id = :id',
                ['quand' => (new \DateTimeImmutable($vieuxDe))->format('Y-m-d H:i:s'), 'id' => $paiement->getId()]
            );

            $this->em->clear();
        }

        return [$paiement, $operateur, $site];
    }

    private function relire(Paiement $paiement): Paiement
    {
        $relu = $this->em->find(Paiement::class, $paiement->getId());

        self::assertNotNull($relu);

        return $relu;
    }
}
