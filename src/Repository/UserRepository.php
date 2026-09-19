<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Все администраторы и менеджеры (персонал, способный совершать звонки),
     * отсортированные по email. Используется для выбора автора звонка.
     *
     * @return User[]
     */
    public function findAdminsAndManagers(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.role IN (:roles)')
            ->setParameter('roles', [UserRole::Admin, UserRole::Manager])
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Все менеджеры (без администраторов), отсортированные по email.
     * Страница назначения группы показывает только менеджеров: администратор
     * видит все группы без GroupAssignment (ADR-0008).
     *
     * @return User[]
     */
    public function findManagers(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.role = :role')
            ->setParameter('role', UserRole::Manager)
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Все администраторы системы. Используется для отправки уведомлений.
     *
     * @return User[]
     */
    public function findAdmins(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.role = :role')
            ->setParameter('role', UserRole::Admin->value)
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByLoginWithNoPassword(string $login): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.login = :login')
            ->andWhere('u.passwordHash = :empty')
            ->setParameter('login', $login)
            ->setParameter('empty', '')
            ->getQuery()
            ->getOneOrNullResult();
    }
}
