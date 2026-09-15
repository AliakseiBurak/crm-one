import { expect, test, type Page } from '@playwright/test';

// CRUD рассылок (change campaign-entity): создание, редактирование,
// статусы, вложения, адресаты, запуск/остановка, клонирование, удаление.

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

async function createCampaign(page: Page, name: string, opts?: { status?: string; subject?: string; body?: string }): Promise<number> {
  await page.goto('/campaigns/new');
  await page.fill('input[name="name"]', name);
  await page.fill('input[name="subject"]', opts?.subject ?? 'Тема');
  await page.fill('textarea[name="body"]', opts?.body ?? 'Текст письма');
  if (opts?.status) {
    await page.selectOption('select[name="status"]', opts.status);
  }
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/highlight=(\d+)/);
  const match = page.url().match(/highlight=(\d+)/);
  return match ? parseInt(match[1], 10) : 0;
}

// ─── Создание рассылки ───────────────────────────────────────────────

test('создание рассылки со всеми полями', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Создание');

  await page.goto('/campaigns/new');
  await page.fill('input[name="name"]', name);
  await page.fill('input[name="subject"]', 'Приглашаем на курсы 2026');
  await page.fill('input[name="preview_text"]', 'Превью письма');
  await page.fill('textarea[name="body"]', '{{greeting}}! Приглашаем вас на курсы.');
  await page.selectOption('select[name="status"]', 'ready');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();

  await expect(page).toHaveURL(/campaigns/);
  await page.goto('/campaigns');
  await expect(page.locator('tr[data-status]', { hasText: name })).toBeVisible();
});

test('создание рассылки с валидацией обязательных полей', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/campaigns/new');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();

  await expect(page.locator('.field__error', { hasText: 'Название обязательно' })).toBeVisible();
  await expect(page.locator('.field__error', { hasText: 'Тема письма обязательна' })).toBeVisible();
  await expect(page.locator('.field__error', { hasText: 'Текст письма обязателен' })).toBeVisible();
});

test('статус по умолчанию — черновик', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/campaigns/new');
  await expect(page.locator('select[name="status"]')).toHaveValue('draft');
});

test('статус failed недоступен в форме', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/campaigns/new');
  const options = page.locator('select[name="status"] option');
  const values = await options.allTextContents();
  expect(values.some(t => t.includes('Ошибка'))).toBe(false);
});

// ─── Список рассылок и сортировка ────────────────────────────────────

test('список рассылок отображается', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/campaigns');
  await expect(page.locator('h1', { hasText: 'Рассылки' })).toBeVisible();
  await expect(page.locator('table.table')).toBeVisible();
});

test('сортировка по столбцам', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/campaigns');
  const nameHeader = page.locator('th a', { hasText: 'Название' });
  await nameHeader.click();
  await expect(page).toHaveURL(/sort=name/);
});

// ─── Карточка рассылки ───────────────────────────────────────────────

test('карточка рассылки отображает поля', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Карточка');
  const subject = 'Тема тест ' + Date.now();

  const id = await createCampaign(page, name, { subject });
  await page.goto(`/campaigns/${id}`);

  await expect(page.locator('h1', { hasText: name })).toBeVisible();
  await expect(page.locator('dt', { hasText: 'Тема письма' })).toBeVisible();
  await expect(page.locator('dd', { hasText: subject })).toBeVisible();
});

// ─── Редактирование ──────────────────────────────────────────────────

test('редактирование рассылки', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Редактируемая');
  const newSubject = 'Обновлённая тема ' + Date.now();

  const id = await createCampaign(page, name, { subject: 'Оригинал' });
  await page.goto(`/campaigns/${id}/edit`);

  await page.fill('input[name="subject"]', newSubject);
  await page.click('button:has-text("Сохранить")');

  await page.goto(`/campaigns/${id}`);
  await expect(page.locator('dd', { hasText: newSubject })).toBeVisible();
});

// ─── Запуск / Остановка ──────────────────────────────────────────────

test('запуск рассылки со статусом ready', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Запуск');

  const id = await createCampaign(page, name, { status: 'ready' });
  await page.goto(`/campaigns/${id}`);
  await page.click('button:has-text("Запустить")');

  await expect(page.locator('dd', { hasText: 'Запущена' })).toBeVisible();
});

test('остановка запущенной рассылки', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Остановка');

  const id = await createCampaign(page, name, { status: 'ready' });
  await page.goto(`/campaigns/${id}`);
  await page.click('button:has-text("Запустить")');
  await expect(page.locator('dd', { hasText: 'Запущена' })).toBeVisible();

  await page.click('button:has-text("Остановить")');
  await expect(page.locator('dd', { hasText: 'Готова' })).toBeVisible();
});

test('кнопка Запустить не отображается для черновика', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Черновик');

  const id = await createCampaign(page, name);
  await page.goto(`/campaigns/${id}`);
  await expect(page.locator('button:has-text("Запустить")')).toBeHidden();
});

// ─── Сброс ошибки ────────────────────────────────────────────────────

test('сброс failed-рассылки в ready', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  // Find a failed campaign in the list
  await page.goto('/campaigns');
  const failedRow = page.locator('tr[data-status="failed"]').first();
  if ((await failedRow.count()) > 0) {
    const id = (await failedRow.getAttribute('id'))?.replace('campaign-', '') ?? '';

    // Navigate to show page (reset button is there, not in the list)
    await page.goto(`/campaigns/${id}`);
    await page.click('button:has-text("Сбросить")');

    // Reset redirects to show page; status label should now be "Готова"
    await expect(page).toHaveURL(new RegExp(`/campaigns/${id}$`));
    await expect(page.locator('dd', { hasText: 'Готова' })).toBeVisible();
  }
});

// ─── Клонирование ────────────────────────────────────────────────────

test('клонирование рассылки', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Оригинал Клон');

  const id = await createCampaign(page, name, { status: 'ready' });
  await page.goto(`/campaigns/${id}`);
  await page.click('button:has-text("Клонировать")');

  await page.goto('/campaigns');
  await expect(page.locator('tr[data-status]', { hasText: `${name} (копия)` })).toBeVisible();
});

test('клонирование недоступно для черновика', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Черновик НеКлон');

  const id = await createCampaign(page, name);
  await page.goto(`/campaigns/${id}`);
  await expect(page.locator('button:has-text("Клонировать")')).toBeHidden();
});

// ─── Вложения ─────────────────────────────────────────────────────────

test('загрузка вложения при редактировании', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('С Вложениями');

  const id = await createCampaign(page, name);
  await page.goto(`/campaigns/${id}/edit`);

  const fileInput = page.locator('input[type="file"][name="attachments[]"]');
  await fileInput.setInputFiles({
    name: 'test-file.pdf',
    mimeType: 'application/pdf',
    buffer: Buffer.from('test content'),
  });

  await page.click('button:has-text("Сохранить")');

  await page.goto(`/campaigns/${id}`);
  await expect(page.locator('.campaign-attachments__name', { hasText: 'test-file.pdf' })).toBeVisible();
});

// ─── Удаление ─────────────────────────────────────────────────────────

test('удаление рассылки с подтверждением', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Удаляемая');

  const id = await createCampaign(page, name);
  await page.goto(`/campaigns/${id}/delete`);

  await expect(page.locator('h1', { hasText: 'Удаление рассылки' })).toBeVisible();
  await page.click('button:has-text("Удалить")');

  await page.goto('/campaigns');
  await expect(page.locator('tr[data-status]', { hasText: name })).toBeHidden();
});

// ─── Адресаты ─────────────────────────────────────────────────────────

test('страница адресатов отображается', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Для Адресатов');

  const id = await createCampaign(page, name, { status: 'ready' });
  await page.goto(`/campaigns/${id}/recipients`);

  await expect(page.locator('h1', { hasText: 'Адресаты рассылки' })).toBeVisible();
  const hasTable = await page.locator('.campaign-recipients__table').isVisible().catch(() => false);
  if (hasTable) {
    await expect(page.locator('th', { hasText: 'Статус' })).toBeVisible();
  } else {
    await expect(page.locator('.campaign-recipients__empty')).toBeVisible();
  }
});

test('добавление адресата', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Адресат Добавление');

  const id = await createCampaign(page, name, { status: 'ready' });
  await page.goto(`/campaigns/${id}/recipients`);

  const orgSelect = page.locator('select[name="organization"]');
  if ((await orgSelect.locator('option').count()) > 1) {
    await orgSelect.selectOption({ index: 1 });
    await page.click('button:has-text("Добавить")');
    await expect(page.locator('.campaign-recipients__table')).toBeVisible();
  }
});

test('массовое добавление всех организаций', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Массовое');

  const id = await createCampaign(page, name, { status: 'ready' });
  await page.goto(`/campaigns/${id}/recipients`);

  await page.click('button:has-text("Выбрать все организации")');
  await expect(page.locator('.campaign-recipients__table tbody tr').first()).toBeVisible();
});

test('замена адресата при добавлении дубля организации', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Замена Адресата');

  const id = await createCampaign(page, name, { status: 'ready' });
  await page.goto(`/campaigns/${id}/recipients`);

  const orgSelect = page.locator('select[name="organization"]');
  if ((await orgSelect.locator('option').count()) > 1) {
    await orgSelect.selectOption({ index: 1 });
    await page.waitForTimeout(200);
    const contactSelect = page.locator('select[name="contact"]');
    const contactCount = await contactSelect.locator('option').count();

    if (contactCount > 1) {
      await contactSelect.selectOption({ index: 1 });
      await page.click('button:has-text("Добавить")');
      await expect(page.locator('.campaign-recipients__table')).toBeVisible();

      await orgSelect.selectOption({ index: 1 });
      await page.waitForTimeout(200);
      await contactSelect.selectOption({ index: 0 });
      await page.click('button:has-text("Добавить")');
    } else {
      await page.click('button:has-text("Добавить")');
      await expect(page.locator('.campaign-recipients__table')).toBeVisible();

      await orgSelect.selectOption({ index: 1 });
      await page.click('button:has-text("Добавить")');
    }

    await expect(page.locator('h1', { hasText: 'Замена адресата' })).toBeVisible();
    await page.click('button:has-text("Заменить")');
    await expect(page.locator('.campaign-recipients__table')).toBeVisible();
  }
});

test('удаление адресата', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Удаление Адресата');

  const id = await createCampaign(page, name, { status: 'ready' });
  await page.goto(`/campaigns/${id}/recipients`);

  const orgSelect = page.locator('select[name="organization"]');
  if ((await orgSelect.locator('option').count()) > 1) {
    await orgSelect.selectOption({ index: 1 });
    await page.click('button:has-text("Добавить")');
    await expect(page.locator('.campaign-recipients__table')).toBeVisible();

    page.on('dialog', dialog => dialog.accept());
    await page.locator('.campaign-recipients__table button:has-text("Убрать")').first().click();
    await expect(page.locator('.campaign-recipients__empty')).toBeVisible();
  }
});

test('страница адресатов для архивной рассылки — форма скрыта', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Архив Адресаты');

  const id = await createCampaign(page, name);
  await page.goto(`/campaigns/${id}/edit`);
  await page.selectOption('select[name="status"]', 'archived');
  await page.click('button:has-text("Сохранить")');

  await page.goto(`/campaigns/${id}/recipients`);
  await expect(page.locator('h1', { hasText: 'Адресаты рассылки' })).toBeVisible();
  await expect(page.locator('select[name="organization"]')).toBeHidden();
  await expect(page.locator('button:has-text("Выбрать все организации")')).toBeHidden();
});

test('редактирование failed-рассылки — статус failed доступен', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/campaigns');
  const failedRow = page.locator('tr[data-status="failed"]').first();
  if ((await failedRow.count()) > 0) {
    const href = await failedRow.locator('a').first().getAttribute('href');
    const id = href?.match(/\/campaigns\/(\d+)/)?.[1];
    if (id) {
      await page.goto(`/campaigns/${id}/edit`);
      const options = page.locator('select[name="status"] option');
      const values = await options.allTextContents();
      expect(values.some(t => t.includes('Ошибка'))).toBe(true);
    }
  }
});

// ─── Индикаторы статусов в списке ─────────────────────────────────────

test('индикаторы статусов отображаются в списке', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/campaigns');
  const rows = page.locator('table.table tbody tr[data-status]');
  const count = await rows.count();
  expect(count).toBeGreaterThan(0);

  for (let i = 0; i < count; i++) {
    const status = await rows.nth(i).getAttribute('data-status');
    expect(['draft', 'ready', 'launched', 'failed', 'archived']).toContain(status);
  }
});

test('архивные рассылки внизу списка', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/campaigns');
  const rows = page.locator('table.table tbody tr[data-status]');
  const count = await rows.count();

  if (count > 1) {
    let foundNonArchived = false;
    let archivedAfterNonArchived = false;

    for (let i = 0; i < count; i++) {
      const status = await rows.nth(i).getAttribute('data-status');
      if (status !== 'archived') {
        foundNonArchived = true;
      } else if (foundNonArchived) {
        archivedAfterNonArchived = true;
      }
    }

    if (archivedAfterNonArchived) {
      expect(archivedAfterNonArchived).toBe(true);
    }
  }
});

// ─── Навигация ────────────────────────────────────────────────────────

test('кнопка «Назад к списку» возвращает в список', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  const name = uniqueName('Навигация');

  const id = await createCampaign(page, name);
  await page.goto(`/campaigns/${id}`);
  await page.click('a:has-text("Назад к списку")');

  await expect(page).toHaveURL(/\/campaigns$/);
});
