import { expect, test, type Page } from '@playwright/test';
import { login } from '../helpers/auth';

// Постраничный вывод списков (change organizations-pagination,
// spec web-interface «Пагинация списков»).
//
// Пагинируются только панель организаций и реестр скрытых. Список групп и
// состав группы постранично не дробятся (spec organization-groups «Пагинация
// не применяется к списку групп и составу группы»).
//
// Покрытие >50 строк делается функциональными тестами на данных, созданных
// внутри теста: 51 импорт через UI в e2e не нужен. Здесь проверяется то, что
// видно на фикстурах: при малом наборе блока навигации нет, номер страницы
// отражается в URL, а номер за пределами диапазона не превращает таблицу в
// пустую.
//
// Число строк нигде не проверяется константой: параллельные тесты создают и
// удаляют организации, и такая проверка падала бы от чужих данных
// (конвенция репозитория, AGENTS.md).

function navBlock(page: Page) {
  return page.locator('nav.pagination');
}

function orgRows(page: Page) {
  return page.locator('.org-table__row');
}

/** Таблица не пуста: страница не «съехала» за пределы выборки. */
async function expectTableNotEmpty(page: Page) {
  await expect(orgRows(page).first()).toBeVisible();
}

test('блока навигации нет при малом наборе данных', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard');

  await expectTableNotEmpty(page);
  await expect(navBlock(page)).toHaveCount(0);
});

test('номер страницы за пределами диапазона открывает последнюю страницу', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard?page=999');

  // Канонический URL: страница приведена к открытой.
  await page.waitForURL(/[?&]page=1(&|$)/);
  await expectTableNotEmpty(page);
  await expect(navBlock(page)).toHaveCount(0);
});

test('некорректный номер страницы открывает первую страницу', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard?page=abc');

  await page.waitForURL(/[?&]page=1(&|$)/);
  await expectTableNotEmpty(page);

  await page.goto('/dashboard?page=0');
  await page.waitForURL(/[?&]page=1(&|$)/);
  await expectTableNotEmpty(page);
});

test('номер страницы не переносится в ссылку сортировки и в форму поиска', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/dashboard?page=999&sort=name&dir=asc');
  await page.waitForURL(/[?&]page=1(&|$)/);

  // Смена сортировки возвращает первую страницу: номер страницы в ссылке не
  // участвует, а значит и появляться не должен.
  const sortHref = await page.locator('a.table__sortable').first().getAttribute('href');
  expect(sortHref).not.toContain('page=');

  // Поиск возвращает первую страницу по той же причине.
  const form = page.locator('form.org-search');
  await expect(form.locator('input[name="page"]')).toHaveCount(0);
  await page.fill('.org-search__input', 'Ромашка');
  await page.click('.org-search button[type="submit"]');
  await expect(page).not.toHaveURL(/[?&]page=/);
});

test('список групп и реестр скрытых без блока навигации при малом наборе', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  await page.goto('/groups');
  await expect(navBlock(page)).toHaveCount(0);
  await expect(page.locator('tr[data-group-row]').first()).toBeVisible();

  await page.goto('/admin/hides');
  await expect(navBlock(page)).toHaveCount(0);
  await expect(page.locator('[data-hide-row]').first()).toBeVisible();
});

test('номер страницы зажимается на реестре скрытых и отбрасывается на списке групп', async ({ page }) => {
  await login(page, 'admin@b2b-crm.loc', 'admin123');

  // Список групп не пагинируется: `page` не значит здесь ничего и
  // отбрасывается, поэтому 999 не превращается в пустую страницу.
  await page.goto('/groups?page=999');
  await page.waitForURL('**/groups');
  await expect(page.locator('tr[data-group-row]').first()).toBeVisible();
  await expect(navBlock(page)).toHaveCount(0);

  // Реестр скрытых пагинируется: номер за диапазоном открывает последнюю
  // существующую страницу, и её номер отражается в URL.
  await page.goto('/admin/hides?page=999');
  await page.waitForURL(/[?&]page=1(&|$)/);
  await expect(page.locator('[data-hide-row]').first()).toBeVisible();
});