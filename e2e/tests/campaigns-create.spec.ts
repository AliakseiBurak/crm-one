import { expect, test } from '@playwright/test';
import { login } from '../helpers/auth';
import { uniqueName } from '../helpers/test-data';

test('создание рассылки со всеми полями', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Создание');

  await page.goto('/campaigns/new');
  await page.fill('input[name="name"]', name);
  await page.fill('input[name="subject"]', 'Приглашаем на курсы 2026');
  await page.fill('input[name="preview_text"]', 'Превью письма');
  await page.fill('textarea[name="body"]', '{{greeting}}! Приглашаем вас на курсы.');
  await page.selectOption('select[name="status"]', 'ready');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();

  await expect(page).toHaveURL(/campaigns/);
  await page.goto('/campaigns');
  await expect(page.locator('tr[data-status]', { hasText: name })).toBeVisible();
});

test('создание рассылки с валидацией обязательных полей', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/campaigns/new');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();

  await expect(page.locator('.field__error', { hasText: 'Название обязательно' })).toBeVisible();
  await expect(page.locator('.field__error', { hasText: 'Тема письма обязательна' })).toBeVisible();
  await expect(page.locator('.field__error', { hasText: 'Текст письма обязателен' })).toBeVisible();
});

test('статус по умолчанию — черновик', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/campaigns/new');
  await expect(page.locator('select[name="status"]')).toHaveValue('draft');
});

test('статус failed недоступен в форме', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/campaigns/new');
  const options = page.locator('select[name="status"] option');
  const values = await options.allTextContents();
  expect(values.some(t => t.includes('Ошибка'))).toBe(false);
});
