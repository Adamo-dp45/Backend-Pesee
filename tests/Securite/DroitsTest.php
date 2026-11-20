<?php

namespace App\Tests\Securite;

use App\Domain\Enum\Role;
use App\Entity\Entreprise;
use App\Entity\Site;
use App\Tests\InvariantTestCase;

/*
    - Le frontend n'affiche un bouton que si l'API lui a dit que le droit existe : '/me' publie la
      table de 'Droits' à plat, et le voter d'affichage se contente de la lire. Il en tenait
      auparavant une copie manuscrite, à reporter à la main — ces cas-là existent pour que la
      copie ne revienne jamais par la fenêtre.

    - Ce qui est surveillé ici, c'est l'ACCORD entre ce que '/me' annonce et ce que l'API accorde.
      Une divergence ne casse rien de visible : elle fait apparaître un bouton qui répondra 403, ou
      disparaître un geste pourtant permis. Dans les deux cas personne ne reçoit d'erreur, et c'est
      bien pour ça qu'il faut un test.
*/
final class DroitsTest extends InvariantTestCase
{
    private Entreprise $entreprise;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = $this->creerEntreprise('ACME');
        $this->site = $this->creerSite($this->entreprise, 'ACM001');
    }

    /*
        - La forme compte autant que le contenu : le frontend interroge 'MOUVEMENTCAISSE_VOIR', donc
          c'est cette chaîne-là qu'il doit recevoir. Le nom court de l'entité est mis à plat en
          majuscules, et c'est exactement là qu'une divergence se glisserait sans bruit.
    */
    public function testMePublieLesDroitsDuRoleDansLeVocabulaireDuFrontend(): void
    {
        $admin = $this->creerUtilisateur($this->entreprise, Role::ADMIN, 'admin@acme.test');

        $this->appeler('GET', '/api/me', $admin);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $droits = $this->reponse()['droits'] ?? null;

        self::assertIsArray($droits, 'Le profil doit porter la liste des droits');
        self::assertContains('MOUVEMENTCAISSE_VOIR', $droits, 'Le nom composé doit arriver à plat et en majuscules');
        self::assertContains('ENTREPRISE_RECHARGER', $droits);
        self::assertContains('PAIEMENT_PAYER', $droits);

        // Ce qui appartient au super administrateur ne doit pas déborder sur l'administrateur du client
        self::assertNotContains('ENTREPRISE_CREER', $droits);
        self::assertNotContains('SITE_CREER', $droits);
    }

    public function testChaqueRoleRepartAvecSaPropreListe(): void
    {
        $operateur = $this->creerUtilisateur($this->entreprise, Role::OPERATEUR, 'operateur@acme.test');
        $this->affecterOperateur($this->site, $operateur);

        $this->appeler('GET', '/api/me', $operateur);
        $droits = $this->reponse()['droits'] ?? [];

        // Il tient la caisse de son poste et réclame de quoi payer
        self::assertContains('PAIEMENT_PAYER', $droits);
        self::assertContains('DEMANDESOLDE_CREER', $droits);

        // Il LIT le référentiel : la classe d'un planteur décide de ce qu'il touche, et c'est lui qui l'a en face
        self::assertContains('PRODUIT_VOIR', $droits);
        self::assertContains('FOURNISSEUR_VOIR', $droits);

        /*
            - ET IL NE L'ÉCRIT PLUS. Il tarifait les produits et créait des planteurs, du temps où
              le référentiel était à son poste. Depuis qu'il appartient à l'entreprise, un prix
              saisi à une barrière vaut à toutes les autres.
        */
        self::assertNotContains('PRODUIT_MODIFIER', $droits);
        self::assertNotContains('FOURNISSEUR_CREER', $droits);
        self::assertNotContains('FOURNISSEUR_MODIFIER', $droits);

        // Il ne gère ni les comptes, ni l'argent de l'entreprise, et ne met rien à la corbeille
        self::assertNotContains('USER_VOIR', $droits);
        self::assertNotContains('ENTREPRISE_RECHARGER', $droits);
        self::assertNotContains('PRODUIT_SUPPRIMER', $droits);
    }

    /*
        - Le compte machine d'un pont bascule ne figure dans aucune ligne de la table : il ne sait
          que déposer des pesées. Il ne se connecte pas au web, mais s'il le faisait, l'absence de
          droits doit lui fermer tous les écrans plutôt que de les lui ouvrir par défaut.
    */
    public function testLeCompteMachineNeRepartAvecAucunDroit(): void
    {
        $machine = $this->creerCompteMachine($this->site);

        $this->appeler('GET', '/api/me', $machine);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->reponse()['droits'] ?? null);
    }

    /*
        - L'accord dans le sens qui compte : ce que '/me' tait doit être VRAIMENT refusé. Si la
          liste et le voter se séparaient, le frontend masquerait un geste permis — ou pire,
          en proposerait un qui échoue une fois le planteur devant le guichet.
        - L'opérateur est affecté au poste exprès : le refus doit venir du DROIT, pas du périmètre.
    */
    public function testCeQueMeTaitEstVraimentRefuseParLApi(): void
    {
        $operateur = $this->creerUtilisateur($this->entreprise, Role::OPERATEUR, 'operateur@acme.test');
        $this->affecterOperateur($this->site, $operateur);
        $produit = $this->creerProduit($this->site);

        $this->appeler('GET', '/api/me', $operateur);
        $droits = $this->reponse()['droits'] ?? [];

        self::assertNotContains('PRODUIT_SUPPRIMER', $droits);
        self::assertNotContains('PRODUIT_MODIFIER', $droits);

        $this->appeler('PATCH', '/api/produits/' . $produit->getId() . '/suppression', $operateur, []);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'Un droit absent de /me doit être refusé par l\'API');

        /*
            - LE RÉFÉRENTIEL EST À L'ADMINISTRATEUR. L'opérateur tarifait les produits et créait des
              planteurs ; depuis que le référentiel appartient à l'ENTREPRISE et non au poste, un
              prix saisi à une barrière vaut à toutes les autres, et une fiche corrigée l'est pour
              tout le monde. Le refus se vérifie DES DEUX CÔTÉS : une table modifiée sans son
              voter laisserait l'écran cacher un bouton que l'API accorde encore.
        */
        $this->appeler('PATCH', '/api/produits/' . $produit->getId(), $operateur, ['prix' => 250]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'L\'opérateur ne tarifie plus');

        // Et le symétrique : ce que la liste annonce, l'API l'accorde
        self::assertContains('DEMANDESOLDE_CREER', $droits);

        $this->appeler('POST', '/api/demande_soldes', $operateur, [
            'site' => '/api/sites/' . $this->site->getId(),
            'montantDemande' => 100_000,
            'caisse' => 'NUMERIQUE',
            'motif' => 'Plus de quoi payer les planteurs.',
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
    }

    /*
        - Le second étage : la table dit qu'un administrateur gère des comptes, la hiérarchie dit
          sur LESQUELS. Chaque compte rendu porte donc le verdict déjà croisé — c'est ce que le
          frontend recopiait, et sa copie avait perdu la règle du compte machine en route.
    */
    public function testChaqueCompteRenduPorteCeQuOnPeutYFaire(): void
    {
        $admin = $this->creerUtilisateur($this->entreprise, Role::ADMIN, 'admin@acme.test');
        $operateur = $this->creerUtilisateur($this->entreprise, Role::OPERATEUR, 'operateur@acme.test');

        $this->appeler('GET', '/api/users/' . $operateur->getId(), $admin);

        $compte = $this->reponse();

        self::assertContains('MODIFIER', $compte['droitsSurCeCompte'] ?? []);
        self::assertContains('BLOQUER', $compte['droitsSurCeCompte'] ?? []);

        // '??' avalerait le null autant que la clé absente : on vérifie les deux séparément
        self::assertArrayHasKey('motifNonGerable', $compte);
        self::assertNull($compte['motifNonGerable']);

        // Créer n'a pas de cible : l'annoncer sur un compte existant n'aurait aucun sens
        self::assertNotContains('CREER', $compte['droitsSurCeCompte'] ?? []);
    }

    /*
        - Personne ne se gère soi-même : deux administrateurs qui se neutralisent verrouillent
          l'entreprise hors de son propre outil. L'écran doit le DIRE, pas seulement masquer le
          bouton — une fiche muette ressemble à une panne.
    */
    public function testOnNeSeGerePasSoiMemeEtLApiLeDit(): void
    {
        $admin = $this->creerUtilisateur($this->entreprise, Role::ADMIN, 'admin@acme.test');

        $this->appeler('GET', '/api/users/' . $admin->getId(), $admin);

        $compte = $this->reponse();

        self::assertSame(['VOIR'], $compte['droitsSurCeCompte'] ?? null);
        self::assertStringContainsString('propre compte', (string) ($compte['motifNonGerable'] ?? ''));
    }

    /*
        - Les droits ne sont publiés que pour l'intéressé. La liste des utilisateurs n'a pas à dire
          ce que chacun peut faire : c'est une information de session, pas une fiche de personnel.
    */
    public function testLesDroitsNeFigurentQueDansMonProfil(): void
    {
        $admin = $this->creerUtilisateur($this->entreprise, Role::ADMIN, 'admin@acme.test');
        $agent = $this->creerUtilisateur($this->entreprise, Role::AGENT, 'agent@acme.test');

        $this->appeler('GET', '/api/users/' . $agent->getId(), $admin);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertArrayNotHasKey('droits', $this->reponse());
    }
}
