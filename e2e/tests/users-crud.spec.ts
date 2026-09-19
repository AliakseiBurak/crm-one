import { expect, test, type Page } from '@playwright/test';

// CRUD пользователей (change add-new-user):
// создание, удаление, список, проверка доступа.

const loginSubmit = 'form[action="/login"] button[type="submit"]';

function uniqueEmail(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 6)}@example.com`;
}

async function login(page: Page, email: string, password: string) {
  const login = email.split('@')[0];
  await page.goto('/login');
  await page.fill('input[name="_login"]', login);
  await page.fill('input[name="_password"]', password);
  await page.click(loginSubmit);
  await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toBeVisible();
}

async function createUser(page: Page, email: string, role: string, name = '', surname = '') {
  const login = email.split('@')[0];
  await page.goto('/admin/users/new');
  await page.fill('input[name="login"]', login);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="name"]', name);
  await page.fill('input[name="surname"]', surname);
  await page.selectOption('select[name="role"]', role);
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await page.waitForLoadState('networkidle');
}

// --- Список пользователей ---

test('администратор видит ссылку «Пользователи» в навигации', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.locator('[data-header-admin-toggle]').click();
  await expect(page.locator('.header-admin__item', { hasText: 'Пользователи' })).toBeVisible();
});

test('администратор открывает список пользователей', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/admin/users');

  await expect(page.getByRole('heading', { name: 'Пользователи' })).toBeVisible();
  await expect(page.locator('.table')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Новый пользователь' })).toBeVisible();
});

test('менеджер не видит ссылку «Пользователи» в навигации', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  await expect(page.locator('.header__menu-link', { hasText: 'Пользователи' })).toHaveCount(0);
});

test('менеджер получает 403 при попытке открыть список пользователей', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  const response = await page.goto('/admin/users');
  expect(response?.status()).toBe(403);
});

test('неаутентифицированный пользователь перенаправляется на вход', async ({ page }) => {
  const response = await page.goto('/admin/users');
  expect(response?.status()).toBe(200);
  await expect(page).toHaveURL(/\/login/);
});

// --- Создание пользователя ---

test('администратор открывает форму создания пользователя', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/admin/users/new');

  await expect(page.getByRole('heading', { name: 'Новый пользователь' })).toBeVisible();
  await expect(page.locator('input[name="login"]')).toBeVisible();
  await expect(page.locator('input[name="email"]')).toBeVisible();
  await expect(page.locator('input[name="name"]')).toBeVisible();
  await expect(page.locator('input[name="surname"]')).toBeVisible();
  await expect(page.locator('select[name="role"]')).toBeVisible();
});

test('администратор создаёт пользователя с именем и фамилией', async ({ page }) => {
  const email = uniqueEmail('maria');
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await createUser(page, email, 'manager', 'Мария', 'Смирнова');

  await expect(page).toHaveURL(/\/admin\/users/);
  const row = page.locator('tr', { hasText: email.split('@')[0] });
  await expect(row).toBeVisible();
  await expect(row).toContainText('Мария');
  await expect(row).toContainText('Смирнова');
});

test('администратор создаёт пользователя без имени и фамилии', async ({ page }) => {
  const email = uniqueEmail('noname');
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await createUser(page, email, 'admin');

  await expect(page).toHaveURL(/\/admin\/users/);
  const row = page.locator('tr', { hasText: email.split('@')[0] });
  await expect(row).toBeVisible();
});

test('ошибка при создании без логина', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/admin/users/new');

  await page.fill('input[name="login"]', '');
  await page.fill('input[name="email"]', 'test@example.com');
  await page.selectOption('select[name="role"]', 'manager');
  // Убираем HTML5 required для тестирования серверной валидации
  await page.evaluate(() => {
    document.querySelectorAll('[required]').forEach(el => el.removeAttribute('required'));
  });
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await page.waitForLoadState('networkidle');

  await expect(page.locator('.field__error').first()).toBeVisible();
});

test('ошибка при создании с существующим email', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/admin/users/new');

  await page.fill('input[name="login"]', 'dup-login-test');
  await page.fill('input[name="email"]', 'admin@b2b-crm.loc');
  await page.selectOption('select[name="role"]', 'manager');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await page.waitForLoadState('networkidle');

  await expect(page.locator('.field__error')).toContainText('уже существует');
});

// --- Удаление пользователя ---

test('администратор подтверждает удаление пользователя', async ({ page }) => {
  const email = uniqueEmail('to-delete');
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await createUser(page, email, 'manager');

  const row = page.locator('tr', { hasText: email.split('@')[0] });
  await row.getByRole('link', { name: 'Удалить' }).click();

  await expect(page.getByRole('heading', { name: 'Удаление пользователя' })).toBeVisible();
  await expect(page.locator('.user-delete__warning')).toContainText(email.split('@')[0]);
});

test('администратор удаляет пользователя через подтверждение', async ({ page }) => {
  const email = uniqueEmail('do-delete');
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await createUser(page, email, 'manager');

  const row = page.locator('tr', { hasText: email.split('@')[0] });
  await row.getByRole('link', { name: 'Удалить' }).click();
  await page.getByRole('button', { name: 'Удалить' }).last().click();
  await page.waitForLoadState('networkidle');

  await expect(page).toHaveURL(/\/admin\/users/);
  await expect(page.locator('tr', { hasText: email.split('@')[0] })).toHaveCount(0);
});

test('кнопка удаления отсутствует для текущего администратора', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/admin/users');

  const row = page.locator('tr', { hasText: 'admin' });
  await expect(row.getByRole('link', { name: 'Удалить' })).toHaveCount(0);
});

test('отмена удаления возвращает к списку', async ({ page }) => {
  const email = uniqueEmail('cancel-del');
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await createUser(page, email, 'manager');

  const row = page.locator('tr', { hasText: email.split('@')[0] });
  await row.getByRole('link', { name: 'Удалить' }).click();
  await page.getByRole('link', { name: 'Отмена' }).click();

  await expect(page).toHaveURL(/\/admin\/users/);
  await expect(page.locator('tr', { hasText: email.split('@')[0] })).toBeVisible();
});
