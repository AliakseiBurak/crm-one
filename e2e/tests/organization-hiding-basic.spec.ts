import { expect, test, type Page } from '@playwright/test';
import { login, logout } from '../helpers/auth';

const ADMIN = 'admin@b2b-crm.loc';
const ADMIN_PASSWORD = 'admin123';
const MANAGER1 = 'manager@b2b-crm.loc';
const MANAGER2 = 'manager2@b2b-crm.loc';
const MANAGER_PASSWORD = 'manager123';

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

async function unhideFromManager1(page: Page, orgText: string) {
  await gotoHides(page);
  const row = hideRow(page, orgText).filter({ hasText: MANAGER1 }).first();
  await row.getByRole('button', { name: 'Показать', exact: true }).click();
  await expect(page).toHaveURL(/\/admin\/hides$/);
  await expect(hideRow(page, orgText).filter({ hasText: MANAGER1 })).toHaveCount(0);
}

async function hideFromAllManagers(page: Page, orgText: string) {
  await gotoHides(page);
  await selectOrganization(page, orgText);
  await page.selectOption('select[name="managers[]"]', '');
  await page.click('button:has-text("Добавить")');
  await expect(page).toHaveURL(/\/admin\/hides$/);
  await expect(hideRow(page, orgText).first()).toBeVisible();
}

async function dashboardOrgNames(page: Page): Promise<string[]> {
  return page.locator('.org-table__name').allTextContents();
}

test('админ скрывает организацию от менеджера — она исчезает только у него', async ({ page }) => {
  await login(page, ADMIN, ADMIN_PASSWORD);
  await cleanupHide(page, 'Парус');
  await hideFromManager1(page, 'Парус');

  const row = hideRow(page, 'Парус').first();
  await expect(row).toContainText(MANAGER1);
  await expect(row).toContainText(/\d{2}\.\d{2}\.\d{4}/);

  await logout(page);
  await login(page, MANAGER1, MANAGER_PASSWORD);
  await page.goto('/dashboard');
  let names = await dashboardOrgNames(page);
  expect(names.some((n) => n.includes('Парус'))).toBe(false);
  expect(names.some((n) => n.includes('Вектор'))).toBe(true);

  await logout(page);
  await login(page, MANAGER2, MANAGER_PASSWORD);
  await page.goto('/dashboard');
  names = await dashboardOrgNames(page);
  expect(names.some((n) => n.includes('Парус'))).toBe(true);

  await logout(page);
  await login(page, ADMIN, ADMIN_PASSWORD);
  await unhideFromManager1(page, 'Парус');
  await expect(hideRow(page, 'Парус').filter({ hasText: MANAGER1 })).toHaveCount(0);

  await logout(page);
  await login(page, MANAGER1, MANAGER_PASSWORD);
  await page.goto('/dashboard');
  await expect(page.locator('.org-table__row', { hasText: 'Парус' })).toBeVisible();
  names = await dashboardOrgNames(page);
  expect(names.some((n) => n.includes('Парус'))).toBe(true);
});

test('повторное скрытие той же пары отклоняется конфликтом', async ({ page }) => {
  await login(page, ADMIN, ADMIN_PASSWORD);
  await cleanupHide(page, 'Горизонт');
  await hideFromManager1(page, 'Горизонт');

  await gotoHides(page);
  await selectOrganization(page, 'Горизонт');
  await selectManager(page, MANAGER1);
  await page.click('button:has-text("Добавить")');
  await expect(page).toHaveURL(/\/admin\/hides$/);
  await expect(
    page.locator('.alert--error, .flash--error', { hasText: 'Организация уже скрыта от' }),
  ).toBeVisible();

  await gotoHides(page);
  await expect(hideRow(page, 'Горизонт')).toHaveCount(1);
  await unhideFromManager1(page, 'Горизонт');
  await expect(hideRow(page, 'Горизонт')).toHaveCount(0);
});

test('скрытие от всех менеджеров через пустую опцию дропдауна', async ({ page }) => {
  test.setTimeout(45_000);
  await login(page, ADMIN, ADMIN_PASSWORD);
  await cleanupHide(page, 'Ромашка');
  await hideFromAllManagers(page, 'Ромашка');

  await logout(page);
  await login(page, MANAGER1, MANAGER_PASSWORD);
  await page.goto('/dashboard');
  expect(await dashboardOrgNames(page)).toEqual(
    expect.not.arrayContaining([expect.stringContaining('Ромашка')]),
  );

  await logout(page);
  await login(page, MANAGER2, MANAGER_PASSWORD);
  await page.goto('/dashboard');
  expect(await dashboardOrgNames(page)).toEqual(
    expect.not.arrayContaining([expect.stringContaining('Ромашка')]),
  );

  await logout(page);
  await login(page, ADMIN, ADMIN_PASSWORD);
  await cleanupHide(page, 'Ромашка');
});
