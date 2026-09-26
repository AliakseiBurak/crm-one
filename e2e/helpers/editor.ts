import { expect, type Page } from '@playwright/test';

async function openSourceMode(page: Page) {
  const toggle = page.locator('[data-editor-toggle-source]');
  await expect(toggle).toBeVisible();
  if ((await toggle.getAttribute('aria-pressed')) !== 'true') {
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
  }
  await expect(page.locator('textarea[name="body"]')).toBeVisible();
}

async function closeSourceMode(page: Page) {
  const toggle = page.locator('[data-editor-toggle-source]');
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-pressed', 'false');
  await expect(page.locator('[data-editor-surface]')).toBeVisible();
  await expect(page.locator('textarea[name="body"]')).toBeHidden();
}

export async function setCampaignBody(page: Page, html: string) {
  await openSourceMode(page);
  await page.locator('textarea[name="body"]').fill(html);
  await closeSourceMode(page);
}

/**
 * Очищает тело письма (change email-base-template): форма создания
 * предзаполнена базовым шаблоном, поэтому для проверки обязательности поля
 * его нужно снять явно.
 */
export async function clearCampaignBody(page: Page) {
  await openSourceMode(page);
  await page.locator('textarea[name="body"]').fill('');
  await closeSourceMode(page);
}

/** Текст тела письма в исходном режиме — то, что реально уйдёт в письмо. */
export async function readCampaignBody(page: Page): Promise<string> {
  await openSourceMode(page);

  return page.locator('textarea[name="body"]').inputValue();
}
