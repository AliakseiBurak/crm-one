<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

/**
 * Функциональные тесты SecurityController: вход, выход, установка
 * пароля новым пользователем (change add-new-user).
 */
final class SecurityControllerTest extends DatabaseWebTestCase
{
    // --- Login ---

    public function testLoginPageRendersForm(): void
    {
        $this->client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Вход');
        $this->assertSelectorExists('input[name="_username"]');
        $this->assertSelectorExists('input[name="_password"]');
    }

    public function testLoginWithValidCredentials(): void
    {
        $this->makeUser('admin@b2b-crm.loc', UserRole::Admin, 'password123');
        $this->client->request('GET', '/login');
        $this->submitFormByButton('Войти', [
            '_username' => 'admin@b2b-crm.loc',
            '_password' => 'password123',
        ]);

        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
    }

    public function testLoginWithWrongPasswordShowsError(): void
    {
        $this->makeUser('admin@b2b-crm.loc', UserRole::Admin, 'password123');
        $this->client->request('GET', '/login');
        $this->submitFormByButton('Войти', [
            '_username' => 'admin@b2b-crm.loc',
            '_password' => 'wrong-password',
        ]);

        $this->assertResponseRedirects('/login');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert--error', 'Неверный email или пароль');
    }

    // --- Setup Password ---

    public function testSetupPasswordSuccess(): void
    {
        $user = $this->makeUser('newuser@b2b-crm.loc', UserRole::Manager, '');

        $this->client->request('POST', '/setup-password', [
            'email' => 'newuser@b2b-crm.loc',
            'new_password' => 'securepass123',
            'confirm_password' => 'securepass123',
            '_csrf_token' => $this->setupPasswordCsrfToken(),
        ]);

        $this->assertResponseRedirects('/login');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert--warning', 'Пароль установлен');

        $this->em()->clear();
        $refreshed = $this->em()->find(User::class, $user->id);
        self::assertNotEmpty($refreshed->getPassword());
        self::assertNotSame('', $refreshed->getPassword());
    }

    public function testSetupPasswordUserNotFound(): void
    {
        $this->client->request('POST', '/setup-password', [
            'email' => 'nonexistent@b2b-crm.loc',
            'new_password' => 'securepass123',
            'confirm_password' => 'securepass123',
            '_csrf_token' => $this->setupPasswordCsrfToken(),
        ]);

        $this->assertResponseRedirects('/login');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert--error', 'не найден');
    }

    public function testSetupPasswordAlreadyHasPassword(): void
    {
        $this->makeUser('haspassword@b2b-crm.loc', UserRole::Manager, 'existing123');

        $this->client->request('POST', '/setup-password', [
            'email' => 'haspassword@b2b-crm.loc',
            'new_password' => 'newpassword123',
            'confirm_password' => 'newpassword123',
            '_csrf_token' => $this->setupPasswordCsrfToken(),
        ]);

        $this->assertResponseRedirects('/login');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert--error', 'не найден');
    }

    public function testSetupPasswordTooShort(): void
    {
        $this->makeUser('short@b2b-crm.loc', UserRole::Manager, '');

        $this->client->request('POST', '/setup-password', [
            'email' => 'short@b2b-crm.loc',
            'new_password' => '1234567',
            'confirm_password' => '1234567',
            '_csrf_token' => $this->setupPasswordCsrfToken(),
        ]);

        $this->assertResponseRedirects('/login');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert--error', 'не менее 8 символов');
    }

    public function testSetupPasswordMismatch(): void
    {
        $this->makeUser('mismatch@b2b-crm.loc', UserRole::Manager, '');

        $this->client->request('POST', '/setup-password', [
            'email' => 'mismatch@b2b-crm.loc',
            'new_password' => 'securepass123',
            'confirm_password' => 'differentpass',
            '_csrf_token' => $this->setupPasswordCsrfToken(),
        ]);

        $this->assertResponseRedirects('/login');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert--error', 'не совпадают');
    }

    public function testSetupPasswordEmptyFields(): void
    {
        $this->client->request('POST', '/setup-password', [
            'email' => '',
            'new_password' => '',
            'confirm_password' => '',
            '_csrf_token' => $this->setupPasswordCsrfToken(),
        ]);

        $this->assertResponseRedirects('/login');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert--error', 'Введите email');
    }

    // --- Helpers ---

    /**
     * CSRF-токен формы установки пароля со страницы входа (user-setup-password:
     * форма содержит _csrf_token; в PHP-контексте Twig-функция csrf_token()
     * недоступна — токен берётся из разметки).
     */
    private function setupPasswordCsrfToken(): string
    {
        $crawler = $this->client->request('GET', '/login');

        return $crawler->filter('#setup-password-form input[name="_csrf_token"]')->first()->attr('value');
    }

    private function makeUser(string $email, UserRole $role, string $password): User
    {
        $user = new User()
            ->setEmail($email)
            ->setRole($role);
        $user->setPassword($password);
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }
}
