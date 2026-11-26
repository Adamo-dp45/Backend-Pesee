<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\FreeTextQueryFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrFilter;
use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use App\Domain\Enum\Role;
use App\Filter\RolePrincipalFilter;
use App\Domain\Enum\StatutActivation;
use App\Entity\Input\AttribuerRoleInput;
use App\Entity\Input\ChangerMotDePasseInput;
use App\Entity\Input\MotDePasseOublieInput;
use App\Entity\Input\RegisterInput;
use App\Entity\Input\ReinitialiserMotDePasseInput;
use App\Entity\Interface\EntrepriseOwnedInterface;
use App\Repository\UserRepository;
use App\State\Processor\AttribuerRoleProcessor;
use App\State\Processor\BasculerStatutUserProcessor;
use App\State\Processor\ChangerMotDePasseProcessor;
use App\State\Processor\MotDePasseOublieProcessor;
use App\State\Processor\RegisterProcessor;
use App\State\Processor\ReinitialiserMotDePasseProcessor;
use App\State\Processor\UserProcessor;
use App\State\Provider\GestionCompteProvider;
use App\State\Provider\MeProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - Deux natures de comptes cohabitent : les humains, et les comptes machine des ponts bascules ('ROLE_SITE') dont les identifiants sont déposés dans le fichier de configuration du poste. Le peseur ne s'authentifie jamais à la main.

    - N'étend PAS 'EntityBase' : un compte ne se met pas à la corbeille, il se suspend. Lui donner un 'deletedAt' inventerait un second état de désactivation, et le jour où les deux divergeraient personne ne saurait lequel fait foi. Il porte donc ses seules colonnes d'audit.
*/
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_user_email', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe déjà avec cette adresse e-mail')]
#[ApiResource(
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    normalizationContext: ['groups' => ['read:User', 'read:Base']],
    denormalizationContext: ['groups' => ['write:User']],
    paginationItemsPerPage: 25,
    paginationClientItemsPerPage: true,
    order: ['nom' => 'ASC'],
    operations: [
        new Post( /*
            - Inscription RÉSERVÉE au super administrateur, et non en libre-service : c'est lui qui décide qui entre sur la plateforme. Le droit demandé est celui de créer une entreprise, puisque c'est ce que fait l'opération — le compte fondateur n'en est que la conséquence.
        */
            uriTemplate: '/register',
            security: "is_granted('CREER', 'Entreprise')",
            status: Response::HTTP_CREATED,
            input: RegisterInput::class,
            denormalizationContext: ['groups' => ['write:Register']],
            processor: RegisterProcessor::class,
            openapi: new Operation(
                summary: 'Inscription d\'une entreprise',
                description: 'Crée une entreprise et son premier administrateur, qui en devient le fondateur. Réservé au super administrateur',
                security: [['bearerAuth' => []]]
            )
        ),
        new GetCollection(
            security: "is_granted('VOIR', 'User')",
            // 'gestion' : ce que le compte connecté peut faire sur chaque ligne, croisé par le provider
            normalizationContext: ['groups' => ['read:User', 'read:Base', 'read:User:gestion']],
            provider: GestionCompteProvider::class,
            openapi: new Operation(
                summary: 'Liste des utilisateurs',
                description: 'Les comptes de son entreprise. Les comptes machine des ponts bascules n\'y figurent pas',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get(
            security: "is_granted('VOIR', object)",
            requirements: ['id' => '\d+'],
            normalizationContext: ['groups' => ['read:User', 'read:Base', 'read:User:gestion']],
            provider: GestionCompteProvider::class,
            openapi: new Operation(
                summary: 'L\'utilisateur',
                description: 'Permet de voir un compte',
                security: [['bearerAuth' => []]]
            )
        ),
        new Post( // Le rôle se choisit à la création ; ensuite il a son propre verbe
            security: "is_granted('CREER', 'User')",
            denormalizationContext: ['groups' => ['write:User', 'write:User:creation']],
            processor: UserProcessor::class,
            openapi: new Operation(
                summary: 'Création d\'un utilisateur',
                description: 'Permet de créer un administrateur, un agent ou un opérateur dans son entreprise',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch(
            security: "is_granted('MODIFIER', object)",
            requirements: ['id' => '\d+'],
            processor: UserProcessor::class,
            openapi: new Operation(
                summary: 'Modification d\'un utilisateur',
                description: 'Permet de modifier les coordonnées d\'un compte. Le rôle a son propre verbe',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch( /*
            - Verbe dédié, et non un champ de la modification : mélangé au reste, corriger un nom suffisait à se promouvoir soi-même
        */
            uriTemplate: '/users/{id}/role',
            security: "is_granted('PROMOUVOIR', object)",
            requirements: ['id' => '\d+'],
            input: AttribuerRoleInput::class,
            denormalizationContext: ['groups' => ['write:User:role']],
            processor: AttribuerRoleProcessor::class,
            openapi: new Operation(
                summary: 'Changement du rôle d\'un utilisateur',
                description: 'On ne change ni son propre rôle, ni celui d\'un fondateur, ni celui d\'un super administrateur',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch( // 'JWTSubscriber' relit le compte à chaque requête : l'effet est immédiat
            uriTemplate: '/users/{id}/activation',
            security: "is_granted('BLOQUER', object)",
            requirements: ['id' => '\d+'],
            input: false,
            processor: BasculerStatutUserProcessor::class,
            openapi: new Operation(
                summary: 'Suspension ou réactivation d\'un utilisateur',
                description: 'Bascule le statut du compte. Un jeton déjà émis ne survit pas à la suspension',
                security: [['bearerAuth' => []]]
            )
        ),
        new Get(
            uriTemplate: '/me',
            security: "is_granted('ROLE_USER')",
            provider: MeProvider::class,
            // Le seul endroit qui publie 'droits' : c'est le compte connecté qui les demande pour lui-même
            normalizationContext: ['groups' => ['read:User', 'read:Base', 'read:User:me']],
            openapi: new Operation(
                summary: 'Mon compte',
                description: 'Permet de voir le compte connecté, son rôle et son entreprise',
                security: [['bearerAuth' => []]]
            )
        ),
        new Patch( /*
            - Sans '{id}' dans l'URI, API Platform ne sait pas quel objet modifier et répond 404 : c'est le provider qui lui désigne l'utilisateur courant
        */
            uriTemplate: '/me',
            uriVariables: [], // Sans cela l'opération n'est pas reconnue comme portant un élément
            read: true,
            security: "is_granted('ROLE_USER')",
            provider: MeProvider::class,
            denormalizationContext: ['groups' => ['write:User:profil']], // Ni rôle, ni entreprise, ni statut
            processor: UserProcessor::class,
            openapi: new Operation(
                summary: 'Modification de mon profil',
                description: 'Permet de modifier son nom, son prénom, son e-mail et son téléphone',
                security: [['bearerAuth' => []]]
            )
        ),
        new Post(
            uriTemplate: '/me/mot-de-passe',
            security: "is_granted('ROLE_USER')",
            input: ChangerMotDePasseInput::class,
            denormalizationContext: ['groups' => ['write:User:motdepasse']],
            processor: ChangerMotDePasseProcessor::class,
            openapi: new Operation(
                summary: 'Changement de mon mot de passe',
                description: 'Le mot de passe actuel est exigé, une session ouverte ne suffit pas',
                security: [['bearerAuth' => []]]
            )
        ),
        new Post( // Réponse identique que l'adresse existe ou non, pour ne pas énumérer les comptes
            uriTemplate: '/mot-de-passe/oubli',
            security: "is_granted('PUBLIC_ACCESS')",
            status: Response::HTTP_ACCEPTED,
            input: MotDePasseOublieInput::class,
            output: false,
            denormalizationContext: ['groups' => ['write:User:oubli']],
            processor: MotDePasseOublieProcessor::class,
            openapi: new Operation(
                summary: 'Demande de réinitialisation du mot de passe',
                description: 'Envoie un lien valable une heure. Répond 202 même si l\'adresse est inconnue'
            )
        ),
        new Post(
            uriTemplate: '/mot-de-passe/reinitialisation',
            security: "is_granted('PUBLIC_ACCESS')",
            status: Response::HTTP_NO_CONTENT,
            input: ReinitialiserMotDePasseInput::class,
            output: false,
            denormalizationContext: ['groups' => ['write:User:reinitialisation']],
            processor: ReinitialiserMotDePasseProcessor::class,
            openapi: new Operation(
                summary: 'Réinitialisation du mot de passe',
                description: 'Consomme le jeton reçu par e-mail. Le lien ne sert qu\'une fois'
            )
        ),
    ],
    openapi: new Operation(security: [['bearerAuth' => []]])
)]
#[ApiFilter(SearchFilter::class, properties: [
    'nom' => 'partial',
    'prenom' => 'partial',
    'email' => 'partial',
    'statut' => 'exact',
    'entreprise' => 'exact',
])]
/*
    - UNE SEULE RECHERCHE POUR QUATRE COLONNES. On cherche un compte avec ce qu'on a sous la main :
      un nom entendu au téléphone, une adresse lue dans un message, un numéro rappelé par
      quelqu'un. Un filtre par colonne n'en interroge qu'une, et rend une liste vide sans jamais
      dire qu'il n'a pas regardé ailleurs.
    - Même montage que la recherche des catégories : un 'OrFilter' sur des correspondances
      partielles, posé sous une clé unique.
*/
#[QueryParameter(
    key: 'recherche',
    filter: new FreeTextQueryFilter(new OrFilter(new PartialSearchFilter())),
    properties: ['nom', 'prenom', 'email', 'telephone'],
)]
/* Le rôle vit dans une colonne JSON : le filtre natif ne saurait pas y regarder. Voir 'RolePrincipalFilter'. */
#[ApiFilter(RolePrincipalFilter::class)]
#[ApiFilter(OrderFilter::class, properties: ['nom', 'email', 'createdAt'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EntrepriseOwnedInterface
{
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['read:Base'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'created_by', type: 'integer', nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(name: 'updated_by', type: 'integer', nullable: true)]
    private ?int $updatedBy = null;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /* 'read:Paiement' : l'auteur d'un versement se sérialisait AVEC SON LIEN ET RIEN D'AUTRE — un objet qui a l'air rempli et ne porte aucun nom. 'read:PieceJointe' : celui qui a déposé le justificatif, pour la même raison. */
    #[Groups(['read:User', 'read:Site', 'read:MouvementCaisse', 'read:DemandeSolde', 'read:DemandeRetrait', 'read:Paiement', 'read:PieceJointe'])]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'L\'adresse e-mail est obligatoire')]
    #[Assert\Email(message: 'Cette adresse e-mail n\'est pas valide')]
    #[Groups(['read:User', 'write:User', 'write:User:profil', 'read:Site'])]
    private ?string $email = null;

    /**
     * @var list<string>
     */
    /*
        - 'write:User:creation' et non 'write:User' : changer un rôle est un acte distinct, avec ses propres règles, qui passe par '/users/{id}/role'. Mélangé à la modification des coordonnées, il suffisait de corriger son nom pour se retrouver administrateur.
    */
    #[ORM\Column]
    #[Groups(['read:User', 'write:User:creation'])]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null; // Jamais exposé : c'est 'plainPassword' qui reçoit la saisie

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire')]
    #[Assert\Length(min: 2, max: 255)]
    #[Groups(['read:User', 'write:User', 'write:User:profil', 'read:Site'])]
    private ?string $nom = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le prénom est obligatoire')]
    #[Assert\Length(min: 2, max: 255)]
    #[Groups(['read:User', 'write:User', 'write:User:profil', 'read:Site'])]
    private ?string $prenom = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(min: 8, max: 30)]
    #[Groups(['read:User', 'write:User', 'write:User:profil'])]
    private ?string $telephone = null;

    #[ORM\Column(enumType: StatutActivation::class, options: ['default' => 'ACTIF'])]
    #[Groups(['read:User'])]
    private StatutActivation $statut = StatutActivation::ACTIF;

    /*
        - Le compte qui a inscrit l'entreprise. Ni suspendable ni supprimable par un autre
          administrateur : sans ce garde-fou, deux administrateurs peuvent se suspendre l'un
          l'autre et l'entreprise se retrouve verrouillée hors de son propre outil.
    */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['read:User'])]
    private bool $fondateur = false;

    #[ORM\ManyToOne(inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['read:User'])]
    private ?Entreprise $entreprise = null; // Nul pour le super administrateur, qui n'appartient à aucune entreprise

    /*
        - Renseigné pour les seuls comptes machine : le pont bascule dont ce compte pousse les
          pesées. C'est cette relation qui porte l'identité du tenant à la synchronisation — on ne
          fait pas confiance au code présent dans le corps de la requête.
    */
    #[ORM\OneToOne(inversedBy: 'compteMachine')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Site $siteMachine = null;

    /**
     * Les ponts bascules dont cet utilisateur tient la caisse.
     *
     * @var Collection<int, Site>
     */
    #[ORM\OneToMany(targetEntity: Site::class, mappedBy: 'operateur')]
    private Collection $sites;

    /**
     * @var Collection<int, PasswordResetToken>
     */
    #[ORM\OneToMany(targetEntity: PasswordResetToken::class, mappedBy: 'user')]
    private Collection $passwordResetTokens;

    /*
        - Obligatoire à la CRÉATION seulement : 'UserProcessor' refuse un compte sans mot de passe, et une modification qui n'y touche pas laisse le champ vide. Un 'NotBlank' inconditionnel empêcherait donc de corriger un simple numéro de téléphone.
        - Les règles de composition valent pour tout le monde, y compris le super administrateur : ces comptes autorisent des virements.
        - Six caractères, c'est LE minimum de l'application : la même valeur est reprise à l'inscription, au changement et à la réinitialisation. Les faire diverger revenait à accepter une saisie côté web pour la voir refusée par l'API.
        - Il n'y a PLUS de contrôle contre les fuites de mots de passe connues : à six caractères il refusait presque tout, et le message parlait de fuites de données là où la vraie cause était la longueur. La longueur et la composition sont donc les seules règles, et elles sont entièrement vérifiables hors ligne — ce qui vaut aussi pour un poste de terrain sans connexion sortante.
    */
    #[Assert\Length(min: 6, max: 255, minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères')]
    #[Assert\Regex(
        pattern: '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
        message: 'Le mot de passe doit contenir au moins une minuscule, une majuscule et un chiffre'
    )]
    #[Groups(['write:User'])]
    private ?string $plainPassword = null; // Non persisté : transporte le mot de passe en clair jusqu'au hachage

    /*
        - Les droits du rôle de ce compte, À PLAT ('SITE_VOIR', 'PRODUIT_MODIFIER'…), posés par
          'MeProvider' et renvoyés par '/me' seulement. Non persisté : 'Droits' en est la source,
          la base n'en sait rien.
        - C'est ce qui remplace la copie manuscrite que le frontend tenait de son côté : il demande
          désormais ce qu'il a le droit d'afficher au lieu de le déduire du rôle.
    */
    /** @var list<string>|null */
    private ?array $droits = null;

    /*
        - Ce que le compte CONNECTÉ peut faire sur CELUI-CI, et pourquoi il ne peut rien quand c'est
          le cas. Posé par 'GestionCompteProvider', qui seul connaît l'acteur.
        - La question « a-t-il le droit d'agir sur des comptes ? » se répond avec la table ; « sur
          CELUI-LÀ ? » demande la hiérarchie de 'UserGuard'. Le frontend recopiait cette seconde
          règle ; il reçoit désormais le verdict, motif compris.
    */
    /** @var list<string> */
    private array $droitsSurCeCompte = [];

    private ?string $motifNonGerable = null;

    /*
        - Les rôles que CE compte peut attribuer, avec leur libellé, pour la liste déroulante du
          frontend. Posé par 'MeProvider' : la règle vit dans 'UserGuard', pas dans deux endroits.
    */
    /** @var array<string, string> */
    private array $rolesAttribuables = [];

    public function __construct()
    {
        $this->sites = new ArrayCollection();
        $this->passwordResetTokens = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower(trim($email));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER'; // Tout compte authentifié le porte au minimum

        return array_values(array_unique(array_filter($roles)));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique($roles));

        return $this;
    }

    public function setRole(Role $role): static
    {
        return $this->setRoles([$role->value]);
    }

    public function aLeRole(Role $role): bool
    {
        return in_array($role->value, $this->getRoles(), true);
    }

    /*
        - Le rôle métier : le premier rôle déclaré hors 'ROLE_USER'. Sert à l'affichage et aux
          listes déroulantes ; les décisions d'accès passent par 'AccesVoter'.
    */
    public function getRolePrincipal(): ?Role
    {
        foreach($this->roles as $role) {
            if(($cas = Role::tryFrom($role)) !== null) {
                return $cas;
            }
        }

        return null;
    }

    public function estCompteMachine(): bool
    {
        return $this->aLeRole(Role::SITE);
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;

        return $this;
    }

    #[Groups(['read:User', 'read:Site', 'read:MouvementCaisse', 'read:DemandeSolde', 'read:DemandePrelevement', 'read:MouvementEpargne', 'read:DemandeRetrait', 'read:Paiement', 'read:PieceJointe'])]
    public function getNomComplet(): string
    {
        return trim(($this->prenom ?? '') . ' ' . ($this->nom ?? ''));
    }

    /*
        - Le rôle et son libellé sous une clé stable, pour que le frontend n'ait pas à fouiller le tableau 'roles' ni à recopier la correspondance des libellés.
    */
    #[Groups(['read:User'])]
    public function getRoleLibelle(): ?string
    {
        return $this->getRolePrincipal()?->libelle();
    }

    /*
        - Ce que ce compte a le droit de faire, sous la forme que le frontend interroge. Il ne
          figure QUE dans '/me' : la liste des utilisateurs n'a pas à publier les droits de chacun,
          et personne d'autre que l'intéressé n'en a l'usage.
        - Ce n'est pas une autorisation, c'est une plausibilité : l'API refait le calcul complet,
          périmètre compris, à chaque appel.
    */
    /** @return list<string> */
    #[Groups(['read:User:me'])]
    public function getDroits(): array
    {
        return $this->droits ?? [];
    }

    /** @param list<string> $droits */
    public function setDroits(array $droits): static
    {
        $this->droits = $droits;

        return $this;
    }

    /*
        - Les actions que l'utilisateur connecté peut exercer sur ce compte : la table et la
          hiérarchie déjà croisées. Le frontend n'a plus qu'à chercher 'MODIFIER' dans la liste.
    */
    /** @return list<string> */
    #[Groups(['read:User:gestion'])]
    public function getDroitsSurCeCompte(): array
    {
        return $this->droitsSurCeCompte;
    }

    /** @param list<string> $actions */
    public function setDroitsSurCeCompte(array $actions): static
    {
        $this->droitsSurCeCompte = $actions;

        return $this;
    }

    /*
        - Pourquoi ce compte ne se gère pas d'ici. Une fiche sans le moindre bouton ressemble à une
          panne : il faut pouvoir dire « c'est le vôtre », « c'est le fondateur », « c'est un
          compte de pont bascule ».
    */
    #[Groups(['read:User:gestion'])]
    public function getMotifNonGerable(): ?string
    {
        return $this->motifNonGerable;
    }

    public function setMotifNonGerable(?string $motif): static
    {
        $this->motifNonGerable = $motif;

        return $this;
    }

    /** @return array<string, string> */
    #[Groups(['read:User:me'])]
    public function getRolesAttribuables(): array
    {
        return $this->rolesAttribuables;
    }

    /**
     * LES RÔLES QU'ON PEUT RENCONTRER DANS UNE LISTE DE COMPTES, pour en filtrer une.
     *
     * Distinct des rôles ATTRIBUABLES, et la différence compte : un agent n'encadre que des
     * opérateurs, mais il VOIT les agents et les administrateurs de son entreprise. Lui proposer
     * la seule liste de ce qu'il peut attribuer ferait un filtre qui tait des valeurs présentes à
     * l'écran — on chercherait longtemps avant de soupçonner le filtre.
     *
     * Le compte machine en est écarté : il ne figure dans aucune liste d'utilisateurs.
     *
     * @return array<string, string>
     */
    #[Groups(['read:User:me'])]
    public function getRolesFiltrables(): array
    {
        $roles = [];

        foreach(Role::cases() as $role) {
            if($role !== Role::SITE) {
                $roles[$role->value] = $role->libelle();
            }
        }

        return $roles;
    }

    /** @param array<string, string> $roles */
    public function setRolesAttribuables(array $roles): static
    {
        $this->rolesAttribuables = $roles;

        return $this;
    }

    /*
        - Les ponts bascules dont ce compte tient la caisse. Un libellé par site, pas la collection : la liste des utilisateurs ne doit pas hydrater les sites de chacun.
    */
    /** @return list<string> */
    #[Groups(['read:User'])]
    public function getSitesGeres(): array
    {
        return array_values(array_filter($this->sites->map(static fn(Site $site) => $site->getLibellesite())->toArray()));
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getStatut(): StatutActivation
    {
        return $this->statut;
    }

    public function setStatut(StatutActivation $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function estActif(): bool
    {
        return $this->statut->estActif();
    }

    #[Groups(['read:User'])]
    public function getStatutLibelle(): string
    {
        return $this->statut->libelle();
    }

    public function isFondateur(): bool
    {
        return $this->fondateur;
    }

    public function setFondateur(bool $fondateur): static
    {
        $this->fondateur = $fondateur;

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

    public function getSiteMachine(): ?Site
    {
        return $this->siteMachine;
    }

    public function setSiteMachine(?Site $siteMachine): static
    {
        $this->siteMachine = $siteMachine;

        return $this;
    }

    /**
     * @return Collection<int, Site>
     */
    public function getSites(): Collection
    {
        return $this->sites;
    }

    /**
     * @return Collection<int, PasswordResetToken>
     */
    public function getPasswordResetTokens(): Collection
    {
        return $this->passwordResetTokens;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?int $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getUpdatedBy(): ?int
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?int $updatedBy): static
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }

    /*
        - Le mot de passe haché ne doit pas transiter dans la session : on le remplace par une
          empreinte, que Symfony compare au rechargement pour détecter un changement.
    */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0" . self::class . "\0password"] = hash('crc32c', (string) $this->password);

        return $data;
    }

    public function __toString(): string
    {
        return $this->getNomComplet();
    }
}
