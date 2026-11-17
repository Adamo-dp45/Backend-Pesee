<?php

namespace App\Tests\Caisse;

use App\Domain\Enum\Role;
use App\Domain\Enum\ModeReglement;
use App\Domain\Enum\StatutPaiement;
use App\Domain\Enum\StatutReglement;
use App\Domain\Enum\TypeMouvement;
use App\Domain\Service\Caisse\PaiementService;
use App\Entity\MouvementCaisse;
use App\Entity\Operation;
use App\Entity\Paiement;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - Un agrégateur mobile money rejoue ses notifications : il ne sait pas si la première est
      arrivée, alors il insiste. Recevoir deux fois la même confirmation doit donc être sans effet.

    - Ce qui se casse en silence quand ce n'est pas le cas : sur un ÉCHEC rejoué, la caisse du poste
      est remboursée deux fois et le site s'enrichit d'un versement qui n'a jamais eu lieu ; sur une
      RÉUSSITE rejouée, la pesée passe à un montant payé supérieur au montant dû. Aucune erreur n'est
      levée dans les deux cas.

    - Le webhook répond toujours 200, même sur une notification ignorée : un agrégateur qui reçoit
      autre chose réessaie, parfois des heures durant.
*/
final class PaiementWebhookTest extends InvariantTestCase
{
    private const CAISSE_INITIALE = 100_000;

    /* Marqueur interne : demande à 'notifier()' de calculer la vraie signature. */
    private const SIGNER = '__signer__';

    public function testUneConfirmationRejoueeNeCrediteQuUneFois(): void
    {
        [$paiement, $pesee, $site] = $this->engagerUnVersement(20_000);

        $this->notifier($paiement->getReference(), 'REUSSI');
        $this->notifier($paiement->getReference(), 'REUSSI'); // L'agrégateur insiste

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $paiement = $this->relire(Paiement::class, $paiement->getId());
        $pesee = $this->relire(Operation::class, $pesee->getId());

        self::assertSame(StatutPaiement::REUSSI, $paiement->getStatut());
        self::assertSame(20_000, $pesee->getMontantpaye(), 'Le montant versé a été compté deux fois');
        self::assertSame(StatutReglement::PARTIEL, $pesee->getStatutReglement());

        // Les fonds sont sortis une seule fois : au moment de la réservation, avant l'appel passerelle
        self::assertSame(self::CAISSE_INITIALE - 20_000, $this->soldeDuSite($site));
        self::assertCount(1, $this->ecrituresDeType(TypeMouvement::PAIEMENT));
        self::assertCount(0, $this->ecrituresDeType(TypeMouvement::REMBOURSEMENT));
    }

    /*
        - Le cas le plus coûteux : chaque rejeu d'un échec rendrait au poste une somme qui n'a quitté
          sa caisse qu'une fois. Un agrégateur bavard suffirait à créer de l'argent.
    */
    public function testUnEchecRejoueNeRembourseQuUneFois(): void
    {
        [$paiement, $pesee, $site] = $this->engagerUnVersement(20_000);

        self::assertSame(self::CAISSE_INITIALE - 20_000, $this->soldeDuSite($site), 'Les fonds sont réservés dès l\'engagement');

        $this->notifier($paiement->getReference(), 'ECHOUE');
        $this->notifier($paiement->getReference(), 'ECHOUE');
        $this->notifier($paiement->getReference(), 'ECHOUE');

        $this->em->clear();
        $paiement = $this->relire(Paiement::class, $paiement->getId());
        $pesee = $this->relire(Operation::class, $pesee->getId());

        self::assertSame(StatutPaiement::ECHOUE, $paiement->getStatut());
        self::assertSame(0, $pesee->getMontantpaye());
        self::assertSame(StatutReglement::NON_PAYE, $pesee->getStatutReglement());

        // La caisse est revenue à son point de départ, ni plus ni moins
        self::assertSame(self::CAISSE_INITIALE, $this->soldeDuSite($site));
        self::assertCount(1, $this->ecrituresDeType(TypeMouvement::PAIEMENT));
        self::assertCount(1, $this->ecrituresDeType(TypeMouvement::REMBOURSEMENT), 'Le remboursement a été rejoué');
    }

    /*
        - Le remboursement est une écriture de PLUS, jamais l'effacement de la précédente. Le débit
          initial doit rester lisible : c'est la trace qu'un versement a été tenté.
    */
    public function testUnEchecPasseUneContrepartieEtNeReecritRien(): void
    {
        [$paiement, , $site] = $this->engagerUnVersement(20_000);

        $this->notifier($paiement->getReference(), 'ECHOUE');

        $this->em->clear();

        $ecritures = $this->em->getRepository(MouvementCaisse::class)->findBy([], ['id' => 'ASC']);
        $surLaCaisseDuSite = array_values(array_filter(
            $ecritures,
            static fn(MouvementCaisse $m) => in_array($m->getType(), [TypeMouvement::PAIEMENT, TypeMouvement::REMBOURSEMENT], true)
        ));

        self::assertCount(2, $surLaCaisseDuSite);

        // La contrepartie repart exactement du solde que le débit a laissé
        self::assertSame(self::CAISSE_INITIALE, $surLaCaisseDuSite[0]->getSoldeAvant());
        self::assertSame(self::CAISSE_INITIALE - 20_000, $surLaCaisseDuSite[0]->getSoldeApres());
        self::assertSame(self::CAISSE_INITIALE - 20_000, $surLaCaisseDuSite[1]->getSoldeAvant());
        self::assertSame(self::CAISSE_INITIALE, $surLaCaisseDuSite[1]->getSoldeApres());

        self::assertSame(self::CAISSE_INITIALE, $this->soldeDuSite($site));
    }

    /*
        - Une notification qu'on choisit d'ignorer n'est pas une erreur : répondre autre chose que 200
          transforme un non-événement en tempête de requêtes.
    */
    public function testUneReferenceInconnueEstAcquitteeSansEffet(): void
    {
        $this->engagerUnVersement(20_000);

        $this->notifier('PAY-INEXISTANTE', 'REUSSI');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $this->ecrituresDeType(TypeMouvement::REMBOURSEMENT));
    }

    /*
        - Le versement doit avoir été traité par la passerelle qui notifie. Sans ce contrôle, changer
          d'agrégateur ferait confirmer par le nouveau des versements encore en vol chez l'ancien,
          dont il ne sait rien.
    */
    public function testUneNotificationDUneAutrePasserelleEstIgnoree(): void
    {
        [$paiement, , $site] = $this->engagerUnVersement(20_000);

        $paiement->setGateway('un-autre-agregateur');
        $this->em->flush();

        $this->notifier($paiement->getReference(), 'ECHOUE');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();

        self::assertSame(StatutPaiement::ENVOYE, $this->relire(Paiement::class, $paiement->getId())->getStatut());
        self::assertSame(self::CAISSE_INITIALE - 20_000, $this->soldeDuSite($site), 'Les fonds ont été rendus sur une notification étrangère');
    }

    /*
        - Un versement en vol a déjà quitté la caisse. Tant qu'il n'est pas tranché, le reste à payer
          qu'il couvre ne peut pas être engagé une seconde fois — sinon le planteur est payé deux fois
          le jour où les deux aboutissent.
    */
    public function testUnVersementEnVolInterditDEngagerLeMemeResteAPayer(): void
    {
        // La pesée doit 200 000 F, et la caisse en contient assez pour deux : seule la règle du
        // montant ENGAGÉ peut alors refuser le second versement
        [, $pesee] = $this->engagerUnVersement(200_000, 500_000);

        $operateur = $this->em->getRepository(User::class)->findOneBy(['email' => 'operateur@acme.test']);

        $this->appeler('POST', '/api/paiements', $operateur, [
            'operationId' => $pesee->getId(),
            'modeReglement' => 'MOBILE_MONEY',
            'montantProduit' => 200_000,
            'montantTransport' => 0,
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('en cours', $this->reponse()['detail'] ?? '');
    }

    /*
        - Le webhook est PUBLIC : sans compte ni jeton, la signature est la seule chose qui distingue
          l'agrégateur de n'importe qui. Une notification non signée qui passerait permettrait à un
          inconnu de faire confirmer un versement, donc de créditer une pesée sans qu'un franc soit
          sorti — ou de déclencher un remboursement, donc de créer de l'argent dans une caisse.
        - 401 et non 200, contrairement au reste : une notification non signée n'est pas une
          notification qu'on choisit d'ignorer, et l'émetteur doit l'apprendre.
    */
    public function testUneNotificationNonSigneeEstRefusee(): void
    {
        [$paiement, , $site] = $this->engagerUnVersement(20_000);

        $this->notifier($paiement->getReference(), 'ECHOUE', signature: null);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->em->clear();

        self::assertSame(StatutPaiement::ENVOYE, $this->relire(Paiement::class, $paiement->getId())->getStatut());
        self::assertSame(self::CAISSE_INITIALE - 20_000, $this->soldeDuSite($site), 'Une notification non signée a remboursé la caisse');
    }

    public function testUneSignatureFausseEstRefusee(): void
    {
        [$paiement, , $site] = $this->engagerUnVersement(20_000);

        $this->notifier($paiement->getReference(), 'REUSSI', signature: str_repeat('a', 64));

        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->em->clear();

        self::assertSame(StatutPaiement::ENVOYE, $this->relire(Paiement::class, $paiement->getId())->getStatut());
        self::assertSame(self::CAISSE_INITIALE - 20_000, $this->soldeDuSite($site));
    }

    /*
        - Le point précis que ce contrôle doit attraper : une signature calculée sur les données
          RÉENCODÉES au lieu du corps reçu. Les deux textes disent la même chose et n'ont pourtant pas
          la même empreinte — ordre des clés, espaces, accents échappés. Vérifier sur le réencodage
          reviendrait à refuser des notifications parfaitement légitimes, une sur deux et sans motif
          lisible.
    */
    public function testLaSignaturePorteSurLeCorpsRecuEtNonSurSonReencodage(): void
    {
        [$paiement, , $site] = $this->engagerUnVersement(20_000);

        // Mêmes données, autre écriture : espaces, ordre des clés. C'est ce texte-là qui est signé.
        $corps = sprintf(
            '{ "transaction": "SIM-%s",  "statut": "REUSSI", "reference": "%s" }',
            $paiement->getReference(),
            $paiement->getReference()
        );

        $this->client->request('POST', '/api/webhooks/paiement', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $corps, self::secret()),
        ], $corps);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();

        self::assertSame(StatutPaiement::REUSSI, $this->relire(Paiement::class, $paiement->getId())->getStatut());
        self::assertSame(self::CAISSE_INITIALE - 20_000, $this->soldeDuSite($site));
    }

    /* ---- Montage ----------------------------------------------------------------------------- */

    /**
     * Engage un versement resté en vol : la passerelle simulée répond 'ENVOYE' et attend la
     * notification, ce qui est le cas nominal.
     *
     * @return array{0: Paiement, 1: Operation, 2: Site}
     */
    private function engagerUnVersement(int $montant, int $caisse = self::CAISSE_INITIALE): array
    {
        $entreprise = $this->creerEntreprise('ACME');
        $site = $this->creerSite($entreprise, 'SOF010', $caisse);
        $operateur = $this->creerUtilisateur($entreprise, Role::OPERATEUR, 'operateur@acme.test');
        $this->affecterOperateur($site, $operateur); // Sans quoi le poste n'est pas dans son périmètre

        $produit = $this->creerProduit($site, 'Hévéa', 200);
        $planteur = $this->creerPlanteur($site);
        $pesee = $this->creerPesee($site, $produit, $planteur, 1_000); // 1 000 kg × 200 = 200 000 F dus

        $paiement = self::getContainer()->get(PaiementService::class)->initier($pesee, $montant, 0, ModeReglement::MOBILE_MONEY, $operateur);

        self::assertSame(StatutPaiement::ENVOYE, $paiement->getStatut(), 'Le décor suppose un versement en vol');

        return [$paiement, $pesee, $site];
    }

    /**
     * Poste une notification signée, comme le ferait l'agrégateur.
     *
     * La signature porte sur le CORPS BRUT, pas sur les données décodées : c'est tout l'objet du
     * contrôle, et le test doit s'y prendre exactement comme l'émetteur.
     */
    private function notifier(?string $reference, string $statut, ?string $signature = self::SIGNER): void
    {
        $corps = json_encode([
            'reference' => $reference,
            'statut' => $statut,
            'transaction' => 'SIM-' . $reference,
        ], JSON_THROW_ON_ERROR);

        $entetes = ['CONTENT_TYPE' => 'application/json'];

        if($signature !== null) {
            $entetes['HTTP_X_SIGNATURE'] = $signature === self::SIGNER
                ? hash_hmac('sha256', $corps, self::secret())
                : $signature;
        }

        $this->client->request('POST', '/api/webhooks/paiement', [], [], $entetes, $corps);
    }

    /* Le secret vient de l'environnement de test, pas d'une constante recopiée : une valeur en double finit toujours par diverger de celle que le code lit. */
    private static function secret(): string
    {
        return (string) ($_ENV['PASSERELLE_SECRET'] ?? $_SERVER['PASSERELLE_SECRET'] ?? '');
    }

    /** @return list<MouvementCaisse> */
    private function ecrituresDeType(TypeMouvement $type): array
    {
        return $this->em->getRepository(MouvementCaisse::class)->findBy(['type' => $type]);
    }

    private function soldeDuSite(object $site): int
    {
        $this->em->clear();

        return $this->relire($site::class, $site->getId())->getSolde();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function relire(string $classe, ?int $id): object
    {
        $entite = $this->em->find($classe, $id);

        self::assertNotNull($entite, sprintf('%s #%d introuvable', $classe, (int) $id));

        return $entite;
    }
}
