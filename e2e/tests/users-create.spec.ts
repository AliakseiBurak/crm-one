import { expect, test, type Page } from '@playwright/test';
import { login } from '../helpers/auth';
import { uniqueEmail } from '../helpers/test-data';

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
