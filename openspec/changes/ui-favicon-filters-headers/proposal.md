## Why

Интерфейс CRM требует ряда улучшений: отсутствует favicon, на дашборде нет фильтров по отписке/активности, заголовки избыточны, стат-секции на домашней странице переименовываются, а таблица организаций не показывает статус активности и дату отписки. Выпадающие списки организаций не имеют поиска.

## What Changes

- **favicon.ico**: скопировать `handshake.ico` в `public/favicon.ico`, обновить ссылку в `<head>` базового шаблона
- **Заголовки дашборда**: удалить `<h1>Панель</h1>` и приветствие, `<h2>Организации</h2>` → `<h1>Организации</h1>`; добавить `h1` синюю полосу по действующей спеке `web-interface`
- **Фильтры на дашборде**: компактная форма в одну строку с поиском — чекбоксы «Неактивные» (`inactive=1`) и «Отписавшиеся» (`optout=1`); условия пересекаются с поиском и между собой; значения сохраняются при сортировке
- **Очистка поиска**: крестик в поле поиска сбрасывает только текст `q`, отмеченные фильтры сохраняются
- **Колонки таблицы**: добавить сортируемые колонки «Активна» (чекбокс) и «Дата отписки» в конец таблицы организаций на дашборде
- **Комбobox организаций**: добавить `data-org-combobox` (JS-поиск) во все `<select name="organization">`, где его нет; обеспечить позиционирование меню (обёртка/`position: relative`)
- **Переименование стат-секций** (домашняя страница): «Звонков» → «Сделано звонков», «Ожидают» → «Ожидают звонка», «Просроченные» → «Просроченные звонки»; подписи называют только период и не повторяют слова заголовка

## Capabilities

### New Capabilities

Нет новых возможностей — UI-улучшения в рамках существующих capability.

### Modified Capabilities

- `web-interface` — favicon, полоса `h1`, поисковый выпадающий список организаций
- `dashboard` — фильтры «Неактивные»/«Отписавшиеся», сортируемые колонки, заголовок h1, очистка поиска, переименование стат-секций и общая формулировка подписей

## Impact

- **favicon**: `public/favicon.ico` (новый файл), `templates/base.html.twig`
- **Шаблоны**: `dashboard.html.twig` — удалить h1+приветствие и `userType`; `_organizations_table.html.twig` — h2→h1, фильтры, колонки, `colspan`, `sort_url`; `index.html.twig` — переименование стат-секций
- **Комбobox**: `organization_hide/list.html.twig`, `campaign/recipients.html.twig` — добавить `data-org-combobox` и `.field`-обёртку
- **Стили**: `base.scss` — полоса `h1`; `dashboard-orgs.scss` — компактная панель фильтров и чекбоксы; `welcome.scss` — удалить мёртвый `.dashboard-head`
- **JS**: `assets/js/dashboard-search.js` — очистка поиска сохраняет фильтры
- **Репозиторий**: `OrganizationRepository::findForDashboard()` — фильтрация по isActive/isOptedOut; сортировка `isActive`/`optedOutAt`
- **Контроллер**: `HomeController::dashboard()` — чтение GET-параметров `inactive`, `optout`; удаление мёртвого `$filter`
- **DTO**: `DashboardOrganizationRow` не меняется — шаблон читает `row.organization.*`
- **Спеки**: дельты `specs/dashboard/spec.md`, `specs/web-interface/spec.md`
- **Тесты**: `HomeControllerTest`, `OrganizationHidingReadPathTest`, `CallControllerTest`, `ContactControllerTest`, `OrganizationControllerTest`, `e2e/home-stats.spec.ts`, `e2e/dashboard.spec.ts`, `e2e/smoke.spec.ts`, `e2e/dashboard-organizations.spec.ts`

## Blocking Dependencies

Нет. Поле `Organization.isActive` уже существует (archived `call-result-deal-and-optout`).
