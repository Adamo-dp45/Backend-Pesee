<?php

namespace App\Tests\Epargne;

use App\Domain\Enum\Role;
use App\Domain\Enum\StatutDemande;
use App\Entity\CompteEpargne;
use App\Entity\DemandePrelevement;
use App\Entity\Fournisseur;
use App\Entity\Produit;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - Le chemin par lequel une mise en compte s'ouvre : l'opérateur, qui a le planteur devant lui,
      dépose la demande avec le taux réclamé ; l'administrateur décide. Même forme que la demande de
      réapprovisionnement, à une différence près qui change tout — l'approbation ne déplace aucun
      argent, elle ouvre un robinet. Ce qu'elle produit ne se voit donc qu'à la pesée suivante.

    - Les cas ci-dessous surveillent surtout les portes : une seule demande en attente, pas de
      double approbation, et l'ouverture directe réservée à l'administrateur.
*/
final class DemandePrelevementTest extends InvariantTestCase
{
    private const PRIX = 200;  // F/kg
    private const TAUX = 20;   // F/kg

    private Site $site;
    private Produit $produit;
    private Fournisseur $planteur;
    private User $operateur;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $entreprise = $this->creerEntreprise('ACME', 2_000_000, 2_000_000);
        $this->site = $this->creerSite($entreprise, 'ACM001', 1_000_000, 1_000_000);
        $this->produit = $this->creerProduit($this->site, 'Hévéa', self::PRIX);
        $this->planteur = $this->creerPlanteur($this->site, 'Kouassi');

        $this->operateur = $this->creerUtilisateur($entreprise, Role::OPERATEUR, 'operateur@acme.test');
        $this->affecterOperateur($this->site, $this->operateur);

        $this->admin = $this->creerUtilisateur($entreprise, Role::ADMIN, 'admin@acme.test');
    }

    private function deposer(int $taux = self::TAUX): int
    {
        $this->appeler('POST', '/api/demande_prelevements', $this->operateur, [
            'fournisseur' => '/api/fournisseurs/' . $this->planteur->getId(),
            'tauxDemande' => $taux,
            'motif' => 'Le planteur veut mettre de côté pour la rentrée',
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (int) ($this->reponse()['id'] ?? 0);
    }

    /*
        - Deux demandes approuvées coup sur coup laisseraient deux taux, et personne ne saurait
          lequel s'applique aux pesées suivantes.
    */
    public function testUnSecondDepotEstRefuseTantQuUneDemandeAttend(): void
    {
        $this->deposer();

        $this->appeler('POST', '/api/demande_prelevements', $this->operateur, [
            'fournisseur' => '/api/fournisseurs/' . $this->planteur->getId(),
            'tauxDemande' => 50,
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('déjà en attente', (string) ($this->reponse()['detail'] ?? ''));

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(DemandePrelevement::class)->findAll());
    }

    /*
        - L'approbation ne déplace rien : elle ouvre. Ce qui se vérifie, c'est que la pesée SUIVANTE
          met de côté — et au taux demandé, pas à un autre.
    */
    public function testLApprobationOuvreLePrelevementAuTauxDemande(): void
    {
        $id = $this->deposer();

        $this->appeler('PATCH', '/api/demande_prelevements/' . $id . '/approbation', $this->admin, []);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame(StatutDemande::APPROUVEE->value, $this->reponse()['statut'] ?? null);

        $this->em->clear();
        $planteur = $this->em->find(Fournisseur::class, $this->planteur->getId());

        self::assertSame(self::TAUX, $planteur?->getTauxEpargne());

        // La pesée suivante met de côté : 500 kg × 20 F
        $pesee = $this->creerPeseeArbitree(
            $this->em->find(Site::class, $this->site->getId()),
            $this->em->find(Produit::class, $this->produit->getId()),
            $planteur
        );

        self::assertSame(10_000, $pesee->getRetenue());
        self::assertSame(90_000, $pesee->getMontantpayable());
    }

    /*
        - Un rejet sans motif laisse l'opérateur sans rien à dire au planteur, et il redemandera la
          même chose.
    */
    public function testUnRejetDoitEtreMotiveEtNOuvreRien(): void
    {
        $id = $this->deposer();

        $this->appeler('PATCH', '/api/demande_prelevements/' . $id . '/rejet', $this->admin, ['motifRejet' => '  ']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $this->appeler('PATCH', '/api/demande_prelevements/' . $id . '/rejet', $this->admin, [
            'motifRejet' => 'Taux trop élevé au regard de ses livraisons',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(StatutDemande::REJETEE->value, $this->reponse()['statut'] ?? null);

        $this->em->clear();
        self::assertNull($this->em->find(Fournisseur::class, $this->planteur->getId())?->getTauxEpargne());
    }

    public function testLAnnulationLibereLePlanteurPourUneNouvelleDemande(): void
    {
        $id = $this->deposer();

        $this->appeler('PATCH', '/api/demande_prelevements/' . $id . '/annulation', $this->operateur, []);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(StatutDemande::ANNULEE->value, $this->reponse()['statut'] ?? null);

        $this->em->clear();
        $this->planteur = $this->em->find(Fournisseur::class, $this->planteur->getId());

        $this->deposer(taux: 30); // Le dépôt suivant passe : c'est tout l'objet de l'annulation
    }

    public function testUneDemandeDejaTraiteeNeSApprouvePasUneSecondeFois(): void
    {
        $id = $this->deposer();

        $this->appeler('PATCH', '/api/demande_prelevements/' . $id . '/approbation', $this->admin, []);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->appeler('PATCH', '/api/demande_prelevements/' . $id . '/approbation', $this->admin, []);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('déjà été traitée', (string) ($this->reponse()['detail'] ?? ''));
    }

    /*
        - L'ouverture directe est le geste de l'administrateur, comme doter une caisse sans demande.
          L'opérateur, lui, demande — sinon la demande ne servirait à rien.
    */
    public function testSeulLAdministrateurOuvreDirectement(): void
    {
        $uri = '/api/fournisseurs/' . $this->planteur->getId() . '/epargne';

        $this->appeler('PATCH', $uri, $this->operateur, ['taux' => 25]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->appeler('PATCH', $uri, $this->admin, ['taux' => 25]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        self::assertSame(25, $this->em->find(Fournisseur::class, $this->planteur->getId())?->getTauxEpargne());
    }

    /*
        - L'arrêt ferme le robinet et RIEN D'AUTRE. Ce qui a déjà été mis de côté appartient au
          planteur : le lui reprendre parce qu'on cesse de prélever serait une confiscation.
    */
    public function testLArretNeTouchePasALEpargneDejaConstituee(): void
    {
        $this->appeler('PATCH', '/api/fournisseurs/' . $this->planteur->getId() . '/epargne', $this->admin, ['taux' => self::TAUX]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $planteur = $this->em->find(Fournisseur::class, $this->planteur->getId());

        $this->creerPeseeArbitree(
            $this->em->find(Site::class, $this->site->getId()),
            $this->em->find(Produit::class, $this->produit->getId()),
            $planteur
        );

        // Puis l'arrêt
        $this->appeler('PATCH', '/api/fournisseurs/' . $this->planteur->getId() . '/epargne', $this->admin, ['taux' => null]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();

        self::assertNull($this->em->find(Fournisseur::class, $this->planteur->getId())?->getTauxEpargne());
        self::assertSame(
            10_000,
            $this->em->getRepository(CompteEpargne::class)->findOneBy([])?->getSolde(),
            'L\'épargne déjà constituée reste au planteur'
        );
    }
}
