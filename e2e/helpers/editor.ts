import { expect, type Page } from '@playwright/test';

export async function setCampaignBody(page: Page, html: string) {
  const toggle = page.locator('[data-editor-toggle-source]');
  await expect(toggle).toBeVisible();
  if ((await toggle.getAttribute('aria-pressed')) !== 'true') {
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
  }
  const source = page.locator('textarea[name="body"]');
  await expect(source).toBeVisible();
  await source.fill(html);
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-pressed', 'false');
  await expect(page.locator('[data-editor-surface]')).toBeVisible();
  await expect(source).toBeHidden();
}
