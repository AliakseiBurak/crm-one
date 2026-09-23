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
