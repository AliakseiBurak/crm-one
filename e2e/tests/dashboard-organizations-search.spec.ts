import { expect, test } from '@playwright/test';
import { login } from '../helpers/auth';

// Поиск организаций панели уходит по кнопке «Найти» (change
// organizations-pagination: автоsubmit по мере ввода удалён, тяжёлая
// выборка перезапрашивалась на каждый ввод и после пагинации стала бы
// ещё и сбрасывать номер страницы). Enter в поле работает нативно —
// это поведение самой GET-формы.

const submitSearch = '.org-search button[type="submit"]';

async function orgNames(page: import('@playwright/test').Page): Promise<string[]> {
  return page.locator('.org-table__name').allTextContents();
}

async function rowNames(page: import('@playwright/test').Page): Promise<string[]> {
  return page.locator('.org-table__row .org-table__name-link').allTextContents();
}

test('поиск по названию организации и по контактам', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');
  const before = await rowNames(page);

  await page.fill('.org-search__input', 'Ромашка');
  await page.click(submitSearch);
  await expect(page.locator('.org-table__row')).toHaveCount(1);
  await expect(page.locator('.org-table__name').first()).toContainText('Ромашка');

  await page.locator('.org-search__input').fill('');
  await page.click(submitSearch);
  await expect(page).not.toHaveURL(/[?&]q=/);
  expect((await orgNames(page)).length).toBeGreaterThan(1);
  expect(await rowNames(page)).toEqual(before);

  await page.fill('.org-search__input', 'contact3@example.ru');
  await page.click(submitSearch);
  const names = await orgNames(page);
  expect(names.length).toBeGreaterThan(0);
  expect(names.some((n) => n.includes('Вектор'))).toBe(true);
  expect(names.some((n) => n.includes('Ромашка'))).toBe(false);

  await page.fill('.org-search__input', 'неттакого');
  await page.click(submitSearch);
  await expect(page.locator('.org-table__empty')).toHaveText('Ничего не найдено');
});

test('ввод текста без нажатия «Найти» не меняет таблицу', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  const before = await rowNames(page);
  const urlBefore = page.url();

  await page.fill('.org-search__input', 'Ромашка');

  // Таблица не перерисовывается, поискового запроса в URL нет.
  expect(await rowNames(page)).toEqual(before);
  expect(page.url()).toBe(urlBefore);

  await page.click(submitSearch);
  await expect(page).toHaveURL(/[?&]q=/);
  await expect(page.locator('.org-table__row')).toHaveCount(1);
});

test('фильтры «Неактивные» и «Отписавшиеся»: пересечение и сохранение при сортировке', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  await page.check('input[name="inactive"]');
  await page.click(submitSearch);
  await expect(page.locator('.org-table__row')).toHaveCount(1);
  await expect(page.locator('.org-table__name').first()).toContainText('Горизонт');

  await expect(page.locator('input[name="inactive"]')).toBeChecked();
  await expect(page.locator('a.table__sortable').first()).toHaveAttribute('href', /inactive=1/);

  await page.getByRole('link', { name: 'Активна' }).click();
  await expect(page.locator('input[name="inactive"]')).toBeChecked();
  await expect(page.locator('.org-table__row')).toHaveCount(1);

  await page.uncheck('input[name="inactive"]');
  await page.check('input[name="optout"]');
  await page.click(submitSearch);
  const names = await orgNames(page);
  expect(names.length).toBe(2);
  expect(names.some((n) => n.includes('Конкурент'))).toBe(true);
  expect(names.some((n) => n.includes('Закат'))).toBe(true);

  await page.check('input[name="inactive"]');
  await page.click(submitSearch);
  await expect(page.locator('.org-table__empty')).toHaveText('Ничего не найдено');
});

test('очистка поиска сохраняет фильтры и убирает q из URL', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  await page.check('input[name="inactive"]');
  await page.fill('.org-search__input', 'Горизонт');
  await page.click(submitSearch);
  await expect(page.locator('.org-table__row')).toHaveCount(1);
  await expect(page).toHaveURL(/inactive=1/);

  // Крестик очистки поля сам по себе ничего не перезапрашивает.
  await page.locator('.org-search__input').fill('');
  await expect(page).toHaveURL(/q=/);
  await page.click(submitSearch);
  await expect(page).not.toHaveURL(/[?&]q=/);

  await expect(page.locator('input[name="inactive"]')).toBeChecked();
  await expect(page.locator('.org-table__row')).toHaveCount(1);
  await expect(page.locator('.org-table__name').first()).toContainText('Горизонт');
});

test('поиск сохраняет выбранную сортировку', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard?sort=optedOutAt&dir=desc');

  await expect(page.locator('form.org-search input[name="sort"]')).toHaveValue('optedOutAt');
  await expect(page.locator('form.org-search input[name="dir"]')).toHaveValue('desc');

  await page.fill('.org-search__input', 'Конкурент');
  await page.click(submitSearch);

  await expect(page).toHaveURL(/sort=optedOutAt/);
  await expect(page).toHaveURL(/dir=desc/);
  await expect(page.locator('form.org-search input[name="sort"]')).toHaveValue('optedOutAt');
  await expect(page.locator('.org-table__row')).toHaveCount(1);
});