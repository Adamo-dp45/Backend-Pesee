<?php

namespace App\Controller;

use App\Repository\PieceJointeRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vich\UploaderBundle\Storage\StorageInterface;

/*
    - SERT LE FICHIER D'UNE PIÈCE JOINTE, et c'est le SEUL chemin qui y mène. Les documents vivent
      hors de la racine web : aucune URL statique ne les atteint, et leur nom sur le disque est
      aléatoire et jamais publié.

    - LE DROIT SE DEMANDE SUR LA LIGNE, pas sur la pièce. « Qui voit le versement voit son
      justificatif » n'est pas une formule : c'est littéralement 'is_granted('VOIR', $cible)', donc
      la table des droits et le périmètre, sans une seule règle de plus à tenir d'accord. Une
      seconde règle posée ici aurait divergé le jour où l'une des deux bouge.

    - LA PIÈCE SE CHARGE À LA MAIN ('read: false' sur l'opération), et c'est voulu : le provider
      d'API Platform l'aurait filtrée sur le cloisonnement de la PIÈCE, ce qui n'est pas la question.
      C'est la ligne qui décide, et elle seule.
*/
#[AsController]
final class PieceJointeFichierController
{
    public function __construct(
        private readonly PieceJointeRepository $pieces,
        private readonly StorageInterface $stockage,
        private readonly Security $securite
    )
    {
    }

    public function __invoke(int $id): BinaryFileResponse
    {
        $piece = $this->pieces->findOneBy(['id' => $id, 'deletedAt' => null]);

        if($piece === null) {
            throw new NotFoundHttpException('Cette pièce jointe est introuvable.');
        }

        $cible = $piece->cible();

        /*
            - UNE PIÈCE SANS LIGNE EST UNE ANOMALIE, que la contrainte de base interdit. Elle rend
              404 et non 500 : si une reprise de données en laisse une, on veut un message, pas un
              appel sur null.
        */
        if($cible === null) {
            throw new NotFoundHttpException('Cette pièce jointe n\'est rattachée à aucune ligne.');
        }

        if(!$this->securite->isGranted('VOIR', $cible)) {
            throw new AccessDeniedHttpException('Vous ne pouvez pas consulter cette pièce jointe.');
        }

        $chemin = $this->stockage->resolvePath($piece, 'fichier');

        /*
            - LA LIGNE EXISTE MAIS PAS LE FICHIER : stockage non sauvegardé, restauration partielle,
              dossier recopié sans son contenu. Un 404 qui le DIT vaut mieux qu'une 500 sur un
              'fopen', qui enverrait chercher un bogue là où il n'y en a pas.
        */
        if($chemin === null || !is_file($chemin)) {
            throw new NotFoundHttpException('Le fichier de cette pièce est introuvable sur le serveur.');
        }

        $reponse = new BinaryFileResponse($chemin);

        /* Un PDF ou une photo se CONSULTE : on l'ouvre dans l'onglet, on ne le télécharge pas pour l'ouvrir ensuite. */
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $piece->nomDeTelechargement());

        /*
            - 'nosniff' : le navigateur s'en tient au type annoncé et n'interprète pas un fichier
              téléversé comme du HTML.
            - 'private, no-store' : une pièce comptable ne doit rester dans AUCUN cache partagé, ni
              sur le disque du poste une fois l'onglet fermé.
        */
        $reponse->headers->set('Content-Type', $piece->getTypeMime() ?? 'application/octet-stream');
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->headers->set('Cache-Control', 'private, no-store');

        return $reponse;
    }
}
