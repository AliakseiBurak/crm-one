<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\DashboardOrganizationRow;
use App\Dto\Pagination;
use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\OrganizationGroup;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Organization>
 */
class OrganizationRepository extends ServiceEntityRepository
{
    /**
     * Сортировки по колонке организации. Пустых значений у них нет, поэтому
     * направление выбирается напрямую.
     */
    private const DASHBOARD_SORT_COLUMNS = [
        'name' => 'o.name',
        'isActive' => 'o.isActive',
    ];

    /**
     * Сортировки, где ключ может быть пустым. Значение — либо псевдоним
     * скалярного подзапроса выбора (lastMadeAt, nextScheduledAt), либо
     * колонка организации (o.optedOutAt). Пустое значение уходит в конец при
     * любом направлении, поэтому первым ключом ORDER BY всегда идёт
     * `CASE WHEN <ключ> IS NULL THEN 1 ELSE 0 END ASC`.
     */
    private const DASHBOARD_SORT_NULLS_LAST = [
        'lastCall' => 'lastMadeAt',
        'nextCall' => 'nextScheduledAt',
        'optedOutAt' => 'o.optedOutAt',
    ];

    /**
     * Выражения ключей сортировки в терминах строки выборки: те же
     * подзапросы, что и в SELECT, — ими же считается позиция строки для
     * разрешения ?highlight.
     */
    private const DASHBOARD_SORT_EXPRESSIONS = [
        'lastCall' => '(SELECT MAX(c.madeAt) FROM App\Entity\Call c WHERE c.organization = o)',
        'nextCall' => '(SELECT MIN(cs.scheduledAt) FROM App\Entity\Call cs WHERE cs.organization = o AND cs.scheduledAt >= :now)',
        'optedOutAt' => 'o.optedOutAt',
        'isActive' => 'o.isActive',
        'name' => 'o.name',
    ];

    /**
     * Верхняя граница даты: в сравнении «меньше» отбирает все непустые даты
     * (пустые в сравнении не участвуют), чем и подсчитываются строки перед
     * организацией с пустым ключом сортировки.
     */
    private const DATE_SENTINEL = '9999-12-31 00:00:00';

    private const DASHBOARD_DEFAULT_SORT = 'name';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    /**
     * Возвращает ID организаций, доступных пользователю (ADR-0012: модель
     * default-open с deny-list). Менеджер видит все организации, кроме
     * скрытых от него записями organization_hide. Администратору и гостю
     * возвращается null — полный доступ ко всем организациям.
     *
     * @return int[]|null
     */
    public function findAccessibleIds(?User $user): ?array
    {
        if (null === $user || UserRole::Admin === $user->role) {
            return null;
        }

        $rows = $this->createQueryBuilder('o')
            ->select('o.id')
            ->where($this->hiddenByUserPredicate('o'))
            ->setParameter('hideScopeUser', $user)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(
            static fn(array $row): int => (int) $row['id'],
            $rows
        ));
    }

    /**
     * Организации в области доступа пользователя для выпадающего списка
     * формы создания контакта: администратору — все (ADR-0008), менеджеру —
     * все, кроме скрытых (ADR-0012), по имени А–Я.
     *
     * Список элементов формы, а не таблица: пагинация к нему не применяется,
     * иначе добавление контакта к далёкой организации стало бы невозможным.
     *
     * @return Organization[]
     */
    public function findAccessibleOrganizations(?User $user): array
    {
        $accessibleIds = $this->findAccessibleIds($user);
        // null — полный доступ (админ/гость), возвращаем ВСЕ организации;
        // только явной пустой массив (менеджер без доступных организаций)
        // означает «ничего».
        if ([] === $accessibleIds) {
            return [];
        }

        $qb = $this->createQueryBuilder('o')->orderBy('o.name', 'ASC');
        if (null !== $accessibleIds) {
            $qb->where('o.id IN (:organizationIds)')
                ->setParameter('organizationIds', $accessibleIds);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Одна страница организаций панели с агрегатами звонков:
     * - lastMadeAt      — дата последнего совершённого звонка (MAX made_at);
     * - nextScheduledAt — ближайший будущий план (MIN scheduled_at >= now);
     * - lastCallNote    — непустая заметка последнего по времени звонка
     *   (макс. эффективная дата среди звонков с заметками, tie-break по id);
     * - lastCallDate    — эффективная дата последнего звонка организации.
     *
     * Вся сортировка выполняется в SQL: страницу выбирает база, поэтому
     * порядок строк должен быть известен до гидрации. Страница режется
     * $offset/$limit, общее число строк считает countForDashboard() — тем же
     * условием, но без коррелированных подзапросов (считать даты звонков
     * для строк, которые не показываются, незачем).
     *
     * @return DashboardOrganizationRow[]
     */
    public function findForDashboard(
        ?User $user,
        ?string $search = null,
        string $sort = self::DASHBOARD_DEFAULT_SORT,
        string $dir = 'asc',
        ?bool $isActive = null,
        ?bool $isOptedOut = null,
        int $offset = 0,
        int $limit = Pagination::PER_PAGE,
        ?\DateTimeImmutable $now = null,
    ): array {
        $now ??= new \DateTimeImmutable();

        $qb = $this->createQueryBuilder('o')
            ->select('o')
            ->addSelect('(SELECT MAX(c.madeAt) FROM App\Entity\Call c WHERE c.organization = o) AS lastMadeAt')
            ->addSelect('(SELECT MIN(cs.scheduledAt) FROM App\Entity\Call cs WHERE cs.organization = o AND cs.scheduledAt >= :now) AS nextScheduledAt')
            ->addSelect('(SELECT cn.notes FROM App\Entity\Call cn WHERE cn.organization = o AND cn.notes IS NOT NULL AND cn.notes <> \'\' AND cn.id = (SELECT MAX(cc.id) FROM App\Entity\Call cc WHERE cc.organization = o AND cc.notes IS NOT NULL AND cc.notes <> \'\' AND COALESCE(cc.madeAt, cc.scheduledAt) = (SELECT MAX(COALESCE(ccd.madeAt, ccd.scheduledAt)) FROM App\Entity\Call ccd WHERE ccd.organization = o AND ccd.notes IS NOT NULL AND ccd.notes <> \'\'))) AS lastCallNote')
            ->addSelect('(SELECT IDENTITY(ccc.contact) FROM App\Entity\Call ccc WHERE ccc.organization = o AND ccc.notes IS NOT NULL AND ccc.notes <> \'\' AND ccc.id = (SELECT MAX(ccx.id) FROM App\Entity\Call ccx WHERE ccx.organization = o AND ccx.notes IS NOT NULL AND ccx.notes <> \'\' AND COALESCE(ccx.madeAt, ccx.scheduledAt) = (SELECT MAX(COALESCE(ccy.madeAt, ccy.scheduledAt)) FROM App\Entity\Call ccy WHERE ccy.organization = o AND ccy.notes IS NOT NULL AND ccy.notes <> \'\'))) AS lastCallContactId')
            ->addSelect('(SELECT MAX(COALESCE(cl.madeAt, cl.scheduledAt)) FROM App\Entity\Call cl WHERE cl.organization = o) AS lastCallDate')
            ->setParameter('now', $now);

        $this->applyDashboardFilters($qb, $user, $search, $isActive, $isOptedOut);
        $this->applyDashboardOrder($qb, $sort, $dir);

        if ($offset > 0) {
            $qb->setFirstResult($offset);
        }
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return array_values(array_map($this->rowToDto(...), $qb->getQuery()->getResult()));
    }

    /**
     * Всего строк выборки панели: область доступа + поиск + фильтры. Без
     * коррелированных подзапросов и без сортировки — сортировка на «сколько
     * всего страниц» не влияет.
     */
    public function countForDashboard(
        ?User $user,
        ?string $search = null,
        ?bool $isActive = null,
        ?bool $isOptedOut = null,
    ): int {
        $qb = $this->createQueryBuilder('o')
            ->select('COUNT(o.id)');

        $this->applyDashboardFilters($qb, $user, $search, $isActive, $isOptedOut);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Позиция организации в текущем порядке выборки — число строк, стоящих
     * перед ней. Нужна для разрешения ?highlight на ту страницу, которая
     * содержит организацию: страница = floor(позиция / размер страницы) + 1.
     *
     * Возвращает null, когда организация вне выборки (скрыта от менеджера
     * или отфильтрована) — тогда подсветке не на что указать.
     */
    public function findDashboardPosition(
        ?User $user,
        Organization $organization,
        ?string $search = null,
        string $sort = self::DASHBOARD_DEFAULT_SORT,
        string $dir = 'asc',
        ?bool $isActive = null,
        ?bool $isOptedOut = null,
        ?\DateTimeImmutable $now = null,
    ): ?int {
        $now ??= new \DateTimeImmutable();
        $sort = self::normalizeDashboardSort($sort);

        // Организация должна входить в ту же выборку, что и список: иначе
        // подсветка уедет на страницу, которой её нет.
        $visible = $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.id = :organizationId')
            ->setParameter('organizationId', $organization->id);
        $this->applyDashboardFilters($visible, $user, $search, $isActive, $isOptedOut);
        if (0 === (int) $visible->getQuery()->getSingleScalarResult()) {
            return null;
        }

        $qb = $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.id <> 0');
        $this->applyDashboardFilters($qb, $user, $search, $isActive, $isOptedOut);
        $this->applyBeforePredicate($qb, $organization, $sort, $dir, $now);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Состав группы целиком: организации группы, отсортированные в SQL. Запрос
     * идёт по org_group_membership, а не по коллекции $group->memberships —
     * иначе сортировка выполнялась бы в памяти и страница работала бы только
     * на бумаге.
     *
     * Срез страницы и COUNT не делаются: состав группы не пагинируется, форма
     * добавления показывает все организации области доступа (change
     * organizations-pagination).
     *
     * @return Organization[]
     */
    public function findGroupMembers(
        OrganizationGroup $group,
        ?User $user,
        string $sort,
        string $dir,
    ): array {
        $qb = $this->createQueryBuilder('o')
            ->select('o')
            ->innerJoin('App\Entity\OrgGroupMembership', 'membership', 'WITH', 'membership.organization = o AND membership.group = :group')
            ->leftJoin('o.createdBy', 'creator')
            ->setParameter('group', $group);
        $this->applyAccessScope($qb, 'o', $user);
        $this->applyMembersOrder($qb, $sort, $dir);

        return $qb->getQuery()->getResult();
    }

    /**
     * Область доступа (ADR-0012, ADR-0008), поиск и фильтры — одно условие
     * для подсчёта, среза и позиции: расхождение условий означало бы, что
     * «всего» и «на странице» считают разные наборки строк.
     */
    private function applyDashboardFilters(
        QueryBuilder $qb,
        ?User $user,
        ?string $search,
        ?bool $isActive,
        ?bool $isOptedOut,
    ): void {
        $this->applyAccessScope($qb, 'o', $user);

        $search = trim((string) $search);
        if ('' !== $search) {
            $qb->andWhere(
                'o.name LIKE :term OR EXISTS (SELECT 1 FROM App\Entity\Contact c2 WHERE c2.organization = o AND (c2.name LIKE :term OR c2.phone LIKE :term OR c2.email LIKE :term))'
            )
                ->setParameter('term', '%' . $search . '%');
        }

        if (null !== $isActive) {
            $qb->andWhere('o.isActive = :isActive')
                ->setParameter('isActive', $isActive);
        }

        if (null !== $isOptedOut) {
            $qb->andWhere('o.isOptedOut = :isOptedOut')
                ->setParameter('isOptedOut', $isOptedOut);
        }
    }

    /**
     * Первым ключом идёт CASE «пустое — в конец», затем сам ключ в
     * запрошенном направлении, последним — первичный ключ строки: без него
     * строки с одинаковым названием (уникального ограничения в БД нет)
     * могли бы попадать на соседние страницы.
     */
    private function applyDashboardOrder(QueryBuilder $qb, string $sort, string $dir): void
    {
        $sort = self::normalizeDashboardSort($sort);
        $direction = 'desc' === strtolower($dir) ? 'DESC' : 'ASC';

        if (isset(self::DASHBOARD_SORT_NULLS_LAST[$sort])) {
            $key = self::DASHBOARD_SORT_NULLS_LAST[$sort];
            $qb->orderBy(\sprintf('CASE WHEN %s IS NULL THEN 1 ELSE 0 END', $key), 'ASC')
                ->addOrderBy($key, $direction);
        } else {
            $qb->orderBy(self::DASHBOARD_SORT_COLUMNS[$sort], $direction);
        }

        $qb->addOrderBy('o.id', 'ASC');
    }

    /**
     * Считает строки, стоящие перед организацией в текущем порядке: ключ
     * сортировки «меньше» (или «больше» при нисходящем направлении), а при
     * равенстве ключа — меньший первичный ключ.
     *
     * Пустой ключ сортировки всегда последний, поэтому перед такой строкой
     * стоят ровно строки с непустым ключом; их отбирает сравнение с верхней
     * границей даты, потому что NULL в сравнении не участвует.
     */
    private function applyBeforePredicate(
        QueryBuilder $qb,
        Organization $organization,
        string $sort,
        string $dir,
        \DateTimeImmutable $now,
    ): void {
        $expression = self::DASHBOARD_SORT_EXPRESSIONS[$sort];
        $value = $this->dashboardSortValue($organization, $sort, $now);

        if ('nextCall' === $sort) {
            $qb->setParameter('now', $now);
        }

        if (isset(self::DASHBOARD_SORT_NULLS_LAST[$sort]) && null === $value) {
            $qb->andWhere(\sprintf('%s < :sortSentinel', $expression))
                ->setParameter('sortSentinel', new \DateTimeImmutable(self::DATE_SENTINEL));

            return;
        }

        $comparison = 'desc' === strtolower($dir) ? '>' : '<';
        $qb->andWhere(\sprintf(
            '(%1$s %2$s :sortValue OR (%1$s = :sortValue AND o.id < :sortId))',
            $expression,
            $comparison,
        ))
            ->setParameter('sortValue', $value)
            ->setParameter('sortId', $organization->id);
    }

    private function dashboardSortValue(
        Organization $organization,
        string $sort,
        \DateTimeImmutable $now,
    ): string|bool|\DateTimeImmutable|null {
        return match ($sort) {
            'lastCall' => $this->lastMadeAt($organization),
            'nextCall' => $this->nextScheduledAt($organization, $now),
            'optedOutAt' => $organization->optedOutAt,
            'isActive' => $organization->isActive,
            default => $organization->name,
        };
    }

    private function lastMadeAt(Organization $organization): ?\DateTimeImmutable
    {
        $value = $this->createQueryBuilder('c')
            ->select('MAX(c.madeAt)')
            ->where('c.organization = :organization')
            ->setParameter('organization', $organization)
            ->getQuery()
            ->getSingleScalarResult();

        return self::toDateTime($value);
    }

    private function nextScheduledAt(Organization $organization, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $value = $this->createQueryBuilder('c')
            ->select('MIN(c.scheduledAt)')
            ->where('c.organization = :organization')
            ->andWhere('c.scheduledAt >= :now')
            ->setParameter('organization', $organization)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();

        return self::toDateTime($value);
    }

    private static function normalizeDashboardSort(string $sort): string
    {
        if (isset(self::DASHBOARD_SORT_COLUMNS[$sort]) || isset(self::DASHBOARD_SORT_NULLS_LAST[$sort])) {
            return $sort;
        }

        return self::DASHBOARD_DEFAULT_SORT;
    }

    /**
     * Предикат ADR-0012: организация скрыта от пользователя.
     */
    private function hiddenByUserPredicate(string $alias): string
    {
        return \sprintf(
            '%s.id NOT IN (
                SELECT IDENTITY(h.organization) FROM App\Entity\OrganizationHide h
                WHERE h.manager = :hideScopeUser
            )',
            $alias,
        );
    }

    /**
     * Область доступа как условие запроса (ADR-0012): администратору и
     * гостю ограничений нет (ADR-0008), менеджеру — все организации, кроме
     * скрытых от него записями organization_hide.
     */
    private function applyAccessScope(QueryBuilder $qb, string $alias, ?User $user): void
    {
        if (null === $user || UserRole::Admin === $user->role) {
            return;
        }

        $qb->andWhere($this->hiddenByUserPredicate($alias))
            ->setParameter('hideScopeUser', $user);
    }

    /**
     * Первичный ключ строки — последний ключ ORDER BY: названия легальны и
     * совпадать могут, без добивки одна и та же организация попадала бы на
     * соседние страницы.
     */
    private function applyMembersOrder(QueryBuilder $qb, string $sort, string $dir): void
    {
        $column = self::MEMBERS_SORT_COLUMNS[$sort] ?? self::MEMBERS_SORT_COLUMNS['name'];
        $qb->orderBy($column, 'DESC' === strtoupper($dir) ? 'DESC' : 'ASC')
            ->addOrderBy('o.id', 'ASC');
    }

    private const MEMBERS_SORT_COLUMNS = [
        'name' => 'o.name',
        'industry' => 'o.industry',
        'createdAt' => 'o.createdAt',
        // Тот же ключ, что и в колонке «Создатель»: «Имя Фамилия», при пустом
        // имени — email. COALESCE нужен, потому что колонки nullable.
        'creator' => "CONCAT(COALESCE(creator.name, ''), ' ', COALESCE(creator.surname, ''), ' ', COALESCE(creator.email, ''))",
    ];

    /**
     * @param array{0: Organization, lastMadeAt?: ?string, nextScheduledAt?: ?string,
     *     lastCallNote?: ?string, lastCallDate?: ?string} $row
     */
    private function rowToDto(array $row): DashboardOrganizationRow
    {
        return new DashboardOrganizationRow(
            organization: $row[0],
            lastMadeAt: self::toDateTime($row['lastMadeAt'] ?? null),
            nextScheduledAt: self::toDateTime($row['nextScheduledAt'] ?? null),
            lastCallNote: $row['lastCallNote'] ?? null,
            lastCallDate: self::toDateTime($row['lastCallDate'] ?? null),
            lastCallContactId: isset($row['lastCallContactId']) ? (int) $row['lastCallContactId'] : null,
        );
    }

    private static function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTime) {
            return \DateTimeImmutable::createFromMutable($value);
        }
        if (\is_string($value)) {
            if ('' === $value) {
                return null;
            }

            return new \DateTimeImmutable($value);
        }

        return null;
    }

    /**
     * Статистика отписок для дашборда.
     *
     * @param int[]|null $organizationIds
     *
     * @return array{optedOutToday: int, optedOutTodayByEmail: int, optedOutWeek: int, optedOutWeekByEmail: int, optedOutMonth: int, optedOutMonthByEmail: int}
     */
    public function optOutStats(?array $organizationIds, \DateTimeImmutable $now): array
    {
        $todayStart = $now->setTime(0, 0);
        // N календарных дней включая сегодня: неделя — today−6, месяц — today−29.
        $weekStart = $todayStart->modify('-6 days');
        $monthStart = $todayStart->modify('-29 days');

        $qb = $this->createQueryBuilder('o')
            ->select(
                'SUM(CASE WHEN o.isOptedOut = true AND o.optedOutAt >= :todayStart THEN 1 ELSE 0 END) AS optedOutToday',
                "SUM(CASE WHEN o.isOptedOut = true AND o.optedOutAt >= :todayStart AND o.optOutReason = 'Отписка из письма' THEN 1 ELSE 0 END) AS optedOutTodayByEmail",
                'SUM(CASE WHEN o.isOptedOut = true AND o.optedOutAt >= :weekStart THEN 1 ELSE 0 END) AS optedOutWeek',
                "SUM(CASE WHEN o.isOptedOut = true AND o.optedOutAt >= :weekStart AND o.optOutReason = 'Отписка из письма' THEN 1 ELSE 0 END) AS optedOutWeekByEmail",
                'SUM(CASE WHEN o.isOptedOut = true AND o.optedOutAt >= :monthStart THEN 1 ELSE 0 END) AS optedOutMonth',
                "SUM(CASE WHEN o.isOptedOut = true AND o.optedOutAt >= :monthStart AND o.optOutReason = 'Отписка из письма' THEN 1 ELSE 0 END) AS optedOutMonthByEmail",
            )
            ->setParameter('todayStart', $todayStart)
            ->setParameter('weekStart', $weekStart)
            ->setParameter('monthStart', $monthStart);

        if (null !== $organizationIds) {
            $qb->andWhere('o.id IN (:organizationIds)')
                ->setParameter('organizationIds', $organizationIds);
        }

        $row = $qb->getQuery()->getSingleResult();

        return [
            'optedOutToday' => (int) $row['optedOutToday'],
            'optedOutTodayByEmail' => (int) $row['optedOutTodayByEmail'],
            'optedOutWeek' => (int) $row['optedOutWeek'],
            'optedOutWeekByEmail' => (int) $row['optedOutWeekByEmail'],
            'optedOutMonth' => (int) $row['optedOutMonth'],
            'optedOutMonthByEmail' => (int) $row['optedOutMonthByEmail'],
        ];
    }
}
