<?php

namespace App\Tests\Audit;

use App\Domain\Enum\Role;
use App\Domain\Enum\TypeActivite;
use App\Entity\Activite;
use App\Entity\Entreprise;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - Le journal des décisions ne se lit qu'après coup, souvent des semaines plus tard, quand
      quelqu'un demande « qui a changé ce prix ? ». Une trace manquante ne se remarque donc jamais au
      moment où elle manque : elle se découvre le jour où elle aurait servi.

    - Ces cas fixent la LISTE des gestes tracés. Le grand livre couvre déjà l'argent — le doubler ici
      donnerait deux versions d'une même vérité. N'y figure que ce qui ne laissait rien.
*/
final class JournalActiviteTest extends InvariantTestCase
{
    private Entreprise $entreprise;
    private Site $site;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = $this->creerEntreprise('ACME', 500_000);
        $this->site = $this->creerSite($this->entreprise, 'SOF010');
        $this->admin = $this->creerUtilisateur($this->entreprise, Role::ADMIN, 'admin@acme.test');
    }

    /*
        - Le cas le plus utile du journal : un prix décide de ce que touche un planteur, et le changer
          revalorise les pesées non encore payées. Seul 'updatedBy' en restait, écrasé au geste suivant.
    */
    public function testUnChangementDePrixRetientLAncienneValeur(): void
    {
        $produit = $this->creerProduit($this->site, 'Hévéa', 400);

        $this->appeler('PATCH', '/api/produits/' . $produit->getId(), $this->admin, ['prix' => 470]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $activite = $this->derniere();

        self::assertSame(TypeActivite::PRODUIT_TARIFE, $activite->getType());
        self::assertStringContainsString('400 F', (string) $activite->getLibelle(), 'L\'ancien prix a disparu du libellé');
        self::assertStringContainsString('470 F', (string) $activite->getLibelle());
        self::assertSame('Produit', $activite->getCibleType());
        self::assertSame($produit->getId(), $activite->getCibleId());
        self::assertSame($this->admin->getId(), $activite->getAuteur()?->getId());
    }

    /** Modifier autre chose que le prix ne doit pas noyer le journal. */
    public function testUneModificationSansChangementDePrixNEcritRien(): void
    {
        $produit = $this->creerProduit($this->site, 'Hévéa', 400);

        $this->appeler('PATCH', '/api/produits/' . $produit->getId(), $this->admin, ['codeproduit' => 'HEV']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertCount(0, $this->activites());
    }

    /*
        - LE TRANSPORT SE TRACE COMME UN PRIX, et ce n'est plus un nombre mais un BARÈME. Le
          libellé doit donc nommer le barème d'avant et celui d'après — « aucun » quand il n'y
          en avait pas, jamais « 0 F », qui laisserait croire à un transport gratuit.
    */
    public function testLeBaremeDeTransportDUnPlanteurEstTraceDeLaMemeFacon(): void
    {
        $planteur = $this->creerPlanteur($this->site);
        $bareme = $this->creerBaremeAuKilo($this->entreprise, 250, 'Au kilo 250');

        $this->appeler('PATCH', '/api/fournisseurs/' . $planteur->getId(), $this->admin, [
            'baremeTransport' => '/api/bareme_transports/' . $bareme->getId(),
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $activite = $this->derniere();

        self::assertSame(TypeActivite::FOURNISSEUR_TARIFE, $activite->getType());
        // Aucun barème auparavant : le libellé doit le dire, pas afficher « 0 F »
        self::assertStringContainsString('aucun', (string) $activite->getLibelle());
        self::assertStringContainsString('Au kilo 250', (string) $activite->getLibelle());
    }

    public function testLeBlocageDUnPosteEstTraceDansLesDeuxSens(): void
    {
        $this->appeler('PATCH', '/api/sites/' . $this->site->getId() . '/blocage', $this->admin, []);
        self::assertSame(TypeActivite::SITE_BLOQUE, $this->derniere()->getType());

        $this->appeler('PATCH', '/api/sites/' . $this->site->getId() . '/blocage', $this->admin, []);
        self::assertSame(TypeActivite::SITE_DEBLOQUE, $this->derniere()->getType());

        self::assertCount(2, $this->activites());
    }

    /*
        - Renouveler les identifiants d'un poste ne laissait STRICTEMENT rien : ni auteur, ni date.
          Un poste qui cesse de synchroniser juste après devenait une énigme.
    */
    public function testLeRenouvellementDesIdentifiantsEstTrace(): void
    {
        $patron = $this->creerUtilisateur(null, Role::SUPER_ADMIN, 'patron@webpesee.test');
        $this->creerCompteMachine($this->site);

        $this->appeler('PATCH', '/api/sites/' . $this->site->getId() . '/identifiants', $patron, []);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $activite = $this->derniere();

        self::assertSame(TypeActivite::SITE_IDENTIFIANTS, $activite->getType());
        self::assertStringContainsString('SOF010', (string) $activite->getLibelle());

        /*
            - Le geste vient du super administrateur, qui n'appartient à aucune entreprise. La ligne
              doit malgré tout se ranger dans le journal du CLIENT : sinon le périmètre l'écarte et
              personne ne la lit jamais. L'entreprise vient donc de la cible, pas de l'acteur.
        */
        self::assertSame($this->entreprise->getId(), $activite->getEntreprise()?->getId());
    }

    public function testLeChangementDeRoleRetientLAncienRole(): void
    {
        $agent = $this->creerUtilisateur($this->entreprise, Role::AGENT, 'agent@acme.test');

        $this->appeler('PATCH', '/api/users/' . $agent->getId() . '/role', $this->admin, ['role' => Role::OPERATEUR->value]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $activite = $this->derniere();

        self::assertSame(TypeActivite::USER_ROLE, $activite->getType());
        self::assertStringContainsString('Agent', (string) $activite->getLibelle());
        self::assertStringContainsString('Opérateur', (string) $activite->getLibelle());
    }

    public function testLaMiseALaCorbeilleEstTracee(): void
    {
        $produit = $this->creerProduit($this->site, 'Hévéa', 400);

        $this->appeler('PATCH', '/api/produits/' . $produit->getId() . '/suppression', $this->admin, []);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertSame(TypeActivite::CORBEILLE_SUPPRESSION, $this->derniere()->getType());
    }

    /*
        - Le grand livre porte déjà qui, combien, avant et après pour chaque mouvement d'argent. Une
          ligne d'activité en plus donnerait deux versions d'un même fait, qui finiraient par diverger.
    */
    public function testLesMouvementsDArgentNeSontPasDoubles(): void
    {
        $this->appeler('PATCH', '/api/sites/' . $this->site->getId() . '/dotation', $this->admin, ['montant' => 50_000, 'caisse' => 'NUMERIQUE']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertCount(0, $this->activites(), 'La dotation est déjà au grand livre');
    }

    /** Un journal ne se réécrit pas : aucune écriture n'est exposée. */
    public function testLeJournalNAccepteAucuneEcritureParLApi(): void
    {
        $this->appeler('POST', '/api/activites', $this->admin, ['type' => 'USER_ROLE', 'libelle' => 'forgé']);

        self::assertSame(405, $this->client->getResponse()->getStatusCode());
    }

    /*
        - Le journal est cloisonné comme le reste : chacun lit celui de son entreprise. L'opérateur en
          est écarté par la table des droits — c'est un écran de supervision.
    */
    public function testLeJournalEstCloisonneEtReserveALaSupervision(): void
    {
        // Tout le décor AVANT le premier appel : une requête peut détacher les entités montées ici
        $rivale = $this->creerEntreprise('RIVALE');
        $adminRival = $this->creerUtilisateur($rivale, Role::ADMIN, 'admin@rivale.test');
        $operateur = $this->creerUtilisateur($this->entreprise, Role::OPERATEUR, 'operateur@acme.test');

        $this->appeler('PATCH', '/api/sites/' . $this->site->getId() . '/blocage', $this->admin, []);

        $this->appeler('GET', '/api/activites', $adminRival);
        self::assertCount(0, $this->membres(), 'Le journal du voisin a fuité');

        $this->appeler('GET', '/api/activites', $this->admin);
        self::assertCount(1, $this->membres());

        $this->appeler('GET', '/api/activites', $operateur);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /*
        - LES MONTANTS DANS LE JOURNAL DE LA MISE EN COMPTE. Ouvrir ou arrêter un prélèvement ne
          déplace aucun argent : le grand livre ne le voit jamais, et ces lignes sont donc la SEULE
          trace de la décision. Un libellé qui omet le taux laisse sans réponse la question pour
          laquelle on vient consulter le journal — combien lui retenait-on ?

        - Le défaut a vécu jusqu'à ce qu'un œil humain le remarque à l'écran : l'ouverture portait le
          taux, l'arrêt non. Rien ne lisait ces libellés, donc rien ne pouvait le signaler.
    */
    public function testLeJournalDeLaMiseEnComptePorteToujoursLeTaux(): void
    {
        $produit = $this->creerProduit($this->site, 'Hévéa', 400);
        $planteur = $this->creerPlanteur($this->site, 'Bamba');
        $uri = '/api/fournisseurs/' . $planteur->getId() . '/epargne';

        // 1. Ouverture directe
        $this->appeler('PATCH', $uri, $this->admin, ['produit' => $produit->getId(), 'taux' => 15]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $ouverture = (string) $this->derniere()->getLibelle();

        self::assertSame(TypeActivite::FOURNISSEUR_PRELEVEMENT, $this->derniere()->getType());
        self::assertStringContainsString('15 F par kilo', $ouverture);
        self::assertStringContainsString('Hévéa', $ouverture);
        self::assertStringContainsString('Bamba', $ouverture);

        // 2. Changement de taux : les DEUX chiffres, parce que les pesées déjà arbitrées gardent l'ancien
        $this->appeler('PATCH', $uri, $this->admin, ['produit' => $produit->getId(), 'taux' => 50]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $changement = (string) $this->derniere()->getLibelle();

        self::assertStringContainsString('15', $changement, 'Le taux quitté a disparu du libellé');
        self::assertStringContainsString('50 F par kilo', $changement);

        // 3. Arrêt : c'est ici que le taux manquait
        $this->appeler('PATCH', $uri, $this->admin, ['produit' => $produit->getId(), 'taux' => null]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $arret = $this->derniere();

        self::assertSame(TypeActivite::FOURNISSEUR_PRELEVEMENT_ARRET, $arret->getType());
        self::assertStringContainsString('50 F par kilo', (string) $arret->getLibelle(), 'Un arrêt sans montant ne dit pas ce qu\'on retenait');
        self::assertStringContainsString('Hévéa', (string) $arret->getLibelle());
        self::assertStringContainsString('Bamba', (string) $arret->getLibelle());
    }

    /*
        - L'autre porte d'ouverture, celle qui part d'une demande. Son libellé doit porter les mêmes
          informations : lu dans le journal, rien ne distingue les deux chemins si l'un d'eux tait le
          produit ou le taux.
    */
    public function testLApprobationDUneDemandeTraceLeTauxEtLeProduit(): void
    {
        $produit = $this->creerProduit($this->site, 'Cacao', 300);
        $planteur = $this->creerPlanteur($this->site, 'Kouassi');
        $operateur = $this->creerUtilisateur($this->entreprise, Role::OPERATEUR, 'op@acme.test');
        $this->affecterOperateur($this->site, $operateur);

        $this->appeler('POST', '/api/demande_prelevements', $operateur, [
            'fournisseur' => '/api/fournisseurs/' . $planteur->getId(),
            'produit' => '/api/produits/' . $produit->getId(),
            'tauxDemande' => 25,
            'motif' => 'Il veut mettre de côté',
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $id = (int) ($this->reponse()['id'] ?? 0);

        $this->appeler('PATCH', '/api/demande_prelevements/' . $id . '/approbation', $this->admin, []);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $libelle = (string) $this->derniere()->getLibelle();

        self::assertSame(TypeActivite::FOURNISSEUR_PRELEVEMENT, $this->derniere()->getType());
        self::assertStringContainsString('25 F par kilo', $libelle);
        self::assertStringContainsString('Cacao', $libelle);
        self::assertStringContainsString('Kouassi', $libelle);
    }

    /* ---- Outils ------------------------------------------------------------------------------ */

    /** @return list<Activite> */
    private function activites(): array
    {
        /*
            - Pas de 'clear()' ici : le noyau ne redemarre pas entre deux requetes, donc les lignes
              ecrites par le processor sont deja dans cet EntityManager. Le vider detacherait au
              passage l'entreprise et les comptes montes par le decor, et la suite du cas echouerait
              sur une entite orpheline plutot que sur ce qu'il verifie.
        */
        return $this->em->getRepository(Activite::class)->findBy([], ['id' => 'ASC']);
    }

    private function derniere(): Activite
    {
        $lignes = $this->activites();

        self::assertNotEmpty($lignes, 'Aucune activité journalisée');

        return $lignes[count($lignes) - 1];
    }
}
