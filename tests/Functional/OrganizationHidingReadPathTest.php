<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Call;
use App\Entity\Campaign;
use App\Entity\CampaignRecipient;
use App\Entity\Contact;
use App\Entity\Enum\UserRole;
use App\Entity\GroupAssignment;
use App\Entity\Organization;
use App\Entity\OrganizationGroup;
use App\Entity\OrganizationHide;
use App\Entity\User;
use App\Service\CampaignRecipientService;
use App\Tests\DatabaseWebTestCase;

/**
 * Функциональные тесты фильтрации путей чтения (change organization-hiding,
 * ADR-0012): скрытая организация исчезает из статистики панели, списка
 * организаций, списков контактов и звонков, адресатов рассылок и состава
 * назначенных групп.
 */
final class OrganizationHidingReadPathTest extends DatabaseWebTestCase
{
    public function testHiddenOrganizationDisappearsFromDashboardAndHomeStats(): void
    {
        [$manager, $hidden, $visible] = $this->seedWithCallToday();
        $this->login($manager);

        // До скрытия: обе организации на панели и в статистике.
        $crawler = $this->client->request('GET', '/dashboard');
        self::assertSame(1, $crawler->filter('#org-' . $hidden->id)->count());
        self::assertSame(1, $crawler->filter('#org-' . $visible->id)->count());

        $crawler = $this->client->request('GET', '/');
        $this->assertStatsTotal($crawler, 2);
        self::assertSame('1', $this->homeFigure($crawler, 'Ожидают сегодня'));

        // Скрываем организацию (после запросов сущности детачатся — перечитываем).
        $this->em()->clear();
        $hidden = $this->em()->find(Organization::class, $hidden->id);
        $manager = $this->em()->find(User::class, $manager->id);
        $this->em()->persist(new OrganizationHide($hidden, $manager));
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/dashboard');
        self::assertSame(0, $crawler->filter('#org-' . $hidden->id)->count(), 'Скрытая организация не отображается на панели');
        self::assertSame(1, $crawler->filter('#org-' . $visible->id)->count());

        // Контакты скрытой организации не отображаются в аккордеоне панели.
        self::assertStringNotContainsString('Иван Ромашкин', $crawler->text());

        $crawler = $this->client->request('GET', '/');
        $this->assertStatsTotal($crawler, 1);
        self::assertSame('0', $this->homeFigure($crawler, 'Ожидают сегодня'));
        self::assertSame('0', $this->homeFigure($crawler, 'Ожидают на неделе'));
    }

    public function testHiddenOrganizationExcludedFromCallAndContactSelects(): void
    {
        [$manager, $hidden, $visible] = $this->seedWithCallToday();
        $this->login($manager);

        $crawler = $this->client->request('GET', '/contacts/new');
        self::assertStringContainsString('ООО Ромашка', $crawler->filter('select[name="organization"]')->html());
        self::assertStringContainsString('ООО Вектор', $crawler->filter('select[name="organization"]')->html());

        $crawler = $this->client->request('GET', '/calls/new');
        self::assertStringContainsString('ООО Ромашка', $crawler->filter('select[name="organization"]')->html());

        $this->em()->clear();
        $hidden = $this->em()->find(Organization::class, $hidden->id);
        $manager = $this->em()->find(User::class, $manager->id);
        $this->em()->persist(new OrganizationHide($hidden, $manager));
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/contacts/new');
        self::assertStringNotContainsString('ООО Ромашка', $crawler->filter('select[name="organization"]')->html());
        self::assertStringContainsString('ООО Вектор', $crawler->filter('select[name="organization"]')->html());

        $crawler = $this->client->request('GET', '/calls/new');
        self::assertStringNotContainsString('ООО Ромашка', $crawler->filter('select[name="organization"]')->html());
        self::assertStringContainsString('ООО Вектор', $crawler->filter('select[name="organization"]')->html());
    }

    public function testRecipientRowsOfHiddenOrganizationAreNotShownToManager(): void
    {
        [$manager, $admin, $hidden, $visible] = $this->seedCampaignWithRecipients();

        $this->em()->persist(new OrganizationHide($hidden, $manager));
        $this->em()->flush();

        $this->login($manager);
        $crawler = $this->client->request('GET', '/campaigns/' . $this->campaignId . '/recipients');
        self::assertStringNotContainsString('ООО Ромашка', $crawler->filter('.campaign-recipients__table')->text());
        self::assertStringContainsString('ООО Вектор', $crawler->filter('.campaign-recipients__table')->text());

        // Администратор видит все строки адресатов.
        $this->login($admin);
        $crawler = $this->client->request('GET', '/campaigns/' . $this->campaignId . '/recipients');
        self::assertStringContainsString('ООО Ромашка', $crawler->filter('.campaign-recipients__table')->text());
        self::assertStringContainsString('ООО Вектор', $crawler->filter('.campaign-recipients__table')->text());
    }

    public function testAssignedGroupMembersViewHidesHiddenOrganizations(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $group = (new OrganizationGroup())
            ->setName('Общая группа')
            ->setCreatedBy($admin);
        $hidden = $this->makeOrganization('ООО Ромашка');
        $visible = $this->makeOrganization('ООО Вектор');
        $this->em()->persist($group);
        $this->em()->persist(new GroupAssignment($manager, $group));
        $this->em()->flush();
        $this->em()->persist(new \App\Entity\OrgGroupMembership($hidden, $group));
        $this->em()->persist(new \App\Entity\OrgGroupMembership($visible, $group));
        $this->em()->persist(new OrganizationHide($hidden, $manager));
        $this->em()->flush();
        $groupId = $group->id;

        // Сбрасываем identity map: контроллер должен прочитать состав группы
        // из БД, а не из коллекции, инициализированной в тесте.
        $this->em()->clear();
        $this->login($manager);
        $crawler = $this->client->request('GET', '/groups/' . $groupId . '/members');

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('ООО Вектор', $crawler->text());
        self::assertStringNotContainsString('ООО Ромашка', $crawler->text());
    }

    public function testBulkAddByGroupSkipsHiddenOrganizations(): void
    {
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $group = (new OrganizationGroup())
            ->setName('Группа менеджера')
            ->setCreatedBy($manager);
        $hidden = $this->makeOrganization('ООО Ромашка');
        $visible = $this->makeOrganization('ООО Вектор');
        $this->em()->persist($group);
        $this->em()->flush();
        $this->em()->persist(new \App\Entity\OrgGroupMembership($hidden, $group));
        $this->em()->persist(new \App\Entity\OrgGroupMembership($visible, $group));
        $this->em()->persist(new Contact()
            ->setOrganization($hidden)
            ->setName('Иван Ромашкин')
            ->setEmail('ivan@romashka.ru'));
        $this->em()->persist(new Contact()
            ->setOrganization($visible)
            ->setName('Пётр Векторов')
            ->setEmail('petr@vektor.ru'));
        $this->em()->persist(new OrganizationHide($hidden, $manager));
        $this->em()->flush();

        $campaign = new Campaign();
        $campaign->setName('Акция');
        $this->em()->persist($campaign);
        $this->em()->flush();

        // Перечитываем группу и рассылку: коллекции/ссылки, затронутые
        // отдельными persist, видны только после очистки identity map.
        $this->em()->clear();
        $group = $this->em()->getRepository(OrganizationGroup::class)->find($group->id);
        $campaign = $this->em()->getRepository(Campaign::class)->find($campaign->id);

        $service = new CampaignRecipientService(
            $this->em()->getRepository(CampaignRecipient::class),
            $this->em()->getRepository(OrganizationGroup::class),
            $this->em()->getRepository(Organization::class),
            $this->em(),
        );

        $result = $service->bulkAddByGroup($campaign, $group, $manager);

        self::assertSame(1, $result['added']);
        self::assertSame(1, $result['skipped']);
        $recipients = $this->em()->getRepository(CampaignRecipient::class)->findBy(['campaign' => $campaign]);
        self::assertCount(1, $recipients);
        self::assertSame('ООО Вектор', $recipients[0]->organization->name);
    }

    /**
     * @return array{0: User, 1: Organization, 2: Organization}
     */
    private function seedWithCallToday(): array
    {
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $hidden = $this->makeOrganization('ООО Ромашка');
        $visible = $this->makeOrganization('ООО Вектор');
        $this->em()->persist(new Contact()
            ->setOrganization($hidden)
            ->setName('Иван Ромашкин'));
        $this->em()->persist(new Contact()
            ->setOrganization($visible)
            ->setName('Пётр Векторов'));
        $now = new \DateTimeImmutable();
        $this->em()->persist((new Call())
            ->setOrganization($hidden)
            ->setScheduledAt($now->setTime(14, 0)));
        $this->em()->persist((new Call())
            ->setOrganization($hidden)
            ->setScheduledAt($now->modify('+3 days')->setTime(10, 0)));
        $this->em()->flush();

        return [$manager, $hidden, $visible];
    }

    private int $campaignId;

    /**
     * @return array{0: User, 1: User, 2: Organization, 3: Organization}
     */
    private function seedCampaignWithRecipients(): array
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $hidden = $this->makeOrganization('ООО Ромашка');
        $visible = $this->makeOrganization('ООО Вектор');
        $this->em()->flush();

        $campaign = new Campaign();
        $campaign->setName('Акция');
        $this->em()->persist($campaign);
        $this->em()->persist(new CampaignRecipient($campaign, $hidden));
        $this->em()->persist(new CampaignRecipient($campaign, $visible));
        $this->em()->flush();
        $this->campaignId = $campaign->id;

        return [$manager, $admin, $hidden, $visible];
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

    private function assertStatsTotal(\Symfony\Component\DomCrawler\Crawler $crawler, int $total): void
    {
        self::assertSame(
            'Доступно организаций: ' . $total,
            trim($crawler->filter('.stats__total')->text()),
        );
    }

    private function homeFigure(\Symfony\Component\DomCrawler\Crawler $crawler, string $caption): ?string
    {
        foreach ($crawler->filter('.stats__item') as $item) {
            $itemCrawler = new \Symfony\Component\DomCrawler\Crawler($item);
            if (trim($itemCrawler->filter('.stats__caption')->text()) === $caption) {
                return trim($itemCrawler->filter('.stats__figure')->text());
            }
        }

        self::fail('Не найдена секция статистики «' . $caption . '»');
    }
}
