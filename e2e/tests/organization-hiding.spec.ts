import { expect, test, type Page } from '@playwright/test';

// Скрытие организаций (change organization-hiding):
// - реестр «Скрытые организации» со встроенной формой (два дропдауна + кнопка);
// - скрытая организация исчезает из панели менеджера, другие менеджеры её видят;
// - 403 для менеджера и видимость раздела только у администратора;
// - форма редактирования организации: список скрытий + кнопка «Скрыть»;
// - скрытие действует внутри групп менеджера (состав группы).
//
// Данные: в фикстурах «ООО "Конкурент"» уже скрыта от manager@b2b-crm.loc.
// Временные записи скрытия тесты убирают через «Показать»,
// чтобы dev-БД не менялась между прогонами.

const loginSubmit = 'form[action="/login"] button[type="submit"]';

const ADMIN = 'admin@b2b-crm.loc';
const ADMIN_PASSWORD = 'admin123';
const MANAGER1 = 'manager@b2b-crm.loc';
const MANAGER2 = 'manager2@b2b-crm.loc';
const MANAGER_PASSWORD = 'manager123';

async function login(page: Page, email: string, password: string) {
  await page.goto('/login');
  await page.fill('input[name="_username"]', email);
  await page.fill('input[name="_password"]', password);
  await page.click(loginSubmit);
  await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toBeVisible();
}

async function logout(page: Page) {
  await page.goto('/logout');
}

async function gotoHides(page: Page) {
  await page.goto('/admin/hides');
  await expect(page.locator('h1', { hasText: 'Скрытые организации' })).toBeVisible();
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

function hideRow(page: Page, orgText: string) {
  return page.locator('tr[data-hide-row]', { hasText: orgText });
}

async function dashboardOrgNames(page: Page): Promise<string[]> {
  return page.locator('.org-table__name').allTextContents();
}

// Убрать все записи скрытия организации (кнопки «Показать» по одной строке).
// Нужно перед каждым созданием записи, чтобы прошлые прогоны не оставляли хвосты.
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

// Скрыть организацию от manager1 через встроенную форму на странице реестра.
async function hideFromManager1(page: Page, orgText: string) {
  await gotoHides(page);
  await selectOrganization(page, orgText);
  await selectManager(page, MANAGER1);
  await page.click('button:has-text("Добавить")');
  await expect(page).toHaveURL(/\/admin\/hides$/);
  await expect(hideRow(page, orgText).first()).toBeVisible();
}

// Убрать запись скрытия пары org+manager1 (кнопка «Показать» в строке).
async function unhideFromManager1(page: Page, orgText: string) {
  await gotoHides(page);
  const row = hideRow(page, orgText).filter({ hasText: MANAGER1 }).first();
  await row.getByRole('button', { name: 'Показать', exact: true }).click();
  await expect(page).toHaveURL(/\/admin\/hides$/);
  // Строка этой конкретной пары удалена.
  await expect(hideRow(page, orgText).filter({ hasText: MANAGER1 })).toHaveCount(0);
}

// Скрыть организацию от ВСЕХ менеджеров (пустая опция в дропдауне).
async function hideFromAllManagers(page: Page, orgText: string) {
  await gotoHides(page);
  await selectOrganization(page, orgText);
  // Выбираем пустую опцию — «скрыть от всех менеджеров».
  await page.selectOption('select[name="managers[]"]', '');
  await page.click('button:has-text("Добавить")');
  await expect(page).toHaveURL(/\/admin\/hides$/);
  await expect(hideRow(page, orgText).first()).toBeVisible();
}

test('админ скрывает организацию от менеджера — она исчезает только у него', async ({ page }) => {
  await login(page, ADMIN, ADMIN_PASSWORD);
  await cleanupHide(page, 'Парус');
  await hideFromManager1(page, 'Парус');

  // Реестр показывает организацию, менеджера и дату скрытия.
  const row = hideRow(page, 'Парус').first();
  await expect(row).toContainText(MANAGER1);
  await expect(row).toContainText(/\d{2}\.\d{2}\.\d{4}/);

  // Менеджер не видит организацию на панели.
  await logout(page);
  await login(page, MANAGER1, MANAGER_PASSWORD);
  await page.goto('/dashboard');
  let names = await dashboardOrgNames(page);
  expect(names.some((n) => n.includes('Парус'))).toBe(false);
  expect(names.some((n) => n.includes('Вектор'))).toBe(true);

  // Других менеджеров скрытие не затрагивает.
  await logout(page);
  await login(page, MANAGER2, MANAGER_PASSWORD);
  await page.goto('/dashboard');
  names = await dashboardOrgNames(page);
  expect(names.some((n) => n.includes('Парус'))).toBe(true);

  // Возврат видимости: организация снова у менеджера.
  await logout(page);
  await login(page, ADMIN, ADMIN_PASSWORD);
  await unhideFromManager1(page, 'Парус');

  await logout(page);
  await login(page, MANAGER1, MANAGER_PASSWORD);
  await page.goto('/dashboard');
  names = await dashboardOrgNames(page);
  expect(names.some((n) => n.includes('Парус'))).toBe(true);
});

test('повторное скрытие той же пары отклоняется конфликтом', async ({ page }) => {
  await login(page, ADMIN, ADMIN_PASSWORD);
  await cleanupHide(page, 'Закат');
  await hideFromManager1(page, 'Закат');

  // Вторая попытка для той же пары — flash-сообщение об ошибке, запись не дублируется.
  await gotoHides(page);
  await selectOrganization(page, 'Закат');
  await selectManager(page, MANAGER1);
  await page.click('button:has-text("Добавить")');
  await expect(page).toHaveURL(/\/admin\/hides$/);
  await expect(
    page.locator('.alert--error, .flash--error', { hasText: 'Организация уже скрыта от' }),
  ).toBeVisible();

  // В реестре ровно одна запись для этой пары (не дублируется).
  await gotoHides(page);
  await expect(hideRow(page, 'Закат')).toHaveCount(1);

  await unhideFromManager1(page, 'Закат');
  await expect(hideRow(page, 'Закат')).toHaveCount(0);
});

test('менеджер получает 403 в разделе скрытий, в навигации пункта нет', async ({ page }) => {
  await login(page, MANAGER1, MANAGER_PASSWORD);

  await page.goto('/dashboard');
  await expect(
    page.locator('.header__menu-link', { hasText: 'Скрытые организации' }),
  ).toHaveCount(0);

  const list = await page.goto('/admin/hides');
  expect(list?.status()).toBe(403);

  await logout(page);
  await login(page, ADMIN, ADMIN_PASSWORD);
  // Открываем выпадающее меню «Админ», где находится ссылка.
  await page.locator('[data-header-admin-toggle]').click();
  const link = page.locator('.header-admin__item', { hasText: 'Скрытые организации' });
  await expect(link).toBeVisible();
  await link.click();
  await expect(page.locator('h1', { hasText: 'Скрытые организации' })).toBeVisible();
  // Реестр показывает запись из фикстур: «Конкурент» скрыта от manager1.
  await expect(hideRow(page, 'Конкурент').first()).toBeVisible();
  // Встроенная форма добавления доступна.
  await expect(page.locator('select[name="organization"]')).toBeVisible();
  await expect(page.locator('select[name="managers[]"]')).toBeVisible();
});

test('форма редактирования: скрытие от менеджера и возврат видимости', async ({ page }) => {
  await login(page, ADMIN, ADMIN_PASSWORD);
  await cleanupHide(page, 'Закат');

  // Скрываем от manager1 через реестр /admin/hides.
  await hideFromManager1(page, 'Закат');

  // Открываем форму редактирования — секция показывает менеджера.
  await page.goto('/organizations/6/edit'); // Закат id=6
  await expect(page.locator('.organization-form__hides')).toBeVisible();
  await expect(page.locator('.organization-hides__item', { hasText: MANAGER1 })).toBeVisible();

  // Менеджер не видит организацию.
  await logout(page);
  await login(page, MANAGER1, MANAGER_PASSWORD);
  await page.goto('/dashboard');
  expect(await dashboardOrgNames(page)).toEqual(
    expect.not.arrayContaining([expect.stringContaining('Закат')]),
  );

  // Возвращаем видимость: клик «Показать» отправляет форму с name="unhide".
  await logout(page);
  await login(page, ADMIN, ADMIN_PASSWORD);
  await page.goto('/organizations/6/edit');
  await page.locator('.organization-hides__item', { hasText: MANAGER1 })
    .getByRole('button', { name: 'Показать', exact: true })
    .click();
  await expect(page).toHaveURL(/\/organizations\/6\/edit/);
  await expect(page.locator('.organization-hides__empty')).toBeVisible();

  // Менеджер снова видит организацию.
  await logout(page);
  await login(page, MANAGER1, MANAGER_PASSWORD);
  await page.goto('/dashboard');
  expect(await dashboardOrgNames(page)).toEqual(
    expect.arrayContaining([expect.stringContaining('Закат')]),
  );
});

test('форма редактирования: менеджер не видит секцию скрытий', async ({ page }) => {
  await login(page, MANAGER1, MANAGER_PASSWORD);
  await page.goto('/organizations/1/edit'); // Ромашка
  await expect(page.locator('.organization-form__hides')).toHaveCount(0);
});

test('скрытие от всех менеджеров через пустую опцию дропдауна', async ({ page }) => {
  await login(page, ADMIN, ADMIN_PASSWORD);
  await cleanupHide(page, 'Парус');

  // Скрываем от всех через пустую опцию.
  await hideFromAllManagers(page, 'Парус');

  // Оба менеджера не видят организацию.
  await logout(page);
  await login(page, MANAGER1, MANAGER_PASSWORD);
  await page.goto('/dashboard');
  expect(await dashboardOrgNames(page)).toEqual(
    expect.not.arrayContaining([expect.stringContaining('Парус')]),
  );

  await logout(page);
  await login(page, MANAGER2, MANAGER_PASSWORD);
  await page.goto('/dashboard');
  expect(await dashboardOrgNames(page)).toEqual(
    expect.not.arrayContaining([expect.stringContaining('Парус')]),
  );

  // Уборка: возвращаем видимость (показываем по одной строке).
  await logout(page);
  await login(page, ADMIN, ADMIN_PASSWORD);
  await cleanupHide(page, 'Парус');
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

  // Скрытая организация не отображается в составе группы менеджера.
  const form = page.locator('.group-members-form');
  await expect(form).toContainText('Ромашка');
  await expect(form).not.toContainText('Вектор');

  // Уборка: возвращаем видимость.
  await logout(page);
  await login(page, ADMIN, ADMIN_PASSWORD);
  await unhideFromManager1(page, 'Вектор');
});
