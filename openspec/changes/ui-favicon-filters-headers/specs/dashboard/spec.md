## ADDED Requirements

### Requirement: Фильтры организаций по активности и отписке
The system SHALL render above the organization table two filter checkboxes:
«Неактивные» and «Отписавшиеся». When «Неактивные» is checked the table
SHALL show only organizations with `isActive = false`; when «Отписавшиеся»
is checked — only organizations with `isOptedOut = true`. The filters SHALL
combine with each other and with the search query as an intersection. When
neither filter is checked the table SHALL show all organizations of the
access scope. The applied filters SHALL persist in the form and in the
column sort links between requests. The filter form SHALL be rendered
compactly in a single row with the search field.

#### Scenario: Фильтр «Неактивные»
- **WHEN** пользователь отмечает фильтр «Неактивные»
- **THEN** в таблице остаются только организации с `isActive = false`
- **AND** активные организации скрыты

#### Scenario: Фильтр «Отписавшиеся»
- **WHEN** пользователь отмечает фильтр «Отписавшиеся»
- **THEN** в таблице остаются только организации с `isOptedOut = true`

#### Scenario: Оба фильтра отмечены
- **WHEN** пользователь отмечает оба фильтра
- **THEN** в таблице остаются только неактивные отписавшиеся организации

#### Scenario: Фильтры сохраняются при сортировке
- **WHEN** пользователь применяет фильтр и кликает по заголовку колонки для сортировки
- **THEN** сортировка применяется к отфильтрованному списку
- **AND** фильтр остаётся отмеченным

### Requirement: Заголовок панели организаций
The dashboard (`/dashboard`) SHALL render a single `h1` heading
«Организации» above the organization table. The dashboard SHALL NOT render
the page title «Панель» nor the authenticated-user greeting.

#### Scenario: Заголовок панели
- **WHEN** пользователь открывает дашборд
- **THEN** страница содержит заголовок `h1` «Организации»
- **AND** заголовок «Панель» и приветствие пользователя не отображаются

## MODIFIED Requirements

### Requirement: Таблица организаций на панели
The system SHALL render on the dashboard, below the statistics blocks, a
table of organizations with columns for the organization name, industry,
date of the last completed call, date of the next scheduled call, activity
status and opt-out date. The last call date SHALL be derived from the
latest `Call.made_at` of the organization, the next call date SHALL be
derived from the nearest future `Call.scheduled_at`. The activity status
column SHALL render `Organization.isActive` as a checkbox, the opt-out date
column SHALL render `Organization.optedOutAt` as a date or «—» when absent.
The activity status and opt-out date columns SHALL be rendered after the
next call date column. Organizations SHALL be listed within the user's
access scope, in the sort order selected by the user.

#### Scenario: Список организаций с датами звоноков
- **WHEN** в области доступа пользователя существуют организации с завершёнными и запланированными звонками
- **AND** пользователь открывает дашборд
- **THEN** в таблице отображаются названия организаций
- **AND** для каждой организации отображаются сфера деятельности, дата последнего завершённого звонка и дата ближайшего запланированного звонка

#### Scenario: Организация без звонков
- **WHEN** в области доступа пользователя существует организация, у которой нет ни одного звонка
- **AND** пользователь открывает дашборд
- **THEN** в строке организации вместо дат звоноков отображаются заглушки «—»

#### Scenario: Статус активности и дата отписки
- **WHEN** в области доступа пользователя существуют активная и неактивная организации, одна из которых отписалась
- **AND** пользователь открывает дашборд
- **THEN** для активной организации отображается отмеченный чекбокс активности, для неактивной — неотмеченный
- **AND** для отписавшейся организации отображается дата отписки, для остальных — «—»

### Requirement: Поиск по организациям и контактам
The system SHALL provide a search field above the organization table that
filters the table by organization name and by contact data (contact name,
phone, email) of the organization's contacts. The search SHALL be
case-insensitive and applied immediately as the user types. The search
SHALL combine with the activity and opt-out filters as an intersection.
Clearing the search field SHALL reset only the search text and SHALL
preserve the applied filters.

#### Scenario: Поиск по названию организации
- **WHEN** пользователь вводит в поле поиска текст, совпадающий с названием одной из организаций
- **THEN** в таблице остаются только организации, в названии которых встречается введённый текст
- **AND** остальные организации скрыты

#### Scenario: Поиск по контакту
- **WHEN** пользователь вводит в поле поиска имя, телефон или email контакта
- **THEN** в таблице остаются только организации, у которых есть контакт с совпадением
- **AND** контакты совпавших организаций остаются доступными для раскрытия

#### Scenario: Поиск без совпадений
- **WHEN** пользователь вводит в поле поиска текст, не встречающийся ни в одной организации или контакте
- **THEN** таблица пуста
- **AND** отображается сообщение об отсутствии результатов

#### Scenario: Очистка поиска через крестик в поле
- **WHEN** в поле поиска есть текст (таблица отфильтрована)
- **AND** пользователь нажимает нативный крестик очистки поля поиска (`type="search"`)
- **THEN** поле очищается, и таблица показывает организации области доступа без ограничения по поисковому запросу
- **AND** в строке URL больше нет параметра `q`
- **AND** ранее отмеченные фильтры сохраняются

### Requirement: Сортировка таблицы организаций
The system SHALL let the user sort the organization table by organization
name, industry, last call date, next call date, activity status and opt-out
date. By default, without a sort parameter, the table SHALL be sorted by
organization name in ascending order. The sortable column headers SHALL
render as plain clickable headers with the currently applied sort direction
(and column) marked visually. Sorting SHALL be toggled by clicking the
corresponding header; the first click applies ascending order, the second
click descending order, both within the enabled column sort directions. The
sort links SHALL preserve the applied search query and filters.

#### Scenario: Сортировка по названию
- **WHEN** пользователь открывает дашборд без параметров сортировки
- **THEN** организации отсортированы по названию (А–Я)
- **WHEN** пользователь кликает по заголовку «Название» таблицы организаций
- **THEN** организации сортируются по названию (А–Я, затем Я–А при повторном клике)
- **AND** направление сортировки отражается в заголовке

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

### Requirement: Статистика на домашней странице
The system SHALL render the total organizations card, the dashboard statistics
in four sections and the by-organization indicators on the home page (`/`)
below the hero banner for authenticated users. The four sections SHALL be
«Сделано звонков», «Ожидают звонка», «Просроченные звонки» and «Отписки организаций», twelve figures in total:
nine call figures and three optout figures, each optout figure with the
filter-link sub-metric «Из письма». The by-organization indicators SHALL be rendered under
the nine call figures. The statistics SHALL NOT be rendered on the home page
for guests (unauthenticated visitors). The organizations panel (`/dashboard`)
SHALL NOT duplicate the statistics figures; it SHALL only render the
organizations table. After login, the user SHALL be redirected to the home page
(`/`) where statistics are visible immediately.

#### Scenario: Вошедший пользователь видит статистику на домашней
- **WHEN** вошедший пользователь открывает домашнюю страницу `/`
- **THEN** под hero-баннером отображается карточка «Доступно организаций: Y»
- **AND** под карточкой отображаются четыре секции статистики: «Сделано звонков», «Ожидают звонка», «Просроченные звонки» и «Отписки организаций»
- **AND** в секциях отображается двенадцать показателей
- **AND** под девятью показателями звонков отображается индикатор «По организациям: N»
- **AND** под тремя показателями отписок отображается подметрика-ссылка «Из письма: N»

#### Scenario: Гость не видит статистику
- **WHEN** неаутентифицированный посетитель открывает домашнюю страницу `/`
- **THEN** происходит редирект на страницу входа `/login`
- **AND** карточка и секции статистики не отображаются

#### Scenario: Редирект после логина
- **WHEN** пользователь проходит аутентификацию
- **THEN** происходит редирект на домашнюю страницу `/`
- **AND** на домашней странице отображается карточка и статистика

#### Scenario: Таблица организаций не дублируется
- **WHEN** пользователь открывает `/dashboard`
- **THEN** отображается таблица организаций с поиском и сортировкой
- **AND** карточка и секции статистики (двенадцать показателей) НЕ отображаются на `/dashboard`

### Requirement: Подписи показателей статистики
The system SHALL render the caption of each statistics figure as the period
of the figure only, without repeating the words of the section title: the
section title SHALL name the category of the figures, the caption SHALL name
the period. The legacy caption wordings «Обзвонено сегодня», «В течение
недели» and «В течение месяца» SHALL NOT be used.

#### Scenario: Подписи показателей обновлены
- **WHEN** пользователь открывает домашнюю страницу
- **THEN** заголовок секции называет категорию показателей
- **AND** подпись показателя называет только период
- **AND** ни одна подпись не повторяет слова своего заголовка секции

#### Scenario: Устаревшие подписи отсутствуют
- **WHEN** пользователь открывает домашнюю страницу
- **THEN** подписи «Обзвонено сегодня», «В течение недели» и «В течение месяца» отсутствуют

## RENAMED Requirements

- FROM: `### Requirement: Переименование подписей показателей`
- TO: `### Requirement: Подписи показателей статистики`
