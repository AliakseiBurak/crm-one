import { expect, test } from '@playwright/test';
import { login } from '../helpers/auth';

async function orgNames(page: import('@playwright/test').Page): Promise<string[]> {
  return page.locator('.org-table__name').allTextContents();
}

test('сортировка по названию и дате следующего звонка', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  let names = await orgNames(page);
  expect(names[0]).toContain('Вектор');

  await page.getByRole('link', { name: 'Название' }).click();
  names = await orgNames(page);
  expect(names[0]).toContain('Вектор');
  expect(names[names.length - 1]).toContain('Ромашка');

  await page.getByRole('link', { name: 'Следующий звонок' }).click();
  const nextDates = await page.locator('.org-table__row td:nth-child(3)').allTextContents();
  const dateless = nextDates.filter((d) => d !== '—');
  expect(dateless.length).toBeGreaterThan(0);
});

test('сортировка по «Активна» и «Дата отписки»', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  await page.getByRole('link', { name: 'Активна' }).click();
  await expect(page.locator('.table__sortable--active', { hasText: 'Активна' })).toBeVisible();
  await expect(page.locator('.org-table__name').first()).toContainText('Горизонт');

  await page.getByRole('link', { name: 'Дата отписки' }).click();
  await expect(page.locator('.table__sortable--active', { hasText: 'Дата отписки' })).toBeVisible();
  const optoutDates = await page.locator('.org-table__row td:nth-child(5)').allTextContents();
  const dated = optoutDates.filter((d) => d !== '—');
  expect(dated.length).toBe(2);
  expect(optoutDates.slice(dated.length)).toEqual(
    Array(optoutDates.length - dated.length).fill('—'),
  );
});

test('сортировочные заголовки — обычные кликабельные ссылки с указанием направления', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  const sortables = page.locator('.table__sortable');
  await expect(sortables).toHaveCount(5);
  await expect(sortables.first()).toBeVisible();
  await page.getByRole('link', { name: 'Название' }).click();
  await expect(page.locator('.table__sortable--active', { hasText: 'Название' })).toBeVisible();
});
