<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Contact;
use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

/**
 * Функциональные тесты подсветки главного контакта (change
 * contact-ismain-email-routing): порядок карточек — по ID, подсветка
 * эффективного главного независимо от позиции, бейдж «Основной» только при
 * isMain, подсветка имени на форме организации. Цвета и hover проверяются
 * e2e-тестами (Playwright).
 */
final class MainContactHighlightTest extends DatabaseWebTestCase
{
    public function testDashboardHighlightsMainContactRegardlessOfPosition(): void
    {
        $organization = $this->makeOrganization();
        $first = $this->makeContact($organization, 'Мария Смирнова');
        $main = $this->makeContact($organization, 'Иван Петров');
        $main->setIsMain(true);
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/dashboard');

        $wraps = $crawler->filter('.org-contacts__grid [data-contact-card-wrap]');
        self::assertCount(2, $wraps);

        // Порядок по ID (порядок добавления): главный — второй.
        $ids = [];
        for ($i = 0; $i < $wraps->count(); $i++) {
            $ids[] = $wraps->eq($i)->attr('data-contact-id');
        }
        self::assertSame([(string) $first->id, (string) $main->id], $ids);

        // Подсветка — только у главного, независимо от позиции.
        $firstWrap = $crawler->filter('[data-contact-card-wrap="' . $first->id . '"]');
        $mainWrap = $crawler->filter('[data-contact-card-wrap="' . $main->id . '"]');
        self::assertStringNotContainsString('org-contacts__card-wrap--main', (string) $firstWrap->attr('class'));
        self::assertStringContainsString('org-contacts__card-wrap--main', (string) $mainWrap->attr('class'));

        // Бейдж «Основной» — только у главного (флаг isMain).
        self::assertSame(0, $firstWrap->filter('.card__badge--primary')->count());
        self::assertSame(1, $mainWrap->filter('.card__badge--primary')->count());
        self::assertSame('Основной', $mainWrap->filter('.card__badge--primary')->first()->text());
    }

    public function testDashboardHighlightsSmallestIdWithoutIsMain(): void
    {
        $organization = $this->makeOrganization();
        $smallest = $this->makeContact($organization, 'Алексей Сидоров');
        $this->makeContact($organization, 'Мария Смирнова');
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/dashboard');

        $smallestWrap = $crawler->filter('[data-contact-card-wrap="' . $smallest->id . '"]');
        self::assertStringContainsString('org-contacts__card-wrap--main', (string) $smallestWrap->attr('class'));
        // Метка «Основной» у ID-based главного отсутствует.
        self::assertSame(0, $smallestWrap->filter('.card__badge--primary')->count());
    }

    public function testOrganizationFormHighlightsMainContactName(): void
    {
        $organization = $this->makeOrganization();
        $first = $this->makeContact($organization, 'Алексей Сидоров');
        $main = $this->makeContact($organization, 'Мария Смирнова');
        $main->setIsMain(true);
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/organizations/' . $organization->id . '/edit');

        $items = $crawler->filter('.organization-contacts__list li');
        self::assertCount(2, $items);

        // Порядок по ID: Алексей раньше Марии.
        self::assertStringContainsString('Алексей Сидоров', $items->eq(0)->text());
        self::assertStringContainsString('Мария Смирнова', $items->eq(1)->text());
        self::assertStringNotContainsString('Основной', $items->eq(0)->text());
        self::assertStringContainsString('Основной', $items->eq(1)->text());

        // Фон имени главного контакта подсвечен (класс --main), у остальных — нет.
        self::assertSame(0, $items->eq(0)->filter('.organization-contacts__name--main')->count());
        self::assertSame(1, $items->eq(1)->filter('.organization-contacts__name--main')->count());
        self::assertSame('Мария Смирнова', $items->eq(1)->filter('.organization-contacts__name--main')->first()->text());
    }

    private function makeUser(string $login, string $email, UserRole $role): User
    {
        $user = new User()->setLogin($login)->setEmail($email)->setRole($role);
        $user->setPassword('test-password-hash');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function makeOrganization(): Organization
    {
        $organization = new Organization()->setName('ООО Ромашка')->setIndustry('IT');
        $this->em()->persist($organization);
        $this->em()->flush();

        return $organization;
    }

    private function makeContact(Organization $organization, string $name): Contact
    {
        $contact = new Contact()->setOrganization($organization)->setName($name);
        $this->em()->persist($contact);
        $this->em()->flush();

        return $contact;
    }
}
