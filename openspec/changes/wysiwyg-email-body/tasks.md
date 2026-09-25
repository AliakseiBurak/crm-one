## 1. Инфраструктура санитизации

- [x] 1.1 Добавить `symfony/html-sanitizer` и `twig/cssinliner-extra` в composer, создать `config/packages/html_sanitizer.yaml` (allowlist элементов/атрибутов, `max_input_length: 262144`, `allowed_link_schemes`, `allowed_media_schemes: [https]`); проверить `php bin/console lint:container` и `composer cs:check`
- [x] 1.2 Реализовать `src/Html/CampaignStyleAttributeSanitizer.php` (allowlist CSS-свойств; режет `position`, `z-index`, `behavior`, `expression()`, `url(javascript:)`); проверить `php bin/phpunit --filter CampaignStyleAttributeSanitizerTest` — тесты на сохранение `color`/`text-align` и удаление опасных свойств
- [x] 1.3 Реализовать `src/Service/CampaignBodySanitizer.php` (обёртка над `HtmlSanitizer`, метод `sanitize(string): string`); проверить `php bin/phpunit --filter CampaignBodySanitizerTest` — тесты: `script`/`onerror`/`iframe` удаляются, `table` с `colspan` и `img[src|alt|width|style]` сохраняются
- [x] 1.4 Подключить санитизацию в `CampaignController::applyRequest()` и ограничение длины тела (200 000) в валидацию сущности; проверить функциональным тестом create/update: скрипт вырезан, превышение лимита — 422 с ошибкой `body`, тело только из запрещённых элементов — 422 «Тело письма не содержит допустимого содержимого», рассылка не сохраняется

## 2. Токены и email-рендерер

- [ ] 2.1 Реализовать `src/Service/CampaignTokenFiller.php` (HTML-экранирование `ENT_QUOTES | ENT_SUBSTITUTE` для тела и прехедера, plain для темы); проверить `php bin/phpunit --filter CampaignTokenFillerTest` — тесты на `<` в названии организации и на токен внутри атрибута
- [ ] 2.2 Создать `templates/emails/campaign.html.twig` (doctype, шелл-таблица 600px, скрытый прехедер, футер, `<style>` с `|inline_css`) и `src/Service/CampaignEmailRenderer.php` (возвращает `{subject, html, text}`, nullable tracking-pixel URL); проверить `php bin/phpunit --filter CampaignEmailRendererTest` — тесты: doctype, ширина 600, инлайн-стили в атрибутах, экранированный прехедер, текстовая часть без HTML-тегов, отсутствие пикселя при `null`
- [ ] 2.3 Перевести `MailingService::sendEmail()` на `TemplatedEmail` через `CampaignEmailRenderer`; проверить обновлённый `MailingServiceTest` — письмо содержит html и text, токены экранированы, вложения и TO/CC не изменились

## 3. Предпросмотр письма

- [x] 3.1 Добавить `GET /campaigns/{id}/preview`: отдаёт готовый email-документ с демо-значениями токенов, без tracking-pixel, с CSP `sandbox`; проверить функциональным тестом: страница открывается для администратора и менеджера, содержит шелл 600px и демо-значения токенов, для несуществующей кампании — 404
- [x] 3.2 Добавить `POST /campaigns/preview` (CSRF-токен `campaign_preview`, JSON `{html}`, без записи в БД, лимит 200 000, санитизация несохранённого тела); проверить функциональным тестом: корректный CSRF — 200 с HTML и демо-токенами, неверный — 403, тело в БД не изменяется, скрипт вырезан, превышение лимита — 422
- [x] 3.3 Добавить на карточку кампании и в форму кнопку «Предпросмотр» и модалку (`campaign/_preview_modal.html.twig`, sandbox-iframe) + `assets/js/campaign-preview-modal.js`; проверить функциональным тестом наличие кнопки и контейнера модалки, `npm run build` собирается

## 4. WYSIWYG-редактор

- [ ] 4.1 Добавить пакеты TipTap 2 в `package.json`, создать `assets/js/campaign-editor.js` (тулбар: bold/italic/underline/strike, H2/H3, списки, ссылка, цвет, выравнивание; переключатель «HTML»; синхронизация скрытой `textarea[name="body"]` при submit и переключении) и стили в `assets/scss/pages/campaigns.scss`, подключить в `assets/app.js`; проверить `npm run build`
- [ ] 4.2 Добавить расширение глобального атрибута `style` для всех разрешённых узлов и марок, расширения table/row/cell/header и диалог вставки изображения по URL (URL, alt, ширина); проверить e2e-тестом round-trip: таблица и изображение переживают «исходный HTML → визуальный → исходный HTML», а недопустимый URL отклоняется
- [ ] 4.3 Обновить `templates/campaign/form.html.twig` (тулбар, режим исходного HTML, подсказка токенов рядом с редактором, кнопка предпросмотра) и `templates/campaign/show.html.twig` (рендер санитизированного HTML вместо `<pre>`, кнопка модалки); проверить функциональным тестом: карточка содержит форматированный HTML, подсказка токенов с `{{unsubscribe_url}}` присутствует

## 5. Данные, e2e и финализация

- [ ] 5.1 Переписать фикстуры кампаний на HTML-тела (абзацы, таблица со стилями, изображение внешним https-URL, токены); проверить загрузку фикстур и зелёные функциональные тесты кампаний
- [ ] 5.2 Добавить e2e-хелпер `setCampaignBody()` (режим исходного HTML → fill → обратно) и обновить 5 спек: `campaigns-create.spec.ts`, `campaigns-crud.spec.ts`, `campaigns-recipients.spec.ts`, `organization-groups-campaigns.spec.ts`, `call-result.spec.ts`; проверить `npx playwright test` (логин-тесты первыми, созданные данные удаляются)
- [ ] 5.3 Добавить e2e-кейсы: вставка изображения по внешнему URL и его отображение на карточке, открытие модалки предпросмотра, страница предпросмотра; проверить `npx playwright test`
- [ ] 5.4 Написать `adr/0014-wysiwyg-editor-and-email-rendering.md` (TipTap 2 vs GPL-редакторы, symfony/html-sanitizer + style-allowlist vs HTMLPurifier, единый рендерер для отправки и предпросмотров); проверить соответствие решений `design.md`
- [ ] 5.5 Финальная верификация: `composer cs:check`, `composer stan`, `php bin/phpunit`, `npm run build`, `npx playwright test`, `composer infection` — все зелёные
