<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Call;
use App\Entity\Contact;
use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\OrganizationGroup;
use App\Entity\OrganizationHide;
use App\Entity\OrgGroupMembership;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

/**
 * Функциональные тесты OrganizationController (change organizations-crud):
 * CRUD с проверкой области доступа (ADR-0005–0008), валидация на сервере,
 * AJAX-обновление модального окна, каскадное удаление.
 */
final class OrganizationControllerTest extends DatabaseWebTestCase
{
    public function testAdminCreatesOrganizationAndRedirectsToDashboard(): void
    {
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));
        $this->open('/organizations/new');
        $this->submitFormByButton('Создать', [
            'name' => 'ООО Ромашка',
            'industry' => 'IT',
        ]);

        $this->assertResponseRedirects();

        // Перенаправление на панель с подсветкой организации.
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Панель');
        $this->assertSelectorExists('.org-table__row--highlight');

        // Свежая гидратация: коллекция у управляемой сущности не перечитывается.
        $this->em()->clear();
        $organization = $this->findOrganization('ООО Ромашка');
        self::assertNotNull($organization);
        self::assertSame('IT', $organization->industry);

        // Администратор личной группы не имеет (ADR-0008): членств нет.
        self::assertCount(0, $organization->groupMemberships);
    }

    public function testManagerCreatesOrgWithoutGroupSelectionStaysUngrouped(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $this->em()->flush();
        $this->login($manager);

        $this->open('/organizations/new');
        $this->assertResponseIsSuccessful();

        $this->submitFormByButton('Создать', [
            'name' => 'ООО Ромашка',
            'industry' => 'IT',
        ]);

        $this->assertResponseRedirects();
        self::assertNotNull($this->findOrganization('ООО Ромашка'));

        // Личных групп больше нет: без выбора группа организация не привязана
        // ни к одной группе (spec organization-groups: создание без выбора группы).
        $this->em()->clear();
        self::assertCount(0, $this->findOrganization('ООО Ромашка')->groupMemberships->toArray());
    }

    public function testManagerCreatesOrgWithSelectedGroupAddsToGroup(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup($manager);
        $this->em()->persist($group);
        $this->em()->flush();

        $this->login($manager);
        $this->open('/organizations/new');
        $this->assertResponseIsSuccessful();

        $this->submitFormByButton('Создать', [
            'name' => 'ООО Ромашка',
            'industry' => 'IT',
            'groups' => [$group->id],
        ]);

        $this->assertResponseRedirects();
        $this->em()->clear();

        $membership = $this->em()->getRepository(OrgGroupMembership::class)->findOneBy([
            'organization' => $this->findOrganization('ООО Ромашка'),
            'group' => $group,
        ]);
        self::assertNotNull($membership);
    }

    public function testManagerCannotAddInaccessibleGroupOnOrgCreate(): void
    {
        $manager1 = $this->makeUser('manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2@b2b-crm.loc', UserRole::Manager);
        $otherGroup = $this->makeGroup($manager2);
        $this->em()->persist($otherGroup);
        $this->em()->flush();

        // Прямой POST: форма не может отправить недоступный чекбокс, поэтому
        // серверная фильтрация области доступа проверяется вручную (ADR-0007).
        // Токен — со своей формы создания: страница доступна только
        // аутентифицированному менеджеру (иначе GET редиректит на /login).
        $this->login($manager1);
        $crawler = $this->client->request('GET', '/organizations/new');
        $token = $crawler->filter('input[name="_csrf_token"]')->first()->attr('value');

        $this->client->request('POST', '/organizations/new', [
            '_csrf_token' => $token,
            'name' => 'ООО Ромашка',
            'industry' => 'IT',
            'groups' => [$otherGroup->id],
        ]);

        $this->assertResponseRedirects();
        $this->em()->clear();

        // Группа другого менеджера недоступна — членство не создаётся.
        $membership = $this->em()->getRepository(OrgGroupMembership::class)->findOneBy([
            'organization' => $this->findOrganization('ООО Ромашка'),
            'group' => $otherGroup,
        ]);
        self::assertNull($membership);
    }

    // --- Org group checkbox tests (task 4.3) ---

    public function testManagerCanAssignGroupViaEditPage(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup($manager);
        $anotherGroup = (new OrganizationGroup())
            ->setName('Другая группа')
            ->setCreatedBy($manager);
        $org = new Organization()->setName('ООО Ромашка')->setIndustry('IT');
        $this->em()->persist($group);
        $this->em()->persist($anotherGroup);
        $this->em()->persist($org);
        // Организация должна входить в область доступа менеджера (ADR-0007):
        // членство в его группе, иначе страница редактирования вернёт 403.
        $this->em()->persist(new OrgGroupMembership($org, $group));
        $this->em()->flush();

        $this->login($manager);
        $this->open('/organizations/' . $org->id . '/edit');
        $this->assertResponseIsSuccessful();

        // Submit with group checked
        $this->client->submitForm('Сохранить', [
            'name' => 'ООО Ромашка',
            'industry' => 'IT',
            'groups' => [$group->id, $anotherGroup->id],
        ]);

        $this->assertResponseRedirects();
        $this->em()->clear();

        $membership = $this->em()->getRepository(OrgGroupMembership::class)->findOneBy([
            'organization' => $org,
            'group' => $anotherGroup,
        ]);
        self::assertNotNull($membership);
    }

    public function testManagerCanRemoveGroupViaEditPage(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup($manager);
        $org = new Organization()->setName('ООО Ромашка')->setIndustry('IT');
        $this->em()->persist($group);
        $this->em()->persist($org);
        $this->em()->flush();

        $membership = new OrgGroupMembership($org, $group);
        $this->em()->persist($membership);
        $this->em()->flush();

        $this->login($manager);
        $this->open('/organizations/' . $org->id . '/edit');

        // Submit without group checked
        $this->client->submitForm('Сохранить', [
            'name' => 'ООО Ромашка',
            'industry' => 'IT',
            'groups' => [],
        ]);

        $this->assertResponseRedirects();
        $this->em()->clear();

        $membership = $this->em()->getRepository(OrgGroupMembership::class)->findOneBy([
            'organization' => $org,
            'group' => $group,
        ]);
        self::assertNull($membership);
    }

    public function testAdminCanAssignAnyGroupViaEditPage(): void
    {
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
        $group = $this->makeGroup($manager);
        $org = new Organization()->setName('ООО Ромашка')->setIndustry('IT');
        $this->em()->persist($group);
        $this->em()->persist($org);
        $this->em()->flush();

        $this->login($admin);
        $this->open('/organizations/' . $org->id . '/edit');
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Сохранить', [
            'name' => 'ООО Ромашка',
            'industry' => 'IT',
            'groups' => [$group->id],
        ]);

        $this->assertResponseRedirects();
        $this->em()->clear();

        $membership = $this->em()->getRepository(OrgGroupMembership::class)->findOneBy([
            'organization' => $org,
            'group' => $group,
        ]);
        self::assertNotNull($membership);
    }

    public function testCreateWithBlankNameShowsRussianErrorAndDoesNotSave(): void
    {
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));
        $this->open('/organizations/new');
        $this->submitFormByButton('Создать', [
            'name' => '',
            'industry' => 'IT',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.field__error', 'Название обязательно для заполнения');

        // Организация не сохраняется.
        self::assertSame(0, $this->em()->getRepository(Organization::class)->count([]));
    }

    public function testManagerCannotEditInaccessibleOrganization(): void
    {
        [$manager1] = $this->makeTwoManagersWithOrganizations();
        $inaccessible = $this->findOrganization('ООО Завод');
        // Недоступность задаётся скрытием организации (ADR-0012).
        $this->em()->persist(new OrganizationHide($inaccessible, $manager1));
        $this->em()->flush();

        $this->login($manager1);
        $this->open('/organizations/' . $inaccessible->id . '/edit');

        // Организация скрыта от менеджера (ADR-0012).
        $this->assertResponseStatusCodeSame(403);
    }

    public function testManagerCannotUpdateInaccessibleOrganizationViaPost(): void
    {
        [$manager1] = $this->makeTwoManagersWithOrganizations();
        $inaccessible = $this->findOrganization('ООО Завод');
        $this->em()->persist(new OrganizationHide($inaccessible, $manager1));
        $this->em()->flush();

        $this->login($manager1);
        // Токен берём со своей формы создания — он не даёт доступа к скрытой организации.
        $this->submitOrganizationAjax(
            '/organizations/' . $inaccessible->id . '/edit',
            '/organizations/new',
            ['name' => 'Взломано', 'industry' => 'Хак'],
        );

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertSame('ООО Завод', $this->findOrganization('ООО Завод')->name);
    }

    public function testManagerEditsVisibleOrganization(): void
    {
        [$manager1] = $this->makeTwoManagersWithOrganizations();
        $visible = $this->findOrganization('ООО Ромашка');
        // Группа менеджера явно отмечается в форме: иначе отправка без
        // чекбоксов удаляет членство и организация выпадает из области
        // доступа (ADR-0007).
        $group = $this->em()->getRepository(OrganizationGroup::class)->findOneBy(['createdBy' => $manager1]);
        self::assertNotNull($group);

        $this->login($manager1);
        $this->open('/organizations/' . $visible->id . '/edit');
        $this->assertInputValueSame('name', 'ООО Ромашка');
        $this->assertInputValueSame('industry', 'IT');

        $this->submitFormByButton('Сохранить', [
            'name' => 'ООО Ромашка',
            'industry' => 'Маркетинг',
            'groups' => [$group->id],
        ]);

        $this->assertResponseRedirects();

        // Перенаправление на панель с подсветкой организации.
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Панель');
        $this->assertSelectorExists('.org-table__row--highlight');

        $this->em()->clear();
        self::assertSame('Маркетинг', $this->findOrganization('ООО Ромашка')->industry);
    }

    public function testEditWithClearedNameShowsRussianErrorAndKeepsValues(): void
    {
        $organization = new Organization()->setName('ООО Ромашка')->setIndustry('IT');
        $this->em()->persist($organization);
        $this->em()->flush();
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/organizations/' . $organization->id . '/edit');
        $this->submitFormByButton('Сохранить', [
            'name' => '',
            'industry' => 'Маркетинг',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.field__error', 'Название обязательно для заполнения');

        // Организация не обновляется.
        $this->em()->clear();
        $reloaded = $this->findOrganization('ООО Ромашка');
        self::assertNotNull($reloaded);
        self::assertSame('IT', $reloaded->industry);
    }

    public function testAjaxUpdateReturnsJsonAndPersistsChanges(): void
    {
        $organization = new Organization()->setName('ООО Ромашка')->setIndustry('IT');
        $this->em()->persist($organization);
        $this->em()->flush();
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $url = '/organizations/' . $organization->id . '/edit';
        $this->submitOrganizationAjax($url, $url, [
            'name' => 'ООО Ромашка',
            'industry' => 'Маркетинг',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseFormatSame('json');

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertSame('Маркетинг', $payload['organization']['industry']);
        self::assertSame('ООО Ромашка', $payload['organization']['name']);

        $this->em()->clear();
        self::assertSame('Маркетинг', $this->findOrganization('ООО Ромашка')->industry);
    }

    public function testAjaxUpdateReturnsJsonErrorsForInvalidData(): void
    {
        $organization = new Organization()->setName('ООО Ромашка')->setIndustry('IT');
        $this->em()->persist($organization);
        $this->em()->flush();
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $url = '/organizations/' . $organization->id . '/edit';
        $this->submitOrganizationAjax($url, $url, [
            'name' => '',
            'industry' => '',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertResponseFormatSame('json');

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);
        self::assertSame('Название обязательно для заполнения', $payload['errors']['name']);
        self::assertSame('Отрасль обязательна для заполнения', $payload['errors']['industry']);
    }

    public function testDeleteConfirmationPageWarnsAboutCascade(): void
    {
        $organization = new Organization()->setName('ООО Ромашка')->setIndustry('IT');
        $this->em()->persist($organization);
        $this->em()->flush();
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/organizations/' . $organization->id . '/delete');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Удаление организации');
        $this->assertSelectorTextContains('body', 'контактами и звонками');
    }

    public function testDeleteCascadesContactsAndCalls(): void
    {
        $organization = new Organization()->setName('ООО Ромашка')->setIndustry('IT');
        $contact = new Contact()
            ->setOrganization($organization)
            ->setName('Иван Петрович Иванов');
        $call = new Call()
            ->setOrganization($organization)
            ->setMadeAt(new \DateTimeImmutable('yesterday'))
            ->setNotes('Нет ответа');
        $this->em()->persist($organization);
        $this->em()->persist($contact);
        $this->em()->persist($call);
        $this->em()->flush();
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/organizations/' . $organization->id . '/delete');
        $this->submitFormByButton('Удалить', []);

        $this->assertResponseRedirects('/dashboard');

        $em = $this->em();
        $em->clear();
        self::assertNull($em->find(Organization::class, $organization->id));
        self::assertNull($em->find(Contact::class, $contact->id), 'Контакты организации удаляются каскадно');
        self::assertNull($em->find(Call::class, $call->id), 'Звонки организации удаляются каскадно');
    }

    public function testCancelDeletionKeepsOrganization(): void
    {
        $organization = new Organization()->setName('ООО Ромашка')->setIndustry('IT');
        $this->em()->persist($organization);
        $this->em()->flush();
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/organizations/' . $organization->id . '/delete');

        // Кнопка «Отмена» ведёт обратно к странице организации, удаление
        // выполняется только POST-подтверждением.
        $this->assertSelectorExists('a[href="/organizations/' . $organization->id . '/edit"]');
        $this->em()->clear();
        self::assertNotNull($this->findOrganization('ООО Ромашка'));
    }

    public function testGuestCannotAccessOrganizationPages(): void
    {
        $this->client->request('GET', '/organizations/new');

        // Неаутентифицированный пользователь попадает на вход (access_control).
        $this->assertResponseRedirects('/login');
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function makeTwoManagersWithOrganizations(): array
    {
        $em = $this->em();
        $manager1 = $this->makeUser('manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2@b2b-crm.loc', UserRole::Manager);
        $em->flush();

        $personal1 = $this->makeGroup($manager1);
        $personal2 = $this->makeGroup($manager2);
        $em->persist($personal1);
        $em->persist($personal2);

        $romashka = new Organization()->setName('ООО Ромашка')->setIndustry('IT');
        $zavod = new Organization()->setName('ООО Завод')->setIndustry('Производство');
        $em->persist($romashka);
        $em->persist($zavod);

        $em->persist(new OrgGroupMembership($romashka, $personal1));
        $em->persist(new OrgGroupMembership($zavod, $personal2));
        $em->flush();

        return [$manager1, $manager2];
    }

    private function makeUser(string $email, UserRole $role): User
    {
        $user = new User()
            ->setEmail($email)
            ->setRole($role);
        $user->setPassword('test-password-hash');
        $this->em()->persist($user);
        // Идентификатор нужен до loginUser() (EntityUserProvider требует id).
        $this->em()->flush();

        return $user;
    }

    private function makeGroup(User $owner): OrganizationGroup
    {
        return new OrganizationGroup()
            ->setName('Личная группа ' . $owner->email)
            ->setCreatedBy($owner);
    }

    private function findOrganization(string $name): ?Organization
    {
        return $this->em()->getRepository(Organization::class)->findOneBy(['name' => $name]);
    }
}
