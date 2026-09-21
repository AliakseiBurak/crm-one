## 1. favicon

- [ ] 1.1 Скопировать `handshake.ico` → `public/favicon.ico`
- [ ] 1.2 Заменить `<link rel="icon" href="data:,">` на `<link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">` в `templates/base.html.twig`

## 2. Заголовки дашборда

- [ ] 2.1 Удалить `<section class="dashboard-head">` (h1 + приветствие) и неиспользуемый `{% set userType %}` из `templates/home/dashboard.html.twig` (строки 6–13)
- [ ] 2.2 В `templates/home/partials/_organizations_table.html.twig`: `<h2>Организации</h2>` → `<h1>Организации</h1>`
- [ ] 2.3 В `assets/scss/base.scss` добавить `h1` синюю полосу `0.19em solid #20799e`, `padding-left: 0.3em` и цвет `#20799e` (по спеке `web-interface`)
- [ ] 2.4 Удалить мёртвый CSS `.dashboard-head` / `.dashboard-head__greeting` из `assets/scss/pages/welcome.scss`

## 3. Репозиторий и контроллер

- [ ] 3.1 Добавить в `OrganizationRepository::findForDashboard()` параметры `?bool $isActive = null, ?bool $isOptedOut = null` и WHERE-условия (`o.isActive = :isActive` / `o.isOptedOut = :isOptedOut`)
- [ ] 3.2 Добавить `isActive` в `SQL_SORT_COLUMNS` и `optedOutAt` в `DATE_SORT_FIELDS` (`OrganizationRepository`)
- [ ] 3.3 Удалить мёртвый параметр `$filter` из `HomeController::dashboard()` (line 66); читать `inactive` и `optout` из query-параметров
- [ ] 3.4 Передать `inactive`/`optout` в `findForDashboard()` и в шаблон для подстановки в форму

## 4. Фильтры и колонки на дашборде

- [ ] 4.1 Компактная форма фильтрации в одну строку: text input `q`, чекбоксы `inactive` («Неактивные») и `optout` («Отписавшиеся»), кнопка «Найти» — в `_organizations_table.html.twig` сразу после `<h1>Организации</h1>`
- [ ] 4.2 Стилизовать чекбоксы и панель фильтров в дизайн-системе (`assets/scss/pages/dashboard-orgs.scss`)
- [ ] 4.3 Обновить макрос `sort_url` (`_organizations_table.html.twig:9-11`): сохранять `inactive`/`optout` вместе с `q`/`highlight`
- [ ] 4.4 Добавить колонку «Активна» (чекбокс `disabled`, отмечен = активна) в thead и tbody — в конец таблицы
- [ ] 4.5 Добавить колонку «Дата отписки» (`optedOutAt` → `d.m.Y` или «—») в thead и tbody — в конец таблицы
- [ ] 4.6 `colspan="4"` → `colspan="6"` в строке деталей (`_organizations_table.html.twig:81`)
- [ ] 4.7 Сохранять значения фильтров между запросами (подстановка GET-параметров обратно в форму)

## 5. Очистка поиска

- [ ] 5.1 `assets/js/dashboard-search.js`: при пустом поле отправлять форму без `q`, сохраняя `inactive`/`optout`; в URL не должно оставаться параметра `q`

## 6. Комбobox организаций

- [ ] 6.1 Добавить `data-org-combobox` к `<select name="organization">` в `templates/organization_hide/list.html.twig`
- [ ] 6.2 Добавить `data-org-combobox` к `<select name="organization">` в `templates/campaign/recipients.html.twig`
- [ ] 6.3 Обернуть оба селекта в `.field` (или задать `position: relative` контейнеру), проверить позиционирование меню и flex-раскладку форм

## 7. Переименование стат-секций (домашняя страница)

- [ ] 7.1 В `templates/home/index.html.twig`: `Звонков` → `Сделано звонков`, подписи → `Сегодня` / `За 7 дней` / `За 30 дней`
- [ ] 7.2 `Ожидают` → `Ожидают звонка`, подписи → `Сегодня` / `На неделе` / `В месяце`
- [ ] 7.3 `Просроченные` → `Просроченные звонки`, подписи → `Вчера` / `За 7 дней` / `За 30 дней`

## 8. Тесты

- [ ] 8.1 `tests/Functional/Controller/HomeControllerTest.php` — новые подписи/секции, фильтры `inactive`/`optout`
- [ ] 8.2 `tests/Functional/OrganizationHidingReadPathTest.php` — `homeFigure()` со скоупом по секции, новые подписи
- [ ] 8.3 `tests/Functional/Controller/CallControllerTest.php:54`, `ContactControllerTest.php:41`, `OrganizationControllerTest.php:38,381` — h1 «Организации» вместо «Панель»
- [ ] 8.4 `e2e/tests/home-stats.spec.ts` — новые подписи, скоуп `statItem` по `.stats-home__section--*`, снять проверку отсутствия «Сегодня»
- [ ] 8.5 `e2e/tests/dashboard.spec.ts`, `e2e/tests/smoke.spec.ts` — заголовок «Организации», убрать проверки приветствия
- [ ] 8.6 `e2e/tests/dashboard-organizations.spec.ts` — проверить индексы колонок; фильтры и сортировка новых колонок
- [ ] 8.7 Проверить `composer cs:check && composer stan` — без ошибок
- [ ] 8.8 Проверить `make test` — все тесты зелёные
