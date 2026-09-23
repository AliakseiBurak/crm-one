<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\GroupAssignment;
use App\Entity\Organization;
use App\Entity\OrganizationGroup;
use App\Entity\OrgGroupMembership;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

/**
 * Функциональные тесты UserController (change add-new-user, organization-groups):
 * создание, удаление, список пользователей, проверка доступа (ADR-0008),
 * per-group reassign/delete выбор при удалении менеджера (organization-groups).
 */
final class UserControllerTest extends DatabaseWebTestCase
{
    // --- Create ---

    public function testAdminCreatesUserWithAllFields(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $this->open('/admin/users/new');
        $this->submitFormByButton('Создать', [
            'login' => 'maria',
            'email' => 'maria@example.com',
            'name' => 'Мария',
            'surname' => 'Смирнова',
            'role' => 'manager',
        ]);

        $this->assertResponseRedirects('/admin/users');

        $this->em()->clear();
        $user = $this->findUser('maria@example.com');
        self::assertNotNull($user);
        self::assertSame('Мария', $user->name);
        self::assertSame('Смирнова', $user->surname);
        self::assertSame(UserRole::Manager, $user->role);
    }

    public function testAdminCreatesUserWithoutNameAndSurname(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $this->open('/admin/users/new');
        $this->submitFormByButton('Создать', [
            'login' => 'ivanov',
            'email' => 'ivan@example.com',
            'name' => '',
            'surname' => '',
            'role' => 'manager',
        ]);

        $this->assertResponseRedirects('/admin/users');

        $this->em()->clear();
        $user = $this->findUser('ivan@example.com');
        self::assertNotNull($user);
        self::assertNull($user->name);
        self::assertNull($user->surname);
    }

    public function testCreateAdminDoesNotCreatePersonalGroup(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $this->open('/admin/users/new');
        $this->submitFormByButton('Создать', [
            'login' => 'newadmin',
            'email' => 'newadmin@example.com',
            'name' => '',
            'surname' => '',
            'role' => 'admin',
        ]);

        $this->assertResponseRedirects('/admin/users');

        $this->em()->clear();
        $user = $this->findUser('newadmin@example.com');
        self::assertNotNull($user);
    }

    public function testCreateWithoutEmailSucceeds(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $this->open('/admin/users/new');
        $this->submitFormByButton('Создать', [
            'login' => 'test-user',
            'email' => '',
            'name' => '',
            'surname' => '',
            'role' => 'manager',
        ]);

        $this->assertResponseRedirects('/admin/users');
        self::assertSame(1, $this->em()->getRepository(User::class)->count(['login' => 'test-user']));
    }

    public function testCreateWithDuplicateEmailShowsError(): void
    {
        $this->makeUser('existing', 'existing@example.com', UserRole::Manager);
        $this->em()->flush();

        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $this->open('/admin/users/new');
        $this->submitFormByButton('Создать', [
            'login' => 'existing',
            'email' => 'existing@example.com',
            'name' => '',
            'surname' => '',
            'role' => 'manager',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.field__error', 'уже существует');
    }

    public function testCreateWithTooShortLoginShowsError(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $this->open('/admin/users/new');
        $this->submitFormByButton('Создать', [
            'login' => 'abc',
            'email' => 'test@example.com',
            'name' => '',
            'surname' => '',
            'role' => 'manager',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.field__error', 'не менее 5 символов');
    }

    public function testCreateWithMissingRoleShowsError(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $this->open('/admin/users/new');
        $this->submitFormByButton('Создать', [
            'login' => 'testuser',
            'email' => 'test@example.com',
            'name' => '',
            'surname' => '',
            'role' => '',
        ]);

        $this->assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em()->getRepository(User::class)->count(['email' => 'test@example.com']));
    }

    public function testCreateWithInvalidRoleShowsError(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $token = $this->open('/admin/users/new')->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/admin/users/new', [
            'login' => 'testuser',
            'email' => 'test@example.com',
            'name' => '',
            'surname' => '',
            'role' => 'guest',
            '_csrf_token' => $token,
        ]);

        $this->assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em()->getRepository(User::class)->count(['email' => 'test@example.com']));
    }

    public function testCreateWithInvalidEmailFormatShowsError(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $this->open('/admin/users/new');
        $this->submitFormByButton('Создать', [
            'login' => 'not-an-email',
            'email' => 'not-an-email',
            'name' => '',
            'surname' => '',
            'role' => 'manager',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.field__error', 'Некорректный формат email');
        self::assertSame(0, $this->em()->getRepository(User::class)->count(['email' => 'not-an-email']));
    }

    // --- Delete ---

    public function testAdminCannotDeleteManagerWithoutGroupChoice(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $group = new OrganizationGroup()
            ->setName('Группа менеджера')
            ->setCreatedBy($manager);
        $this->em()->persist($group);
        $this->em()->flush();
        $managerId = $manager->id;

        $this->login($admin);
        $this->open('/admin/users/' . $managerId . '/delete');
        $this->submitFormByButton('Удалить', []);

        // Без выбора действия для созданной группы удаление отклоняется
        // (spec organization-groups: «Администратор не может удалить менеджера
        // без выбора для каждой группы»).
        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertNotNull($this->em()->find(User::class, $managerId));
    }

    public function testDeleteConfirmationShowsGroupContext(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $colleague = $this->makeUser('colleague', 'colleague@b2b-crm.loc', UserRole::Manager);
        $colleague->setName('Пётр')->setSurname('Сидоров');

        $group = (new OrganizationGroup())->setName('Группа менеджера')->setCreatedBy($manager);
        $this->em()->persist($group);
        $this->em()->persist(new GroupAssignment($colleague, $group));

        $org = (new Organization())->setName('ООО Ромашка')->setIndustry('IT');
        $this->em()->persist($org);
        $this->em()->flush();
        $this->em()->persist(new OrgGroupMembership($org, $group));
        $this->em()->flush();
        $this->em()->clear();

        $this->login($admin);
        $this->open('/admin/users/' . $manager->id . '/delete');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Группа менеджера');
        $this->assertSelectorTextContains('body', 'Организаций в группе: 1');
        $this->assertSelectorTextContains('body', 'Пётр Сидоров');
    }

    public function testAdminReassignsGroupsWhenDeletingManager(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $group = new OrganizationGroup()
            ->setName('Группа менеджера')
            ->setCreatedBy($manager);
        $this->em()->persist($group);
        $this->em()->flush();
        $groupId = $group->id;
        $managerId = $manager->id;

        $this->login($admin);
        $this->open('/admin/users/' . $managerId . '/delete');
        $this->submitFormByButton('Удалить', ['group_action_' . $groupId => 'reassign']);

        $this->assertResponseRedirects('/admin/users');
        $this->em()->clear();

        self::assertNull($this->em()->find(User::class, $managerId));
        $reassigned = $this->em()->find(OrganizationGroup::class, $groupId);
        self::assertNotNull($reassigned, 'Группа сохраняется при переназначении');
        self::assertSame($admin->id, $reassigned->createdBy->id);
    }

    public function testAdminDeletesGroupsWhenDeletingManager(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $group = new OrganizationGroup()
            ->setName('Группа менеджера')
            ->setCreatedBy($manager);
        $this->em()->persist($group);
        $this->em()->flush();
        $groupId = $group->id;
        $managerId = $manager->id;

        $this->login($admin);
        $this->open('/admin/users/' . $managerId . '/delete');
        $this->submitFormByButton('Удалить', ['group_action_' . $groupId => 'delete']);

        $this->assertResponseRedirects('/admin/users');
        $this->em()->clear();

        self::assertNull($this->em()->find(User::class, $managerId));
        self::assertNull(
            $this->em()->find(OrganizationGroup::class, $groupId),
            'Группа удаляется при выборе «Удалить группу»'
        );
    }

    public function testAdminDeletesAdminNoGroupDeleted(): void
    {
        $admin1 = $this->makeUser('admin1', 'admin1@b2b-crm.loc', UserRole::Admin);
        $admin2 = $this->makeUser('admin2', 'admin2@b2b-crm.loc', UserRole::Admin);
        $this->em()->flush();

        $admin2Id = $admin2->id;

        $this->login($admin1);
        $this->open('/admin/users/' . $admin2Id . '/delete');
        $this->submitFormByButton('Удалить', []);

        $this->assertResponseRedirects('/admin/users');

        $this->em()->clear();
        self::assertNull($this->em()->find(User::class, $admin2Id));
    }

    public function testAdminCannotDeleteSelf(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $this->em()->flush();

        $adminId = $admin->id;

        $this->login($admin);
        $this->open('/admin/users/' . $adminId . '/delete');
        $this->submitFormByButton('Удалить', []);

        $this->assertResponseStatusCodeSame(403);

        $this->em()->clear();
        self::assertNotNull($this->em()->find(User::class, $adminId));
    }

    // --- List ---

    public function testAdminSeesAllUsersInList(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $this->em()->flush();

        $this->login($admin);
        $this->open('/admin/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Пользователи');
        $this->assertSelectorTextContains('body', 'admin@b2b-crm.loc');
        $this->assertSelectorTextContains('body', 'manager@b2b-crm.loc');
    }

    public function testDeleteButtonMissingForCurrentUser(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $this->em()->flush();

        $this->login($admin);
        $this->open('/admin/users');

        $this->assertResponseIsSuccessful();
        // Кнопка удаления отсутствует для текущего пользователя.
        $this->assertSelectorNotExists('a[href="/admin/users/' . $admin->id . '/delete"]');
    }

    // --- Access Control ---

    public function testManagerCannotAccessUserList(): void
    {
        $this->login($this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager));
        $this->client->request('GET', '/admin/users');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testManagerCannotAccessCreateForm(): void
    {
        $this->login($this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager));
        $this->client->request('GET', '/admin/users/new');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testManagerCannotDeleteUser(): void
    {
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $target = $this->makeUser('target', 'target@b2b-crm.loc', UserRole::Manager);
        $this->em()->flush();

        $this->login($manager);
        $this->client->request('GET', '/admin/users/' . $target->id . '/delete');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAnonymousCannotAccessUserPages(): void
    {
        $this->client->request('GET', '/admin/users');
        $this->assertResponseRedirects('/login');

        $this->client->request('GET', '/admin/users/new');
        $this->assertResponseRedirects('/login');
    }

    // --- Manager-side assignment (change organization-group-assignment) ---

    public function testAdminSeesAssignPageWithGroupsAndCheckboxState(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $assignedGroup = (new OrganizationGroup())->setName('Assigned Group')->setCreatedBy($admin);
        $otherGroup = (new OrganizationGroup())->setName('Other Group')->setCreatedBy($admin);
        $ownGroup = (new OrganizationGroup())->setName('Own Group')->setCreatedBy($manager);
        $this->em()->persist($assignedGroup);
        $this->em()->persist($otherGroup);
        $this->em()->persist($ownGroup);
        $this->em()->flush();
        $this->em()->persist(new GroupAssignment($manager, $assignedGroup));
        $this->em()->flush();

        $this->login($admin);
        $crawler = $this->client->request('GET', '/admin/users/' . $manager->id . '/assign');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Группы менеджера');

        // Все группы в списке (design D1).
        self::assertSame(1, $crawler->filter('input[name="groups[]"][value="' . $assignedGroup->id . '"]')->count());
        self::assertSame(1, $crawler->filter('input[name="groups[]"][value="' . $otherGroup->id . '"]')->count());
        self::assertSame(1, $crawler->filter('input[name="groups[]"][value="' . $ownGroup->id . '"]')->count());

        // Отмечена только назначенная группа (spec: Назначение группы менеджеру).
        self::assertSame(1, $crawler->filter('input[name="groups[]"]:checked')->count());
        self::assertSame(1, $crawler->filter('input[name="groups[]"][value="' . $assignedGroup->id . '"]:checked')->count());
    }

    public function testAdminAssignsGroupsToManager(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $group = (new OrganizationGroup())->setName('Shared Group')->setCreatedBy($admin);
        $this->em()->persist($group);
        $this->em()->flush();
        $groupId = $group->id;

        // POST напрямую: DomCrawler раскладывает значения чекбоксов позиционно.
        $this->login($admin);
        $token = $this->client->request('GET', '/admin/users/' . $manager->id . '/assign')
            ->filter('input[name="_csrf_token"]')
            ->first()
            ->attr('value');

        $this->client->request('POST', '/admin/users/' . $manager->id . '/assign', [
            '_csrf_token' => $token,
            'groups' => [$groupId],
        ]);

        $this->assertResponseRedirects('/admin/users');
        $this->em()->clear();

        $assignment = $this->em()->getRepository(GroupAssignment::class)
            ->findOneBy(['user' => $manager->id, 'group' => $groupId]);
        self::assertNotNull($assignment, 'GroupAssignment создана');
    }

    public function testAdminUnassignsGroupsFromManager(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $group = (new OrganizationGroup())->setName('Shared Group')->setCreatedBy($admin);
        $this->em()->persist($group);
        $this->em()->flush();
        $this->em()->persist(new GroupAssignment($manager, $group));
        $this->em()->flush();
        $groupId = $group->id;

        // Diff-based save (design D2): пустая отправка снимает назначение.
        // POST напрямую — DomCrawler раскладывает значения чекбоксов позиционно.
        $this->login($admin);
        $token = $this->client->request('GET', '/admin/users/' . $manager->id . '/assign')
            ->filter('input[name="_csrf_token"]')
            ->first()
            ->attr('value');

        $this->client->request('POST', '/admin/users/' . $manager->id . '/assign', [
            '_csrf_token' => $token,
            'groups' => [],
        ]);

        $this->assertResponseRedirects('/admin/users');
        $this->em()->clear();

        self::assertNull($this->em()->getRepository(GroupAssignment::class)
            ->findOneBy(['user' => $manager->id, 'group' => $groupId]));
    }

    public function testUserAssignPageNotFoundForAdmin(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $otherAdmin = $this->makeUser('other-admin', 'other-admin@b2b-crm.loc', UserRole::Admin);
        $this->em()->flush();

        // Администратору группы не назначаются (design D6).
        $this->login($admin);
        $this->client->request('GET', '/admin/users/' . $otherAdmin->id . '/assign');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testManagerCannotAccessUserAssignPage(): void
    {
        $manager1 = $this->makeUser('manager1', 'manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2', 'manager2@b2b-crm.loc', UserRole::Manager);
        $this->em()->flush();

        $this->login($manager2);
        $this->client->request('GET', '/admin/users/' . $manager1->id . '/assign');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testManagerCannotPostUserAssign(): void
    {
        $manager1 = $this->makeUser('manager1', 'manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2', 'manager2@b2b-crm.loc', UserRole::Manager);
        $group = (new OrganizationGroup())->setName('Some Group')->setCreatedBy($manager2);
        $this->em()->persist($group);
        $this->em()->flush();
        $groupId = $group->id;

        $this->login($manager2);
        $this->client->request('POST', '/admin/users/' . $manager1->id . '/assign', [
            'groups' => [$groupId],
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertNull($this->em()->getRepository(GroupAssignment::class)
            ->findOneBy(['user' => $manager1->id, 'group' => $groupId]));
    }

    public function testAssignButtonShownOnlyForManagerRows(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $otherAdmin = $this->makeUser('other-admin', 'other-admin@b2b-crm.loc', UserRole::Admin);
        $this->em()->flush();

        $this->login($admin);
        $crawler = $this->client->request('GET', '/admin/users');

        $this->assertResponseIsSuccessful();
        self::assertSame(
            1,
            $crawler->filter('a[href="/admin/users/' . $manager->id . '/assign"]')->count(),
            'Строка менеджера предлагает «Назначить»',
        );
        self::assertSame(
            0,
            $crawler->filter('a[href="/admin/users/' . $otherAdmin->id . '/assign"]')->count(),
            'Строка администратора не предлагает «Назначить» (design D6)',
        );
    }

    // --- CSRF rejection tests ---

    public function testCreateUserRejectsInvalidCsrfToken(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request('POST', '/admin/users/new', [
            '_csrf_token' => 'invalid',
            'login' => 'newuser',
            'email' => 'new@example.com',
            'role' => 'manager',
        ]);

        $this->assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em()->getRepository(User::class)->count(['email' => 'new@example.com']));
    }

    public function testDeleteUserRejectsInvalidCsrfToken(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $target = $this->makeUser('target', 'target@b2b-crm.loc', UserRole::Manager);
        $this->em()->flush();
        $this->login($admin);

        $this->client->request('POST', '/admin/users/' . $target->id . '/delete', [
            '_csrf_token' => 'invalid',
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertNotNull($this->em()->find(User::class, $target->id));
    }

    public function testAssignGroupsRejectsInvalidCsrfToken(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $group = (new OrganizationGroup())->setName('Shared Group')->setCreatedBy($admin);
        $this->em()->persist($group);
        $this->em()->flush();
        $this->login($admin);

        $this->client->request('POST', '/admin/users/' . $manager->id . '/assign', [
            '_csrf_token' => 'invalid',
            'groups' => [$group->id],
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertNull($this->em()->getRepository(GroupAssignment::class)
            ->findOneBy(['user' => $manager->id, 'group' => $group->id]));
    }

    // --- Helpers ---

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

    private function findUser(string $email): ?User
    {
        return $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
    }
}
