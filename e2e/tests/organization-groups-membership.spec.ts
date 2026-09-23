import { expect, test, type Page } from '@playwright/test';
import { login } from '../helpers/auth';
import { uniqueName } from '../helpers/test-data';

async function navigateToGroupsPage(page: Page) {
  await page.goto('/groups');
  await expect(page.locator('h1', { hasText: 'Мои группы' })).toBeVisible();
}

test('manager can add organizations to their group', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  const groupName = uniqueName('Группа для участников');

  await navigateToGroupsPage(page);
  await page.click('a:has-text("Новая группа")');
  await page.fill('input[name="name"]', groupName);
  await page.fill('textarea[name="description"]', 'Группа для теста участников');
  await page.fill('input[name="color"]', '#3b82f6');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/\/groups$/);

  const groupRow = page.locator('[data-group-row]', { hasText: groupName }).first();
  await groupRow.locator('a:has-text("Состав")').click();
  await expect(page.locator('h1', { hasText: 'Состав группы' })).toBeVisible();
  await page.locator('input[name="organizations[]"]').first().check();
  await page.click('button:has-text("Сохранить")');
  await expect(page).toHaveURL(/\/groups$/);

  const groupRowAfter = page.locator('[data-group-row]', { hasText: groupName }).first();
  await groupRowAfter.locator('a:has-text("Состав")').click();
  await expect(page.locator('input[name="organizations[]"]:checked').first()).toBeChecked();
});

test('manager can remove organizations from their group', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  const groupName = uniqueName('Группа для удаления участников');

  await navigateToGroupsPage(page);
  await page.click('a:has-text("Новая группа")');
  await page.fill('input[name="name"]', groupName);
  await page.fill('textarea[name="description"]', 'Группа для теста удаления участников');
  await page.fill('input[name="color"]', '#3b82f6');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/\/groups$/);

  const groupRow = page.locator('[data-group-row]', { hasText: groupName }).first();
  await groupRow.locator('a:has-text("Состав")').click();
  await expect(page.locator('h1', { hasText: 'Состав группы' })).toBeVisible();
  await page.locator('input[name="organizations[]"]').first().check();
  await page.click('button:has-text("Сохранить")');
  await expect(page).toHaveURL(/\/groups$/);

  const groupRowAgain = page.locator('[data-group-row]', { hasText: groupName }).first();
  await groupRowAgain.locator('a:has-text("Состав")').click();
  const checkboxes = page.locator('input[name="organizations[]"]');
  const count = await checkboxes.count();
  for (let i = 0; i < count; i++) {
    await checkboxes.nth(i).uncheck();
  }
  await page.click('button:has-text("Сохранить")');
  await expect(page).toHaveURL(/\/groups$/);

  const groupRowAfter = page.locator('[data-group-row]', { hasText: groupName }).first();
  await groupRowAfter.locator('a:has-text("Состав")').click();
  await expect(page.locator('input[name="organizations[]"]:checked')).toHaveCount(0);
});
