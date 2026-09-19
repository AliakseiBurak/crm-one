import { expect, test, type Page } from '@playwright/test';

// Назначение групп менеджерам (change organization-group-assignment):
// - страница «Назначить» у группы: чекбоксы менеджеров, diff-сохранение,
//   менеджер получает назначенную группу на просмотр;
// - страница «Назначить» у менеджера: чекбоксы групп, синхронность с
//   групповой стороной;
// - предзаполнение по фикстурам («Клиенты-партнёры» / «Клиенты Ромашка»);
// - видимость кнопки «Назначить» и 403 для менеджера.

const loginSubmit = 'form[action="/login"] button[type="submit"]';

let counter = 0;
function uniqueName(prefix: string) {
  return `${prefix} ${Date.now()}-${++counter}`;
}

async function login(page: Page, email: string, password: string) {
  const login = email.split('@')[0];
  await page.goto('/login');
  await page.fill('input[name="_login"]', login);
  await page.fill('input[name="_password"]', password);
  await page.click(loginSubmit);
  await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toBeVisible();
}

async function logout(page: Page) {
  await page.goto('/logout');
}

async function createGroup(page: Page, name: string) {
  await page.goto('/groups/new');
  await page.fill('input[name="name"]', name);
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/\/groups$/);
}

function groupAssignLink(page: Page, name: string) {
  return page
    .locator('[data-group-row]', { hasText: name })
    .first()
    .locator('a:has-text("Назначить")');
}

function assignCheckbox(page: Page, text: string) {
  return page
    .locator('tbody tr[data-assign-row]', { hasText: text })
    .locator('input[type="checkbox"]');
}

// Временные группы удаляем через форму правки, чтобы состояние dev-БД
// (фикстуры и области видимости менеджеров) не менялось между прогонами.
async function deleteGroup(page: Page, name: string) {
  await page.goto('/groups');
  await page
    .locator('[data-group-row]', { hasText: name })
    .first()
    .locator('a:has-text("Редактировать")')
    .click();
  await page.click('a:has-text("Удалить")');
  await expect(page.locator('h1', { hasText: 'Удаление группы' })).toBeVisible();
  await page.click('button:has-text("Удалить")');
  await expect(page).toHaveURL(/\/groups$/);
  await expect(page.locator('[data-group-row]', { hasText: name })).toHaveCount(0);
}

test('администратор назначает группу менеджеру — менеджер видит её только на просмотр', async ({ page }) => {
  const name = uniqueName('Группа назначения');
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await createGroup(page, name);

  await expect(groupAssignLink(page, name)).toBeVisible();
  await groupAssignLink(page, name).click();
  await expect(page.locator('h1')).toContainText(`Назначение группы «${name}»`);

  // Spec «Назначение группы менеджеру»: новые менеджеры не отмечены.
  await expect(assignCheckbox(page, 'manager@b2b-crm.loc')).not.toBeChecked();
  await assignCheckbox(page, 'manager@b2b-crm.loc').check();
  await page.click('button:has-text("Сохранить")');
  await expect(page).toHaveURL(/\/groups$/);

  // Spec «Сохранение назначений»: состав менеджеров сохранён.
  await groupAssignLink(page, name).click();
  await expect(assignCheckbox(page, 'manager@b2b-crm.loc')).toBeChecked();

  // Менеджер получил доступ к группе (read-only, organization-groups).
  await logout(page);
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  await page.goto('/groups');
  const row = page.locator('[data-group-row]', { hasText: name }).first();
  await expect(row).toBeVisible();
  await expect(row.locator('.group-list__readonly')).toBeVisible();
  await expect(row.locator('a:has-text("Редактировать")')).toHaveCount(0);

  // Уборка: удаление группы снимает назначения (ADR-0011, каскад).
  await logout(page);
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await deleteGroup(page, name);
});

test('состав назначенных менеджеров предзаполнен (данные фикстур)', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/groups');

  // «Клиенты-партнёры»: по фикстурам назначена обоим менеджерам.
  await groupAssignLink(page, 'Клиенты-партнёры').click();
  await expect(page.locator('h1')).toContainText('Клиенты-партнёры');
  await expect(assignCheckbox(page, 'manager@b2b-crm.loc')).toBeChecked();
  await expect(assignCheckbox(page, 'manager2@b2b-crm.loc')).toBeChecked();
  // Группы создавшего её менеджера в назначении нет — только менеджеры (D5).
  await expect(page.locator('tbody tr[data-assign-row]', { hasText: 'admin@b2b-crm.loc' })).toHaveCount(0);

  // «Клиенты Ромашка»: назначений нет — ни одного флажка.
  await page.goto('/groups');
  await groupAssignLink(page, 'Клиенты Ромашка').click();
  await expect(page.locator('tbody tr[data-assign-row] input[type="checkbox"]:checked')).toHaveCount(0);
});

test('назначение из карточки менеджера синхронно со страницей группы', async ({ page }) => {
  const name = uniqueName('Группа из карточки');
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await createGroup(page, name);

  await page.goto('/admin/users');
  const managerRow = page.locator('[data-user-row]', { hasText: 'manager2@b2b-crm.loc' }).first();
  await managerRow.locator('a:has-text("Назначить")').click();
  await expect(page.locator('h1')).toContainText('Группы менеджера');

  await expect(assignCheckbox(page, name)).not.toBeChecked();
  await assignCheckbox(page, name).check();
  // Предзаполнение: «Клиенты-партнёры» уже назначен manager2 в фикстурах.
  await expect(assignCheckbox(page, 'Клиенты-партнёры')).toBeChecked();
  await page.click('button:has-text("Сохранить")');
  await expect(page).toHaveURL(/\/admin\/users$/);

  // Тот же факт с групповой стороны (design D1).
  await page.goto('/groups');
  await groupAssignLink(page, name).click();
  await expect(assignCheckbox(page, 'manager2@b2b-crm.loc')).toBeChecked();

  // Отмена назначения и удаление временной группы.
  await page.goto('/admin/users');
  await page
    .locator('[data-user-row]', { hasText: 'manager2@b2b-crm.loc' })
    .first()
    .locator('a:has-text("Назначить")')
    .click();
  await assignCheckbox(page, name).uncheck();
  await page.click('button:has-text("Сохранить")');
  await expect(page).toHaveURL(/\/admin\/users$/);
  await deleteGroup(page, name);
});

test('менеджер не видит «Назначить» в списке групп и получает 403', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  await page.goto('/groups');
  await expect(page.locator('a:has-text("Назначить")')).toHaveCount(0);

  const response = await page.goto('/groups/1/assign');
  expect(response?.status()).toBe(403);
});

test('в списке пользователей «Назначить» есть только у строк-менеджеров', async ({ page }) => {
  const email = `temp-admin-${Date.now()}-${++counter}@example.com`;
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  // Временный администратор: его строка — эталон «нет кнопки».
  await page.goto('/admin/users/new');
  const login = email.split('@')[0];
  await page.fill('input[name="login"]', login);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="name"]', '');
  await page.fill('input[name="surname"]', '');
  await page.selectOption('select[name="role"]', 'admin');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/\/admin\/users$/);

  const adminRow = page.locator('[data-user-row]', { hasText: login }).first();
  await expect(adminRow).toBeVisible();
  await expect(adminRow.locator('a:has-text("Назначить")')).toHaveCount(0);

  const managerRow = page.locator('[data-user-row]', { hasText: 'manager2' }).first();
  await expect(managerRow.locator('a:has-text("Назначить")')).toBeVisible();

  // Уборка: удаляем временного администратора (групп у него нет).
  await adminRow.getByRole('link', { name: 'Удалить' }).click();
  await expect(page.locator('h1', { hasText: 'Удаление пользователя' })).toBeVisible();
  await page.getByRole('button', { name: 'Удалить' }).last().click();
  await expect(page).toHaveURL(/\/admin\/users$/);
  await expect(page.locator('[data-user-row]', { hasText: login })).toHaveCount(0);
});
