import { expect, test, type Page } from '@playwright/test';
import { login } from '../helpers/auth';
import { uniqueName } from '../helpers/test-data';

async function createCampaign(page: Page, name: string): Promise<number> {
  await page.goto('/campaigns/new');
  await page.fill('input[name="name"]', name);
  await page.fill('input[name="subject"]', 'Тема');
  await page.fill('textarea[name="body"]', 'Текст письма');
  await page.selectOption('select[name="status"]', 'ready');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/highlight=(\d+)/);
  const match = page.url().match(/highlight=(\d+)/);
  return match ? parseInt(match[1], 10) : 0;
}

test('страница адресатов отображается', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Для Адресатов');

  const id = await createCampaign(page, name);
  await page.goto(`/campaigns/${id}/recipients`);

  await expect(page.locator('h1', { hasText: 'Адресаты рассылки' })).toBeVisible();
  const hasTable = await page.locator('.campaign-recipients__table').isVisible().catch(() => false);
  if (hasTable) {
    await expect(page.locator('th', { hasText: 'Статус' })).toBeVisible();
  } else {
    await expect(page.locator('.campaign-recipients__empty')).toBeVisible();
  }
});

test('добавление адресата', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Адресат Добавление');

  const id = await createCampaign(page, name);
  await page.goto(`/campaigns/${id}/recipients`);

  const orgSelect = page.locator('select[name="organization"]');
  if ((await orgSelect.locator('option').count()) > 1) {
    await orgSelect.selectOption({ index: 1 });
    await page.click('button:has-text("Добавить")');
    await expect(page.locator('.campaign-recipients__table')).toBeVisible();
  }
});

test('массовое добавление всех организаций', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Массовое');

  const id = await createCampaign(page, name);
  await page.goto(`/campaigns/${id}/recipients`);

  await page.click('button:has-text("Выбрать все организации")');
  await expect(page.locator('.campaign-recipients__table tbody tr').first()).toBeVisible();
});

test('замена адресата при добавлении дубля организации', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Замена Адресата');

  const id = await createCampaign(page, name);
  await page.goto(`/campaigns/${id}/recipients`);

  const orgSelect = page.locator('select[name="organization"]');
  if ((await orgSelect.locator('option').count()) > 1) {
    await orgSelect.selectOption({ index: 1 });
    await page.waitForTimeout(200);
    const contactSelect = page.locator('select[name="contact"]');
    const contactCount = await contactSelect.locator('option').count();

    if (contactCount > 1) {
      await contactSelect.selectOption({ index: 1 });
      await page.click('button:has-text("Добавить")');
      await expect(page.locator('.campaign-recipients__table')).toBeVisible();

      await orgSelect.selectOption({ index: 1 });
      await page.waitForTimeout(200);
      await contactSelect.selectOption({ index: 0 });
      await page.click('button:has-text("Добавить")');
    } else {
      await page.click('button:has-text("Добавить")');
      await expect(page.locator('.campaign-recipients__table')).toBeVisible();

      await orgSelect.selectOption({ index: 1 });
      await page.click('button:has-text("Добавить")');
    }

    await expect(page.locator('h1', { hasText: 'Замена адресата' })).toBeVisible();
    await page.click('button:has-text("Заменить")');
    await expect(page.locator('.campaign-recipients__table')).toBeVisible();
  }
});

test('удаление адресата', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Удаление Адресата');

  const id = await createCampaign(page, name);
  await page.goto(`/campaigns/${id}/recipients`);

  const orgSelect = page.locator('select[name="organization"]');
  if ((await orgSelect.locator('option').count()) > 1) {
    await orgSelect.selectOption({ index: 1 });
    await page.click('button:has-text("Добавить")');
    await expect(page.locator('.campaign-recipients__table')).toBeVisible();

    page.on('dialog', dialog => dialog.accept());
    await page.locator('.campaign-recipients__table button:has-text("Убрать")').first().click();
    await expect(page.locator('.campaign-recipients__empty')).toBeVisible();
  }
});

test('страница адресатов для архивной рассылки — форма скрыта', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Архив Адресаты');

  const id = await createCampaign(page, name);
  await page.goto(`/campaigns/${id}/edit`);
  await page.selectOption('select[name="status"]', 'archived');
  await page.click('button:has-text("Сохранить")');

  await page.goto(`/campaigns/${id}/recipients`);
  await expect(page.locator('h1', { hasText: 'Адресаты рассылки' })).toBeVisible();
  await expect(page.locator('select[name="organization"]')).toBeHidden();
  await expect(page.locator('button:has-text("Выбрать все организации")')).toBeHidden();
});

test('адресаты рассылки: поисковый выпадающий список организаций', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Адресат Комбобокс');

  const id = await createCampaign(page, name);
  await page.goto(`/campaigns/${id}/recipients`);

  const form = page.locator('.campaign-recipients__add');
  const toggle = form.locator('.org-combobox__toggle');
  await expect(toggle).toBeVisible();
  await toggle.click();

  const search = form.locator('.org-combobox__search');
  await expect(search).toBeVisible();
  await search.fill('заведомо-нет-такой-организации');
  await expect(form.locator('.org-combobox__empty')).toHaveText('Ничего не найдено');

  await search.fill('Ромашка');
  await form.locator('.org-combobox__option', { hasText: 'Ромашка' }).first().click();
  await expect(page.locator('select[name="organization"]')).toHaveValue(/\d+/);
});
