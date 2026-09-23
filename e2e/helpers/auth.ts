import { expect, type Page } from '@playwright/test';

export const loginSubmit = 'form[action="/login"] button[type="submit"]';

export async function login(page: Page, email: string, password: string) {
  const login = email.split('@')[0];
  await page.goto('/login');
  await page.fill('input[name="_login"]', login);
  await page.fill('input[name="_password"]', password);
  await page.click(loginSubmit);
  await expect(page.locator('.header__menu-link', { hasText: 'Панель' })).toBeVisible();
}

export async function logout(page: Page) {
  await page.goto('/logout');
}
