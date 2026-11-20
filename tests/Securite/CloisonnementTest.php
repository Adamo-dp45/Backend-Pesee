<?php

namespace App\Tests\Securite;

use App\Domain\Enum\Role;
use App\Domain\Enum\TypeCaisse;
use App\Domain\Service\Caisse\CaisseService;
use App\Entity\Entreprise;
use App\Entity\Operation;
use App\Entity\Produit;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - Le cloisonnement est la promesse la plus lourde de l'application : plusieurs clients partagent
      la même base, et aucun ne doit voir l'autre. Il ne repose pas sur des 'where' écrits à la main
      dans chaque requête mais sur 'PerimetreExtension', branchée une fois pour toutes.

    - C'est justement ce qui le rend fragile à surveiller : une fuite ne provoque aucune erreur. Elle
      ajoute des lignes à une liste, et il faut connaître les données pour s'en apercevoir. D'où des
      cas qui montent DEUX entreprises complètes et vérifient qu'aucune ne déborde sur l'autre.

    - Les appels passent par HTTP : l'extension Doctrine ne s'applique qu'aux requêtes d'API
      Platform, l'interroger depuis un repository ne prouverait rien.
*/
final class CloisonnementTest extends InvariantTestCase
{
    private Entreprise $acme;
    private Entreprise $rivale;
    private Site $siteAcme;
    private Site $secondSiteAcme;
    private Site $siteRivale;
    private User $adminAcme;
    private User $operateurAcme;

    protected function setUp(): void
    {
        parent::setUp();

        // Client A : deux ponts bascules, dont un seul tenu par notre opérateur
        $this->acme = $this->creerEntreprise('ACME', 500_000);
        $this->siteAcme = $this->creerSite($this->acme, 'ACM001');
        $this->secondSiteAcme = $this->creerSite($this->acme, 'ACM002');
        $this->adminAcme = $this->creerUtilisateur($this->acme, Role::ADMIN, 'admin@acme.test');
        $this->operateurAcme = $this->creerUtilisateur($this->acme, Role::OPERATEUR, 'operateur@acme.test');
        $this->affecterOperateur($this->siteAcme, $this->operateurAcme);

        // Client B : un concurrent, dont rien ne doit filtrer
        $this->rivale = $this->creerEntreprise('RIVALE', 900_000);
        $this->siteRivale = $this->creerSite($this->rivale, 'RIV001');
        $this->creerUtilisateur($this->rivale, Role::ADMIN, 'admin@rivale.test');

        /*
            - UN PRODUIT PAR ENTREPRISE, un planteur et une pesée PAR POSTE : c'est le clivage que
              le modèle porte désormais. Les deux postes d'ACME partagent donc le même hévéa — en
              créer un par poste serait aujourd'hui un doublon, que l'API refuserait.
        */
        $heveaAcme = $this->creerProduit($this->siteAcme, 'Hévéa');
        $heveaRivale = $this->creerProduit($this->siteRivale, 'Hévéa');

        foreach([$this->siteAcme, $this->secondSiteAcme, $this->siteRivale] as $index => $site) {
            $planteur = $this->creerPlanteur($site, 'Planteur ' . $site->getCode());
            $produit = $site === $this->siteRivale ? $heveaRivale : $heveaAcme;
            $this->creerPesee($site, $produit, $planteur, 500, (string) ($index + 1));
        }
    }

    public function testUnAdministrateurNeVoitQueLesPostesDeSonEntreprise(): void
    {
        $this->appeler('GET', '/api/sites', $this->adminAcme);

        $codes = array_column($this->membres(), 'code');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertEqualsCanonicalizing(['ACM001', 'ACM002'], $codes);
        self::assertNotContains('RIV001', $codes);
    }

    public function testUnAdministrateurNeVoitQueLesPeseesDeSonEntreprise(): void
    {
        $this->appeler('GET', '/api/operations', $this->adminAcme);

        $sites = array_column(array_column($this->membres(), 'site'), 'code');

        self::assertCount(2, $this->membres());
        self::assertEqualsCanonicalizing(['ACM001', 'ACM002'], $sites);
    }

    /*
        - Deviner un identifiant ne doit pas suffire. La réponse est 404 et non 403 : dire « interdit »
          révélerait que la ressource existe, et donc combien de clients compte la plateforme.
    */
    public function testUnIdentifiantDevineNOuvrePasLaPorteDUnAutreClient(): void
    {
        $this->appeler('GET', '/api/sites/' . $this->siteRivale->getId(), $this->adminAcme);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $peseeRivale = $this->em->getRepository(Operation::class)->findOneBy(['site' => $this->siteRivale]);
        $this->appeler('GET', '/api/operations/' . $peseeRivale->getId(), $this->adminAcme);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $produitRival = $this->em->getRepository(Produit::class)->findOneBy(['entreprise' => $this->rivale]);
        $this->appeler('GET', '/api/produits/' . $produitRival->getId(), $this->adminAcme);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /*
        - Le cloisonnement a un second niveau, à l'intérieur d'une même entreprise : un opérateur ne
          tient pas toutes les caisses, et ne doit pas voir celles de ses collègues.
    */
    public function testUnOperateurNeVoitQueLesPostesDontIlTientLaCaisse(): void
    {
        $this->appeler('GET', '/api/sites', $this->operateurAcme);

        self::assertSame(['ACM001'], array_column($this->membres(), 'code'));

        $this->appeler('GET', '/api/sites/' . $this->secondSiteAcme->getId(), $this->operateurAcme);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'Le poste d\'un collègue reste hors de portée');
    }

    public function testUnOperateurNeVoitQueLesPeseesDeSesPostes(): void
    {
        $this->appeler('GET', '/api/operations', $this->operateurAcme);

        $sites = array_column(array_column($this->membres(), 'site'), 'code');

        self::assertSame(['ACM001'], $sites);
    }

    /*
        - Les mouvements de la caisse de l'ENTREPRISE n'ont pas de site. Sans traitement explicite, la
          clause « site parmi les miens OU site nul » les ferait remonter à tout le monde : la
          trésorerie globale ne regarde pas un opérateur.
    */
    public function testUnOperateurNeVoitPasLaTresorerieDeLEntreprise(): void
    {
        $caisse = self::getContainer()->get(CaisseService::class);
        $caisse->rechargerEntreprise($this->acme, TypeCaisse::NUMERIQUE, 100_000);   // Sans site
        $caisse->doterSite($this->siteAcme, TypeCaisse::NUMERIQUE, 40_000);          // Une ligne sans site, une avec

        $this->appeler('GET', '/api/mouvement_caisses', $this->operateurAcme);

        $comptes = array_column($this->membres(), 'compte');

        self::assertNotEmpty($comptes);
        self::assertSame(['SITE'], array_values(array_unique($comptes)));

        // L'administrateur, lui, voit les deux niveaux
        $this->appeler('GET', '/api/mouvement_caisses', $this->adminAcme);
        self::assertEqualsCanonicalizing(['ENTREPRISE', 'SITE'], array_unique(array_column($this->membres(), 'compte')));
    }

    /*
        - Le super administrateur provisionne les clients, il n'exploite pas leurs données. Sa ligne
          de la table de droits ne porte que 'Entreprise', 'User' et 'Site'.
    */
    public function testLeSuperAdministrateurNAccedePasAuxDonneesDExploitation(): void
    {
        $patron = $this->creerUtilisateur(null, Role::SUPER_ADMIN, 'patron@webpesee.test');

        foreach(['/api/operations', '/api/mouvement_caisses', '/api/produits', '/api/paiements'] as $uri) {
            $this->appeler('GET', $uri, $patron);

            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $uri . ' devrait lui être fermé');
        }

        // En revanche il voit tous les clients : c'est son métier
        $this->appeler('GET', '/api/entreprises', $patron);
        self::assertEqualsCanonicalizing(['ACME', 'RIVALE'], array_column($this->membres(), 'codeentreprise'));
    }

    /*
        - Il met les postes en service, donc c'est lui qu'on appelle quand l'un d'eux déraille : il
          doit pouvoir le couper sans attendre que le client s'en aperçoive, et sur n'importe quel
          client — le blocage traverse le cloisonnement, contrairement à tout le reste.
        - Couper un poste n'est pas toucher à la caisse qu'il tient : il n'a toujours pas 'ATTRIBUER'.
    */
    public function testLeSuperAdministrateurBloqueNImporteQuelPoste(): void
    {
        $patron = $this->creerUtilisateur(null, Role::SUPER_ADMIN, 'patron@webpesee.test');

        $this->appeler('PATCH', '/api/sites/' . $this->siteRivale->getId() . '/blocage', $patron, []);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('BLOQUE', $this->reponse()['statut'] ?? null);

        // Doter la caisse de ce poste lui reste fermé
        $this->appeler('PATCH', '/api/sites/' . $this->siteRivale->getId() . '/dotation', $patron, ['montant' => 1_000]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testUnOperateurNeBloqueAucunPoste(): void
    {
        // Le sien : il le voit, mais bloquer ne lui appartient pas
        $this->appeler('PATCH', '/api/sites/' . $this->siteAcme->getId() . '/blocage', $this->operateurAcme, []);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /*
        - L'inscription d'un client n'est plus en libre-service : elle exige le droit de créer une
          entreprise, que seul le super administrateur porte. Un administrateur de client, lui, ne
          doit pas pouvoir s'en fabriquer un second.
    */
    public function testSeulLeSuperAdministrateurInscritUnClient(): void
    {
        $inscription = [
            'nomEntreprise' => 'Nouvelle', 'codeentreprise' => 'NEW01', 'contact1' => '0700000009',
            'nom' => 'Diallo', 'prenom' => 'Awa', 'email' => 'awa@nouvelle.test', 'motDePasse' => 'MotDePasse1234',
        ];

        $this->appeler('POST', '/api/register', $this->adminAcme, $inscription);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // Sans jeton du tout : 401, et non 403 — la vraie réponse est « identifiez-vous d'abord »
        $this->appeler('POST', '/api/register', null, $inscription);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $patron = $this->creerUtilisateur(null, Role::SUPER_ADMIN, 'patron@webpesee.test');

        $this->appeler('POST', '/api/register', $patron, $inscription);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        // Le premier compte est marqué fondateur : ses pairs ne pourront ni le suspendre ni le rétrograder
        self::assertTrue($this->reponse()['fondateur'] ?? false);
    }

    /*
        - Le compte machine d'un poste appartient bien à l'entreprise : sans 'UserExtension', il
          apparaîtrait au milieu du personnel et sa fiche s'ouvrirait.
    */
    public function testLesComptesMachineNApparaissentPasParmiLesUtilisateurs(): void
    {
        $compteMachine = $this->creerCompteMachine($this->siteAcme);

        $this->appeler('GET', '/api/users', $this->adminAcme);

        self::assertNotContains($compteMachine->getEmail(), array_column($this->membres(), 'email'));

        $this->appeler('GET', '/api/users/' . $compteMachine->getId(), $this->adminAcme);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'Une URL devinée ne doit pas l\'afficher non plus');
    }

    /*
        - LE PRODUIT N'EST PLUS LOCAL, LE PLANTEUR L'EST RESTÉ. C'est tout le changement, et il se
          lit sur un seul opérateur : il ne tient qu'un poste, voit TOUS les produits de son
          entreprise — y compris celui que seul l'autre poste pèse — et toujours ses SEULS
          planteurs. Sans ce cas, rien n'empêcherait de recloisonner le produit par mégarde.
    */
    public function testUnOperateurVoitLesProduitsDeSonEntrepriseMaisSesSeulsPlanteurs(): void
    {
        $this->creerProduit($this->secondSiteAcme, 'Palme'); // Sur l'entreprise, par l'autre poste

        $this->appeler('GET', '/api/produits', $this->operateurAcme);
        self::assertEqualsCanonicalizing(['Hévéa', 'Palme'], array_column($this->membres(), 'libelle'));

        $this->appeler('GET', '/api/fournisseurs', $this->operateurAcme);
        self::assertSame(['Planteur ACM001'], array_column($this->membres(), 'nom'));
    }

    /*
        - L'ENTREPRISE D'UN PRODUIT NE VIENT PAS DE LA REQUÊTE, mais du compte qui l'envoie.

        - Le cas envoie l'entreprise rivale EXPRÈS, et n'attend pas un refus : le champ a quitté
          les groupes d'écriture, il n'est donc plus lu du tout. Ce qu'on vérifie, c'est que la
          ligne atterrit chez son auteur quoi qu'on demande.

        - La même charge utile répondait 400 avant : l'IRI d'un autre client ne résolvait pas, le
          périmètre s'appliquant aussi à la lecture d'item. Rien n'était donc mal placé — mais le
          cloisonnement tenait par effet de bord, et l'auteur lisait « Item not found » là où il
          n'avait, en réalité, rien à saisir.
    */
    public function testLEntrepriseDUnProduitVientDuCompteEtNonDeLaRequete(): void
    {
        $this->appeler('POST', '/api/produits', $this->adminAcme, [
            'libelle' => 'Karité',
            'prix' => 600,
            'entreprise' => '/api/entreprises/' . $this->rivale->getId(),
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $produit = $this->em->getRepository(Produit::class)->findOneBy(['libelle' => 'Karité']);

        self::assertNotNull($produit);
        self::assertSame(
            $this->acme->getId(),
            $produit->getEntreprise()?->getId(),
            'Le produit doit rester chez son auteur, pas chez l\'entreprise nommée dans la charge utile'
        );
    }

    /*
        - La corbeille est portée par la même extension. Un produit supprimé doit disparaître des
          listes sans emporter les pesées qui le référencent.
    */
    public function testUnProduitMisALaCorbeilleSortDesListes(): void
    {
        $produit = $this->em->getRepository(Produit::class)->findOneBy(['entreprise' => $this->acme]);

        $this->appeler('GET', '/api/produits', $this->adminAcme);
        self::assertCount(1, $this->membres()); // UN pour toute l'entreprise, ses deux postes compris

        $this->appeler('PATCH', '/api/produits/' . $produit->getId() . '/suppression', $this->adminAcme, []);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->appeler('GET', '/api/produits', $this->adminAcme);
        self::assertCount(0, $this->membres());

        $this->appeler('GET', '/api/produits/' . $produit->getId(), $this->adminAcme);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        // La pesée, elle, garde son produit et son montant : c'est tout l'intérêt d'une suppression logique
        $this->appeler('GET', '/api/operations', $this->adminAcme);
        self::assertCount(2, $this->membres());
    }
}
