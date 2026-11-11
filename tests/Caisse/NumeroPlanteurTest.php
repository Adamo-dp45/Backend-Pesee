<?php

namespace App\Tests\Caisse;

use App\Domain\Enum\Role;
use App\Domain\Enum\StatutPaiement;
use App\Domain\Support\NumeroMobile;
use App\Entity\Fournisseur;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/*
    - Un numéro de téléphone entre dans l'application par trois portes : le formulaire web, la
      synchronisation du poste de pesée, et le numéro de circonstance saisi au moment d'un versement.
      Les agrégateurs mobile money, eux, n'en acceptent qu'une seule écriture.

    - Ce qui se casse quand on ne normalise pas : rien, longtemps. Les pesées s'accumulent, les
      planteurs paraissent payables, et les refus tombent un par un le jour du versement — avec le
      planteur devant le poste. Chacun coûte une réservation de fonds, un aller-retour réseau et un
      remboursement.

    - La règle vit à UN seul endroit, 'NumeroMobile'. La validation et la normalisation la tirent
      toutes deux de là : un numéro accepté par l'une est forcément écrivable par l'autre.
*/
final class NumeroPlanteurTest extends InvariantTestCase
{
    private const CAISSE_INITIALE = 100_000;

    /*
        - Les formes que les gens tapent réellement, telles qu'ils lisent leur numéro. Aucune n'est
          une faute : ce sont toutes le même abonné.
    */
    public function testLesEcrituresCourantesDonnentTouteLeMemeNumero(): void
    {
        $attendu = '+2250701020304';

        foreach([
            '0701020304',
            '07 01 02 03 04',
            '07-01-02-03-04',
            '07.01.02.03.04',
            '+225 07 01 02 03 04',
            '+2250701020304',
            '002250701020304',
            '225 0701020304',
        ] as $saisie) {
            self::assertSame($attendu, NumeroMobile::normaliser($saisie), sprintf('« %s » n\'a pas donné le numéro attendu', $saisie));
        }
    }

    /*
        - Un numéro trop court ou trop long n'est PAS corrigé au jugé. Deviner le chiffre manquant
          enverrait l'argent chez quelqu'un d'autre.
    */
    public function testUnNumeroIncompletEstRefuseEtNonDevine(): void
    {
        foreach(['070102030', '07010203045', 'zéro sept', '', '+33612345678'] as $saisie) {
            self::assertNull(NumeroMobile::normaliser($saisie), sprintf('« %s » aurait dû être refusé', $saisie));
        }
    }

    /* Un champ vide n'est pas une faute de saisie : c'est une absence, et elle se dit ailleurs. */
    public function testUnChampVideNEstPasUneFauteDeSaisie(): void
    {
        self::assertTrue(NumeroMobile::estValide(null));
        self::assertTrue(NumeroMobile::estValide('   '));
    }

    /*
        - Le formulaire web est le seul endroit où un numéro peut être corrigé : c'est donc là que la
          faute doit être refusée, à la personne qui a la fiche sous les yeux.
    */
    public function testLApiRefuseUnNumeroIllisibleSurUnPlanteur(): void
    {
        [$site, $admin] = $this->decor();

        $this->appeler('POST', '/api/fournisseurs', $admin, [
            'nom' => 'Kouadio',
            'site' => '/api/sites/' . $site->getId(),
            'contact1' => '070102',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('07 01 02 03 04', $this->reponse()['detail'] ?? '', 'Le message doit montrer un exemple, pas seulement dire « invalide »');
    }

    /*
        - Une écriture correcte mais tapée à la main est ACCEPTÉE, puis rangée. Refuser
          « 07 01 02 03 04 » serait refuser un numéro valable pour la seule raison qu'il est lisible.
    */
    public function testLApiRangeUnNumeroCorrectementTape(): void
    {
        [$site, $admin] = $this->decor();

        $this->appeler('POST', '/api/fournisseurs', $admin, [
            'nom' => 'Kouadio',
            'site' => '/api/sites/' . $site->getId(),
            'contact1' => '07 01 02 03 04',
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertSame('+2250701020304', $this->reponse()['contact1'] ?? null);
    }

    /*
        - Le numéro de circonstance ne passe par aucun formulaire ni par aucun setter d'entité : il
          arrive du corps de la requête et va droit à l'agrégateur. C'est le dernier point où il peut
          encore être arrêté, et il doit l'être AVANT que les fonds ne soient réservés — sinon le
          refus coûte trois écritures de caisse pour une faute de frappe.
    */
    public function testUnNumeroDeCirconstanceIllisibleNeSortPasLesFondsDeLaCaisse(): void
    {
        [$site, , $operateur, $pesee] = $this->decorAvecPesee();

        $this->appeler('POST', '/api/paiements', $operateur, [
            'operationId' => $pesee->getId(),
            'modeReglement' => 'MOBILE_MONEY',
            'montant' => 20_000,
            'numeroDestinataire' => '070102',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $this->em->clear();

        self::assertSame(self::CAISSE_INITIALE, $this->em->find(Site::class, $site->getId())?->getSolde(), 'Les fonds ont été réservés pour un numéro que l\'agrégateur aurait refusé');
    }

    public function testUnNumeroDeCirconstanceLisibleEstNormaliseAvantLEnvoi(): void
    {
        [, , $operateur, $pesee] = $this->decorAvecPesee();

        $this->appeler('POST', '/api/paiements', $operateur, [
            'operationId' => $pesee->getId(),
            'modeReglement' => 'MOBILE_MONEY',
            'montant' => 20_000,
            'numeroDestinataire' => '07 09 08 07 06',
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertSame('+2250709080706', $this->reponse()['numeroDestinataire'] ?? null);
        self::assertSame(StatutPaiement::ENVOYE->value, $this->reponse()['statut'] ?? null);
    }

    /*
        - Les fiches déjà en base gardent la forme dans laquelle elles ont été saisies : les setters
          ne valent que pour les écritures à venir. Sans reprise, les planteurs d'avant restent
          impayables — et c'est justement eux qui ont des pesées en attente.
    */
    public function testLaCommandeDeRepriseReecritLExistantSansRienDeviner(): void
    {
        [$site] = $this->decor();

        // Écriture directe : passer par le setter reviendrait à normaliser avant même la reprise
        $this->em->getConnection()->executeStatement(
            'INSERT INTO fournisseur (site_id, nom, cle, contact1, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW()), (?, ?, ?, ?, NOW(), NOW())',
            [$site->getId(), 'Lisible', 'lisible', '07 01 02 03 04', $site->getId(), 'Illisible', 'illisible', '070102']
        );

        $tester = new CommandTester((new Application(self::$kernel))->find('app:fournisseurs:normaliser-numeros'));
        $tester->execute([]);

        $this->em->clear();

        $lisible = $this->em->getRepository(Fournisseur::class)->findOneBy(['nom' => 'Lisible']);
        $illisible = $this->em->getRepository(Fournisseur::class)->findOneBy(['nom' => 'Illisible']);

        self::assertSame('+2250701020304', $lisible?->getContact1());
        self::assertSame('070102', $illisible?->getContact1(), 'Un numéro illisible doit rester tel quel : le corriger au jugé enverrait l\'argent ailleurs');
        self::assertStringContainsString('Illisible', $tester->getDisplay(), 'Ce qui n\'a pas pu être réécrit doit être nommé, sinon personne ne le corrigera');
    }

    /*
        - Le poste de pesée n'a pas de formulaire, pas de validation, et pas de second essai : si son
          envoi est refusé, c'est la PESÉE qui est perdue, pas seulement le numéro. Un numéro mal
          tapé au poste entre donc quand même — la fiche le montre, et 'getMotifNonPayable()' dit
          pourquoi elle ne vaut rien.
    */
    public function testUnNumeroIllisiblePasseParLaSynchronisationMaisRendLaFicheNonPayable(): void
    {
        [$site] = $this->decor();

        $planteur = (new Fournisseur())->setNom('Kouadio')->setSite($site)->setContact1('070102');

        $this->em->persist($planteur);
        $this->em->flush();

        self::assertSame('070102', $planteur->getContact1(), 'La saisie du poste est conservée telle quelle');
        self::assertFalse($planteur->estPayable());
        self::assertStringContainsString('070102', (string) $planteur->getMotifNonPayable());
    }

    /* ---- Montage ----------------------------------------------------------------------------- */

    /** @return array{0: Site, 1: User} */
    private function decor(): array
    {
        $entreprise = $this->creerEntreprise('ACME');
        $site = $this->creerSite($entreprise, 'SOF010', self::CAISSE_INITIALE);
        $admin = $this->creerUtilisateur($entreprise, Role::ADMIN, 'admin@acme.test');

        return [$site, $admin];
    }

    /** @return array{0: Site, 1: User, 2: User, 3: \App\Entity\Operation} */
    private function decorAvecPesee(): array
    {
        [$site, $admin] = $this->decor();

        $operateur = $this->creerUtilisateur($site->getEntreprise(), Role::OPERATEUR, 'operateur@acme.test');
        $this->affecterOperateur($site, $operateur);

        $produit = $this->creerProduit($site, 'Hévéa', 200);
        $planteur = $this->creerPlanteur($site);
        $pesee = $this->creerPesee($site, $produit, $planteur, 1_000);

        return [$site, $admin, $operateur, $pesee];
    }
}
