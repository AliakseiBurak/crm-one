<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\DashboardStats;
use App\Entity\Call;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Call>
 */
class CallRepository extends ServiceEntityRepository
{
    /**
     * Ключи девяти категорий индикаторов «По организациям»: 3 called
     * (факт), 3 waiting (план в будущем) и 3 overdue (просроченные).
     */
    public const ORGANIZATION_BUCKETS = [
        'called1', 'called7', 'called30',
        'waiting1', 'waiting7', 'waiting30',
        'overdue1', 'overdue7', 'overdue30',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Call::class);
    }

    /**
     * Границы всех периодов дашборда от одного момента $now — единый
     * источник истины для dashboardStats() и organizationCounts():
     * сегодня, вчера, неделя (сегодня-6 дней), месяц (сегодня-29 дней),
     * горизонт ожидания (+7/+30 дней).
     *
     * @return array{todayStart: \DateTimeImmutable, todayEnd: \DateTimeImmutable,
     *     yesterdayStart: \DateTimeImmutable, yesterdayEnd: \DateTimeImmutable,
     *     weekStart: \DateTimeImmutable, monthStart: \DateTimeImmutable,
     *     weekEnd: \DateTimeImmutable, monthEnd: \DateTimeImmutable}
     */
    private function periodBounds(\DateTimeImmutable $now): array
    {
        $todayStart = $now->setTime(0, 0);
        $yesterdayStart = $todayStart->modify('-1 day');

        return [
            'todayStart' => $todayStart,
            'todayEnd' => $now->setTime(23, 59, 59),
            'yesterdayStart' => $yesterdayStart,
            'yesterdayEnd' => $yesterdayStart->setTime(23, 59, 59),
            'weekStart' => $todayStart->modify('-6 days'),
            'monthStart' => $todayStart->modify('-29 days'),
            'weekEnd' => $now->modify('+7 days'),
            'monthEnd' => $now->modify('+30 days'),
        ];
    }

    /**
     * Считает метрики дашборда одним запросом. null $organizationIds —
     * вся база (администратор/гость).
     *
     * @param int[]|null $organizationIds
     */
    public function dashboardStats(?array $organizationIds, \DateTimeImmutable $now): DashboardStats
    {
        $qb = $this->createQueryBuilder('c')
            ->select(
                'SUM(CASE WHEN c.madeAt IS NOT NULL AND c.madeAt >= :todayStart THEN 1 ELSE 0 END) AS calledToday',
                'SUM(CASE WHEN c.madeAt IS NOT NULL AND c.madeAt >= :weekStart THEN 1 ELSE 0 END) AS calledWeek',
                'SUM(CASE WHEN c.madeAt IS NOT NULL AND c.madeAt >= :monthStart THEN 1 ELSE 0 END) AS calledMonth',
                'SUM(CASE WHEN c.scheduledAt IS NOT NULL AND c.scheduledAt BETWEEN :todayStart AND :todayEnd THEN 1 ELSE 0 END) AS waitingToday',
                'SUM(CASE WHEN c.scheduledAt IS NOT NULL AND c.scheduledAt > :now AND c.scheduledAt <= :weekEnd THEN 1 ELSE 0 END) AS waitingWeek',
                'SUM(CASE WHEN c.scheduledAt IS NOT NULL AND c.scheduledAt > :now AND c.scheduledAt <= :monthEnd THEN 1 ELSE 0 END) AS waitingMonth',
            )
            ->setParameter('now', $now);

        // DQL показателей не использует overdue-границы yesterday*.
        $bounds = $this->periodBounds($now);
        foreach (['todayStart', 'todayEnd', 'weekStart', 'monthStart', 'weekEnd', 'monthEnd'] as $name) {
            $qb->setParameter($name, $bounds[$name]);
        }

        if (null !== $organizationIds) {
            $qb->andWhere('c.organization IN (:organizationIds)')
                ->setParameter('organizationIds', $organizationIds);
        }

        $row = $qb->getQuery()->getSingleResult();

        return new DashboardStats(
            calledToday: (int) $row['calledToday'],
            calledWeek: (int) $row['calledWeek'],
            calledMonth: (int) $row['calledMonth'],
            waitingToday: (int) $row['waitingToday'],
            waitingWeek: (int) $row['waitingWeek'],
            waitingMonth: (int) $row['waitingMonth'],
        );
    }

    /**
     * Число уникальных организаций по девяти категориям звонков — один
     * нативный SQL-запрос с COUNT(DISTINCT). Категории:
     * called* — факт (made_at в периоде); waiting* — план в будущем при
     * отсутствии факта у организации в том же периоде (NOT EXISTS,
     * исключающая логика); overdue* — план в прошлом без факта
     * (made_at IS NULL), независимая категория. null $organizationIds —
     * вся база (администратор); пустой массив — нули без запроса.
     *
     * @param int[]|null $organizationIds
     *
     * @return array<string, int> [bucket => n], ключи self::ORGANIZATION_BUCKETS
     */
    public function organizationCounts(?array $organizationIds, \DateTimeImmutable $now): array
    {
        if (null !== $organizationIds && [] === $organizationIds) {
            return array_fill_keys(self::ORGANIZATION_BUCKETS, 0);
        }

        $sql = <<<'SQL'
            SELECT
                COUNT(DISTINCT CASE WHEN c.made_at >= :todayStart
                    THEN c.organization_id END) AS called1,
                COUNT(DISTINCT CASE WHEN c.made_at >= :weekStart
                    THEN c.organization_id END) AS called7,
                COUNT(DISTINCT CASE WHEN c.made_at >= :monthStart
                    THEN c.organization_id END) AS called30,

                COUNT(DISTINCT CASE
                    WHEN c.scheduled_at BETWEEN :todayStart AND :todayEnd
                    AND NOT EXISTS (
                        SELECT 1 FROM `call` cx
                        WHERE cx.organization_id = c.organization_id
                        AND cx.made_at >= :todayStart
                    )
                    THEN c.organization_id
                END) AS waiting1,

                COUNT(DISTINCT CASE
                    WHEN c.scheduled_at > :now AND c.scheduled_at <= :weekEnd
                    AND NOT EXISTS (
                        SELECT 1 FROM `call` cx
                        WHERE cx.organization_id = c.organization_id
                        AND cx.made_at >= :weekStart
                    )
                    THEN c.organization_id
                END) AS waiting7,

                COUNT(DISTINCT CASE
                    WHEN c.scheduled_at > :now AND c.scheduled_at <= :monthEnd
                    AND NOT EXISTS (
                        SELECT 1 FROM `call` cx
                        WHERE cx.organization_id = c.organization_id
                        AND cx.made_at >= :monthStart
                    )
                    THEN c.organization_id
                END) AS waiting30,

                COUNT(DISTINCT CASE
                    WHEN c.scheduled_at BETWEEN :yesterdayStart AND :yesterdayEnd
                    AND c.made_at IS NULL
                    THEN c.organization_id
                END) AS overdue1,

                COUNT(DISTINCT CASE
                    WHEN c.scheduled_at >= :weekStart AND c.scheduled_at < :todayStart
                    AND c.made_at IS NULL
                    THEN c.organization_id
                END) AS overdue7,

                COUNT(DISTINCT CASE
                    WHEN c.scheduled_at >= :monthStart AND c.scheduled_at < :todayStart
                    AND c.made_at IS NULL
                    THEN c.organization_id
                END) AS overdue30
            FROM `call` c
            SQL;

        $params = ['now' => $now] + $this->periodBounds($now);
        $types = array_fill_keys(array_keys($params), Types::DATETIME_IMMUTABLE);

        if (null !== $organizationIds) {
            $sql .= ' WHERE c.organization_id IN (:organizationIds)';
            $params['organizationIds'] = $organizationIds;
            $types['organizationIds'] = ArrayParameterType::INTEGER;
        }

        $row = $this->getEntityManager()->getConnection()->fetchAssociative($sql, $params, $types);

        $counts = [];
        foreach (self::ORGANIZATION_BUCKETS as $bucket) {
            $counts[$bucket] = (int) $row[$bucket];
        }

        return $counts;
    }

    /**
     * Все звонки выбранных организаций одним запросом: у каждого звонка —
     * id, эффективная дата (COALESCE(made_at, scheduled_at)), заметка
     * (nullable), id контакта (nullable) и поля результата для модального
     * окна редактирования (scheduledAt/madeAt/isDeal). Возвращает массив
     * «id организации => список [id, date, notes, contactId, ...]», строки
     * отсортированы от новых к старым.
     *
     * @param int[] $organizationIds
     *
     * @return array<int, list<array{id: int, organizationId: int, contactId: int,
     *     date: ?\DateTimeImmutable, scheduledAt: ?\DateTimeImmutable,
     *     madeAt: ?\DateTimeImmutable, isDeal: bool, isNoAnswer: bool,
     *     campaignId: ?int, campaignName: ?string, nextCallId: ?int,
     *     nextCallScheduledAt: ?\DateTimeImmutable, notes: ?string}>>
     */
    public function findAllCallsByOrganizations(array $organizationIds): array
    {
        if ([] === $organizationIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'IDENTITY(c.organization) AS organizationId', 'IDENTITY(c.contact) AS contactId', 'COALESCE(c.madeAt, c.scheduledAt) AS callDate', 'c.notes', 'c.scheduledAt', 'c.madeAt', 'c.isDeal', 'c.isNoAnswer', 'IDENTITY(c.madeBy) AS madeById', 'IDENTITY(c.campaign) AS campaignId', 'camp.name AS campaignName', 'IDENTITY(c.nextCall) AS nextCallId', 'nc.scheduledAt AS nextCallScheduledAt')
            ->leftJoin('c.campaign', 'camp')
            ->leftJoin('c.nextCall', 'nc')
            ->where('IDENTITY(c.organization) IN (:organizationIds)')
            ->setParameter('organizationIds', $organizationIds)
            ->orderBy('callDate', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $byOrganization = [];
        foreach ($rows as $row) {
            $byOrganization[(int) $row['organizationId']][] = [
                'id' => (int) $row['id'],
                'organizationId' => (int) $row['organizationId'],
                'date' => $row['callDate'],
                'notes' => $row['notes'],
                'contactId' => (int) $row['contactId'],
                'scheduledAt' => $row['scheduledAt'],
                'madeAt' => $row['madeAt'],
                'madeById' => $row['madeById'] ? (int) $row['madeById'] : null,
                'isDeal' => (bool) $row['isDeal'],
                'isNoAnswer' => (bool) $row['isNoAnswer'],
                'campaignId' => $row['campaignId'] ? (int) $row['campaignId'] : null,
                'campaignName' => $row['campaignName'],
                'nextCallId' => $row['nextCallId'] ? (int) $row['nextCallId'] : null,
                'nextCallScheduledAt' => $row['nextCallScheduledAt'],
            ];
        }

        return $byOrganization;
    }
}
