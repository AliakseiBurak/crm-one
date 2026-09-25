import { expect, test, type Page } from '@playwright/test';
import { login } from '../helpers/auth';
import { campaignIdFromUrl, deleteCampaign } from '../helpers/campaign';
import { setCampaignBody } from '../helpers/editor';
import { uniqueName } from '../helpers/test-data';

async function createCampaign(page: Page, name: string, body: string): Promise<number> {
  await page.goto('/campaigns/new');
  await page.fill('input[name="name"]', name);
  await page.fill('input[name="subject"]', `Тема ${name}`);
  await setCampaignBody(page, body);
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/highlight=(\d+)/);
  return campaignIdFromUrl(page);
}

test('вставка изображения по внешнему URL и его отображение на карточке', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Изображение');

  await page.goto('/campaigns/new');
  await page.fill('input[name="name"]', name);
  await page.fill('input[name="subject"]', 'Письмо с картинкой');

  await page.locator('[data-editor-insert-image]').click();
  await page.locator('[data-editor-image-url]').fill('https://example.com/card-photo.png');
  await page.locator('[data-editor-image-alt]').fill('Фото с карточки');
  await page.locator('[data-editor-image-insert]').click();
  await expect(page.locator('[data-editor-surface] img')).toBeVisible();

  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/highlight=(\d+)/);
  const match = page.url().match(/highlight=(\d+)/);
  const id = match ? parseInt(match[1], 10) : 0;

  await page.goto(`/campaigns/${id}`);
  const cardImage = page.locator('.campaign-card__body-html img');
  await expect(cardImage).toBeVisible();
  await expect(cardImage).toHaveAttribute('src', 'https://example.com/card-photo.png');
  await expect(cardImage).toHaveAttribute('alt', 'Фото с карточки');

  await deleteCampaign(page, id);
});

test('модалка предпросмотра открывается на карточке и закрывается по Escape', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Предпросмотр-модалка');
  const id = await createCampaign(page, name, '<p>{{greeting}}! Текст для модалки предпросмотра</p>');
  await page.goto(`/campaigns/${id}`);

  await page.locator('[data-campaign-preview-open]').click();
  const modal = page.locator('[data-campaign-preview-modal] .modal');
  await expect(modal).toBeVisible();
  const frame = page.frameLocator('[data-campaign-preview-frame]');
  await expect(frame.locator('body')).toContainText('Текст для модалки предпросмотра');
  await expect(frame.locator('body')).toContainText('Иван Петров');

  await page.keyboard.press('Escape');
  await expect(modal).toBeHidden();

  await deleteCampaign(page, id);
});

test('страница предпросмотра отдаёт email-документ с демо-токенами', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Предпросмотр-страница');
  const id = await createCampaign(page, name, '<p>{{greeting}}! Текст страницы предпросмотра</p>');

  await page.goto(`/campaigns/${id}/preview`);
  await expect(page.locator('body')).toContainText('Текст страницы предпросмотра');
  await expect(page.locator('body')).toContainText('Иван Петров');
  await expect(page.locator('table[width="600"]')).toBeVisible();
  await expect(page.locator('body')).toContainText('Центр Обучающих Технологий');
  await expect(
    page.locator('img[src="https://trainingcenter.by/wp-content/themes/training-center-by/img/icons/logo.svg"]'),
  ).toBeVisible();

  await deleteCampaign(page, id);
});
