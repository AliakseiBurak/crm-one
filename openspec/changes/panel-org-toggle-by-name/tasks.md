## 1. Изменение шаблона

- [ ] 1.1 В `templates/home/partials/_organizations_table.html.twig` удалить `<summary class="org-details__summary">Звонки и контакты организации</summary>` из раскрытой строки организации. Проверить, что summary элемент отсутствует в HTML.

- [ ] 1.2 Добавить `id="org-details-{{ organization.id }}"` к элементу `<details class="org-details__box">`. Проверить, что каждый details имеет уникальный ID.

- [ ] 1.3 Добавить `data-org-row-toggle="{{ organization.id }}"` к строке организации `<tr class="org-table__row">`. Проверить, что строка имеет data-атрибут с ID организации.

- [ ] 1.4 Добавить класс `org-table__row--expanded` к строке организации при `highlight == organization.id` (для корректного отображения индикатора при первом рендере с раскрытой секцией). Проверить, что класс добавляется только при наличии highlight.

- [ ] 1.5 Добавить атрибут `open` к элементу `<details class="org-details__box">` при `highlight == organization.id`. Проверить, что при переходе с параметром highlight секция автоматически раскрыта.

## 2. Изменение стилей

- [ ] 2.1 В `assets/scss/pages/dashboard-orgs.scss` удалить стили `.org-details__summary` и `.org-details__box[open] .org-details__summary::before`. Проверить, что сборка CSS проходит без ошибок.

- [ ] 2.2 Добавить стили для `.org-table__row`: `cursor: pointer` для индикации кликабельности. Проверить визуально, что курсор меняется при наведении на строку.

- [ ] 2.3 Добавить стили `.org-table__name::before` с треугольником ▸ (content: '▸', display: inline-block, margin-right: 0.5em, color: $color-action-orange, transition: transform). Проверить, что индикатор отображается перед названием.

- [ ] 2.4 Добавить стиль `.org-table__row--expanded .org-table__name::before { transform: rotate(90deg); }` для поворота индикатора при раскрытии. Проверить, что индикатор поворачивается при раскрытии и возвращается при сворачивании.

## 3. Добавление JavaScript

- [ ] 3.1 В `assets/js/organization-modal.js` добавить обработчик клика по `[data-org-row-toggle]`: проверить, что клик не был по `[data-organization-edit]` или `.org-table__name-link` (если был — return), найти `<details>` по `id="org-details-{orgId}"`, переключить `details.open`, переключить класс `org-table__row--expanded` у строки. Проверить, что клик по строке раскрывает/сворачачивает секцию, клик по кнопке «Изменить» открывает модальное окно, а клик по имени организации выполняет переход на страницу редактирования.

## 4. Обновление E2E тестов

- [ ] 4.1 В `e2e/tests/dashboard-organizations.spec.ts` заменить все `details.locator('summary.org-details__summary').click()` на `row.click()` (где row — это `.org-table__row` с нужным текстом). Удалить проверку `await expect(details.locator('summary.org-details__summary')).toHaveText('Звонки и контакты организации')`. Запустить тесты и проверить, что все проходят.

- [ ] 4.2 В `e2e/tests/calls-crud.spec.ts` заменить `details.locator('summary.org-details__summary').click()` на `highlighted.click()`. Запустить тесты и проверить прохождение.

- [ ] 4.3 В `e2e/tests/call-result.spec.ts` заменить все `orgDetails.locator('summary.org-details__summary').click()` на клик по строке организации (использовать `orgDetails.locator('xpath=preceding-sibling::tr[1]').click()`). Запустить тесты и проверить прохождение.

- [ ] 4.4 В `e2e/tests/contact-ismain.spec.ts` заменить `details.locator('summary.org-details__summary').click()` на `row.click()`. Запустить тесты и проверить прохождение.

- [ ] 4.5 В `e2e/diag.js` и `e2e/diag2.js` заменить `d.locator('.org-details__summary').click()` на `d.locator('xpath=preceding-sibling::tr[1]').click()`. Проверить, что диагностические скрипты работают.

## 5. Сборка и верификация

- [ ] 5.1 Выполнить `npm run build` и проверить, что сборка проходит без ошибок. Проверить, что `public/build/` содержит обновлённые CSS и JS файлы.

- [ ] 5.2 Открыть панель организаций в браузере, проверить визуально: строка организации имеет cursor pointer, название имеет индикатор ▸, клик по любой части строки раскрывает контакты и звонки, повторный клик сворачивает, кнопка «Изменить» работает независимо.

- [ ] 5.3 Проверить, что в раскрытой секции отсутствует текст «Звонки и контакты организации» и нет отдельной ссылки-раскрытия.

- [ ] 5.4 Проверить, что клик по кнопке «Изменить» открывает модальное окно редактирования и не раскрывает/не сворачивает строку.

- [ ] 5.5 Создать контакт для организации и проверить, что после сохранения происходит переход на панель с подсвеченной организацией, и секция контактов автоматически раскрыта (пользователь видит newly created контакт без дополнительного клика).

- [ ] 5.6 Добавить звонок для организации и проверить, что после сохранения происходит переход на панель с подсвеченной организацией, и секция звонков автоматически раскрыта (пользователь видит newly created звонок без дополнительного клика).
