## 1. favicon

- [x] 1.1 Скопировать `handshake.ico` → `public/favicon.ico`
- [x] 1.2 Заменить `<link rel="icon" href="data:,">` на `<link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">` в `templates/base.html.twig`

## 2. Заголовки дашборда

- [x] 2.1 Удалить `<section class="dashboard-head">` (h1 + приветствие) и неиспользуемый `{% set userType %}` из `templates/home/dashboard.html.twig` (строки 6–13)
- [x] 2.2 В `templates/home/partials/_organizations_table.html.twig`: `<h2>Организации</h2>` → `<h1>Организации</h1>`
- [x] 2.3 В `assets/scss/base.scss` добавить `h1` синюю полосу `0.19em solid #20799e`, `padding-left: 0.3em` и цвет `#20799e` (по спеке `web-interface`)
- [x] 2.4 Удалить мёртвый CSS `.dashboard-head` / `.dashboard-head__greeting` из `assets/scss/pages/welcome.scss`

## 3. Репозиторий и контроллер

- [x] 3.1 Добавить в `OrganizationRepository::findForDashboard()` параметры `?bool $isActive = null, ?bool $isOptedOut = null` и WHERE-условия (`o.isActive = :isActive` / `o.isOptedOut = :isOptedOut`)
- [x] 3.2 Добавить `isActive` в `SQL_SORT_COLUMNS` и `optedOutAt` в `DATE_SORT_FIELDS` (`OrganizationRepository`)
- [x] 3.3 Удалить мёртвый параметр `$filter` из `HomeController::dashboard()` (line 66); читать `inactive` и `optout` из query-параметров
- [x] 3.4 Передать `inactive`/`optout` в `findForDashboard()` и в шаблон для подстановки в форму

## 4. Фильтры и колонки на дашборде

- [x] 4.1 Компактная форма фильтрации в одну строку: text input `q`, чекбоксы `inactive` («Неактивные») и `optout` («Отписавшиеся»), кнопка «Найти» — в `_organizations_table.html.twig` сразу после `<h1>Организации</h1>`
- [x] 4.2 Стилизовать чекбоксы и панель фильтров в дизайн-системе (`assets/scss/pages/dashboard-orgs.scss`)
- [x] 4.3 Обновить макрос `sort_url` (`_organizations_table.html.twig:9-11`): сохранять `inactive`/`optout` вместе с `q`/`highlight`
- [x] 4.4 Добавить колонку «Активна» (чекбокс `disabled`, отмечен = активна) в thead и tbody — в конец таблицы
- [x] 4.5 Добавить колонку «Дата отписки» (`optedOutAt` → `d.m.Y` или «—») в thead и tbody — в конец таблицы
- [x] 4.6 `colspan="4"` → `colspan="6"` в строке деталей (`_organizations_table.html.twig:81`)
- [x] 4.7 Сохранять значения фильтров между запросами (подстановка GET-параметров обратно в форму)

## 5. Очистка поиска

- [x] 5.1 `assets/js/dashboard-search.js`: при пустом поле отправлять форму без `q`, сохраняя `inactive`/`optout`; в URL не должно оставаться параметра `q`

## 6. Комбobox организаций

- [x] 6.1 Добавить `data-org-combobox` к `<select name="organization">` в `templates/organization_hide/list.html.twig`
- [x] 6.2 Добавить `data-org-combobox` к `<select name="organization">` в `templates/campaign/recipients.html.twig`
- [x] 6.3 Обернуть оба селекта в `.field` (или задать `position: relative` контейнеру), проверить позиционирование меню и flex-раскладку форм

## 7. Переименование стат-секций (домашняя страница)

- [x] 7.1 В `templates/home/index.html.twig`: `Звонков` → `Сделано звонков`, подписи → `Сегодня` / `За 7 дней` / `За 30 дней`
- [x] 7.2 `Ожидают` → `Ожидают звонка`, подписи → `Сегодня` / `За 7 дней` / `За 30 дней`
- [x] 7.3 `Просроченные` → `Просроченные звонки`, подписи → `Вчера` / `За 7 дней` / `За 30 дней`

## 8. Тесты

- [x] 8.1 `tests/Functional/Controller/HomeControllerTest.php` — новые подписи/секции, фильтры `inactive`/`optout`
- [x] 8.2 `tests/Functional/OrganizationHidingReadPathTest.php` — `homeFigure()` со скоупом по секции, новые подписи
- [x] 8.3 `tests/Functional/Controller/CallControllerTest.php:54`, `ContactControllerTest.php:41`, `OrganizationControllerTest.php:38,381` — h1 «Организации» вместо «Панель»
- [x] 8.4 `e2e/tests/home-stats.spec.ts` — новые подписи, скоуп `statItem` по `.stats-home__section--*`, снять проверку отсутствия «Сегодня»
- [x] 8.5 `e2e/tests/dashboard.spec.ts`, `e2e/tests/smoke.spec.ts` — заголовок «Организации», убрать проверки приветствия
- [x] 8.6 `e2e/tests/dashboard-organizations.spec.ts` — проверить индексы колонок; фильтры и сортировка новых колонок
- [x] 8.7 Проверить `composer cs:check && composer stan` — cs:check чисто; PHPStan 5 ошибок исправлены в задаче 9
- [x] 8.8 Проверить `make test` — все тесты зелёные (`php bin/phpunit`: 360/360; docker в среде недоступен, запуск локально)

## 9. Синхронизация дельт, чистка PHPStan и документация

- [x] 9.1 Добавить в дельту `dashboard` MODIFIED-блоки `Статистика просроченных звонков` и `Исключающая логика waiting-категорий` (шаги → «секция + период», имена сценариев сохранены)
- [x] 9.2 В дельте `web-interface` заменить пример «Обзвонено сегодня»/«Ждут обзвона» на «Сделано звонков»/«Ожидают звонка»
- [x] 9.3 `UserController:67,77` — убрать мёртвые null-сравнения после `(string)`-присваиваний
- [x] 9.4 `OrganizationHideController:112` — null-safe сбор email в сообщении
- [x] 9.5 `MailingService:399` — администратор без email пропускается с предупреждением в лог
- [x] 9.6 Удалить 2 устаревшие записи из `phpstan-baseline.neon` (`UserController`, `CallRepository`)
- [x] 9.7 `composer cs:check && composer stan` — 0 ошибок (`php bin/phpunit`: 361/361)
- [x] 9.8 Документировать `<title>Панель` и hero-`h1` в `design.md`
- [x] 9.9 Добавить проверку favicon (функциональный тест) и комбобокса (e2e)
- [x] 9.10 `npm run build` и прогон e2e. Итог триажа: падения — pre-existing и не связаны с изменением:
  - 5-секундный `timeout` в `playwright.config.ts` не вмещает многошаговые сценарии на медленном dev-сервере (лог 570 МБ) → таймауты на `login()` и кликах
  - сломанная логика тестов после change `panel-org-toggle-by-name`: `dashboard-organizations.spec.ts:239` ховерит скрытую строку `.org-details`; `calls-crud.spec.ts:100` кликает уже раскрытую `--expanded` строку и сворачивает её
  - `group-assignment.spec.ts:163` — `ReferenceError: Cannot access 'login' before initialization` (баг в самом тесте)
  - устаревшие фикстуры: `dashboard-organizations.spec.ts:267` ждёт старую последнюю заметку Ромашки (в фикстурах появился более новый отказ), `organization-groups.spec.ts:262` выбирает организацию без e-mail
  - загрязнение dev-БД упавшими прогонами: лишние `organization_hide` и организация `E2E Подсветка`; после восстановления состояния `home-stats.spec.ts` — 12/12, `dashboard-organizations.spec.ts` — 13/15, новые тесты фильтров/колонок/поиска и оба комбобокс-теста зелёные
- [x] 9.11 `openspec archive` после коммита и проверка синхронизации main-спек
