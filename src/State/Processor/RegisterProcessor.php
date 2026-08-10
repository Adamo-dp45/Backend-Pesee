<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\Role;
use App\Entity\Entreprise;
use App\Entity\Input\RegisterInput;
use App\Entity\User;
use App\Repository\EntrepriseRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/*
    - Inscription d'une entreprise et de son premier administrateur.
    - Une seule transaction : au moindre incident on ne veut pas d'une entreprise sans personne pour y accéder, que seul un passage en base rattraperait.
    - Le premier compte est marqué « fondateur ». Ce n'est pas décoratif : 'UserGuard' interdit à un pair de le suspendre ou de le rétrograder, sinon une entreprise peut se verrouiller hors de son propre outil.
    - Plus de limiteur de débit : il gardait un point d'entrée public contre les entreprises fantômes. L'opération exigeant désormais le droit de créer une entreprise, seul le super administrateur l'atteint, et brider son travail n'aurait plus de sens.
*/

/**
 * @implements ProcessorInterface<RegisterInput, User>
 */
class RegisterProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<mixed, mixed> $processor
     */
    public function __construct(
        private ProcessorInterface $processor,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private UserRepository $userRepository,
        private EntrepriseRepository $entrepriseRepository
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        // Explicite plutôt qu'une violation d'index : le message dit lequel des deux champs coince
        if($this->userRepository->findOneByEmail((string) $data->email) !== null) {
            throw new ConflictHttpException('Un compte existe déjà avec cette adresse e-mail.');
        }

        if($this->entrepriseRepository->findOneByCode((string) $data->codeentreprise) !== null) {
            throw new ConflictHttpException('Ce code entreprise est déjà utilisé.');
        }

        $fondateur = null;

        $this->em->wrapInTransaction(function() use ($data, &$fondateur): void {
            $entreprise = (new Entreprise())
                ->setNom((string) $data->nomEntreprise)
                ->setCodeentreprise((string) $data->codeentreprise)
                ->setContact1((string) $data->contact1)
                ->setContact2($data->contact2)
                ->setAdresse($data->adresse)
                ->setEmail($data->emailEntreprise);

            $this->em->persist($entreprise);

            $fondateur = (new User())
                ->setEmail((string) $data->email)
                ->setNom((string) $data->nom)
                ->setPrenom((string) $data->prenom)
                ->setTelephone($data->telephone)
                ->setEntreprise($entreprise)
                ->setRole(Role::ADMIN)
                ->setFondateur(true);

            $fondateur->setPassword($this->hasher->hashPassword($fondateur, (string) $data->motDePasse));

            $this->em->persist($fondateur);
        });

        return $this->processor->process($fondateur, $operation, $uriVariables, $context);
    }
}
