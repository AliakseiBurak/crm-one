import { expect, test } from '@playwright/test';
import { login } from '../helpers/auth';

test('даты звоноков берутся из звонков; без звонков — заглушка «—»', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  const romashka = page.locator('.org-table__row', { hasText: 'Ромашка' });
  await expect(romashka.locator('td').nth(1)).toHaveText(/\d{2}\.\d{2}\.\d{4}/);
  await expect(romashka.locator('td').nth(2)).toHaveText(/\d{2}\.\d{2}\.\d{4}/);
  await expect(romashka.locator('a', { hasText: 'Изменить дату' })).toHaveCount(0);
  await expect(romashka.locator('td').nth(2).locator('a')).toHaveCount(0);

  const sidorov = page.locator('.org-table__row', { hasText: 'Сидоров' });
  await expect(sidorov.locator('td').nth(1)).toHaveText(/\d{2}\.\d{2}\.\d{4}/);
  await expect(sidorov.locator('td').nth(2)).toHaveText(/\d{2}\.\d{2}\.\d{4}/);

  const zakat = page.locator('.org-table__row', { hasText: 'Закат' });
  await expect(zakat.locator('td').nth(1)).toHaveText('—');
  await expect(zakat.locator('td').nth(2)).toHaveText('—');

  const horizon = page.locator('.org-table__row', { hasText: 'Горизонт' });
  await expect(horizon.locator('td').nth(1)).toHaveText('—');
  await expect(horizon.locator('td').nth(2)).toHaveText('—');
});

test('колонки «Активна» и «Дата отписки»: статус чекбоксом и дата отписки', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  const horizon = page.locator('.org-table__row', { hasText: 'Горизонт' });
  await expect(horizon.locator('td').nth(3).locator('input[type="checkbox"]')).not.toBeChecked();
  await expect(horizon.locator('td').nth(4)).toHaveText('—');

  const zakat = page.locator('.org-table__row', { hasText: 'Закат' });
  await expect(zakat.locator('td').nth(3).locator('input[type="checkbox"]')).toBeChecked();
  await expect(zakat.locator('td').nth(4)).toHaveText(/\d{2}\.\d{2}\.\d{4}/);
});
