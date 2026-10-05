## ADDED Requirements

### Requirement: Пагинация списков
The system SHALL provide one shared pagination component for list pages that
render a data table. A paginated list SHALL show at most 50 rows per page.
The pagination block SHALL appear below the table only when the matching row
count exceeds 50; while the list fits on a single page the block SHALL NOT be
rendered at all, so the appearance of a short list does not change. The block
SHALL offer page numbers within a window around the current page and links to
the first and the last page. It SHALL NOT render separate «‹ Назад» /
«Вперёд ›» controls: the neighbouring pages are already visible in the number
window, so the same choice SHALL NOT be offered twice. Navigation
links SHALL preserve the list's active search text, filters and sorting. The
block SHALL NOT display a «Показано N–M из K» summary line. The same component
and the same 50-row page size SHALL be used by every paginated list in the
application.

#### Scenario: Список помещается в одну страницу
- **WHEN** пользователь открывает пагинируемый список, в котором не больше 50 строк
- **THEN** таблица показывает все строки списка
- **AND** блок навигации по страницам не отображается

#### Scenario: Список не помещается в одну страницу
- **WHEN** в пагинируемом списке больше 50 строк
- **THEN** таблица показывает не больше 50 строк текущей страницы
- **AND** под таблицей отображается блок навигации по страницам

#### Scenario: Переход между страницами сохраняет условия списка
- **WHEN** пользователь применяет поиск и сортировку в пагинируемом списке и переходит на другую страницу
- **THEN** на новой странице сохранены тот же поисковый запрос, те же фильтры и та же сортировка

#### Scenario: Блок навигации не содержит строки сводки и стрелок «Назад»/«Вперёд»
- **WHEN** отображается блок навигации по страницам
- **THEN** в нём есть номера страниц, отмечена текущая, и доступны переходы на первую и на последнюю страницу
- **AND** строка вида «Показано N–M из K» не отображается
- **AND** отдельных переходов «Назад» и «Вперёд» в блоке нет

#### Scenario: Список с большим числом страниц
- **WHEN** пагинируемый список разбит на много страниц
- **THEN** в блоке навигации видны номера страниц вокруг текущей, а не все номера подряд
- **AND** доступны переходы на первую и на последнюю страницу