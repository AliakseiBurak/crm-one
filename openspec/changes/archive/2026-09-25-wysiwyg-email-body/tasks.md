## 1. Инфраструктура санитизации

- [x] 1.1 Добавить `symfony/html-sanitizer` и `twig/cssinliner-extra` в composer, создать `config/packages/html_sanitizer.yaml` (allowlist элементов/атрибутов, `max_input_length: 262144`, `allowed_link_schemes`, `allowed_media_schemes: [https]`); проверить `php bin/console lint:container` и `composer cs:check`
- [x] 1.2 Реализовать `src/Html/CampaignStyleAttributeSanitizer.php` (allowlist CSS-свойств; режет `position`, `z-index`, `behavior`, `expression()`, `url(javascript:)`); проверить `php bin/phpunit --filter CampaignStyleAttributeSanitizerTest` — тесты на сохранение `color`/`text-align` и удаление опасных свойств
- [x] 1.3 Реализовать `src/Service/CampaignBodySanitizer.php` (обёртка над `HtmlSanitizer`, метод `sanitize(string): string`); проверить `php bin/phpunit --filter CampaignBodySanitizerTest` — тесты: `script`/`onerror`/`iframe` удаляются, `table` с `colspan` и `img[src|alt|title|width|height|style]` сохраняются
- [x] 1.4 Подключить санитизацию в `CampaignController::applyRequest()` и ограничение длины тела (200 000) в валидацию сущности; проверить функциональным тестом create/update: скрипт вырезан, превышение лимита — 422 с ошибкой `body`, тело только из запрещённых элементов — 422 «Тело письма не содержит допустимого содержимого», рассылка не сохраняется

## 2. Токены и email-рендерер

- [x] 2.1 Реализовать `src/Service/CampaignTokenFiller.php` (`htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE)` для тела, plain-заполнение прехедера с экранированием Twig и plain-заполнение темы); проверить `php bin/phpunit --filter CampaignTokenFillerTest` — тесты на `<` в названии организации и на токен внутри атрибута
- [x] 2.2 Создать `templates/emails/campaign.html.twig` (doctype, шелл-таблица 600px, скрытый прехедер, футер, `<style>` с `|inline_css`) и `src/Service/CampaignEmailRenderer.php` (возвращает `{subject, html, text}`, nullable tracking-pixel URL); проверить `php bin/phpunit --filter CampaignEmailRendererTest` — тесты: doctype, ширина 600, инлайн-стили в атрибутах, экранированный прехедер, текстовая часть без HTML-тегов, отсутствие пикселя при `null`
- [x] 2.3 Подключить `CampaignEmailRenderer` к `MailingService::sendEmail()` и собирать `Symfony\Component\Mime\Email` с `html()`, `text()`, вложениями и прежними TO/CC; проверить обновлённый `MailingServiceTest` — письмо содержит html и text, токены экранированы, вложения и TO/CC не изменились

## 3. Предпросмотр письма

- [x] 3.1 Добавить `GET /campaigns/{id}/preview`: отдаёт готовый email-документ с демо-значениями токенов, без tracking-pixel, с CSP `sandbox`; проверить функциональным тестом: страница открывается для администратора и менеджера, содержит шелл 600px и демо-значения токенов, для несуществующей кампании — 404
- [x] 3.2 Добавить `POST /campaigns/preview` (CSRF-токен `campaign_preview`, JSON `{html}`, без записи в БД, лимит 200 000, санитизация несохранённого тела); проверить функциональным тестом: корректный CSRF — 200 с HTML и демо-токенами, неверный — 403, тело в БД не изменяется, скрипт вырезан, превышение лимита — 422
- [x] 3.3 Добавить на карточку кампании и в форму кнопку «Предпросмотр» и модалку (`campaign/_preview_modal.html.twig`, sandbox-iframe) + `assets/js/campaign-preview-modal.js`; проверить функциональным тестом наличие кнопки и контейнера модалки, `npm run build` собирается

## 4. WYSIWYG-редактор

- [x] 4.1 Добавить пакеты TipTap 2 в `package.json`, создать `assets/js/campaign-editor.js` (тулбар: bold/italic/underline/strike, H1/H2/H3, списки, ссылка, цвет, выравнивание; переключатель «HTML»; синхронизация скрытой `textarea[name="body"]` при submit и переключении; code-block отключён) и стили в `assets/scss/pages/campaigns.scss`, подключить в `assets/app.js`; проверить `npm run build`
- [x] 4.2 Добавить расширение глобального атрибута `style` для всех разрешённых узлов и марок, расширения table/row/cell/header и диалог вставки изображения по URL (URL, alt, ширина); проверить e2e-тестом round-trip: таблица и изображение переживают «исходный HTML → визуальный → исходный HTML», а недопустимый URL отклоняется
- [x] 4.3 Обновить `templates/campaign/form.html.twig` (тулбар, режим исходного HTML, подсказка токенов рядом с редактором, кнопка предпросмотра) и `templates/campaign/show.html.twig` (рендер санитизированного HTML вместо `<pre>`, кнопка модалки); проверить функциональным тестом: карточка содержит форматированный HTML, подсказка токенов с `{{unsubscribe_url}}` присутствует

## 5. Данные, e2e и финализация

- [x] 5.1 Переписать фикстуры кампаний на HTML-тела (абзацы, таблица со стилями, изображение внешним https-URL, токены); проверить загрузку фикстур и зелёные функциональные тесты кампаний
- [x] 5.2 Добавить e2e-хелпер `setCampaignBody()` (режим исходного HTML → fill → обратно) и обновить 5 спек: `campaigns-create.spec.ts`, `campaigns-crud.spec.ts`, `campaigns-recipients.spec.ts`, `organization-groups-campaigns.spec.ts`, `call-result.spec.ts`; проверить `npx playwright test` (логин-тесты первыми, созданные данные удаляются)
- [x] 5.3 Добавить e2e-кейсы: вставка изображения по внешнему URL и его отображение на карточке, открытие модалки предпросмотра, страница предпросмотра; проверить `npx playwright test`
- [x] 5.4 Написать и синхронизировать `adr/0014-wysiwyg-editor-and-email-rendering.md` с решениями D3, D4, D5 и D10: TipTap 2, санитизация с запретом токенов в CSS, единый рендерер для отправки и предпросмотров, standalone-страницы обоих результатов отписки

## 6. Страницы отписки

- [x] 6.1 Заменить `unsubscribe/confirmed.html.twig` и `unsubscribe/already.html.twig` общим standalone-шаблоном `unsubscribe/layout.html.twig` (без `base.html.twig`: без шапки, подвала, ассетов и ссылок на сайт, с инлайн-стилями и `noindex, nofollow`); проверить функциональным тестом обе страницы

## 7. Исправления по верификации

- [x] 7.1 Синхронизировать `proposal.md`, delta-spec, `design.md` и ADR-0014: убрать противоречивый сценарий, добавить требование `MailingService`, описать standalone-отписку, запрет токенов в CSS и канонический round-trip контракт
- [x] 7.2 Свести sanitizer и TipTap к email-safe allowlist: добавить H1–H6, image `height`, стилизованный `div` и email-table serializer; не поддерживать `thead`, `tfoot` и устаревшие table-атрибуты; добавить один консолидированный e2e round-trip тест всех разрешённых элементов и атрибутов
- [x] 7.3 Запретить токены `{{...}}` в CSS-значениях, сохраняя остальные объявления `style`; покрыть unit и функциональным тестом
- [x] 7.4 Передать `{{unsubscribe_url}}` при заполнении прехедера; покрыть renderer-тестом и усилить проверку инлайна CSS
- [x] 7.5 Добавить функциональные тесты update-сценариев санитизации, лимита и запрещённого содержимого без изменения сохранённого тела
- [x] 7.6 Вернуть `setCampaignBody()` в визуальный режим и обеспечить cleanup всех создаваемых e2e-данных в `finally`, включая клон, группу и membership; удалить мутацию общей failed-фикстуры
- [x] 7.7 Финальная нетестовая верификация: проверить связность proposal/spec/design/tasks/ADR, выполнить `openspec validate "wysiwyg-email-body" --type change --strict`; тестовые команды не запускать по решению пользователя

## 8. Исправления по результатам проверки

- [x] 8.1 Очищать test-env кэш Symfony через `BOOTSTRAP_CLEAR_CACHE_ENV`, чтобы функциональные тесты видели актуальный allowlist санитайзера
- [x] 8.2 Отключить TipTap code-block, удалить `pre` из allowlist, добавить кнопку H1 и синхронизировать sanitizer с сериализуемыми TipTap-атрибутами ссылок и изображений
- [x] 8.3 Переписать консолидированный e2e round-trip тест как сравнение канонических наборов элементов и атрибутов вместо буквальных CSS-строк и полного DOM-дерева
- [x] 8.4 Синхронизировать proposal, delta-spec, design и ADR-0014 с решением по code-block, H1, прехедеру и серверному multibyte-лимиту
- [x] 8.5 Переиспользовать общий `deleteCampaign()` в `call-result.spec.ts`
- [x] 8.6 Проверить исправления целевыми PHPUnit/Playwright-тестами и quality-гейтами
