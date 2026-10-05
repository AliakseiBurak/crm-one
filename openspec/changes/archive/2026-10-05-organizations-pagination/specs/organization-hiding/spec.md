## MODIFIED Requirements

### Requirement: Администратор видит все записи скрытия
The administrator SHALL see all hide records regardless of the organizations
and managers involved. Hide records SHALL NOT restrict the administrator's
view of organizations in any way. All records SHALL remain reachable: when the
registry does not fit on a single page, the records SHALL be split into pages
so that navigating the pagination reveals every record, and no record SHALL be
excluded from the registry because of pagination.

#### Scenario: Админ видит реестр скрытий и скрытые организации
- **WHEN** в системе существуют записи скрытия для разных организаций и менеджеров
- **AND** администратор открывает раздел «Скрытые организации»
- **THEN** он видит записи скрытия для всех организаций и менеджеров
- **AND** переходы по страницам реестра позволяют увидеть каждую запись
- **AND** скрытые организации по-прежнему отображаются администратору во всех разделах

## ADDED Requirements

### Requirement: Постраничный просмотр реестра скрытых организаций
The hidden-organizations registry SHALL show at most 50 organizations per page,
using the shared pagination component, with the page addressed by the `page`
query parameter. Organizations without a hide record SHALL NOT be counted or
rendered. The pagination links SHALL preserve the current sorting, and clicking
a sortable header SHALL return the registry to the first page. A page number
outside the available range SHALL resolve to the last existing page.

#### Scenario: Реестр скрытых организаций разбит на страницы
- **WHEN** администратор открывает реестр, в котором скрыто больше 50 организаций
- **THEN** таблица реестра показывает не больше 50 строк текущей страницы
- **AND** под таблицей доступна навигация по страницам
- **AND** переход на последнюю страницу открывает оставшиеся строки реестра

#### Scenario: Реестр помещается в одну страницу
- **WHEN** администратор открывает реестр, в котором скрыто не больше 50 организаций
- **THEN** таблица показывает все организации реестра
- **AND** блок навигации не отображается

#### Scenario: Сортировка реестра возвращает первую страницу
- **WHEN** администратор находится на второй странице реестра и кликает по заголовку сортировки
- **THEN** открывается первая страница с новым порядком строк

#### Scenario: Страница реестра за пределами диапазона
- **WHEN** администратор открывает ссылку с номером страницы больше числа доступных страниц
- **THEN** открывается последняя существующая страница реестра со строками