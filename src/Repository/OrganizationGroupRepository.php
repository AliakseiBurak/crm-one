<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrganizationGroup;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizationGroup>
 */
class OrganizationGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationGroup::class);
    }

    /**
     * Groups visible to a manager: created by self OR assigned via GroupAssignment.
     */
    public function findForManager(User $manager): array
    {
        return $this->createQueryBuilder('g')
            ->leftJoin('g.assignments', 'ga')
            ->where('g.createdBy = :manager')
            ->orWhere('ga.user = :manager')
            ->setParameter('manager', $manager)
            ->getQuery()
            ->getResult();
    }

    /**
     * All groups (admin view).
     */
    public function findAllGroups(): array
    {
        return $this->createQueryBuilder('g')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Groups created by a specific manager.
     */
    public function findCreatedBy(User $manager): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.createdBy = :manager')
            ->setParameter('manager', $manager)
            ->getQuery()
            ->getResult();
    }

    /**
     * Назначена ли группа менеджеру (GroupAssignment) — запрос к БД, чтобы не
     * зависеть от уже загруженной коллекции группы.
     */
    public function isAssignedTo(OrganizationGroup $group, User $manager): bool
    {
        $row = $this->createQueryBuilder('g')
            ->select('1')
            ->innerJoin('g.assignments', 'a')
            ->where('g.id = :groupId')
            ->andWhere('a.user = :manager')
            ->setParameter('groupId', $group->id)
            ->setParameter('manager', $manager)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $row;
    }
}
