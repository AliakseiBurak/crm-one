<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\DashboardOrganizationRow;
use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Organization>
 */
class OrganizationRepository extends ServiceEntityRepository
{
    /**
     * Колонки, допущенные в SQL ORDER BY (только пути к полям и псевдонимы
     * скалярных результатов). Даты lastCall/nextCall сортируются в PHP,
     * т.к. DQL не допускает функций (COALESCE/IS NULL) в ORDER BY, а
     * NULL-позиция «в конец» требует PHP-маппера.
     */
    private const SQL_SORT_COLUMNS = [
        'name' => 'o.name',
        'industry' => 'o.industry',
    ];

    /**
     * Колонки сортировки, сопоставляющие ключ запроса скалярному результату:
     * lastMadeAt — последний совершённый звонок,
     * nextScheduledAt — ближайший будущий план.
     */
    private const DATE_SORT_FIELDS = [
        'lastCall' => 'lastMadeAt',
        'nextCall' => 'nextScheduledAt',
    ];

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
            ->where('o.id NOT IN (
                SELECT IDENTITY(h.organization) FROM App\Entity\OrganizationHide h
                WHERE h.manager = :user
            )')
            ->setParameter('user', $user)
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
     * Организации панели с агрегатами звонков одним запросом:
     * - lastMadeAt      — дата последнего совершённого звонка (MAX made_at);
     * - nextScheduledAt — ближайший будущий план (MIN scheduled_at >= now);
     * - lastCallNote    — непустая заметка последнего по времени звонка
     *   (макс. эффективная дата среди звонков с заметками, tie-break по id);
     * - lastCallDate    — эффективная дата последнего звонка организации.
     *
     * Область доступа: null $organizationIds — все организации (админ/гость).
     * Сортировка: name/industry — в SQL (whitelist-путь к полю);
     * lastCall/nextCall — в PHP с NULL в конец и вторичным ключом name ASC.
     *
     * @return DashboardOrganizationRow[]
     */
    public function findForDashboard(?User $user, ?string $search = null, string $sort = 'name', string $dir = 'asc'): array
    {
        $organizationIds = $this->findAccessibleIds($user);
        $now = new \DateTimeImmutable();

        $qb = $this->createQueryBuilder('o')
            ->select('o')
            ->addSelect('(SELECT MAX(c.madeAt) FROM App\Entity\Call c WHERE c.organization = o) AS lastMadeAt')
            ->addSelect('(SELECT MIN(cs.scheduledAt) FROM App\Entity\Call cs WHERE cs.organization = o AND cs.scheduledAt >= :now) AS nextScheduledAt')
            ->addSelect('(SELECT cn.notes FROM App\Entity\Call cn WHERE cn.organization = o AND cn.notes IS NOT NULL AND cn.notes <> \'\' AND cn.id = (SELECT MAX(cc.id) FROM App\Entity\Call cc WHERE cc.organization = o AND cc.notes IS NOT NULL AND cc.notes <> \'\' AND COALESCE(cc.madeAt, cc.scheduledAt) = (SELECT MAX(COALESCE(ccd.madeAt, ccd.scheduledAt)) FROM App\Entity\Call ccd WHERE ccd.organization = o AND ccd.notes IS NOT NULL AND ccd.notes <> \'\'))) AS lastCallNote')
            ->addSelect('(SELECT IDENTITY(ccc.contact) FROM App\Entity\Call ccc WHERE ccc.organization = o AND ccc.notes IS NOT NULL AND ccc.notes <> \'\' AND ccc.id = (SELECT MAX(ccx.id) FROM App\Entity\Call ccx WHERE ccx.organization = o AND ccx.notes IS NOT NULL AND ccx.notes <> \'\' AND COALESCE(ccx.madeAt, ccx.scheduledAt) = (SELECT MAX(COALESCE(ccy.madeAt, ccy.scheduledAt)) FROM App\Entity\Call ccy WHERE ccy.organization = o AND ccy.notes IS NOT NULL AND ccy.notes <> \'\'))) AS lastCallContactId')
            ->addSelect('(SELECT MAX(COALESCE(cl.madeAt, cl.scheduledAt)) FROM App\Entity\Call cl WHERE cl.organization = o) AS lastCallDate')
            ->setParameter('now', $now);

        if (null !== $organizationIds) {
            $qb->andWhere('o.id IN (:organizationIds)')
                ->setParameter('organizationIds', $organizationIds);
        }

        $search = trim((string) $search);
        if ('' !== $search) {
            $qb->andWhere(
                'o.name LIKE :term OR EXISTS (SELECT 1 FROM App\Entity\Contact c2 WHERE c2.organization = o AND (c2.name LIKE :term OR c2.phone LIKE :term OR c2.email LIKE :term))'
            )
                ->setParameter('term', '%' . $search . '%');
        }

        // name/industry — whitelist-сортировка в SQL; вторичный ключ name ASC.
        // Без параметра сортировки — по умолчанию по имени организации (А–Я).
        if (\array_key_exists($sort, self::SQL_SORT_COLUMNS)) {
            $qb->orderBy(self::SQL_SORT_COLUMNS[$sort], strtolower($dir) === 'desc' ? 'DESC' : 'ASC')
                ->addOrderBy('o.name', 'ASC');
        } else {
            $qb->orderBy('o.name', 'ASC');
        }

        $rows = $qb->getQuery()->getResult();

        return $this->sortRows($rows, $sort, $dir);
    }

    /**
     * @return DashboardOrganizationRow[]
     */
    private function sortRows(array $rows, string $sort, string $dir): array
    {
        $field = $this->dateSortField($sort);
        if (null === $field) {
            // name/industry — уже отсортированы в SQL; просто маппим в DTO.
            return array_values(array_map($this->rowToDto(...), $rows));
        }

        $asc = strtolower($dir) !== 'desc';

        usort($rows, static function (array $a, array $b) use ($asc, $field): int {
            $va = self::toDateTime($a[$field] ?? null);
            $vb = self::toDateTime($b[$field] ?? null);

            if (null === $va && null === $vb) {
                return self::nameCompare($a, $b);
            }
            if (null === $va) {
                return 1; // NULL последними независимо от направления
            }
            if (null === $vb) {
                return $asc ? -1 : 1;
            }

            $cmp = $va <=> $vb;
            if (0 !== $cmp) {
                return $asc ? $cmp : -$cmp;
            }

            // равные даты — вторичный ключ по названию А–Я всегда по возрастанию
            return self::nameCompare($a, $b);
        });

        return array_values(array_map($this->rowToDto(...), $rows));
    }

    private function dateSortField(string $sort): ?string
    {
        return self::DATE_SORT_FIELDS[$sort] ?? null;
    }

    /**
     * Вторичный ключ — название организации А–Я (стабильность для всех
     * видов сортировки).
     */
    private static function nameCompare(array $a, array $b): int
    {
        return strcmp((string) $a[0]->name, (string) $b[0]->name);
    }

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
        if (\is_string($value)) {
            if ('' === $value) {
                return null;
            }

            return new \DateTimeImmutable($value);
        }

        return null;
    }
}
