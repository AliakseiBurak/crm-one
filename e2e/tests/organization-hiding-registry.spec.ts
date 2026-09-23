import { expect, test } from '@playwright/test';
import { login } from '../helpers/auth';

const ADMIN = 'admin@b2b-crm.loc';
const ADMIN_PASSWORD = 'admin123';
const MANAGER1 = 'manager@b2b-crm.loc';
const MANAGER_PASSWORD = 'manager123';

test('менеджер получает 403 в разделе скрытий, в навигации пункта нет', async ({ page }) => {
  await login(page, MANAGER1, MANAGER_PASSWORD);

  await page.goto('/dashboard');
  await expect(
    page.locator('.header__menu-link', { hasText: 'Скрытые организации' }),
  ).toHaveCount(0);

  const list = await page.goto('/admin/hides');
  expect(list?.status()).toBe(403);

  await page.goto('/logout');
  await login(page, ADMIN, ADMIN_PASSWORD);
  await page.locator('[data-header-admin-toggle]').click();
  const link = page.locator('.header-admin__item', { hasText: 'Скрытые организации' });
  await expect(link).toBeVisible();
  await link.click();
  await expect(page.locator('h1', { hasText: 'Скрытые организации' })).toBeVisible();
  await expect(page.locator('tr[data-hide-row]', { hasText: 'Конкурент' }).first()).toBeVisible();
  await expect(page.locator('select[name="organization"]')).toBeVisible();
  await expect(page.locator('select[name="managers[]"]')).toBeVisible();
});

test('реестр скрытий: поисковый выпадающий список организаций', async ({ page }) => {
  await login(page, ADMIN, ADMIN_PASSWORD);
  await page.goto('/admin/hides');
  await expect(page.locator('h1', { hasText: 'Скрытые организации' })).toBeVisible();

  const form = page.locator('.campaign-recipients__add');
  const toggle = form.locator('.org-combobox__toggle');
  await expect(toggle).toBeVisible();
  await toggle.click();

  const search = form.locator('.org-combobox__search');
  await expect(search).toBeVisible();
  await search.fill('заведомо-нет-такой-организации');
  await expect(form.locator('.org-combobox__empty')).toHaveText('Ничего не найдено');

  await search.fill('Вектор');
  await form.locator('.org-combobox__option', { hasText: 'Вектор' }).first().click();
  await expect(page.locator('select[name="organization"]')).toHaveValue(/\d+/);
});

test('реестр скрытий показывает колонки "Дата создания" и "Отрасль"', async ({ page }) => {
  await login(page, ADMIN, ADMIN_PASSWORD);
  await page.goto('/admin/hides');
  await expect(page.locator('h1', { hasText: 'Скрытые организации' })).toBeVisible();

  const headers = page.locator('th');
  await expect(headers.filter({ hasText: 'Дата создания' })).toBeVisible();
  await expect(headers.filter({ hasText: 'Отрасль' })).toBeVisible();

  const rows = page.locator('tbody tr');
  const rowCount = await rows.count();
  expect(rowCount).toBeGreaterThanOrEqual(1);

  const firstRow = rows.first();
  const cells = firstRow.locator('td');
  const cellCount = await cells.count();
  let foundDate = false;
  let foundIndustry = false;
  for (let i = 0; i < cellCount; i++) {
    const text = await cells.nth(i).textContent();
    if (text && /\d{2}\.\d{2}\.\d{4}/.test(text)) {
      foundDate = true;
    }
    if (text !== null) {
      foundIndustry = true;
    }
  }
  expect(foundDate).toBe(true);
  expect(foundIndustry).toBe(true);
});
