import { expect, test } from '@playwright/test';
import { login } from '../helpers/auth';

test('состав группы показывает колонки "Дата создания", "Создатель", "Отрасль"', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/groups');

  const groupRow = page.locator('[data-group-row]').first();
  await groupRow.locator('a:has-text("Состав")').click();
  await expect(page.locator('h1', { hasText: 'Состав группы' })).toBeVisible();

  const headers = page.locator('th');
  await expect(headers.filter({ hasText: 'Название' })).toBeVisible();
  await expect(headers.filter({ hasText: 'Отрасль' })).toBeVisible();
  await expect(headers.filter({ hasText: 'Дата создания' })).toBeVisible();
  await expect(headers.filter({ hasText: 'Создатель' })).toBeVisible();

  const rows = page.locator('tbody tr');
  const rowCount = await rows.count();
  expect(rowCount).toBeGreaterThanOrEqual(1);
});

test('состав группы: сортировка по имени работает по умолчанию (А–Я)', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/groups');

  const groupRow = page.locator('[data-group-row]').first();
  await groupRow.locator('a:has-text("Состав")').click();
  await expect(page.locator('h1', { hasText: 'Состав группы' })).toBeVisible();

  const nameHeader = page.locator('th a', { hasText: 'Название' }).first();
  await expect(nameHeader).toBeVisible();

  const names = await page.locator('tbody td:first-child').allTextContents();
  const sorted = [...names].sort((a, b) => a.localeCompare(b, 'ru'));
  expect(names).toEqual(sorted);
});

test('состав группы: клик по заголовку "Дата создания" меняет сортировку', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/groups');

  const groupRow = page.locator('[data-group-row]').first();
  await groupRow.locator('a:has-text("Состав")').click();
  await expect(page.locator('h1', { hasText: 'Состав группы' })).toBeVisible();

  const dateHeader = page.locator('th a', { hasText: 'Дата создания' }).first();
  await dateHeader.click();
  await page.waitForLoadState('networkidle');

  expect(page.url()).toContain('sort=createdAt');
  await expect(page.locator('th a.table__sortable--active', { hasText: 'Дата создания' })).toBeVisible();
});
