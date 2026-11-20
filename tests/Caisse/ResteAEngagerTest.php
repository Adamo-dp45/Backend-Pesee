<?php

namespace App\Tests\Caisse;

use App\Domain\Enum\Role;
use App\Domain\Enum\StatutReglement;
use App\Entity\Operation;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - DEUX RESTES, et les confondre se paie en double paiement :

        · le reste à PAYER     — la créance du planteur, dû moins CONFIRMÉ. Elle ne tombe que
                                 lorsque la passerelle a répondu, et c'est juste : tant qu'elle n'a
                                 pas répondu, le planteur n'a rien reçu ;
        · le reste à ENGAGER   — ce que la caisse peut encore sortir, donc ce que l'API acceptera.
                                 Un versement en vol l'a déjà amputée.

    - Ce qui se cassait quand la file du guichet ne montrait que le premier : l'opérateur verse, la
      ligne affiche exactement le même montant qu'avant, il en conclut que rien n'est parti et
      recommence. L'API refusait le second — mais il aura fallu cliquer pour l'apprendre, planteur
      devant soi, et rien n'empêchait de recommencer encore.

    - L'écran ne recalcule rien : les deux chiffres viennent de l'API, celle-là même qui tranche.
*/
final class ResteAEngagerTest extends InvariantTestCase
{
    private const CAISSE_INITIALE = 500_000;

    /** Prix 200 F × 1 000 kg : une pesée à 200 000 F, de quoi verser en plusieurs fois. */
    private const MONTANT_DU = 200_000;

    /*
        - Le cas du rapport : on verse, et le reste doit bouger TOUT DE SUITE, avant même que la
          passerelle ait confirmé. L'argent, lui, est déjà sorti de la caisse.
    */
    public function testUnVersementEnVolFaitBaisserLeResteAEngagerDesLEnvoi(): void
    {
        $this->exigeLeMobileMoney();

        [, $operateur, $pesee] = $this->decor();

        $this->verser($operateur, $pesee, 30_000);

        $vue = $this->lire($operateur, $pesee);

        self::assertSame(30_000, $vue['montantenvol'], 'Le versement en vol n\'est pas remonté');
        self::assertSame(self::MONTANT_DU - 30_000, $vue['resteaengager'], 'Le reste à engager n\'a pas bougé après le versement');

        // La créance, elle, ne tombe qu'à la confirmation : rien n'est encore arrivé chez le planteur
        self::assertSame(0, $vue['montantpaye']);
        self::assertSame(self::MONTANT_DU, $vue['resteapayer']);
        self::assertSame(StatutReglement::NON_PAYE->value, $vue['statutReglement']);
    }

    /*
        - La file du guichet est une LISTE : le montant en vol doit y être, et sans faire payer une
          requête par ligne. 'MontantEnVolProvider' le pose en une fois pour toute la page — d'où le
          zéro explicite sur les pesées qui n'ont rien en vol, qui prouve qu'il est bien passé.
    */
    public function testLaListePorteLeMontantEnVolDeChaqueLigne(): void
    {
        $this->exigeLeMobileMoney();

        [$site, $operateur, $pesee] = $this->decor();

        $intacte = $this->creerPesee($site, $this->produit, $this->planteur, 1_000, '2');

        $this->verser($operateur, $pesee, 30_000);

        $this->appeler('GET', '/api/operations', $operateur);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $parId = [];

        foreach($this->membres() as $ligne) {
            $parId[$ligne['id']] = $ligne;
        }

        self::assertSame(30_000, $parId[$pesee->getId()]['montantenvol'] ?? null);
        self::assertSame(0, $parId[$intacte->getId()]['montantenvol'] ?? null, 'Une pesée sans versement en vol doit porter zéro, pas l\'absence de valeur');
        self::assertSame(self::MONTANT_DU, $parId[$intacte->getId()]['resteaengager'] ?? null);
    }

    /*
        - Tout le reste engagé, rien de confirmé : la pesée n'est PAS soldée — le planteur n'a rien
          reçu — mais il n'y a plus rien à engager. Le motif doit le dire avec les mots du refus, et
          le refus tomber si l'on insiste.
    */
    public function testUnePeseeEntierementEngageeAnnonceUnVersementEnCoursEtNonUnSolde(): void
    {
        $this->exigeLeMobileMoney();

        [$site, $operateur, $pesee] = $this->decor();

        $this->verser($operateur, $pesee, self::MONTANT_DU);

        $vue = $this->lire($operateur, $pesee);

        self::assertSame(0, $vue['resteaengager']);
        self::assertSame(StatutReglement::NON_PAYE->value, $vue['statutReglement'], 'Un versement en vol ne solde rien : la passerelle n\'a pas répondu');
        self::assertStringContainsString('déjà en cours', (string) $vue['motifNonPayable']);

        // Insister donne le même message, mot pour mot : l'écran annonçait bien ce que le serveur répond
        $this->appeler('POST', '/api/paiements', $operateur, ['operationId' => $pesee->getId(),
            'modeReglement' => 'MOBILE_MONEY', 'montantProduit' => 1_000, 'montantTransport' => 0]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame($vue['motifNonPayable'], $this->reponse()['detail'] ?? null);

        $this->em->clear();

        self::assertSame(self::CAISSE_INITIALE - self::MONTANT_DU, $this->em->find(Site::class, $site->getId())?->getSolde(), 'Le refus a tout de même sorti des fonds');
    }

    /* ---- Montage ----------------------------------------------------------------------------- */

    private \App\Entity\Produit $produit;
    private \App\Entity\Fournisseur $planteur;

    /** @return array{0: Site, 1: User, 2: Operation} */
    private function decor(): array
    {
        $entreprise = $this->creerEntreprise('ACME');
        $site = $this->creerSite($entreprise, 'SOF010', self::CAISSE_INITIALE);

        $operateur = $this->creerUtilisateur($entreprise, Role::OPERATEUR, 'operateur@acme.test');
        $this->affecterOperateur($site, $operateur);

        $this->produit = $this->creerProduit($site, 'Hévéa', 200);
        $this->planteur = $this->creerPlanteur($site);

        return [$site, $operateur, $this->creerPesee($site, $this->produit, $this->planteur, 1_000)];
    }

    /*
        - Un versement qui reste EN VOL : 'PasserelleSimulee' ne confirme tout de suite que les
          montants se terminant par '99'. Ceux-ci partent en 'ENVOYE' et attendent — c'est le cas
          nominal, et celui que l'écran doit savoir montrer.
    */
    private function verser(User $operateur, Operation $pesee, int $montant): void
    {
        $this->appeler('POST', '/api/paiements', $operateur, ['operationId' => $pesee->getId(),
            'modeReglement' => 'MOBILE_MONEY', 'montantProduit' => $montant, 'montantTransport' => 0]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    /** @return array<string, mixed> */
    private function lire(User $operateur, Operation $pesee): array
    {
        $this->appeler('GET', '/api/operations/' . $pesee->getId(), $operateur);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $this->reponse();
    }
}
