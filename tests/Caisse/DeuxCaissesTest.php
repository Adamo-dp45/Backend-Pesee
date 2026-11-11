<?php

namespace App\Tests\Caisse;

use App\Domain\Enum\CompteMouvement;
use App\Domain\Enum\Role;
use App\Domain\Enum\StatutActivation;
use App\Domain\Enum\StatutPaiement;
use App\Domain\Enum\StatutReglement;
use App\Domain\Enum\TypeCaisse;
use App\Domain\Service\Caisse\CaisseService;
use App\Entity\MouvementCaisse;
use App\Entity\Operation;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - DEUX NATURES D'ARGENT, qui ne se convertissent pas l'une en l'autre : les billets du tiroir et
      le flotte mobile money. Remplir le flotte passe par l'agrégateur, remplir le tiroir passe par
      la banque, et aucun des deux gestes n'a lieu dans cette application.

    - Ce qui se cassait avec un solde unique : un planteur sans téléphone était déclaré impayable —
      alors que c'est précisément celui qu'on règle en liquide — et un poste au tiroir plein se
      voyait refuser un versement mobile sans comprendre pourquoi.

    - Ce que ces cas protègent, dans l'ordre : que chaque caisse tienne sa propre chaîne de grand
      livre, qu'aucun mouvement ne traverse de l'une à l'autre, et que le mode de règlement décide
      seul de la caisse qui se vide.
*/
final class DeuxCaissesTest extends InvariantTestCase
{
    private CaisseService $caisse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->caisse = self::getContainer()->get(CaisseService::class);
    }

    /*
        - Le grand livre de chaque caisse se relit comme un relevé bancaire, et les deux relevés ne
          se mêlent pas. Sans le champ 'caisse' sur la ligne, les écritures des deux caisses d'un
          même poste se suivaient avec des soldes qui ne s'enchaînaient pas : la chaîne paraissait
          rompue à chaque alternance, et plus rien ne prouvait qu'aucun franc n'avait disparu.
    */
    public function testChaqueCaisseTientSaPropreChaineDeSoldes(): void
    {
        $entreprise = $this->creerEntreprise('ACME', solde: 300_000, soldePhysique: 100_000);
        $site = $this->creerSite($entreprise, 'SOF010');

        // On alterne volontairement : c'est l'entrelacement qui faisait apparaître la chaîne comme rompue
        $this->caisse->doterSite($site, TypeCaisse::NUMERIQUE, 100_000);
        $this->caisse->doterSite($site, TypeCaisse::PHYSIQUE, 40_000);
        $this->caisse->doterSite($site, TypeCaisse::NUMERIQUE, 50_000);
        $this->caisse->doterSite($site, TypeCaisse::PHYSIQUE, 10_000);

        $this->assertChaineIntacte(CompteMouvement::SITE, TypeCaisse::NUMERIQUE, 150_000);
        $this->assertChaineIntacte(CompteMouvement::SITE, TypeCaisse::PHYSIQUE, 50_000);
        $this->assertChaineIntacte(CompteMouvement::ENTREPRISE, TypeCaisse::NUMERIQUE, 150_000);
        $this->assertChaineIntacte(CompteMouvement::ENTREPRISE, TypeCaisse::PHYSIQUE, 50_000);

        self::assertSame(150_000, $site->getSoldeNumerique());
        self::assertSame(50_000, $site->getSoldePhysique());
        self::assertSame(200_000, $site->getSolde(), 'Le cumul se lit, même s\'il ne se dépense pas');
    }

    /*
        - AUCUN MOUVEMENT NE TRAVERSE. Une entreprise qui déborde de flotte ne peut pas doter un
          tiroir : il faudrait d'abord passer à la banque, ce qui n'arrive pas ici. Le refus doit
          nommer la caisse, sinon l'administrateur regarde son solde global et ne comprend rien.
    */
    public function testUneCaissePleineNeRenfloucePasLAutre(): void
    {
        $entreprise = $this->creerEntreprise('ACME', solde: 1_000_000, soldePhysique: 0);
        $site = $this->creerSite($entreprise, 'SOF010');

        try {
            $this->caisse->doterSite($site, TypeCaisse::PHYSIQUE, 50_000);
            self::fail('Doter le tiroir avec du flotte aurait dû être refusé');
        } catch(UnprocessableEntityHttpException $e) {
            self::assertStringContainsString('insuffisant', $e->getMessage());
            self::assertStringContainsString('physique', mb_strtolower($e->getMessage()), 'Le refus doit nommer la caisse à sec');
        }

        $this->em->clear();

        self::assertSame(1_000_000, $this->rechargerEntite($entreprise)->getSoldeNumerique(), 'Le flotte a servi à payer des billets');
        self::assertCount(0, $this->ecritures(), 'Un refus n\'écrit pas au journal');
    }

    /*
        - Le cas qui justifie tout le reste : un planteur sans téléphone. Impayable par mobile money,
          réglable en espèces — et c'est le MODE qui décide de la caisse, sans que personne ait à la
          désigner.
    */
    public function testUnVersementEnEspecesVideLeTiroirEtPasLeFlotte(): void
    {
        [$site, $operateur, $pesee] = $this->decor(sansTelephone: true);

        $this->appeler('POST', '/api/paiements', $operateur, [
            'operationId' => $pesee->getId(),
            'modeReglement' => 'ESPECES',
            'montant' => 60_000,
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        $site = $this->em->find(Site::class, $site->getId());

        self::assertSame(240_000, $site?->getSoldePhysique(), 'Les billets n\'ont pas quitté le tiroir');
        self::assertSame(500_000, $site?->getSoldeNumerique(), 'Le flotte a été entamé par un versement en espèces');

        $ecriture = $this->ecritures()[0];

        self::assertSame(TypeCaisse::PHYSIQUE, $ecriture->getCaisse());
        self::assertSame(CompteMouvement::SITE, $ecriture->getCompte());
    }

    /*
        - Des billets qui changent de main sont versés, point. Pas de passerelle à interroger, rien
          qui puisse échouer une heure plus tard, donc rien à réconcilier : le versement est confirmé
          dans la foulée et la pesée tombe à « soldée » sans attendre.
    */
    public function testUnVersementEnEspecesEstConfirmeSansPasserelle(): void
    {
        [, $operateur, $pesee] = $this->decor(sansTelephone: true);

        $this->appeler('POST', '/api/paiements', $operateur, [
            'operationId' => $pesee->getId(),
            'modeReglement' => 'ESPECES',
            'montant' => 200_000, // La totalité de ce qui est dû
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $reponse = $this->reponse();

        self::assertSame(StatutPaiement::REUSSI->value, $reponse['statut'] ?? null);
        self::assertSame('ESPECES', $reponse['modeReglement'] ?? null);
        self::assertArrayHasKey('numeroDestinataire', $reponse);
        self::assertNull($reponse['numeroDestinataire'], 'Des espèces ne vont à aucun numéro');

        $this->em->clear();
        $pesee = $this->em->find(Operation::class, $pesee->getId());

        self::assertSame(200_000, $pesee?->getMontantpaye(), 'Le versement n\'a pas été compté comme confirmé');
        self::assertSame(StatutReglement::SOLDE, $pesee?->getStatutReglement());
        self::assertSame(0, $pesee?->getMontantenvol(), 'Des espèces ne restent jamais en vol');
    }

    /*
        - Le planteur sans téléphone n'est plus déclaré impayable : son motif ferme un MODE, pas le
          règlement. L'API, elle, refuse toujours de l'envoyer sur un téléphone qu'il n'a pas.
    */
    public function testUnPlanteurSansTelephoneResteReglableEnEspeces(): void
    {
        [, $operateur, $pesee] = $this->decor(sansTelephone: true);

        self::assertNull($pesee->getMotifNonPayable(), 'La pesée est réglable : il reste les espèces');
        self::assertNotNull($pesee->getFournisseur()?->getMotifNonPayable(), 'Le planteur reste impayable PAR MOBILE');

        $this->appeler('POST', '/api/paiements', $operateur, [
            'operationId' => $pesee->getId(),
            'modeReglement' => 'MOBILE_MONEY',
            'montant' => 60_000,
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('numéro', (string) ($this->reponse()['detail'] ?? ''));
    }

    /*
        - Un planteur SUSPENDU, lui, ne se paie d'aucune façon. C'est la différence entre « on ne
          peut pas le joindre » et « on ne doit pas le payer », et les confondre rouvrait en espèces
          une porte que la suspension venait de fermer.
    */
    public function testUnPlanteurSuspenduNEstPayableParAucunMode(): void
    {
        [, $operateur, $pesee] = $this->decor();

        $pesee->getFournisseur()?->setStatut(StatutActivation::SUSPENDU);
        $this->em->flush();

        self::assertStringContainsString('suspendu', (string) $pesee->getMotifNonPayable());

        $this->appeler('POST', '/api/paiements', $operateur, [
            'operationId' => $pesee->getId(),
            'modeReglement' => 'ESPECES',
            'montant' => 60_000,
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    /* ---- Outils ------------------------------------------------------------------------------ */

    /** @return array{0: Site, 1: User, 2: Operation} */
    private function decor(bool $sansTelephone = false): array
    {
        $entreprise = $this->creerEntreprise('ACME');
        $site = $this->creerSite($entreprise, 'SOF010', solde: 500_000, soldePhysique: 300_000);

        $operateur = $this->creerUtilisateur($entreprise, Role::OPERATEUR, 'operateur@acme.test');
        $this->affecterOperateur($site, $operateur);

        $produit = $this->creerProduit($site, 'Hévéa', 200);
        $planteur = $this->creerPlanteur($site, 'Kouassi', $sansTelephone ? null : '0700000001');

        return [$site, $operateur, $this->creerPesee($site, $produit, $planteur, 1_000)]; // 1 000 kg × 200 = 200 000 F dus
    }

    /**
     * Relit le journal d'une CAISSE d'un compte dans l'ordre d'écriture et vérifie qu'il se chaîne,
     * puis que la dernière ligne annonce bien le solde attendu.
     */
    private function assertChaineIntacte(CompteMouvement $compte, TypeCaisse $caisse, int $soldeAttendu): void
    {
        $ecritures = array_values(array_filter(
            $this->ecritures(),
            static fn(MouvementCaisse $m) => $m->getCompte() === $compte && $m->getCaisse() === $caisse
        ));

        self::assertNotEmpty($ecritures, sprintf('Aucune écriture pour %s sur le compte %s', $caisse->value, $compte->value));

        $precedent = null;

        foreach($ecritures as $rang => $ecriture) {
            if($precedent !== null) {
                self::assertSame($precedent, $ecriture->getSoldeAvant(), sprintf(
                    'La chaîne %s/%s est rompue au rang %d', $compte->value, $caisse->value, $rang
                ));
            }

            $precedent = $ecriture->getSoldeApres();
        }

        self::assertSame($soldeAttendu, $precedent, sprintf('La dernière ligne %s/%s ne vaut pas le solde', $compte->value, $caisse->value));
    }

    /** @return list<MouvementCaisse> */
    private function ecritures(): array
    {
        return $this->em->getRepository(MouvementCaisse::class)->findBy([], ['id' => 'ASC']);
    }

    private function rechargerEntite(object $entite): object
    {
        return $this->em->find($entite::class, $entite->getId());
    }
}
