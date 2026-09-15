import { expect, test, type Locator, type Page } from '@playwright/test';

// Результат звонка (change call-result): рассылка, следующий звонок,
 // сделка/нет ответа, валидация, предупреждение при удалении.

const editModal = '[data-call-edit-modal] .modal__window';
const loginSubmit = 'form[action="/login"] button[type="submit"]';

let counter = 0;
function unique(prefix: string) {
  return `${prefix} ${Date.now()}-${++counter}`;
}

function pad(n: number) {
  return String(n).padStart(2, '0');
}

function formatDate(date: Date) {
  return `${pad(date.getDate())}.${pad(date.getMonth() + 1)}.${date.getFullYear()}`;
}

function formatDateTime(date: Date) {
  return `${formatDate(date)} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

async function login(page: Page, email: string, password: string) {
  await page.goto('/login');
  await page.fill('input[name="_username"]', email);
  await page.fill('input[name="_password"]', password);
  await page.click(loginSubmit);
  await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toBeVisible();
}

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

async function createReadyCampaign(page: Page, name: string): Promise<number> {
  await page.goto('/campaigns/new');
  await page.fill('input[name="name"]', name);
  await page.fill('input[name="subject"]', `Тема ${name}`);
  await page.fill('textarea[name="body"]', '{{greeting}}');
  await page.selectOption('select[name="status"]', 'ready');
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/highlight=(\d+)/);
  const match = page.url().match(/highlight=(\d+)/);
  return match ? parseInt(match[1], 10) : 0;
}

async function deleteCampaign(page: Page, id: number) {
  await page.goto(`/campaigns/${id}/delete`);
  await page.click('button:has-text("Удалить")');
  await expect(page).toHaveURL(/\/campaigns/);
}

async function deleteCallById(page: Page, callId: string) {
  await page.goto(`/calls/${callId}/delete`);
  await page.click('button:has-text("Удалить")');
  await expect(page).toHaveURL(/\/dashboard\?highlight=\d+$/);
}

/** Раскрывает аккордеон звонков и открывает модалку для проведённого звонка без следующего. */
async function openEditableCallModal(page: Page): Promise<Locator> {
  await page.goto('/dashboard');

  const completed = page
    .locator('[data-call-row][data-call-next-call-id=""]:not([data-call-made-at=""])')
    .first();
  await expect(completed).toBeAttached({ timeout: 10_000 });

  const orgDetails = completed.locator('xpath=ancestor::details[contains(@class,"org-details__box")]').first();
  await orgDetails.locator('summary.org-details__summary').click();
  const allCalls = orgDetails.locator('details.org-calls__all').first();
  if (!(await allCalls.evaluate((el) => (el as HTMLDetailsElement).open))) {
    await allCalls.locator('summary').click();
  }

  await completed.locator('[data-call-edit]').click();
  await expect(page.locator(editModal)).toBeVisible();

  return completed;
}

async function selectCampaignByName(page: Page, campaignName: string) {
  const select = page.locator('[data-call-field="mailing_campaign"]');
  const value = await select.locator('option').evaluateAll((options, name) => {
    const match = options.find((o) => (o.textContent ?? '').includes(name));
    return match ? (match as HTMLOptionElement).value : '';
  }, campaignName);
  expect(value).not.toBe('');
  await select.selectOption(value);
}

async function createCompletedCall(page: Page, notes: string): Promise<string> {
  await page.goto('/dashboard');
  const addLink = page.locator('a.org-calls__add').first();
  const href = (await addLink.getAttribute('href')) ?? '/calls/new';
  await page.goto(href);

  const yesterday = new Date(Date.now() - 86_400_000);
  await page.fill('#made_at', formatDateTime(yesterday));
  await page.fill('#notes', notes);
  await page.locator('form').getByRole('button', { name: 'Создать' }).click();
  await expect(page).toHaveURL(/\/dashboard\?highlight=\d+$/);

  const highlighted = page.locator('.org-table__row--highlight');
  const details = highlighted.locator('xpath=./following-sibling::tr[1]').locator('.org-details__box');
  await details.locator('summary.org-details__summary').click();
  await details.locator('.org-calls__all summary').click();
  const item = details.locator('.org-calls__item', { hasText: notes }).first();
  await expect(item).toBeVisible();

  return (await item.getAttribute('data-call-id')) ?? '';
}

// ─── Список рассылок в форме результата ──────────────────────────────

test('в списке рассылки есть черновик и нет архивной', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await openEditableCallModal(page);

  const options = page.locator('[data-call-field="mailing_campaign"] option');
  const texts = await options.allTextContents();
  expect(texts.some((t) => t.includes('Новые курсы'))).toBe(true);
  expect(texts.some((t) => t.includes('Прошлая акция'))).toBe(false);
  expect(texts.some((t) => t.includes('Осенняя рассылка'))).toBe(true);
});

// ─── Рассылка ────────────────────────────────────────────────────────

test('проведённый звонок: выбор рассылки создаёт адресата', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  const campaignName = unique('E2E рассылка');
  const campaignId = await createReadyCampaign(page, campaignName);
  expect(campaignId).toBeGreaterThan(0);

  const notes = unique('e2e mailing');
  const callId = await createCompletedCall(page, notes);

  await page.goto('/dashboard');
  const row = page.locator(`[data-call-row][data-call-id="${callId}"]`);
  const orgDetails = row.locator('xpath=ancestor::details[contains(@class,"org-details__box")]').first();
  await orgDetails.locator('summary.org-details__summary').click();
  await orgDetails.locator('.org-calls__all summary').click();
  await row.locator('[data-call-edit]').click();
  await expect(page.locator(editModal)).toBeVisible();

  const yesterday = new Date(Date.now() - 86_400_000);
  await page.locator('[data-call-field="made_at"]').fill(formatDateTime(yesterday));
  await selectCampaignByName(page, campaignName);
  await page.locator(editModal).locator('button[type="submit"]').first().click();
  await expect(page.locator(editModal)).not.toBeVisible();

  await page.goto(`/campaigns/${campaignId}/recipients`);
  await expect(page.locator('.campaign-recipients__table')).toBeVisible();
  await expect(page.locator('.campaign-recipients__table tbody tr').first()).toBeVisible();

  await deleteCallById(page, callId);
  await deleteCampaign(page, campaignId);
});

// ─── Следующий звонок в модалке ──────────────────────────────────────

test('дата следующего звонка добавляет строку в «Все звонки» без перезагрузки', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  const notes = unique('e2e next-call');
  const callId = await createCompletedCall(page, notes);

  await page.goto('/dashboard');
  const row = page.locator(`[data-call-row][data-call-id="${callId}"]`);
  const orgDetails = row.locator('xpath=ancestor::details[contains(@class,"org-details__box")]').first();
  await orgDetails.locator('summary.org-details__summary').click();
  const allCalls = orgDetails.locator('details.org-calls__all').first();
  await allCalls.locator('summary').click();

  const list = allCalls.locator('.org-calls__list');
  const beforeCount = await list.locator('[data-call-row]').count();

  await row.locator('[data-call-edit]').click();
  await expect(page.locator(editModal)).toBeVisible();
  await markAlive(page);

  const yesterday = new Date(Date.now() - 86_400_000);
  const nextDate = new Date(Date.now() + 10 * 86_400_000);
  const nextLabel = formatDate(nextDate);

  await page.locator('[data-call-field="made_at"]').fill(formatDateTime(yesterday));
  await page.locator('[data-call-field="next_call_date"]').fill(nextLabel);
  await page.locator(editModal).locator('button[type="submit"]').first().click();

  await expect(page.locator(editModal)).not.toBeVisible();
  await expectStillSamePage(page);

  await expect(list.locator('[data-call-row]')).toHaveCount(beforeCount + 1);
  await expect(list.locator(`[data-call-row]:has-text("${nextLabel}")`).first()).toBeVisible();
  await expect(allCalls.locator('.org-calls__all-summary')).toHaveText(
    new RegExp(`Все звонки \\(${beforeCount + 1}\\)`),
  );

  const nextRow = list.locator(`[data-call-row]:has-text("${nextLabel}")`).first();
  const nextId = await nextRow.getAttribute('data-call-id');
  if (nextId) {
    await deleteCallById(page, nextId);
  }
  await deleteCallById(page, callId);
});

// ─── Сделка и нет ответа ─────────────────────────────────────────────

test('сделка и нет ответа сохраняются и видны в строке', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  const notes = unique('e2e deal no-answer');
  const callId = await createCompletedCall(page, notes);

  await page.goto('/dashboard');
  const row = page.locator(`[data-call-row][data-call-id="${callId}"]`);
  const orgDetails = row.locator('xpath=ancestor::details[contains(@class,"org-details__box")]').first();
  await orgDetails.locator('summary.org-details__summary').click();
  await orgDetails.locator('.org-calls__all summary').click();
  await row.locator('[data-call-edit]').click();

  const yesterday = new Date(Date.now() - 86_400_000);
  await page.locator('[data-call-field="made_at"]').fill(formatDateTime(yesterday));
  await page.locator('[data-call-field="is_deal"]').check();
  await page.locator('[data-call-field="is_no_answer"]').check();
  await page.locator(editModal).locator('button[type="submit"]').first().click();
  await expect(page.locator(editModal)).not.toBeVisible();

  const updated = page.locator(`[data-call-row][data-call-id="${callId}"]`);
  await expect(updated).toHaveAttribute('data-call-is-deal', '1');
  await expect(updated).toHaveAttribute('data-call-is-no-answer', '1');
  await expect(updated.locator('.org-calls__deal')).toBeVisible();
  await expect(updated.locator('.org-calls__no-answer')).toBeVisible();

  await deleteCallById(page, callId);
});

// ─── Валидация ───────────────────────────────────────────────────────

test('очистка фактической даты у проведённого звонка отклоняется', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await openEditableCallModal(page);

  await page.locator('[data-call-field="made_at"]').fill('');
  await page.locator(editModal).locator('button[type="submit"]').first().click();

  await expect(page.locator('[data-call-error="madeAt"]')).toBeVisible();
  await expect(page.locator('[data-call-error="madeAt"]')).toContainText(
    'Фактическую дату звонка нельзя удалить, только изменить',
  );
  await expect(page.locator(editModal)).toBeVisible();
});

test('рассылка без фактической даты отклоняется с ошибкой', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/dashboard');
  const planned = page
    .locator('[data-call-row][data-call-next-call-id=""][data-call-made-at=""]')
    .first();
  await expect(planned).toBeAttached({ timeout: 10_000 });
  const orgDetails = planned.locator('xpath=ancestor::details[contains(@class,"org-details__box")]').first();
  await orgDetails.locator('summary.org-details__summary').click();
  const allCalls = orgDetails.locator('details.org-calls__all').first();
  if (!(await allCalls.evaluate((el) => (el as HTMLDetailsElement).open))) {
    await allCalls.locator('summary').click();
  }
  await planned.locator('[data-call-edit]').click();
  await expect(page.locator(editModal)).toBeVisible();

  await page.locator('[data-call-field="made_at"]').fill('');
  const campaignOption = page.locator('[data-call-field="mailing_campaign"] option').nth(1);
  const value = await campaignOption.getAttribute('value');
  await page.locator('[data-call-field="mailing_campaign"]').selectOption(value ?? '');
  await page.locator(editModal).locator('button[type="submit"]').first().click();

  await expect(page.locator('[data-call-error="madeAt"]')).toBeVisible();
  await expect(page.locator('[data-call-error="madeAt"]')).toContainText(
    'Для действий результата звонка нужна фактическая дата',
  );
  await expect(page.locator(editModal)).toBeVisible();
});

test('дата следующего звонка в прошлом показывает ошибку и сохраняет рассылку', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await openEditableCallModal(page);

  const yesterday = new Date(Date.now() - 86_400_000);
  const campaignSelect = page.locator('[data-call-field="mailing_campaign"]');
  const campaignOption = campaignSelect.locator('option').nth(1);
  const campaignValue = (await campaignOption.getAttribute('value')) ?? '';
  await campaignSelect.selectOption(campaignValue);

  await page.locator('[data-call-field="made_at"]').fill(formatDateTime(yesterday));
  await page.locator('[data-call-field="next_call_date"]').fill('01.01.2020');
  await page.locator(editModal).locator('button[type="submit"]').first().click();

  await expect(page.locator('[data-call-error="nextCallDate"]')).toBeVisible();
  await expect(page.locator('[data-call-error="nextCallDate"]')).toHaveText(
    'Дата следующего звонка должна быть в будущем',
  );
  await expect(campaignSelect).toHaveValue(campaignValue);
  await expect(page.locator('[data-call-field="next_call_date"]')).toHaveValue('01.01.2020');
  await expect(page.locator(editModal)).toBeVisible();
});

// ─── Удаление ────────────────────────────────────────────────────────

test('страница удаления предупреждает об адресате рассылки', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  const campaignName = unique('E2E delete warn');
  const campaignId = await createReadyCampaign(page, campaignName);
  const notes = unique('e2e delete mailing');
  const callId = await createCompletedCall(page, notes);

  await page.goto('/dashboard');
  const row = page.locator(`[data-call-row][data-call-id="${callId}"]`);
  const orgDetails = row.locator('xpath=ancestor::details[contains(@class,"org-details__box")]').first();
  await orgDetails.locator('summary.org-details__summary').click();
  await orgDetails.locator('.org-calls__all summary').click();
  await row.locator('[data-call-edit]').click();

  const yesterday = new Date(Date.now() - 86_400_000);
  await page.locator('[data-call-field="made_at"]').fill(formatDateTime(yesterday));
  await selectCampaignByName(page, campaignName);
  await page.locator(editModal).locator('button[type="submit"]').first().click();
  await expect(page.locator(editModal)).not.toBeVisible();

  await page.goto(`/calls/${callId}/delete`);
  await expect(page.locator('[data-call-delete-mailing]')).toBeVisible();
  await expect(page.locator('[data-call-delete-mailing]')).toContainText('Адресат рассылки');
  await expect(page.locator('[data-call-delete-mailing]')).toContainText(campaignName);
  await expect(page.locator('[data-call-delete-mailing] a')).toHaveAttribute('target', '_blank');

  await page.click('button:has-text("Удалить")');
  await expect(page).toHaveURL(/\/dashboard\?highlight=\d+$/);
  await deleteCampaign(page, campaignId);
});

// ─── Макет формы: будущий звонок ─────────────────────────────────────

test('режим будущего звонка показывает дату плана и скрывает результат', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/calls/new');

  await expect(page.locator('#contact')).toBeVisible();
  await expect(page.locator('#is-future-call')).not.toBeChecked();
  await expect(page.locator('[data-call-scheduled-field]')).toBeHidden();
  await expect(page.locator('#made_at')).toBeVisible();
  await expect(page.locator('#is-deal')).toBeVisible();

  await page.locator('#is-future-call').check();
  await expect(page.locator('[data-call-scheduled-field]')).toBeVisible();
  await expect(page.locator('#made_at')).toBeHidden();
  await expect(page.locator('#is-deal')).toBeHidden();
  await expect(page.locator('#notes')).toBeVisible();
});
