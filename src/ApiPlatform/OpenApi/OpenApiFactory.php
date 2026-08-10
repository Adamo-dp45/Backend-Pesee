<?php

namespace App\ApiPlatform\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\OpenApi;
use ArrayObject;

/*
    - Ajoute le schéma d'authentification 'bearerAuth' que chaque opération référence dans son 'openapi:'. Sans lui, le bouton « Authorize » de la doc interactive n'existe pas.
    - Décoré dans 'services.yaml'.
*/
class OpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(
        private OpenApiFactoryInterface $decorated
    )
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = $this->decorated->__invoke($context);

        $schemas = $openApi->getComponents()->getSecuritySchemes();
        $schemas['bearerAuth'] = new ArrayObject([
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
        ]);

        return $openApi;
    }
}
