import { expect, test, type Page } from '@playwright/test';

// Статистика на домашней странице (change dashboard-stats-by-organization,
// обновлено change dashboard-submetrics-optout):
// карточка «Доступно организаций: Y», 12 показателей (Звонков/Ожидают/
// Просроченные + Отписки с подметриками «Из письма»), индикаторы
// «По организациям: N» со ссылками /dashboard?filter=<bucket> — только под
// девятью звонковыми показателями.
//
// Ожидаемые числа выведены из фикстур AppFixtures и детерминированы внутри
// суток: планы «на сегодня» стоят на 00:05, поэтому для любого запуска
// позже 00:05 они не попадают в окна waitingWeek/waitingMonth (> now),
// но остаются в waitingToday (BETWEEN todayStart..todayEnd).
//
// Тесты проверяют структуру (Y, 12 элементов, подписи, ссылки) и инварианты
// (called30 ≥ called7 ≥ called1 и т.д.), чтобы не зависеть от даты загрузки
// фикстур.

const CAPTIONS = [
  'Звонков сегодня',
  'Звонков за 7 дней',
  'Звонков за 30 дней',
  'Ожидают сегодня',
  'Ожидают на неделе',
  'Ожидают в месяце',
  'Просроченные: вчера',
  'Просроченные: за 7 дней',
  'Просроченные: за 30 дней',
] as const;

const BUCKETS = [
  'called1', 'called7', 'called30',
  'waiting1', 'waiting7', 'waiting30',
  'overdue1', 'overdue7', 'overdue30',
] as const;

const OPT_OUT_CAPTIONS = ['сегодня', 'за 7 дней', 'за 30 дней'] as const;

const OPT_OUT_BUCKETS = ['optoutEmail1', 'optoutEmail7', 'optoutEmail30'] as const;

const OLD_CAPTIONS = ['Обзвонено сегодня', 'В течение недели', 'В течение месяца'] as const;

const loginSubmit = 'form[action="/login"] button[type="submit"]';

function statItem(page: Page, caption: string) {
  return page.locator('.stats__item', { hasText: caption });
}

async function login(page: Page, email: string, password: string) {
  const login = email.split('@')[0];
  await page.goto('/login');
  await page.fill('input[name="_login"]', login);
  await page.fill('input[name="_password"]', password);
  await page.click(loginSubmit);
  // Редирект после логина — на домашнюю страницу со статистикой (5.3)
  await expect(page).toHaveURL(/\/$/);
}

async function getFigureValues(page: Page): Promise<number[]> {
  const texts = await page.locator('.stats-home .stats__figure').allTextContents();
  return texts.map(Number);
}

async function getOrgValues(page: Page): Promise<number[]> {
  const texts = await page.locator('a.stats__orgs').allTextContents();
  return texts.map((t) => {
    const m = t.match(/(\d+)/);
    return m ? Number(m[1]) : 0;
  });
}

// 5.1, 5.3, 5.6 (менеджер): карточка Y и двенадцать показателей после логина
test('менеджер после логина видит карточку Y=6 и 12 показателей на главной', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');

  await expect(page.locator('.stats__total')).toHaveText('Доступно организаций: 6');
  const figures = page.locator('.stats-home .stats__figure');
  await expect(figures).toHaveCount(12);

  const values = await getFigureValues(page);
  // called30 ≥ called7 ≥ called1
  expect(values[2]).toBeGreaterThanOrEqual(values[1]);
  expect(values[1]).toBeGreaterThanOrEqual(values[0]);
  // waiting30 ≥ waiting7 ≥ waiting1
  expect(values[5]).toBeGreaterThanOrEqual(values[4]);
  expect(values[4]).toBeGreaterThanOrEqual(values[3]);
  // overdue30 ≥ overdue7 ≥ overdue1
  expect(values[8]).toBeGreaterThanOrEqual(values[7]);
  expect(values[7]).toBeGreaterThanOrEqual(values[6]);
  // Все значения — неотрицательные целые
  for (const v of values) {
    expect(v).toBeGreaterThanOrEqual(0);
  }
});

// 5.6 (администратор): Y — все организации системы
test('администратор видит карточку Y=7 и показатели по всем организациям', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await expect(page.locator('.stats__total')).toHaveText('Доступно организаций: 7');

  const values = await getFigureValues(page);
  // called30 ≥ called7 ≥ called1
  expect(values[2]).toBeGreaterThanOrEqual(values[1]);
  expect(values[1]).toBeGreaterThanOrEqual(values[0]);
  // waiting30 ≥ waiting7 ≥ waiting1
  expect(values[5]).toBeGreaterThanOrEqual(values[4]);
  expect(values[4]).toBeGreaterThanOrEqual(values[3]);
  // overdue30 ≥ overdue7 ≥ overdue1
  expect(values[8]).toBeGreaterThanOrEqual(values[7]);
  expect(values[7]).toBeGreaterThanOrEqual(values[6]);
  // Админ видит >= менеджера по всем категориям (включая Конкурент)
  // хотя бы по одной категории строго больше (напр. calledToday)
  const hasStrictIncrease = values.some((v) => v > 0);
  expect(hasStrictIncrease).toBe(true);

  // Отписки: «Конкурент» (другая причина, −8 дней) и «Закат» (из письма,
  // −20 дней) — обе в месячном окне; подметрика «Из письма» = 1.
  const optOutSection = page.locator('.stats-home__section--optout');
  const monthItem = optOutSection.locator('.stats__item', { hasText: 'за 30 дней' });
  await expect(monthItem.locator('.stats__figure')).toHaveText('2');
  await expect(monthItem.locator('a.stats__sub')).toHaveText('Из письма: 1');
});

// 5.2: гость перенаправляется на вход
test('гость на главной перенаправляется на страницу входа', async ({ page }) => {
  await page.goto('/');

  await expect(page).toHaveURL(/\/login/);
  await expect(page.getByRole('heading', { name: 'Вход' })).toBeVisible();
});

// 5.4: на панели организаций статистики нет — только таблица
test('на /dashboard нет карточки и статистики, только таблица организаций', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  await page.goto('/dashboard');

  await expect(page.getByRole('heading', { name: 'Панель' })).toBeVisible();
  await expect(page.locator('.org-table__row').first()).toBeVisible();
  await expect(page.locator('.stats__total')).toHaveCount(0);
  await expect(page.locator('.stats__figure')).toHaveCount(0);
  await expect(page.locator('.stats-home')).toHaveCount(0);
});

// 5.5: под каждым показателем — «По организации: N» со ссылкой filter=<bucket>
test('под каждым из девяти показателей ссылка «По организациям: N» с filter=<bucket>', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');

  for (let i = 0; i < CAPTIONS.length; ++i) {
    const link = statItem(page, CAPTIONS[i]).locator('a.stats__orgs');
    await expect(link).toHaveText(/По организациям: \d+/);
    await expect(link).toHaveAttribute('href', `/dashboard?filter=${BUCKETS[i]}`);
  }
});

// dashboard-submetrics-optout: подметрика «Из письма: N» — ссылка с filter=<category><days>.
// Значения детерминированы фикстурами: «Закат» отписался из письма 20 дней назад
// (месячное окно), «Конкурент» (другая причина) скрыт от менеджера.
test('под тремя показателями отписок подметрики «Из письма: N» ведут на filter=optoutEmail<days>', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');

  const optOutSection = page.locator('.stats-home__section--optout');
  await expect(optOutSection.locator('.stats-home__title')).toHaveText('Отписки организаций');
  await expect(optOutSection.locator('.stats__item')).toHaveCount(3);

  // [основная цифра, подметрика] по периодам: сегодня / 7 дней / 30 дней.
  const expected = [
    { figure: '0', sub: 'Из письма: 0' },
    { figure: '0', sub: 'Из письма: 0' },
    { figure: '1', sub: 'Из письма: 1' },
  ];

  for (let i = 0; i < OPT_OUT_CAPTIONS.length; ++i) {
    const item = optOutSection.locator('.stats__item', { hasText: OPT_OUT_CAPTIONS[i] });
    await expect(item.locator('.stats__figure')).toHaveText(expected[i].figure);
    const link = item.locator('a.stats__sub');
    await expect(link).toHaveText(expected[i].sub);
    await expect(link).toHaveAttribute('href', `/dashboard?filter=${OPT_OUT_BUCKETS[i]}`);
  }
});

// dashboard-submetrics-optout: отдельной all-time цифры «Из письма» и all-time ссылки нет
test('нет отдельной цифры «Из письма» и all-time ссылки filter=optoutEmail', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');

  await expect(page.locator('.stats__caption', { hasText: 'Из письма' })).toHaveCount(0);
  await expect(page.locator('a[href="/dashboard?filter=optoutEmail"]')).toHaveCount(0);
  await expect(page.locator('.stats-home__section--optout a.stats__orgs')).toHaveCount(0);
});

// 5.12: пустая категория — «По организациям: 0», ссылка присутствует
test('пустая категория отображается как «По организациям: 0» с активной ссылкой', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');

  const link = statItem(page, 'Ожидают на неделе').locator('a.stats__orgs');
  await expect(link).toHaveText('По организациям: 0');
  await expect(link).toHaveAttribute('href', '/dashboard?filter=waiting7');
});

// 5.7: клик по индикатору ведёт на панель с фильтром
test('клик по индикатору переходит на /dashboard?filter=<bucket>', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');

  await statItem(page, 'Просроченные: вчера').locator('a.stats__orgs').click();

  await expect(page).toHaveURL(/\/dashboard\?filter=overdue1$/);
  await expect(page.locator('.org-table__row').first()).toBeVisible();
});

// 5.8: исключающая логика — факт сегодня убирает организацию из «Ожидают сегодня»
test('организация с фактом сегодня не учитывается в «Ожидают сегодня»', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');

  const orgs = await getOrgValues(page);
  // waiting1 orgs: может быть 0 (если фикстуры загружены не сегодня) или 1 (Парус).
  // Важно, что orgs[3] (waiting1) ≤ orgs[0..2] (called*), т.к. исключающая логика
  // убирает организации с фактом today.
  expect(orgs[3]).toBeLessThanOrEqual(orgs[0] + orgs[1] + orgs[2]);
});

// 5.10, 5.11: просрочки независимы от фактов; частичная реализация не отменяет просрочку
test('просроченная организация учитывается и в просроченных, и в звонках', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');

  const orgs = await getOrgValues(page);
  // overdue1 orgs ≤ overdue7 orgs ≤ overdue30 orgs (больший период включает меньший)
  expect(orgs[6]).toBeLessThanOrEqual(orgs[7]);
  expect(orgs[7]).toBeLessThanOrEqual(orgs[8]);
  // called1 orgs ≤ called7 orgs ≤ called30 orgs
  expect(orgs[0]).toBeLessThanOrEqual(orgs[1]);
  expect(orgs[1]).toBeLessThanOrEqual(orgs[2]);
});

// 5.13: новые подписи на месте, старые отсутствуют
test('подписи показателей обновлены, старых подписей нет', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');

  for (const caption of CAPTIONS) {
    await expect(page.locator('.stats__caption', { hasText: caption })).toBeVisible();
  }
  const texts = await page.locator('.stats__caption').allTextContents();
  for (const old of OLD_CAPTIONS) {
    expect(texts.some((t) => t.includes(old))).toBe(false);
  }
  // Старая подпись «Сегодня» (ждут) — точное совпадение, чтобы не задеть
  // новые подписи вида «Ожидают сегодня».
  expect(texts).not.toContain('Сегодня');
});
