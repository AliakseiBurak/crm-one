import { expect, test } from '@playwright/test';
import { login } from '../helpers/auth';

async function orgNames(page: import('@playwright/test').Page): Promise<string[]> {
  return page.locator('.org-table__name').allTextContents();
}

test('поиск по названию организации и по контактам', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

   await page.fill('.org-search__input', 'Ромашка');
   await page.click('.org-search button[type="submit"]');
   await expect(page.locator('.org-table__row')).toHaveCount(1);
   await expect(page.locator('.org-table__name').first()).toContainText('Ромашка');

   await page.locator('.org-search__input').fill('');
   await page.waitForURL((url) => !url.searchParams.has('q'));
   expect((await orgNames(page)).length).toBeGreaterThan(1);

  await page.fill('.org-search__input', 'contact3@example.ru');
  await page.click('.org-search button[type="submit"]');
  const names = await orgNames(page);
  expect(names.length).toBeGreaterThan(0);
  expect(names.some((n) => n.includes('Вектор'))).toBe(true);
  expect(names.some((n) => n.includes('Ромашка'))).toBe(false);

  await page.fill('.org-search__input', 'неттакого');
  await page.click('.org-search button[type="submit"]');
  await expect(page.locator('.org-table__empty')).toHaveText('Ничего не найдено');
});

test('фильтры «Неактивные» и «Отписавшиеся»: пересечение и сохранение при сортировке', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  await page.check('input[name="inactive"]');
  await page.click('.org-search button[type="submit"]');
  await expect(page.locator('.org-table__row')).toHaveCount(1);
  await expect(page.locator('.org-table__name').first()).toContainText('Горизонт');

  await expect(page.locator('input[name="inactive"]')).toBeChecked();
  await expect(page.locator('a.table__sortable').first()).toHaveAttribute('href', /inactive=1/);

  await page.getByRole('link', { name: 'Активна' }).click();
  await expect(page.locator('input[name="inactive"]')).toBeChecked();
  await expect(page.locator('.org-table__row')).toHaveCount(1);

  await page.uncheck('input[name="inactive"]');
  await page.check('input[name="optout"]');
  await page.click('.org-search button[type="submit"]');
  const names = await orgNames(page);
  expect(names.length).toBe(2);
  expect(names.some((n) => n.includes('Конкурент'))).toBe(true);
  expect(names.some((n) => n.includes('Закат'))).toBe(true);

  await page.check('input[name="inactive"]');
  await page.click('.org-search button[type="submit"]');
  await expect(page.locator('.org-table__empty')).toHaveText('Ничего не найдено');
});

test('очистка поиска сохраняет фильтры и убирает q из URL', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  await page.check('input[name="inactive"]');
  await page.fill('.org-search__input', 'Горизонт');
  await page.click('.org-search button[type="submit"]');
  await expect(page.locator('.org-table__row')).toHaveCount(1);
  await expect(page).toHaveURL(/inactive=1/);

  await page.locator('.org-search__input').fill('');
  await page.waitForURL((url) => !url.searchParams.has('q'));
  await expect(page.locator('input[name="inactive"]')).toBeChecked();
  await expect(page.locator('.org-table__row')).toHaveCount(1);
  await expect(page.locator('.org-table__name').first()).toContainText('Горизонт');
});
