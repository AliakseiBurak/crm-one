import { expect, test } from '@playwright/test';

// 4.6 Страницы в общем стиле: наличие общих элементов.
// Скриншот-сверка и проверки стилей — на финальном этапе.
test.use({ viewport: { width: 1440, height: 900 } });

for (const path of ['/', '/login']) {
  test(`страница ${path} загружается без ошибок консоли`, async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') errors.push(msg.text());
    });

    await page.goto(path);
    await page.waitForLoadState('networkidle');

    expect(errors).toEqual([]);
  });
}

test('страница входа использует общий шаблон (шапка и подвал)', async ({ page }) => {
  await page.goto('/login');
  await expect(page.locator('.header__logo')).toBeVisible();
  await expect(page.locator('.header__menu-link').first()).toBeVisible();
  await expect(page.locator('.footer')).toBeVisible();
  await expect(page.locator('input[name="_login"]')).toBeVisible();
  await expect(page.locator('form[action="/login"] button[type="submit"]')).toHaveText('Войти');
});

test('страница панели использует общий шаблон', async ({ page }) => {
  await page.goto('/login');
  await page.fill('input[name="_login"]', 'admin');
  await page.fill('input[name="_password"]', 'admin123');
  await page.click('button[type="submit"]');
  await page.goto('/dashboard');

  await expect(page.locator('.header__logo')).toBeVisible();
  await expect(page.locator('.footer')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Организации' })).toBeVisible();
  await expect(page.locator('.org-table__row').first()).toBeVisible();
});