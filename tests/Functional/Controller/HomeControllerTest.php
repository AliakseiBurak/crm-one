<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

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

        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/');

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Отписки', $content);
        self::assertStringContainsString('Отписки: сегодня', $content);
        self::assertStringContainsString('Отписки: 7 дней', $content);
        self::assertStringContainsString('Отписки: за 30 дней', $content);
    }

    public function testDashboardOptOutStatsRespectManagerScope(): void
    {
        $manager = $this->makeUser('manager@b2b-crm.loc', UserRole::Manager);
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
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Отписки', $content);
    }

    public function testHomeRedirectsUnauthenticatedUser(): void
    {
        $this->client->request('GET', '/');

        $this->assertResponseRedirects('/login');
    }

    public function testHomeShowsOptOutSection(): void
    {
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/');

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Отписки', $content);
        self::assertStringContainsString('Отписки: сегодня', $content);
        self::assertStringContainsString('Отписки: 7 дней', $content);
        self::assertStringContainsString('Отписки: за 30 дней', $content);
        self::assertStringContainsString('Из письма', $content);
    }

    public function testDashboardOptOutByEmailCount(): void
    {
        $now = new \DateTimeImmutable();

        // Организация, отписавшаяся из письма сегодня
        $org1 = $this->makeOrganization('ООО Из Письма');
        $org1->setIsOptedOut(true)
            ->setOptOutReason('Отписка из письма')
            ->setOptedOutAt($now->modify('-2 hours'));

        // Организация, отписавшаяся по другой причине
        $org2 = $this->makeOrganization('ООО Другая Причина');
        $org2->setIsOptedOut(true)
            ->setOptOutReason('Не интересно')
            ->setOptedOutAt($now->modify('-3 hours'));

        // Организация без отписки
        $this->makeOrganization('ООО Активная');

        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/');

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Отписки: сегодня', $content);
        self::assertStringContainsString('Из письма', $content);
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

    private function makeOrganization(string $name): Organization
    {
        $organization = new Organization()->setName($name)->setIndustry('IT');
        $this->em()->persist($organization);
        $this->em()->flush();

        return $organization;
    }
}
