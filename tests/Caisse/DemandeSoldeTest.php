<?php

namespace App\Tests\Caisse;

use App\Domain\Enum\Role;
use App\Domain\Enum\StatutDemande;
use App\Domain\Enum\TypeMouvement;
use App\Entity\DemandeSolde;
use App\Entity\Entreprise;
use App\Entity\MouvementCaisse;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - « Une seule demande en attente par pont bascule ». La règle a l'air administrative ; elle est
      financière. Sans elle, un opérateur à court d'argent en dépose trois, un administrateur les
      approuve toutes en enchaînant les écrans, et le poste reçoit le triple de ce qu'il fallait —
      chaque approbation étant prise isolément, aucune ne paraît anormale.

    - Rien ne bloque plus une fois la demande sortie de l'attente : approuvée, rejetée ou retirée par
      son auteur, le poste redevient libre d'en déposer une nouvelle.
*/
final class DemandeSoldeTest extends InvariantTestCase
{
    private Entreprise $entreprise;
    private Site $site;
    private User $operateur;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = $this->creerEntreprise('ACME', 1_000_000);
        $this->site = $this->creerSite($this->entreprise, 'SOF010');
        $this->operateur = $this->creerUtilisateur($this->entreprise, Role::OPERATEUR, 'operateur@acme.test');
        $this->admin = $this->creerUtilisateur($this->entreprise, Role::ADMIN, 'admin@acme.test');

        $this->affecterOperateur($this->site, $this->operateur);
    }

    public function testUnSecondDepotEstRefuseTantQuUneDemandeAttend(): void
    {
        $this->deposer(50_000);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $this->deposer(50_000);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('déjà en attente', $this->reponse()['detail'] ?? '');

        self::assertCount(1, $this->demandes(), 'La seconde demande a tout de même été enregistrée');
    }

    public function testLAnnulationLibereLePosteEtNeToucheAAucunSolde(): void
    {
        $this->deposer(50_000);
        $demande = $this->demandes()[0];

        $this->appeler('PATCH', '/api/demande_soldes/' . $demande->getId() . '/annulation', $this->operateur, []);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertSame(StatutDemande::ANNULEE, $this->demandes()[0]->getStatut());

        // Une annulation ne déplace rien : c'est un retrait, pas une décision
        self::assertCount(0, $this->em->getRepository(MouvementCaisse::class)->findAll());
        self::assertSame(0, $this->soldeDuSite());

        $this->deposer(30_000);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), 'Le poste devrait pouvoir redemander');
    }

    public function testLeRejetLibereLePoste(): void
    {
        $this->deposer(50_000);
        $demande = $this->demandes()[0];

        $this->appeler('PATCH', '/api/demande_soldes/' . $demande->getId() . '/rejet', $this->admin, [
            'motifRejet' => 'La caisse a été dotée directement ce matin.',
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertSame(StatutDemande::REJETEE, $this->demandes()[0]->getStatut());
        self::assertSame(0, $this->soldeDuSite());

        $this->deposer(30_000);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
    }

    /*
        - L'approbation est le seul chemin par lequel une demande touche à l'argent, et elle passe par
          le service de caisse : deux écritures chaînées, pas une.
    */
    public function testLApprobationDoteLaCaisseEtLibereLePoste(): void
    {
        $this->deposer(50_000);
        $demande = $this->demandes()[0];

        $this->appeler('PATCH', '/api/demande_soldes/' . $demande->getId() . '/approbation', $this->admin, []);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $demande = $this->demandes()[0];

        self::assertSame(StatutDemande::APPROUVEE, $demande->getStatut());
        // Montant accordé absent du corps : c'est le montant demandé qui s'applique
        self::assertSame(50_000, $demande->getMontantAccorde());

        self::assertSame(50_000, $this->soldeDuSite());
        self::assertSame(950_000, $this->soldeDeLEntreprise());

        $ecritures = $this->em->getRepository(MouvementCaisse::class)->findBy([], ['id' => 'ASC']);
        self::assertCount(2, $ecritures);
        self::assertSame(TypeMouvement::ATTRIBUTION, $ecritures[0]->getType());
        self::assertSame(TypeMouvement::DOTATION, $ecritures[1]->getType());

        // Les deux écritures sont rattachées à la demande : c'est ce qui permet de remonter d'un mouvement à sa justification
        self::assertSame($demande->getId(), $ecritures[0]->getDemandeSolde()?->getId());

        $this->deposer(30_000);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
    }

    public function testUneDemandeDejaTraiteeNeSApprouvePasUneSecondeFois(): void
    {
        $this->deposer(50_000);
        $demande = $this->demandes()[0];

        $this->appeler('PATCH', '/api/demande_soldes/' . $demande->getId() . '/approbation', $this->admin, []);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->appeler('PATCH', '/api/demande_soldes/' . $demande->getId() . '/approbation', $this->admin, []);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('déjà été traitée', $this->reponse()['detail'] ?? '');

        // Le point qui compte : la caisse n'a pas été dotée deux fois
        $this->em->clear();
        self::assertSame(50_000, $this->soldeDuSite());
        self::assertCount(2, $this->em->getRepository(MouvementCaisse::class)->findAll());
    }

    /*
        - Approuver au-delà du solde de l'entreprise doit tout annuler : la demande ne doit surtout
          pas rester marquée « approuvée » alors qu'aucun franc n'est arrivé au poste.
    */
    public function testUneApprobationRefuseeParLaCaisseNeMarquePasLaDemande(): void
    {
        $this->entreprise->setSolde(10_000);
        $this->em->flush();

        $this->deposer(50_000);
        $demande = $this->demandes()[0];

        $this->appeler('PATCH', '/api/demande_soldes/' . $demande->getId() . '/approbation', $this->admin, []);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('insuffisant', $this->reponse()['detail'] ?? '');

        $this->em->clear();

        self::assertSame(StatutDemande::EN_ATTENTE, $this->demandes()[0]->getStatut());
        self::assertSame(0, $this->soldeDuSite());
        self::assertSame(10_000, $this->soldeDeLEntreprise());
        self::assertCount(0, $this->em->getRepository(MouvementCaisse::class)->findAll());
    }

    /* ---- Outils ------------------------------------------------------------------------------ */

    private function deposer(int $montant): void
    {
        $this->appeler('POST', '/api/demande_soldes', $this->operateur, [
            'site' => '/api/sites/' . $this->site->getId(),
            'montantDemande' => $montant,
            'motif' => 'Plus de quoi payer les planteurs.',
        ]);
    }

    /** @return list<DemandeSolde> */
    private function demandes(): array
    {
        return $this->em->getRepository(DemandeSolde::class)->findBy([], ['id' => 'ASC']);
    }

    private function soldeDuSite(): int
    {
        return $this->em->find(Site::class, $this->site->getId())->getSolde();
    }

    private function soldeDeLEntreprise(): int
    {
        return $this->em->find(Entreprise::class, $this->entreprise->getId())->getSolde();
    }
}
