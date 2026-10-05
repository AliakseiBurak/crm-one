<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\OrganizationHide;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizationHide>
 */
class OrganizationHideRepository extends ServiceEntityRepository
{
    /**
     * Ключи сортировки реестра: name/createdAt/industry — колонки
     * организации, manager — email последнего скрывающего, hiddenAt — дата
     * последнего скрытия. Всё в SQL: страницу выбирает база
     * (change organizations-pagination).
     */
    private const REGISTRY_SORT_COLUMNS = [
        'name' => 'o.name',
        'createdAt' => 'o.createdAt',
        'industry' => 'o.industry',
        'manager' => 'hmanager.email',
        'hiddenAt' => 'h.hiddenAt',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationHide::class);
    }

    /**
     * Все записи скрытия организации (spec organization-hiding: реестр
     * «Показать всем»).
     *
     * @return OrganizationHide[]
     */
    public function findForOrganization(Organization $organization): array
    {
        return $this->createQueryBuilder('h')
            ->where('h.organization = :organization')
            ->setParameter('organization', $organization)
            ->orderBy('h.hiddenAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Все организации, скрытые от менеджера.
     *
     * @return OrganizationHide[]
     */
    public function findForManager(User $manager): array
    {
        return $this->createQueryBuilder('h')
            ->where('h.manager = :manager')
            ->setParameter('manager', $manager)
            ->orderBy('h.hiddenAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByOrganizationAndManager(Organization $organization, User $manager): ?OrganizationHide
    {
        return $this->createQueryBuilder('h')
            ->where('h.organization = :organization')
            ->andWhere('h.manager = :manager')
            ->setParameter('organization', $organization)
            ->setParameter('manager', $manager)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Одна страница реестра — по одной строке на организацию: запись,
     * которой нет в выборке, показана последней скрывающей, поэтому
     * сортировки manager и hiddenAt считаются по ней (change
     * organizations-pagination). Сами записи скрытия организаций страницы
     * для отображения подтягивает findForOrganizations().
     *
     * @return OrganizationHide[]
     */
    public function findRegistryPage(string $sort, string $dir, int $offset, int $limit): array
    {
        $qb = $this->createQueryBuilder('h')
            ->select('h')
            ->innerJoin('h.organization', 'o')
            ->leftJoin('h.manager', 'hmanager');
        $this->applyLatestHideScope($qb);
        $this->applyRegistryOrder($qb, $sort, $dir);

        if ($offset > 0) {
            $qb->setFirstResult($offset);
        }
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Всего скрытых организаций реестра. Записей может быть больше, чем
     * организаций (одну организацию можно скрыть от нескольких менеджеров),
     * но страницы считаются по организациям — иначе переход по страницам
     * открывал бы пустые таблицы.
     */
    public function countRegistry(): int
    {
        $qb = $this->createQueryBuilder('h')
            ->select('COUNT(h.id)');
        $this->applyLatestHideScope($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Записи скрытия организаций страницы — по убыванию даты, при равенстве
     * по id: так «последний скрывающий» определён однозначно даже когда
     * обе записи созданы в одну секунду.
     *
     * @param Organization[] $organizations
     *
     * @return OrganizationHide[]
     */
    public function findForOrganizations(array $organizations): array
    {
        if ([] === $organizations) {
            return [];
        }

        return $this->createQueryBuilder('h')
            ->innerJoin('h.organization', 'o')
            ->where('o.id IN (:organizationIds)')
            ->setParameter('organizationIds', array_map(
                static fn(Organization $organization): int => (int) $organization->id,
                $organizations,
            ))
            ->orderBy('h.hiddenAt', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Только последняя запись скрытия каждой организации: NOT EXISTS не
     * находит записи «новее» выбранной (дата скрытия, при равенстве — id).
     * Альтернатива «скалярный подзапрос с ORDER BY … LIMIT 1» в DQL
     * невозможна, а скалярный подзапрос без LIMIT MySQL отвергает, если он
     * может вернуть больше одной строки.
     */
    private function applyLatestHideScope(QueryBuilder $qb): void
    {
        $qb->andWhere('NOT EXISTS (
            SELECT 1 FROM App\Entity\OrganizationHide newer
            WHERE newer.organization = h.organization
              AND (newer.hiddenAt > h.hiddenAt OR (newer.hiddenAt = h.hiddenAt AND newer.id > h.id))
        )');
    }

    /**
     * Первичный ключ организации — последний ключ ORDER BY: названия
     * организаций не уникальны, без добивки одна и та же организация могла
     * бы попасть на соседние страницы.
     */
    private function applyRegistryOrder(QueryBuilder $qb, string $sort, string $dir): void
    {
        $column = self::REGISTRY_SORT_COLUMNS[$sort] ?? self::REGISTRY_SORT_COLUMNS['name'];
        $qb->orderBy($column, 'DESC' === strtoupper($dir) ? 'DESC' : 'ASC')
            ->addOrderBy('o.id', 'ASC');
    }
}
