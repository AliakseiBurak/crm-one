import { expect, test } from '@playwright/test';
import { login } from '../helpers/auth';

test('администратор видит ссылку «Пользователи» в навигации', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.locator('[data-header-admin-toggle]').click();
  await expect(page.locator('.header-admin__item', { hasText: 'Пользователи' })).toBeVisible();
});

test('администратор открывает список пользователей', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/admin/users');

  await expect(page.getByRole('heading', { name: 'Пользователи' })).toBeVisible();
  await expect(page.locator('.table')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Новый пользователь' })).toBeVisible();
});

test('менеджер не видит ссылку «Пользователи» в навигации', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  await expect(page.locator('.header__menu-link', { hasText: 'Пользователи' })).toHaveCount(0);
});

test('менеджер получает 403 при попытке открыть список пользователей', async ({ page }) => {
  await login(page, 'manager@b2b-crm.loc', 'manager123');
  const response = await page.goto('/admin/users');
  expect(response?.status()).toBe(403);
});

test('неаутентифицированный пользователь перенаправляется на вход', async ({ page }) => {
  const response = await page.goto('/admin/users');
  expect(response?.status()).toBe(200);
  await expect(page).toHaveURL(/\/login/);
});
