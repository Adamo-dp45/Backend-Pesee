<?php

namespace App\Domain\Service;

use App\Domain\Enum\Role;
use App\Entity\Site;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/*
    - Fabrique et renouvelle les identifiants du poste de pesée. Ils sont déposés dans un fichier de configuration que le logiciel lit au démarrage : le peseur ne saisit jamais rien.
    - Le mot de passe n'existe qu'en mémoire, le temps de la réponse qui le renvoie une fois. Le perdre n'est pas un problème, 'regenerer()' en produit un nouveau.
    - L'identifiant est dérivé du code site sur un domaine dédié, pour qu'un compte machine n'entre jamais en collision avec l'adresse d'une personne réelle.

    - CE QU'IL FAUT SAVOIR AVANT D'Y TOUCHER : l'adresse est dérivée du code À LA CRÉATION, puis elle
      ne bouge plus. Le code d'un site, lui, se modifie. Les deux divergent donc, et c'est voulu :

        · un poste s'authentifie avec cette adresse, écrite dans son fichier de configuration. La
          déplacer parce qu'on a renommé un pont bascule couperait un poste en service sans que
          personne ne l'ait demandé, et sans rien pour le dire ;
        · l'adresse affichée est donc TOUJOURS celle que le compte porte vraiment ('identifiantDe()'),
          jamais une recomposition à partir du code. Le code recomposé était faux dès le premier
          renommage, et l'écran remettait à l'installateur un identifiant qui n'ouvrait rien.

    - Conséquence : le code d'un site renommé reste occupé par son ancien compte. Un nouveau poste
      qui reprend ce code reçoit donc 'code-2@…'. C'est laid et c'est sans importance — personne ne
      le tape jamais —, alors qu'une collision, elle, coûtait une violation d'unicité sur
      'user.email', c'est-à-dire une erreur 500 à la création du site ET au renouvellement.
*/
class CompteMachineService
{
    private const DOMAINE = 'poste.webpesee.local';

    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private string $urlApi
    )
    {
    }

    /**
     * @return string le mot de passe en clair, à transmettre une seule fois
     */
    public function creer(Site $site): string
    {
        $compte = (new User())
            ->setEmail($this->identifiantLibre($site))
            ->setNom('Poste de pesée')
            ->setPrenom((string) $site->getLibellesite())
            ->setEntreprise($site->getEntreprise())
            ->setSiteMachine($site)
            ->setRole(Role::SITE);

        $motDePasse = $this->engendrerMotDePasse();
        $compte->setPassword($this->hasher->hashPassword($compte, $motDePasse));

        $site->setCompteMachine($compte);
        $this->em->persist($compte);

        return $motDePasse;
    }

    /**
     * Renouvelle le mot de passe du poste. Les jetons déjà émis restent valables jusqu'à leur expiration : pour couper un poste immédiatement, c'est le blocage du site qu'il faut utiliser.
     *
     * @return string le nouveau mot de passe en clair
     */
    public function regenerer(Site $site): string
    {
        $compte = $this->compteDe($site);

        if($compte === null) {
            return $this->creer($site); // Site déclaré avant que ce service n'existe, ou compte supprimé à la main
        }

        $motDePasse = $this->engendrerMotDePasse();

        /*
            - L'ADRESSE NE BOUGE PAS. Elle était recomposée à partir du code, ce qui la déplaçait à
              chaque renouvellement sur un site renommé : soit le poste perdait son login sans
              prévenir, soit — si un autre compte occupait déjà l'adresse visée — la requête mourait
              sur la violation d'unicité de 'user.email', en 500.
            - Seul un compte sans adresse en reçoit une : le cas d'une fiche réparée à la main.
        */
        if(($compte->getEmail() ?? '') === '') {
            $compte->setEmail($this->identifiantLibre($site));
        }

        $compte->setPassword($this->hasher->hashPassword($compte, $motDePasse));

        return $motDePasse;
    }

    /* La RÈGLE DE COMPOSITION d'une adresse neuve, et rien d'autre : ce n'est pas l'identifiant d'un poste en service, qui peut en différer. Pour celui-là, voir 'identifiantDe()'. */
    public function identifiant(Site $site): string
    {
        return strtolower((string) $site->getCode()) . '@' . self::DOMAINE;
    }

    /* L'adresse que le compte du poste porte VRAIMENT. C'est elle qu'on affiche et qu'on écrit dans le fichier de configuration : c'est la seule avec laquelle le poste pourra se connecter. */
    public function identifiantDe(Site $site): ?string
    {
        return $this->compteDe($site)?->getEmail();
    }

    /*
        - Une adresse libre pour un compte neuf : celle du code si personne ne l'occupe, sinon la
          même suffixée. Le suffixe n'est pas de la coquetterie — il évite le seul cas où la
          création d'un pont bascule échouait en 500, celui d'un code rendu par un site renommé dont
          l'ancien compte garde l'adresse.
        - 'deletedAt' n'est pas filtré, et c'est exprès : l'index unique de la base, lui, ne fait pas
          la différence. Une adresse tenue par un compte à la corbeille est tenue tout court.
    */
    private function identifiantLibre(Site $site): string
    {
        $base = $this->identifiant($site);

        if(!$this->estPrise($base)) {
            return $base;
        }

        [$local, $domaine] = explode('@', $base, 2);

        for($n = 2; $n <= 99; ++$n) {
            if(!$this->estPrise($candidat = sprintf('%s-%d@%s', $local, $n, $domaine))) {
                return $candidat;
            }
        }

        throw new UnprocessableEntityHttpException(sprintf(
            'Impossible d\'attribuer une adresse au compte machine : le code « %s » et ses variantes sont tous pris. Choisissez un autre code.',
            (string) $site->getCode()
        ));
    }

    private function estPrise(string $email): bool
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]) !== null;
    }

    private function compteDe(Site $site): ?User
    {
        return $site->getCompteMachine() ?? $this->em->getRepository(User::class)->findOneBy(['siteMachine' => $site]);
    }

    /**
     * Le contenu du fichier de configuration à déposer sur le poste.
     *
     * @return array<string, mixed>
     */
    public function configuration(Site $site, string $motDePasse): array
    {
        return [
            'api' => [
                // L'adresse RÉELLE du compte : c'est ce fichier que le poste lit pour se connecter, une adresse recomposée ne lui ouvrirait rien
                'url' => $this->urlApi,
                'login' => $this->identifiantDe($site) ?? $this->identifiant($site),
                'motdepasse' => $motDePasse,
            ],
            'site' => [
                'code' => $site->getCode(),
                'libelle' => $site->getLibellesite(),
            ],
            'genere_le' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    // Jamais tapé à la main, donc pas mémorisable. Sans les caractères qui se confondent à l'œil
    private function engendrerMotDePasse(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $dernier = strlen($alphabet) - 1;

        $motDePasse = '';

        for($i = 0; $i < 24; ++$i) {
            $motDePasse .= $alphabet[random_int(0, $dernier)];
        }

        return $motDePasse;
    }
}
