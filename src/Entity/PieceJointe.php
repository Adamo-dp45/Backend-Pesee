<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\Symfony\Action\NotFoundAction;
use App\Controller\PieceJointeFichierController;
use App\Entity\Interface\EntrepriseOwnedInterface;
use App\Repository\PieceJointeRepository;
use App\State\Processor\PieceJointeProcessor;
use ArrayObject;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute\Uploadable;
use Vich\UploaderBundle\Mapping\Attribute\UploadableField;

/*
    - LE JUSTIFICATIF D'UNE LIGNE D'ARGENT : le reçu signé d'un planteur, le bordereau d'une
      recharge bancaire, le talon d'un chèque, la pièce qui motive une demande. C'est ce qu'un
      comptable rapproche d'un relevé, et ce qu'on ressort le jour où quelqu'un conteste.

    - LA PIÈCE PORTE SA CIBLE, et non l'inverse. Cinq colonnes nullables — versement, mouvement de
      caisse, et les trois demandes — dont EXACTEMENT UNE est renseignée, ce qu'une contrainte
      'CHECK' fige. Mettre la clé sur les cibles aurait ajouté une colonne à 'paiement' et à
      'mouvement_caisse', deux tables d'argent qu'on ne touche pas sans raison, et il aurait fallu
      cinq relations inverses ici de toute façon pour qu'une pièce sache d'où elle vient.

    - ELLE N'A PAS DE DROITS À ELLE EN LECTURE. Qui voit la ligne voit sa pièce : le contrôle se
      fait sur 'VOIR' de la CIBLE, jamais sur la pièce. Une seconde table de visibilité, posée à
      côté de celle qui existe, aurait commencé à diverger le jour où l'une des deux bouge.
      L'écriture, elle, a son action : 'JOINDRE', parce que voir un versement et pouvoir y coller
      un document ne sont pas la même permission.

    - AUCUNE URL NE MÈNE AU FICHIER. Il vit hors de la racine web, sous un nom aléatoire, et ne se
      lit que par 'GET /api/piece_jointes/{id}/fichier', qui refait le contrôle sur la cible. Le
      chemin n'est jamais publié : il ne sert qu'au serveur.

    - UNE SEULE PIÈCE PAR LIGNE, et un second dépôt REMPLACE le premier. C'est le geste réel : on
      se trompe de scan, on recommence. Plusieurs pièces par ligne demanderaient une table de
      liaison et un écran de gestion, pour un besoin que personne n'a formulé.
*/
#[ORM\Entity(repositoryClass: PieceJointeRepository::class)]
#[ORM\Table(name: 'piece_jointe')]
#[ORM\Index(name: 'idx_piece_entreprise', fields: ['entreprise'])]
/*
    - EXACTEMENT UNE CIBLE, et c'est la base qui le tient. Zéro cible ferait une pièce orpheline que
      plus aucun écran ne montre ; deux en feraient une pièce que deux lignes revendiquent, et le
      contrôle d'accès ne saurait plus laquelle interroger.
*/
#[ORM\UniqueConstraint(name: 'uniq_piece_paiement', columns: ['paiement_id'])]
#[ORM\UniqueConstraint(name: 'uniq_piece_mouvement', columns: ['mouvement_caisse_id'])]
#[ORM\UniqueConstraint(name: 'uniq_piece_demande_solde', columns: ['demande_solde_id'])]
#[ORM\UniqueConstraint(name: 'uniq_piece_demande_prelevement', columns: ['demande_prelevement_id'])]
#[ORM\UniqueConstraint(name: 'uniq_piece_demande_retrait', columns: ['demande_retrait_id'])]
#[Uploadable]
#[ApiResource(
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    normalizationContext: ['groups' => ['read:PieceJointe', 'read:Base']],
    operations: [
        /*
            - LECTURE DIRECTE FERMÉE. L'opération n'existe que pour fabriquer et résoudre l'IRI ;
              ouverte, elle laisserait parcourir les pièces de son entreprise par identifiant, sans
              passer par la ligne qui les porte et donc sans son contrôle à elle. Même patron
              qu'iTransport, et pour la même raison.
        */
        new Get(
            controller: NotFoundAction::class,
            read: false,
            output: false,
            openapi: new Operation(summary: 'hidden')
        ),
        /*
            - LE FICHIER LUI-MÊME. 'read: false' : le provider par défaut ne doit pas charger la
              pièce sous le cloisonnement d'API Platform, parce que c'est le CONTRÔLEUR qui doit
              décider — il interroge la CIBLE, pas la pièce.
        */
        new Get(
            uriTemplate: '/piece_jointes/{id}/fichier',
            requirements: ['id' => '\d+'],
            controller: PieceJointeFichierController::class,
            read: false,
            output: false,
            openapi: new Operation(
                summary: 'Le fichier d\'une pièce jointe',
                description: 'Sert le document après avoir vérifié les droits de la LIGNE qui le porte. Aucune URL publique ne mène à ce fichier',
                security: [['bearerAuth' => []]]
            )
        ),
        /*
            - LE DÉPÔT, en multipart. 'deserialize: false' : le corps n'est pas du JSON, c'est le
              processor qui lit la requête et pose le fichier.
        */
        new Post(
            inputFormats: ['multipart' => ['multipart/form-data']],
            deserialize: false,
            processor: PieceJointeProcessor::class,
            openapi: new Operation(
                summary: 'Joindre une pièce à une ligne d\'argent',
                description: 'PDF ou image, 5 Mo au plus. La cible se désigne par son type et son identifiant ; un second dépôt sur la même ligne REMPLACE le précédent',
                requestBody: new RequestBody(
                    content: new ArrayObject([
                        'multipart/form-data' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'fichier' => ['type' => 'string', 'format' => 'binary'],
                                    'ressource' => [
                                        'type' => 'string',
                                        'enum' => ['PAIEMENT', 'MOUVEMENT_CAISSE', 'DEMANDE_SOLDE', 'DEMANDE_PRELEVEMENT', 'DEMANDE_RETRAIT'],
                                    ],
                                    'ressourceId' => ['type' => 'integer'],
                                    'reference' => ['type' => 'string'],
                                    'libelle' => ['type' => 'string'],
                                ],
                                'required' => ['fichier', 'ressource', 'ressourceId'],
                            ],
                        ],
                    ])
                ),
                security: [['bearerAuth' => []]]
            )
        ),
    ],
    openapi: new Operation(security: [['bearerAuth' => []]])
)]
class PieceJointe extends EntityBase implements EntrepriseOwnedInterface
{
    /* PDF d'abord — un bordereau arrive numérisé — et les images, parce qu'au guichet on photographie un reçu avec le téléphone qu'on a. */
    public const FORMATS = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public const TAILLE_MAX = '5M';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:PieceJointe', 'read:Paiement', 'read:MouvementCaisse', 'read:DemandeSolde', 'read:DemandePrelevement', 'read:DemandeRetrait'])]
    private ?int $id = null;

    /*
        - LE FICHIER, jamais persisté : Vich le pose sur le disque et n'écrit que son nom. La
          validation vit ICI et non dans le processor — c'est la seule place où elle s'applique
          aussi aux fixtures et aux tests.
    */
    #[UploadableField(mapping: 'piece_jointe', fileNameProperty: 'chemin')]
    #[Assert\File(
        maxSize: self::TAILLE_MAX,
        mimeTypes: self::FORMATS,
        maxSizeMessage: 'Le fichier ne doit pas dépasser 5 Mo.',
        mimeTypesMessage: 'Seuls les fichiers PDF, JPEG, PNG et WEBP sont acceptés.'
    )]
    public ?File $fichier = null;

    /* Le nom sur le disque, aléatoire. Jamais publié : il n'a d'intérêt que pour le serveur, et le dire reviendrait à donner la seconde barrière. */
    #[ApiProperty(writable: false)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $chemin = null;

    /* Ce que l'utilisateur a envoyé. Il ne sert qu'à RÉAFFICHER — « recu-kouame.pdf » — et jamais à ouvrir quoi que ce soit. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['read:PieceJointe', 'read:Paiement', 'read:MouvementCaisse', 'read:DemandeSolde', 'read:DemandePrelevement', 'read:DemandeRetrait'])]
    private ?string $nomOrigine = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['read:PieceJointe', 'read:Paiement', 'read:MouvementCaisse', 'read:DemandeSolde', 'read:DemandePrelevement', 'read:DemandeRetrait'])]
    private ?string $typeMime = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['read:PieceJointe', 'read:Paiement', 'read:MouvementCaisse', 'read:DemandeSolde', 'read:DemandePrelevement', 'read:DemandeRetrait'])]
    private int $taille = 0;

    /*
        - LE NUMÉRO DE PIÈCE COMPTABLE, saisi à la main. À ne pas confondre avec
          'Paiement::reference', qui est la référence marchand de la passerelle et sert de clé
          d'idempotence au webhook : l'une vient de l'agrégateur, l'autre du classeur du comptable.
    */
    #[ORM\Column(length: 60, nullable: true)]
    #[Groups(['read:PieceJointe', 'read:Paiement', 'read:MouvementCaisse', 'read:DemandeSolde', 'read:DemandePrelevement', 'read:DemandeRetrait'])]
    private ?string $reference = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['read:PieceJointe', 'read:Paiement', 'read:MouvementCaisse', 'read:DemandeSolde', 'read:DemandePrelevement', 'read:DemandeRetrait'])]
    private ?string $libelle = null;

    /* Le cloisonnement. Posé au dépôt depuis la cible, jamais depuis la requête : c'est la ligne qui décide de qui est la pièce. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Entreprise $entreprise = null;

    #[ORM\ManyToOne]
    #[Groups(['read:PieceJointe'])]
    private ?User $deposePar = null;

    #[ORM\OneToOne(inversedBy: 'pieceJointe')]
    private ?Paiement $paiement = null;

    #[ORM\OneToOne(inversedBy: 'pieceJointe')]
    private ?MouvementCaisse $mouvementCaisse = null;

    #[ORM\OneToOne(inversedBy: 'pieceJointe')]
    private ?DemandeSolde $demandeSolde = null;

    #[ORM\OneToOne(inversedBy: 'pieceJointe')]
    private ?DemandePrelevement $demandePrelevement = null;

    #[ORM\OneToOne(inversedBy: 'pieceJointe')]
    private ?DemandeRetrait $demandeRetrait = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChemin(): ?string
    {
        return $this->chemin;
    }

    public function setChemin(?string $chemin): static
    {
        $this->chemin = $chemin;

        return $this;
    }

    public function getNomOrigine(): ?string
    {
        return $this->nomOrigine;
    }

    public function setNomOrigine(?string $nomOrigine): static
    {
        $this->nomOrigine = $nomOrigine;

        return $this;
    }

    public function getTypeMime(): ?string
    {
        return $this->typeMime;
    }

    public function setTypeMime(?string $typeMime): static
    {
        $this->typeMime = $typeMime;

        return $this;
    }

    public function getTaille(): int
    {
        return $this->taille;
    }

    public function setTaille(int $taille): static
    {
        $this->taille = $taille;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(?string $libelle): static
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): static
    {
        $this->entreprise = $entreprise;

        return $this;
    }

    public function getDeposePar(): ?User
    {
        return $this->deposePar;
    }

    public function setDeposePar(?User $deposePar): static
    {
        $this->deposePar = $deposePar;

        return $this;
    }

    public function getPaiement(): ?Paiement
    {
        return $this->paiement;
    }

    public function setPaiement(?Paiement $paiement): static
    {
        $this->paiement = $paiement;

        return $this;
    }

    public function getMouvementCaisse(): ?MouvementCaisse
    {
        return $this->mouvementCaisse;
    }

    public function setMouvementCaisse(?MouvementCaisse $mouvementCaisse): static
    {
        $this->mouvementCaisse = $mouvementCaisse;

        return $this;
    }

    public function getDemandeSolde(): ?DemandeSolde
    {
        return $this->demandeSolde;
    }

    public function setDemandeSolde(?DemandeSolde $demandeSolde): static
    {
        $this->demandeSolde = $demandeSolde;

        return $this;
    }

    public function getDemandePrelevement(): ?DemandePrelevement
    {
        return $this->demandePrelevement;
    }

    public function setDemandePrelevement(?DemandePrelevement $demandePrelevement): static
    {
        $this->demandePrelevement = $demandePrelevement;

        return $this;
    }

    public function getDemandeRetrait(): ?DemandeRetrait
    {
        return $this->demandeRetrait;
    }

    public function setDemandeRetrait(?DemandeRetrait $demandeRetrait): static
    {
        $this->demandeRetrait = $demandeRetrait;

        return $this;
    }

    /*
        - LA LIGNE QUI PORTE CETTE PIÈCE, quelle qu'elle soit. C'est elle qu'on interroge pour
          savoir qui a le droit de lire : le contrôle d'accès n'a jamais à connaître les cinq cas.
        - Nulle est une anomalie, pas un état : la contrainte de base l'interdit. Le contrôleur la
          traite quand même, parce qu'une pièce orpheline arrivée par une reprise de données doit
          donner un 404 et non une erreur 500 sur un appel à null.
    */
    public function cible(): ?object
    {
        return $this->paiement
            ?? $this->mouvementCaisse
            ?? $this->demandeSolde
            ?? $this->demandePrelevement
            ?? $this->demandeRetrait;
    }

    /* Le nom sous lequel le navigateur proposera le document. Celui d'origine s'il est là, sinon un nom composé — jamais le nom aléatoire du disque. */
    public function nomDeTelechargement(): string
    {
        if($this->nomOrigine !== null && trim($this->nomOrigine) !== '') {
            return $this->nomOrigine;
        }

        $extension = pathinfo((string) $this->chemin, PATHINFO_EXTENSION);

        return sprintf('piece-%d.%s', (int) $this->id, $extension === '' ? 'bin' : $extension);
    }
}
