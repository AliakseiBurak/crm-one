<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\OrganizationHide;
use App\Entity\User;
use App\Repository\OrganizationHideRepository;
use App\Tests\DatabaseWebTestCase;

/**
 * Функциональные тесты OrganizationHideController (change
 * organization-hiding): админский реестр «Скрытые организации», форма
 * скрытия, 403 для менеджера, валидация и конфликты дубликатов.
 */
final class OrganizationHideControllerTest extends DatabaseWebTestCase
{
    public function testManagerGets403OnHidesList(): void
    {
        $this->login($this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager));

        $this->client->request('GET', '/admin/hides');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testManagerCannotCreateHideViaPost(): void
    {
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Ромашка');
        $this->em()->flush();
        $this->login($manager);

        $this->client->request('POST', '/admin/hides', [
            'organization' => $org->id,
            'managers' => [$manager->id],
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertCount(0, $this->repo()->findAll());
    }

    public function testManagerCannotDeleteHideViaPost(): void
    {
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $org = $this->makeOrganization('ООО Ромашка');
        $hide = new OrganizationHide($org, $manager);
        $this->em()->persist($hide);
        $this->em()->flush();
        $hideId = $hide->id;
        $this->login($manager);

        $this->client->request('POST', '/admin/hides/' . $hideId . '/delete');

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertNotNull($this->repo()->find($hideId));
    }

    public function testAdminSeesEmptyRegistry(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->client->request('GET', '/admin/hides');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Скрытые организации');
        self::assertStringContainsString('Скрытых организаций нет.', $crawler->text());
        // Встроенная форма добавления присутствует даже при пустом реестре.
        self::assertGreaterThanOrEqual(1, $crawler->filter('select[name="organization"]')->count());
    }

    public function testAdminCreatesHideForSpecificManager(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager1 = $this->makeUser('manager1', 'manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2', 'manager2@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Ромашка');
        $this->em()->flush();
        $this->login($admin);

        $token = $this->formToken();
        $this->client->request('POST', '/admin/hides', [
            '_csrf_token' => $token,
            'organization' => $org->id,
            'managers' => [$manager1->id],
        ]);

        $this->assertResponseRedirects('/admin/hides');
        $this->em()->clear();
        self::assertNotNull($this->repo()->findOneByOrganizationAndManager($org, $manager1));
        self::assertNull($this->repo()->findOneByOrganizationAndManager($org, $manager2));
    }

    public function testAdminCreatesHideForAllManagers(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $this->makeUser('manager1', 'manager1@b2b-crm.loc', UserRole::Manager);
        $this->makeUser('manager2', 'manager2@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Ромашка');
        $this->em()->flush();
        $this->login($admin);

        $token = $this->formToken();
        $this->client->request('POST', '/admin/hides', [
            '_csrf_token' => $token,
            'organization' => $org->id,
        ]);

        $this->assertResponseRedirects('/admin/hides');
        $this->em()->clear();
        self::assertCount(2, $this->repo()->findForOrganization($org));
        // Администратор не является менеджером: записи для него не создаются.
        self::assertNull($this->repo()->findOneByOrganizationAndManager($org, $admin));
    }

    public function testEmptyManagersMeansHideFromAll(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $this->makeUser('manager1', 'manager1@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Ромашка');
        $this->em()->flush();
        $this->login($admin);

        $token = $this->formToken();
        $this->client->request('POST', '/admin/hides', [
            '_csrf_token' => $token,
            'organization' => $org->id,
            'managers' => [],
        ]);

        $this->assertResponseRedirects('/admin/hides');
        $this->em()->clear();
        self::assertCount(1, $this->repo()->findForOrganization($org));
    }

    public function testCreateWithoutOrganizationShowsError(): void
    {
        $this->makeUser('manager1', 'manager1@b2b-crm.loc', UserRole::Manager);
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $token = $this->formToken();
        $this->client->request('POST', '/admin/hides', [
            '_csrf_token' => $token,
            'organization' => '',
            'managers' => [1],
        ]);

        $this->assertResponseRedirects('/admin/hides');
        $this->client->followRedirect();
        self::assertStringContainsString('Организация обязательна для выбора', $this->client->getResponse()->getContent());
        $this->em()->clear();
        self::assertCount(0, $this->repo()->findAll());
    }

    public function testCreateRejectsNonManagerUsers(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $org = $this->makeOrganization('ООО Ромашка');
        $this->em()->flush();
        $this->login($admin);

        $token = $this->formToken();
        $this->client->request('POST', '/admin/hides', [
            '_csrf_token' => $token,
            'organization' => $org->id,
            'managers' => [$admin->id],
        ]);

        $this->assertResponseRedirects('/admin/hides');
        $this->client->followRedirect();
        self::assertStringContainsString('Скрыть можно только от менеджеров', $this->client->getResponse()->getContent());
        $this->em()->clear();
        self::assertCount(0, $this->repo()->findAll());
    }

    public function testCreateDuplicatePairShowsConflictError(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager1 = $this->makeUser('manager1', 'manager1@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Ромашка');
        $this->em()->persist(new OrganizationHide($org, $manager1));
        $this->em()->flush();
        $this->login($admin);

        $token = $this->formToken();
        $this->client->request('POST', '/admin/hides', [
            '_csrf_token' => $token,
            'organization' => $org->id,
            'managers' => [$manager1->id],
        ]);

        $this->assertResponseRedirects('/admin/hides');
        $this->client->followRedirect();
        self::assertStringContainsString('Организация уже скрыта от: manager1@b2b-crm.loc', $this->client->getResponse()->getContent());
        $this->em()->clear();
        self::assertCount(1, $this->repo()->findAll());
    }

    public function testAdminUnhidesSingleManager(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager1 = $this->makeUser('manager1', 'manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2', 'manager2@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Ромашка');
        $hide1 = new OrganizationHide($org, $manager1);
        $this->em()->persist($hide1);
        $this->em()->persist(new OrganizationHide($org, $manager2));
        $this->em()->flush();
        $this->login($admin);

        $token = $this->formToken();
        $this->client->request('POST', '/admin/hides/' . $hide1->id . '/delete', [
            '_csrf_token' => $token,
        ]);

        $this->assertResponseRedirects('/admin/hides');
        $this->em()->clear();
        self::assertNull($this->repo()->findOneByOrganizationAndManager($org, $manager1));
        self::assertNotNull($this->repo()->findOneByOrganizationAndManager($org, $manager2));
    }

    public function testRegistryListsOrganizationManagerAndHiddenAt(): void
    {
        $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager1', 'manager1@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Ромашка');
        $this->em()->persist(new OrganizationHide($org, $manager));
        $this->em()->flush();
        $this->login($this->em()->getRepository(User::class)->findOneBy(['email' => 'admin@b2b-crm.loc']));

        $crawler = $this->client->request('GET', '/admin/hides');

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('ООО Ромашка', $crawler->text());
        self::assertStringContainsString('manager1@b2b-crm.loc', $crawler->text());
        self::assertSame(1, $crawler->filter('form[action$="/delete"]')->count());
        // Встроенная форма добавления: два дропдауна + кнопка.
        self::assertSame(1, $crawler->filter('select[name="organization"]')->count());
        self::assertSame(1, $crawler->filter('select[name="managers[]"]')->count());
    }

    public function testAdminNavContainsHidesEntry(): void
    {
        $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $this->em()->flush();
        $this->login($this->em()->getRepository(User::class)->findOneBy(['email' => 'admin@b2b-crm.loc']));

        $crawler = $this->client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        // Админские страницы ушли из основной навигации в выпадающий
        // список «⚙ Админ» (change menu-header-footer).
        self::assertSame(0, $crawler->filter('.header__nav a[href="/admin/hides"]')->count());
        // Пункт есть и в верхней строке, и в мобильной боковой панели: панель
        // повторяет содержимое правой части шапки (change
        // add-admin-menu-to-mobile-sidebar), поэтому сверху он один, а всего —
        // два.
        self::assertSame(1, $crawler->filter('.header__actions .header-admin__menu a[href="/admin/hides"]')->count());
        self::assertSame(2, $crawler->filter('.header-admin__menu a[href="/admin/hides"]')->count());
    }

    public function testManagerNavHasNoHidesEntry(): void
    {
        $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $this->em()->flush();
        $this->login($this->em()->getRepository(User::class)->findOneBy(['email' => 'manager@b2b-crm.loc']));

        $crawler = $this->client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('a[href="/admin/hides"]')->count());
    }

    public function testOrgEditFormShowsHidesForAdminOnly(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $otherManager = $this->makeUser('manager2', 'manager2@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Ромашка');
        // Группа принадлежит otherManager — менеджер получает доступ к org.
        $group = (new \App\Entity\OrganizationGroup())
            ->setName('Тестовая')
            ->setCreatedBy($otherManager);
        $this->em()->persist($group);
        $this->em()->persist(new \App\Entity\OrgGroupMembership($org, $group));
        $this->em()->persist(new OrganizationHide($org, $manager));
        $this->em()->flush();

        // Админ видит секцию скрытий с записью.
        $this->login($admin);
        $crawler = $this->client->request('GET', '/organizations/' . $org->id . '/edit');
        $this->assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(1, $crawler->filter('.organization-form__hides')->count());
        self::assertStringContainsString('manager@b2b-crm.loc', $crawler->text());

        // Менеджер не видит секцию скрытий (is_granted('ROLE_ADMIN') = false).
        $this->login($otherManager);
        $crawler = $this->client->request('GET', '/organizations/' . $org->id . '/edit');
        $this->assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.organization-form__hides')->count());
    }

    public function testUnhideViaEditFormRemovesHideRow(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Ромашка');
        $hide = new OrganizationHide($org, $manager);
        $this->em()->persist($hide);
        $this->em()->flush();
        $hideId = $hide->id;
        $this->login($admin);

        // Клик «Показать» на странице редактирования отправляет форму с name="unhide".
        $this->client->request('POST', '/organizations/' . $org->id . '/edit', [
            '_csrf_token' => $this->getEditCsrfToken($org->id),
            'unhide' => $hideId,
        ]);

        $this->assertResponseRedirects('/organizations/' . $org->id . '/edit');
        $this->em()->clear();
        self::assertNull($this->repo()->find($hideId));
        // Организация не пострадала.
        self::assertSame('ООО Ромашка', $this->organizations()->find($org->id)->name);
    }

    private function getEditCsrfToken(int $orgId): string
    {
        $crawler = $this->client->request('GET', '/organizations/' . $orgId . '/edit');

        return $crawler->filter('input[name="_csrf_token"]')->first()->attr('value');
    }

    public function testManagerCannotUnhideViaOrgEdit(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Ромашка');
        $hide = new OrganizationHide($org, $manager);
        $this->em()->persist($hide);
        $this->em()->flush();
        $hideId = $hide->id;

        // Организация скрыта от менеджера → 403 даже на GET /edit.
        $this->login($manager);
        $this->client->request('GET', '/organizations/' . $org->id . '/edit');
        $this->assertResponseStatusCodeSame(403);

        // Попытка POST с unhide также блокируется (организация вне области доступа).
        $this->client->request('POST', '/organizations/' . $org->id . '/edit', [
            '_csrf_token' => 'dummy',
            'unhide' => $hideId,
        ]);
        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        // Запись скрытия не удалена.
        self::assertNotNull($this->repo()->find($hideId));
    }

    // --- CSRF rejection tests ---

    public function testAdminCreateHideRejectsInvalidCsrfToken(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Ромашка');
        $this->em()->flush();
        $this->login($admin);

        $this->client->request('POST', '/admin/hides', [
            '_csrf_token' => 'invalid',
            'organization' => $org->id,
            'managers' => [$manager->id],
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertCount(0, $this->repo()->findAll());
    }

    public function testAdminDeleteHideRejectsInvalidCsrfToken(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Ромашка');
        $hide = new OrganizationHide($org, $manager);
        $this->em()->persist($hide);
        $this->em()->flush();
        $hideId = $hide->id;
        $this->login($admin);

        $this->client->request('POST', '/admin/hides/' . $hideId . '/delete', [
            '_csrf_token' => 'invalid',
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertNotNull($this->repo()->find($hideId));
    }

    private function organizations(): \Doctrine\Persistence\ObjectRepository
    {
        return $this->em()->getRepository(Organization::class);
    }

    private function formToken(): string
    {
        $crawler = $this->client->request('GET', '/admin/hides');

        return $crawler->filter('input[name="_csrf_token"]')->first()->attr('value');
    }

    private function makeUser(string $login, string $email, UserRole $role): User
    {
        $user = (new User())
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
        $organization = (new Organization())
            ->setName($name)
            ->setIndustry('IT');
        $this->em()->persist($organization);

        return $organization;
    }

    private function repo(): OrganizationHideRepository
    {
        return $this->em()->getRepository(OrganizationHide::class);
    }

    /**
     * Реестр постраничный: страница из 50 скрытых организаций (spec
     * organization-hiding «Постраничный просмотр реестра скрытых
     * организаций»).
     */
    public function testRegistryIsPaginatedFiftyPerPage(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $this->hideOrganizations($manager, 60);
        $this->login($admin);

        $crawler = $this->client->request('GET', '/admin/hides');

        $this->assertResponseIsSuccessful();
        self::assertCount(50, $crawler->filter('[data-hide-row]'));
        self::assertCount(1, $crawler->filter('nav.pagination'));

        $crawler = $this->client->request('GET', '/admin/hides?page=2');
        $this->assertResponseIsSuccessful();
        self::assertCount(10, $crawler->filter('[data-hide-row]'));
        self::assertSame('Скрытая 051', $this->firstHiddenOrganizationName($crawler));
    }

    public function testRegistryHasNoNavigationWhenItFitsOnePage(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $this->hideOrganizations($manager, 50);
        $this->login($admin);

        $crawler = $this->client->request('GET', '/admin/hides');

        $this->assertResponseIsSuccessful();
        self::assertCount(50, $crawler->filter('[data-hide-row]'));
        self::assertCount(0, $crawler->filter('nav.pagination'));
    }

    public function testRegistrySortLinkResetsPage(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $this->hideOrganizations($manager, 60);
        $this->login($admin);

        $crawler = $this->client->request('GET', '/admin/hides?page=2&sort=name&dir=ASC');
        $sortHref = (string) $crawler->filter('a.table__sortable')->first()->attr('href');

        // Номер страницы не передаётся — это и есть возврат к первой странице.
        self::assertStringNotContainsString('page=', $sortHref);
        self::assertStringContainsString('sort=name', $sortHref);
        self::assertStringContainsString('dir=DESC', $sortHref);
    }

    /**
     * Страницы реестра считаются по организациям, а не по отдельным записям
     * скрытия: у организации несколько скрытий, но она занимает одну строку
     * страницы.
     */
    public function testRegistryPagesCountOrganizationsNotHideRecords(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager1 = $this->makeUser('manager1', 'manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2', 'manager2@b2b-crm.loc', UserRole::Manager);

        // 50 организаций, 25 из них скрыты от обоих менеджеров: записей
        // скрытия 75, а страница всё равно одна.
        $organizations = $this->hideOrganizations($manager1, 50);
        foreach ($organizations as $index => $organization) {
            if (0 === $index % 2) {
                $this->em()->persist(new OrganizationHide($organization, $manager2));
            }
        }
        $this->em()->flush();
        $this->login($admin);

        $crawler = $this->client->request('GET', '/admin/hides');

        $this->assertResponseIsSuccessful();
        self::assertCount(75, $crawler->filter('[data-hide-row]'));
        self::assertCount(0, $crawler->filter('nav.pagination'));
    }

    /**
     * Сортировка реестра считается по последней записи скрытия
     * организации: сортировка по менеджеру упорядочивает организации по email
     * того, кто скрыл их последним.
     */
    public function testRegistrySortsByTheLastHidingManager(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager1 = $this->makeUser('manager1', 'manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2', 'manager2@b2b-crm.loc', UserRole::Manager);

        // «А» скрыта сначала от manager1, потом от manager2 — её последний
        // скрывающий manager2. У «Б» наоборот: последний скрывающий
        // manager1, поэтому при сортировке по менеджеру «Б» идёт первой.
        $base = new \DateTimeImmutable('2026-01-01 10:00:00');
        $a = $this->makeOrganization('Скрытая А');
        $this->persistHide($a, $manager1, $base);
        $this->persistHide($a, $manager2, $base->modify('+1 hour'));

        $b = $this->makeOrganization('Скрытая Б');
        $this->persistHide($b, $manager2, $base);
        $this->persistHide($b, $manager1, $base->modify('+1 hour'));
        $this->em()->flush();
        $this->login($admin);

        $crawler = $this->client->request('GET', '/admin/hides?sort=manager&dir=ASC');

        $this->assertResponseIsSuccessful();
        self::assertSame(
            ['Скрытая Б', 'Скрытая Б', 'Скрытая А', 'Скрытая А'],
            $crawler->filter('[data-hide-row] td.table--org__name')->each(
                static fn(\Symfony\Component\DomCrawler\Crawler $node): string => trim($node->text()),
            ),
        );
    }

    /**
     * Две записи скрытия одной организации, созданные в одну секунду, дают
     * одинаковый hidden_at. «Последний скрывающий» тогда определяется
     * дополнительным признаком (id), и порядок строк не должен прыгать от
     * запроса к запросу.
     */
    public function testRegistryOrderIsStableWhenTwoHidesShareTheSameSecond(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager1 = $this->makeUser('manager1', 'manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2', 'manager2@b2b-crm.loc', UserRole::Manager);

        // hidden_at у всех записей одинаковый — в пределах одной секунды
        // порядок определяется дополнительным признаком (id записи).
        $sameSecond = new \DateTimeImmutable('2026-01-01 10:00:00');
        foreach (['Скрытая А', 'Скрытая Б', 'Скрытая В'] as $name) {
            $organization = $this->makeOrganization($name);
            $this->persistHide($organization, $manager1, $sameSecond);
            $this->persistHide($organization, $manager2, $sameSecond);
        }
        $this->em()->flush();
        self::assertCount(6, $this->repo()->findAll());
        $this->login($admin);

        $orders = [];
        foreach ([1, 2, 3] as $attempt) {
            $crawler = $this->client->request('GET', '/admin/hides?sort=manager&dir=ASC');
            $this->assertResponseIsSuccessful();
            $orders[] = $crawler->filter('[data-hide-row] td.table--org__name')->each(
                static fn(\Symfony\Component\DomCrawler\Crawler $node): string => trim($node->text()),
            );
            unset($attempt);
        }

        self::assertSame($orders[0], $orders[1]);
        self::assertSame($orders[1], $orders[2]);

        // Записи одной организации идут подряд: 3 организации × 2 записи.
        // Порядок организаций внутри страницы при равном hidden_at задаётся
        // случайным id записи, поэтому сравниваем состав, а не позиции.
        $counts = array_count_values($orders[0]);
        ksort($counts);
        self::assertSame(
            ['Скрытая А' => 2, 'Скрытая Б' => 2, 'Скрытая В' => 2],
            $counts,
        );
        foreach (array_chunk($orders[0], 2) as $pair) {
            self::assertCount(1, array_unique($pair), 'Записи одной организации должны идти подряд.');
        }
    }

    /**
     * Страница реестра за пределами диапазона открывает последнюю
     * существующую страницу со строками.
     */
    public function testRegistryPageBeyondTheRangeOpensLastPage(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $this->hideOrganizations($manager, 60);
        $this->login($admin);

        $this->client->request('GET', '/admin/hides?page=999');
        $crawler = $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        self::assertSame('2', $this->client->getRequest()->query->get('page'));
        self::assertCount(10, $crawler->filter('[data-hide-row]'));
        // Последняя страница: сортировка по имени по умолчанию, остаток выборки.
        self::assertSame('Скрытая 051', $this->firstHiddenOrganizationName($crawler));
    }

    private function firstHiddenOrganizationName(\Symfony\Component\DomCrawler\Crawler $crawler): string
    {
        return trim($crawler->filter('[data-hide-row] td.table--org__name')->first()->text());
    }

    /**
     * Запись скрытия с заданной датой: конструктор проставляет «сейчас», а
     * для проверки порядка нужны разные (и одинаковые) секунды.
     */
    private function persistHide(Organization $organization, User $manager, \DateTimeImmutable $hiddenAt): OrganizationHide
    {
        $hide = new OrganizationHide($organization, $manager);
        new \ReflectionProperty($hide, 'hiddenAt')->setValue($hide, $hiddenAt);
        $this->em()->persist($hide);

        return $hide;
    }

    /**
     * Канонический URL реестра: пустые значения параметров убираются, а
     * номер страницы приводится к открытой.
     */
    public function testRegistryCanonicalUrlDropsEmptySort(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $this->hideOrganizations($manager, 60);
        $this->login($admin);

        $this->client->request('GET', '/admin/hides?sort=&dir=ASC');
        $crawler = $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        self::assertSame('/admin/hides?dir=ASC', $this->client->getRequest()->getRequestUri());
        self::assertCount(50, $crawler->filter('[data-hide-row]'));
    }

    /**
     * @return Organization[]
     */
    private function hideOrganizations(User $manager, int $count): array
    {
        $organizations = [];
        for ($index = 1; $index <= $count; ++$index) {
            $organization = $this->makeOrganization(\sprintf('Скрытая %03d', $index));
            $this->em()->persist(new OrganizationHide($organization, $manager));
            $organizations[] = $organization;
        }
        $this->em()->flush();

        return $organizations;
    }
}
