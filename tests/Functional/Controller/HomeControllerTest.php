<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Функциональные тесты HomeController: статистика дашборда.
 */
final class HomeControllerTest extends DatabaseWebTestCase
{
    public function testDashboardShowsOptOutStatistics(): void
    {
        $now = new \DateTimeImmutable();

        // Организация с отпиской сегодня
        $org1 = $this->makeOrganization('ООО Отписана Сегодня');
        $org1->setIsOptedOut(true)->setOptedOutAt($now->modify('-2 hours'));

        // Организация с отпиской за 7 дней
        $org2 = $this->makeOrganization('ООО Отписана Неделя');
        $org2->setIsOptedOut(true)->setOptedOutAt($now->modify('-3 days'));

        // Организация с отпиской за 30 дней
        $org3 = $this->makeOrganization('ООО Отписана Месяц');
        $org3->setIsOptedOut(true)->setOptedOutAt($now->modify('-15 days'));

        // Организация без отписки
        $this->makeOrganization('ООО Активная');

        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/');

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Отписки организаций', $content);
        self::assertSame(
            ['сегодня', 'за 7 дней', 'за 30 дней'],
            $crawler->filter('.stats-home__section--optout .stats__caption')->each(
                static fn(Crawler $node): string => trim($node->text()),
            ),
        );
    }

    public function testDashboardOptOutStatsRespectManagerScope(): void
    {
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $group = (new \App\Entity\OrganizationGroup())->setName('Группа менеджера')->setCreatedBy($manager);
        $this->em()->persist($group);

        // Managed organization with opt-out
        $managed = $this->makeOrganization('ООО Моя');
        $managed->setIsOptedOut(true)->setOptedOutAt(new \DateTimeImmutable('-3 days'));
        $this->em()->persist(new \App\Entity\OrgGroupMembership($managed, $group));

        // Hidden (inaccessible) organization with opt-out
        $hidden = $this->makeOrganization('ООО Скрытая');
        $hidden->setIsOptedOut(true)->setOptedOutAt(new \DateTimeImmutable('-3 days'));
        $this->em()->persist(new \App\Entity\OrganizationHide($hidden, $manager));

        $this->em()->flush();
        $this->login($manager);

        $crawler = $this->open('/');

        $this->assertResponseIsSuccessful();

        // Видимая менеджеру организация учитывается в показателях, скрытая —
        // нет: если бы скрытая учитывалась, «за 7 дней»/«за 30 дней» были бы 2.
        self::assertSame(
            ['0', 'Из письма: 0', '/dashboard?filter=optoutEmail1'],
            $this->optOutItem($crawler, 'сегодня'),
        );
        self::assertSame(
            ['1', 'Из письма: 0', '/dashboard?filter=optoutEmail7'],
            $this->optOutItem($crawler, 'за 7 дней'),
        );
        self::assertSame(
            ['1', 'Из письма: 0', '/dashboard?filter=optoutEmail30'],
            $this->optOutItem($crawler, 'за 30 дней'),
        );
    }

    public function testHomeRedirectsUnauthenticatedUser(): void
    {
        $this->client->request('GET', '/');

        $this->assertResponseRedirects('/login');
    }

    public function testHomeShowsOptOutSection(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/');

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Отписки организаций', $content);
        self::assertStringContainsString('Из письма', $content);
        self::assertSame(
            ['сегодня', 'за 7 дней', 'за 30 дней'],
            $crawler->filter('.stats-home__section--optout .stats__caption')->each(
                static fn(Crawler $node): string => trim($node->text()),
            ),
        );
    }

    public function testDashboardOptOutEmailSubMetrics(): void
    {
        $now = new \DateTimeImmutable();

        // Отписка из письма сегодня
        $emailToday = $this->makeOrganization('ООО Из Письма Сегодня');
        $emailToday->setIsOptedOut(true)
            ->setOptOutReason('Отписка из письма')
            ->setOptedOutAt($now->setTime(0, 0));

        // Отписка из письма 3 дня назад — с запасом от границы 7 дней
        $emailWeek = $this->makeOrganization('ООО Из Письма Неделя');
        $emailWeek->setIsOptedOut(true)
            ->setOptOutReason('Отписка из письма')
            ->setOptedOutAt($now->modify('-3 days'));

        // Отписка из письма 15 дней назад — с запасом от границы 30 дней
        $emailMonth = $this->makeOrganization('ООО Из Письма Месяц');
        $emailMonth->setIsOptedOut(true)
            ->setOptOutReason('Отписка из письма')
            ->setOptedOutAt($now->modify('-15 days'));

        // Отписка по другой причине: только в основной цифре, не в подметрике
        $other = $this->makeOrganization('ООО Другая Причина');
        $other->setIsOptedOut(true)
            ->setOptOutReason('Не интересно')
            ->setOptedOutAt($now->modify('-3 days'));

        // Организация без отписки
        $this->makeOrganization('ООО Активная');

        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/');

        $this->assertResponseIsSuccessful();

        // Email-причина учитывается и в основной цифре, и в подметрике;
        // другая причина — только в основной цифре. Подметрика — ссылка
        // с периодным filter-параметром <category><days>.
        self::assertSame(
            ['1', 'Из письма: 1', '/dashboard?filter=optoutEmail1'],
            $this->optOutItem($crawler, 'сегодня'),
        );
        self::assertSame(
            ['3', 'Из письма: 2', '/dashboard?filter=optoutEmail7'],
            $this->optOutItem($crawler, 'за 7 дней'),
        );
        self::assertSame(
            ['4', 'Из письма: 3', '/dashboard?filter=optoutEmail30'],
            $this->optOutItem($crawler, 'за 30 дней'),
        );
    }

    public function testDashboardHasNoSeparateAllTimeOptOutEmailFigure(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/');

        $this->assertResponseIsSuccessful();

        // В блоке «Отписки организаций» ровно три периодные цифры, отдельной
        // all-time цифры «Из письма» и её ссылки «По организациям» нет.
        $optOutItems = $crawler->filter('.stats-home__section--optout .stats__item');
        self::assertCount(3, $optOutItems);

        $captions = $optOutItems->each(
            static fn(Crawler $item): string => trim($item->filter('.stats__caption')->text()),
        );
        self::assertSame(['сегодня', 'за 7 дней', 'за 30 дней'], $captions);

        // Индикаторов «По организациям» под отписками нет, all-time bucket
        // optoutEmail не используется — только периодные optoutEmail1/7/30.
        self::assertCount(0, $crawler->filter('.stats-home__section--optout a.stats__orgs'));
        $hrefs = $crawler->filter('.stats-home__section--optout a')->each(
            static fn(Crawler $link): string => (string) $link->attr('href'),
        );
        self::assertSame(
            ['/dashboard?filter=optoutEmail1', '/dashboard?filter=optoutEmail7', '/dashboard?filter=optoutEmail30'],
            $hrefs,
        );
    }

    private function makeUser(string $login, string $email, UserRole $role): User
    {
        $user = new User()
            ->setLogin($login)
            ->setEmail($email)
            ->setRole($role);
        $user->setPassword('test-password-hash');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function makeOrganization(string $name): Organization
    {
        $organization = new Organization()->setName($name)->setIndustry('IT');
        $this->em()->persist($organization);
        $this->em()->flush();

        return $organization;
    }

    /**
     * Показатель блока «Отписки организаций»: [основная цифра, подметрика, href подметрики].
     *
     * @return array{string, string, string}
     */
    private function optOutItem(Crawler $crawler, string $caption): array
    {
        $item = $crawler->filter('.stats-home__section--optout .stats__item')->reduce(
            static fn(Crawler $node): bool => trim($node->filter('.stats__caption')->text()) === $caption,
        );

        self::assertCount(1, $item, \sprintf('Показатель «%s» не найден.', $caption));
        self::assertCount(1, $item->filter('a.stats__sub'), \sprintf('Подметрика «%s» не найдена.', $caption));

        return [
            trim($item->filter('.stats__figure')->text()),
            trim($item->filter('a.stats__sub')->text()),
            (string) $item->filter('a.stats__sub')->attr('href'),
        ];
    }
}
