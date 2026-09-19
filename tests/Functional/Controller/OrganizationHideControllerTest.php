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
        self::assertSame(1, $crawler->filter('.header-admin__menu a[href="/admin/hides"]')->count());
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
}
