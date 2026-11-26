<?php

namespace App\Tests\Api;

use App\Domain\Enum\ModeReglement;
use App\Domain\Enum\Role;
use App\Domain\Enum\StatutPaiement;
use App\Entity\Paiement;
use App\Entity\PieceJointe;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/*
    - UNE PIÈCE JOINTE EST UN FICHIER QU'ON SERT, et c'est ce qui la rend différente de tout le
      reste de cette API. Partout ailleurs, une fuite de cloisonnement montre un chiffre ; ici elle
      livre le reçu signé d'un planteur d'une autre entreprise, en pièce jointe, téléchargeable.

    - CE QUE CES CAS GARDENT, et rien d'autre :
      · le fichier revient OCTET POUR OCTET — un justificatif tronqué ne vaut rien devant un
        contrôleur ;
      · la lecture suit les droits de la LIGNE, pas ceux de la pièce — c'est toute la règle, et
        elle n'est écrite qu'à un endroit ;
      · l'agent voit mais ne joint pas : 'VOIR' et 'JOINDRE' sont deux permissions ;
      · un second dépôt REMPLACE, il n'empile pas.

    - CE QU'ILS NE GARDENT PAS, et il faut le savoir : la contrainte 'CHECK' qui impose une cible
      et une seule vit dans la MIGRATION, pas dans le mapping Doctrine. Le schéma de test est
      construit depuis le mapping, donc la contrainte n'y est pas. Elle a été vérifiée à la main
      sur la base de développement, et c'est tout ce qu'on peut en dire ici.
*/
final class PieceJointeTest extends InvariantTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    /* Un fichier sur disque, parce qu'un téléversement en demande un. Il vit dans le dossier des tests, que la suite peut vider. */
    private function fichier(string $contenu = self::PDF, string $nom = 'recu.pdf', string $type = 'application/pdf'): UploadedFile
    {
        $chemin = sys_get_temp_dir() . '/' . bin2hex(random_bytes(8)) . '-' . $nom;
        file_put_contents($chemin, $contenu);

        /* Le dernier argument met 'UploadedFile' en mode test : sans lui, il refuse un fichier qui n'est pas arrivé par HTTP. */
        return new UploadedFile($chemin, $nom, $type, null, true);
    }

    /** @param array<string, string> $champs */
    private function televerser(User $utilisateur, UploadedFile $fichier, array $champs): void
    {
        $this->client->request(
            'POST',
            '/api/piece_jointes',
            $champs,
            ['fichier' => $fichier],
            [
                'HTTP_ACCEPT' => 'application/ld+json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()
                    ->get(\Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface::class)
                    ->create($utilisateur),
            ]
        );
    }

    /*
        - LE CORPS D'UN FICHIER SERVI, et il ne se lit pas comme les autres. 'BinaryFileResponse'
          ne CONSTRUIT pas son contenu : il l'envoie directement, et 'getContent()' rend 'false'.
          Le comparer tel quel ferait passer un test pour faux alors que le téléchargement marche.
    */
    private function corpsDuFichier(): string
    {
        ob_start();
        $this->client->getResponse()->sendContent();

        return (string) ob_get_clean();
    }

    /* Un versement confirmé, tel qu'il existe après un règlement en espèces : c'est la ligne la plus courante à justifier. */
    private function creerVersement(Site $site): Paiement
    {
        $planteur = $this->creerPlanteur($site, 'Kouame');

        $paiement = (new Paiement())
            ->setReference(Paiement::genererReference())
            /* Un seul setter pour les deux parts : c'est lui qui tient 'montant = produit + transport'. */
            ->setMontants(50_000, 0)
            ->setFournisseur($planteur)
            ->setSite($site)
            ->setModeReglement(ModeReglement::ESPECES)
            /* Les espèces ne passent par aucune passerelle, mais la colonne n'est pas nullable : c'est la main qui a compté les billets. */
            ->setGateway('ESPECES')
            ->setStatut(StatutPaiement::REUSSI);

        $this->em->persist($paiement);
        $this->em->flush();

        return $paiement;
    }

    /*
        - LE CAS ORDINAIRE, et le socle des autres : on joint, on relit, et ce qui revient est
          exactement ce qu'on a envoyé. Un justificatif tronqué ou ré-encodé ne prouve plus rien.
    */
    public function testLaPieceRevientOctetPourOctet(): void
    {
        $entreprise = $this->creerEntreprise('ACME');
        $site = $this->creerSite($entreprise);
        $admin = $this->creerUtilisateur($entreprise, Role::ADMIN, 'patron@acme.test');
        $versement = $this->creerVersement($site);

        $this->televerser($admin, $this->fichier(), [
            'ressource' => 'PAIEMENT',
            'ressourceId' => (string) $versement->getId(),
            'reference' => 'PC-2026-0001',
            'libelle' => 'Reçu signé',
        ]);

        self::assertResponseStatusCodeSame(201);

        $piece = $this->reponse();

        self::assertSame('recu.pdf', $piece['nomOrigine']);
        self::assertSame('PC-2026-0001', $piece['reference']);
        self::assertSame(strlen(self::PDF), $piece['taille']);
        /* Le chemin sur le disque ne sort JAMAIS : c'est la seconde barrière, et la publier reviendrait à l'ouvrir. */
        self::assertArrayNotHasKey('chemin', $piece);

        $this->appeler('GET', sprintf('/api/piece_jointes/%d/fichier', $piece['id']), $admin);

        self::assertResponseIsSuccessful();
        self::assertSame(self::PDF, $this->corpsDuFichier());
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    /*
        - LE CLOISONNEMENT, ET C'EST LE CAS QUI COMPTE. Ailleurs une fuite montre un montant ; ici
          elle livre le document. Deux entreprises dans la même base, et celle d'à côté n'obtient
          ni le fichier, ni le droit d'en poser un sur une ligne qui n'est pas la sienne.
    */
    public function testUneAutreEntrepriseNObtientNiLaPieceNiLeDroitDEnPoser(): void
    {
        $entreprise = $this->creerEntreprise('ACME');
        $site = $this->creerSite($entreprise);
        $admin = $this->creerUtilisateur($entreprise, Role::ADMIN, 'patron@acme.test');
        $versement = $this->creerVersement($site);

        $voisine = $this->creerEntreprise('VOISIN');
        $this->creerSite($voisine, 'VOI010');
        $intrus = $this->creerUtilisateur($voisine, Role::ADMIN, 'patron@voisin.test');

        $this->televerser($admin, $this->fichier(), ['ressource' => 'PAIEMENT', 'ressourceId' => (string) $versement->getId()]);
        self::assertResponseStatusCodeSame(201);

        $id = $this->reponse()['id'];

        $this->appeler('GET', sprintf('/api/piece_jointes/%d/fichier', $id), $intrus);
        self::assertResponseStatusCodeSame(403, 'Le justificatif d\'une autre entreprise a été servi');

        $this->televerser($intrus, $this->fichier(), ['ressource' => 'PAIEMENT', 'ressourceId' => (string) $versement->getId()]);
        self::assertResponseStatusCodeSame(403, 'Une autre entreprise a pu joindre une pièce à ce versement');
    }

    /*
        - VOIR N'EST PAS JOINDRE. L'agent supervise les montants : il doit lire les justificatifs,
          et il n'a rien à classer. Deux permissions et non une, sans quoi « qui peut lire peut
          écrire » deviendrait la règle sans que personne ne l'ait décidé.
    */
    public function testLAgentLitLaPieceMaisNEnJointAucune(): void
    {
        $entreprise = $this->creerEntreprise('ACME');
        $site = $this->creerSite($entreprise);
        $admin = $this->creerUtilisateur($entreprise, Role::ADMIN, 'patron@acme.test');
        $agent = $this->creerUtilisateur($entreprise, Role::AGENT, 'agent@acme.test');
        $versement = $this->creerVersement($site);

        $this->televerser($admin, $this->fichier(), ['ressource' => 'PAIEMENT', 'ressourceId' => (string) $versement->getId()]);
        $id = $this->reponse()['id'];

        $this->appeler('GET', sprintf('/api/piece_jointes/%d/fichier', $id), $agent);
        self::assertResponseIsSuccessful('Le superviseur ne peut plus lire les justificatifs');

        $this->televerser($agent, $this->fichier(), ['ressource' => 'PAIEMENT', 'ressourceId' => (string) $versement->getId()]);
        self::assertResponseStatusCodeSame(403, 'Le superviseur a pu joindre une pièce');
    }

    /*
        - UN SECOND DÉPÔT REMPLACE LE PREMIER. On se trompe de scan et on recommence : la ligne
          garde UNE pièce, et c'est la dernière qui fait foi. Deux lignes laisseraient deux
          justificatifs pour un versement sans dire lequel compte.
    */
    public function testUnSecondDepotRemplaceLePremier(): void
    {
        $entreprise = $this->creerEntreprise('ACME');
        $site = $this->creerSite($entreprise);
        $admin = $this->creerUtilisateur($entreprise, Role::ADMIN, 'patron@acme.test');
        $versement = $this->creerVersement($site);

        $this->televerser($admin, $this->fichier(), ['ressource' => 'PAIEMENT', 'ressourceId' => (string) $versement->getId()]);
        $premier = $this->reponse()['id'];

        $corrige = self::PDF . "% corrige\n";
        $this->televerser($admin, $this->fichier($corrige, 'recu-corrige.pdf'), ['ressource' => 'PAIEMENT', 'ressourceId' => (string) $versement->getId()]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($premier, $this->reponse()['id'], 'Le second dépôt a créé une seconde pièce');
        self::assertSame('recu-corrige.pdf', $this->reponse()['nomOrigine']);

        $pieces = $this->em->getRepository(PieceJointe::class)->count(['paiement' => $versement]);
        self::assertSame(1, $pieces, 'Le versement porte plus d\'une pièce');

        $this->appeler('GET', sprintf('/api/piece_jointes/%d/fichier', $premier), $admin);
        self::assertSame($corrige, $this->corpsDuFichier(), 'C\'est l\'ancien fichier qui est servi');
    }

    /*
        - CE QU'ON REFUSE, ET AVEC QUEL MOT. Un format inattendu et une taille excessive sont les
          deux erreurs que fera un opérateur pressé ; le message doit nommer laquelle, sinon il
          réessaie trois fois le même fichier.
    */
    public function testUnFormatEtUneTailleHorsLimitesSontRefuses(): void
    {
        $entreprise = $this->creerEntreprise('ACME');
        $site = $this->creerSite($entreprise);
        $admin = $this->creerUtilisateur($entreprise, Role::ADMIN, 'patron@acme.test');
        $versement = $this->creerVersement($site);
        $cible = ['ressource' => 'PAIEMENT', 'ressourceId' => (string) $versement->getId()];

        $this->televerser($admin, $this->fichier('du texte', 'notes.txt', 'text/plain'), $cible);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('PDF', $this->reponse()['detail'] ?? '');

        $this->televerser($admin, $this->fichier(self::PDF . str_repeat('A', 6 * 1024 * 1024)), $cible);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('5 Mo', $this->reponse()['detail'] ?? '');
    }

    /*
        - LA CHAÎNE DE LA DOTATION, DE BOUT EN BOUT, et c'est le seul cas où l'API a dû bouger pour
          qu'un écran puisse joindre une pièce. Doter rendait le SITE, c'est-à-dire un solde : rien
          ne nommait l'écriture qui venait de naître, et le web n'avait donc aucune ligne où poser
          le bordereau.

        - CE QUE CE CAS GARDE, dans l'ordre :
          · 'id' est l'écriture DU POSTE, celle qui reçoit, et 'contrepartieId' celle de
            l'entreprise qui donne. L'inverse passerait les deux premières assertions sans que rien
            ne le signale — et c'est la dernière qui le rattraperait ;
          · l'opérateur, qui a signé la décharge, PEUT LA RELIRE. C'est tout l'enjeu du sens choisi :
            il voit les écritures de sa caisse et jamais celles de l'entreprise, donc une pièce
            accrochée à la sortie aurait été invisible à celui-là même qui l'a signée.
    */
    public function testLaDotationNommeLEcritureDuPosteEtSaDechargeSuitLOperateur(): void
    {
        $entreprise = $this->creerEntreprise('ACME', 500_000);
        $site = $this->creerSite($entreprise);
        $admin = $this->creerUtilisateur($entreprise, Role::ADMIN, 'patron@acme.test');
        $operateur = $this->creerUtilisateur($entreprise, Role::OPERATEUR, 'guichet@acme.test');
        $this->affecterOperateur($site, $operateur);

        $this->appeler('PATCH', sprintf('/api/sites/%d/dotation', $site->getId()), $admin, [
            'caisse' => 'NUMERIQUE',
            'montant' => 120_000,
        ]);

        self::assertResponseIsSuccessful();

        $ecriture = $this->reponse();

        self::assertSame(120_000, $ecriture['montant']);
        self::assertNotNull($ecriture['contrepartieId'], 'Une dotation a toujours deux jambes');

        /* Le solde APRÈS est celui du poste, qui partait de zéro : c'est ce qui prouve lequel des deux mouvements est en tête. */
        self::assertSame(120_000, $ecriture['soldeApres'], 'C\'est la sortie de l\'entreprise qui a été rendue');

        $this->televerser($admin, $this->fichier(self::PDF, 'decharge.pdf'), [
            'ressource' => 'MOUVEMENT_CAISSE',
            'ressourceId' => (string) $ecriture['id'],
            'libelle' => 'Décharge de dotation',
        ]);

        self::assertResponseStatusCodeSame(201);

        $this->appeler('GET', sprintf('/api/piece_jointes/%d/fichier', $this->reponse()['id']), $operateur);

        self::assertResponseIsSuccessful('L\'opérateur ne peut pas relire la décharge qu\'il a signée');
        self::assertSame(self::PDF, $this->corpsDuFichier());
    }

    /*
        - UNE CIBLE INCONNUE SE NOMME. Un type mal orthographié dans un formulaire ne doit pas
          rendre un 500 ni, pire, une pièce orpheline : il rend un refus qui dit ce qu'on attendait.
    */
    public function testUnTypeDeLigneInconnuEstRefuse(): void
    {
        $entreprise = $this->creerEntreprise('ACME');
        $site = $this->creerSite($entreprise);
        $admin = $this->creerUtilisateur($entreprise, Role::ADMIN, 'patron@acme.test');
        $versement = $this->creerVersement($site);

        $this->televerser($admin, $this->fichier(), ['ressource' => 'PESEE', 'ressourceId' => (string) $versement->getId()]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('PAIEMENT', $this->reponse()['detail'] ?? '');
    }
}
