import { expect, test, type Page } from '@playwright/test';

// Организации на панели (change dashboard-orgs-contacts):
// таблица области доступа (default-open: всё, кроме скрытого — organization-hiding),
// поиск, сортировка, аккордеон контактов,
// звонки организаций, кнопки-заглушки действий (404).

const loginSubmit = 'form[action="/login"] button[type="submit"]';

async function login(page: Page, email: string, password: string) {
  await page.goto('/login');
  await page.fill('input[name="_username"]', email);
  await page.fill('input[name="_password"]', password);
  await page.click(loginSubmit);
  await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toBeVisible();
}

async function orgNames(page: Page): Promise<string[]> {
  return page.locator('.org-table__name').allTextContents();
}

test('менеджер видит все организации, кроме скрытых от него', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  await page.goto('/dashboard');

  await expect(page.getByRole('heading', { name: 'Организации' })).toBeVisible();
  const names = await orgNames(page);
  expect(names.some((n) => n.includes('Ромашка'))).toBe(true);
  expect(names.some((n) => n.includes('Вектор'))).toBe(true);
  // «Конкурент» скрыт от менеджера фикстурой organization_hide.
  expect(names.some((n) => n.includes('Конкурент'))).toBe(false);
});

test('администратор видит все организации', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  const names = await orgNames(page);
  expect(names.some((n) => n.includes('Ромашка'))).toBe(true);
  expect(names.some((n) => n.includes('Вектор'))).toBe(true);
  expect(names.some((n) => n.includes('Сидоров'))).toBe(true);
  expect(names.some((n) => n.includes('Конкурент'))).toBe(true);
});

test('даты звоноков берутся из звонков; без звонков — заглушка «—»', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  const romashka = page.locator('.org-table__row', { hasText: 'Ромашка' });
  await expect(romashka.locator('td').nth(2)).toHaveText(/\d{2}\.\d{2}\.\d{4}/);
  // У Ромашки есть план на завтра (+1д в фикстурах) — дата, не заглушка.
  await expect(romashka.locator('td').nth(3)).toHaveText(/\d{2}\.\d{2}\.\d{4}/);
  // Кнопки переноса даты у следующего звонка больше нет — редактирование
  // звонка выполняется из списка «Все звонки»
  await expect(romashka.locator('a', { hasText: 'Изменить дату' })).toHaveCount(0);
  await expect(romashka.locator('td').nth(3).locator('a')).toHaveCount(0);

  const sidorov = page.locator('.org-table__row', { hasText: 'Сидоров' });
  await expect(sidorov.locator('td').nth(2)).toHaveText(/\d{2}\.\d{2}\.\d{4}/);
  await expect(sidorov.locator('td').nth(3)).toHaveText(/\d{2}\.\d{2}\.\d{4}/);

  // Организация с контактом, но без звонков: обе даты — заглушка «—»
  const zakat = page.locator('.org-table__row', { hasText: 'Закат' });
  await expect(zakat.locator('td').nth(2)).toHaveText('—');
  await expect(zakat.locator('td').nth(3)).toHaveText('—');

  // Организация без звонков вовсе: обе даты — заглушка «—»
  const horizon = page.locator('.org-table__row', { hasText: 'Горизонт' });
  await expect(horizon.locator('td').nth(2)).toHaveText('—');
  await expect(horizon.locator('td').nth(3)).toHaveText('—');
});

test('организация без контактов и звонков: только кнопки действия', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  const horizon = page.locator('.org-table__row', { hasText: 'Горизонт' });
  const details = horizon.locator('xpath=./following-sibling::tr[1]').locator('.org-details__box');
  await details.locator('summary.org-details__summary').click();
  await expect(details.locator('.org-contacts__card-wrap')).toHaveCount(0);
  await expect(details.locator('a.org-contacts__add', { hasText: 'Добавить контакт' })).toBeVisible();
  // Кнопка «Добавить звонок» видна и при отсутствии звонков
  await expect(details.locator('a.org-calls__add', { hasText: 'Добавить звонок' })).toBeVisible();
  // Без звонков: секция звонков не рендерится вовсе
  await expect(details.locator('.org-calls')).toHaveCount(0);
  // Summary переименован
  await expect(details.locator('summary.org-details__summary')).toHaveText('Звонки и контакты организации');
});

test('организация с контактом, но без звонков: карточка есть, списка звонков нет', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  const zakat = page.locator('.org-table__row', { hasText: 'Закат' });
  const details = zakat.locator('xpath=./following-sibling::tr[1]').locator('.org-details__box');
  await details.locator('summary.org-details__summary').click();
  await expect(details.locator('.org-contacts__card-wrap .card')).toHaveCount(1);
  await expect(details.locator('.org-contacts__card-wrap .card .card__name')).toContainText('Ольга Викторовна');
  await expect(details.locator('.org-calls')).toHaveCount(0);
});

test('поиск по названию организации и по контактам', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

   await page.fill('.org-search__input', 'Ромашка');
   await page.click('.org-search button[type="submit"]');
   await expect(page.locator('.org-table__row')).toHaveCount(1);
   await expect(page.locator('.org-table__name').first()).toContainText('Ромашка');

   // Очистка поиска через нативный крестик поля type="search":
   // поле очищается, авто-применение сбрасывает q — таблица снова показывает
   // все организации области доступа администратора.
   await page.locator('.org-search__input').fill('');
   await page.waitForURL((url) => !url.searchParams.has('q'));
   expect((await orgNames(page)).length).toBeGreaterThan(1);

  await page.fill('.org-search__input', 'contact3@example.ru');
  await page.click('.org-search button[type="submit"]');
  const names = await orgNames(page);
  expect(names.length).toBeGreaterThan(0);
  expect(names.some((n) => n.includes('Вектор'))).toBe(true);
  expect(names.some((n) => n.includes('Ромашка'))).toBe(false);

  await page.fill('.org-search__input', 'неттакого');
  await page.click('.org-search button[type="submit"]');
  await expect(page.locator('.org-table__empty')).toHaveText('Ничего не найдено');
});

test('сортировка по названию, отрасли и дате следующего звонка', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  // По умолчанию (без параметров) — сортировка по названию А–Я
  let names = await orgNames(page);
  expect(names[0]).toContain('Вектор');

  await page.getByRole('link', { name: 'Название' }).click();
  names = await orgNames(page);
  expect(names[0]).toContain('Вектор');
  expect(names[names.length - 1]).toContain('Ромашка');

  await page.getByRole('link', { name: 'Сфера деятельности' }).click();
  const industries = await page.locator('.org-table__row td:nth-child(2)').allTextContents();
  expect([...industries].sort()).toEqual(industries);

  await page.getByRole('link', { name: 'Следующий звонок' }).click();
  const nextDates = await page.locator('.org-table__row td:nth-child(4)').allTextContents();
  const dateless = nextDates.filter((d) => d !== '—');
  expect(dateless.length).toBeGreaterThan(0);
});

test('наведение подсвечивает строку оттенком зебры, не убирая цвет', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  const row = page.locator('.org-table__row').first();
  await row.hover();
  const hoverBg = await row.evaluate((el) => getComputedStyle(el).backgroundColor);
  expect(hoverBg).toBe('rgb(207, 230, 242)'); // $color-table-stripe-hover (#cfe6f2)

  // Аккордеонная строка раскрытия не подсвечивается
  const detailsRow = page.locator('.org-details').first();
  await detailsRow.hover();
  const detailsBg = await detailsRow.evaluate((el) => getComputedStyle(el).backgroundColor);
  expect(detailsBg).toBe('rgba(0, 0, 0, 0)');
});

test('сортировочные заголовки — обычные кликабельные ссылки с указанием направления', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  const sortables = page.locator('.table__sortable');
  await expect(sortables).toHaveCount(4);
  await expect(sortables.first()).toBeVisible();
  // Активная колонка подсвечивается после клика
  await page.getByRole('link', { name: 'Название' }).click();
  await expect(page.locator('.table__sortable--active', { hasText: 'Название' })).toBeVisible();
});

test('аккордеон: раскрытие контактов организации и звонков', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  const romashka = page.locator('.org-table__row', { hasText: 'Ромашка' });
  const details = romashka.locator('xpath=./following-sibling::tr[1]').locator('.org-details__box');
  await details.locator('summary.org-details__summary').click();

  const cards = details.locator('.org-contacts__card-wrap .card');
  await expect(cards.first()).toBeVisible();
  // Иван Петрович (contact0) имеет заполненную заметку — карточка показывает «Заметка: …»
  const ivanCard = cards.filter({ hasText: 'Иван Петрович' }).first();
  await expect(ivanCard.locator('.card__name')).toContainText('Иван Петрович');
  await expect(ivanCard.locator('a[href^="tel:"]').first()).toBeVisible();
  await expect(ivanCard.locator('a[href^="mailto:"]').first()).toBeVisible();
  await expect(details.locator('[data-contact-edit]').first()).toBeVisible();
  await expect(ivanCard.locator('.card__meta', { hasText: 'Заметка' })).toBeVisible();

  // Единственная непустая заметка Ромашки — у звонка без контакта (-3д):
  // блок «Последний звонок» показывает заметку без контакта.
  await expect(details.locator('.org-calls__last')).toContainText('Нет ответа, перезвонить завтра');
  await expect(details.locator('.org-calls__contact')).toHaveCount(0);

  // «Все звонки»: в фикстурах Ромашка имеет несколько звонков (факты, план, рассылка).
  const allCalls = details.locator('.org-calls__all summary');
  await expect(allCalls).toHaveText(/Все звонки \(\d+\)/);
  await allCalls.click();
  const items = details.locator('.org-calls__item');
  const callsCount = await items.count();
  expect(callsCount).toBeGreaterThanOrEqual(3);

  // У каждого звонка справа — кнопка «Изменить» (открывает модальное окно
  // быстрого редактирования, change calls-crud)
  const editButtons = items.locator('button[data-call-edit]', { hasText: 'Изменить' });
  expect(await editButtons.count()).toBeGreaterThanOrEqual(3);
  await expect(editButtons.nth(0)).toBeVisible();

  const allText = await items.allTextContents();
  const combined = allText.join(' ');
  // Есть звонок с контактом Иван Петрович
  expect(combined).toContain('Иван Петрович');
  // Есть звонок с заметкой "Нет ответа, перезвонить завтра"
  expect(combined).toContain('Нет ответа, перезвонить завтра');
  // Хотя бы один звонок с контактной информацией (телефон/email)
  const withContact = items.filter({ has: page.locator('.org-calls__item-contact') });
  expect(await withContact.count()).toBeGreaterThanOrEqual(1);

  await details.locator('summary.org-details__summary').click();
  await expect(cards.first()).toBeHidden();
});

test('добавление контакта доступно в раскрытой секции', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  const romashka = page.locator('.org-table__row', { hasText: 'Ромашка' });
  const details = romashka.locator('xpath=./following-sibling::tr[1]').locator('.org-details__box');
  await details.locator('summary.org-details__summary').click();
  await expect(details.locator('a.org-contacts__add', { hasText: 'Добавить контакт' })).toBeVisible();
});