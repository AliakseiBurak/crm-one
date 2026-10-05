## MODIFIED Requirements

### Requirement: Поиск по организациям и контактам
The system SHALL provide a search field above the organization table that
filters the table by organization name and by contact data (contact name,
phone, email) of the organization's contacts. The search SHALL be
case-insensitive and SHALL be applied only when the user submits it, by
pressing the «Найти» button or by pressing `Enter` in the search field; typing
alone SHALL NOT trigger a search. The search SHALL combine with the activity
and opt-out filters as an intersection. Submitting the search SHALL preserve
the applied sorting and filters and SHALL return the table to the first page.
Clearing the search field SHALL reset only the search text; the table SHALL be
re-filtered only after the search is submitted again.

#### Scenario: Поиск по названию организации
- **WHEN** пользователь вводит в поле поиска текст, совпадающий с названием одной из организаций, и нажимает «Найти»
- **THEN** в таблице остаются только организации, в названии которых встречается введённый текст
- **AND** остальные организации скрыты

#### Scenario: Поиск по контакту
- **WHEN** пользователь вводит в поле поиска имя, телефон или email контакта и нажимает «Найти»
- **THEN** в таблице остаются только организации, у которых есть контакт с совпадением
- **AND** контакты совпавших организаций остаются доступными для раскрытия

#### Scenario: Поиск без совпадений
- **WHEN** пользователь вводит в поле поиска текст, не встречающийся ни в одной организации или контакте, и нажимает «Найти»
- **THEN** таблица пуста
- **AND** отображается сообщение об отсутствии результатов

#### Scenario: Ввод текста не запускает поиск
- **WHEN** пользователь вводит текст в поле поиска, не нажимая «Найти»
- **THEN** таблица остаётся в прежнем состоянии и не перерисовывается
- **AND** в строке URL появляется поисковый запрос только после нажатия «Найти»

#### Scenario: Поиск запускается клавишей Enter
- **WHEN** пользователь вводит текст в поле поиска и нажимает `Enter`
- **THEN** применяется тот же поиск, что и по кнопке «Найти»

#### Scenario: Поиск сохраняет сортировку и фильтры
- **WHEN** пользователь отсортировал таблицу по колонке и включил фильтр, затем вводит поисковый текст и нажимает «Найти»
- **THEN** выбранная сортировка сохраняется
- **AND** ранее включённые фильтры остаются включёнными

#### Scenario: Очистка поиска через крестик в поле
- **WHEN** в поле поиска есть текст (таблица отфильтрована)
- **AND** пользователь нажимает нативный крестик очистки поля поиска (`type="search"`)
- **THEN** поле очищается
- **AND** в строке URL больше нет параметра `q` только после отправки поиска по кнопке «Найти»
- **AND** ранее отмеченные фильтры сохраняются

### Requirement: Сортировка таблицы организаций
The system SHALL let the user sort the organization table by organization
name, last call date, next call date, activity status and opt-out date.
Sorting by website, by industry and by city SHALL NOT be offered, because none of
those columns is rendered in this table. By default,
without a sort parameter, the table SHALL be sorted by organization name
in ascending order. The sortable column headers SHALL render as plain
clickable headers with the currently applied sort direction (and column)
marked visually. Sorting SHALL be toggled by clicking the corresponding
header; the first click applies ascending order, the second click
descending order, both within the enabled column sort directions. The sort
links SHALL preserve the applied search query and filters. The applied sort
SHALL hold across page boundaries: rows of every page SHALL follow the same
ordering, and organizations with an empty sort value SHALL remain at the end
regardless of the sort direction. The sort links SHALL return the table to
the first page. Rows SHALL have a stable order, so that the same
organization does not change pages between requests with identical
parameters.

#### Scenario: Сортировка по названию
- **WHEN** пользователь открывает дашборд без параметров сортировки
- **THEN** организации отсортированы по названию (А–Я)
- **WHEN** пользователь кликает по заголовку «Название» таблицы организаций
- **THEN** организации сортируются по названию (А–Я, затем Я–А при повторном клике)
- **AND** направление сортировки отражается в заголовке

#### Scenario: Сортировка по сфере деятельности недоступна
- **WHEN** пользователь открывает дашборд
- **THEN** среди заголовков сортируемых колонок отсутствует «Сфера деятельности»

#### Scenario: Сортировка по городу недоступна
- **WHEN** пользователь открывает дашборд
- **THEN** среди заголовков сортируемых колонок отсутствует «Город»

#### Scenario: Сортировка по дате следующего звонка
- **WHEN** пользователь кликает по заголовку «Следующий звонок»
- **THEN** организации сортируются по дате следующего звонка от ближайшей к самой поздней
- **AND** организации без запланированных звонков располагаются в конце списка

#### Scenario: Сортировка по статусу активности
- **WHEN** пользователь кликает по заголовку «Активна»
- **THEN** организации сортируются по статусу активности
- **AND** направление сортировки отражается в заголовке

#### Scenario: Сортировка по дате отписки
- **WHEN** пользователь кликает по заголовку «Дата отписки»
- **THEN** организации сортируются по дате отписки
- **AND** организации без даты отписки располагаются в конце списка

#### Scenario: Смена сортировки возвращает первую страницу
- **WHEN** пользователь находится на третьей странице панели и кликает по заголовку сортировки
- **THEN** открывается первая страница с новым порядком строк

#### Scenario: Сортировка одинаковых названий устойчива
- **WHEN** в области доступа есть разные организации с одинаковым названием
- **THEN** их порядок между собой не меняется от запроса к запросу
- **AND** одна и та же организация не появляется одновременно на двух страницах

## ADDED Requirements

### Requirement: Постраничный просмотр панели организаций
The organization table on the dashboard SHALL show at most 50 organizations
per page, using the shared pagination component. The page SHALL be addressed by
the `page` query parameter. Organizations outside the current page SHALL NOT
be rendered in the table. The pagination links SHALL preserve the current
search text, sorting and filters. A page number outside the available range —
because of an outdated bookmark, deleted organizations or a hand-typed value —
SHALL resolve to the last existing page; a non-numeric or zero page number
SHALL resolve to the first page. The requested page number SHALL always be
reflected in the URL. Switching the sort column or direction, submitting a
search, and enabling or disabling the activity and opt-out filters SHALL return
the table to the first page. When a highlighted organization
(`?highlight=<id>`) is not on the resolved page, the system SHALL open the page
that contains that organization, with the organization highlighted and its row
expanded.

#### Scenario: Панель показывает страницу организаций
- **WHEN** в области доступа пользователя больше 50 организаций
- **THEN** таблица показывает не больше 50 строк
- **AND** под таблицей доступна навигация по страницам
- **AND** строки вне текущей страницы в таблице не выводятся

#### Scenario: Номер страницы указан в URL
- **WHEN** пользователь переходит на вторую страницу панели
- **THEN** в строке URL присутствует параметр `page` со значением 2

#### Scenario: Страница за пределами диапазона
- **WHEN** пользователь открывает ссылку с номером страницы больше числа доступных страниц
- **THEN** открывается последняя существующая страница со строками
- **AND** номер страницы в URL соответствует открытой странице

#### Scenario: Некорректный номер страницы
- **WHEN** пользователь открывает панель с нечисловым или нулевым номером страницы
- **THEN** открывается первая страница

#### Scenario: Переключение фильтра возвращает первую страницу
- **WHEN** пользователь находится на третьей странице и включает или выключает фильтр «Неактивные» или «Отписавшиеся»
- **THEN** открывается первая страница с обновлённым набором строк

#### Scenario: Подсветка организации на нужной странице
- **WHEN** пользователь сохранил организацию, которая при текущей сортировке попадает не на первую страницу
- **THEN** открывается страница, содержащая эту организацию
- **AND** строка организации подсвечивается и раскрыта

#### Scenario: Подсветка организации на текущей странице
- **WHEN** подсвечиваемая организация присутствует на текущей странице
- **THEN** открывается текущая страница без изменения её номера
- **AND** строка организации подсвечивается и раскрыта

#### Scenario: Область доступа учитывается при пагинации
- **WHEN** менеджер открывает страницу панели
- **THEN** общее число страниц вычисляется по организациям его области доступа, а не по всем организациям системы

#### Scenario: Список создания контакта не пагинируется
- **WHEN** пользователь открывает панель организаций
- **THEN** список выбора организации для создания контакта содержит все организации его области доступа, а не только организации текущей страницы