## 1. Фикс подсветки и поведения

- [x] 1.1 Цепочка flash → шаблон → CSS-класс работает (диагностика завершена: OrganizationController передаёт `?highlight=<id>`, HomeController читает, Twig применяет класс)
- [x] 1.2 Добавить авто-раскрытие `<details>` для подсвечиваемой организации — `{% if highlight == organization.id %}open{% endif %}` на тег `<details>` в `_organizations_table.html.twig`
- [x] 1.3 Добавить одноразовое исчезновение подсветки — JS `setTimeout` удаляет класс `.org-table__row--highlight` через 4 секунды (или CSS `@keyframes` fade-out)
- [x] 1.4 Сохранять параметр `highlight` в макросе sort_url — передавать `highlight` через ссылки сортировки в `_organizations_table.html.twig` (аналогично campaign/index.html.twig)

## 2. E2e-тест

- [x] 2.1 Playwright e2e-тест: авторизация → POST create organization → редирект → проверка CSS-класса `.org-table__row--highlight` + авто-раскрытие `<details>` с контактами
- [x] 2.2 Playwright e2e-тест: редактирование организации → редирект → проверка подсветки + авто-раскрытия
- [ ] 2.3 Запустить все тесты — зелёный статус
