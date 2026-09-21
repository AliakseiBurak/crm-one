## Context

- **favicon**: `handshake.ico` (32x32 ICO) лежит в корне repo; `public/favicon.ico` отсутствует; `base.html.twig:7` содержит заглушку `<link rel="icon" href="data:,">`
- **Dashboard**: `<h1>Панель</h1>` и приветствие в `dashboard.html.twig:10-11`; неиспользуемый `{% set userType %}` в строке 6; `<h2>Организации</h2>` в `_organizations_table.html.twig:25`
- **Фильтры дашборда**: таблица организаций имеет поиск `q` по названию/контактам, но нет фильтров по isActive/isOptedOut. Контроллер `HomeController::dashboard()` читает мёртвый параметр `$filter` (line 66) но не использует его
- **Колонки таблицы**: 4 колонки (Название, Отрасль, Последний звонок, Следующий звонок); нет isActive и optedOutAt; `colspan="4"` у строки деталей (`_organizations_table.html.twig:81`)
- **Поиск**: `dashboard-search.js:16-18` при пустом поле уходит на `form.action`, теряя все параметры запроса
- **Комбobox**: `data-org-combobox` используется в 3 шаблонах; 2 шаблона (hide list, campaign recipients) не имеют его; оба целевых селекта не обёрнуты в `.field`, а `.org-combobox__menu` позиционируется абсолютно относительно `.field { position: relative }` (`form-field.scss:5-6`)
- **Стат-секции**: на домашней странице (`index.html.twig`) секции «Звонков», «Ожидают», «Просроченные» — заголовки и подписи содержат повторы слов
- **h1**: в `base.scss:38-39` у `h1` нет синей полосы и синего цвета, хотя `web-interface` spec требует полосу для `h1`
- **Спеки**: `dashboard` spec перечисляет девять подписей и запрещает «Сегодня» (spec.md:416-429), а также фиксирует заголовки секций «Звонков», «Ожидают», «Просроченные» (spec.md:456,468); дельта изменения приводит требования в соответствие

## Goals

### Goals:
- Добавить favicon.ico в `public/` + ссылку в `base.html.twig`
- Удалить `<h1>Панель</h1>` и приветствие, `<h2>Организации</h2>` → `<h1>Организации</h1>`
- Добавить фильтры «Неактивные» и «Отписавшиеся» (чекбоксы) на дашборде
- Добавить сортируемые колонки «Активна» и «Дата отписки» в таблицу организаций
- Добавить `data-org-combobox` во все select организаций
- Переименовать стат-секции на домашней странице; подписи называют только период
- Добавить синюю полосу к `h1` (по спеке `web-interface`)

### Non-Goals:
- Фильтр по отрасли (industry) — не реализуется
- Фильтры на странице скрытия организаций — убраны из scope
- Изменение логики скрытия организаций
- Изменение дизайн-системы, кроме полосы `h1` по действующей спеке
- Drill-down по стат-ссылкам `?filter=...` — ссылки остаются, контроллер параметр не читает
- DTO `DashboardOrganizationRow` не меняется — шаблон читает `row.organization.*`

## Decisions

### Decision 1: favicon
- Файл: скопировать `handshake.ico` → `public/favicon.ico`
- Подключение: `<link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">` в `<head>` base.html.twig

### Decision 2: Заголовки дашборда
- `templates/home/dashboard.html.twig`: удалить весь `<section class="dashboard-head">` (h1 + приветствие, строки 8–13) и неиспользуемый `{% set userType %}` (строка 6)
- `templates/home/partials/_organizations_table.html.twig:25`: `<h2>Организации</h2>` → `<h1>Организации</h1>`
- Форма фильтрации размещается сразу после `<h1>Организации</h1>`, перед таблицей
- `h1` получает синюю полосу `0.19em solid #20799e`, `padding-left: 0.3em` и цвет `#20799e` в `assets/scss/base.scss` (требование `web-interface` spec)
- Мёртвый CSS `.dashboard-head` / `.dashboard-head__greeting` (`welcome.scss:53-67`) удаляется

### Decision 3: Фильтры дашборда (isActive + isOptedOut)
- Компактная панель фильтров над таблицей, в одну строку с поиском:
  ```
  [Поиск по названию и контактам____] [☐ Неактивные] [☐ Отписавшиеся] [Найти]
  ```
- Чекбоксы стилизуются в дизайн-системе; поле поиска сохраняет `type="search"` и авто-применение
- Реализация: GET-параметры `q`, `inactive`, `optout`, кнопка «Найти»
- Чекбоксы:
  - `inactive=1` — показать только неактивные (`o.isActive = false`)
  - `optout=1` — показать только отписавшиеся (`o.isOptedOut = true`)
  - Оба отсутствуют — показать все
  - Оба отмечены — пересечение: неактивные и отписавшиеся
- Контроллер: `HomeController::dashboard()` — чтение `inactive` и `optout` из query-параметров, передача в `findForDashboard()`
- Репозиторий: `OrganizationRepository::findForDashboard()` — добавить `?bool $isActive = null, ?bool $isOptedOut = null`; WHERE `AND o.isActive = :isActive` / `AND o.isOptedOut = :isOptedOut` при непустых значениях
- Мёртвый параметр `$filter` (HomeController:66): удалить, заменить на `inactive`/`optout`
- Макрос `sort_url` (`_organizations_table.html.twig:9-11`) сохраняет `inactive`/`optout` вместе с `q`/`highlight`
- Форма сохраняет отмеченные фильтры между запросами

### Decision 4: Колонки таблицы
- Добавить в конец таблицы две новые сортируемые колонки:
  - **«Активна»**: чекбокс (отмечен = активна), `disabled`, с доступным именем
  - **«Дата отписки»**: `optedOutAt` (дата `d.m.Y` или «—»)
- Колонки добавляются после «Следующий звонок» — существующие индексы `td` в e2e не сдвигаются
- `colspan="4"` строки деталей (`_organizations_table.html.twig:81`) → `colspan="6"`
- Сортировка: `isActive` → `SQL_SORT_COLUMNS`, `optedOutAt` → `DATE_SORT_FIELDS` (NULL в конец, вторичный ключ name ASC)
- DTO `DashboardOrganizationRow` не меняется: шаблон читает `row.organization.isActive`, `row.organization.isOptedOut`, `row.organization.optedOutAt`

### Decision 5: Комбobox организаций
- Добавить `data-org-combobox` к `<select name="organization">` в:
  - `templates/organization_hide/list.html.twig:20`
  - `templates/campaign/recipients.html.twig:55`
- Остальные 3 шаблона (contact form, contact modal, call form) уже имеют `data-org-combobox`
- Обернуть оба селекта в `.field` (или задать `position: relative` контейнеру), т.к. `.org-combobox__menu` позиционируется абсолютно
- Проверить flex-раскладку форм (`.campaign-recipients__add-form`, форма реестра скрытий): кнопка-переключатель и меню не должны ломать строку
- JS (`assets/js/org-combobox.js`) автоматически инициализирует все `select[data-org-combobox]`

### Decision 6: Переименование стат-секций (домашняя страница)
- `templates/home/index.html.twig`:
  - `Звонков` → `Сделано звонков`; подписи: `Сегодня`, `За 7 дней`, `За 30 дней`
  - `Ожидают` → `Ожидают звонка`; подписи: `Сегодня`, `На неделе`, `В месяце`
  - `Просроченные` → `Просроченные звонки`; подписи: `Вчера`, `За 7 дней`, `За 30 дней`
- Заголовок секции называет категорию, подпись — только период; общая формулировка зафиксирована в дельте `dashboard` (без перечисления девяти подписей)

### Decision 7: Очистка поиска
- `assets/js/dashboard-search.js`: при пустом поле не уходить на `form.action`, а отправлять форму без `q`, сохраняя отмеченные `inactive`/`optout`; в URL не должно оставаться параметра `q`

### Decision 8: Тесты
- Функциональные: `HomeControllerTest` (подписи, секции, фильтры), `OrganizationHidingReadPathTest` (скоуп `homeFigure()` по секциям + новые подписи), `CallControllerTest:54`, `ContactControllerTest:41`, `OrganizationControllerTest:38,381` (h1 → «Организации»)
- E2E: `home-stats.spec.ts` (новые подписи, скоуп `statItem` по `.stats-home__section--*`, снять проверку отсутствия «Сегодня»), `dashboard.spec.ts` и `smoke.spec.ts` (заголовок «Организации», без приветствия), `dashboard-organizations.spec.ts` (индексы колонок, фильтры, сортировка новых колонок)
- `composer cs:check && composer stan`, `make test`

## Tasks

1. favicon: скопировать handshake.ico → public/favicon.ico, обновить base.html.twig
2. Заголовки: удалить h1+приветствие и userType, h2→h1, полоса h1, удалить мёртвый CSS
3. Репозиторий и контроллер: фильтрация inactive/optout, сортировка isActive/optedOutAt, удаление $filter
4. Шаблон дашборда: компактная форма фильтрации, колонки, colspan, sort_url
5. Поиск: очистка сохраняет фильтры
6. Комбobox: data-org-combobox + `.field`-обёртки/стили
7. Стат-секции: переименование заголовков и подписей
8. Тесты: функциональные и e2e, cs:check, stan, make test
