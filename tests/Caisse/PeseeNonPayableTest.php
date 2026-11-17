<?php

namespace App\Tests\Caisse;

use App\Domain\Enum\Role;
use App\Domain\Enum\StatutPaiement;
use App\Domain\Enum\StatutReglement;
use App\Entity\Operation;
use App\Entity\Paiement;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - « Rien à verser » recouvre deux situations qui n'ont rien à voir, et les confondre coûtait une
      visite au poste : une pesée SOLDÉE, où l'argent est parti, et une pesée SANS PRIX, où il n'a
      jamais été question d'argent.

    - Le second cas n'a rien d'un cas limite, c'est le déroulement normal : le peseur crée ses
      produits depuis l'écran de pesée, et un produit ainsi créé naît sans prix ('Produit::$prix'
      vaut 0). Ses pesées valent zéro franc, restent « non payées », s'affichent donc dans la file du
      guichet — et le versement était refusé d'un « Cette pesée est déjà soldée ». L'opérateur
      cherchait alors un versement qui n'avait jamais eu lieu, au lieu d'aller tarifer le produit.

    - Ce que ces cas vérifient : que le refus NOMME ce qu'il faut corriger, et que la pesée le dise
      AVANT le clic — c'est 'motifNonPayable' qui l'alimente, du même côté que la décision.
*/
final class PeseeNonPayableTest extends InvariantTestCase
{
    private const CAISSE_INITIALE = 100_000;

    /*
        - Le cas de terrain : un produit jamais tarifé. Le message doit parler du produit, et surtout
          pas de solde — il n'y a pas un franc dans cette histoire.
    */
    public function testUnePeseeSansPrixNEstPasAnnonceeSoldee(): void
    {
        [$site, $operateur, $pesee] = $this->decor(prixDuProduit: 0);

        self::assertSame(0, $pesee->getMontantdu(), 'Un produit sans prix donne une pesée à zéro franc');
        self::assertSame(StatutReglement::NON_PAYE, $pesee->getStatutReglement(), 'Elle reste « non payée » : c\'est ce qui la fait apparaître dans la file du guichet');

        $this->appeler('POST', '/api/paiements', $operateur, [
            'operationId' => $pesee->getId(),
            'modeReglement' => 'MOBILE_MONEY',
            'montantProduit' => 20_000,
            'montantTransport' => 0,
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $detail = (string) ($this->reponse()['detail'] ?? '');

        self::assertStringNotContainsString('soldée', $detail, 'Une pesée qui n\'a jamais rien valu n\'est pas une pesée soldée');
        self::assertStringContainsString('Cacao', $detail, 'Le refus doit nommer le produit à tarifer, sinon il n\'indique rien à corriger');

        $this->em->clear();

        self::assertSame(self::CAISSE_INITIALE, $this->em->find(Site::class, $site->getId())?->getSolde(), 'Un refus ne doit rien réserver');
    }

    /*
        - L'écran décide avec la même phrase que l'API, et c'est tout l'intérêt de la faire porter par
          la pesée : le bouton « Verser » se désactive sur ce motif, le serveur refuse sur le même.
    */
    public function testLaPeseeDitAvantLeClicPourquoiElleNEstPasPayable(): void
    {
        [, $operateur, $pesee] = $this->decor(prixDuProduit: 0);

        self::assertFalse($pesee->estPayable());

        $this->appeler('GET', '/api/operations/' . $pesee->getId(), $operateur);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Cacao', (string) ($this->reponse()['motifNonPayable'] ?? ''));
    }

    /*
        - La phrase reste juste quand elle l'est : une pesée réellement couverte par ses versements se
          refuse bien d'un « déjà soldée ». Corriger le message ne devait pas le faire disparaître.
    */
    public function testUnePeseeReellementCouverteEstBienAnnonceeSoldee(): void
    {
        [$site, $operateur, $pesee] = $this->decor(prixDuProduit: 200);

        /*
            - L'état où la confirmation d'un versement laisse la pesée, posé sans rejouer tout le
              cycle de la passerelle. Le versement est bien écrit : c'est lui, et non le cumul
              dénormalisé, que le service relit pour savoir ce qui est déjà engagé.
        */
        $this->em->persist(
            (new Paiement())
                ->setReference(Paiement::genererReference())
                ->setMontants((int) $pesee->getMontantdu(), 0)
                ->setOperation($pesee)
                ->setFournisseur($pesee->getFournisseur())
                ->setSite($site)
                ->setNumeroDestinataire('+2250700000001')
                ->setGateway('fake')
                ->setStatut(StatutPaiement::REUSSI)
                ->setInitiePar($operateur)
        );

        $pesee->setMontantspayes((int) $pesee->getMontantdu(), 0)->setStatutReglement(StatutReglement::SOLDE);
        $this->em->flush();

        $this->appeler('POST', '/api/paiements', $operateur, [
            'operationId' => $pesee->getId(),
            'modeReglement' => 'MOBILE_MONEY',
            'montantProduit' => 20_000,
            'montantTransport' => 0,
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        // Le refus porte sur LA PART demandée, et le dit : c'est la récolte qui est réglée
        $detail = (string) ($this->reponse()['detail'] ?? '');
        self::assertStringContainsString('La part récolte de cette pesée est déjà réglée', $detail);
        self::assertStringNotContainsString('pas de prix', $detail);

        // Et le motif GLOBAL dit bien « soldée » : les deux parts sont fermées pour de l'argent
        $this->em->clear();
        self::assertStringContainsString(
            'soldée',
            (string) $this->em->find(Operation::class, $pesee->getId())?->getMotifNonPayable()
        );
    }

    /* ---- Montage ----------------------------------------------------------------------------- */

    /** @return array{0: Site, 1: User, 2: Operation} */
    private function decor(int $prixDuProduit): array
    {
        $entreprise = $this->creerEntreprise('ACME');
        $site = $this->creerSite($entreprise, 'SOF010', self::CAISSE_INITIALE);

        $operateur = $this->creerUtilisateur($entreprise, Role::OPERATEUR, 'operateur@acme.test');
        $this->affecterOperateur($site, $operateur);

        $produit = $this->creerProduit($site, 'Cacao', $prixDuProduit);
        $planteur = $this->creerPlanteur($site);

        return [$site, $operateur, $this->creerPesee($site, $produit, $planteur, 1_000)];
    }
}
