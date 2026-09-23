import { expect, test, type Page } from '@playwright/test';
import { login, logout } from '../helpers/auth';
import { uniqueName, uniqueEmail } from '../helpers/test-data';

async function createGroup(page: Page, name: string): Promise<number> {
  await page.goto('/groups/new');
  await page.fill('input[name="name"]', name);
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/\/groups$/);
  return 1;
}

test('admin can delete manager with group reassign/delete choices', async ({ page }) => {
  const victimEmail = uniqueEmail('e2e-victim');
  const victimPassword = 'victimpass123';
  const groupA = uniqueName('Victim group A');
  const groupB = uniqueName('Victim group B');

  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/admin/users/new');
  const victimLogin = victimEmail.split('@')[0];
  await page.fill('input[name="login"]', victimLogin);
  await page.fill('input[name="email"]', victimEmail);
  await page.selectOption('select[name="role"]', 'manager');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/\/admin\/users$/);

  await logout(page);
  await page.goto('/login');
  const csrf = await page.locator('#setup-password-form input[name="_csrf_token"]').inputValue();
  const setupResponse = await page.request.post('/setup-password', {
    form: {
      login: victimLogin,
      new_password: victimPassword,
      confirm_password: victimPassword,
      _csrf_token: csrf,
    },
  });
  expect(setupResponse.ok()).toBe(true);

  await logout(page);
  await login(page, victimEmail, victimPassword);
  await createGroup(page, groupA);
  await createGroup(page, groupB);
  await logout(page);

  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/admin/users');
  const victimRow = page.locator('[data-user-row]', { hasText: victimLogin }).first();
  await expect(victimRow).toBeVisible();
  await victimRow.locator('a:has-text("Удалить")').click();

  await expect(page.locator('h1', { hasText: 'Удаление пользователя' })).toBeVisible();
  await expect(page.locator('h2', { hasText: 'Группы, созданные пользователем' })).toBeVisible();

  const choices = page.locator('.user-delete__group');
  expect(await choices.count()).toBeGreaterThanOrEqual(2);
  await choices.filter({ hasText: groupA }).locator('input[value="reassign"]').check();
  await choices.filter({ hasText: groupB }).locator('input[value="delete"]').check();

  await page.locator('button:has-text("Удалить")').click();
  await expect(page).toHaveURL(/\/admin\/users$/);
  await expect(page.locator('[data-user-row]', { hasText: victimLogin })).toHaveCount(0);

  await page.goto('/groups');
  await expect(page.locator('[data-group-row]', { hasText: groupA }).first()).toBeVisible();
  await expect(page.locator('[data-group-row]', { hasText: groupB })).toHaveCount(0);

  const rowA = page.locator('[data-group-row]', { hasText: groupA }).first();
  await rowA.locator('a:has-text("Редактировать")').click();
  await page.click('a:has-text("Удалить")');
  await page.locator('button:has-text("Удалить")').click();
  await expect(page).toHaveURL(/\/groups$/);
  await expect(page.locator('[data-group-row]', { hasText: groupA })).toHaveCount(0);
});

test('admin cannot delete manager without selecting group action', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/admin/users');

  const managerRow = page.locator('[data-user-row]', { hasText: 'manager2' }).first();
  await expect(managerRow).toBeVisible();
  await managerRow.locator('a:has-text("Удалить")').click();

  await expect(page.locator('h1', { hasText: 'Удаление пользователя' })).toBeVisible();
  await expect(page.locator('.user-delete__group').first()).toBeVisible();

  await page.locator('button:has-text("Удалить")').click();
  await expect(page).toHaveURL(/\/admin\/users\/\d+\/delete$/);

  await page.goto('/admin/users');
  await expect(page.locator('[data-user-row]', { hasText: 'manager2' })).toBeVisible();
});
