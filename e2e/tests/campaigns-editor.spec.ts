import { expect, test } from '@playwright/test';
import { login } from '../helpers/auth';
import { setCampaignBody } from '../helpers/editor';

const editorContent = '[data-editor-surface] .campaign-editor__content';

async function openNewCampaign(page: import('@playwright/test').Page) {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/campaigns/new');
  await expect(page.locator(editorContent)).toBeVisible();
}

test('таблица и изображение переживают переключение режимов редактора', async ({ page }) => {
  await openNewCampaign(page);

  await setCampaignBody(
    page,
    '<p>До таблицы</p>' +
      '<table><tr><td style="background-color: #eef">Ячейка</td></tr></table>' +
      '<p><img src="https://example.com/pic.png" alt="Пример" width="320"></p>',
  );

  await page.locator('[data-editor-toggle-source]').click();
  await expect(page.locator(editorContent)).toBeVisible();
  await expect(page.locator(editorContent + ' table')).toBeVisible();
  await expect(page.locator(editorContent + ' table td')).toHaveText('Ячейка');
  await expect(page.locator(editorContent + ' img')).toBeVisible();
  await expect(page.locator(editorContent + ' img')).toHaveAttribute('src', 'https://example.com/pic.png');

  await page.locator('[data-editor-toggle-source]').click();
  const html = await page.locator('textarea[name="body"]').inputValue();
  expect(html).toContain('<table');
  expect(html).toContain('background-color');
  expect(html).toContain('Ячейка');
  expect(html).toContain('src="https://example.com/pic.png"');
  expect(html).toContain('width="320"');
  expect(html).toContain('alt="Пример"');
});

test('вставка изображения по https URL через диалог', async ({ page }) => {
  await openNewCampaign(page);

  await page.locator('[data-editor-insert-image]').click();
  await expect(page.locator('[data-editor-image-form]')).toBeVisible();
  await page.locator('[data-editor-image-url]').fill('https://example.com/photo.png');
  await page.locator('[data-editor-image-alt]').fill('Фото');
  await page.locator('[data-editor-image-width]').fill('300');
  await page.locator('[data-editor-image-insert]').click();

  await expect(page.locator('[data-editor-image-form]')).toBeHidden();
  await expect(page.locator(editorContent + ' img')).toBeVisible();

  await page.locator('[data-editor-toggle-source]').click();
  const html = await page.locator('textarea[name="body"]').inputValue();
  expect(html).toContain('src="https://example.com/photo.png"');
  expect(html).toContain('width="300"');
  expect(html).toContain('alt="Фото"');
});

test('недопустимый URL изображения отклоняется', async ({ page }) => {
  await openNewCampaign(page);

  await page.locator('[data-editor-insert-image]').click();
  await page.locator('[data-editor-image-url]').fill('javascript:alert(1)');
  await page.locator('[data-editor-image-insert]').click();

  await expect(page.locator('[data-editor-image-error]')).toBeVisible();
  await expect(page.locator('[data-editor-image-error]')).toContainText('https://');
  await expect(page.locator(editorContent + ' img')).toHaveCount(0);
  await expect(page.locator('[data-editor-image-form]')).toBeVisible();

  await page.locator('[data-editor-image-cancel]').click();
  await expect(page.locator('[data-editor-image-form]')).toBeHidden();
});

test('форматирование в визуальном режиме попадает в исходный HTML', async ({ page }) => {
  await openNewCampaign(page);

  await page.locator(editorContent).click();
  await page.keyboard.type('Привет мир');
  await page.keyboard.press('Control+a');
  await page.locator('[data-editor-command="bold"]').click();
  await expect(page.locator(editorContent + ' strong')).toHaveText('Привет мир');

  await page.locator('[data-editor-toggle-source]').click();
  const html = await page.locator('textarea[name="body"]').inputValue();
  expect(html).toContain('<strong>Привет мир</strong>');
});
