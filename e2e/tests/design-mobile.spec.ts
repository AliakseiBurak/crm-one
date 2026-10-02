import { expect, test } from '@playwright/test';

// Мобильный кейс (576px): навигация, footer, таблицы организаций.
test.use({ viewport: { width: 576, height: 800 } });

const loginSubmit = 'form[action="/login"] button[type="submit"]';

async function loginAsAdmin(page: import('@playwright/test').Page) {
  await page.goto('/login');
  await page.fill('input[name="_login"]', 'admin');
  await page.fill('input[name="_password"]', 'admin123');
  await page.click(loginSubmit);
  await expect(page).toHaveURL(/\/$/);
}

test('ссылки навигации шапки видимы', async ({ page }) => {
  await loginAsAdmin(page);
  await expect(page.locator('.header__logo')).toBeVisible();
  await expect(page.locator('[data-header-hamburger]')).toBeVisible();
});

// Панель повторяет правую часть шапки, и списки в ней раскрываются в потоке,
// а не поверх панели (change add-admin-menu-to-mobile-sidebar).
test('боковая панель: «⚙ Админ ▾» раскрывается в потоке', async ({ page }) => {
  await loginAsAdmin(page);

  await page.click('[data-header-hamburger]');
  const toggle = page.locator('.header__sidebar [data-header-admin-toggle]');
  await expect(toggle).toBeVisible();

  // В верхней строке на этом экране блока нет — он уехал в панель.
  await expect(page.locator('.header__actions [data-header-admin-toggle]')).toBeHidden();

  await toggle.click();
  const menu = page.locator('.header__sidebar .header-admin__menu');
  const items = page.locator('.header__sidebar .header-admin__item');
  await expect(items).toHaveCount(3);
  await expect(items.first()).toBeVisible();
  await expect(items.first()).toHaveText('Пользователи');
  await expect(menu).toHaveCSS('position', 'static');

  // Блок пользователя сдвинут вниз списком, а не перекрыт им.
  const menuBox = (await menu.boundingBox())!;
  const userBox = (await page.locator('.header__sidebar-user').boundingBox())!;
  expect(userBox.y).toBeGreaterThanOrEqual(menuBox.y + menuBox.height - 1);
});

test('подвал отображается на мобильном', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('.footer__note')).toBeVisible();
});

test('дашборд: нет горизонтальной прокрутки страницы, таблица в контейнере', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/dashboard');

  // Страница не выходит за пределы окна браузера
  const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
  const viewportWidth = page.viewportSize()!.width;
  expect(scrollWidth).toBeLessThanOrEqual(viewportWidth);

  // Таблица обёрнута в .table-wrap (прокручивается внутри контейнера)
  await expect(page.locator('#organizations .table-wrap')).toBeVisible();
  await expect(page.locator('#organizations .table--org')).toBeVisible();
});

test('дашборд: заголовки сортировки в одну строку', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/dashboard');

  // Каждый заголовок — ссылка, помещается в одну строку (white-space: nowrap)
  const headers = page.locator('#organizations .table--org th .table__sortable');
  const count = await headers.count();
  expect(count).toBeGreaterThanOrEqual(1);

  for (let i = 0; i < count; i++) {
    const header = headers.nth(i);
    const box = await header.boundingBox();
    // Высота заголовка ≤ одной строки (~20px с padding)
    expect(box!.height).toBeLessThanOrEqual(30);
  }
});

test('реестр скрытий: нет горизонтальной прокрутки страницы, кнопка видна', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/admin/hides');

  const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
  const viewportWidth = page.viewportSize()!.width;
  expect(scrollWidth).toBeLessThanOrEqual(viewportWidth);

  // Таблица обёрнута в .table-wrap
  await expect(page.locator('.table-wrap .table--org')).toBeVisible();

  // Кнопки «Показать» видимы (данные из фикстур)
  const buttons = page.locator('button:has-text("Показать")');
  expect(await buttons.count()).toBeGreaterThanOrEqual(1);
});
