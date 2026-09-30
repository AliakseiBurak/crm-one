import { expect, test, type Page } from '@playwright/test';

import { login } from '../helpers/auth';
import { uniqueName } from '../helpers/test-data';

/**
 * Импорт организаций из CSV (change add-organizations-csv-import).
 *
 * Тесты создают свой файл, свой прогон и свои организации и убирают их за
 * собой: общая база между прогонами не меняется. Идентификаторы не
 * хардкодятся — прогон ищется по имени файла, организации — по названию.
 *
 * Тесты с загрузкой идут последовательно и каждый завершает свой прогон:
 * активный импорт в системе ровно один, поэтому незавершённый прогон, оставшийся
 * от одного теста, отверг бы загрузку другого (design D9).
 */

const CSV_HEADER =
  'Компания,Актуальный курс,Взаимодействия,Контакты,Следующий контакт,Для чего звонок?,Текущее состояние ,Учились у нас,Составление плана на год,\n';

function csvContent(firstName: string, secondName: string): Buffer {
  return Buffer.from(
    CSV_HEADER +
      `"${firstName}","Курс А","(25.08.2026) Пока потребности нет","Иван Петров, тел: +375171234567","08.06.2026","созвониться по КП","Договор подписан","Курс по переговорам","https://armis.by/",\n` +
      `"${secondName}",,"(17.10.2025) Выслан прайс","Мария Сидорова, тел: +375291112233",,,,,\n`,
    'utf-8',
  );
}

/**
 * Название чужого незавершённого прогона, если он есть.
 *
 * Активный импорт в системе ровно один, поэтому прогон, заведённый вручную,
 * отвергает загрузку файла тестом. Тест на этом останавливается с объяснением,
 * а не падает на таймауте ожидания.
 */
async function foreignActiveRun(page: Page, ownFilenames: string[]): Promise<string | null> {
  await page.goto('/admin/import');
  const rows = page.locator('tr');

  for (const row of await rows.all()) {
    const name = ((await row.textContent()) ?? '').replace(/\s+/g, ' ').trim();
    if (!name || ownFilenames.some((own) => name.includes(own))) {
      continue;
    }
    if ((await row.locator('a:has-text("Импортировать")').count()) > 0) {
      return name;
    }
  }

  return null;
}

/**
 * Завершает только те прогоны, которые завёл сам тест, по имени файла.
 *
 * Раньше здесь искалась первая кнопка «Импортировать» в списке, и уборка
 * утверждала любой незавершённый прогон подряд — включая чужой, заведённый
 * вручную. Активный импорт один, и такой прогон обычно и был первым в списке:
 * прогоны e2e продвигали чужой прогон по 20 строк, не создавая его. Теперь
 * хелпер ограничен именами файлов теста и чужое не трогает.
 */
async function completeOwnRuns(page: Page, ownFilenames: string[]): Promise<void> {
  for (const filename of ownFilenames) {
    for (let attempt = 0; attempt < 10; attempt += 1) {
      await page.goto('/admin/import');
      const row = page.locator('tr', { hasText: filename }).first();
      if ((await row.locator('a:has-text("Импортировать")').count()) === 0) {
        break;
      }
      await row.locator('a:has-text("Импортировать")').click();
      const approve = page.locator('button:has-text("Импортировать")');
      if ((await approve.count()) === 0) {
        break;
      }
      await approve.click();
      await page.waitForLoadState('networkidle');
    }
  }
}

async function deleteOrganizationByName(page: Page, name: string): Promise<void> {
  await page.goto('/dashboard');
  await page.fill('input[name="q"]', name);
  await page.click('button:has-text("Найти")');
  await page.waitForLoadState('networkidle');

  const row = page.locator('.org-table__row', { hasText: name }).first();
  if ((await row.count()) === 0) {
    return;
  }

  // Ссылка ведёт на карточку: ID берём из её адреса, а не хардкодим.
  const href = await row.locator('a.org-table__name-link').first().getAttribute('href');
  const id = href?.match(/\/organizations\/(\d+)\/edit/)?.[1];
  if (!id) {
    return;
  }

  await page.goto(`/organizations/${id}/delete`);
  await page.locator('form button[type="submit"]').click();
  await page.waitForLoadState('networkidle');
}

test.describe.serial('импорт организаций из CSV', () => {
  // Полный цикл импорта (загрузка → проверка → утверждение → уборка) не
  // укладывается в общий 15-секундный таймаут конфигурации.
  test.slow();

  test('администратор загружает CSV, проверяет и утверждает пакет', async ({ page }) => {
    await login(page, 'admin@b2b-crm.loc', 'admin123');
    const firstName = uniqueName('Импорт E2E');
    const secondName = uniqueName('Импорт E2E');
    const filename = `${firstName}.csv`;

    const foreign = await foreignActiveRun(page, [filename]);
    if (foreign) {
      test.skip(true, `активен чужой прогон «${foreign}»: импорт один, загрузка отвергнется (design D9)`);
    }

    try {
      // Импорт доступен из выпадающего списка «⚙ Админ ▾».
      await page.goto('/dashboard');
      await page.click('[data-header-admin-toggle]');
      await expect(page.locator('[data-header-admin-menu] a', { hasText: 'Импорт организаций' })).toBeVisible();
      await page.click('[data-header-admin-menu] a:has-text("Импорт организаций")');

      await expect(page).toHaveURL(/\/admin\/import$/);
      await expect(page.getByRole('heading', { name: 'Импорт организаций' })).toBeVisible();
      await expect(page.locator('.tabs__item--active')).toHaveText('CSV');

      await page.locator('form[action$="/admin/import/upload"] input[type="file"]').setInputFiles({
        name: filename,
        mimeType: 'text/csv',
        buffer: csvContent(firstName, secondName),
      });
      await page.click('button:has-text("Загрузить CSV")');

      // Загрузка возвращает в список: файл виден строкой, разбор не начался.
      await expect(page).toHaveURL(/\/admin\/import$/);
      await expect(page.getByRole('heading', { name: 'Пошаговый импорт' })).toHaveCount(0);
      const runRow = page.locator('tr', { hasText: filename }).first();
      await expect(runRow).toHaveCount(1);
      await expect(runRow.locator('a:has-text("Скачать")')).toBeVisible();
      await expect(runRow.locator('a:has-text("Импортировать")')).toBeVisible();
      await expect(runRow.locator('a:has-text("Перезагрузить")')).toBeVisible();

      // Пакет появляется только по кнопке «Импортировать».
      await runRow.locator('a:has-text("Импортировать")').click();
      await expect(page).toHaveURL(/\/admin\/import\/\d+$/);
      await expect(page.getByRole('heading', { name: 'Пошаговый импорт' })).toBeVisible();
      await expect(page.locator('.import-row')).toHaveCount(2);
      // Название организации лежит в поле ввода, поэтому ищется по значению.
      await expect(page.locator('input[name="rows[1][name]"]')).toHaveValue(firstName);
      await expect(page.locator('input[name="rows[2][name]"]')).toHaveValue(secondName);

      // В пакете нет ни поля города, ни поля годового плана. Замены файла на
      // странице пакета тоже нет — она запускается из списка.
      await expect(page.locator('input[name*="[city]"]')).toHaveCount(0);
      await expect(page.locator('input[name*="[annualPlan]"]')).toHaveCount(0);
      await expect(page.locator('form[action*="/replace"]')).toHaveCount(0);
      await expect(page.locator('body')).not.toContainText('Заменить файл');

      // Пакет отправляется кнопкой «Импортировать», а плановый звонок из
      // «Следующего контакта» отмечен «Планируемый».
      await expect(page.locator('button:has-text("Импортировать")')).toBeVisible();

      // Метка планового звонка есть у каждого звонка, но отмечен ровно один:
      // тот, что пришёл из «Следующего контакта». У первого контакта метка
      // своя — «Основной», это другой флажок.
      await expect(page.locator('label.import-nested__main')).toHaveCount(2);
      const planned = page.locator('label.import-nested__planned input[type="checkbox"]');
      expect(await planned.count()).toBeGreaterThan(0);
      await expect(page.locator('label.import-nested__planned input[type="checkbox"]:checked')).toHaveCount(1);
      await expect(page.locator('.import-row').first()
        .locator('label.import-nested__planned input[type="checkbox"]:checked')).toHaveCount(1);

      // «Назад к списку» — навигация, а не отмена прогона: он остаётся
      // незавершённым, поэтому «Импортировать» доступен и после возврата.
      await page.locator('a:has-text("Назад к списку")').click();
      await expect(page).toHaveURL(/\/admin\/import$/);
      const backRow = page.locator('tr', { hasText: filename }).first();
      await expect(backRow.locator('a:has-text("Импортировать")')).toBeVisible();
      await backRow.locator('a:has-text("Импортировать")').click();
      await expect(page.getByRole('heading', { name: 'Пошаговый импорт' })).toBeVisible();

      // Тот же адрес — тот же пакет: позиция берётся из прогресса прогона.
      const reviewUrl = page.url();
      await page.goto(reviewUrl);
      await expect(page.locator('.import-row')).toHaveCount(2);

      await page.click('button:has-text("Импортировать")');

      await expect(page).toHaveURL(/\/admin\/import$/);
      await expect(page.locator('.alert--success')).toContainText('Обработано строк в этом прогоне: 2');

      // Импортированная организация видна на панели. Колонки «Сайт» в таблице
      // нет (ADR-0015): сайт лежит в раскрытой строке в том виде, как сохранён.
      await page.goto(`/dashboard?q=${encodeURIComponent(firstName)}`);
      const row = page.locator('.org-table__row', { hasText: firstName }).first();
      await expect(row).toHaveCount(1);
      await expect(row.locator('a.org-table__name-link')).toHaveText(firstName);
      await expect(row.locator('th', { hasText: 'Сайт' })).toHaveCount(0);

      await row.locator('td').last().click();
      const details = row.locator('xpath=./following-sibling::tr[1]');
      await expect(details.locator('[data-organization-cell="website"]')).toHaveText('https://armis.by/');
      await expect(details.locator('[data-organization-cell="city"]')).toHaveText('—');

      // Прогон завершён: остаётся только «Скачать».
      await page.goto('/admin/import');
      const finishedRow = page.locator('tr', { hasText: filename }).first();
      await expect(finishedRow).toHaveCount(1);
      await expect(finishedRow.locator('a')).toHaveCount(1);
      await expect(finishedRow.locator('a:has-text("Скачать")')).toBeVisible();
      await expect(finishedRow.locator('a:has-text("Импортировать")')).toHaveCount(0);
      await expect(finishedRow.locator('a:has-text("Перезагрузить")')).toHaveCount(0);
    } finally {
      await completeOwnRuns(page, [filename]);
      await deleteOrganizationByName(page, firstName);
      await deleteOrganizationByName(page, secondName);
    }
  });

  test('замена файла импорта: отчёт до подтверждения и отмена', async ({ page }) => {
    await login(page, 'admin@b2b-crm.loc', 'admin123');
    const firstName = uniqueName('Импорт E2E');
    const secondName = uniqueName('Импорт E2E');
    const ownFilenames = ['первая-загрузка.csv', 'исправленная-загрузка.csv'];

    const foreign = await foreignActiveRun(page, ownFilenames);
    if (foreign) {
      test.skip(true, `активен чужой прогон «${foreign}»: импорт один, загрузка отвергнется (design D9)`);
    }

    try {
      await page.goto('/admin/import');
      await page.locator('form[action$="/admin/import/upload"] input[type="file"]').setInputFiles({
        name: 'первая-загрузка.csv',
        mimeType: 'text/csv',
        buffer: csvContent(firstName, secondName),
      });
      await page.click('button:has-text("Загрузить CSV")');
      await expect(page).toHaveURL(/\/admin\/import$/);

      // Замена запускается из списка: «Перезагрузить» ведёт на выбор файла.
      const runRow = page.locator('tr', { hasText: 'первая-загрузка.csv' }).first();
      await runRow.locator('a:has-text("Перезагрузить")').click();
      await expect(page).toHaveURL(/\/admin\/import\/\d+\/replace$/);

      // Кандидат: те же строки, иначе отчёт перечислил бы изменившиеся.
      await page.locator('input[type="file"][name="file"]').setInputFiles({
        name: 'исправленная-загрузка.csv',
        mimeType: 'text/csv',
        buffer: csvContent(firstName, secondName),
      });
      await page.click('button:has-text("Проверить замену")');

      await expect(page.getByRole('heading', { name: 'Замена файла' })).toBeVisible();
      await expect(page.getByRole('button', { name: /Продолжить с строки/ })).toBeVisible();
      await expect(page.getByRole('button', { name: 'Отмена' })).toBeVisible();
      // Отчёт не содержит сведений о давности источника: файл из браузера
      // метки времени не несёт.
      await expect(page.locator('body')).not.toContainText('свежее');
      await expect(page.locator('body')).not.toContainText('устарел');

      // Отчёт воспроизводим по адресу — серверной сессии нет.
      const replaceUrl = page.url();
      await page.goto(replaceUrl);
      await expect(page.getByRole('heading', { name: 'Замена файла' })).toBeVisible();

      await page.getByRole('button', { name: 'Отмена' }).click();
      await expect(page).toHaveURL(/\/admin\/import\/\d+$/);
      await expect(page.locator('.alert--success')).toContainText('Замена отменена');
    } finally {
      // Прогон до завершения оставил бы импорт незавершённым и заблокировал бы
      // следующую загрузку, поэтому пакет утверждается, а данные удаляются.
      await completeOwnRuns(page, ownFilenames);
      await deleteOrganizationByName(page, firstName);
      await deleteOrganizationByName(page, secondName);
    }
  });

  test('менеджеру импорт недоступен', async ({ page }) => {
    await login(page, 'manager@b2b-crm.loc', 'manager123');

    const response = await page.goto('/admin/import');

    expect(response?.status()).toBe(403);
    // И пункта в меню «⚙ Админ ▾» у менеджера нет вовсе.
    await page.goto('/dashboard');
    await expect(page.locator('[data-header-admin]')).toHaveCount(0);
  });
});
