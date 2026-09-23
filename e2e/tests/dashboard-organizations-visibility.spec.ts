import { expect, test } from '@playwright/test';
import { login } from '../helpers/auth';

async function orgNames(page: import('@playwright/test').Page): Promise<string[]> {
  return page.locator('.org-table__name').allTextContents();
}

test('менеджер видит все организации, кроме скрытых от него', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  await page.goto('/dashboard');

  await expect(page.getByRole('heading', { name: 'Организации' })).toBeVisible();
  const names = await orgNames(page);
  expect(names.some((n) => n.includes('Ромашка'))).toBe(true);
  expect(names.some((n) => n.includes('Вектор'))).toBe(true);
  expect(names.some((n) => n.includes('Конкурент'))).toBe(false);
});

test('администратор видит все организации', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  const names = await orgNames(page);
  expect(names.some((n) => n.includes('Ромашка'))).toBe(true);
  expect(names.some((n) => n.includes('Вектор'))).toBe(true);
  expect(names.some((n) => n.includes('Сидоров'))).toBe(true);
  expect(names.some((n) => n.includes('Конкурент'))).toBe(true);
});
