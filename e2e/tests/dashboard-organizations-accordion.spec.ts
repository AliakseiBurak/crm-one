import { expect, test } from '@playwright/test';
import { login } from '../helpers/auth';

test('наведение подсвечивает строку оттенком зебры, не убирая цвет', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  const row = page.locator('.org-table__row').first();
  await row.hover();
  const hoverBg = await row.evaluate((el) => getComputedStyle(el).backgroundColor);
  expect(hoverBg).toBe('rgb(207, 230, 242)');

  await row.click();
  const detailsRow = page.locator('.org-details').first();
  await detailsRow.hover();
  const detailsBg = await detailsRow.evaluate((el) => getComputedStyle(el).backgroundColor);
  expect(detailsBg).toBe('rgba(0, 0, 0, 0)');
});

test('организация без контактов и звонков: только кнопки действия', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  const horizon = page.locator('.org-table__row', { hasText: 'Горизонт' });
  const details = horizon.locator('xpath=./following-sibling::tr[1]').locator('.org-details__box');
  await horizon.click();
  await expect(details.locator('.org-contacts__card-wrap')).toHaveCount(0);
  await expect(details.locator('a.org-contacts__add', { hasText: 'Добавить контакт' })).toBeVisible();
  await expect(details.locator('a.org-calls__add', { hasText: 'Добавить звонок' })).toBeVisible();
  await expect(details.locator('.org-calls')).toHaveCount(0);
});

test('организация с контактом, но без звонков: карточка есть, списка звонков нет', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  const zakat = page.locator('.org-table__row', { hasText: 'Закат' });
  const details = zakat.locator('xpath=./following-sibling::tr[1]').locator('.org-details__box');
  await zakat.click();
  await expect(details.locator('.org-contacts__card-wrap .card')).toHaveCount(1);
  await expect(details.locator('.org-contacts__card-wrap .card .card__name')).toContainText('Ольга Викторовна');
  await expect(details.locator('.org-calls')).toHaveCount(0);
});
