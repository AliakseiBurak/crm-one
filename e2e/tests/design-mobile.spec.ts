import { expect, test } from '@playwright/test';

// 4.5 Мобильный кейс (576px): навигация и footer присутствуют.
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
