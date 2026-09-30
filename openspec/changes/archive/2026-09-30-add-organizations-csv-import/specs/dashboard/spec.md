# Dashboard (delta)

## MODIFIED Requirements

### Requirement: Таблица организаций на панели
The system SHALL render on the dashboard, below the statistics blocks, a
table of organizations with columns for the organization name, date of
the last completed call, date of the next scheduled call, activity status and
opt-out date. There SHALL be no website column and no city column: the table
holds five columns, and both fields are shown in the expanded organization
details row instead. The last call date
SHALL be derived from the latest `Call.made_at` of the organization, the next
call date SHALL be derived from the nearest future `Call.scheduled_at`. The
activity status column SHALL render `Organization.isActive` as a checkbox, the
opt-out date column SHALL render `Organization.optedOutAt` as a date or «—» when
absent. The activity status and opt-out date columns SHALL be rendered after the
next call date column. Organizations SHALL be listed within the user's access
scope, in the sort order selected by the user. The organization name column
SHALL occupy the maximum available width and allow text wrapping; the remaining
columns SHALL be at least as wide as their header text (including sort arrows) on
one line and grow to fit cell content.

#### Scenario: Список организаций с датами звоноков

- **WHEN** в области доступа пользователя существуют организации с завершёнными и запланированными звонками
- **AND** пользователь открывает дашборд
- **THEN** в таблице отображаются названия организаций
- **AND** для каждой организации отображаются дата последнего завершённого звонка и дата ближайшего запланированного звонка

#### Scenario: Колонки сайта и города отсутствуют

- **WHEN** организация имеет сайт и город
- **AND** пользователь открывает панель организаций
- **THEN** в таблице отсутствуют колонки «Сайт» и «Город»
- **AND** таблица состоит из пяти колонок: название, последний звонок, следующий звонок, активна, дата отписки
- **AND** сайт и город видны в раскрытой строке организации

#### Scenario: Сортировка по сайту недоступна

- **WHEN** пользователь открывает панель организаций
- **THEN** среди сортируемых заголовков отсутствует «Сайт»

#### Scenario: Организация без звонков

- **WHEN** в области доступа пользователя существует организация, у которой нет ни одного звонка
- **AND** пользователь открывает дашборд
- **THEN** в строке организации вместо дат звонков отображаются заглушки «—»

#### Scenario: Статус активности и дата отписки

- **WHEN** в области доступа пользователя существуют активная и неактивная организации, одна из которых отписалась
- **AND** пользователь открывает дашборд
- **THEN** для активной организации отображается отмеченный чекбокс активности, для неактивной — неотмеченный
- **AND** для отписавшейся организации отображается дата отписки, для остальных — «—»

#### Scenario: Колонка города отсутствует

- **WHEN** пользователь открывает панель с организациями
- **THEN** в таблице отсутствует колонка «Город»

#### Scenario: Name column absorbs remaining width, other columns grow
- **WHEN** пользователь открывает дашборд
- **THEN** колонка названия организации занимает максимально возможное место и допускает перенос текста
- **AND** остальные колонки не уже заголовка со стрелкой сортировки и расширяются под содержимое
- **AND** таблица прокручивается горизонтально внутри контейнера, если не помещается в окно

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
links SHALL preserve the applied search query and filters.

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
