<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\OrganizationHide;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testHomeShowsRenamedStatisticsSections(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/');
        $this->assertResponseIsSuccessful();

        $titles = $crawler->filter('.stats-home__title')->each(
            static fn(Crawler $node): string => trim($node->text()),
        );
        self::assertSame(
            ['Сделано звонков', 'Ожидают звонка', 'Просроченные звонки', 'Отписки организаций'],
            $titles,
        );

        $captions = [
            'called' => ['Сегодня', 'За 7 дней', 'За 30 дней'],
            'waiting' => ['Сегодня', 'За 7 дней', 'За 30 дней'],
            'overdue' => ['Вчера', 'За 7 дней', 'За 30 дней'],
            'optout' => ['сегодня', 'за 7 дней', 'за 30 дней'],
        ];
        foreach ($captions as $modifier => $expected) {
            self::assertSame(
                $expected,
                $crawler->filter('.stats-home__section--' . $modifier . ' .stats__caption')->each(
                    static fn(Crawler $node): string => trim($node->text()),
                ),
                'Подписи секции ' . $modifier,
            );
        }

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Обзвонено сегодня', $content);
        self::assertStringNotContainsString('В течение недели', $content);
        self::assertStringNotContainsString('В течение месяца', $content);
    }

    public function testBaseLayoutReferencesFavicon(): void
    {
        $crawler = $this->open('/login');

        $this->assertResponseIsSuccessful();
        $icon = $crawler->filter('head link[rel="icon"]');
        self::assertCount(1, $icon);
        self::assertSame('image/x-icon', $icon->attr('type'));
        self::assertStringEndsWith('/favicon.ico', (string) $icon->attr('href'));
    }

    public function testDashboardFiltersByInactiveAndOptout(): void
    {
        $active = $this->makeOrganization('ООО Активная');

        $inactive = $this->makeOrganization('ООО Неактивная');
        $inactive->setIsActive(false);

        $optedOut = $this->makeOrganization('ООО Отписавшаяся');
        $optedOut->setIsOptedOut(true);

        $inactiveOptedOut = $this->makeOrganization('ООО Неактивная Отписавшаяся');
        $inactiveOptedOut->setIsActive(false)->setIsOptedOut(true);

        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        // Без фильтров — все организации области доступа.
        $crawler = $this->open('/dashboard');
        foreach ([$active, $inactive, $optedOut, $inactiveOptedOut] as $organization) {
            self::assertSame(1, $crawler->filter('#org-' . $organization->id)->count());
        }

        // «Неактивные»: только isActive = false; фильтр отмечен в форме.
        $crawler = $this->open('/dashboard?inactive=1');
        self::assertSame(0, $crawler->filter('#org-' . $active->id)->count());
        self::assertSame(1, $crawler->filter('#org-' . $inactive->id)->count());
        self::assertSame(0, $crawler->filter('#org-' . $optedOut->id)->count());
        self::assertSame(1, $crawler->filter('#org-' . $inactiveOptedOut->id)->count());
        self::assertCount(1, $crawler->filter('input[name="inactive"][checked]'));
        self::assertCount(0, $crawler->filter('input[name="optout"][checked]'));

        // «Отписавшиеся»: только isOptedOut = true.
        $crawler = $this->open('/dashboard?optout=1');
        self::assertSame(0, $crawler->filter('#org-' . $active->id)->count());
        self::assertSame(0, $crawler->filter('#org-' . $inactive->id)->count());
        self::assertSame(1, $crawler->filter('#org-' . $optedOut->id)->count());
        self::assertSame(1, $crawler->filter('#org-' . $inactiveOptedOut->id)->count());

        // Пересечение: неактивные отписавшиеся.
        $crawler = $this->open('/dashboard?inactive=1&optout=1');
        self::assertSame(0, $crawler->filter('#org-' . $active->id)->count());
        self::assertSame(0, $crawler->filter('#org-' . $inactive->id)->count());
        self::assertSame(0, $crawler->filter('#org-' . $optedOut->id)->count());
        self::assertSame(1, $crawler->filter('#org-' . $inactiveOptedOut->id)->count());

        // Фильтры сохраняются в ссылках сортировки.
        $sortHref = (string) $crawler->filter('a.table__sortable')->first()->attr('href');
        self::assertStringContainsString('inactive=1', $sortHref);
        self::assertStringContainsString('optout=1', $sortHref);
    }

    public function testDashboardSortsByActivityAndOptOutDate(): void
    {
        $active = $this->makeOrganization('Активная');
        $inactive = $this->makeOrganization('Неактивная');
        $inactive->setIsActive(false);
        $optedOut = $this->makeOrganization('Отписавшаяся');
        $optedOut->setIsOptedOut(true)->setOptedOutAt(new \DateTimeImmutable('-3 days'));
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        // По активности (возрастание): неактивные впереди активных.
        $crawler = $this->open('/dashboard?sort=isActive&dir=asc');
        self::assertSame('Неактивная', $this->firstOrganizationName($crawler));

        // По дате отписки (возрастание): с датой — впереди, без даты — в конце.
        $crawler = $this->open('/dashboard?sort=optedOutAt&dir=asc');
        self::assertSame(
            ['Отписавшаяся', 'Активная', 'Неактивная'],
            $crawler->filter('.org-table__row .org-table__name-link')->each(
                static fn(Crawler $node): string => trim($node->text()),
            ),
        );
    }

    /**
     * Панель показывает страницу организаций (spec dashboard «Постраничный
     * просмотр панели организаций»): на странице не больше 50 строк, под
     * таблицей есть навигация, строки вне страницы в таблицу не попадают.
     */
    public function testDashboardShowsAtMostFiftyOrganizationsPerPage(): void
    {
        $this->makeOrganizations(137);
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/dashboard');

        $this->assertResponseIsSuccessful();
        self::assertCount(50, $crawler->filter('.org-table__row'));
        self::assertCount(1, $crawler->filter('nav.pagination'));
        // Первые 50 строк по алфавиту; 51-я уже на второй странице.
        self::assertSame('Организация 001', $this->firstOrganizationName($crawler));

        $crawler = $this->open('/dashboard?page=2');
        self::assertCount(50, $crawler->filter('.org-table__row'));
        self::assertSame('Организация 051', $this->firstOrganizationName($crawler));

        // Последняя страница короче и содержит остаток выборки.
        $crawler = $this->open('/dashboard?page=3');
        self::assertCount(37, $crawler->filter('.org-table__row'));
        self::assertSame('Организация 101', $this->firstOrganizationName($crawler));
    }

    /**
     * В блоке навигации нет отдельных стрелок «Назад»/«Вперёд»: соседние
     * страницы и так видны в окне номеров (spec web-interface «Пагинация
     * списков»).
     */
    public function testDashboardPaginationHasNoPrevNextArrows(): void
    {
        $this->makeOrganizations(137);
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/dashboard?page=2');

        $this->assertResponseIsSuccessful();
        $nav = $crawler->filter('nav.pagination');
        $text = (string) $nav->text();
        self::assertStringNotContainsString('Назад', $text);
        self::assertStringNotContainsString('Вперёд', $text);
        self::assertCount(0, $nav->filter('[rel="prev"], [rel="next"]'));
        self::assertCount(0, $nav->filter('.pagination__link--disabled'));
        // Номера страниц при этом на месте, включая соседние.
        self::assertCount(2, $nav->filter('a.pagination__page'));
        self::assertCount(1, $nav->filter('[aria-current="page"]'));
        // Переходы на первую и последнюю страницу остались.
        self::assertCount(1, $nav->filter('[rel="first"]'));
        self::assertCount(1, $nav->filter('[rel="last"]'));
    }

    public function testDashboardPageNumberIsInTheUrl(): void
    {
        $this->makeOrganizations(137);
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/dashboard?page=2');

        $this->assertResponseIsSuccessful();
        self::assertSame('2', $this->query('page'));

        // Номер открытой страницы виден в блоке навигации и отмечен, соседние
        // номера — обычные ссылки, а переходы на первую и последнюю страницу
        // есть на своих местах.
        self::assertSame('2', $crawler->filter('nav.pagination [aria-current="page"]')->text());
        self::assertStringContainsString('page=1', (string) $crawler->filter('nav.pagination a[rel="first"]')->attr('href'));
        self::assertStringContainsString('page=3', (string) $crawler->filter('nav.pagination a[rel="last"]')->attr('href'));
        self::assertSame(
            ['1', '3'],
            $crawler->filter('nav.pagination a.pagination__page')->each(
                static fn(Crawler $node): string => trim($node->text()),
            ),
        );
    }

    /**
     * @param string|int $requested
     */
    #[DataProvider('provideDashboardPageNumberOutsideTheRangeResolvesToLastPageCases')]
    public function testDashboardPageNumberOutsideTheRangeResolvesToLastPage(string|int $requested, int $expectedPage): void
    {
        $this->makeOrganizations(137);
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/dashboard?page=' . $requested);

        // Канонический URL: по нему видно, какая страница открыта.
        $crawler = $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        self::assertSame((string) $expectedPage, $this->query('page'));
        self::assertSame(
            3 === $expectedPage ? 'Организация 101' : 'Организация 001',
            $this->firstOrganizationName($crawler),
        );
    }

    /**
     * @return iterable<string, array{string|int, int}>
     */
    public static function provideDashboardPageNumberOutsideTheRangeResolvesToLastPageCases(): iterable
    {
        yield 'номер больше числа страниц' => ['999', 3];
        yield 'нечисловой номер' => ['abc', 1];
        yield 'нулевой номер' => ['0', 1];
    }

    public function testDashboardKeepsFirstPageWithoutPageParameter(): void
    {
        $this->makeOrganizations(137);
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/dashboard');

        $this->assertResponseIsSuccessful();
        self::assertCount(50, $crawler->filter('.org-table__row'));
        self::assertSame('Организация 001', $this->firstOrganizationName($crawler));
    }

    public function testDashboardWithoutPaginationBlockOnShortList(): void
    {
        $this->makeOrganizations(50);
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/dashboard');

        $this->assertResponseIsSuccessful();
        self::assertCount(50, $crawler->filter('.org-table__row'));
        self::assertCount(0, $crawler->filter('nav.pagination'));
    }

    public function testDashboardPaginationCountsOnlyManagerAccessScope(): void
    {
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $this->makeOrganizations(101);

        // Организация скрыта от менеджера (ADR-0012): в его области доступа
        // остаётся 100 организаций — две страницы вместо трёх.
        $hidden = $this->em()->getRepository(Organization::class)->findOneBy(['name' => 'Организация 101']);
        self::assertNotNull($hidden);
        $this->em()->persist(new OrganizationHide($hidden, $manager));
        $this->em()->flush();

        $this->login($manager);

        $crawler = $this->open('/dashboard?page=999');
        $crawler = $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        self::assertSame('2', $this->query('page'));
        self::assertCount(50, $crawler->filter('.org-table__row'));

        // У администратора скрытие не ограничивает область доступа (ADR-0008):
        // те же 101 организация дают третью страницу.
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $crawler = $this->open('/dashboard?page=999');
        $crawler = $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        self::assertSame('3', $this->query('page'));
        self::assertCount(1, $crawler->filter('.org-table__row'));
    }

    public function testDashboardSortingLinkReturnsToFirstPageAndKeepsConditions(): void
    {
        $this->makeOrganizations(137);
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/dashboard?page=3&sort=name&dir=asc');
        $sortHref = (string) $crawler->filter('a.table__sortable')->first()->attr('href');

        // Номер страницы не передаётся — это и есть возврат к первой странице.
        self::assertStringNotContainsString('page=', $sortHref);
        self::assertStringContainsString('sort=name', $sortHref);
        self::assertStringContainsString('dir=desc', $sortHref);
    }

    public function testDashboardSortLinkKeepsSearchAndFilters(): void
    {
        $organizations = $this->makeOrganizations(137);
        $organizations[0]->setIsActive(false)->setIsOptedOut(true);
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/dashboard?q=Организация&sort=lastCall&dir=desc&inactive=1&optout=1&page=1');
        $sortHref = (string) $crawler->filter('a.table__sortable')->first()->attr('href');

        self::assertStringNotContainsString('page=', $sortHref);
        self::assertStringContainsString('q=', $sortHref);
        self::assertStringContainsString('inactive=1', $sortHref);
        self::assertStringContainsString('optout=1', $sortHref);
    }

    public function testDashboardSearchFormCarriesSortAndResetsPage(): void
    {
        $this->makeOrganizations(137);
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/dashboard?sort=optedOutAt&dir=desc&page=3');

        // Скрытые sort/dir: поиск не стирает выбранную сортировку.
        self::assertSame('optedOutAt', $crawler->filter('form.org-search input[name="sort"]')->attr('value'));
        self::assertSame('desc', $crawler->filter('form.org-search input[name="dir"]')->attr('value'));

        $form = $crawler->filter('form.org-search')->form();
        self::assertArrayNotHasKey('page', $form->getPhpValues());

        $crawler = $this->client->submit($form, ['q' => 'Организация 005']);

        $this->assertResponseIsSuccessful();
        // Сортировка сохранена, страница первая.
        self::assertSame('optedOutAt', $this->query('sort'));
        self::assertSame('desc', $this->query('dir'));
        self::assertNull($this->query('page'));
        self::assertSame('Организация 005', $this->firstOrganizationName($crawler));
    }

    public function testDashboardSearchResetsPage(): void
    {
        $this->makeOrganizations(137);
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        // Без поиска организация 005 на третьей странице.
        self::assertSame('Организация 101', $this->firstOrganizationName($this->open('/dashboard?page=3')));

        // Поиск открывает первую страницу и сужает выборку.
        $crawler = $this->open('/dashboard?q=Организация%2000');
        $this->assertResponseIsSuccessful();
        self::assertNull($this->query('page'));
        self::assertCount(9, $crawler->filter('.org-table__row'));
        self::assertSame('Организация 001', $this->firstOrganizationName($crawler));
    }

    public function testDashboardFilterToggleReturnsToFirstPage(): void
    {
        $this->makeOrganizations(60);
        $this->em()->getRepository(Organization::class)->findOneBy(['name' => 'Организация 060'])
            ->setIsActive(false);
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/dashboard?page=2');
        $this->assertResponseIsSuccessful();
        self::assertCount(10, $crawler->filter('.org-table__row'));

        // Фильтр «Неактивные» сокращает выборку до одной строки — это первая
        // страница, номер страницы не переносится.
        $crawler = $this->open('/dashboard?inactive=1');
        $this->assertResponseIsSuccessful();
        self::assertSame('Организация 060', $this->firstOrganizationName($crawler));
        self::assertCount(0, $crawler->filter('nav.pagination'));
    }

    public function testDashboardDateSortingIsStableAcrossPagesAndKeepsEmptyDatesLast(): void
    {
        $organizations = $this->makeOrganizations(60);
        // У части организаций есть дата отписки, у части — нет.
        foreach ($organizations as $index => $organization) {
            if (0 === $index % 3) {
                continue;
            }
            $organization->setIsOptedOut(true)->setOptedOutAt(new \DateTimeImmutable(\sprintf('-%d days', 1 + $index)));
        }
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        foreach (['asc', 'desc'] as $dir) {
            $names = [];
            foreach ([1, 2] as $page) {
                $crawler = $this->open('/dashboard?sort=optedOutAt&dir=' . $dir . '&page=' . $page);
                $this->assertResponseIsSuccessful();
                $names = [...$names, ...$this->organizationNames($crawler)];
            }

            // 60 организаций: 20 без даты отписки (каждая третья) идут
            // последними при обоих направлениях, а порядок дат на второй
            // странице продолжает порядок первой.
            self::assertCount(60, $names);
            self::assertCount(20, array_filter(
                array_map($this->nameIndex(...), $names),
                static fn(int $number): bool => 1 === $number % 3,
            ), 'Организации без даты отписки должны стоять последними при dir=' . $dir);
            // Каждая третья организация без даты отписки выпадает из
            // упорядоченной части: 060, 059, 057, 056 при возрастании.
            self::assertSame(
                'desc' === $dir ? [2, 3, 5, 6] : [60, 59, 57, 56],
                array_map($this->nameIndex(...), \array_slice($names, 0, 4)),
                'Порядок дат должен быть одинаковым независимо от границы страницы.',
            );
        }
    }

    public function testDashboardSortingByNextCallDateKeepsEmptyDatesLast(): void
    {
        $organizations = $this->makeOrganizations(60);
        foreach ($organizations as $index => $organization) {
            if (0 === $index % 2) {
                continue;
            }
            $this->em()->persist(
                (new \App\Entity\Call())
                    ->setOrganization($organization)
                    ->setScheduledAt(new \DateTimeImmutable(\sprintf('+%d days', 1 + $index))),
            );
        }
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/dashboard?sort=nextCall&dir=asc');
        $names = $this->organizationNames($crawler);

        // Планы есть у каждой второй организации (чётные номера), они идут
        // первыми по возрастанию даты, без плана — в конце.
        self::assertCount(50, $names);
        self::assertSame(2, $this->nameIndex($names[0]));
        self::assertSame(60, $this->nameIndex($names[29]));
        foreach (\array_slice($names, 30, 5) as $name) {
            self::assertSame(1, $this->nameIndex($name) % 2, 'Без плана должно быть в конце при dir=asc');
        }
    }

    public function testDashboardIdenticalNamesDoNotMigrateBetweenPages(): void
    {
        // Одинаковые названия легальны: уникального ограничения в БД нет.
        $em = $this->em();
        $ids = [];
        for ($i = 1; $i <= 60; ++$i) {
            $organization = (new Organization())->setName('Одно название')->setIndustry('IT');
            $em->persist($organization);
            $ids[] = $organization;
        }
        $em->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $firstPage = $this->organizationRowIds($this->open('/dashboard?page=1'));
        $secondPage = $this->organizationRowIds($this->open('/dashboard?page=2'));

        self::assertCount(50, $firstPage);
        self::assertCount(10, $secondPage);
        // Одна и та же организация не оказалась на двух страницах.
        self::assertSame([], array_intersect($firstPage, $secondPage));
        // Порядок одинаковых названий между страницами устойчив.
        self::assertSame($firstPage, $this->organizationRowIds($this->open('/dashboard?page=1')));
    }

    public function testDashboardHighlightOpensPageWithTheOrganization(): void
    {
        $organizations = $this->makeOrganizations(137);
        $first = $organizations[0];
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        // Я–А: организация 001 встаёт последней, то есть на третьей странице.
        $crawler = $this->open('/dashboard?sort=name&dir=desc&highlight=' . $first->id);

        // Открытая страница отражается в URL — переход на неё канонический.
        $crawler = $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        self::assertSame('3', $this->query('page'));
        self::assertCount(1, $crawler->filter('tr.org-table__row--highlight#org-' . $first->id));
        self::assertCount(1, $crawler->filter('tr.org-table__row--expanded#org-' . $first->id));
        // Страница 137 строк по Я–А: строки 101–137, то есть 037 … 001.
        self::assertSame('Организация 037', $this->firstOrganizationName($crawler));
        self::assertSame('Организация 001', $this->lastOrganizationName($crawler));
    }

    public function testDashboardHighlightOnCurrentPageKeepsPageNumber(): void
    {
        $organizations = $this->makeOrganizations(137);
        $target = $organizations[1];
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/dashboard?page=1&highlight=' . $target->id);

        $this->assertResponseIsSuccessful();
        self::assertSame('1', $this->query('page'));
        self::assertCount(1, $crawler->filter('tr.org-table__row--highlight#org-' . $target->id));
        self::assertCount(1, $crawler->filter('tr.org-table__row--expanded#org-' . $target->id));
    }

    public function testDashboardHighlightBySortByDateOpensTheRightPage(): void
    {
        $organizations = $this->makeOrganizations(137);
        $last = $organizations[136];
        foreach ([60, 70, 80] as $index => $organization) {
            $this->em()->persist(
                (new \App\Entity\Call())
                    ->setOrganization($organizations[$index])
                    ->setMadeAt(new \DateTimeImmutable(\sprintf('-%d days', $index + 1))),
            );
        }
        // Дата отписки есть только у одной организации — самой последней по
        // имени, поэтому по sort=optedOutAt&dir=asc она первая, а по desc —
        // она же единственная с датой и должна оказаться на первой странице.
        $last->setIsOptedOut(true)->setOptedOutAt(new \DateTimeImmutable('-1 day'));
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/dashboard?sort=optedOutAt&dir=asc&highlight=' . $last->id);

        $this->assertResponseIsSuccessful();
        self::assertSame('Организация 137', $this->firstOrganizationName($crawler));
        self::assertCount(1, $crawler->filter('tr.org-table__row--highlight#org-' . $last->id));
    }

    public function testDashboardContactOrganizationSelectIsNotPaginated(): void
    {
        $this->makeOrganizations(137);
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/dashboard?page=3');

        $this->assertResponseIsSuccessful();
        self::assertCount(37, $crawler->filter('.org-table__row'));
        // Элемент формы, а не таблица: все организации области доступа, а не
        // 37 строк последней страницы.
        self::assertCount(
            138,
            $crawler->filter('select[data-contact-field="organization"] option'),
            '137 организаций плюс пустой пункт выбора.',
        );
    }

    public function testDashboardClearedSearchDropsQFromTheUrl(): void
    {
        $this->makeOrganizations(3);
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        // GET-форма отправляет пустое поле как `q=`; канонический URL
        // очищает его, чтобы в строке не оставался мусорный след отправки.
        $this->open('/dashboard?q=');
        $crawler = $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        self::assertSame('/dashboard', $this->client->getRequest()->getRequestUri());
        self::assertCount(3, $crawler->filter('.org-table__row'));
    }

    public function testDashboardKeepsFiltersWhileDroppingEmptySearch(): void
    {
        $organizations = $this->makeOrganizations(3);
        $organizations[0]->setIsActive(false);
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/dashboard?q=&inactive=1');
        $crawler = $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        self::assertSame('/dashboard?inactive=1', $this->client->getRequest()->getRequestUri());
        self::assertCount(1, $crawler->filter('.org-table__row'));
    }

    public function testDashboardSearchHasNoAutomaticSubmitHook(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/dashboard');
        $content = (string) $this->client->getResponse()->getContent();

        // Автоsubmit по мере ввода удалён: поиск уходит только по кнопке
        // «Найти» и по Enter нативным поведением GET-формы.
        self::assertCount(0, $crawler->filter('[data-dashboard-search]'));
        self::assertStringNotContainsString('data-dashboard-search', $content);
        self::assertCount(1, $crawler->filter('form.org-search button[type="submit"]'));
    }

    private function firstOrganizationName(Crawler $crawler): string
    {
        return trim($crawler->filter('.org-table__row .org-table__name-link')->first()->text());
    }

    private function lastOrganizationName(Crawler $crawler): string
    {
        return trim($crawler->filter('.org-table__row .org-table__name-link')->last()->text());
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
     * @return Organization[]
     */
    private function makeOrganizations(int $count): array
    {
        $em = $this->em();
        $organizations = [];
        for ($index = 1; $index <= $count; ++$index) {
            $organization = (new Organization())
                ->setName(\sprintf('Организация %03d', $index))
                ->setIndustry('IT');
            $em->persist($organization);
            $organizations[] = $organization;
        }
        $em->flush();

        return $organizations;
    }

    /**
     * @return string[]
     */
    private function organizationNames(Crawler $crawler): array
    {
        return $crawler->filter('.org-table__row .org-table__name-link')->each(
            static fn(Crawler $node): string => trim($node->text()),
        );
    }

    /**
     * @return int[]
     */
    private function organizationRowIds(Crawler $crawler): array
    {
        return $crawler->filter('.org-table__row')->each(
            static fn(Crawler $node): int => (int) $node->attr('data-org-id'),
        );
    }

    /**
     * Параметр query-строки последнего запроса.
     */
    private function query(string $name): ?string
    {
        return $this->client->getRequest()->query->get($name);
    }

    /**
     * Порядковый номер организации из её названия («Организация 007» → 7).
     */
    private function nameIndex(string $name): int
    {
        return (int) substr($name, -3);
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
