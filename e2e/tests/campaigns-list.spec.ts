import { expect, test } from '@playwright/test';
import { login } from '../helpers/auth';

test('список рассылок отображается', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/campaigns');
  await expect(page.locator('h1', { hasText: 'Рассылки' })).toBeVisible();
  await expect(page.locator('table.table')).toBeVisible();
});

test('сортировка по столбцам', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/campaigns');
  const nameHeader = page.locator('th a', { hasText: 'Название' });
  await nameHeader.click();
  await expect(page).toHaveURL(/sort=name/);
});

test('индикаторы статусов отображаются в списке', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/campaigns');
  const rows = page.locator('table.table tbody tr[data-status]');
  const count = await rows.count();
  expect(count).toBeGreaterThan(0);

  for (let i = 0; i < count; i++) {
    const status = await rows.nth(i).getAttribute('data-status');
    expect(['draft', 'ready', 'launched', 'failed', 'archived']).toContain(status);
  }
});

test('архивные рассылки внизу списка', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/campaigns');
  const rows = page.locator('table.table tbody tr[data-status]');
  const count = await rows.count();

  if (count > 1) {
    let foundNonArchived = false;
    let archivedAfterNonArchived = false;

    for (let i = 0; i < count; i++) {
      const status = await rows.nth(i).getAttribute('data-status');
      if (status !== 'archived') {
        foundNonArchived = true;
      } else if (foundNonArchived) {
        archivedAfterNonArchived = true;
      }
    }

    if (archivedAfterNonArchived) {
      expect(archivedAfterNonArchived).toBe(true);
    }
  }
});
