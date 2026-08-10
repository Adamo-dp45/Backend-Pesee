<?php

namespace App\Repository;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PasswordResetToken>
 */
class PasswordResetTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetToken::class);
    }

    /*
        - Périme les demandes précédentes. Sans ça, cliquer trois fois sur « mot de passe oublié » laisse trois liens valables en même temps, dont deux traînent dans la boîte mail.
    */
    public function invaliderPrecedents(User $user): void
    {
        $this->createQueryBuilder('t')
            ->update()
            ->set('t.usedAt', ':maintenant')
            ->andWhere('t.user = :user')
            ->andWhere('t.usedAt IS NULL')
            ->setParameter('maintenant', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    public function findOneUtilisable(string $token): ?PasswordResetToken
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.token = :token')
            ->andWhere('t.usedAt IS NULL')
            ->andWhere('t.expiresAt > :maintenant')
            ->setParameter('token', $token)
            ->setParameter('maintenant', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }
}
