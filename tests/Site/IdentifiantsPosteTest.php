<?php

namespace App\Tests\Site;

use App\Domain\Enum\Role;
use App\Entity\Entreprise;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - Mettre un poste en service, c'est remettre DEUX valeurs à l'installateur : un identifiant et un
      mot de passe. Le second n'existe nulle part en clair — il est engendré et renvoyé une seule
      fois — donc une réponse incomplète ne se rattrape pas : il faut régénérer, ce qui invalide ce
      qui vient d'être déposé sur le poste.

    - Ces cas existent parce que le défaut est passé inaperçu : la réponse ne portait que le mot de
      passe, l'identifiant manquait, et rien ne le signalait côté API. C'est l'écran qui tombait, au
      moment précis où quelqu'un installait un poste sur le terrain.

    - Le revers est vérifié aussi : ces deux champs ne doivent apparaître NULLE PART ailleurs. Ils
      vivent dans le groupe 'read:Site:machine', que seules ces deux opérations activent.
*/
final class IdentifiantsPosteTest extends InvariantTestCase
{
    private Entreprise $entreprise;
    private User $patron;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = $this->creerEntreprise('ACME');
        $this->patron = $this->creerUtilisateur(null, Role::SUPER_ADMIN, 'patron@webpesee.test');
    }

    public function testLaMiseEnServiceRemetLIdentifiantEtLeMotDePasse(): void
    {
        $this->mettreEnService('SOF010');

        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $reponse = $this->reponse();

        self::assertArrayHasKey('identifiantMachine', $reponse, 'Sans lui, l\'écran d\'installation ne peut rien afficher');
        self::assertArrayHasKey('motDePasseMachine', $reponse);
        self::assertNotEmpty($reponse['identifiantMachine']);
        self::assertNotEmpty($reponse['motDePasseMachine']);

        // L'identifiant se dérive du code du poste : c'est ce qui le rend reconnaissable dans les journaux
        self::assertStringStartsWith('sof010@', $reponse['identifiantMachine']);

        /*
            - Le fichier de configuration et l'écran doivent annoncer le MÊME compte. Les laisser
              diverger déposerait sur le poste des identifiants que l'installateur n'a pas sous les yeux.
        */
        self::assertSame($reponse['identifiantMachine'], $reponse['configuration']['api']['login'] ?? null);
        self::assertSame($reponse['motDePasseMachine'], $reponse['configuration']['api']['motdepasse'] ?? null);
    }

    public function testLeRenouvellementRemetLesDeuxValeurs(): void
    {
        $this->mettreEnService('SOF010');

        $premier = $this->reponse();
        $site = $this->em->getRepository(Site::class)->findOneBy(['code' => 'SOF010']);

        $this->appeler('PATCH', '/api/sites/' . $site->getId() . '/identifiants', $this->patron, []);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $second = $this->reponse();

        self::assertArrayHasKey('identifiantMachine', $second);
        self::assertNotEmpty($second['motDePasseMachine']);

        // L'identifiant ne bouge pas — il appartient au compte, pas au code — mais le mot de passe, si
        self::assertSame($premier['identifiantMachine'], $second['identifiantMachine']);
        self::assertNotSame($premier['motDePasseMachine'], $second['motDePasseMachine']);
    }

    /*
        - LE défaut qui rendait 500 la création d'un pont bascule, et que personne ne pouvait
          diagnostiquer depuis l'écran : « Une erreur est survenue », rien de plus.

        - L'adresse du compte machine se dérive du code À LA CRÉATION et ne le suit plus ensuite.
          Renommer un poste libère donc son code en apparence seulement : l'ancien compte garde
          l'adresse. Le poste suivant qui reprenait ce code butait sur l'index unique de
          'user.email', et la requête mourait sur une violation d'intégrité.
    */
    public function testUnCodeRenduParUnPosteRenommeNEmpechePlusDEnCreerUnAutre(): void
    {
        $this->mettreEnService('SOF010');
        $premier = $this->reponse();

        $site = $this->em->getRepository(Site::class)->findOneBy(['code' => 'SOF010']);
        $this->appeler('PATCH', '/api/sites/' . $site->getId(), $this->patron, ['code' => 'SOF999']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Le code a l'air libre : un nouveau poste doit pouvoir le prendre
        $this->mettreEnService('SOF010');
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $second = $this->reponse();

        self::assertNotSame(
            $premier['identifiantMachine'],
            $second['identifiantMachine'],
            'Deux postes ne peuvent pas porter la même adresse : la base l\'interdit'
        );
        self::assertSame($second['identifiantMachine'], $second['configuration']['api']['login'] ?? null);

        // Et il ouvre vraiment : une adresse de repli qui ne connecte pas ne vaut rien
        $this->connecter($second['identifiantMachine'], $second['motDePasseMachine']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /*
        - Le même défaut par l'autre porte : le renouvellement recomposait l'adresse à partir du
          code, et la déplaçait donc à chaque fois sur un poste renommé. Si un autre compte occupait
          l'adresse visée, c'était une 500 ; sinon, le poste en service perdait son login sans que
          rien ne l'annonce.

        - Ce que le cas fixe : renouveler ne touche QUE le mot de passe, et l'ancien poste continue
          d'ouvrir avec l'adresse qu'il a toujours eue.
    */
    public function testLeRenouvellementNeDeplacePasLAdresseDUnPosteRenomme(): void
    {
        $this->mettreEnService('SOF010');
        $avant = $this->reponse();

        $site = $this->em->getRepository(Site::class)->findOneBy(['code' => 'SOF010']);
        $this->appeler('PATCH', '/api/sites/' . $site->getId(), $this->patron, ['code' => 'SOF999']);

        // Un second poste reprend le code rendu : l'adresse 'sof010@' reste donc occupée
        $this->mettreEnService('SOF010');
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $this->appeler('PATCH', '/api/sites/' . $site->getId() . '/identifiants', $this->patron, []);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $apres = $this->reponse();

        self::assertSame($avant['identifiantMachine'], $apres['identifiantMachine'], 'Le renouvellement a déplacé le login du poste');
        self::assertNotSame($avant['motDePasseMachine'], $apres['motDePasseMachine']);

        $this->connecter($apres['identifiantMachine'], $apres['motDePasseMachine']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /*
        - Le mot de passe renvoyé doit RÉELLEMENT ouvrir le compte du poste. C'est la seule façon de
          savoir qu'on remet à l'installateur autre chose qu'une chaîne décorative : le poste, lui,
          ne découvrira le problème qu'au premier envoi de pesées, sur le terrain.
    */
    public function testLesIdentifiantsRemisOuvrentVraimentLeCompteDuPoste(): void
    {
        $this->mettreEnService('SOF010');

        $identifiants = $this->reponse();

        $this->connecter($identifiants['identifiantMachine'], $identifiants['motDePasseMachine']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'Le poste ne peut pas se connecter avec ce qu\'on vient de lui remettre');

        // Et après renouvellement, c'est le NOUVEAU qui ouvre, plus l'ancien
        $site = $this->em->getRepository(Site::class)->findOneBy(['code' => 'SOF010']);
        $this->appeler('PATCH', '/api/sites/' . $site->getId() . '/identifiants', $this->patron, []);
        $renouveles = $this->reponse();

        $this->connecter($renouveles['identifiantMachine'], $renouveles['motDePasseMachine']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->connecter($identifiants['identifiantMachine'], $identifiants['motDePasseMachine']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode(), 'L\'ancien mot de passe ouvre encore le poste');
    }

    /*
        - Le revers : hors de ces deux opérations, les identifiants ne doivent apparaître nulle part.
          Le groupe qui les porte n'est activé que là, et une fiche ordinaire qui les laisserait
          passer transformerait chaque consultation en fuite.
    */
    public function testLesIdentifiantsNApparaissentNiSurLaFicheNiDansLaListe(): void
    {
        $this->mettreEnService('SOF010');
        $site = $this->em->getRepository(Site::class)->findOneBy(['code' => 'SOF010']);

        $this->appeler('GET', '/api/sites/' . $site->getId(), $this->patron);
        $fiche = $this->reponse();

        self::assertArrayNotHasKey('identifiantMachine', $fiche);
        self::assertArrayNotHasKey('motDePasseMachine', $fiche);
        self::assertArrayNotHasKey('configuration', $fiche);

        $this->appeler('GET', '/api/sites', $this->patron);

        foreach ($this->membres() as $ligne) {
            self::assertArrayNotHasKey('identifiantMachine', $ligne);
            self::assertArrayNotHasKey('motDePasseMachine', $ligne);
        }
    }

    /* ---- Outils ------------------------------------------------------------------------------ */

    private function mettreEnService(string $code): void
    {
        $this->appeler('POST', '/api/sites', $this->patron, [
            'code' => $code,
            'libellesite' => 'Pont bascule ' . $code,
            'entreprise' => '/api/entreprises/' . $this->entreprise->getId(),
        ]);
    }

    private function connecter(string $identifiant, string $motDePasse): void
    {
        $this->client->request(
            'POST',
            '/api/login_check',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['username' => $identifiant, 'password' => $motDePasse], JSON_THROW_ON_ERROR)
        );
    }
}
