import { expect, test } from '@playwright/test';

// 4.2 Компоненты: наличие кнопок, шапки/подвала.
// Проверки стилей (цвета, градиенты, тени) вынесены на финальный этап.

async function loginAsAdmin(page: import('@playwright/test').Page) {
  await page.goto('/login');
  await page.fill('input[name="_username"]', 'admin@b2b-crm.loc');
  await page.fill('input[name="_password"]', 'admin123');
  await page.click('form[action="/login"] button[type="submit"]');
  await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toBeVisible();
}

test('primary-кнопка есть в hero welcome page', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/');
  await expect(page.locator('.hero .btn--primary')).toHaveCount(1);
});

test.describe('шапка', () => {
  test('логотип слева ведёт на главную; навигация гостя — «Войти»', async ({ page }) => {
    await page.goto('/');
    const logo = page.locator('.header__logo');
    await expect(logo).toHaveText('B2B Call CRM');
    await expect(logo).toHaveAttribute('href', '/');
    await expect(page.locator('.header__menu-link', { hasText: 'Войти' })).toBeVisible();
    await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toHaveCount(0);
  });

  test('нет зелёной полосы меню и CTA-пары «Позвонить»/«Создать»', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('.header__menu')).toHaveCount(0);
    await expect(page.locator('.cta-bar')).toHaveCount(0);
    await expect(page.locator('[data-modal-open]')).toHaveCount(0);
  });

  test('для вошедшего — кнопки «Создать организацию»/«Создать контакт» справа', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/');
    const createToggle = page.locator('[data-header-create-toggle]').first();
    await createToggle.click();
    const createMenu = page.locator('[data-header-create-menu]').first();
    await expect(createMenu.locator('.header-create__item', { hasText: 'Организацию' })).toHaveAttribute('href', '/organizations/new');
    await expect(createMenu.locator('.header-create__item', { hasText: 'Контакт' })).toHaveAttribute('href', '/contacts/new');
  });
});

test('подвал присутствует с копирайтом', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('.footer__note')).toBeVisible();
});