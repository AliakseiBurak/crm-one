import { expect, test, type Page } from '@playwright/test';
import { login } from '../helpers/auth';
import { setCampaignBody } from '../helpers/editor';
import { uniqueName } from '../helpers/test-data';

async function createCampaign(page: Page, name: string, opts?: { status?: string; subject?: string; body?: string }): Promise<number> {
  await page.goto('/campaigns/new');
  await page.fill('input[name="name"]', name);
  await page.fill('input[name="subject"]', opts?.subject ?? 'Тема');
  await setCampaignBody(page, opts?.body ?? 'Текст письма');
  if (opts?.status) {
    await page.selectOption('select[name="status"]', opts.status);
  }
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/highlight=(\d+)/);
  const match = page.url().match(/highlight=(\d+)/);
  return match ? parseInt(match[1], 10) : 0;
}

test('карточка рассылки отображает поля', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Карточка');
  const subject = 'Тема тест ' + Date.now();

  const id = await createCampaign(page, name, { subject });
  await page.goto(`/campaigns/${id}`);

  await expect(page.locator('h1', { hasText: name })).toBeVisible();
  await expect(page.locator('dt', { hasText: 'Тема письма' })).toBeVisible();
  await expect(page.locator('dd', { hasText: subject })).toBeVisible();
});

test('редактирование рассылки', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Редактируемая');
  const newSubject = 'Обновлённая тема ' + Date.now();

  const id = await createCampaign(page, name, { subject: 'Оригинал' });
  await page.goto(`/campaigns/${id}/edit`);

  await page.fill('input[name="subject"]', newSubject);
  await page.click('button:has-text("Сохранить")');

  await page.goto(`/campaigns/${id}`);
  await expect(page.locator('dd', { hasText: newSubject })).toBeVisible();
});

test('запуск рассылки со статусом ready', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Запуск');

  const id = await createCampaign(page, name, { status: 'ready' });
  await page.goto(`/campaigns/${id}`);
  await page.click('button:has-text("Запустить")');

  await expect(page.locator('dd', { hasText: 'Запущена' })).toBeVisible();
});

test('остановка запущенной рассылки', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Остановка');

  const id = await createCampaign(page, name, { status: 'ready' });
  await page.goto(`/campaigns/${id}`);
  await page.click('button:has-text("Запустить")');
  await expect(page.locator('dd', { hasText: 'Запущена' })).toBeVisible();

  await page.click('button:has-text("Остановить")');
  await expect(page.locator('dd', { hasText: 'Готова' })).toBeVisible();
});

test('кнопка Запустить не отображается для черновика', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Черновик');

  const id = await createCampaign(page, name);
  await page.goto(`/campaigns/${id}`);
  await expect(page.locator('button:has-text("Запустить")')).toBeHidden();
});

test('сброс failed-рассылки в ready', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/campaigns');
  const failedRow = page.locator('tr[data-status="failed"]').first();
  if ((await failedRow.count()) > 0) {
    const id = (await failedRow.getAttribute('id'))?.replace('campaign-', '') ?? '';

    await page.goto(`/campaigns/${id}`);
    await page.click('button:has-text("Сбросить")');

    await expect(page).toHaveURL(new RegExp(`/campaigns/${id}$`));
    await expect(page.locator('dd', { hasText: 'Готова' })).toBeVisible();
  }
});

test('клонирование рассылки', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Оригинал Клон');

  const id = await createCampaign(page, name, { status: 'ready' });
  await page.goto(`/campaigns/${id}`);
  await page.click('button:has-text("Клонировать")');

  await page.goto('/campaigns');
  await expect(page.locator('tr[data-status]', { hasText: `${name} (копия)` })).toBeVisible();
});

test('клонирование недоступно для черновика', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Черновик НеКлон');

  const id = await createCampaign(page, name);
  await page.goto(`/campaigns/${id}`);
  await expect(page.locator('button:has-text("Клонировать")')).toBeHidden();
});

test('загрузка вложения при редактировании', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('С Вложениями');

  const id = await createCampaign(page, name);
  await page.goto(`/campaigns/${id}/edit`);

  const fileInput = page.locator('input[type="file"][name="attachments[]"]');
  await fileInput.setInputFiles({
    name: 'test-file.pdf',
    mimeType: 'application/pdf',
    buffer: Buffer.from('test content'),
  });

  await page.click('button:has-text("Сохранить")');

  await page.goto(`/campaigns/${id}`);
  await expect(page.locator('.campaign-attachments__name', { hasText: 'test-file.pdf' })).toBeVisible();
});

test('удаление рассылки с подтверждением', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Удаляемая');

  const id = await createCampaign(page, name);
  await page.goto(`/campaigns/${id}/delete`);

  await expect(page.locator('h1', { hasText: 'Удаление рассылки' })).toBeVisible();
  await page.click('button:has-text("Удалить")');

  await page.goto('/campaigns');
  await expect(page.locator('tr[data-status]', { hasText: name })).toBeHidden();
});

test('редактирование failed-рассылки — статус failed доступен', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/campaigns');
  const failedRow = page.locator('tr[data-status="failed"]').first();
  if ((await failedRow.count()) > 0) {
    const href = await failedRow.locator('a').first().getAttribute('href');
    const id = href?.match(/\/campaigns\/(\d+)/)?.[1];
    if (id) {
      await page.goto(`/campaigns/${id}/edit`);
      const options = page.locator('select[name="status"] option');
      const values = await options.allTextContents();
      expect(values.some(t => t.includes('Ошибка'))).toBe(true);
    }
  }
});

test('кнопка «Назад к списку» возвращает в список', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Навигация');

  const id = await createCampaign(page, name);
  await page.goto(`/campaigns/${id}`);
  await page.click('a:has-text("Назад к списку")');

  await expect(page).toHaveURL(/\/campaigns$/);
});
