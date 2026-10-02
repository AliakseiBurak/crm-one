# Tasks

## 1. Админский блок в мобильной боковой панели

- [x] 1.1 `templates/components/header.html.twig`: в `header__sidebar` добавить блок «⚙ Админ ▾» с `data-header-admin`, тремя пунктами маршрутов и `is_granted('ROLE_ADMIN')` — тем же списком, что и в верхней строке.
- [x] 1.2 `assets/scss/components/header.scss`: `.header-admin--sidebar .header-admin__menu` раскрывается в потоке (`position: static`), как `.header-create--sidebar .header-create__menu`. Оба правила объединены в одно селекторное перечисление.
- [x] 1.3 JS не трогаем: `header-create-dropdown.js` находит все выпадающие списки по `data-header-admin`, поэтому боковой блок подхватывается сам.
- [x] 1.4 Требование `web-interface` «Шапка и подвал» дополнено: панель повторяет содержимое правой части шапки, включая админский блок, и сценарием «Админский блок в боковой панели».
