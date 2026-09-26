import { expect, test } from '@playwright/test';
import { login } from '../helpers/auth';
import { campaignIdFromUrl, deleteCampaign } from '../helpers/campaign';
import { clearCampaignBody, readCampaignBody, setCampaignBody } from '../helpers/editor';
import { uniqueName } from '../helpers/test-data';

test('создание рассылки со всеми полями', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Создание');
  let id = 0;

  try {
    await page.goto('/campaigns/new');
    await page.fill('input[name="name"]', name);
    await page.fill('input[name="subject"]', 'Приглашаем на курсы 2026');
    await page.fill('input[name="preview_text"]', 'Превью письма');
    await setCampaignBody(page, '{{greeting}}! Приглашаем вас на курсы.');
    await page.selectOption('select[name="status"]', 'ready');
    await page.locator('form').getByRole('button', { name: 'Создать' }).click();

    await expect(page).toHaveURL(/campaigns/);
    id = campaignIdFromUrl(page);
    await page.goto('/campaigns');
    await expect(page.locator('tr[data-status]', { hasText: name })).toBeVisible();
  } finally {
    if (id > 0) {
      await deleteCampaign(page, id);
    }
  }
});

test('создание рассылки с валидацией обязательных полей', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/campaigns/new');
  // Форма предзаполнена базовым шаблоном, поэтому тело нужно очистить явно.
  await clearCampaignBody(page);
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

test('форма создания предзаполнена базовым шаблоном письма', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Базовый шаблон');
  let id = 0;

  try {
    await page.goto('/campaigns/new');

    const body = await readCampaignBody(page);
    expect(body).toContain('{{greeting}}');
    expect(body).toContain('ОДО «Центр Обучающих Технологий»');
    expect(body).toContain('+375 (29) 684-84-26');
    expect(body).toContain('href="tel:+375296848426"');
    expect(body).toContain('https://trainingcenter.by/catalog');
    expect(body).toContain('logo.svg');
    expect(body).toContain('Отписаться от рассылки');
    expect(body).toContain('{{unsubscribe_url}}');
    // Шелл и раскладка письма остаются в Twig-шаблоне, в теле их нет.
    expect(body).not.toContain('<!DOCTYPE');
    expect(body).not.toContain('<head');
    expect(body).not.toContain('width: 600px');

    await page.fill('input[name="name"]', name);
    await page.fill('input[name="subject"]', 'Письмо с базовым шаблоном');
    await page.locator('form').getByRole('button', { name: 'Создать' }).click();

    await expect(page).toHaveURL(/campaigns/);
    id = campaignIdFromUrl(page);

    await page.goto(`/campaigns/${id}/preview`);
    const frame = page.locator('body');
    await expect(frame).toContainText('Центр Обучающих Технологий');
    await expect(frame).toContainText('+375 (29) 684-84-26');
    await expect(
      page.locator('img[src="https://trainingcenter.by/wp-content/themes/training-center-by/img/icons/logo.svg"]'),
    ).toBeVisible();
    await expect(page.locator('a[href*="/unsubscribe/"]')).toBeVisible();
  } finally {
    if (id > 0) {
      await deleteCampaign(page, id);
    }
  }
});

test('подпись и телефоны меняются, ссылка отписки удаляется', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Правка футера');
  let id = 0;

  try {
    await page.goto('/campaigns/new');
    const body = await readCampaignBody(page);
    const edited = body
      .replace('ОДО «Центр Обучающих Технологий»', 'ООО «Моя компания»')
      .replace('+375 (29) 684-84-26', '+375 (44) 111-22-33')
      // Порядок атрибутов после round-trip редактора меняется на
      // target/rel/href/style, поэтому href ищем в любой позиции.
      .replace(/<a\b[^>]*href="\{\{unsubscribe_url\}\}"[^>]*>Отписаться от рассылки<\/a>/, '');

    expect(edited).not.toBe(body);
    expect(edited).not.toContain('Отписаться от рассылки');
    expect(edited).not.toContain('{{unsubscribe_url}}');

    await setCampaignBody(page, edited);
    await page.fill('input[name="name"]', name);
    await page.fill('input[name="subject"]', 'Письмо с изменённым футером');
    await page.locator('form').getByRole('button', { name: 'Создать' }).click();

    await expect(page).toHaveURL(/campaigns/);
    id = campaignIdFromUrl(page);

    await page.goto(`/campaigns/${id}/preview`);
    await expect(page.locator('body')).toContainText('ООО «Моя компания»');
    await expect(page.locator('body')).toContainText('+375 (44) 111-22-33');
    await expect(page.locator('body')).not.toContainText('Отписаться от рассылки');
  } finally {
    if (id > 0) {
      await deleteCampaign(page, id);
    }
  }
});
