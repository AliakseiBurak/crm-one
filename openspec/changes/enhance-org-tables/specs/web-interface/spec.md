## MODIFIED Requirements

### Requirement: Таблицы
The system SHALL render data tables (organizations, contacts, calls) with
width 100%, text at 16px Roboto Condensed, zebra striping in `#e3f1f6` for
odd rows and `#e5f5fb` for even rows, without cell borders. Hovering a data
row SHALL highlight it with a shade of the same blue family distinct from
both zebra stripes (`#cfe6f2`), instead of removing the row color. Table
headers SHALL be bold 16px in `#5a5a5a` with bottom padding, and the table
SHALL have a bottom margin of 3rem. The contact name column SHALL be bold,
and the phone column SHALL be rendered in orange `#d66a2b` as a clickable
link. In organization tables (dashboard panel, hidden organizations
registry, group composition), the organization-name column SHALL be fixed
and occupy the maximum available width; the remaining columns SHALL use
static widths computed from their content so the layout does not reflow
with dynamic data.

#### Scenario: Зебра-таблица списка контактов
- **WHEN** пользователь открывает список контактов
- **THEN** строки таблицы окрашены попеременно в `#e3f1f6` и `#e5f5fb`
- **AND** между строками и ячейками нет линий рамок
- **AND** текст ячеек выполнен шрифтом Roboto Condensed 16px

#### Scenario: Наведение подсвечивает строку
- **WHEN** пользователь наводит курсор на строку данных таблицы
- **THEN** строка подсвечивается оттенком `#cfe6f2` того же голубого семейства, что и полосы зебры
- **AND** базовый цвет строки не исчезает и заменяется на оттенок семейства (а не на прозрачный)
- **AND** аккордеонная строка раскрытия (вторая строка организации) при наведении не подсвечивается

#### Scenario: Выделение ключевых данных в таблице
- **WHEN** в таблице отображаются контакты
- **THEN** имя контакта в первом столбце — жирное
- **AND** телефон — оранжевая `#d66a2b` кликабельная ссылка
- **AND** остальные столбцы — обычный серый текст `#5a5a5a`

#### Scenario: Фиксированная ширина колонки названия организации
- **WHEN** отображается любая из таблиц организаций: панель, «Скрытые организации» или «Состав группы»
- **THEN** колонка названия организации имеет фиксированную ширину и занимает максимально возможное место
- **AND** остальные колонки имеют статические вычисленные ширины
