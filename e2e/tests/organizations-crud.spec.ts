import { expect, test, type Page } from '@playwright/test';

// CRUD организаций и модальное окно быстрого редактирования
// (change organizations-crud).

const loginSubmit = 'form[action="/login"] button[type="submit"]';
// На дашборде в DOM присутствуют все модальные окна (организация, контакты,
// звонок) — локатор скоупится окном редактирования организации, иначе strict
// mode падает на четырёх .modal__window.
const modalWindow = '[data-organization-edit-modal] .modal__window';

async function login(page: Page, email: string, password: string) {
  const login = email.split('@')[0];
  await page.goto('/login');
  await page.fill('input[name="_login"]', login);
  await page.fill('input[name="_password"]', password);
  await page.click(loginSubmit);
  await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toBeVisible();
}

async function openEditModal(page: Page) {
  await page.goto('/dashboard');
  const row = page.locator('[data-organization-row]', { hasText: 'Ромашка' }).first();
  await row.locator('[data-organization-edit]').click();
  await expect(page.locator(modalWindow)).toBeVisible();

  return row;
}

// Маркер живёт до перезагрузки страницы: если он «пережил» сохранение,
// перезагрузки не было (spec: таблица обновляется без перезагрузки).
async function markAlive(page: Page) {
  await page.evaluate(() => {
    (window as unknown as { __noReloadMarker?: boolean }).__noReloadMarker = true;
  });
}

async function expectStillSamePage(page: Page) {
  const marker = await page.evaluate(
    () => (window as unknown as { __noReloadMarker?: boolean }).__noReloadMarker,
  );
  expect(marker).toBe(true);
}

test('кнопка Изменить открывает модальное окно с данными организации', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  const row = await openEditModal(page);
  const form = page.locator(modalWindow);

  await expect(form.locator('[data-organization-field="name"]')).toHaveValue(
    (await row.locator('[data-organization-cell="name"]').textContent()) ?? '',
  );
  await expect(form.locator('[data-organization-field="industry"]')).toHaveValue(
    await row.getAttribute('data-org-industry'),
  );
});

test('сохранение в модальном окне обновляет таблицу без перезагрузки', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const row = await openEditModal(page);

  const previous = (await row.getAttribute('data-org-industry')) ?? '';
  const next = previous === 'Маркетинг' ? 'Маркетинг-2' : 'Маркетинг';

  await markAlive(page);
  await page.locator(`${modalWindow} [data-organization-field="industry"]`).fill(next);
  await page.locator(modalWindow).getByRole('button', { name: 'Сохранить' }).click();

  await expect(page.locator(modalWindow)).toBeHidden();
  // Row dataset updated
  await expect(row).toHaveAttribute('data-org-industry', next);
  // Details section updated (expand row to check visible text)
  await row.click();
  const orgId = await row.getAttribute('data-org-id');
  const meta = page.locator(`#org-details-${orgId} [data-organization-cell="industry"]`);
  await expect(meta).toHaveText(next);
  await expectStillSamePage(page);
});

test('отмена закрывает модальное окно без сохранения изменений', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const row = await openEditModal(page);

  const previous = (await row.getAttribute('data-org-industry')) ?? '';

  await markAlive(page);
  await page.locator(`${modalWindow} [data-organization-field="industry"]`).fill(previous + '-изменено');
  await page.locator(modalWindow).getByRole('button', { name: 'Отмена' }).click();

  await expect(page.locator(modalWindow)).toBeHidden();
  // Value unchanged
  await expect(row).toHaveAttribute('data-org-industry', previous);
  await expectStillSamePage(page);
});

test('ошибка валидации в модальном окне показывается на русском', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await openEditModal(page);

  await markAlive(page);
  await page.locator(`${modalWindow} [data-organization-field="name"]`).fill('');
  await page.locator(modalWindow).getByRole('button', { name: 'Сохранить' }).click();

  const error = page.locator(`${modalWindow} [data-organization-error="name"]`);
  await expect(error).toBeVisible();
  await expect(error).toHaveText('Название обязательно для заполнения');
  await expect(page.locator(modalWindow)).toBeVisible();
  await expectStillSamePage(page);
});

// --- Форма редактирования: информация о создателе и дате создания ---

test('форма редактирования организации показывает создателя и дату создания', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  // Открываем форму редактирования существующей организации (Ромашка из фикстур)
  const row = page.locator('.org-table__row', { hasText: 'Ромашка' });
  const editLink = row.locator('a[href*="/edit"]');
  await editLink.click();
  await expect(page.locator('h1', { hasText: 'Редактирование организации' })).toBeVisible();

  // Мета-информация о создателе и дате отображается
  const meta = page.locator('.organization-form__meta');
  await expect(meta).toBeVisible();
  await expect(meta).toContainText('Создатель:');
  await expect(meta).toContainText('Дата создания:');
});
