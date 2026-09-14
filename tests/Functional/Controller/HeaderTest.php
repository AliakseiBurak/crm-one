<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

/**
 * Функциональные тесты шапки и подвала (change menu-header-footer):
 * «Создать ▾» и «⚙ Админ ▾» по ролям, список пользователя с «Выйти»,
 * гамбургер и боковая панель, подвал только с копирайтом.
 */
final class HeaderTest extends DatabaseWebTestCase
{
    public function testAdminHeader(): void
    {
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin, 'Ада Админова'));

        $crawler = $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();

        // «Создать ▾» — 6 пунктов для админа.
        $this->assertSelectorExists('.header__actions .header-create__toggle');
        self::assertSame(6, $crawler->filter('.header__actions .header-create__menu .header-create__item')->count());

        // «⚙ Админ ▾» с двумя пунктами.
        $this->assertSelectorExists('.header__actions .header-admin__toggle');
        self::assertSame(2, $crawler->filter('.header__actions .header-admin__menu .header-admin__item')->count());

        // Выпадающий список пользователя: «Профиль», первый пункт — имя/email, затем «Выйти».
        $this->assertSelectorTextContains('.header__actions .header-user__toggle', 'Профиль');
        $this->assertSelectorTextContains('.header-user__info', 'Ада Админова');
        $this->assertSelectorTextContains('.header-user__info', 'admin@b2b-crm.loc');
        self::assertSame(1, $crawler->filter('.header__actions .header-user__menu a[href="/logout"]')->count());

        // Меню изначально закрыты.
        self::assertSame('false', $crawler->filter('.header-create__toggle')->attr('aria-expanded'));

        // Гамбургер присутствует в разметке.
        $this->assertSelectorExists('.header__hamburger');

        // Боковая панель: пункты навигации + «Создать» + блок пользователя.
        $this->assertSelectorExists('[data-header-sidebar]');
        $this->assertSelectorExists('[data-header-sidebar-overlay]');
        self::assertGreaterThanOrEqual(3, $crawler->filter('.header__sidebar-link')->count());
        self::assertSame(6, $crawler->filter('.header__sidebar .header-create__menu .header-create__item')->count());
        self::assertSame(1, $crawler->filter('.header__sidebar-user')->count());
        self::assertSame(1, $crawler->filter('.header__sidebar-user a[href="/logout"]')->count());

        // Подвал: только копирайт.
        $this->assertSelectorTextContains('.footer', '© ' . date('Y') . ' B2B Call CRM');
        $this->assertSelectorNotExists('.footer__col');
        $this->assertSelectorNotExists('.footer__menu');
    }

    public function testManagerHeader(): void
    {
        $this->login($this->makeUser('manager@b2b-crm.loc', UserRole::Manager, 'Пётр Сидоров'));

        $crawler = $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();

        // 5 пунктов: без «Пользователя».
        self::assertSame(5, $crawler->filter('.header__actions .header-create__menu .header-create__item')->count());

        // Нет «⚙ Админ».
        $this->assertSelectorNotExists('.header__actions .header-admin');

        // Пользователь: «Профиль» + «Выйти».
        $this->assertSelectorTextContains('.header__actions .header-user__toggle', 'Профиль');
        $this->assertSelectorTextContains('.header-user__info', 'Пётр Сидоров');
        self::assertSame(1, $crawler->filter('.header__actions .header-user__menu a[href="/logout"]')->count());
    }

    public function testAnonymousHeader(): void
    {
        $this->client->request('GET', '/login');
        $this->assertResponseIsSuccessful();

        // Без «Создать»/гамбургера/боковой панели; в навигации «Войти».
        $this->assertSelectorNotExists('.header-create');
        $this->assertSelectorNotExists('.header__hamburger');
        $this->assertSelectorTextContains('.header__nav', 'Войти');
    }

    public function testUserDropdownFallsBackToEmailWhenNameMissing(): void
    {
        $this->login($this->makeUser('noname@b2b-crm.loc', UserRole::Manager));

        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.header-user__toggle', 'Профиль');
        // Без имени/фамилии — только email, без строки имени.
        $this->assertSelectorNotExists('.header-user__info-name');
        $this->assertSelectorTextContains('.header-user__info-email', 'noname@b2b-crm.loc');
    }

    private function makeUser(string $email, UserRole $role, ?string $name = null): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setRole($role);
        if ($name !== null) {
            $user->setName($name);
        }
        $user->setPassword('test-password-hash');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }
}
