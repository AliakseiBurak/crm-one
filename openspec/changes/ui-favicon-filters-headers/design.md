## Context

- **favicon**: отсутствует в `public/`; `base.html.twig:7` содержит заглушку `<link rel="icon" href="data:,">`
- **Dashboard**: `<h1>Панель</h1>` в `templates/home/dashboard.html.twig:10` — требуется удалить
- **Фильтры дашборда**: таблица организаций (`_organizations_table.html.twig`) имеет поиск `q` по названию/контактам, но нет фильтров по отрасли и активности. Контроллер `HomeController::dashboard()` читает мёртвый параметр `$filter` (line 65)
- **Фильтры страницы скрытия**: `organization_hide/list.html.twig` — таблица с сортировкой, без фильтров. Контроллер `OrganizationHideController::list()` загружает все hides и группирует в PHP

## Goals

### Goals:
- Добавить favicon.ico в `public/` + ссылку в `base.html.twig`
- Удалить `<h1>Панель</h1>` и прочие заголовки с дашборда
- Добавить фильтры на дашборде (название, отрасль, isActive)
- Добавить фильтры на странице скрытия (название, группа)

### Non-Goals:
- Изменение логики скрытия организаций
- Изменение дизайн-системы
- Реализация фильтра isActive — заблокирована change `call-result-deal-and-optout`

## Decisions

### Decision 1: favicon
- Файл: `public/favicon.ico` (можно SVG или ICO 32x32)
- Подключение: `<link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">` в `<head>` base.html.twig

### Decision 2: Удаление заголовков
- `templates/home/dashboard.html.twig`: удалить `<h1>Панель</h1>` и `<p class="dashboard-head__greeting">...</p>` (или оставить приветствие без h1)
- Другие заголовки на дашборде — проверить и удалить

### Decision 3: Фильтры дашборда
- Горизонтальная панель фильтров над таблицей организаций (инлайн-форма):
  ```
  [Название____] [Отрасль ▾] [Активность ▾] [Найти]
  ```
- Поля: text input для названия (расширяет существующий `q`), select для отрасли (DISTINCT из БД + «Все отрасли»), select для isActive (Все/Активные/Неактивные)
- Реализация: GET-параметры `q`, `industry`, `active`, кнопка «Найти»
- Контроллер: `HomeController::dashboard()` (`src/Controller/HomeController.php:47`) — добавить чтение `industry` и `active` из query-параметров
- Репозиторий: `OrganizationRepository::findForDashboard()` — добавить `AND o.industry = :industry` и `AND o.isActive = :active` (isActive — после архивации `call-result-deal-and-optout`)
- Сохранение значений: подстановка GET-параметров обратно в форму (атрибут `value` / `selected`)

**Хук для будущего**: `HomeController:65` уже читает `$filter = $request->query->get('filter', '')` но не использует его. Можно задействовать как общий параметр фильтрации или удалить.

### Decision 4: Фильтры страницы скрытия
- Горизонтальная панель фильтров над таблицей:
  ```
  [Название организации____] [Менеджер ▾] [Найти]
  ```
- Поля: text input для названия организации, select менеджера (из уже загруженного массива `managers`)
- GET-параметры `org_name`, `manager`, кнопка «Найти»
- Контроллер: `OrganizationHideController::list()` (`src/Controller/OrganizationHideController.php:36`) — фильтрация `$grouped` в PHP после построения (малый объём данных)
- Шаблон: `organization_hide/list.html.twig` — добавить форму фильтрации, подстановка значений

## Blocking Dependency

Фильтр по **isActive** на дашборде заблокирован change `call-result-deal-and-optout`:
поле `Organization.isActive` (boolean, default true) добавляется в рамках этого change
(design Decision 6, tasks 8.1–8.6). До архивации — реализовать фильтры по названию и отрасли.

## Tasks

1. favicon.ico
2. Удаление заголовков
3. Фильтры на дашборде (название + отрасль; isActive — заблокирован)
4. Фильтры страницы скрытия (название + менеджер)