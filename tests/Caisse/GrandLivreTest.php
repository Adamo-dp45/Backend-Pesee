<?php

namespace App\Tests\Caisse;

use App\Domain\Enum\CompteMouvement;
use App\Domain\Enum\Role;
use App\Domain\Enum\TypeMouvement;
use App\Domain\Service\Caisse\CaisseService;
use App\Entity\MouvementCaisse;
use App\Tests\InvariantTestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Le grand livre est la seule pièce qui permette de dire, après coup, qu'aucun franc n'est
      apparu ni n'a disparu. Il ne le permet qu'à une condition : que la chaîne soit intacte — pour
      un même compte, le 'soldeApres' d'une écriture doit être le 'soldeAvant' de la suivante, et la
      dernière doit valoir le solde de l'entité.

    - Une rupture de cette chaîne ne lève aucune erreur et ne s'affiche nulle part. Elle signifie
      qu'un solde a bougé hors de 'CaisseService', et on ne s'en aperçoit que le jour où un opérateur
      compte ses billets. D'où ces cas.
*/
final class GrandLivreTest extends InvariantTestCase
{
    private CaisseService $caisse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->caisse = self::getContainer()->get(CaisseService::class);
    }

    public function testLaDotationDeplaceLArgentSansLeCreer(): void
    {
        $entreprise = $this->creerEntreprise('ACME', 100_000);
        $site = $this->creerSite($entreprise, 'SOF010');

        $this->caisse->doterSite($site, 30_000);

        self::assertSame(70_000, $entreprise->getSolde());
        self::assertSame(30_000, $site->getSolde());

        // Le total du système est inchangé : l'argent s'est déplacé, il ne s'est pas créé
        self::assertSame(100_000, $entreprise->getSolde() + $site->getSolde());

        $ecritures = $this->ecritures();
        self::assertCount(2, $ecritures, 'Une dotation écrit sa sortie ET son entrée');

        self::assertSame(TypeMouvement::ATTRIBUTION, $ecritures[0]->getType());
        self::assertSame(CompteMouvement::ENTREPRISE, $ecritures[0]->getCompte());
        self::assertNull($ecritures[0]->getSite());

        self::assertSame(TypeMouvement::DOTATION, $ecritures[1]->getType());
        self::assertSame(CompteMouvement::SITE, $ecritures[1]->getCompte());
        self::assertSame($site->getId(), $ecritures[1]->getSite()?->getId());

        // Les deux lignes portent le même montant, positif : c'est le sens qui donne la direction
        self::assertSame(30_000, $ecritures[0]->getMontant());
        self::assertSame(30_000, $ecritures[1]->getMontant());
    }

    /*
        - Le cas central : on enchaîne les quatre gestes qui touchent à l'argent, puis on relit le
          journal compte par compte. Chacun doit se lire comme un relevé bancaire.
    */
    public function testChaqueEcritureRepartDuSoldeLaisseParLaPrecedente(): void
    {
        $entreprise = $this->creerEntreprise('ACME');
        $site = $this->creerSite($entreprise, 'SOF010');

        $this->caisse->rechargerEntreprise($entreprise, 500_000);
        $this->caisse->doterSite($site, 200_000);
        $this->caisse->doterSite($site, 50_000);
        $this->caisse->rechargerEntreprise($entreprise, 10_000);

        $this->assertChaineIntacte(CompteMouvement::ENTREPRISE, $entreprise->getSolde());
        $this->assertChaineIntacte(CompteMouvement::SITE, $site->getSolde());

        self::assertSame(260_000, $entreprise->getSolde()); // 500 000 + 10 000 − 250 000
        self::assertSame(250_000, $site->getSolde());
    }

    /*
        - Une dépense refusée ne doit RIEN laisser derrière elle. Une écriture orpheline — un débit
          sans son crédit — casserait la chaîne pour toujours, et aucun écran ne le signalerait.
    */
    public function testUneDotationSuperieureAuSoldeNeLaisseAucuneTrace(): void
    {
        $entreprise = $this->creerEntreprise('ACME', 10_000);
        $site = $this->creerSite($entreprise, 'SOF010');

        try {
            $this->caisse->doterSite($site, 50_000);
            self::fail('Doter au-delà du solde de l\'entreprise aurait dû être refusé');
        } catch(UnprocessableEntityHttpException $e) {
            // Le message s'adresse à qui est devant l'écran : il annonce ce qui manque
            self::assertStringContainsString('insuffisant', $e->getMessage());
        }

        $this->em->clear();

        self::assertSame(10_000, $this->rechargerEntite($entreprise)->getSolde());
        self::assertSame(0, $this->rechargerSite($site)->getSolde());
        self::assertCount(0, $this->ecritures(), 'Un refus n\'écrit pas au journal');
    }

    public function testUnMontantNulOuNegatifEstRefuse(): void
    {
        $entreprise = $this->creerEntreprise('ACME', 100_000);
        $site = $this->creerSite($entreprise, 'SOF010');

        foreach([0, -1, -5_000] as $montant) {
            try {
                $this->caisse->doterSite($site, $montant);
                self::fail(sprintf('Une dotation de %d aurait dû être refusée', $montant));
            } catch(UnprocessableEntityHttpException) {
                // Attendu : un mouvement de zéro ou négatif inverserait le sens de l'écriture
            }
        }

        self::assertCount(0, $this->ecritures());
    }

    /*
        - La caisse d'un poste ne se dote que depuis le solde de l'entreprise, jamais depuis rien.
          Sans ce contrôle, un site sans entreprise créerait de l'argent à partir de zéro.
    */
    public function testLaRechargeEstLaSeuleEntreeDArgentDansLeSysteme(): void
    {
        $entreprise = $this->creerEntreprise('ACME');
        $site = $this->creerSite($entreprise, 'SOF010');

        $this->caisse->rechargerEntreprise($entreprise, 80_000, 'Apport du gérant');
        $this->caisse->doterSite($site, 80_000);

        $entrees = array_filter($this->ecritures(), static fn(MouvementCaisse $m) => $m->getType() === TypeMouvement::RECHARGE);

        self::assertCount(1, $entrees, 'Seule une RECHARGE fait entrer de l\'argent');
        self::assertSame(80_000, $entreprise->getSolde() + $site->getSolde());
        self::assertSame(0, $entreprise->getSolde());
    }

    /*
        - Le grand livre est en lecture seule côté API : une écriture comptable ne se saisit pas, elle
          résulte d'un geste métier. Si un POST devenait possible, tout le reste ne servirait plus.
    */
    public function testLeJournalNAccepteAucuneEcritureParLApi(): void
    {
        $entreprise = $this->creerEntreprise('ACME', 50_000);
        $admin = $this->creerUtilisateur($entreprise, Role::ADMIN, 'admin@acme.test');

        $this->appeler('POST', '/api/mouvement_caisses', $admin, [
            'compte' => 'ENTREPRISE',
            'montant' => 999_999,
        ]);

        self::assertSame(405, $this->client->getResponse()->getStatusCode());
    }

    /* ---- Outils ------------------------------------------------------------------------------ */

    /**
     * Relit le journal d'un compte dans l'ordre d'écriture et vérifie qu'il se chaîne, puis que la
     * dernière ligne annonce bien le solde que porte l'entité.
     */
    private function assertChaineIntacte(CompteMouvement $compte, int $soldeAttendu): void
    {
        $ecritures = array_values(array_filter($this->ecritures(), static fn(MouvementCaisse $m) => $m->getCompte() === $compte));

        self::assertNotEmpty($ecritures, 'Aucune écriture pour le compte ' . $compte->value);

        $precedent = 0;

        foreach($ecritures as $rang => $ecriture) {
            self::assertSame($precedent, $ecriture->getSoldeAvant(), sprintf(
                'Rupture de chaîne sur %s à l\'écriture %d (%s) : elle part de %d alors que la précédente a laissé %d',
                $compte->value,
                $rang + 1,
                $ecriture->getType()?->value ?? '?',
                $ecriture->getSoldeAvant(),
                $precedent
            ));

            // Le montant est toujours positif, c'est le sens qui porte la direction
            self::assertGreaterThan(0, $ecriture->getMontant());
            self::assertSame(
                $ecriture->getSoldeAvant() + $ecriture->getSens()->signe() * $ecriture->getMontant(),
                $ecriture->getSoldeApres()
            );

            $precedent = $ecriture->getSoldeApres();
        }

        self::assertSame($soldeAttendu, $precedent, 'Le solde de l\'entité a divergé de son journal');
    }

    /** @return list<MouvementCaisse> */
    private function ecritures(): array
    {
        return $this->em->getRepository(MouvementCaisse::class)->findBy([], ['id' => 'ASC']);
    }

    private function rechargerEntite(object $entreprise): object
    {
        return $this->em->find($entreprise::class, $entreprise->getId());
    }

    private function rechargerSite(object $site): object
    {
        return $this->em->find($site::class, $site->getId());
    }
}
