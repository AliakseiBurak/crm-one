import { expect, test, type Page } from '@playwright/test';

const loginSubmit = 'form[action="/login"] button[type="submit"]';

async function login(page: Page, email: string, password: string) {
  const login = email.split('@')[0];
  await page.goto('/login');
  await page.fill('input[name="_login"]', login);
  await page.fill('input[name="_password"]', password);
  await page.click(loginSubmit);
  await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toBeVisible();
}

test('главная страница перенаправляет гостя на вход', async ({ page }) => {
  const response = await page.goto('/');

  expect(response?.status()).toBe(200);
  await expect(page).toHaveURL(/\/login/);
  await expect(page.getByRole('heading', { name: 'Вход' })).toBeVisible();
});

test('вход администратором', async ({ page }) => {
  await page.goto('/login');
  await page.fill('input[name="_login"]', 'admin');
  await page.fill('input[name="_password"]', 'admin123');
  await page.click(loginSubmit);

  await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toBeVisible();

  await page.goto('/dashboard');
  await expect(page.getByRole('heading', { name: 'Панель' })).toBeVisible();
  await expect(page.locator('.dashboard-head__greeting')).toHaveText('Вы вошли как администратор admin@b2b-crm.loc');
});

test('вход менеджером', async ({ page }) => {
  await page.goto('/login');
  await page.fill('input[name="_login"]', 'manager');
  await page.fill('input[name="_password"]', 'manager123');
  await page.click(loginSubmit);

  // После логина — редирект на домашнюю страницу со статистикой
  await expect(page).toHaveURL(/\/$/);
  await expect(page.locator('.stats__total')).toBeVisible();

  await page.goto('/dashboard');
  await expect(page.getByRole('heading', { name: 'Панель' })).toBeVisible();
  await expect(page.locator('.dashboard-head__greeting')).toHaveText('Вы вошли как менеджер manager@b2b-crm.loc');
});

test('неверный пароль: ошибка и отсутствие сессии', async ({ page }) => {
  await page.goto('/login');
  await page.fill('input[name="_login"]', 'admin');
  await page.fill('input[name="_password"]', 'wrong-password');
  await page.click(loginSubmit);

  await expect(page.locator('.alert--error')).toBeVisible();
  await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toHaveCount(0);
});

// --- Установка пароля новым пользователем ---

test('чекбокс «Новый пользователь» переключает форму', async ({ page }) => {
  await page.goto('/login');

  // Форма входа видима, форма установки скрыта
  await expect(page.locator('#login-form')).toBeVisible();
  await expect(page.locator('#setup-password-form')).toBeHidden();

  // Отмечаем чекбокс
  await page.check('#new-user-toggle');

  // Форма входа скрыта, форма установки видима
  await expect(page.locator('#login-form')).toBeHidden();
  await expect(page.locator('#setup-password-form')).toBeVisible();
});

test('новый пользователь устанавливает пароль и входит', async ({ page }) => {
  // Создаём пользователя без пароля через API (админ создаёт)
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/admin/users/new');
  const email = `setup-e2e-${Date.now()}@example.com`;
  const loginName = email.split('@')[0];
  await page.fill('input[name="login"]', loginName);
  await page.fill('input[name="email"]', email);
  await page.selectOption('select[name="role"]', 'manager');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await page.waitForLoadState('networkidle');

  // Выходим
  await page.goto('/logout');
  await page.waitForLoadState('networkidle');

  // На странице входа отмечаем «Новый пользователь»
  await page.goto('/login');
  await page.check('#new-user-toggle');

  // Заполняем форму установки пароля
  await page.fill('#setup-password-form input[name="login"]', loginName);
  await page.fill('#setup-password-form input[name="new_password"]', 'securepass123');
  await page.fill('#setup-password-form input[name="confirm_password"]', 'securepass123');
  await page.click('#setup-password-form button[type="submit"]');

  // Проверяем сообщение об успехе
  await expect(page).toHaveURL(/\/login/);
  await expect(page.locator('.alert--warning')).toContainText('Пароль установлен');

  // Теперь входим с новым паролем
  await page.fill('input[name="_login"]', loginName);
  await page.fill('input[name="_password"]', 'securepass123');
  await page.click(loginSubmit);

  await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toBeVisible();
});

test('установка пароля: ошибка при коротком пароле', async ({ page }) => {
  // Создаём пользователя без пароля
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/admin/users/new');
  const email = `short-e2e-${Date.now()}@example.com`;
  const loginName = email.split('@')[0];
  await page.fill('input[name="login"]', loginName);
  await page.fill('input[name="email"]', email);
  await page.selectOption('select[name="role"]', 'manager');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await page.waitForLoadState('networkidle');
  await page.goto('/logout');
  await page.waitForLoadState('networkidle');

  await page.goto('/login');
  await page.check('#new-user-toggle');

  await page.fill('#setup-password-form input[name="login"]', loginName);
  await page.fill('#setup-password-form input[name="new_password"]', '1234567');
  await page.fill('#setup-password-form input[name="confirm_password"]', '1234567');
  await page.click('#setup-password-form button[type="submit"]');

  await expect(page).toHaveURL(/\/login/);
  await expect(page.locator('.alert--error')).toContainText('не менее 8 символов');
});

test('установка пароля: ошибка при несовпадении паролей', async ({ page }) => {
  // Создаём пользователя без пароля
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/admin/users/new');
  const email = `mismatch-e2e-${Date.now()}@example.com`;
  const loginName = email.split('@')[0];
  await page.fill('input[name="login"]', loginName);
  await page.fill('input[name="email"]', email);
  await page.selectOption('select[name="role"]', 'manager');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await page.waitForLoadState('networkidle');
  await page.goto('/logout');
  await page.waitForLoadState('networkidle');

  await page.goto('/login');
  await page.check('#new-user-toggle');

  await page.fill('#setup-password-form input[name="login"]', loginName);
  await page.fill('#setup-password-form input[name="new_password"]', 'securepass123');
  await page.fill('#setup-password-form input[name="confirm_password"]', 'differentpass');
  await page.click('#setup-password-form button[type="submit"]');

  await expect(page).toHaveURL(/\/login/);
  await expect(page.locator('.alert--error')).toContainText('не совпадают');
});