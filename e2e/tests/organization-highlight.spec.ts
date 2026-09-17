import { expect, test, type Page } from '@playwright/test';

// Подсветка организации после редиректа create/update
// (change fix-org-highlight-e2e): класс .org-table__row--highlight,
// авто-раскрытие <details> с контактами на сервере (атрибут open) и
// одноразовое исчезновение подсветки через 4 секунды.

const loginSubmit = 'form[action="/login"] button[type="submit"]';

async function login(page: Page, email: string, password: string) {
  await page.goto('/login');
  await page.fill('input[name="_username"]', email);
  await page.fill('input[name="_password"]', password);
  await page.click(loginSubmit);
  await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toBeVisible();
}

function highlightedRow(page: Page) {
  return page.locator('tr.org-table__row--highlight');
}

// Блок <details> организации живёт в соседней строке таблицы; id строки
// стабилен и после fade-out (в отличие от класса подсветки).
function detailsBox(page: Page, orgId: string) {
  return page
    .locator(`tr[data-org-id="${orgId}"]`)
    .locator('xpath=./following-sibling::tr[1]')
    .locator('.org-details__box');
}

test('после создания организация подсвечена, contacts-блок раскрыт, подсветка исчезает', async ({ page }) => {
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

  // Авто-раскрытие без клика: серверный open + видимое содержимое блока.
  const details = detailsBox(page, orgId);
  await expect(details).toHaveAttribute('open', '');
  await expect(details.locator('.org-contacts__add')).toBeVisible();

  // Одноразовость: fade-out убирает класс примерно через 4 секунды.
  await expect(highlightedRow(page)).toHaveCount(0, { timeout: 8_000 });

  // Уборка: тест создаёт реальные данные в общей БД фикстур — удаляем организацию.
  await page.goto(`/organizations/${orgId}/delete`);
  await page.click('button:has-text("Удалить")');
  await expect(page).toHaveURL(/\/dashboard/);
});

test('после редактирования организация подсвечена и раскрыта с контактами', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/dashboard');
  const row = page.locator('[data-organization-row]', { hasText: 'Ромашка' }).first();
  const orgId = (await row.getAttribute('data-org-id')) ?? '';
  expect(orgId).not.toBe('');
  const previousIndustry = (await row.locator('[data-organization-cell="industry"]').textContent()) ?? '';

  // Страничная форма редактирования (не модальное окно — нужен редирект).
  await page.goto(`/organizations/${orgId}/edit`);
  await page.fill('input[name="industry"]', 'E2E подсветка');
  await page.locator('form').getByRole('button', { name: 'Сохранить' }).click();

  await expect(page).toHaveURL(/\/dashboard\?highlight=\d+$/);

  await expect(highlightedRow(page)).toHaveCount(1);

  // Авто-раскрытие: карточки контактов видны без клика по summary.
  const details = detailsBox(page, orgId);
  await expect(details).toHaveAttribute('open', '');
  await expect(details.locator('.org-contacts .card').first()).toBeVisible();

  await expect(highlightedRow(page)).toHaveCount(0, { timeout: 8_000 });

  // Возврат фикстуры: общая БД используется другими тестами.
  await page.goto(`/organizations/${orgId}/edit`);
  await page.fill('input[name="industry"]', previousIndustry);
  await page.locator('form').getByRole('button', { name: 'Сохранить' }).click();
  await expect(page).toHaveURL(/\/dashboard\?highlight=\d+$/);
});
