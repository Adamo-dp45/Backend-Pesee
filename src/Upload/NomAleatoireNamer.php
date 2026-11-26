<?php

namespace App\Upload;

use Vich\UploaderBundle\Mapping\PropertyMappingInterface;
use Vich\UploaderBundle\Naming\NamerInterface;

/*
    - UN NOM QUI NE SE DEVINE PAS : 128 bits tirés de 'random_bytes', et l'extension. Les pièces
      vivent hors de la racine web, donc aucune URL n'y mène ; ce nom est la SECONDE barrière,
      celle qui tient le jour où le dossier se retrouve servi par erreur — une racine mal
      configurée, un dossier recopié ailleurs, un serveur de développement lancé à la main.

    - LES NAMERS DE VICH NE CONVIENNENT PAS. 'UniqidNamer' et 'SmartUniqueNamer' dérivent de
      'uniqid()', c'est-à-dire de l'HORLOGE : connaître l'heure approximative d'un dépôt ramène la
      recherche à quelques millions de noms par seconde. Et 'SmartUniqueNamer' conserve le nom
      d'origine, qui porte souvent celui du planteur — « recu-kouame-yao.pdf » en dit déjà trop.

    - L'EXTENSION EST DÉDUITE DU CONTENU ('guessExtension'), jamais reprise du client. Un fichier
      annoncé '.pdf' et qui est un script ne doit pas garder son annonce : c'est le serveur qui
      tranche, à partir de ce qu'il a reçu.
*/
final class NomAleatoireNamer implements NamerInterface
{
    public function name(object|array $object, PropertyMappingInterface $mapping): string
    {
        $fichier = $mapping->getFile($object);
        $extension = $fichier?->guessExtension() ?: 'bin';

        return sprintf('%s.%s', bin2hex(random_bytes(16)), $extension);
    }
}
