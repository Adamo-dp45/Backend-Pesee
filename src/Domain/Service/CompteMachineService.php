<?php

namespace App\Domain\Service;

use App\Domain\Enum\Role;
use App\Entity\Site;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/*
    - Fabrique et renouvelle les identifiants du poste de pesée. Ils sont déposés dans un fichier de configuration que le logiciel lit au démarrage : le peseur ne saisit jamais rien.
    - Le mot de passe n'existe qu'en mémoire, le temps de la réponse qui le renvoie une fois. Le perdre n'est pas un problème, 'regenerer()' en produit un nouveau.
    - L'identifiant est dérivé du code site sur un domaine dédié, pour qu'un compte machine n'entre jamais en collision avec l'adresse d'une personne réelle.
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
            ->setEmail($this->identifiant($site))
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
        $compte = $site->getCompteMachine() ?? $this->em->getRepository(User::class)->findOneBy(['siteMachine' => $site]);

        if($compte === null) {
            return $this->creer($site); // Site déclaré avant que ce service n'existe, ou compte supprimé à la main
        }

        $motDePasse = $this->engendrerMotDePasse();
        $compte
            ->setEmail($this->identifiant($site))
            ->setPassword($this->hasher->hashPassword($compte, $motDePasse));

        return $motDePasse;
    }

    public function identifiant(Site $site): string
    {
        return strtolower((string) $site->getCode()) . '@' . self::DOMAINE;
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
                'url' => $this->urlApi,
                'login' => $this->identifiant($site),
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
