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

/**
 * change email-base-template: создаёт рассылку, не трогая тело, — оно уже
 * предзаполнено базовым шаблоном с раскладкой 600px и футером.
 */
async function createCampaignWithBaseBody(page: Page, name: string): Promise<number> {
  await page.goto('/campaigns/new');
  await page.fill('input[name="name"]', name);
  await page.fill('input[name="subject"]', `Тема ${name}`);
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
  // В форме уже лежит базовый шаблон с логотипом, поэтому ищем вставленную
  // картинку по src, а не «единственную img в редакторе».
  await expect(
    page.locator('[data-editor-surface] img[src="https://example.com/card-photo.png"]'),
  ).toBeVisible();

  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/highlight=(\d+)/);
  const match = page.url().match(/highlight=(\d+)/);
  const id = match ? parseInt(match[1], 10) : 0;

  await page.goto(`/campaigns/${id}`);
  // На карточке теперь всё тело письма, включая логотип футера, — берём
  // картинку по src.
  const cardImage = page.locator('.campaign-card__body-html img[src="https://example.com/card-photo.png"]');
  await expect(cardImage).toBeVisible();
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
  // change email-base-template: раскладка 600px и футер приезжают в тело
  // рассылки, поэтому рассылка создаётся с базовым шаблоном, а не с
  // перезаписанным телом — иначе проверки смотрели бы на пустой шелл.
  const id = await createCampaignWithBaseBody(page, name);

  await page.goto(`/campaigns/${id}/preview`);
  await expect(page.locator('body')).toContainText('Уважаемый(ая) Иван Петров');
  // Раскладка 600px остаётся в шелле, а подпись и телефоны приезжают из тела.
  await expect(page.locator('table[style*="width: 600px"]')).toBeVisible();
  await expect(page.locator('body')).toContainText('Центр Обучающих Технологий');
  await expect(page.locator('a[href^="tel:"]')).toHaveCount(3);
  await expect(
    page.locator('img[src="https://trainingcenter.by/wp-content/themes/training-center-by/img/icons/logo.svg"]'),
  ).toBeVisible();

  await deleteCampaign(page, id);
});
