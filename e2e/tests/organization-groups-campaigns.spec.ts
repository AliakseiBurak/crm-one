import { expect, test, type Page } from '@playwright/test';
import { login } from '../helpers/auth';
import { setCampaignBody } from '../helpers/editor';
import { uniqueName } from '../helpers/test-data';

async function createGroup(page: Page, name: string): Promise<number> {
  await page.goto('/groups/new');
  await page.fill('input[name="name"]', name);
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/\/groups$/);
  return 1;
}

async function createCampaign(page: Page, name: string): Promise<number> {
  await page.goto('/campaigns/new');
  await page.fill('input[name="name"]', name);
  await page.fill('input[name="subject"]', 'Тема теста');
  await setCampaignBody(page, 'Текст письма');
  await page.selectOption('select[name="status"]', 'ready');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/highlight=(\d+)/);
  const match = page.url().match(/highlight=(\d+)/);
  return match ? parseInt(match[1] as string, 10) : 0;
}

test('manager can bulk add organizations from group to campaign recipients', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  const groupName = uniqueName('Группа для рассылки');
  const campaignName = uniqueName('Рассылка для групп');

  await createGroup(page, groupName);
  const groupRow = page.locator('[data-group-row]', { hasText: groupName }).first();
  await groupRow.locator('a:has-text("Состав")').click();
  await page.locator('input[name="organizations[]"]').first().check();
  await page.click('button:has-text("Сохранить")');
  await expect(page).toHaveURL(/\/groups$/);

  const campaignId = await createCampaign(page, campaignName);
  expect(campaignId).toBeGreaterThan(0);

  await page.goto(`/campaigns/${campaignId}/recipients`);
  await expect(page.locator('h1', { hasText: 'Адресаты рассылки' })).toBeVisible();
  const groupButton = page.locator('button[data-group-id]', { hasText: groupName });
  await expect(groupButton).toBeVisible();
  await groupButton.click();
  await expect(page).toHaveURL(new RegExp(`/campaigns/${campaignId}/recipients$`));
  await expect(page.locator('.campaign-recipients__table tbody tr')).toBeVisible();
});

test('manager cannot bulk add from inaccessible group', async ({ page }) => {
  await login(page, 'manager2@b2b-crm.loc', 'manager123');
  const campaignId = await createCampaign(page, uniqueName('Рассылка manager2'));
  expect(campaignId).toBeGreaterThan(0);

  await page.goto(`/campaigns/${campaignId}/recipients`);
  await expect(page.locator('h1', { hasText: 'Адресаты рассылки' })).toBeVisible();
  await expect(page.locator('button[data-group-id]', { hasText: 'Клиенты Вектор' })).toBeVisible();
  await expect(page.locator('button[data-group-id]', { hasText: 'Клиенты Ромашка' })).toHaveCount(0);
});
