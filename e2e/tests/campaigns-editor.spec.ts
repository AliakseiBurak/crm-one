import { expect, test, type Page } from '@playwright/test';
import { login } from '../helpers/auth';
import { setCampaignBody } from '../helpers/editor';

const editorContent = '[data-editor-surface] .campaign-editor__content';

type HtmlSignature = {
  elements: string[];
  text: string;
};

async function openNewCampaign(page: Page) {
  await login(page, 'admin@b2b-crm.loc', 'admin123');
  await page.goto('/campaigns/new');
  await expect(page.locator(editorContent)).toBeVisible();
}

async function readSourceBody(page: Page): Promise<string> {
  const toggle = page.locator('[data-editor-toggle-source]');
  if ((await toggle.getAttribute('aria-pressed')) !== 'true') {
    await toggle.click();
  }
  await expect(toggle).toHaveAttribute('aria-pressed', 'true');
  return page.locator('textarea[name="body"]').inputValue();
}

async function signatureOf(html: string, page: Page): Promise<HtmlSignature> {
  return page.evaluate((source) => {
    const doc = new DOMParser().parseFromString(source, 'text/html');
    const probe = document.createElement('div');
    const normalizeStyle = (value: string) => {
      probe.style.cssText = value;
      return Array.from(probe.style)
        .sort()
        .map((property) => `${property}: ${probe.style.getPropertyValue(property)}`)
        .join('; ');
    };
    const elements = Array.from(doc.body.querySelectorAll('*'))
      .filter((element) => !(element.tagName === 'P' && element.attributes.length === 0))
      .map((element) => {
        const attrs = Array.from(element.attributes)
          .sort((left, right) => left.name.localeCompare(right.name))
          .map((attribute) => {
            const value = attribute.name === 'style'
              ? normalizeStyle(attribute.value)
              : attribute.value;

            return `${attribute.name}=${value}`;
          })
          .join('|');
        const signature = `${element.tagName.toLowerCase()}${attrs ? `[${attrs}]` : ''}`;

        return signature;
      })
      .sort();

    return {
      elements,
      text: (doc.body.textContent ?? '').replace(/\s+/g, ' ').trim(),
    };
  }, html);
}

test('все разрешённые элементы и атрибуты переживают round-trip редактора', async ({ page }) => {
  await openNewCampaign(page);

  const source =
    '<p style="color: #111">A &amp; B &lt; C &#8364;&nbsp;D<br></p>' +
    '<h1 style="text-align: left">H1</h1>' +
    '<h2 style="text-align: left">H2</h2>' +
    '<h3 style="text-align: center">H3</h3>' +
    '<h4 style="text-align: right">H4</h4>' +
    '<h5 style="color: red">H5</h5>' +
    '<h6 style="color: blue">H6</h6>' +
    '<ul style="padding-left: 20px"><li style="color: red">Пункт</li></ul>' +
    '<ol style="margin-left: 10px"><li>Второй</li></ol>' +
    '<blockquote style="border-left-width: 2px">Цитата</blockquote>' +
    '<hr style="height: 2px">' +
    '<p><strong style="font-weight: bold">Ж</strong> <b style="color: blue">B</b> ' +
    '<em style="font-style: italic">К</em> <i style="color: green">I</i> ' +
    '<u style="text-decoration: underline">Ч</u> <s style="text-decoration: line-through">З</s> ' +
    '<del style="color: gray">D</del> <code style="background-color: #eee">C</code> ' +
    '<span style="color: navy">S</span> ' +
    '<a href="https://example.com/?a=1&amp;b=2" style="color: #00aa00">Ссылка</a></p>' +
    '<img src="https://example.com/photo.png" alt="A &amp; B &lt; C" width="320" height="180" style="display: block">' +
    '<div style="padding: 8px"><p>Блок</p></div>' +
    '<table style="width: 100%"><tbody><tr style="height: 24px">' +
    '<th colspan="2" rowspan="1" style="background-color: #f5f5f5">Шапка</th></tr>' +
    '<tr><td colspan="1" rowspan="1" style="text-align: center">Ячейка</td></tr></tbody></table>';

  const expectedHtml =
    '<p style="color: #111">A &amp; B &lt; C &#8364;&nbsp;D<br></p>' +
    '<h1 style="text-align: left">H1</h1>' +
    '<h2 style="text-align: left">H2</h2>' +
    '<h3 style="text-align: center">H3</h3>' +
    '<h4 style="text-align: right">H4</h4>' +
    '<h5 style="color: red">H5</h5>' +
    '<h6 style="color: blue">H6</h6>' +
    '<ul style="padding-left: 20px"><li style="color: red">Пункт</li></ul>' +
    '<ol style="margin-left: 10px"><li>Второй</li></ol>' +
    '<blockquote style="border-left-width: 2px">Цитата</blockquote>' +
    '<hr style="height: 2px">' +
    '<p><strong style="font-weight: bold">Ж</strong> <strong style="color: blue">B</strong> ' +
    '<em style="font-style: italic">К</em> <em style="color: green">I</em> ' +
    '<u style="text-decoration: underline">Ч</u> <s style="text-decoration: line-through">З</s> ' +
    '<s style="color: gray">D</s> <code style="background-color: #eee">C</code> ' +
    '<span style="color: navy">S</span> ' +
    '<a href="https://example.com/?a=1&amp;b=2" rel="noopener noreferrer nofollow" style="color: #00aa00" target="_blank">Ссылка</a></p>' +
    '<img src="https://example.com/photo.png" alt="A &amp; B &lt; C" width="320" height="180" style="display: block">' +
    '<div style="padding: 8px"><p>Блок</p></div>' +
    '<table style="width: 100%"><tbody><tr style="height: 24px">' +
    '<th colspan="2" rowspan="1" style="background-color: #f5f5f5">Шапка</th></tr>' +
    '<tr><td colspan="1" rowspan="1" style="text-align: center">Ячейка</td>'
    + '<td colspan="1" rowspan="1"></td></tr></tbody></table>';

  await setCampaignBody(page, source);
  await expect(page.locator(editorContent + ' table')).toBeVisible();
  await expect(page.locator(editorContent + ' div')).toBeVisible();
  await expect(page.locator(editorContent + ' img')).toHaveAttribute('height', '180');
  await expect(page.locator('[data-editor-command="heading"][data-level="1"]')).toBeVisible();

  const firstHtml = await readSourceBody(page);
  const first = await signatureOf(firstHtml, page);
  const expected = await signatureOf(expectedHtml, page);
  expect(first.elements).toEqual(expected.elements);
  expect(first.text).toContain('A & B < C € D');
  expect(first.text).toContain('Ссылка');
  expect(first.elements.some((element) => element.startsWith('pre'))).toBe(false);

  await setCampaignBody(page, firstHtml);
  const second = await signatureOf(await readSourceBody(page), page);
  expect(second).toEqual(first);
  expect(second.elements).not.toContain('thead');
  expect(second.elements).not.toContain('tfoot');
  expect(second.elements.some((element) => element.startsWith('colgroup'))).toBe(false);
});

test('таблица и изображение переживают переключение режимов редактора', async ({ page }) => {
  await openNewCampaign(page);

  await setCampaignBody(
    page,
    '<p>До таблицы</p>' +
      '<table><tr><td style="background-color: #eef">Ячейка</td></tr></table>' +
      '<p><img src="https://example.com/pic.png" alt="Пример" width="320"></p>',
  );

  await expect(page.locator(editorContent + ' table')).toBeVisible();
  await expect(page.locator(editorContent + ' table td')).toHaveText('Ячейка');
  await expect(page.locator(editorContent + ' img')).toBeVisible();
  await expect(page.locator(editorContent + ' img')).toHaveAttribute('src', 'https://example.com/pic.png');

  await page.locator('[data-editor-toggle-source]').click();
  const html = await page.locator('textarea[name="body"]').inputValue();
  expect(html).toContain('<table');
  expect(html).toContain('background-color');
  expect(html).toContain('Ячейка');
  expect(html).toContain('src="https://example.com/pic.png"');
  expect(html).toContain('width="320"');
  expect(html).toContain('alt="Пример"');
});

test('вставка изображения по https URL через диалог', async ({ page }) => {
  await openNewCampaign(page);

  await page.locator('[data-editor-insert-image]').click();
  await expect(page.locator('[data-editor-image-form]')).toBeVisible();
  await page.locator('[data-editor-image-url]').fill('https://example.com/photo.png');
  await page.locator('[data-editor-image-alt]').fill('Фото');
  await page.locator('[data-editor-image-width]').fill('300');
  await page.locator('[data-editor-image-insert]').click();

  await expect(page.locator('[data-editor-image-form]')).toBeHidden();
  // В форме уже лежит базовый шаблон с логотипом, поэтому ищем картинку,
  // которую вставил этот тест, а не единственную img.
  await expect(page.locator(editorContent + ' img[src="https://example.com/photo.png"]')).toBeVisible();

  await page.locator('[data-editor-toggle-source]').click();
  const html = await page.locator('textarea[name="body"]').inputValue();
  expect(html).toContain('src="https://example.com/photo.png"');
  expect(html).toContain('width="300"');
  expect(html).toContain('alt="Фото"');
});

test('недопустимый URL изображения отклоняется', async ({ page }) => {
  await openNewCampaign(page);

  await page.locator('[data-editor-insert-image]').click();
  await expect(page.locator('[data-editor-image-form]')).toBeVisible();
  await page.locator('[data-editor-image-url]').fill('javascript:alert(1)');
  await page.locator('[data-editor-image-insert]').click();

  await expect(page.locator('[data-editor-image-error]')).toBeVisible();
  await expect(page.locator('[data-editor-image-error]')).toContainText('https://');
  // Логотип базового шаблона в теле уже есть, поэтому проверяем, что не
  // добавилось именно изображение с недопустимым URL.
  await expect(page.locator(editorContent + ' img[src^="javascript:"]')).toHaveCount(0);
  await expect(page.locator('[data-editor-image-form]')).toBeVisible();

  await page.locator('[data-editor-image-cancel]').click();
  await expect(page.locator('[data-editor-image-form]')).toBeHidden();
});

test('форматирование в визуальном режиме попадает в исходный HTML', async ({ page }) => {
  await openNewCampaign(page);

  // Форма предзаполнена базовым шаблоном: набирать своё поверх него нельзя,
  // Ctrl+A выделил бы весь дефолт. Поэтому сначала заменяем тело своим.
  await setCampaignBody(page, '<p>Привет мир</p>');
  await page.locator(editorContent).click();
  await page.keyboard.press('Control+a');
  await page.locator('[data-editor-command="bold"]').click();
  await expect(page.locator(editorContent + ' strong')).toHaveText('Привет мир');

  await page.locator('[data-editor-toggle-source]').click();
  const html = await page.locator('textarea[name="body"]').inputValue();
  expect(html).toContain('<strong>Привет мир</strong>');
});

test('базовое тело письма переживает round-trip редактора', async ({ page }) => {
  await openNewCampaign(page);

  // Тело предзаполнено базовым шаблоном — setCampaignBody его не перезаписывает,
  // а нормализует так же, как любой введённый HTML.
  const prefilled = await readSourceBody(page);
  expect(prefilled).toContain('Отписаться от рассылки');

  await setCampaignBody(page, prefilled);
  const firstHtml = await readSourceBody(page);
  expect(firstHtml).toContain('logo.svg');
  expect(firstHtml).toContain('Отписаться от рассылки');
  expect(firstHtml).toContain('href="https://trainingcenter.by/catalog"');
  expect(firstHtml).toContain('href="tel:+375296848426"');
  expect(firstHtml).toContain('{{unsubscribe_url}}');
  // Раскладка 600px остаётся в шелле, в теле её нет.
  expect(firstHtml).not.toContain('width: 600px');

  const first = await signatureOf(firstHtml, page);
  // Без кавычек-ёлочек: они не часть проверяемого смысла, а текст письма
  // нормализуется редактором.
  expect(first.text).toContain('Центр Обучающих Технологий');
  expect(first.text).toContain('+375 (29) 684-84-26');
  expect(first.text).toContain('Отписаться от рассылки');
  expect(first.elements.some((element) => element.startsWith('img'))).toBe(true);
  expect(first.elements.some((element) => element.includes('href=tel:'))).toBe(true);

  // Повторный round-trip идемпотентен: футер, логотип и ссылки не теряются.
  await setCampaignBody(page, firstHtml);
  const second = await signatureOf(await readSourceBody(page), page);
  expect(second).toEqual(first);
});
