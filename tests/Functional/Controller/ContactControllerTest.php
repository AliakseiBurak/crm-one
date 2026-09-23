<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Contact;
use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\OrganizationGroup;
use App\Entity\OrganizationHide;
use App\Entity\OrgGroupMembership;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

/**
 * Функциональные тесты ContactController (change contacts-crud):
 * CRUD с проверкой области доступа (ADR-0005–0008), привязка к организации,
 * валидация на сервере с сообщениями на русском, AJAX-обновление карточки
 * в модальном окне без перезагрузки страницы, удаление с подтверждением.
 */
final class ContactControllerTest extends DatabaseWebTestCase
{
    public function testAdminCreatesContactBoundToOrganization(): void
    {
        $organization = $this->makeOrganization('ООО Ромашка');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/contacts/new');
        $this->submitFormByButton('Создать', [
            'organization' => (string) $organization->id,
            'name' => 'Иван Петров',
            'phone' => '+7-900-111-11-11',
            'email' => 'ivan@romashka.ru',
            'position' => 'Директор',
        ]);

        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Организации');

        $contact = $this->findContact('Иван Петров');
        self::assertNotNull($contact);
        self::assertSame('ООО Ромашка', $contact->organization->name);
        self::assertSame('+7-900-111-11-11', $contact->phone);
        self::assertSame('ivan@romashka.ru', $contact->email);
        self::assertSame('Директор', $contact->position);
    }

    public function testManagerCreatesContactInVisibleOrganization(): void
    {
        [$manager1, , $romashka] = $this->makeTwoManagersWithOrganizations();
        $this->login($manager1);

        $this->open('/contacts/new');
        $this->submitFormByButton('Создать', [
            'organization' => (string) $romashka->id,
            'name' => 'Иван Петров',
        ]);

        $this->assertResponseRedirects();

        $this->em()->clear();
        $contact = $this->findContact('Иван Петров');
        self::assertNotNull($contact);
        self::assertSame('ООО Ромашка', $contact->organization->name);
    }

    public function testAdminCreatesContactWithIsMain(): void
    {
        $organization = $this->makeOrganization('ООО Ромашка');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/contacts/new');
        $this->submitFormByButton('Создать', [
            'organization' => (string) $organization->id,
            'name' => 'Иван Петров',
            'isMain' => '1',
        ]);

        $this->assertResponseRedirects();
        $this->em()->clear();
        $contact = $this->findContact('Иван Петров');
        self::assertNotNull($contact);
        self::assertTrue($contact->isMain);
    }

    public function testCreatingMainContactResetsPreviousMainContact(): void
    {
        $organization = $this->makeOrganization('ООО Ромашка');
        $previous = $this->makeContact($organization, 'Мария Смирнова');
        $previous->setIsMain(true);
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/contacts/new');
        $this->submitFormByButton('Создать', [
            'organization' => (string) $organization->id,
            'name' => 'Иван Петров',
            'isMain' => '1',
        ]);

        $this->assertResponseRedirects();
        $this->em()->clear();
        self::assertTrue($this->findContact('Иван Петров')->isMain);
        self::assertFalse($this->em()->find(Contact::class, $previous->id)->isMain);
    }

    public function testEditingContactAssignsMainAndResetsPreviousMain(): void
    {
        $organization = $this->makeOrganization('ООО Ромашка');
        $previous = $this->makeContact($organization, 'Мария Смирнова');
        $previous->setIsMain(true);
        $candidate = $this->makeContact($organization, 'Иван Петров');
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/contacts/' . $candidate->id . '/edit');
        $this->submitFormByButton('Сохранить', ['name' => 'Иван Петров', 'isMain' => '1']);

        $this->assertResponseRedirects();
        $this->em()->clear();
        self::assertTrue($this->em()->find(Contact::class, $candidate->id)->isMain);
        self::assertFalse($this->em()->find(Contact::class, $previous->id)->isMain);
    }

    public function testSavingContactAutoResetsExtraIsMainKeepingSmallestId(): void
    {
        $organization = $this->makeOrganization('ООО Ромашка');
        $first = $this->makeContact($organization, 'Мария Смирнова');
        $first->setIsMain(true);
        $second = $this->makeContact($organization, 'Иван Петров');
        $second->setIsMain(true);
        $third = $this->makeContact($organization, 'Алексей Сидоров');
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        // Сохранение любого контакта организации нормализует аномалию:
        // isMain остаётся только у контакта с минимальным ID.
        $this->open('/contacts/' . $third->id . '/edit');
        $this->submitFormByButton('Сохранить', ['name' => 'Алексей Сидоров']);

        $this->assertResponseRedirects();
        $this->em()->clear();
        self::assertTrue($this->em()->find(Contact::class, $first->id)->isMain);
        self::assertFalse($this->em()->find(Contact::class, $second->id)->isMain);
        self::assertFalse($this->em()->find(Contact::class, $third->id)->isMain);
    }

    public function testManagerCannotCreateContactInInaccessibleOrganization(): void
    {
        [$manager1, , , $zavod] = $this->makeTwoManagersWithOrganizations();
        // Недоступность задаётся скрытием организации (ADR-0012).
        $this->em()->persist(new OrganizationHide($zavod, $manager1));
        $this->em()->flush();
        $this->login($manager1);

        // Токен берём со своей формы создания — он не даёт доступа к скрытой организации.
        $this->submitContactAjax(
            '/contacts/new',
            '/contacts/new',
            [
                'organization' => (string) $zavod->id,
                'name' => 'Иван Петров',
            ],
            ajax: false,
        );

        // Организация скрыта от менеджера (ADR-0012).
        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertNull($this->findContact('Иван Петров'));
    }

    public function testCreateFormPreselectsOrganizationFromLink(): void
    {
        $organization = $this->makeOrganization('ООО Ромашка');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/contacts/new?organization=' . $organization->id);

        $selected = $crawler->filter('select[name="organization"] option[selected]');
        self::assertCount(1, $selected);
        self::assertSame((string) $organization->id, $selected->attr('value'));
    }

    public function testCreateWithBlankNameShowsRussianErrorAndDoesNotSave(): void
    {
        $organization = $this->makeOrganization('ООО Ромашка');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/contacts/new');
        $this->submitFormByButton('Создать', [
            'organization' => (string) $organization->id,
            'name' => '',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.field__error', 'Имя обязательно для заполнения');

        self::assertSame(0, $this->em()->getRepository(Contact::class)->count([]));
    }

    public function testCreateWithoutOrganizationShowsRussianError(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        // Прямой POST: в пустом списке организаций нет опций для заполнения формы.
        $this->submitContactAjax(
            '/contacts/new',
            '/contacts/new',
            ['organization' => '', 'name' => 'Иван Петров'],
            ajax: false,
        );

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.field__error', 'Организация обязательна для выбора');
        self::assertSame(0, $this->em()->getRepository(Contact::class)->count([]));
    }

    public function testAdminEditsContactPhone(): void
    {
        $contact = $this->makeContactWithOrganization();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/contacts/' . $contact->id . '/edit');
        $this->assertInputValueSame('name', 'Иван Петров');

        $this->submitFormByButton('Сохранить', [
            'name' => 'Иван Петров',
            'phone' => '+7-900-111-11-11',
            'email' => 'ivan@romashka.ru',
            'position' => 'Директор',
            'notes' => 'Перезвонить вечером',
        ]);

        $this->assertResponseRedirects();

        $this->em()->clear();
        $reloaded = $this->em()->find(Contact::class, $contact->id);
        self::assertSame('+7-900-111-11-11', $reloaded->phone);
        self::assertSame('Перезвонить вечером', $reloaded->notes);
    }

    public function testEditWithClearedNameShowsRussianErrorAndKeepsValues(): void
    {
        $contact = $this->makeContactWithOrganization(phone: '+7-900-000-00-00');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/contacts/' . $contact->id . '/edit');
        $this->submitFormByButton('Сохранить', [
            'name' => '',
            'phone' => '+7-900-111-11-11',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.field__error', 'Имя обязательно для заполнения');

        // Контакт не обновляется.
        $this->em()->clear();
        $reloaded = $this->em()->find(Contact::class, $contact->id);
        self::assertSame('Иван Петров', $reloaded->name);
        self::assertSame('+7-900-000-00-00', $reloaded->phone);
    }

    public function testManagerEditsVisibleContact(): void
    {
        [$manager1, , $romashka] = $this->makeTwoManagersWithOrganizations();
        $contact = $this->makeContact($romashka);
        $this->em()->flush();
        $this->login($manager1);

        $this->open('/contacts/' . $contact->id . '/edit');
        $this->submitFormByButton('Сохранить', [
            'name' => 'Иван Петров',
            'phone' => '+7-900-111-11-11',
        ]);

        $this->assertResponseRedirects();

        $this->em()->clear();
        self::assertSame('+7-900-111-11-11', $this->em()->find(Contact::class, $contact->id)->phone);
    }

    public function testManagerCannotOpenEditOfInvisibleContact(): void
    {
        [$manager1, , , $zavod] = $this->makeTwoManagersWithOrganizations();
        $zavodContact = $this->makeContact($zavod, 'Заводской контакт');
        $this->em()->persist(new OrganizationHide($zavod, $manager1));
        $this->em()->flush();
        $this->login($manager1);

        $this->open('/contacts/' . $zavodContact->id . '/edit');

        // Контакт принадлежит организации, скрытой от менеджера (ADR-0012).
        $this->assertResponseStatusCodeSame(403);
    }

    public function testManagerCannotUpdateInvisibleContactViaPost(): void
    {
        [$manager1, , , $zavod] = $this->makeTwoManagersWithOrganizations();
        $zavodContact = $this->makeContact($zavod, 'Заводской контакт');
        $this->em()->persist(new OrganizationHide($zavod, $manager1));
        $this->em()->flush();
        $this->login($manager1);

        // Токен берём со своей формы создания — он не даёт доступа к чужому контакту.
        $this->submitContactAjax(
            '/contacts/' . $zavodContact->id . '/edit',
            '/contacts/new',
            ['name' => 'Взломано'],
            ajax: false,
        );

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertSame(
            'Заводской контакт',
            $this->em()->find(Contact::class, $zavodContact->id)->name,
        );
    }

    public function testAjaxUpdateReturnsJsonGridAndPersistsChanges(): void
    {
        $contact = $this->makeContactWithOrganization(phone: '+7-900-000-00-00');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $url = '/contacts/' . $contact->id . '/edit';
        $this->submitContactAjax($url, $url, [
            'name' => 'Иван Петров',
            'phone' => '+7-900-111-11-11',
            'email' => 'ivan@romashka.ru',
            'position' => 'Директор',
            'notes' => 'Важный клиент',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseFormatSame('json');

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        // Сервер возвращает отрисованную сетку контактов организации целиком
        // для замены без перезагрузки страницы.
        self::assertStringContainsString('org-contacts__grid', $payload['grid']);
        self::assertStringContainsString('data-contact-card-wrap="' . $contact->id . '"', $payload['grid']);
        self::assertStringContainsString('+7-900-111-11-11', $payload['grid']);
        self::assertStringContainsString('Важный клиент', $payload['grid']);

        $this->em()->clear();
        $reloaded = $this->em()->find(Contact::class, $contact->id);
        self::assertSame('+7-900-111-11-11', $reloaded->phone);
    }

    public function testAjaxUpdateInvalidDataReturnsJsonErrors(): void
    {
        $contact = $this->makeContactWithOrganization();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $url = '/contacts/' . $contact->id . '/edit';
        $this->submitContactAjax($url, $url, ['name' => '']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertResponseFormatSame('json');

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);
        self::assertSame('Имя обязательно для заполнения', $payload['errors']['name']);
    }

    public function testDeleteConfirmationPageShowsWarning(): void
    {
        $contact = $this->makeContactWithOrganization();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/contacts/' . $contact->id . '/delete');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Удаление контакта');
        $this->assertSelectorTextContains('body', 'Иван Петров');
    }

    public function testRemoveDeletesContact(): void
    {
        $contact = $this->makeContactWithOrganization();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/contacts/' . $contact->id . '/delete');
        $this->submitFormByButton('Удалить', []);

        $this->assertResponseRedirects();

        $this->em()->clear();
        self::assertNull($this->em()->find(Contact::class, $contact->id));
    }

    public function testCancelDeletionKeepsContact(): void
    {
        $contact = $this->makeContactWithOrganization();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/contacts/' . $contact->id . '/delete');

        // Кнопка «Отмена» ведёт обратно к странице контакта, удаление
        // выполняется только POST-подтверждением.
        $this->assertSelectorExists('a[href="/contacts/' . $contact->id . '/edit"]');
        $this->em()->clear();
        self::assertNotNull($this->em()->find(Contact::class, $contact->id));
    }

    public function testCreateRejectsInvalidCsrfToken(): void
    {
        $organization = $this->makeOrganization('ООО Ромашка');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request('POST', '/contacts/new', [
            '_csrf_token' => 'invalid',
            'organization' => (string) $organization->id,
            'name' => 'Иван Петров',
        ]);

        $this->assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em()->getRepository(Contact::class)->count([]));
    }

    public function testEditRejectsInvalidCsrfToken(): void
    {
        $contact = $this->makeContactWithOrganization();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request('POST', '/contacts/' . $contact->id . '/edit', [
            '_csrf_token' => 'invalid',
            'name' => 'Взлом',
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertSame('Иван Петров', $this->em()->find(Contact::class, $contact->id)->name);
    }

    public function testRemoveRejectsInvalidCsrfToken(): void
    {
        $contact = $this->makeContactWithOrganization();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request('POST', '/contacts/' . $contact->id . '/delete', [
            '_csrf_token' => 'invalid',
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertNotNull($this->em()->find(Contact::class, $contact->id));
    }

    public function testGuestCannotAccessContactPages(): void
    {
        $this->client->request('GET', '/contacts/new');

        // Неаутентифицированный пользователь попадает на вход (access_control).
        $this->assertResponseRedirects('/login');
    }

    private function makeUser(string $login, string $email, UserRole $role): User
    {
        $user = new User()
            ->setLogin($login)
            ->setEmail($email)
            ->setRole($role);
        $user->setPassword('test-password-hash');
        $this->em()->persist($user);
        // Идентификатор нужен до loginUser() (EntityUserProvider требует id).
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

    private function makeContact(Organization $organization, string $name = 'Иван Петров', ?string $phone = null): Contact
    {
        $contact = new Contact()
            ->setOrganization($organization)
            ->setName($name)
            ->setPhone($phone)
            ->setEmail('Иван Петров' === $name ? 'ivan@romashka.ru' : null);
        $this->em()->persist($contact);

        return $contact;
    }

    private function makeContactWithOrganization(?string $phone = null): Contact
    {
        $contact = $this->makeContact($this->makeOrganization('ООО Ромашка'), phone: $phone);
        $this->em()->flush();

        return $contact;
    }

    /**
     * Два менеджера с личными группами и организациями: Ромашка доступна
     * только первому, Завод — только второму (ADR-0005/0006/0007).
     *
     * @return array{0: User, 1: User, 2: Organization, 3: Organization}
     */
    private function makeTwoManagersWithOrganizations(): array
    {
        $em = $this->em();
        $manager1 = $this->makeUser('manager1', 'manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2', 'manager2@b2b-crm.loc', UserRole::Manager);
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

        return [$manager1, $manager2, $romashka, $zavod];
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

    private function findContact(string $name): ?Contact
    {
        return $this->em()->getRepository(Contact::class)->findOneBy(['name' => $name]);
    }

    /**
     * POST формы контакта с CSRF-токеном открытой страницы; для AJAX —
     * заголовок X-Requested-With.
     *
     * @param array<string, string> $fields
     */
    private function submitContactAjax(string $url, string $tokenPageUrl, array $fields, bool $ajax = true): void
    {
        $token = $this->open($tokenPageUrl)->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->request(
            'POST',
            $url,
            $fields + ['_csrf_token' => $token],
            [],
            $ajax ? ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'] : [],
        );
    }

    /**
     * trim() в applyRequest() — пробелы по краям имени контакта обрезаются.
     * Убийца UnwrapTrim (line 192).
     */
    public function testCreateContactTrimsWhitespaceFromName(): void
    {
        $organization = $this->makeOrganization('ООО Ромашка');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $this->open('/contacts/new?organization=' . $organization->id);
        $this->submitFormByButton('Создать', [
            'name' => '  Иван Петров  ',
            'phone' => '  +7-900-111-11-11  ',
            'email' => '  ivan@example.com  ',
            'position' => '  Директор  ',
            'notes' => '  Заметка  ',
        ]);

        $this->assertResponseRedirects();
        $this->em()->clear();

        $contact = $this->findContact('Иван Петров');
        self::assertNotNull($contact);
        self::assertSame('+7-900-111-11-11', $contact->phone);
        self::assertSame('ivan@example.com', $contact->email);
        self::assertSame('Директор', $contact->position);
        self::assertSame('Заметка', $contact->notes);
    }

    /**
     * AJAX-обновление контакта возвращает JSON с grid.
     * Убийца ArrayItem (line 154).
     */
    public function testAjaxUpdateContactReturnsGridInJson(): void
    {
        $contact = $this->makeContactWithOrganization();

        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->submitContactAjax(
            '/contacts/' . $contact->id . '/edit',
            '/contacts/' . $contact->id . '/edit',
            ['name' => 'Иван Петров', 'phone' => '+7-900-111-11-11'],
        );

        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($payload['ok']);
        self::assertArrayHasKey('grid', $payload);
        self::assertStringContainsString('org-contacts__grid', $payload['grid']);
    }

    /**
     * Edit-форма контакта отображает имя организации.
     * Убийца ArrayItemRemoval (line 98), ArrayItem (lines 99, 101).
     */
    public function testEditContactFormShowsOrganizationName(): void
    {
        $contact = $this->makeContactWithOrganization();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/contacts/' . $contact->id . '/edit');

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('ООО Ромашка', $this->client->getResponse()->getContent());
    }

    /**
     * Create-форма контакта с пустым именем возвращает ошибки.
     * Убийца ArrayItemRemoval (line 122), ArrayItem (line 123).
     */
    public function testCreateContactWithBlankNameShowsErrors(): void
    {
        $organization = $this->makeOrganization('ООО Ромашка');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $this->open('/contacts/new?organization=' . $organization->id);
        $this->submitFormByButton('Создать', [
            'name' => '',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.field__error', 'Имя обязательно для заполнения');
    }
}
