import { expect, test, type Page } from '@playwright/test';
import { login, logout } from '../helpers/auth';
import { uniqueName } from '../helpers/test-data';

async function createGroup(page: Page, name: string, opts?: { description?: string; color?: string }): Promise<number> {
  await page.goto('/groups/new');
  await page.fill('input[name="name"]', name);
  if (opts?.description) {
    await page.fill('textarea[name="description"]', opts.description);
  }
  if (opts?.color) {
    await page.fill('input[name="color"]', opts.color);
  }
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/\/groups$/);
  return 1;
}

async function navigateToGroupsPage(page: Page) {
  await page.goto('/groups');
  await expect(page.locator('h1', { hasText: 'Мои группы' })).toBeVisible();
}

async function groupIdByName(page: Page, name: string): Promise<number> {
  await page.goto('/groups');
  const href = await page.locator('[data-group-row]', { hasText: name }).first()
    .locator('a[href$="/edit"]').first().getAttribute('href');
  const match = (href ?? '').match(/\/groups\/(\d+)\/edit/);
  return match ? parseInt(match[1] as string, 10) : 0;
}

test('manager can create a new group', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  const groupName = uniqueName('Тестовая группа');

  await navigateToGroupsPage(page);
  await page.click('a:has-text("Новая группа")');
  await expect(page.locator('h1', { hasText: 'Новая группа' })).toBeVisible();
  await page.fill('input[name="name"]', groupName);
  await page.fill('textarea[name="description"]', 'Описание тестовой группы');
  await page.fill('input[name="color"]', '#3b82f6');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/\/groups$/);
  await expect(page.locator('body', { hasText: groupName })).toBeVisible();
});

test('manager can edit their own group', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  const groupName = uniqueName('Группа для редактирования');

  await navigateToGroupsPage(page);
  await page.click('a:has-text("Новая группа")');
  await page.fill('input[name="name"]', groupName);
  await page.fill('textarea[name="description"]', 'Оригинальное описание');
  await page.fill('input[name="color"]', '#3b82f6');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/\/groups$/);

  const groupRow = page.locator('[data-group-row]', { hasText: groupName }).first();
  await groupRow.locator('a:has-text("Редактировать")').click();
  await expect(page.locator('h1', { hasText: 'Редактировать группу' })).toBeVisible();
  await page.fill('input[name="name"]', `${groupName} Обновленная`);
  await page.fill('textarea[name="description"]', 'Обновленное описание');
  await page.fill('input[name="color"]', '#ef4444');
  await page.click('button:has-text("Сохранить")');
  await expect(page).toHaveURL(/\/groups$/);
  await expect(page.locator('body', { hasText: `${groupName} Обновленная` })).toBeVisible();
});

test('manager cannot edit other manager\'s group', async ({ page }) => {
  const groupName = uniqueName('Чужая группа (edit)');
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  await createGroup(page, groupName);
  const groupId = await groupIdByName(page, groupName);
  expect(groupId).toBeGreaterThan(0);

  await logout(page);
  await login(page, 'manager2@b2b-crm.loc', 'manager123');
  const response = await page.goto(`/groups/${groupId}/edit`);
  expect(response?.status()).toBe(403);
});

test('manager can delete their own group', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  const groupName = uniqueName('Группа для удаления');

  await navigateToGroupsPage(page);
  await page.click('a:has-text("Новая группа")');
  await page.fill('input[name="name"]', groupName);
  await page.fill('textarea[name="description"]', 'Группа для теста удаления');
  await page.fill('input[name="color"]', '#3b82f6');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/\/groups$/);

  const groupRow = page.locator('[data-group-row]', { hasText: groupName }).first();
  await groupRow.locator('a:has-text("Редактировать")').click();
  await page.click('a:has-text("Удалить")');
  await expect(page.locator('h1', { hasText: 'Удаление группы' })).toBeVisible();
  await page.click('button:has-text("Удалить")');
  await expect(page).toHaveURL(/\/groups$/);
  await expect(page.locator('body', { hasText: groupName })).toBeHidden();
});

test('manager cannot delete other manager\'s group', async ({ page }) => {
  const groupName = uniqueName('Чужая группа (delete)');
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  await createGroup(page, groupName);
  const groupId = await groupIdByName(page, groupName);
  expect(groupId).toBeGreaterThan(0);

  await logout(page);
  await login(page, 'manager2@b2b-crm.loc', 'manager123');
  const response = await page.goto(`/groups/${groupId}/delete`);
  expect(response?.status()).toBe(403);
});
