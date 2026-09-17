## Context

Цепочка подсветки работает: `OrganizationController` → `?highlight=<id>` → `HomeController` читает → Twig добавляет `.org-table__row--highlight` → JS скроллит. Но поведение не полностью соответствует спецификации: нет авто-раскрытия контактов, подсветка не одноразовая, sort-ссылки теряют параметр.

## Goals / Non-Goals

**Goals:**
- Авто-раскрывать `<details>` с контактами для подсвечиваемой организации
- Делать подсветку одноразовой (fade-out через 4 сек)
- Сохранять `highlight` в sort-ссылках
- Написать Playwright e2e-тест

**Non-Goals:**
- Изменение логики CRUD
- Изменение дизайн-системы

## Decisions

### Decision 1: Авто-раскрытие — серверный `open` атрибут
На тег `<details>` в `_organizations_table.html.twig:78` добавить `{% if highlight == organization.id %}open{% endif %}`. Это надёжнее JS-подхода — нет мерцания.

### Decision 2: Fade-out — CSS @keyframes + JS fallback
Основной механизм: CSS-анимация `@keyframes highlight-fade` на `.org-table__row--highlight` с длительностью 4 сек и `animation-fill-mode: forwards` (убирает фон). JS fallback: `setTimeout` удаляет класс через 4 сек для старых браузеров.

### Decision 3: Sort-ссылки — передавать highlight
Изменить макрос `sort_url` в `_organizations_table.html.twig` чтобы принимал и передавал `highlight` параметр (аналогично `campaign/index.html.twig:33`).

### Decision 4: E2e-тест — Playwright
Playwright e2e-тест: авторизация → POST create → редирект → проверка `.org-table__row--highlight` + `<details[open]>` → проверка fade-out. Аналогично для update.

## Risks / Trade-offs

- **[CSS-анимация не поддерживается]** → JS fallback с `setTimeout` покрывает этот случай
- **[Sort-ссылки ломают highlight]** → Fix: макрос `sort_url` передаёт `highlight` параметр
