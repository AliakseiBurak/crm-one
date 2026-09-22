import { expect, test, type Page } from '@playwright/test';

// Модальные окна контактов (change contacts-crud, fix W3):
// быстрое редактирование и быстрое создание на дашборде.

const loginSubmit = 'form[action="/login"] button[type="submit"]';
const editModal = '[data-contact-edit-modal] .modal__window';
const createModal = '[data-contact-create-modal] .modal__window';

async function login(page: Page, email: string, password: string) {
  const login = email.split('@')[0];
  await page.goto('/login');
  await page.fill('input[name="_login"]', login);
  await page.fill('input[name="_password"]', password);
  await page.click(loginSubmit);
  await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toBeVisible();
}

// Маркер живёт до перезагрузки страницы: если он «пережил» действие,
// перезагрузки не было (spec: модальные окна работают без перезагрузки).
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

async function openEditModal(page: Page) {
  await page.goto('/dashboard');
  const card = page.locator('[data-contact-card-wrap]').first();
  // Карточка контакта внутри раскрытой секции организации — нужно раскрыть строку организации
  const orgRow = card.locator('xpath=ancestor::tr[contains(@class,"org-details")]/preceding-sibling::tr[1]');
  if (!(await orgRow.evaluate((el) => el.classList.contains('org-table__row--expanded')))) {
    await orgRow.click();
  }
  await card.locator('[data-contact-edit]').click();
  await expect(page.locator(editModal)).toBeVisible();

  return card;
}

async function openCreateModal(page: Page) {
  await page.goto('/dashboard');
  const button = page.locator('[data-contact-create]').first();
  const orgId = (await button.getAttribute('data-org-id')) ?? '';
  // Кнопка «Добавить контакт» внутри раскрытой секции организации — нужно раскрыть строку организации
  const orgRow = button.locator('xpath=ancestor::tr[contains(@class,"org-details")]/preceding-sibling::tr[1]');
  if (!(await orgRow.evaluate((el) => el.classList.contains('org-table__row--expanded')))) {
    await orgRow.click();
  }
  await button.click();
  await expect(page.locator(createModal)).toBeVisible();

  return orgId;
}

test('кнопка Изменить открывает модальное окно с данными контакта', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  const card = await openEditModal(page);
  const form = page.locator(editModal);

  await expect(form.locator('[data-contact-field="name"]')).toHaveValue(
    (await card.getAttribute('data-contact-name')) ?? '',
  );
});

test('отмена закрывает модальное окно редактирования без перезагрузки', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await openEditModal(page);
  await markAlive(page);

  await page.locator(editModal).getByRole('button', { name: 'Отмена' }).click();
  await expect(page.locator(editModal)).toBeHidden();
  await expectStillSamePage(page);
});

test('ошибка валидации в модальном окне редактирования показывается на русском', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await openEditModal(page);
  await markAlive(page);

  await page.locator(`${editModal} [data-contact-field="name"]`).fill('');
  await page.locator(editModal).getByRole('button', { name: 'Сохранить' }).click();

  const error = page.locator(`${editModal} [data-contact-error="name"]`);
  await expect(error).toBeVisible();
  await expect(error).toHaveText('Имя обязательно для заполнения');
  await expect(page.locator(editModal)).toBeVisible();
  await expectStillSamePage(page);
});

test('кнопка Добавить контакт открывает модальное окно с предвыбором организации', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  const orgId = await openCreateModal(page);
  await expect(page.locator(`${createModal} [data-contact-field="organization"]`)).toHaveValue(orgId);
});

test('отмена закрывает модальное окно создания без перезагрузки', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await openCreateModal(page);
  await markAlive(page);

  await page.locator(createModal).getByRole('button', { name: 'Отмена' }).click();
  await expect(page.locator(createModal)).toBeHidden();
  await expectStillSamePage(page);
});

test('ошибка валидации в модальном окне создания показывается на русском', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await openCreateModal(page);
  await markAlive(page);

  await page.locator(`${createModal} [data-contact-field="name"]`).fill('');
  await page.locator(createModal).getByRole('button', { name: 'Создать' }).click();

  const error = page.locator(`${createModal} [data-contact-error="name"]`);
  await expect(error).toBeVisible();
  await expect(error).toHaveText('Имя обязательно для заполнения');
  await expect(page.locator(createModal)).toBeVisible();
  await expectStillSamePage(page);
});
