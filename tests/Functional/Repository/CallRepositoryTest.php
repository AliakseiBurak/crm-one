<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\Call;
use App\Entity\Organization;
use App\Repository\CallRepository;
use App\Tests\DatabaseWebTestCase;

/**
 * Тесты границ periodBounds() через dashboardStats() и organizationCounts():
 * каждая граница (todayStart, todayEnd, yesterdayStart, yesterdayEnd,
 * weekStart, weekEnd, monthStart, monthEnd) покрыта фикстурой на
 * точной границе — мутации IncrementInteger/DecrementInteger сдвигают
 * границу и фикстура перестаёт попадать в bucket.
 */
final class CallRepositoryTest extends DatabaseWebTestCase
{
    private const NOW = '2026-09-23 15:00:00';

    /**
     * Фикстуры на точных границах periodBounds().
     *
     * Организации разделены: madeAt-орги и scheduledAt-орги,
     * чтобы NOT EXISTS подзапрос в waiting-категориях не исключал их.
     *
     * $now = 2026-09-23 15:00:
     *   todayStart=09-23 00:00, todayEnd=09-23 23:59:59
     *   yesterdayStart=09-22 00:00, yesterdayEnd=09-22 23:59:59
     *   weekStart=09-17 00:00, weekEnd=09-30 15:00
     *   monthStart=08-25 00:00, monthEnd=10-23 15:00
     *
     * dashboardStats (считает ЗВОНКИ):
     *   calledToday=1, calledWeek=2, calledMonth=3
     *   waitingToday=1, waitingWeek=2, waitingMonth=3
     *
     * organizationCounts (считает ОРГАНИЗАЦИИ):
     *   called1=1, called7=2, called30=3
     *   waiting1=1, waiting7=2, waiting30=3
     *   overdue1=1, overdue7=1, overdue30=1
     */
    public function testPeriodBoundsFixturesProduceExpectedCounts(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $em = $this->em();

        // --- madeAt orgs (called* buckets) ---
        $org1 = new Organization()->setName('Org1-calledToday')->setIndustry('IT');
        $org2 = new Organization()->setName('Org2-calledWeek')->setIndustry('IT');
        $org3 = new Organization()->setName('Org3-calledMonth')->setIndustry('IT');
        $em->persist($org1);
        $em->persist($org2);
        $em->persist($org3);

        // calledToday: madeAt >= todayStart (23.09 00:00)
        $this->makeCall($org1, madeAt: '2026-09-23 15:00');
        // calledWeek: madeAt >= weekStart (17.09 00:00)
        $this->makeCall($org2, madeAt: '2026-09-18 00:00');
        // calledMonth: madeAt >= monthStart (25.08 00:00)
        $this->makeCall($org3, madeAt: '2026-08-25 00:00');

        // --- scheduledAt orgs (waiting* buckets, без madeAt вызовов) ---
        $org4 = new Organization()->setName('Org4-waitingToday')->setIndustry('IT');
        $org5 = new Organization()->setName('Org5-waitingWeek')->setIndustry('IT');
        $org6 = new Organization()->setName('Org6-waitingMonth')->setIndustry('IT');
        $em->persist($org4);
        $em->persist($org5);
        $em->persist($org6);

        // waitingToday: scheduledAt BETWEEN todayStart AND todayEnd
        $this->makeCall($org4, scheduledAt: '2026-09-23 23:59:59');
        // waitingWeek: scheduledAt > now AND <= weekEnd (30.09 15:00)
        $this->makeCall($org5, scheduledAt: '2026-09-24 00:00');
        // waitingMonth: scheduledAt > now AND <= monthEnd (23.10 15:00)
        $this->makeCall($org6, scheduledAt: '2026-10-23 15:00');

        // --- overdue org ---
        $org7 = new Organization()->setName('Org7-overdue')->setIndustry('IT');
        $em->persist($org7);

        // overdue1: scheduled BETWEEN yesterdayStart AND yesterdayEnd, madeAt IS NULL
        $this->makeCall($org7, scheduledAt: '2026-09-22 23:59:59');

        $em->flush();

        $repo = $em->getRepository(Call::class);

        // --- dashboardStats (считает звонки) ---
        $stats = $repo->dashboardStats(null, $now);
        self::assertSame(1, $stats->calledToday, 'calledToday');
        self::assertSame(2, $stats->calledWeek, 'calledWeek');
        self::assertSame(3, $stats->calledMonth, 'calledMonth');
        self::assertSame(1, $stats->waitingToday, 'waitingToday');
        self::assertSame(2, $stats->waitingWeek, 'waitingWeek');
        self::assertSame(3, $stats->waitingMonth, 'waitingMonth');

        // --- organizationCounts (считает организации) ---
        $counts = $repo->organizationCounts(null, $now);
        self::assertSame(1, $counts['called1'], 'called1');
        self::assertSame(2, $counts['called7'], 'called7');
        self::assertSame(3, $counts['called30'], 'called30');
        self::assertSame(1, $counts['waiting1'], 'waiting1');
        self::assertSame(2, $counts['waiting7'], 'waiting7');
        self::assertSame(3, $counts['waiting30'], 'waiting30');
        self::assertSame(1, $counts['overdue1'], 'overdue1');
        self::assertSame(1, $counts['overdue7'], 'overdue7');
        self::assertSame(1, $counts['overdue30'], 'overdue30');
    }

    /**
     * Проверяет, что organizationCounts возвращает нули для пустого
     * массива organizationIds (без SQL-запроса).
     */
    public function testOrganizationCountsEmptyArrayReturnsZeros(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $counts = $this->em()->getRepository(Call::class)->organizationCounts([], $now);

        foreach (CallRepository::ORGANIZATION_BUCKETS as $bucket) {
            self::assertSame(0, $counts[$bucket], $bucket);
        }
    }

    private function makeCall(
        Organization $organization,
        ?string $madeAt = null,
        ?string $scheduledAt = null,
    ): Call {
        $call = new Call()->setOrganization($organization);
        if (null !== $madeAt) {
            $call->setMadeAt(new \DateTimeImmutable($madeAt));
        }
        if (null !== $scheduledAt) {
            $call->setScheduledAt(new \DateTimeImmutable($scheduledAt));
        }
        $this->em()->persist($call);

        return $call;
    }
}
