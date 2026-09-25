import { expect, type Page } from '@playwright/test';

export function campaignIdFromUrl(page: Page): number {
  const match = page.url().match(/highlight=(\d+)/);

  return match ? Number.parseInt(match[1] as string, 10) : 0;
}

export async function findCampaignId(page: Page, name: string): Promise<number> {
  const row = page.locator('tr[data-status]', { hasText: name }).first();
  const href = await row.locator('a[href^="/campaigns/"]').first().getAttribute('href');
  const match = href?.match(/\/campaigns\/(\d+)/);

  if (!match) {
    throw new Error(`Не найдена рассылка «${name}»`);
  }

  return Number.parseInt(match[1] as string, 10);
}

export async function deleteCampaign(page: Page, id: number): Promise<void> {
  await page.goto(`/campaigns/${id}/delete`);
  await page.getByRole('button', { name: 'Удалить' }).click();
  await expect(page).toHaveURL(/\/campaigns/);
}
