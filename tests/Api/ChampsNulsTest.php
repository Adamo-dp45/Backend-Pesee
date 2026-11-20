<?php

namespace App\Tests\Api;

use App\Domain\Enum\Role;
use App\Tests\InvariantTestCase;

/*
    - Un champ nul sort à null, il ne disparaît pas : le web tourne en 'strict_variables', et une clé
      absente y lève une 500 sur les seules fiches incomplètes.

    - Le réglage vit UNE fois, dans 'api_platform.yaml'. Ce qu'on garde ici, c'est qu'il traverse les
      ressources et les opérations qui déclarent leur PROPRE 'normalizationContext' — '/me' et la
      fiche d'un compte en ont chacune un.
*/
final class ChampsNulsTest extends InvariantTestCase
{
    public function testUnChampNulResteDansLaReponseDeMe(): void
    {
        $admin = $this->creerUtilisateur($this->creerEntreprise('ACME'), Role::ADMIN, 'admin@acme.test');

        $this->appeler('GET', '/api/me', $admin);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('telephone', $this->reponse());
        self::assertNull($this->reponse()['telephone']);
    }

    public function testUnChampNulResteDansLaFicheDUnCompte(): void
    {
        $entreprise = $this->creerEntreprise('ACME');
        $admin = $this->creerUtilisateur($entreprise, Role::ADMIN, 'admin@acme.test');
        $operateur = $this->creerUtilisateur($entreprise, Role::OPERATEUR, 'operateur@acme.test');

        $this->appeler('GET', '/api/users/' . $operateur->getId(), $admin);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('telephone', $this->reponse());
        self::assertNull($this->reponse()['telephone']);
    }
}
