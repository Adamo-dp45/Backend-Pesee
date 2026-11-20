<?php

namespace App\Tests\Epargne;

use App\Domain\Enum\Role;
use App\Domain\Enum\StatutDemande;
use App\Entity\CompteEpargne;
use App\Entity\DemandePrelevement;
use App\Entity\Fournisseur;
use App\Entity\Prelevement;
use App\Entity\Produit;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - Le chemin par lequel une mise en compte s'ouvre : l'opérateur, qui a le planteur devant lui,
      dépose la demande avec le taux réclamé ; l'administrateur décide. Même forme que la demande de
      réapprovisionnement, à une différence près qui change tout — l'approbation ne déplace aucun
      argent, elle ouvre un robinet. Ce qu'elle produit ne se voit donc qu'à la pesée suivante.

    - Les cas ci-dessous surveillent surtout les portes : une seule demande en attente PAR PRODUIT,
      pas de double approbation, l'ouverture directe réservée à l'administrateur, et le taux tenu
      sous le prix du produit — du dépôt jusqu'à l'approbation, qui le revérifie.
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

    private function deposer(int $taux = self::TAUX, ?Produit $produit = null): int
    {
        $this->poster($taux, $produit);

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (int) ($this->reponse()['id'] ?? 0);
    }

    /* Le dépôt brut, sans attendre de succès : les cas qui éprouvent un refus en ont besoin. */
    private function poster(int $taux, ?Produit $produit = null): void
    {
        $this->appeler('POST', '/api/demande_prelevements', $this->operateur, [
            'fournisseur' => '/api/fournisseurs/' . $this->planteur->getId(),
            'produit' => '/api/produits/' . ($produit ?? $this->produit)->getId(),
            'tauxDemande' => $taux,
            'motif' => 'Le planteur veut mettre de côté pour la rentrée',
        ]);
    }

    /* Le taux du prélèvement ouvert sur ce produit, ou null quand il n'y en a pas. */
    private function tauxEnCours(?Produit $produit = null): ?int
    {
        $planteur = $this->em->find(Fournisseur::class, $this->planteur->getId());
        $produit = $this->em->find(Produit::class, ($produit ?? $this->produit)->getId());

        return $planteur?->prelevementPour($produit)?->getTaux();
    }

    /*
        - Deux demandes approuvées coup sur coup laisseraient deux taux, et personne ne saurait
          lequel s'applique aux pesées suivantes.
    */
    public function testUnSecondDepotEstRefuseTantQuUneDemandeAttend(): void
    {
        $this->deposer();

        $this->poster(50);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('déjà en attente', (string) ($this->reponse()['detail'] ?? ''));

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(DemandePrelevement::class)->findAll());
    }

    /*
        - Et la contrepartie, qui est tout l'objet du changement : une demande sur le cacao ne gêne
          pas une demande sur l'hévéa. Ce sont deux décisions sans rapport, prises sur deux prix
          différents, et les lier ferait attendre le planteur sans raison.
    */
    public function testUneDemandeSurUnAutreProduitPasseEnMemeTemps(): void
    {
        $cacao = $this->creerProduit($this->site, 'Cacao', 300);

        $this->deposer();
        $this->deposer(taux: 40, produit: $cacao);

        $this->em->clear();
        self::assertCount(2, $this->em->getRepository(DemandePrelevement::class)->findAll());
    }

    /*
        - LA règle du patron : un taux qui atteint le prix ne laisserait rien au planteur. Refusé à
          l'ÉGALITÉ, pas seulement au-dessus — à 200 F sur un produit à 200 F, le planteur repart
          avec exactement zéro.

        - Le cas n'affirme plus le libellé « inférieur au prix du produit » : le prix qui borne est
          celui qui est RÉELLEMENT PAYÉ à ce planteur, tarif propre ou tarif de sa catégorie
          compris, et le message le dit maintenant en nommant ce prix-là. Ce qu'on vérifie ici est
          la règle, pas sa formulation — mais il faut bien en citer un morceau, alors on cite la
          partie qui ne dépend d'aucun des trois sauts.
    */
    public function testUnTauxQuiAtteintLePrixDuProduitEstRefuse(): void
    {
        $this->poster(self::PRIX);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('ne laisserait rien au planteur', (string) ($this->reponse()['detail'] ?? ''));

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(DemandePrelevement::class)->findAll());
    }

    /*
        - Un produit non tarifé n'accepte aucun prélèvement : ses pesées ne valent rien, et le robinet
          ouvert dessus ne mettrait jamais un franc de côté. Le message renvoie là où il y a quelque
          chose à corriger.
    */
    public function testUnProduitNonTarifeNAccepteAucunPrelevement(): void
    {
        $this->poster(10, $this->creerProduit($this->site, 'Cacao', 0));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('tarifez-le', (string) ($this->reponse()['detail'] ?? ''));
    }

    /*
        - LE cas que la revérification à l'approbation existe pour attraper : la demande est déposée
          quand le produit vaut 200 F, et le prix tombe à 15 F avant que l'administrateur ne tranche.
          Approuver ouvrirait un prélèvement de 20 F par kilo sur un produit qui en vaut 15.
    */
    public function testUneBaisseDePrixEntreLeDepotEtLApprobationBloqueLOuverture(): void
    {
        $id = $this->deposer();

        $this->appeler('PATCH', '/api/produits/' . $this->produit->getId(), $this->admin, ['prix' => 15]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->appeler('PATCH', '/api/demande_prelevements/' . $id . '/approbation', $this->admin, []);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('ne laisserait rien au planteur', (string) ($this->reponse()['detail'] ?? ''));

        $this->em->clear();
        self::assertNull($this->tauxEnCours(), 'Aucun prélèvement ouvert');
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

        self::assertSame(self::TAUX, $this->tauxEnCours());

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
        self::assertNull($this->tauxEnCours());
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

        $corps = ['produit' => $this->produit->getId(), 'taux' => 25];

        $this->appeler('PATCH', $uri, $this->operateur, $corps);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->appeler('PATCH', $uri, $this->admin, $corps);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        self::assertSame(25, $this->tauxEnCours());
    }

    /*
        - Changer un taux n'écrase pas la ligne : il en ouvre une nouvelle et DATE la précédente.
          Sans cela, la fiche prétendrait qu'il a toujours épargné à 60 F alors que les pesées déjà
          arbitrées portent 25 F figés, et plus rien n'expliquerait l'écart devant un planteur qui
          compte.
    */
    public function testChangerLeTauxArreteLAncienPrelevementEtEnOuvreUnAutre(): void
    {
        $uri = '/api/fournisseurs/' . $this->planteur->getId() . '/epargne';

        $this->appeler('PATCH', $uri, $this->admin, ['produit' => $this->produit->getId(), 'taux' => 25]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->appeler('PATCH', $uri, $this->admin, ['produit' => $this->produit->getId(), 'taux' => 60]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->em->clear();

        self::assertSame(60, $this->tauxEnCours(), 'Le nouveau taux prélève');
        self::assertCount(2, $this->em->getRepository(Prelevement::class)->findAll(), 'Le précédent reste, daté');
    }

    /*
        - L'arrêt ferme le robinet et RIEN D'AUTRE. Ce qui a déjà été mis de côté appartient au
          planteur : le lui reprendre parce qu'on cesse de prélever serait une confiscation.
    */
    public function testLArretNeTouchePasALEpargneDejaConstituee(): void
    {
        $this->appeler('PATCH', '/api/fournisseurs/' . $this->planteur->getId() . '/epargne', $this->admin, ['produit' => $this->produit->getId(), 'taux' => self::TAUX]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $planteur = $this->em->find(Fournisseur::class, $this->planteur->getId());

        $this->creerPeseeArbitree(
            $this->em->find(Site::class, $this->site->getId()),
            $this->em->find(Produit::class, $this->produit->getId()),
            $planteur
        );

        // Puis l'arrêt
        $this->appeler('PATCH', '/api/fournisseurs/' . $this->planteur->getId() . '/epargne', $this->admin, ['produit' => $this->produit->getId(), 'taux' => null]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();

        self::assertNull($this->tauxEnCours());
        self::assertSame(
            10_000,
            $this->em->getRepository(CompteEpargne::class)->findOneBy([])?->getSolde(),
            'L\'épargne déjà constituée reste au planteur'
        );
    }
}
