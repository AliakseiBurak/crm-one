import { expect, test, type Page } from '@playwright/test';

// Organization groups e2e tests (change organization-groups):
// - Manager group CRUD (create, edit, delete)
// - Group membership management (add/remove orgs from groups)
// - Campaign recipient bulk add-by-group
// - Manager deletion flow with group reassign/delete

const loginSubmit = 'form[action="/login"] button[type="submit"]';

let counter = 0;
function uniqueName(prefix: string) {
  return `${prefix} ${Date.now()}-${++counter}`;
}

async function login(page: Page, email: string, password: string) {
  await page.goto('/login');
  await page.fill('input[name="_username"]', email);
  await page.fill('input[name="_password"]', password);
  await page.click(loginSubmit);
  await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toBeVisible();
}

async function createGroup(page: Page, name: string, opts?: { description?: string; color?: string }): Promise<number> {
  await page.goto('/groups/new');
  await page.fill('input[name="name"]', name);
  if (opts?.description) {
    await page.fill('textarea[name="description"]', opts.description);
  }
  if (opts?.color) {
    await page.fill('input[name="color"]', opts.color);
  }
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/\/groups$/);
  return 1; // Simple success indicator
}

async function navigateToGroupsPage(page: Page) {
  await page.goto('/groups');
  await expect(page.locator('h1', { hasText: 'Мои группы' })).toBeVisible();
}

async function logout(page: Page) {
  await page.goto('/logout');
}

function uniqueEmail(prefix: string) {
  return `${prefix}-${Date.now()}-${++counter}@b2b-crm.loc`;
}

async function createCampaign(page: Page, name: string): Promise<number> {
  await page.goto('/campaigns/new');
  await page.fill('input[name="name"]', name);
  await page.fill('input[name="subject"]', 'Тема теста');
  await page.fill('textarea[name="body"]', 'Текст письма');
  await page.selectOption('select[name="status"]', 'ready');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/highlight=(\d+)/);
  const match = page.url().match(/highlight=(\d+)/);

  return match ? parseInt(match[1] as string, 10) : 0;
}

// Созданная менеджером группа: id по ссылке «Редактировать» в строке списка.
async function groupIdByName(page: Page, name: string): Promise<number> {
  await page.goto('/groups');
  const href = await page.locator('[data-group-row]', { hasText: name }).first()
    .locator('a[href$="/edit"]').first().getAttribute('href');
  const match = (href ?? '').match(/\/groups\/(\d+)\/edit/);

  return match ? parseInt(match[1] as string, 10) : 0;
}

// ─── Manager Group CRUD ───────────────────────────────────────────────

test('manager can create a new group', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  const groupName = uniqueName('Тестовая группа');

  await navigateToGroupsPage(page);
  
  await page.click('a:has-text("Новая группа")');
  await expect(page.locator('h1', { hasText: 'Новая группа' })).toBeVisible();
  
  await page.fill('input[name="name"]', groupName);
  await page.fill('textarea[name="description"]', 'Описание тестовой группы');
  await page.fill('input[name="color"]', '#3b82f6');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  
  await expect(page).toHaveURL(/\/groups$/);
  await expect(page.locator('body', { hasText: groupName })).toBeVisible();
});

test('manager can edit their own group', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  const groupName = uniqueName('Группа для редактирования');

  await navigateToGroupsPage(page);
  
  // Create group first
  await page.click('a:has-text("Новая группа")');
  await page.fill('input[name="name"]', groupName);
  await page.fill('textarea[name="description"]', 'Оригинальное описание');
  await page.fill('input[name="color"]', '#3b82f6');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  
  await expect(page).toHaveURL(/\/groups$/);
  
  // Find and edit the group
  const groupRow = page.locator('[data-group-row]', { hasText: groupName }).first();
  await groupRow.locator('a:has-text("Редактировать")').click();
  
  await expect(page.locator('h1', { hasText: 'Редактировать группу' })).toBeVisible();
  
  await page.fill('input[name="name"]', `${groupName} Обновленная`);
  await page.fill('textarea[name="description"]', 'Обновленное описание');
  await page.fill('input[name="color"]', '#ef4444');
  await page.click('button:has-text("Сохранить")');
  
  await expect(page).toHaveURL(/\/groups$/);
  await expect(page.locator('body', { hasText: `${groupName} Обновленная` })).toBeVisible();
});

test('manager cannot edit other manager\'s group', async ({ page }) => {
  const groupName = uniqueName('Чужая группа (edit)');
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  await createGroup(page, groupName);
  const groupId = await groupIdByName(page, groupName);
  expect(groupId).toBeGreaterThan(0);

  await logout(page);
  await login(page, 'manager2@b2b-crm.loc', 'manager123');

  const response = await page.goto(`/groups/${groupId}/edit`);
  expect(response?.status()).toBe(403);
});

test('manager can delete their own group', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  const groupName = uniqueName('Группа для удаления');

  await navigateToGroupsPage(page);
  
  // Create group first
  await page.click('a:has-text("Новая группа")');
  await page.fill('input[name="name"]', groupName);
  await page.fill('textarea[name="description"]', 'Группа для теста удаления');
  await page.fill('input[name="color"]', '#3b82f6');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  
  await expect(page).toHaveURL(/\/groups$/);
  
  // Find and delete the group (ссылка «Удалить» — на форме правки)
  const groupRow = page.locator('[data-group-row]', { hasText: groupName }).first();
  await groupRow.locator('a:has-text("Редактировать")').click();
  await page.click('a:has-text("Удалить")');
  
  await expect(page.locator('h1', { hasText: 'Удаление группы' })).toBeVisible();
  
  await page.click('button:has-text("Удалить")');
  
  await expect(page).toHaveURL(/\/groups$/);
  await expect(page.locator('body', { hasText: groupName })).toBeHidden();
});

test('manager cannot delete other manager\'s group', async ({ page }) => {
  const groupName = uniqueName('Чужая группа (delete)');
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  await createGroup(page, groupName);
  const groupId = await groupIdByName(page, groupName);
  expect(groupId).toBeGreaterThan(0);

  await logout(page);
  await login(page, 'manager2@b2b-crm.loc', 'manager123');

  const response = await page.goto(`/groups/${groupId}/delete`);
  expect(response?.status()).toBe(403);
});

// ─── Group Membership Management ──────────────────────────────────────

test('manager can add organizations to their group', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  const groupName = uniqueName('Группа для участников');

  await navigateToGroupsPage(page);
  
  // Create group first
  await page.click('a:has-text("Новая группа")');
  await page.fill('input[name="name"]', groupName);
  await page.fill('textarea[name="description"]', 'Группа для теста участников');
  await page.fill('input[name="color"]', '#3b82f6');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  
  await expect(page).toHaveURL(/\/groups$/);
  
  // Find and go to group members page
  const groupRow = page.locator('[data-group-row]', { hasText: groupName }).first();
  await groupRow.locator('a:has-text("Состав")').click();
  
  await expect(page.locator('h1', { hasText: 'Состав группы' })).toBeVisible();
  
  // Select some organizations
  await page.locator('input[name="organizations[]"]').first().check();
  await page.click('button:has-text("Сохранить")');
  
  await expect(page).toHaveURL(/\/groups$/);
  
  // Verify the membership persisted: reopen members, a checkbox is checked
  const groupRowAfter = page.locator('[data-group-row]', { hasText: groupName }).first();
  await groupRowAfter.locator('a:has-text("Состав")').click();
  await expect(page.locator('input[name="organizations[]"]:checked').first()).toBeChecked();
});

test('manager can remove organizations from their group', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  const groupName = uniqueName('Группа для удаления участников');

  await navigateToGroupsPage(page);
  
  // Create group first
  await page.click('a:has-text("Новая группа")');
  await page.fill('input[name="name"]', groupName);
  await page.fill('textarea[name="description"]', 'Группа для теста удаления участников');
  await page.fill('input[name="color"]', '#3b82f6');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  
  await expect(page).toHaveURL(/\/groups$/);
  
  // Go to group members page
  const groupRow = page.locator('[data-group-row]', { hasText: groupName }).first();
  await groupRow.locator('a:has-text("Состав")').click();
  
  await expect(page.locator('h1', { hasText: 'Состав группы' })).toBeVisible();
  
  // Add an organization first, then remove it again
  await page.locator('input[name="organizations[]"]').first().check();
  await page.click('button:has-text("Сохранить")');
  await expect(page).toHaveURL(/\/groups$/);

  const groupRowAgain = page.locator('[data-group-row]', { hasText: groupName }).first();
  await groupRowAgain.locator('a:has-text("Состав")').click();

  const checkboxes = page.locator('input[name="organizations[]"]');
  const count = await checkboxes.count();
  for (let i = 0; i < count; i++) {
    await checkboxes.nth(i).uncheck();
  }
  await page.click('button:has-text("Сохранить")');
  
  await expect(page).toHaveURL(/\/groups$/);

  // Reopen members: nothing is checked anymore
  const groupRowAfter = page.locator('[data-group-row]', { hasText: groupName }).first();
  await groupRowAfter.locator('a:has-text("Состав")').click();
  await expect(page.locator('input[name="organizations[]"]:checked')).toHaveCount(0);
});

// ─── Campaign Recipient Bulk Add-By-Group ──────────────────────────────

test('manager can bulk add organizations from group to campaign recipients', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  const groupName = uniqueName('Группа для рассылки');
  const campaignName = uniqueName('Рассылка для групп');

  // Create a group with at least one organization
  await createGroup(page, groupName);
  const groupRow = page.locator('[data-group-row]', { hasText: groupName }).first();
  await groupRow.locator('a:has-text("Состав")').click();
  await page.locator('input[name="organizations[]"]').first().check();
  await page.click('button:has-text("Сохранить")');
  await expect(page).toHaveURL(/\/groups$/);

  // Create a campaign
  const campaignId = await createCampaign(page, campaignName);
  expect(campaignId).toBeGreaterThan(0);

  // Navigate to campaign recipients page
  await page.goto(`/campaigns/${campaignId}/recipients`);
  
  await expect(page.locator('h1', { hasText: 'Адресаты рассылки' })).toBeVisible();
  
  // Look for the group in the bulk add buttons
  const groupButton = page.locator('button[data-group-id]', { hasText: groupName });
  await expect(groupButton).toBeVisible();
  
  // Click the group button to add recipients
  await groupButton.click();
  
  // Wait for redirect back to recipients page
  await expect(page).toHaveURL(new RegExp(`/campaigns/${campaignId}/recipients$`));
  
  // Verify recipients were added
  await expect(page.locator('.campaign-recipients__table tbody tr')).toBeVisible();
});

test('manager cannot bulk add from inaccessible group', async ({ page }) => {
  await login(page, 'manager2@b2b-crm.loc', 'manager123');
  const campaignId = await createCampaign(page, uniqueName('Рассылка manager2'));
  expect(campaignId).toBeGreaterThan(0);

  await page.goto(`/campaigns/${campaignId}/recipients`);
  await expect(page.locator('h1', { hasText: 'Адресаты рассылки' })).toBeVisible();

  // Своя группа видна, чужая (менеджера manager@) — нет
  await expect(page.locator('button[data-group-id]', { hasText: 'Клиенты Вектор' })).toBeVisible();
  await expect(page.locator('button[data-group-id]', { hasText: 'Клиенты Ромашка' })).toHaveCount(0);
});

// ─── Manager Deletion Flow ────────────────────────────────────────────

test('admin can delete manager with group reassign/delete choices', async ({ page }) => {
  const victimEmail = uniqueEmail('e2e-victim');
  const victimPassword = 'victimpass123';
  const groupA = uniqueName('Victim group A'); // переназначается админу
  const groupB = uniqueName('Victim group B'); // удаляется

  // Админ создаёт одноразового менеджера
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/admin/users/new');
  await page.fill('input[name="email"]', victimEmail);
  await page.selectOption('select[name="role"]', 'manager');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/\/admin\/users$/);

  // Жертва устанавливает пароль через анонимную форму на /login
  await logout(page);
  await page.goto('/login');
  const csrf = await page.locator('#setup-password-form input[name="_csrf_token"]').inputValue();
  const setupResponse = await page.request.post('/setup-password', {
    form: {
      email: victimEmail,
      new_password: victimPassword,
      confirm_password: victimPassword,
      _csrf_token: csrf,
    },
  });
  expect(setupResponse.ok()).toBe(true);

  // Жертва создаёт две группы
  await logout(page);
  await login(page, victimEmail, victimPassword);
  await createGroup(page, groupA);
  await createGroup(page, groupB);
  await logout(page);

  // Админ удаляет жертву: A — переназначить, B — удалить
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/admin/users');
  const victimRow = page.locator('[data-user-row]', { hasText: victimEmail }).first();
  await expect(victimRow).toBeVisible();
  await victimRow.locator('a:has-text("Удалить")').click();

  await expect(page.locator('h1', { hasText: 'Удаление пользователя' })).toBeVisible();
  await expect(page.locator('h2', { hasText: 'Группы, созданные пользователем' })).toBeVisible();

  const choices = page.locator('.user-delete__group');
  expect(await choices.count()).toBeGreaterThanOrEqual(2);
  await choices.filter({ hasText: groupA }).locator('input[value="reassign"]').check();
  await choices.filter({ hasText: groupB }).locator('input[value="delete"]').check();

  await page.locator('button:has-text("Удалить")').click();
  await expect(page).toHaveURL(/\/admin\/users$/);
  await expect(page.locator('[data-user-row]', { hasText: victimEmail })).toHaveCount(0);

  // A досталась админу и видна в списке, B удалена
  await page.goto('/groups');
  await expect(page.locator('[data-group-row]', { hasText: groupA }).first()).toBeVisible();
  await expect(page.locator('[data-group-row]', { hasText: groupB })).toHaveCount(0);

  // Уборка: админ удаляет переназначенную группу (ссылка «Удалить» — на форме правки)
  const rowA = page.locator('[data-group-row]', { hasText: groupA }).first();
  await rowA.locator('a:has-text("Редактировать")').click();
  await page.click('a:has-text("Удалить")');
  await page.locator('button:has-text("Удалить")').click();
  await expect(page).toHaveURL(/\/groups$/);
  await expect(page.locator('[data-group-row]', { hasText: groupA })).toHaveCount(0);
});

test('admin cannot delete manager without selecting group action', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  
  await page.goto('/admin/users');
  
  // Менеджер с созданными группами (fixture manager2 владеет «Клиенты Вектор»)
  const managerRow = page.locator('[data-user-row]', { hasText: 'manager2@b2b-crm.loc' }).first();
  await expect(managerRow).toBeVisible();
  await managerRow.locator('a:has-text("Удалить")').click();
  
  await expect(page.locator('h1', { hasText: 'Удаление пользователя' })).toBeVisible();
  await expect(page.locator('.user-delete__group').first()).toBeVisible();
  
  // Клик «Удалить» без выбора действия: native required-validation блокирует отправку
  await page.locator('button:has-text("Удалить")').click();
  await expect(page).toHaveURL(/\/admin\/users\/\d+\/delete$/);

  // Менеджер по-прежнему существует
  await page.goto('/admin/users');
  await expect(page.locator('[data-user-row]', { hasText: 'manager2@b2b-crm.loc' })).toBeVisible();
});
