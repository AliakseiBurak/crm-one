import { expect, test, type Page } from '@playwright/test';
import { login, logout } from '../helpers/auth';

const ADMIN = 'admin@b2b-crm.loc';
const ADMIN_PASSWORD = 'admin123';
const MANAGER1 = 'manager@b2b-crm.loc';
const MANAGER_PASSWORD = 'manager123';

async function orgEditUrl(page: Page, nameText: string): Promise<string> {
  await page.goto('/dashboard');
  const row = page.locator('.org-table__row', { hasText: nameText }).first();
  const link = row.locator('.org-table__name-link');
  return (await link.getAttribute('href')) ?? '';
}

async function gotoHides(page: Page) {
  await page.goto('/admin/hides');
  await expect(page.locator('h1', { hasText: 'Скрытые организации' })).toBeVisible();
}

function hideRow(page: Page, orgText: string) {
  return page.locator('tr[data-hide-row]', { hasText: orgText });
}

async function selectOrganization(page: Page, nameText: string) {
  const option = page
    .locator('select[name="organization"] option', { hasText: nameText })
    .first();
  const value = await option.getAttribute('value');
  await page.selectOption('select[name="organization"]', value as string);
}

async function selectManager(page: Page, email: string) {
  const option = page
    .locator('select[name="managers[]"] option', { hasText: email })
    .first();
  const value = await option.getAttribute('value');
  await page.selectOption('select[name="managers[]"]', value as string);
}

async function cleanupHide(page: Page, orgText: string) {
  await gotoHides(page);
  const rows = hideRow(page, orgText);
  const count = await rows.count();
  if (count === 0) return;
  for (let i = 0; i < count; i++) {
    await rows.first().getByRole('button', { name: 'Показать', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/hides$/);
  }
  await expect(hideRow(page, orgText)).toHaveCount(0);
}

async function hideFromManager1(page: Page, orgText: string) {
  await gotoHides(page);
  await selectOrganization(page, orgText);
  await selectManager(page, MANAGER1);
  await page.click('button:has-text("Добавить")');
  await expect(page).toHaveURL(/\/admin\/hides$/);
  await expect(hideRow(page, orgText).first()).toBeVisible();
}

async function dashboardOrgNames(page: Page): Promise<string[]> {
  return page.locator('.org-table__name').allTextContents();
}

test('форма редактирования: скрытие от менеджера и возврат видимости', async ({ page }) => {
  await login(page, ADMIN, ADMIN_PASSWORD);
  await cleanupHide(page, 'Закат');
  await hideFromManager1(page, 'Закат');

  const zakatUrl = await orgEditUrl(page, 'Закат');
  await page.goto(zakatUrl);
  await expect(page.locator('.organization-form__hides')).toBeVisible();
  await expect(page.locator('.organization-hides__item', { hasText: MANAGER1 })).toBeVisible();

  await logout(page);
  await login(page, MANAGER1, MANAGER_PASSWORD);
  await page.goto('/dashboard');
  expect(await dashboardOrgNames(page)).toEqual(
    expect.not.arrayContaining([expect.stringContaining('Закат')]),
  );

  await logout(page);
  await login(page, ADMIN, ADMIN_PASSWORD);
  await page.goto(zakatUrl);
  await page.locator('.organization-hides__item', { hasText: MANAGER1 })
    .getByRole('button', { name: 'Показать', exact: true })
    .click();
  await expect(page).toHaveURL(/\/organizations\/\d+\/edit/);
  await expect(page.locator('.organization-hides__empty')).toBeVisible();

  await logout(page);
  await login(page, MANAGER1, MANAGER_PASSWORD);
  await page.goto('/dashboard');
  expect(await dashboardOrgNames(page)).toEqual(
    expect.arrayContaining([expect.stringContaining('Закат')]),
  );
});

test('форма редактирования: менеджер не видит секцию скрытий', async ({ page }) => {
  await login(page, MANAGER1, MANAGER_PASSWORD);
  const editUrl = await orgEditUrl(page, 'Вектор');
  await page.goto(editUrl);
  await expect(page.locator('.organization-form__hides')).toHaveCount(0);
});

test('скрытие действует внутри групп менеджера', async ({ page }) => {
  await login(page, ADMIN, ADMIN_PASSWORD);
  await cleanupHide(page, 'Вектор');
  await hideFromManager1(page, 'Вектор');

  await logout(page);
  await login(page, MANAGER1, MANAGER_PASSWORD);
  await page.goto('/groups');

  const groupRow = page.locator('[data-group-row]', { hasText: 'Клиенты Ромашка' }).first();
  await groupRow.locator('a:has-text("Состав")').click();
  await expect(page.locator('h1', { hasText: 'Состав группы' })).toBeVisible();

  const form = page.locator('.group-members-form');
  // Якорь — организация, которую не скрывает ни один другой тест
  // (basic:132 скрывает «Ромашку» от всех менеджеров на время своего выполнения).
  await expect(form).toContainText('Сидоров');
  await expect(form).not.toContainText('Вектор');

  await logout(page);
  await login(page, ADMIN, ADMIN_PASSWORD);
  await gotoHides(page);
  const row = hideRow(page, 'Вектор').filter({ hasText: MANAGER1 }).first();
  await row.getByRole('button', { name: 'Показать', exact: true }).click();
  await expect(page).toHaveURL(/\/admin\/hides$/);
});
