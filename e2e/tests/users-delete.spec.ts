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

test('удаление менеджера показывает примечание о переназначении организаций', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  await page.goto('/organizations/new');
  const orgName = `Org-${Date.now()}`;
  await page.fill('input[name="name"]', orgName);
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await page.waitForLoadState('networkidle');

  await page.goto('/logout');
  await page.waitForLoadState('networkidle');

  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/admin/users');
  const managerRow = page.locator('[data-user-row]', { hasText: 'manager@b2b-crm.loc' }).first();
  await expect(managerRow).toBeVisible();
  await managerRow.locator('a:has-text("Удалить")').click();

  await expect(page.locator('h1', { hasText: 'Удаление пользователя' })).toBeVisible();
  await expect(page.locator('.user-delete__orgs')).toBeVisible();
  await expect(page.locator('.user-delete__orgs')).toContainText(orgName);
  await expect(page.locator('.user-delete__orgs')).toContainText('переназначены вам');

  await page.goto('/dashboard');
  const orgRow = page.locator('.org-table__row', { hasText: orgName });
  if (await orgRow.count() > 0) {
    const editLink = orgRow.locator('a[href*="/edit"]');
    const href = await editLink.getAttribute('href');
    if (href) {
      await page.goto(href);
      await page.click('a:has-text("Удалить")');
      await page.locator('button:has-text("Удалить")').click();
      await page.waitForLoadState('networkidle');
    }
  }
});

test('удаление менеджера переназначает его организации текущему админу', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  await page.goto('/organizations/new');
  const orgName = `Reassign-${Date.now()}`;
  await page.fill('input[name="name"]', orgName);
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await page.waitForLoadState('networkidle');

  await page.goto('/logout');
  await page.waitForLoadState('networkidle');

  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/admin/users');
  const managerRow = page.locator('[data-user-row]', { hasText: 'manager@b2b-crm.loc' }).first();
  await managerRow.locator('a:has-text("Удалить")').click();
  await page.locator('button:has-text("Удалить")').last().click();
  await page.waitForLoadState('networkidle');

  await page.goto('/dashboard');
  await expect(page.locator('.org-table__row', { hasText: orgName })).toBeVisible();

  const orgRow = page.locator('.org-table__row', { hasText: orgName });
  const editLink = orgRow.locator('a[href*="/edit"]');
  const href = await editLink.getAttribute('href');
  if (href) {
    await page.goto(href);
    await page.click('a:has-text("Удалить")');
    await page.locator('button:has-text("Удалить")').click();
    await page.waitForLoadState('networkidle');
  }
});

test('удаление администратора не требует переназначения организаций', async ({ page }) => {
  const email = uniqueEmail('admin-temp');
  const loginName = email.split('@')[0];
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await createUser(page, email, 'admin');

  const row = page.locator('tr', { hasText: loginName });
  await row.getByRole('link', { name: 'Удалить' }).click();
  await expect(page.locator('h1', { hasText: 'Удаление пользователя' })).toBeVisible();
  await expect(page.locator('.user-delete__orgs')).toHaveCount(0);
});
