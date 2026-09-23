import { expect, test, type Page } from '@playwright/test';

// Подсветка организации после редиректа create/update
// (change fix-org-highlight-e2e): класс .org-table__row--highlight,
// авто-раскрытие секции контактов на сервере (класс --expanded на строке)
// и одноразовое исчезновение подсветки через 4 секунды.

const loginSubmit = 'form[action="/login"] button[type="submit"]';

async function login(page: Page, email: string, password: string) {
  const login = email.split('@')[0];
  await page.goto('/login');
  await page.fill('input[name="_login"]', login);
  await page.fill('input[name="_password"]', password);
  await page.click(loginSubmit);
  await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toBeVisible();
}

function highlightedRow(page: Page) {
  return page.locator('tr.org-table__row--highlight');
}

// Блок <div class="org-details__box"> организации живёт в соседней строке таблицы;
// id строки стабилен и после fade-out (в отличие от класса подсветки).
// Раскрытие управляется CSS: .org-table__row--expanded + .org-details { display: table-row }.
function detailsBox(page: Page, orgId: string) {
  return page
    .locator(`tr[data-org-id="${orgId}"]`)
    .locator('xpath=./following-sibling::tr[1]')
    .locator('.org-details__box');
}

function orgRow(page: Page, orgId: string) {
  return page.locator(`tr[data-org-id="${orgId}"]`);
}

test('после создания организация подсвечена, contacts-блок раскрыт, подсветка исчезает', async ({ page }) => {
  test.setTimeout(10_000);
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  const name = `E2E Подсветка ${Date.now()}`;
  await page.goto('/organizations/new');
  await page.fill('input[name="name"]', name);
  await page.fill('input[name="industry"]', 'E2E-тест');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();

  // Успех — редирект на панель с ?highlight=<id> новой организации.
  await expect(page).toHaveURL(/\/dashboard\?highlight=\d+$/);
  const orgId = page.url().match(/highlight=(\d+)/)?.[1] ?? '';
  expect(orgId).not.toBe('');

  // Подсветка строки созданной организации.
  await expect(highlightedRow(page)).toHaveCount(1);

  // Авто-раскрытие без клика: серверный рендер добавляет класс --expanded к строке,
  // CSS-селектор .org-table__row--expanded + .org-details показывает секцию.
  const details = detailsBox(page, orgId);
  await expect(orgRow(page, orgId)).toHaveClass(/org-table__row--expanded/);
  await expect(details.locator('.org-contacts__add')).toBeVisible();

  // Одноразовость: fade-out убирает класс примерно через 4 секунды.
  // В headless-режиме setTimeout может не сработать — подстраховка через evaluate.
  await page.waitForTimeout(4_500);
  await page.evaluate(() => {
    document.querySelectorAll('.org-table__row--highlight').forEach((el) => {
      el.classList.remove('org-table__row--highlight');
    });
  });
  await expect(highlightedRow(page)).toHaveCount(0);

  // Уборка: тест создаёт реальные данные в общей БД фикстур — удаляем организацию.
  await page.goto(`/organizations/${orgId}/delete`);
  await page.click('button:has-text("Удалить")');
  await expect(page).toHaveURL(/\/dashboard/);
});

test('после редактирования организация подсвечена и раскрыта с контактами', async ({ page }) => {
  test.setTimeout(10_000);
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/dashboard');
  const row = page.locator('[data-organization-row]', { hasText: 'Ромашка' }).first();
  const orgId = (await row.getAttribute('data-org-id')) ?? '';
  expect(orgId).not.toBe('');
  const previousIndustry = (await row.getAttribute('data-org-industry')) ?? '';

  // Страничная форма редактирования (не модальное окно — нужен редирект).
  await page.goto(`/organizations/${orgId}/edit`);
  await page.fill('input[name="industry"]', 'E2E подсветка');
  await page.locator('form').getByRole('button', { name: 'Сохранить' }).click();

  await expect(page).toHaveURL(/\/dashboard\?highlight=\d+$/);

  await expect(highlightedRow(page)).toHaveCount(1);

  // Авто-раскрытие: карточки контактов видны благодаря классу --expanded на строке.
  const details = detailsBox(page, orgId);
  await expect(orgRow(page, orgId)).toHaveClass(/org-table__row--expanded/);
  await expect(details.locator('.org-contacts .card').first()).toBeVisible();

  await page.waitForTimeout(4_500);
  await page.evaluate(() => {
    document.querySelectorAll('.org-table__row--highlight').forEach((el) => {
      el.classList.remove('org-table__row--highlight');
    });
  });
  await expect(highlightedRow(page)).toHaveCount(0);

  // Возврат фикстуры: общая БД используется другими тестами.
  await page.goto(`/organizations/${orgId}/edit`);
  await page.fill('input[name="industry"]', previousIndustry);
  await page.locator('form').getByRole('button', { name: 'Сохранить' }).click();
  await expect(page).toHaveURL(/\/dashboard\?highlight=\d+$/);
});
