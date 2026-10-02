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
 * Тесты с загрузкой идут последовательно и каждый завершает свой прогон.
 * Незавершённый прогон, оставшийся от одного теста, другому не мешает: прогоны
 * независимы (design D12), и каждый тест убирает за собой только свои данные.
 *
 * Список прогонов живёт на отдельной вкладке «Результаты» — туда ведут и меню,
 * и все редиректы после действий.
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
 * Завершает только те прогоны, которые завёл сам тест, по имени файла.
 *
 * Уборка ограничена именами файлов теста и чужое не трогает: в списке лежат
 * прогоны, заведённые вручную.
 */
async function completeOwnRuns(page: Page, ownFilenames: string[]): Promise<void> {
  for (const filename of ownFilenames) {
    for (let attempt = 0; attempt < 10; attempt += 1) {
      await page.goto('/admin/import/results');
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

/**
 * Идентификатор прогона по имени его файла.
 *
 * Имя прогона — имя загруженного файла, и у каждого теста оно своё: искать по
 * имени надёжнее, чем брать первую строку списка, тем более что прогоны в нём
 * независимы и принадлежат разным тестам.
 */
async function runIdByFilename(page: Page, filename: string): Promise<string> {
  const href = await page
    .locator('tr', { hasText: filename })
    .first()
    .locator('a:has-text("Импортировать")')
    .getAttribute('href');
  const id = href?.match(/\/admin\/import\/(\d+)/)?.[1];
  if (!id) {
    throw new Error(`У прогона «${filename}» нет кнопки «Импортировать».`);
  }

  return id;
}

/**
 * Доводит прогон до конца по его адресу, сколько бы пакетов ни оставалось.
 */
async function finishRunById(page: Page, runId: string): Promise<void> {
  for (let attempt = 0; attempt < 10; attempt += 1) {
    await page.goto(`/admin/import/${runId}`);
    const approve = page.locator('button:has-text("Импортировать")');
    if ((await approve.count()) === 0) {
      return;
    }
    await approve.click();
    await page.waitForLoadState('networkidle');
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


    try {
      // Импорт доступен из выпадающего списка «⚙ Админ ▾».
      await page.goto('/dashboard');
      await page.click('.header__actions [data-header-admin-toggle]');
      await expect(page.locator('.header__actions [data-header-admin-menu] a', { hasText: 'Импорт организаций' })).toBeVisible();
      await page.click('.header__actions [data-header-admin-menu] a:has-text("Импорт организаций")');

      await expect(page).toHaveURL(/\/admin\/import\/results$/);
      await expect(page.getByRole('heading', { name: 'Импорт организаций' })).toBeVisible();
      await expect(page.locator('.tabs__item--active')).toHaveText('Результаты');

      // Форма загрузки — на своей вкладке; на «Результатах» только список.
      await page.locator('.tabs__item:has-text("CSV")').click();
      await expect(page.locator('.tabs__item--active')).toHaveText('CSV');

      await page.locator('form[action$="/admin/import/upload"] input[type="file"]').setInputFiles({
        name: filename,
        mimeType: 'text/csv',
        buffer: csvContent(firstName, secondName),
      });
      await page.click('button:has-text("Загрузить CSV")');

      // Загрузка возвращает в список: файл виден строкой, разбор не начался.
      await expect(page).toHaveURL(/\/admin\/import\/results$/);
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
      await expect(page).toHaveURL(/\/admin\/import\/results$/);
      const backRow = page.locator('tr', { hasText: filename }).first();
      await expect(backRow.locator('a:has-text("Импортировать")')).toBeVisible();
      await backRow.locator('a:has-text("Импортировать")').click();
      await expect(page.getByRole('heading', { name: 'Пошаговый импорт' })).toBeVisible();

      // Тот же адрес — тот же пакет: позиция берётся из прогресса прогона.
      const reviewUrl = page.url();
      await page.goto(reviewUrl);
      await expect(page.locator('.import-row')).toHaveCount(2);

      await page.click('button:has-text("Импортировать")');

      await expect(page).toHaveURL(/\/admin\/import\/results$/);
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
      await page.goto('/admin/import/results');
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


    try {
      // Форма загрузки — на вкладке CSV, список прогонов — на «Результатах».
      await page.goto('/admin/import');
      await page.locator('form[action$="/admin/import/upload"] input[type="file"]').setInputFiles({
        name: 'первая-загрузка.csv',
        mimeType: 'text/csv',
        buffer: csvContent(firstName, secondName),
      });
      await page.click('button:has-text("Загрузить CSV")');
      await expect(page).toHaveURL(/\/admin\/import\/results$/);

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
      // Прогон до завершения оставил бы строку с «Импортировать» в списке,
      // поэтому пакет утверждается, а данные удаляются.
      await completeOwnRuns(page, ownFilenames);
      await deleteOrganizationByName(page, firstName);
      await deleteOrganizationByName(page, secondName);
    }
  });

  test('менеджеру импорт недоступен', async ({ page }) => {
    await login(page, 'manager@b2b-crm.loc', 'manager123');

    const response = await page.goto('/admin/import/results');

    expect(response?.status()).toBe(403);
    // И пункта в меню «⚙ Админ ▾» у менеджера нет вовсе.
    await page.goto('/dashboard');
    await expect(page.locator('[data-header-admin]')).toHaveCount(0);
  });
});

// ---------------------------------------------------------------------------
// Импорт из ответа JSON (change add-organizations-json-import).
//
// Тесты живут в этом же файле, а не в отдельном: один файл и `describe.serial`
// дают одну последовательную очередь, а уборка в `finally` не даёт прогону,
// заведённому одним тестом, остаться незавершённым и помешать следующему.
// Прогоны независимы (design D12), поэтому уборка ограничена именами файлов
// теста и чужие прогоны не трогает.
// ---------------------------------------------------------------------------

/** Ответ модели: две организации, у первой — отрасль, город, УНП и контакт. */
function jsonResponse(firstName: string, secondName: string): Buffer {
  return Buffer.from(
    JSON.stringify({
      organizations: [
        {
          name: firstName,
          industry: 'ИТ-дистрибьютор',
          city: 'Минск',
          website: 'https://armis.by/',
          unp: '191000000',
          contacts: [{ name: 'Иван Петров', position: 'директор', phone: '+375171234567', isMain: true }],
          calls: [{ date: '25.08.2026', notes: 'Пока потребности нет' }],
        },
        { name: secondName, contacts: [], calls: [] },
      ],
    }),
    'utf-8',
  );
}

/**
 * Ответ без года. Схема его отвергает, и весь ответ отклоняется целиком: год
 * не восстанавливается и запись не прячется в заметку соседнего звонка.
 */
const ambiguousDateResponse = Buffer.from(
  JSON.stringify({
    organizations: [{ name: 'Неверная дата', calls: [{ date: '31.08', notes: 'Договорённость' }] }],
  }),
  'utf-8',
);

test.describe.serial('импорт организаций из ответа JSON', () => {
  // Полный цикл импорта (отправка → проверка → утверждение → уборка) не
  // укладывается в общий 15-секундный таймаут конфигурации.
  test.slow();

  test('администратор отправляет ответ, проверяет и утверждает пакет', async ({ page }) => {
    await login(page, 'admin@b2b-crm.loc', 'admin123');
    const firstName = uniqueName('Импорт JSON');
    const secondName = uniqueName('Импорт JSON');
    const filename = `${firstName}.json`;


    try {
      // Список прогонов открыт по умолчанию, а вкладки переключаются переходом
      // по адресу, а не показом блоков на месте.
      await page.goto('/admin/import/results');
      await expect(page.locator('.tabs__item--active')).toHaveText('Результаты');
      await page.locator('.tabs__item:has-text("JSON")').click();
      await expect(page).toHaveURL(/\/admin\/import\/json$/);
      await expect(page.locator('.tabs__item--active')).toHaveText('JSON');
      await expect(page.locator('input[type="file"][name="file"]')).toBeVisible();

      // Вставки на вкладке нет: ответ приходит файлом — из вкладки «LLM» или из
      // любого другого источника. Описание формата и схема — на «Промпт».
      await expect(page.locator('textarea')).toHaveCount(0);
      await expect(page.locator('a:has-text("Скачать JSON-схему")')).toHaveCount(0);
      await page.locator('.tabs__item:has-text("Промпт")').click();
      await expect(page).toHaveURL(/\/admin\/import\/prompt$/);
      await expect(page.locator('a:has-text("Скачать JSON-схему")')).toBeVisible();
      await page.locator('.tabs__item:has-text("JSON")').click();

      await page.locator('form[action$="/admin/import/json"] input[type="file"]').setInputFiles({
        name: filename,
        mimeType: 'application/json',
        buffer: jsonResponse(firstName, secondName),
      });
      await page.click('button:has-text("Загрузить JSON")');

      // Ответ сохранён, но разбор не начался: пакет появится только по
      // «Импортировать», как и на вкладке CSV.
      await expect(page).toHaveURL(/\/admin\/import\/results$/);
      await expect(page.getByRole('heading', { name: 'Пошаговый импорт' })).toHaveCount(0);
      const runRow = page.locator('tr', { hasText: filename }).first();
      await expect(runRow).toHaveCount(1);

      await runRow.locator('a:has-text("Импортировать")').click();
      await expect(page.getByRole('heading', { name: 'Пошаговый импорт' })).toBeVisible();
      await expect(page.locator('input[name="rows[1][name]"]')).toHaveValue(firstName);
      await expect(page.locator('input[name="rows[2][name]"]')).toHaveValue(secondName);

      // Поля, которых нет в выгрузке, пришли от ответа — значит, видны и
      // правятся: значение обязано можно было увидеть до сохранения.
      await expect(page.locator('input[name="rows[1][industry]"]')).toHaveValue('ИТ-дистрибьютор');
      await expect(page.locator('input[name="rows[1][city]"]')).toHaveValue('Минск');
      await expect(page.locator('input[name="rows[1][unp]"]')).toHaveValue('191000000');
      await expect(page.locator('label.import-nested__main input[value="1"]').first()).toBeChecked();
      await page.fill('input[name="rows[1][city]"]', 'Брест');

      await page.click('button:has-text("Импортировать")');

      await expect(page).toHaveURL(/\/admin\/import\/results$/);
      await expect(page.locator('.alert--success')).toContainText('Обработано строк в этом прогоне: 2');

      // Исправленный город и принесённый УНП сохранены, а не исходные.
      await page.goto(`/dashboard?q=${encodeURIComponent(firstName)}`);
      const row = page.locator('.org-table__row', { hasText: firstName }).first();
      await expect(row).toHaveCount(1);
      await row.locator('td').last().click();
      const details = row.locator('xpath=./following-sibling::tr[1]');
      await expect(details.locator('[data-organization-cell="city"]')).toHaveText('Брест');
      await expect(details.locator('[data-organization-cell="industry"]')).toHaveText('ИТ-дистрибьютор');
      await expect(details.locator('[data-organization-cell="unp"]')).toHaveText('191000000');
    } finally {
      await completeOwnRuns(page, [filename]);
      await deleteOrganizationByName(page, firstName);
      await deleteOrganizationByName(page, secondName);
    }
  });

  test('список из одних названий создаёт организации', async ({ page }) => {
    await login(page, 'admin@b2b-crm.loc', 'admin123');
    const firstName = uniqueName('Импорт JSON список');
    const secondName = uniqueName('Импорт JSON список');


    let runId: string | null = null;
    try {
      const filename = `${firstName}.json`;
      await page.goto('/admin/import/json');

      // У организации обязательно только название: такой ответ — полноценный
      // импорт, а не «почти пустой».
      await page.locator('form[action$="/admin/import/json"] input[type="file"]').setInputFiles({
        name: filename,
        mimeType: 'application/json',
        buffer: Buffer.from(
          JSON.stringify({ organizations: [{ name: firstName }, { name: secondName }] }),
          'utf-8',
        ),
      });
      await page.click('button:has-text("Загрузить JSON")');

      await expect(page).toHaveURL(/\/admin\/import\/results$/);
      runId = await runIdByFilename(page, filename);

      await page.goto(`/admin/import/${runId}`);
      await expect(page.getByRole('heading', { name: 'Пошаговый импорт' })).toBeVisible();
      await expect(page.locator('.import-row')).toHaveCount(2);
      await page.click('button:has-text("Импортировать")');

      await expect(page).toHaveURL(/\/admin\/import\/results$/);
      await expect(page.locator('.alert--success')).toContainText('Обработано строк в этом прогоне: 2');

      for (const name of [firstName, secondName]) {
        await page.goto(`/dashboard?q=${encodeURIComponent(name)}`);
        await expect(page.locator('.org-table__row', { hasText: name }).first()).toHaveCount(1);
      }
    } finally {
      // Прогон доводится по адресу: кнопки «Импортировать» в списке уже нет,
      // когда прогон завершён.
      if (runId !== null) {
        await finishRunById(page, runId);
      }
      await deleteOrganizationByName(page, firstName);
      await deleteOrganizationByName(page, secondName);
    }
  });

  test('неверный ответ отклоняется с отчётом и не создаёт прогон', async ({ page }) => {
    await login(page, 'admin@b2b-crm.loc', 'admin123');
    const name = uniqueName('Импорт JSON отказ');

    try {
      await page.goto('/admin/import/json');

      await page.locator('input[type="file"][name="file"]').setInputFiles({
        name: `${name}-битый.json`,
        mimeType: 'application/json',
        buffer: Buffer.from('{не json', 'utf-8'),
      });
      await page.click('button:has-text("Загрузить JSON")');

      // Файл не JSON — прогон не создан; править файл администратор будет у
      // себя, загрузка повторяется после правки.
      await expect(page.locator('.alert--error')).toContainText('не является корректным JSON');

      // Дата без года — тоже отвергнута: год не додумывается.
      await page.locator('input[type="file"][name="file"]').setInputFiles({
        name: `${name}.json`,
        mimeType: 'application/json',
        buffer: ambiguousDateResponse,
      });
      await page.click('button:has-text("Загрузить JSON")');

      await expect(page.locator('.alert--error')).toContainText('calls[0].date');

      await page.goto('/admin/import/results');
      await expect(page.locator('tr', { hasText: name })).toHaveCount(0);
    } finally {
      await completeOwnRuns(page, [`${name}.json`]);
      await deleteOrganizationByName(page, name);
    }
  });

  test('менеджеру вкладки JSON и LLM недоступны', async ({ page }) => {
    await login(page, 'manager@b2b-crm.loc', 'manager123');

    for (const url of ['/admin/import/results', '/admin/import/json', '/admin/import/json-schema', '/admin/import/llm', '/admin/import/prompt']) {
      const response = await page.goto(url);
      expect(response?.status(), `${url} должен быть закрыт от менеджера`).toBe(403);
    }
  });
});
