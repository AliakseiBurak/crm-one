import { expect, type Page } from '@playwright/test';

export async function createGroup(page: Page, name: string): Promise<number> {
  await page.goto('/groups/new');
  await page.fill('input[name="name"]', name);
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/\/groups$/);

  const href = await page
    .locator('[data-group-row]', { hasText: name })
    .first()
    .locator('a:has-text("Редактировать")')
    .getAttribute('href');
  const match = href?.match(/\/groups\/(\d+)\/edit/);

  if (!match) {
    throw new Error(`Не найдена группа «${name}»`);
  }

  return Number.parseInt(match[1] as string, 10);
}

export async function deleteGroup(page: Page, id: number): Promise<void> {
  await page.goto(`/groups/${id}/delete`);
  await page.getByRole('button', { name: 'Удалить' }).click();
  await expect(page).toHaveURL(/\/groups$/);
}
