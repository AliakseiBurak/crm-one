<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrganizationGroup;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizationGroup>
 */
class OrganizationGroupRepository extends ServiceEntityRepository
{
    /**
     * Ключ сортировки списка групп: имя группы либо email создателя. Оба
     * сопоставляются колонкам, поэтому сортировка целиком в SQL — страницу
     * выбирает база (change organizations-pagination).
     */
    private const SORT_COLUMNS = [
        'name' => 'g.name',
        'creator' => 'creator.email',
    ];

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
     * Все группы области управления пользователя, отсортированные в SQL. null
     * вместо $user — администратор, которому доступны все группы (ADR-0008).
     *
     * Список групп не пагинируется: у компании десятки собственных групп, а
     * не сотни, и постраничный вывод здесь не окупает ни одного своего
     * недостатка (change organizations-pagination).
     *
     * @return OrganizationGroup[]
     */
    public function findVisible(?User $user, string $sort, string $dir): array
    {
        $qb = $this->createQueryBuilder('g')
            ->select('g')
            ->leftJoin('g.createdBy', 'creator');
        $this->applyScope($qb, $user);
        $this->applyOrder($qb, $sort, $dir);

        return $qb->getQuery()->getResult();
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

    /**
     * Область управления: администратору все группы, менеджеру созданные им
     * и назначенные ему (ADR-0011). Назначение (user_id, group_id) —
     * составной первичный ключ, поэтому JOIN не дублирует строки.
     */
    private function applyScope(QueryBuilder $qb, ?User $user): void
    {
        if (null === $user) {
            return;
        }

        $qb->leftJoin('g.assignments', 'ga')
            ->andWhere('g.createdBy = :scopeUser OR ga.user = :scopeUser')
            ->setParameter('scopeUser', $user);
    }

    /**
     * Первичный ключ строки — последний ключ ORDER BY: названия групп не
     * уникальны, без добивки одна и та же группа могла бы повлиять на порядок
     * соседних строк.
     */
    private function applyOrder(QueryBuilder $qb, string $sort, string $dir): void
    {
        $column = self::SORT_COLUMNS[$sort] ?? self::SORT_COLUMNS['name'];
        $qb->orderBy($column, 'DESC' === strtoupper($dir) ? 'DESC' : 'ASC')
            ->addOrderBy('g.id', 'ASC');
    }
}
