import { expect, test, type Page } from '@playwright/test';

// Панель организаций после change dashboard-stats-by-organization:
// статистика перенесена на домашнюю страницу /, на /dashboard — только
// таблица организаций (покрытие статистики — home-stats.spec.ts).

const loginSubmit = 'form[action="/login"] button[type="submit"]';

async function login(page: Page, email: string, password: string) {
  const login = email.split('@')[0];
  await page.goto('/login');
  await page.fill('input[name="_login"]', login);
  await page.fill('input[name="_password"]', password);
  await page.click(loginSubmit);
  await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toBeVisible();
}

test('гость редиректится с дашборда на вход', async ({ page }) => {
  await page.goto('/dashboard');
  await expect(page).toHaveURL(/\/login$/);
});

test('администратор видит панель без секции статистики', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  await expect(page.getByRole('heading', { name: 'Панель' })).toBeVisible();
  await expect(page.locator('.dashboard-head__greeting')).toHaveText('Вы вошли как администратор admin@b2b-crm.loc');
  await expect(page.locator('.stats__total')).toHaveCount(0);
  await expect(page.locator('.stats__figure')).toHaveCount(0);
});

test('менеджер видит панель своей области доступа без секции статистики', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  await page.goto('/dashboard');

  await expect(page.getByRole('heading', { name: 'Панель' })).toBeVisible();
  await expect(page.locator('.dashboard-head__greeting')).toHaveText('Вы вошли как менеджер manager@b2b-crm.loc');
  await expect(page.locator('.stats__total')).toHaveCount(0);
  await expect(page.locator('.stats__figure')).toHaveCount(0);
});