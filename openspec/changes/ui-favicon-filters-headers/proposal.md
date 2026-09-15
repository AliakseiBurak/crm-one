## Why

Интерфейс CRM требует ряда мелких улучшений: отсутствует favicon для вкладки браузера, на панели и странице скрытия организаций не хватает фильтров, а заголовок «Панель» на дашборде избыточен.

## What Changes

- **favicon.ico**: добавить файл favicon.ico в `public/` и ссылку в `<head>` базового шаблона
- **Удалить заголовки дашборда**: убрать `<h1>Панель</h1>` из `templates/home/dashboard.html.twig` (и все прочие заголовки h1/h2 на дашборде если есть)
- **Фильтры на панели (дашборд)**: добавить фильтры для таблицы организаций — по названию, отрасли, статусу активности (isActive)
- **Фильтры на странице скрытия организаций** (`organization-hiding`): добавить фильтры для списка — по названию организации, менеджеру

## Capabilities

### New Capabilities

Нет новых возможностей — UI-улучшения в рамках существующих capability.

### Modified Capabilities

- `web-interface` — favicon, удаление заголовков
- `organizations` — фильтры поиска на панели (дополнение к существующему поиску)
- `organization-hiding` — фильтры на странице скрытия

## Impact

- **favicon.ico**: добавить файл, обновить `templates/base.html.twig`
- **Шаблоны**: dashboard.html.twig — удалить h1; обновить partial таблицы организаций — добавить фильтры
- **Organization-hiding**: обновить шаблон страницы скрытия — фильтры
- **Контроллеры**: обновить логику фильтрации в `HomeController` и `OrganizationHideController`

## Blocking Dependencies

- **`call-result-deal-and-optout`** — фильтр по isActive на дашборде заблокирован до архивации этого change: поле `Organization.isActive` добавляется там (design Decision 6, tasks 8.1–8.6).