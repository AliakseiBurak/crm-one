<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\GroupAssignment;
use App\Entity\Organization;
use App\Entity\OrganizationGroup;
use App\Entity\OrganizationHide;
use App\Entity\OrgGroupMembership;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

final class GroupControllerTest extends DatabaseWebTestCase
{
    public function testManagerSeesOwnGroupsInList(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('My Group', $manager);
        $this->em()->persist($group);
        $this->em()->flush();

        $this->login($manager);
        $this->client->request('GET', '/groups');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'My Group');
    }

    public function testManagerSeesAssignedGroupsInList(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $group = $this->makeGroup('Assigned Group', $admin);
        $this->em()->persist($group);
        $this->em()->flush();

        $assignment = new \App\Entity\GroupAssignment($manager, $group);
        $this->em()->persist($assignment);
        $this->em()->flush();

        $this->login($manager);
        $this->client->request('GET', '/groups');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Assigned Group');
    }

    public function testManagerDoesNotSeeOtherManagerGroupsInList(): void
    {
        $manager1 = $this->makeUser('manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('Other Group', $manager2);
        $this->em()->persist($group);
        $this->em()->flush();

        $this->login($manager1);
        $this->client->request('GET', '/groups');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('body:contains("Other Group")');
    }

    public function testManagerCreatesGroup(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $this->em()->flush();

        $this->login($manager);
        $this->client->request('GET', '/groups/new');
        $this->assertResponseIsSuccessful();

        $this->submitFormByButton('Создать', [
            'name' => 'Новая группа',
            'description' => 'Описание',
            'color' => '#ff0000',
        ]);

        $this->assertResponseRedirects('/groups');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $this->em()->clear();
        $group = $this->em()->getRepository(OrganizationGroup::class)->findOneBy(['name' => 'Новая группа']);
        self::assertNotNull($group);
        self::assertSame('Описание', $group->description);
        self::assertSame('#ff0000', $group->color);
        self::assertSame($manager->id, $group->createdBy->id);
    }

    public function testManagerCreatesGroupWithInvalidColorReturns422(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $this->em()->flush();

        $this->login($manager);
        $this->client->request('GET', '/groups/new');

        $this->submitFormByButton('Создать', [
            'name' => 'Новая группа',
            'color' => 'not-a-color',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.field__error', 'Неверный формат цвета');
    }

    public function testManagerCreatesGroupWithBlankNameReturns422(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $this->em()->flush();

        $this->login($manager);
        $this->client->request('GET', '/groups/new');

        $this->submitFormByButton('Создать', [
            'name' => '',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.field__error', 'Название обязательно');
    }

    public function testManagerEditsOwnGroup(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('My Group', $manager);
        $this->em()->persist($group);
        $this->em()->flush();
        $groupId = $group->id;

        $this->login($manager);
        $this->client->request('GET', '/groups/' . $groupId . '/edit');
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Сохранить', [
            'name' => 'Updated Group',
        ]);

        $this->assertResponseRedirects('/groups');
        $this->em()->clear();
        $group = $this->em()->find(OrganizationGroup::class, $groupId);
        self::assertSame('Updated Group', $group->name);
    }

    public function testManagerCannotEditOtherManagerGroup(): void
    {
        $manager1 = $this->makeUser('manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('Other Group', $manager2);
        $this->em()->persist($group);
        $this->em()->flush();

        $this->login($manager1);
        $this->client->request('GET', '/groups/' . $group->id . '/edit');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testManagerDeletesOwnGroup(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('My Group', $manager);
        $this->em()->persist($group);
        $this->em()->flush();
        $groupId = $group->id;

        $this->login($manager);
        $this->client->request('GET', '/groups/' . $groupId . '/delete');
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Удалить', []);

        $this->assertResponseRedirects('/groups');
        $this->em()->clear();
        self::assertNull($this->em()->find(OrganizationGroup::class, $groupId));
    }

    public function testManagerCannotDeleteOtherManagerGroup(): void
    {
        $manager1 = $this->makeUser('manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('Other Group', $manager2);
        $this->em()->persist($group);
        $this->em()->flush();

        $this->login($manager1);
        $this->client->request('GET', '/groups/' . $group->id . '/delete');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testManagerAddsOrgsToGroupMembership(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('My Group', $manager);
        $this->em()->persist($group);

        // Org2 доступна менеджеру через другую созданную им группу — обе
        // организации видны на странице участников (ADR-0007).
        $otherGroup = $this->makeGroup('Other Arena', $manager);
        $this->em()->persist($otherGroup);

        $org1 = new Organization()->setName('Org1')->setIndustry('IT');
        $org2 = new Organization()->setName('Org2')->setIndustry('IT');
        $this->em()->persist($org1);
        $this->em()->persist($org2);

        $membership = new OrgGroupMembership($org1, $group);
        $this->em()->persist($membership);
        $this->em()->persist(new OrgGroupMembership($org2, $otherGroup));
        $this->em()->flush();
        $groupId = $group->id;

        $this->login($manager);
        $this->client->request('GET', '/groups/' . $groupId . '/members');
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Сохранить', [
            'organizations' => [$org1->id, $org2->id],
        ]);

        $this->assertResponseRedirects('/groups');
        $this->em()->clear();

        $group = $this->em()->find(OrganizationGroup::class, $groupId);
        self::assertCount(2, $group->memberships);
    }

    public function testManagerRemovesOrgsFromGroupMembership(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('My Group', $manager);
        $this->em()->persist($group);

        $org1 = new Organization()->setName('Org1')->setIndustry('IT');
        $org2 = new Organization()->setName('Org2')->setIndustry('IT');
        $this->em()->persist($org1);
        $this->em()->persist($org2);

        $this->em()->persist(new OrgGroupMembership($org1, $group));
        $this->em()->persist(new OrgGroupMembership($org2, $group));
        $this->em()->flush();
        $groupId = $group->id;

        $this->login($manager);
        $this->client->request('GET', '/groups/' . $groupId . '/members');
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Сохранить', [
            'organizations' => [$org1->id],
        ]);

        $this->assertResponseRedirects('/groups');
        $this->em()->clear();

        $group = $this->em()->find(OrganizationGroup::class, $groupId);
        self::assertCount(1, $group->memberships);
    }

    public function testManagerCannotAccessOtherManagerGroupMembers(): void
    {
        $manager1 = $this->makeUser('manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('Other Group', $manager2);
        $this->em()->persist($group);
        $this->em()->flush();

        $this->login($manager1);
        $this->client->request('GET', '/groups/' . $group->id . '/members');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testManagerCannotAddInaccessibleOrgToGroupMembership(): void
    {
        $manager1 = $this->makeUser('manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('My Group', $manager1);
        $this->em()->persist($group);

        // Организация скрыта от manager1 (ADR-0012) — добавить её в группу
        // он не может, хотя группа создана им.
        $inaccessibleOrg = (new Organization())
            ->setName('Чужой Орг')
            ->setIndustry('IT');
        $manager2Group = $this->makeGroup('Manager2 Group', $manager2);
        $this->em()->persist($manager2Group);
        $this->em()->persist($inaccessibleOrg);
        $this->em()->persist(new OrgGroupMembership($inaccessibleOrg, $manager2Group));
        $this->em()->persist(new OrganizationHide($inaccessibleOrg, $manager1));
        $this->em()->flush();
        $groupId = $group->id;

        // Токен — со своей страницы участников: форма доступна только
        // аутентифицированному менеджеру (иначе GET редиректит на /login).
        $this->login($manager1);
        $crawler = $this->client->request('GET', '/groups/' . $groupId . '/members');
        $token = $crawler->filter('input[name="_csrf_token"]')->first()->attr('value');

        $this->client->request('POST', '/groups/' . $groupId . '/members', [
            '_csrf_token' => $token,
            'organizations' => [$inaccessibleOrg->id],
        ]);

        $this->assertResponseRedirects('/groups');
        $this->em()->clear();

        $group = $this->em()->find(OrganizationGroup::class, $groupId);
        self::assertCount(0, $group->memberships);
    }

    // --- Assigned groups are read-only for managers (task 9.4) ---

    public function testAssignedGroupRowHasNoEditLink(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $ownGroup = $this->makeGroup('My Group', $manager);
        $assignedGroup = $this->makeGroup('Assigned Group', $admin);
        $this->em()->persist($ownGroup);
        $this->em()->persist($assignedGroup);
        $this->em()->flush();
        $this->em()->persist(new GroupAssignment($manager, $assignedGroup));
        $this->em()->flush();

        $this->login($manager);
        $crawler = $this->client->request('GET', '/groups');

        $this->assertResponseIsSuccessful();
        self::assertSame(
            0,
            $crawler->filter('a[href="/groups/' . $assignedGroup->id . '/edit"]')->count(),
            'Назначенная группа не должна предлагать правку',
        );
        self::assertSame(
            1,
            $crawler->filter('a[href="/groups/' . $assignedGroup->id . '/members"]')->count(),
            'Назначенная группа должна быть доступна на просмотр',
        );
        $this->assertSelectorTextContains('body', 'только просмотр');
        self::assertSame(
            1,
            $crawler->filter('a[href="/groups/' . $ownGroup->id . '/edit"]')->count(),
            'Своя группа остаётся доступной для правки',
        );
    }

    public function testManagerSeesAssignedGroupMembersReadOnly(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $group = $this->makeGroup('Assigned Group', $admin);
        $this->em()->persist($group);

        $org = new Organization()->setName('Org1')->setIndustry('IT');
        $this->em()->persist($org);
        $this->em()->flush();
        $this->em()->persist(new OrgGroupMembership($org, $group));
        $this->em()->persist(new GroupAssignment($manager, $group));
        $this->em()->flush();
        $this->em()->clear();

        $this->login($manager);
        $this->client->request('GET', '/groups/' . $group->id . '/members');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Org1');
        $this->assertSelectorNotExists('input[name="organizations[]"]');
        $this->assertSelectorNotExists('.group-members-form');
    }

    public function testManagerCannotUpdateMembersOfAssignedGroup(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $assignedGroup = $this->makeGroup('Assigned Group', $admin);
        $ownGroup = $this->makeGroup('My Group', $manager);
        $this->em()->persist($assignedGroup);
        $this->em()->persist($ownGroup);

        $org = new Organization()->setName('Org1')->setIndustry('IT');
        $this->em()->persist($org);
        $this->em()->flush();
        $this->em()->persist(new GroupAssignment($manager, $assignedGroup));
        $this->em()->flush();
        $assignedGroupId = $assignedGroup->id;

        $this->login($manager);
        // Токен берётся со страницы участников своей группы: отказ должен быть
        // по правам доступа, а не из-за CSRF.
        $token = $this->client->request('GET', '/groups/' . $ownGroup->id . '/members')
            ->filter('input[name="_csrf_token"]')
            ->first()
            ->attr('value');

        $this->client->request('POST', '/groups/' . $assignedGroupId . '/members', [
            '_csrf_token' => $token,
            'organizations' => [$org->id],
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        $group = $this->em()->find(OrganizationGroup::class, $assignedGroupId);
        self::assertCount(0, $group->memberships);
    }

    public function testGroupDeletePageKeepsOrganizations(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('My Group', $manager);
        $this->em()->persist($group);

        $org = new Organization()->setName('Org1')->setIndustry('IT');
        $this->em()->persist($org);
        $this->em()->flush();
        $this->em()->persist(new OrgGroupMembership($org, $group));
        $this->em()->flush();
        $this->em()->clear();
        $groupId = $group->id;
        $orgId = $org->id;

        $this->login($manager);
        $this->client->request('GET', '/groups/' . $groupId . '/delete');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Организации (1) останутся в системе');

        $this->client->submitForm('Удалить', []);
        $this->assertResponseRedirects('/groups');

        $this->em()->clear();
        self::assertNull($this->em()->find(OrganizationGroup::class, $groupId));
        self::assertNotNull($this->em()->find(Organization::class, $orgId));
    }

    // --- Admin group management (task 3.4) ---

    public function testAdminSeesAllGroupsInList(): void
    {
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $ownGroup = $this->makeGroup('Manager Group', $manager);
        $adminGroup = $this->makeGroup('Admin Group', $admin);
        $this->em()->persist($ownGroup);
        $this->em()->persist($adminGroup);
        $this->em()->flush();

        $this->login($admin);
        $this->client->request('GET', '/groups');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Manager Group');
        $this->assertSelectorTextContains('body', 'Admin Group');
    }

    public function testAdminCanEditManagerGroup(): void
    {
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('Manager Group', $manager);
        $this->em()->persist($group);
        $this->em()->flush();
        $groupId = $group->id;

        $this->login($admin);
        $this->client->request('GET', '/groups/' . $groupId . '/edit');
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Сохранить', [
            'name' => 'Renamed by Admin',
        ]);

        $this->assertResponseRedirects('/groups');
        $this->em()->clear();
        $group = $this->em()->find(OrganizationGroup::class, $groupId);
        self::assertSame('Renamed by Admin', $group->name);
    }

    public function testAdminCanDeleteManagerGroup(): void
    {
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('Manager Group', $manager);
        $this->em()->persist($group);
        $this->em()->flush();
        $groupId = $group->id;

        $this->login($admin);
        $this->client->request('GET', '/groups/' . $groupId . '/delete');
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Удалить', []);

        $this->assertResponseRedirects('/groups');
        $this->em()->clear();
        self::assertNull($this->em()->find(OrganizationGroup::class, $groupId));
    }

    public function testAdminCreatesGroup(): void
    {
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $this->em()->flush();

        $this->login($admin);
        $this->client->request('GET', '/groups/new');

        $this->submitFormByButton('Создать', [
            'name' => 'Admin Group',
            'description' => 'Created by admin',
            'color' => '#00ff00',
        ]);

        $this->assertResponseRedirects('/groups');
        $this->em()->clear();
        $group = $this->em()->getRepository(OrganizationGroup::class)->findOneBy(['name' => 'Admin Group']);
        self::assertNotNull($group);
        self::assertSame($admin->id, $group->createdBy->id);
    }

    public function testAdminCanAccessManagerGroupMembers(): void
    {
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('Manager Group', $manager);
        $this->em()->persist($group);

        $org = new Organization()->setName('Org1')->setIndustry('IT');
        $this->em()->persist($org);
        $this->em()->persist(new OrgGroupMembership($org, $group));
        $this->em()->flush();

        $this->login($admin);
        $this->client->request('GET', '/groups/' . $group->id . '/members');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Org1');
    }

    public function testAdminCanAddOrgsToManagerGroup(): void
    {
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('Manager Group', $manager);
        $this->em()->persist($group);

        $org1 = new Organization()->setName('Org1')->setIndustry('IT');
        $org2 = new Organization()->setName('Org2')->setIndustry('IT');
        $this->em()->persist($org1);
        $this->em()->persist($org2);
        $this->em()->flush();
        $groupId = $group->id;

        $this->login($admin);
        $this->client->request('GET', '/groups/' . $groupId . '/members');
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Сохранить', [
            'organizations' => [$org1->id, $org2->id],
        ]);

        $this->assertResponseRedirects('/groups');
        $this->em()->clear();

        $group = $this->em()->find(OrganizationGroup::class, $groupId);
        self::assertCount(2, $group->memberships);
    }

    public function testAdminGroupListHeading(): void
    {
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $this->em()->flush();

        $this->login($admin);
        $this->client->request('GET', '/groups');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Группы');
    }

    public function testManagerGroupListHeading(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $this->em()->flush();

        $this->login($manager);
        $this->client->request('GET', '/groups');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Мои группы');
    }

    // --- Group-side assignment (change organization-group-assignment) ---

    public function testAdminSeesAssignPageWithManagersAndCheckboxState(): void
    {
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $manager1 = $this->makeUser('manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('Assign Group', $admin);
        $this->em()->persist($group);
        $this->em()->flush();
        $this->em()->persist(new GroupAssignment($manager1, $group));
        $this->em()->flush();

        $this->login($admin);
        $crawler = $this->client->request('GET', '/groups/' . $group->id . '/assign');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Назначение группы');

        // Все менеджеры в списке, администратора нет (design D5).
        self::assertSame(1, $crawler->filter('input[name="managers[]"][value="' . $manager1->id . '"]')->count());
        self::assertSame(1, $crawler->filter('input[name="managers[]"][value="' . $manager2->id . '"]')->count());
        self::assertSame(0, $crawler->filter('input[name="managers[]"][value="' . $admin->id . '"]')->count());

        // Отмечен только назначенный менеджер (spec: Назначение группы менеджеру).
        self::assertSame(1, $crawler->filter('input[name="managers[]"]:checked')->count());
        self::assertSame(1, $crawler->filter('input[name="managers[]"][value="' . $manager1->id . '"]:checked')->count());
    }

    public function testAdminAssignsManagersToGroup(): void
    {
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $manager1 = $this->makeUser('manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('Assign Group', $admin);
        $this->em()->persist($group);
        $this->em()->flush();
        $groupId = $group->id;

        // POST напрямую: DomCrawler раскладывает значения чекбоксов
        // позиционно, поэтому подмена состава флажков — через request().
        $this->login($admin);
        $token = $this->client->request('GET', '/groups/' . $groupId . '/assign')
            ->filter('input[name="_csrf_token"]')
            ->first()
            ->attr('value');

        $this->client->request('POST', '/groups/' . $groupId . '/assign', [
            '_csrf_token' => $token,
            'managers' => [$manager1->id, $manager2->id],
        ]);

        $this->assertResponseRedirects('/groups');
        $this->em()->clear();

        $assignments = $this->em()->getRepository(GroupAssignment::class)->findBy(['group' => $groupId]);
        self::assertCount(2, $assignments);
    }

    public function testAdminUnassignsManagerFromGroup(): void
    {
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $manager1 = $this->makeUser('manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('Assign Group', $admin);
        $this->em()->persist($group);
        $this->em()->flush();
        $this->em()->persist(new GroupAssignment($manager1, $group));
        $this->em()->flush();
        $groupId = $group->id;

        // Diff-based save (design D2): снимаем manager1, добавляем manager2.
        // POST напрямую — DomCrawler раскладывает значения чекбоксов позиционно.
        $this->login($admin);
        $token = $this->client->request('GET', '/groups/' . $groupId . '/assign')
            ->filter('input[name="_csrf_token"]')
            ->first()
            ->attr('value');

        $this->client->request('POST', '/groups/' . $groupId . '/assign', [
            '_csrf_token' => $token,
            'managers' => [$manager2->id],
        ]);

        $this->assertResponseRedirects('/groups');
        $this->em()->clear();

        $assignments = $this->em()->getRepository(GroupAssignment::class)->findBy(['group' => $groupId]);
        self::assertCount(1, $assignments);
        self::assertSame($manager2->id, $assignments[0]->user->id);
    }

    public function testAdminUnassignsAllManagersFromGroup(): void
    {
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('Assign Group', $admin);
        $this->em()->persist($group);
        $this->em()->flush();
        $this->em()->persist(new GroupAssignment($manager, $group));
        $this->em()->flush();
        $groupId = $group->id;

        // POST напрямую: пустой managers[] = «все флажки сняты».
        $this->login($admin);
        $token = $this->client->request('GET', '/groups/' . $groupId . '/assign')
            ->filter('input[name="_csrf_token"]')
            ->first()
            ->attr('value');

        $this->client->request('POST', '/groups/' . $groupId . '/assign', [
            '_csrf_token' => $token,
            'managers' => [],
        ]);

        $this->assertResponseRedirects('/groups');
        $this->em()->clear();
        self::assertCount(0, $this->em()->getRepository(GroupAssignment::class)->findBy(['group' => $groupId]));
    }

    public function testGroupAssignIgnoresSubmittedAdminId(): void
    {
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $group = $this->makeGroup('Assign Group', $admin);
        $this->em()->persist($group);
        $this->em()->flush();
        $groupId = $group->id;

        // Прямой POST: id администратора не должен создать GroupAssignment
        // (admin видит все группы без назначений, ADR-0008).
        $this->login($admin);
        $token = $this->client->request('GET', '/groups/' . $groupId . '/assign')
            ->filter('input[name="_csrf_token"]')
            ->first()
            ->attr('value');

        $this->client->request('POST', '/groups/' . $groupId . '/assign', [
            '_csrf_token' => $token,
            'managers' => [$admin->id],
        ]);

        $this->assertResponseRedirects('/groups');
        $this->em()->clear();
        self::assertCount(0, $this->em()->getRepository(GroupAssignment::class)->findBy(['group' => $groupId]));
    }

    public function testManagerCannotAccessGroupAssignPage(): void
    {
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('Admin Group', $admin);
        $this->em()->persist($group);
        $this->em()->flush();

        $this->login($manager);
        $this->client->request('GET', '/groups/' . $group->id . '/assign');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testManagerCannotPostGroupAssign(): void
    {
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('Admin Group', $admin);
        $this->em()->persist($group);
        $this->em()->flush();

        $this->login($manager);
        $this->client->request('POST', '/groups/' . $group->id . '/assign', [
            'managers' => [$manager->id],
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertCount(0, $this->em()->getRepository(GroupAssignment::class)->findBy(['group' => $group->id]));
    }

    public function testAdminSeesAssignButtonInGroupList(): void
    {
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $managerGroup = $this->makeGroup('Manager Group', $manager);
        $this->em()->persist($managerGroup);
        $this->em()->flush();

        $this->login($admin);
        $crawler = $this->client->request('GET', '/groups');

        $this->assertResponseIsSuccessful();
        self::assertSame(
            1,
            $crawler->filter('a[href="/groups/' . $managerGroup->id . '/assign"]')->count(),
            'Администратор видит «Назначить» у чужой группы',
        );
    }

    public function testManagerDoesNotSeeAssignButtonInGroupList(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup('My Group', $manager);
        $this->em()->persist($group);
        $this->em()->flush();

        $this->login($manager);
        $crawler = $this->client->request('GET', '/groups');

        $this->assertResponseIsSuccessful();
        self::assertSame(
            0,
            $crawler->filter('a[href="/groups/' . $group->id . '/assign"]')->count(),
            'Кнопка «Назначить» доступна только администратору',
        );
    }

    private function makeUser(string $email, UserRole $role): User
    {
        $user = new User()
            ->setEmail($email)
            ->setRole($role);
        $user->setPassword('test-password-hash');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function makeGroup(string $name, User $createdBy): OrganizationGroup
    {
        return (new OrganizationGroup())
            ->setName($name)
            ->setCreatedBy($createdBy);
    }
}
