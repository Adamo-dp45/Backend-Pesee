<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Entreprise;
use App\Entity\MouvementCaisse;
use App\Entity\PieceJointe;
use App\Repository\PieceJointeRepository;
use App\Security\Perimetre;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/*
    - JOINDRE UN JUSTIFICATIF À UNE LIGNE D'ARGENT. Le corps est du multipart, donc API Platform ne
      désérialise rien : ce processor lit la requête lui-même, trouve la cible, vérifie le droit,
      puis pose le fichier.

    - LE DROIT SE DEMANDE SUR LA CIBLE, jamais sur la pièce. 'JOINDRE' sur un versement, sur un
      mouvement, sur une demande — et c'est 'AccesVoter' qui applique au passage le périmètre de
      l'utilisateur. Charger la cible par son identifiant contourne les extensions Doctrine, qui ne
      s'appliquent qu'aux requêtes d'API Platform : sans ce 'is_granted', un opérateur attacherait
      un document au versement d'une autre entreprise en changeant un chiffre dans le formulaire.

    - UN SECOND DÉPÔT REMPLACE LE PREMIER, dans la MÊME ligne. Deux lignes seraient de toute façon
      refusées par la contrainte d'unicité, et en créer une nouvelle après avoir effacé l'ancienne
      laisserait le fichier précédent sur le disque. Vich s'en charge quand on remplace en place.

    - L'ENTREPRISE VIENT DE LA CIBLE, jamais de la requête : c'est la ligne qui décide de qui est
      la pièce. La prendre dans le périmètre de l'utilisateur reviendrait au même aujourd'hui, et
      cesserait d'être vrai le jour où quelqu'un gagne deux entreprises.
*/

/**
 * @implements ProcessorInterface<mixed, PieceJointe>
 */
final class PieceJointeProcessor implements ProcessorInterface
{
    /** Le type de cible reçu => le champ de 'PieceJointe' et la classe à charger. */
    private const CIBLES = [
        'PAIEMENT' => ['champ' => 'paiement', 'classe' => \App\Entity\Paiement::class],
        'MOUVEMENT_CAISSE' => ['champ' => 'mouvementCaisse', 'classe' => MouvementCaisse::class],
        'DEMANDE_SOLDE' => ['champ' => 'demandeSolde', 'classe' => \App\Entity\DemandeSolde::class],
        'DEMANDE_PRELEVEMENT' => ['champ' => 'demandePrelevement', 'classe' => \App\Entity\DemandePrelevement::class],
        'DEMANDE_RETRAIT' => ['champ' => 'demandeRetrait', 'classe' => \App\Entity\DemandeRetrait::class],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PieceJointeRepository $pieces,
        private readonly RequestStack $requetes,
        private readonly Security $securite,
        private readonly Perimetre $perimetre,
        private readonly ValidatorInterface $validateur
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PieceJointe
    {
        $requete = $this->requetes->getCurrentRequest();

        if($requete === null) {
            throw new UnprocessableEntityHttpException('Aucun fichier reçu.');
        }

        $fichier = $requete->files->get('fichier');

        if(!$fichier instanceof UploadedFile) {
            throw new UnprocessableEntityHttpException('Aucun fichier reçu : le champ « fichier » est obligatoire.');
        }

        /*
            - LE REFUS DE PHP SE LIT AVANT TOUT LE RESTE. Un fichier plus gros que 'upload_max_filesize'
              arrive « non valide » et vide : sans ce test, on répondrait « format non accepté » pour
              une erreur de taille, et l'utilisateur chercherait longtemps.
        */
        if(!$fichier->isValid()) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Le téléversement a échoué : %s. La taille maximale acceptée est de 5 Mo.',
                $fichier->getErrorMessage()
            ));
        }

        $cible = $this->cible($requete->request->get('ressource'), $requete->request->get('ressourceId'));

        if(!$this->securite->isGranted('JOINDRE', $cible['objet'])) {
            throw new AccessDeniedHttpException('Vous ne pouvez pas joindre de pièce à cette ligne.');
        }

        $piece = $this->pieces->pourCible($cible['champ'], $cible['objet']) ?? new PieceJointe();

        $piece->fichier = $fichier;
        $piece
            ->setNomOrigine($fichier->getClientOriginalName())
            ->setTypeMime($fichier->getMimeType() ?? 'application/octet-stream')
            ->setTaille((int) $fichier->getSize())
            ->setReference($this->texte($requete->request->get('reference')))
            ->setLibelle($this->texte($requete->request->get('libelle')))
            ->setEntreprise($this->entrepriseDe($cible['objet']))
            ->setDeposePar($this->perimetre->utilisateur());

        /* Le setter de la cible : 'setPaiement', 'setMouvementCaisse'… Sur un remplacement il repose la même valeur, ce qui ne coûte rien. */
        $piece->{'set' . ucfirst($cible['champ'])}($cible['objet']);

        /*
            - LA VALIDATION SE FAIT ICI, À LA MAIN. Avec 'deserialize: false', API Platform ne
              construit pas l'objet et ne le valide donc pas : les contraintes de taille et de
              format posées sur l'entité ne se déclencheraient jamais.
        */
        $erreurs = $this->validateur->validate($piece);

        if(count($erreurs) > 0) {
            throw new UnprocessableEntityHttpException((string) $erreurs->get(0)->getMessage());
        }

        $this->em->persist($piece);
        $this->em->flush();

        return $piece;
    }

    /**
     * La cible désignée par la requête, chargée et vérifiée.
     *
     * @return array{champ: string, objet: object}
     */
    private function cible(mixed $ressource, mixed $ressourceId): array
    {
        $type = strtoupper(trim((string) $ressource));
        $id = (int) $ressourceId;

        if(!isset(self::CIBLES[$type])) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Type de ligne inconnu : « %s ». Attendu : %s.',
                $type === '' ? '(vide)' : $type,
                implode(', ', array_keys(self::CIBLES))
            ));
        }

        $objet = $id > 0 ? $this->em->getRepository(self::CIBLES[$type]['classe'])->findOneBy(['id' => $id, 'deletedAt' => null]) : null;

        if($objet === null) {
            throw new NotFoundHttpException('Cette ligne est introuvable.');
        }

        return ['champ' => self::CIBLES[$type]['champ'], 'objet' => $objet];
    }

    /* L'entreprise de la ligne. Un mouvement la porte ; les autres passent par leur pont bascule. */
    private function entrepriseDe(object $cible): ?Entreprise
    {
        if($cible instanceof MouvementCaisse) {
            return $cible->getEntreprise() ?? $cible->getSite()?->getEntreprise();
        }

        return method_exists($cible, 'getSite') ? $cible->getSite()?->getEntreprise() : null;
    }

    private function texte(mixed $valeur): ?string
    {
        $texte = trim((string) $valeur);

        return $texte === '' ? null : $texte;
    }
}
