## 1. favicon

- [ ] 1.1 Создать файл `public/favicon.ico` (32x32 ICO или SVG)
- [ ] 1.2 Заменить `<link rel="icon" href="data:,">` на `<link rel="icon" href="{{ asset('favicon.ico') }}">` в `<head>` шаблона `templates/base.html.twig`

## 2. Удаление заголовков дашборда

- [ ] 2.1 Удалить `<h1>Панель</h1>` и `<p class="dashboard-head__greeting">...</p>` из `templates/home/dashboard.html.twig`
- [ ] 2.2 Проверить и удалить другие заголовки/секции-заголовки на дашборде

## 3. Фильтры на дашборде

- [ ] 3.1 Добавить форму фильтрации над таблицей организаций: поле названия (text), отрасль (select), статус активности (select: Все/Активные/Неактивные)
- [ ] 3.2 Обновить `HomeController::dashboard()` — чтение GET-параметров `q`, `industry`, `active`, передача в репозиторий
- [ ] 3.3 Обновить `OrganizationRepository::findForDashboard()` — фильтрация по отрасли (`o.industry = :industry`), isActive заблокирован change `call-result-deal-and-optout`
- [ ] 3.4 Сохранять значения фильтров между запросами (через GET-параметры, подстановка в форму)
- [ ] 3.5 Задействовать мёртвый параметр `$filter` в `HomeController:65` как будущий хук или удалить

### Blocked: isActive

Задача 3.3 (фильтр по isActive) заблокирована change `call-result-deal-and-optout` — поле `Organization.isActive` добавляется там. После его архивации вернуться к реализации фильтра.

## 4. Фильтры на странице скрытия организаций

- [ ] 4.1 Добавить форму фильтрации над списком: поле названия организации (text), select менеджера
- [ ] 4.2 Обновить `OrganizationHideController::list()` — обработка GET-параметров `org_name`, `manager`
- [ ] 4.3 Обновить шаблон `organization_hide/list.html.twig` — форма фильтрации + восстановление значений