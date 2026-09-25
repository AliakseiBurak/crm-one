import { expect, test, type Page } from '@playwright/test';
import { login } from '../helpers/auth';
import { campaignIdFromUrl, deleteCampaign, findCampaignId } from '../helpers/campaign';
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

  return campaignIdFromUrl(page);
}

test('карточка рассылки отображает поля', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Карточка');
  const subject = 'Тема тест ' + Date.now();
  let id = 0;

  try {
    id = await createCampaign(page, name, { subject });
    await page.goto(`/campaigns/${id}`);

    await expect(page.locator('h1', { hasText: name })).toBeVisible();
    await expect(page.locator('dt', { hasText: 'Тема письма' })).toBeVisible();
    await expect(page.locator('dd', { hasText: subject })).toBeVisible();
  } finally {
    if (id > 0) {
      await deleteCampaign(page, id);
    }
  }
});

test('редактирование рассылки', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Редактируемая');
  const newSubject = 'Обновлённая тема ' + Date.now();
  let id = 0;

  try {
    id = await createCampaign(page, name, { subject: 'Оригинал' });
    await page.goto(`/campaigns/${id}/edit`);

    await page.fill('input[name="subject"]', newSubject);
    await page.click('button:has-text("Сохранить")');

    await page.goto(`/campaigns/${id}`);
    await expect(page.locator('dd', { hasText: newSubject })).toBeVisible();
  } finally {
    if (id > 0) {
      await deleteCampaign(page, id);
    }
  }
});

test('запуск рассылки со статусом ready', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Запуск');
  let id = 0;

  try {
    id = await createCampaign(page, name, { status: 'ready' });
    await page.goto(`/campaigns/${id}`);
    await page.click('button:has-text("Запустить")');

    await expect(page.locator('dd', { hasText: 'Запущена' })).toBeVisible();
  } finally {
    if (id > 0) {
      await deleteCampaign(page, id);
    }
  }
});

test('остановка запущенной рассылки', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Остановка');
  let id = 0;

  try {
    id = await createCampaign(page, name, { status: 'ready' });
    await page.goto(`/campaigns/${id}`);
    await page.click('button:has-text("Запустить")');
    await expect(page.locator('dd', { hasText: 'Запущена' })).toBeVisible();

    await page.click('button:has-text("Остановить")');
    await expect(page.locator('dd', { hasText: 'Готова' })).toBeVisible();
  } finally {
    if (id > 0) {
      await deleteCampaign(page, id);
    }
  }
});

test('кнопка Запустить не отображается для черновика', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Черновик');
  let id = 0;

  try {
    id = await createCampaign(page, name);
    await page.goto(`/campaigns/${id}`);
    await expect(page.locator('button:has-text("Запустить")')).toBeHidden();
  } finally {
    if (id > 0) {
      await deleteCampaign(page, id);
    }
  }
});

test('клонирование рассылки', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Оригинал Клон');
  let id = 0;
  let cloneId = 0;

  try {
    id = await createCampaign(page, name, { status: 'ready' });
    await page.goto(`/campaigns/${id}`);
    await page.click('button:has-text("Клонировать")');

    await page.goto('/campaigns');
    await expect(page.locator('tr[data-status]', { hasText: `${name} (копия)` })).toBeVisible();
    cloneId = await findCampaignId(page, `${name} (копия)`);
  } finally {
    if (cloneId > 0) {
      await deleteCampaign(page, cloneId);
    }
    if (id > 0) {
      await deleteCampaign(page, id);
    }
  }
});

test('клонирование недоступно для черновика', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Черновик НеКлон');
  let id = 0;

  try {
    id = await createCampaign(page, name);
    await page.goto(`/campaigns/${id}`);
    await expect(page.locator('button:has-text("Клонировать")')).toBeHidden();
  } finally {
    if (id > 0) {
      await deleteCampaign(page, id);
    }
  }
});

test('загрузка вложения при редактировании', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('С Вложениями');
  let id = 0;

  try {
    id = await createCampaign(page, name);
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
  } finally {
    if (id > 0) {
      await deleteCampaign(page, id);
    }
  }
});

test('удаление рассылки с подтверждением', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Удаляемая');
  let id = 0;

  try {
    id = await createCampaign(page, name);
    await page.goto(`/campaigns/${id}/delete`);

    await expect(page.locator('h1', { hasText: 'Удаление рассылки' })).toBeVisible();
    await page.click('button:has-text("Удалить")');

    await page.goto('/campaigns');
    await expect(page.locator('tr[data-status]', { hasText: name })).toBeHidden();
    id = 0;
  } finally {
    if (id > 0) {
      await deleteCampaign(page, id);
    }
  }
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
  let id = 0;

  try {
    id = await createCampaign(page, name);
    await page.goto(`/campaigns/${id}`);
    await page.click('a:has-text("Назад к списку")');

    await expect(page).toHaveURL(/\/campaigns$/);
  } finally {
    if (id > 0) {
      await deleteCampaign(page, id);
    }
  }
});
