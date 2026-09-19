<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\OrganizationHide;
use App\Entity\User;
use App\Repository\OrganizationHideRepository;
use App\Service\OrganizationHideService;
use App\Tests\DatabaseWebTestCase;

/**
 * Интеграционные тесты скрытия организаций (change organization-hiding):
 * каскады при удалении менеджера/организации, полный флоу скрытия и
 * возврата видимости, «скрыть от всех» и «показать всем».
 */
final class OrganizationHidingIntegrationTest extends DatabaseWebTestCase
{
    public function testDeletingManagerRemovesTheirHideRows(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Ромашка');
        $hide = new OrganizationHide($org, $manager);
        $this->em()->persist($hide);
        $this->em()->flush();

        $this->login($admin);
        $this->client->request('GET', '/admin/users/' . $manager->id . '/delete');
        $token = $this->client->getCrawler()->filter('input[name="_csrf_token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/users/' . $manager->id . '/delete', [
            '_csrf_token' => $token,
        ]);

        $this->assertResponseRedirects('/admin/users');
        $this->em()->clear();
        self::assertNull($this->em()->find(User::class, $manager->id));
        // FK ON DELETE CASCADE: записи скрытия удалены вместе с менеджером.
        self::assertNull($this->repo()->find($hide->id));
    }

    public function testDeletingOrganizationRemovesItsHideRows(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Ромашка');
        $hide = new OrganizationHide($org, $manager);
        $this->em()->persist($hide);
        $this->em()->flush();

        $this->login($admin);
        $this->client->request('GET', '/organizations/' . $org->id . '/delete');
        $token = $this->client->getCrawler()->filter('input[name="_csrf_token"]')->first()->attr('value');
        $this->client->request('POST', '/organizations/' . $org->id . '/delete', [
            '_csrf_token' => $token,
        ]);

        $this->assertResponseRedirects('/dashboard');
        $this->em()->clear();
        self::assertNull($this->em()->find(Organization::class, $org->id));
        // FK ON DELETE CASCADE: записи скрытия удалены вместе с организацией.
        self::assertNull($this->repo()->find($hide->id));
    }

    public function testHideThenUnhideFullFlow(): void
    {
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Ромашка');
        $this->em()->flush();

        // До скрытия организация видна.
        $this->login($manager);
        $crawler = $this->client->request('GET', '/dashboard');
        self::assertSame(1, $crawler->filter('#org-' . $org->id)->count());

        // Скрываем: организация исчезает из всех представлений менеджера.
        $this->em()->clear();
        $org = $this->em()->find(Organization::class, $org->id);
        $manager = $this->em()->find(User::class, $manager->id);
        $this->service()->hide($org, [$manager]);

        $crawler = $this->client->request('GET', '/dashboard');
        self::assertSame(0, $crawler->filter('#org-' . $org->id)->count());
        $crawler = $this->client->request('GET', '/contacts/new');
        self::assertStringNotContainsString('ООО Ромашка', $crawler->filter('select[name="organization"]')->html());

        // Возврат видимости: организация снова появляется.
        $this->em()->clear();
        $org = $this->em()->find(Organization::class, $org->id);
        $manager = $this->em()->find(User::class, $manager->id);
        $this->service()->unhide($org, $manager);

        $crawler = $this->client->request('GET', '/dashboard');
        self::assertSame(1, $crawler->filter('#org-' . $org->id)->count());
        $crawler = $this->client->request('GET', '/contacts/new');
        self::assertStringContainsString('ООО Ромашка', $crawler->filter('select[name="organization"]')->html());
    }

    public function testHideFromAllManagersSparesFutureManagers(): void
    {
        $manager1 = $this->makeUser('manager1', 'manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2', 'manager2@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Внутренняя');
        $this->em()->flush();

        // Скрыть от всех текущих менеджеров.
        $this->em()->clear();
        $org = $this->em()->find(Organization::class, $org->id);
        $created = $this->service()->hideFromAllManagers($org);
        self::assertSame(2, $created);

        $this->login($manager1);
        $crawler = $this->client->request('GET', '/dashboard');
        self::assertSame(0, $crawler->filter('#org-' . $org->id)->count());

        $this->login($manager2);
        $crawler = $this->client->request('GET', '/dashboard');
        self::assertSame(0, $crawler->filter('#org-' . $org->id)->count());

        // Новый менеджер не затрагивается скрытием «от всех» (default-open).
        $newManager = (new User())
            ->setLogin('manager3')
            ->setEmail('manager3@b2b-crm.loc')
            ->setRole(UserRole::Manager);
        $newManager->setPassword('test-password-hash');
        $this->em()->persist($newManager);
        $this->em()->flush();
        $this->login($newManager);
        $crawler = $this->client->request('GET', '/dashboard');
        self::assertSame(1, $crawler->filter('#org-' . $org->id)->count());
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

    private function service(): OrganizationHideService
    {
        return new OrganizationHideService(
            $this->repo(),
            $this->em()->getRepository(User::class),
            $this->em(),
        );
    }

    private function repo(): OrganizationHideRepository
    {
        return $this->em()->getRepository(OrganizationHide::class);
    }
}
