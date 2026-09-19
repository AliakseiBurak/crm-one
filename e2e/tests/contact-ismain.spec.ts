import { expect, test, type Locator, type Page } from '@playwright/test';

// Основной контакт (change contact-ismain-email-routing):
// назначение через модальное окно без перезагрузки, бейдж «Основной»
// в правом верхнем углу карточки, порядок карточек — по ID, подсветка:
// главный — очень светлый оттенок, при наведении (в т.ч. у главного) —
// рыжая подсветка; на форме организации — фон имени главного контакта.

const loginSubmit = 'form[action="/login"] button[type="submit"]';
const editModal = '[data-contact-edit-modal] .modal__window';
const MAIN_BG = 'rgb(253, 243, 234)'; // #fdf3ea
const HOVER_BG = 'rgb(250, 231, 219)'; // #fae7db
const WHITE_BG = 'rgb(255, 255, 255)';
const HOVER_TRANSITION_MS = 350; // transition: background-color 0.15s

async function login(page: Page) {
  await page.goto('/login');
  await page.fill('input[name="_login"]', 'admin');
  await page.fill('input[name="_password"]', 'admin123');
  await page.click(loginSubmit);
  await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toBeVisible();
}

// Маркер живёт до перезагрузки страницы (spec: модальные окна без перезагрузки).
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

// Раскрывает аккордеон «Ромашки» на дашборде (2 контакта в фикстурах).
// Таблица по умолчанию сортируется по имени организации (А–Я): первый ряд —
// не Ромашка, поэтому цепляемся за строку с нужным названием.
async function openRomashkaCards(page: Page): Promise<Locator> {
  await page.goto('/dashboard');
  const row = page.locator('.org-table__row', {
    has: page.locator('.org-table__name', { hasText: 'Ромашка' }),
  });
  const details = row.locator('xpath=./following-sibling::tr[1]').locator('.org-details__box');
  await details.locator('summary.org-details__summary').click();
  await expect(details.locator('.org-contacts__grid [data-contact-card-wrap]')).toHaveCount(2);

  return details;
}

// Открывает модальное окно контакта и ставит/снимает «Основной контакт».
async function setMainForCard(page: Page, details: Locator, index: number, checked: boolean) {
  const card = details.locator('[data-contact-card-wrap]').nth(index);
  await card.locator('[data-contact-edit]').click();
  const modal = page.locator(editModal);
  await expect(modal).toBeVisible();

  const checkbox = modal.locator('[data-contact-field="isMain"]');
  if (checked) {
    await checkbox.check();
  } else {
    await checkbox.uncheck();
  }
  await modal.getByRole('button', { name: 'Сохранить' }).click();
  await expect(modal).toBeHidden();
}

async function mainCardOf(grid: Locator): Promise<Locator> {
  return grid.locator('[data-contact-card-wrap]', {
    has: grid.page().locator('.card__badge--primary'),
  });
}

test('основной контакт назначается через модальное окно без перезагрузки', async ({ page }) => {
  await login(page);
  const details = await openRomashkaCards(page);
  const grid = details.locator('.org-contacts__grid');
  const cards = grid.locator('[data-contact-card-wrap]');
  const idsBefore = await cards.evaluateAll((els) => els.map((el) => el.getAttribute('data-contact-id')));

  await cards.first().locator('[data-contact-edit]').click();
  const modal = page.locator(editModal);
  await expect(modal).toBeVisible();
  await markAlive(page);
  await modal.locator('[data-contact-field="isMain"]').check();
  await modal.getByRole('button', { name: 'Сохранить' }).click();
  await expect(modal).toBeHidden();
  await expectStillSamePage(page);

  // Сетка перерисована, но порядок карточек — по ID, не меняется.
  const idsAfter = await cards.evaluateAll((els) => els.map((el) => el.getAttribute('data-contact-id')));
  expect(idsAfter).toEqual(idsBefore);

  // Бейдж «Основной» — на карточке выбранного контакта, с подсветкой.
  const mainCard = await mainCardOf(grid);
  await expect(mainCard).toHaveCount(1);
  await expect(mainCard).toHaveClass(/org-contacts__card-wrap--main/);
  await expect(mainCard.locator('.card__badge--primary')).toHaveText('Основной');
  await expect(cards.nth(1).locator('.card__badge--primary')).toHaveCount(0);

  // Бейдж в правом верхнем углу карточки.
  const cardBox = await mainCard.boundingBox();
  const badgeBox = await mainCard.locator('.card__badge--primary').boundingBox();
  expect(cardBox).not.toBeNull();
  expect(badgeBox).not.toBeNull();
  expect(badgeBox!.y).toBeLessThan(cardBox!.y + cardBox!.height / 2);
  expect(badgeBox!.x).toBeGreaterThan(cardBox!.x + cardBox!.width / 2);

  // Очистка состояния для других тестов.
  await setMainForCard(page, details, 0, false);
});

test('подсветка карточек: главный очень светлый, при наведении рыжий', async ({ page }) => {
  await login(page);
  const details = await openRomashkaCards(page);
  const grid = details.locator('.org-contacts__grid');
  await setMainForCard(page, details, 0, true);

  const background = async (card: Locator) =>
    card.locator('.card').evaluate((el) => getComputedStyle(el).backgroundColor);

  const mainCard = await mainCardOf(grid);
  await expect(mainCard).toHaveCount(1);
  const other = grid
    .locator('[data-contact-card-wrap]', { hasNot: page.locator('.card__badge--primary') })
    .first();

  // Главный — очень светлый оттенок, при наведении — рыжий, как и у остальных.
  expect(await background(mainCard)).toBe(MAIN_BG);
  await mainCard.hover();
  await page.waitForTimeout(HOVER_TRANSITION_MS);
  expect(await background(mainCard)).toBe(HOVER_BG);

  // Обычная карточка белая, при наведении — рыжая; главный возвращается к своему оттенку.
  await other.hover();
  await page.waitForTimeout(HOVER_TRANSITION_MS);
  expect(await background(other)).toBe(HOVER_BG);
  expect(await background(mainCard)).toBe(MAIN_BG);

  await page.mouse.move(0, 0);
  await page.waitForTimeout(HOVER_TRANSITION_MS);
  expect(await background(other)).toBe(WHITE_BG);

  await setMainForCard(page, details, 0, false);
});

test('форма организации подсвечивает имя главного контакта', async ({ page }) => {
  await login(page);
  const details = await openRomashkaCards(page);
  const firstCard = details.locator('[data-contact-card-wrap]').first();
  const contactName = (await firstCard.getAttribute('data-contact-name')) ?? '';
  await setMainForCard(page, details, 0, true);

  const orgId = (await details.locator('[data-contact-create]').getAttribute('data-org-id')) ?? '';
  await page.goto(`/organizations/${orgId}/edit`);

  const items = page.locator('.organization-contacts__list li');
  await expect(items).toHaveCount(2);

  // Порядок по ID: главный (id 1) — первым, метка «Основной» рядом с именем.
  const texts = await items.allTextContents();
  expect(texts[0]).toContain('Иван Петрович Иванов');
  expect(texts[1]).toContain('Иван Иванович Петров');
  await expect(items.first().locator('.organization-contacts__badge')).toHaveText('Основной');
  await expect(items.nth(1).locator('.organization-contacts__badge')).toHaveCount(0);

  // Фон имени главного контакта подсвечен.
  const mainName = items.first().locator('.organization-contacts__name--main');
  await expect(mainName).toHaveCount(1);
  await expect(mainName).toHaveText(contactName);
  await expect(items.nth(1).locator('.organization-contacts__name--main')).toHaveCount(0);

  // Очистка состояния для других тестов.
  await page.goto('/dashboard');
  await setMainForCard(page, await openRomashkaCards(page), 0, false);
});
