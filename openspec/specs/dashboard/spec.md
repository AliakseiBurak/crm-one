# Dashboard

Статистика обзвона на дашборде из реальных данных звонков с учётом области
доступа пользователя.

## Purpose

Статистика обзвона на дашборде из реальных данных звонков с учётом области доступа пользователя.

## Requirements

### Requirement: Реальная статистика обзвона на дашборде
The system SHALL render on the dashboard six statistics figures computed
from actual call data: three "called" figures counting calls whose call
date (`made_at`) falls on the current calendar day, within the last 7
calendar days and within the last 30 calendar days, and three "waiting"
figures counting calls whose scheduled date (`scheduled_at`) falls on the
current calendar day, within the next 7 days and within the next 30 days.
Figures SHALL update automatically when call data changes and SHALL NOT be
hardcoded.

#### Scenario: Дашборд показывает факты звонков за периоды
- **WHEN** в системе существуют звонки с фактом звонка сегодня, вчера и 10 дней назад
- **AND** пользователь открывает дашборд
- **THEN** на дашборде отображается число звонков, сделанных сегодня
- **AND** на дашборде отображается число звонков, сделанных за последние 7 дней
- **AND** на дашборде отображается число звонков, сделанных за последние 30 дней

#### Scenario: Дашборд показывает запланированные звонки
- **WHEN** в системе существуют звонки, запланированные на сегодня, на через 3 дня и на через 20 дней
- **AND** пользователь открывает дашборд
- **THEN** на дашборде отображается число звонков, ожидающих обзвона сегодня
- **AND** на дашборде отображается число звонков, ожидающих обзвона в течение недели
- **AND** на дашборде отображается число звонков, ожидающих обзвона в течение месяца

#### Scenario: Отсутствие захардкоженных значений
- **WHEN** в системе не существует ни одного звонка
- **AND** пользователь открывает дашборд
- **THEN** все шесть показателей отображают нулевые значения
- **AND** ни один показатель не отображает значение, отсутствующее в данных

### Requirement: Область доступа статистики
The system SHALL compute dashboard statistics within the user's access
scope: an administrator SHALL see statistics across all organizations,
while a manager SHALL see statistics only for organizations in groups they created (`created_by`) and in the custom groups assigned to them
(`adr/0007, 0011`). The dashboard SHALL NOT display statistics of organizations
outside the user's access scope.

#### Scenario: Менеджер видит статистику своей области доступа
- **WHEN** в системе существуют организации "ООО Ромашка" и "ООО Конкурент"
- **AND** организация "ООО Ромашка" входит в область доступа менеджера, а "ООО Конкурент" нет
- **AND** в обеих организациях зафиксированы звонки
- **THEN** на дашборде менеджера учитываются только звонки организации "ООО Ромашка"
- **AND** звонки организации "ООО Конкурент" не влияют на показатели дашборда

#### Scenario: Администратор видит всю статистику
- **WHEN** в системе существуют организации в разных группах с зафиксированными звонками
- **AND** администратор открывает дашборд
- **THEN** показатели дашборда учитывают звонки всех организаций

### Requirement: Таблица организаций на панели
The system SHALL render on the dashboard, below the statistics blocks, a
table of organizations with columns for the organization name, date of the
last completed call, date of the next scheduled call, activity status and
opt-out date. The last call date SHALL be derived from the
latest `Call.made_at` of the organization, the next call date SHALL be
derived from the nearest future `Call.scheduled_at`. The activity status
column SHALL render `Organization.isActive` as a checkbox, the opt-out date
column SHALL render `Organization.optedOutAt` as a date or «—» when absent.
The activity status and opt-out date columns SHALL be rendered after the
next call date column. Organizations SHALL be listed within the user's
access scope, in the sort order selected by the user. The organization
name column SHALL occupy the maximum available width and allow text
wrapping; the remaining columns SHALL be at least as wide as their header
text (including sort arrows) on one line and grow to fit cell content.

#### Scenario: Список организаций с датами звоноков
- **WHEN** в области доступа пользователя существуют организации с завершёнными и запланированными звонками
- **AND** пользователь открывает дашборд
- **THEN** в таблице отображаются названия организаций
- **AND** для каждой организации отображаются дата последнего завершённого звонка и дата ближайшего запланированного звонка

#### Scenario: Организация без звонков
- **WHEN** в области доступа пользователя существует организация, у которой нет ни одного звонка
- **AND** пользователь открывает дашборд
- **THEN** в строке организации вместо дат звоноков отображаются заглушки «—»

#### Scenario: Статус активности и дата отписки
- **WHEN** в области доступа пользователя существуют активная и неактивная организации, одна из которых отписалась
- **AND** пользователь открывает дашборд
- **THEN** для активной организации отображается отмеченный чекбокс активности, для неактивной — неотмеченный
- **AND** для отписавшейся организации отображается дата отписки, для остальных — «—»

#### Scenario: Name column absorbs remaining width, other columns grow
- **WHEN** пользователь открывает дашборд
- **THEN** колонка названия организации занимает максимально возможное место и допускает перенос текста
- **AND** остальные колонки не уже заголовка со стрелкой сортировки и расширяются под содержимое
- **AND** таблица прокручивается горизонтально внутри контейнера, если не помещается в окно

### Requirement: Контакты организации на панели
The system SHALL let the user expand an organization row on the dashboard
to reveal the expanded section (`org_details`) of that organization by
clicking anywhere on the organization row. The system SHALL NOT render a
separate expand link or summary element (such as «Звонки и контакты
организации») inside the expanded section. The system SHALL NOT render a
native disclosure triangle or «Details» text from a `<details>` element.
The entire organization row SHALL act as a toggle area that expands and
collapses the section, except for interactive elements within the row (the
«Изменить» button and the organization name link). The expanded section
SHALL be hidden by default and shown only when the organization row has
the expanded state. Content of the expanded section SHALL appear in this
order from top to bottom: organization description («Описание»), a metadata
row containing industry («Сфера деятельности»), UNP («УНП»), and courses
attended («Учились у нас») grouped together, last call note block
(«Последний звонок»), the expandable «Все звонки» list, contact cards
(«Контакты»), and at the very bottom the action buttons «Добавить звонок»
and «Добавить контакт». Empty description, industry, UNP, or courses
attended SHALL render as «—» or be omitted without breaking the order of
the remaining blocks. The system SHALL render contact cards with the contact
name, the phone as a clickable `tel:` link and the email as a clickable
`mailto:` link. A card SHALL also render the non-empty `Contact.notes` of
the contact under a «Заметка» label. The cards SHALL NOT contain call or
other action buttons. When the contact has at least one `CampaignRecipient`
with status `bounced` in a non-archived campaign, the card SHALL show a
visible mark indicating a bounced email.

#### Scenario: Раскрытие контактов организации по клику на строку
- **WHEN** пользователь на панели кликает по любой части строки организации (название, даты звонков), у которой есть контакты
- **THEN** под строкой отображается раскрытая секция организации
- **AND** первым блоком секции идёт «Описание»
- **AND** затем идёт строка метаданных: «Сфера деятельности», «УНП», «Учились у нас»
- **AND** затем следуют «Последний звонок», «Все звонки» и карточки контактов
- **AND** в самом низу секции расположены кнопки «Добавить звонок» и «Добавить контакт»
- **AND** каждая карточка контакта содержит имя, телефон как кликабельную ссылку, email как кликабельную ссылку
- **AND** при заполненной заметке контакта карточка содержит строку «Заметка: …»
- **AND** на карточке нет кнопки «Позвонить» и других кнопок звонка
- **AND** повторный клик по строке организации скрывает секцию

#### Scenario: Описание и строка метаданных в раскрытой секции
- **WHEN** пользователь раскрывает строку организации с описанием "Крупный клиент", отраслью "IT", УНП "100123456" и курсами "Курс по продажам"
- **THEN** в секции отображается блок «Описание» со значением "Крупный клиент"
- **AND** ниже него отображается строка метаданных: «Сфера деятельности: IT», «УНП: 100123456», «Учились у нас: Курс по продажам»
- **AND** эта строка расположена выше блока «Последний звонок»

#### Scenario: Пустые значения в строке метаданных
- **WHEN** пользователь раскрывает строку организации без отрасли, УНП и курсов
- **THEN** в строке метаданных для пустых полей отображается «—»

#### Scenario: Клик по интерактивным элементам не раскрывает строку
- **WHEN** пользователь кликает по кнопке «Изменить» в строке организации
- **THEN** открывается модальное окно редактирования организации
- **AND** строка организации не раскрывается и не сворачивается

#### Scenario: Клик по имени организации переходит на редактирование
- **WHEN** пользователь кликает по имени организации (ссылке) в строке организации
- **THEN** происходит переход на страницу редактирования организации
- **AND** строка организации не раскрывается и не сворачивается

#### Scenario: Отсутствие отдельной ссылки-раскрытия и нативного маркера
- **WHEN** пользователь открывает панель организаций
- **THEN** в раскрытой секции организации отсутствует отдельная ссылка или summary элемент с текстом «Звонки и контакты организации»
- **AND** в раскрытой секции организации отсутствует нативный disclosure triangle или текст «Details» от элемента `<details>`
- **AND** раскрытие происходит только по клику на строку организации

#### Scenario: Организация без контактов
- **WHEN** пользователь кликает по строке организации, у которой нет контактов
- **THEN** в раскрытой секции отображаются блоки описания, строки метаданных и звонков (если они есть)
- **AND** карточки контактов не показываются
- **AND** в самом низу секции отображается кнопка «Добавить контакт»

#### Scenario: Кнопки действий внизу секции
- **WHEN** пользователь раскрывает строку организации
- **THEN** кнопка «Добавить звонок» и кнопка «Добавить контакт» расположены в самом низу раскрытой секции
- **AND** под ними нет других блоков содержимого секции

#### Scenario: Отметка bounced e-mail на карточке контакта
- **WHEN** пользователь раскрывает строку организации на дашборде
- **AND** у контакта организации есть хотя бы один `CampaignRecipient` со статусом `bounced` в неархивной рассылке
- **THEN** на карточке контакта отображается отметка о bounced e-mail

#### Scenario: Нет отметки без bounced
- **WHEN** пользователь раскрывает строку организации на дашборде
- **AND** у контакта нет `CampaignRecipient` со статусом `bounced` в неархивной рассылке
- **THEN** на карточке контакта нет отметки о bounced e-mail

#### Scenario: Архивный bounced не даёт отметку
- **WHEN** пользователь раскрывает строку организации на дашборде
- **AND** у контакта есть `CampaignRecipient` со статусом `bounced`, но все такие рассылки в статусе `archived`
- **THEN** на карточке контакта нет отметки о bounced e-mail

### Requirement: Все звонки организации
The system SHALL render in the expanded section of an organization the note
of its last call: the note SHALL be taken from the non-empty `Call.notes`
of the latest call of the organization, where the latest call SHALL be
resolved by the maximum effective date `COALESCE(made_at, scheduled_at)`;
when several calls share the same maximum effective date, the latest call
SHALL be the one with the greatest `Call.id`. The note SHALL be displayed
together with the contact of that call (contact
name, phone as a `tel:` link and email as a `mailto:` link). The system
SHALL provide a «Все звонки» expandable list that reveals all calls
of the organization with the date of each call, newest first. A row of a
call with non-empty `Call.notes` SHALL show the date, the note text and the
contact of that call (contact name, phone as a `tel:` link and email as a
`mailto:` link). A row of a call without notes SHALL show the date and,
when the call has a contact, that contact (contact name, phone as a `tel:`
link and email as a `mailto:` link) — no note text. Expansion of the list
SHALL NOT re-fetch data or reload the page.

#### Scenario: Заметка последнего звонка
- **WHEN** у организации есть звонки с заметками
- **AND** пользователь раскрывает строку организации
- **THEN** в раскрытой секции отображается заметка последнего по времени звонка
- **AND** рядом с заметкой отображается контакт звонка (имя, телефон как ссылка для звонка, email как ссылка для почты)
- **AND** звонки без заметок не участвуют в выборе последней заметки

#### Scenario: Все звонки
- **WHEN** пользователь кликает по «Все звонки» в раскрытой секции организации
- **THEN** раскрывается список всех звонков организации с датой каждого
- **AND** каждая строка списка с непустой заметкой содержит дату, текст заметки и контакт этого звонка (имя, телефон как ссылка для звонка, email как ссылка для почты)
- **AND** строка списка звонка без заметки содержит дату и контакт звонка, если он есть (имя, телефон как ссылка для звонка, email как ссылка для почты) — без текста заметки
- **AND** строки отсортированы от новых к старым
- **AND** повторный клик по «Все звонки» скрывает список

#### Scenario: У организации нет заметок
- **WHEN** все звонки организации не имеют заметок
- **AND** пользователь раскрывает строку организации
- **THEN** блок «Последний звонок» не показывается
- **AND** в списке «Все звонки» отображаются строки звонков с датой и контактом, если он есть — без текста заметки
- **WHEN** у организации совсем нет звонков
- **THEN** секция звонков не показывается вовсе
- **AND** раскрытая секция показывает только контакты организации

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
name, last call date, next call date, activity status and opt-out date.
Sorting by industry SHALL NOT be offered, because the industry column is
not rendered in this table. By default,
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

### Requirement: Область доступа списка организаций
The system SHALL render the organization table on the dashboard within the
user's access scope: an administrator SHALL see all organizations, while a
manager SHALL see only the organizations of the groups they created
(`created_by`) and of the custom groups assigned to them (`adr/0007, 0011`).
The dashboard SHALL NOT render organizations outside the user's access
scope.

#### Scenario: Менеджер видит только свои организации
- **WHEN** в системе существуют организации в области доступа менеджера и вне неё
- **AND** менеджер открывает дашборд
- **THEN** в таблице отображаются только организации области доступа менеджера
- **AND** организации вне области доступа не отображаются

#### Scenario: Администратор видит все организации
- **WHEN** администратор открывает дашборд
- **THEN** в таблице отображаются все организации системы

### Requirement: Кнопки действий на панели
The system SHALL render action buttons on the dashboard: an «Изменить»
button per organization row, an «Добавить звонок» button and an
«Добавить контакт» button at the bottom of the expanded section
(`org_details`) of an organization, an «Изменить» button per call row of
the «Все звонки» list, and an «Изменить» button on each contact card.
The «Добавить звонок» and «Добавить контакт» buttons SHALL be the last
elements of the expanded section (below description, industry, last call,
all calls and contacts). The buttons SHALL link to the corresponding
management routes. Those routes SHALL NOT be implemented yet: the requests
to them SHALL be answered with the standard 404 not-found response until
the separate management change is implemented.

#### Scenario: Изменение организации
- **WHEN** пользователь кликает по кнопке «Изменить» в строке организации
- **THEN** браузер переходит по ссылке на страницу редактирования этой организации
- **AND** сервер отвечает 404, так как страница редактирования ещё не реализована

#### Scenario: Добавление звонка
- **WHEN** пользователь кликает по кнопке «Добавить звонок» в раскрытой секции организации, в том числе когда звонков нет
- **THEN** браузер переходит по ссылке на страницу создания звонка этой организации
- **AND** сервер отвечает 404, так как страница создания звонка ещё не реализована

#### Scenario: Кнопки в самом низу раскрытой секции
- **WHEN** пользователь раскрывает строку организации
- **THEN** кнопки «Добавить звонок» и «Добавить контакт» отображаются в самом низу секции `org_details`
- **AND** над ними расположены блоки описания, сферы деятельности, звонков и контактов

#### Scenario: Изменение звонка
- **WHEN** пользователь кликает по кнопке «Изменить» в строке звонка списка «Все звонки»
- **THEN** браузер переходит по ссылке на страницу редактирования этого звонка
- **AND** сервер отвечает 404, так как страница редактирования звонка ещё не реализована

#### Scenario: Добавление контакта
- **WHEN** пользователь кликает по кнопке «Добавить контакт» в раскрытой секции организации, в том числе когда контактов нет
- **THEN** браузер переходит по ссылке на страницу создания контакта этой организации
- **AND** сервер отвечает 404, так как страница создания контакта ещё не реализована

#### Scenario: Изменение контакта
- **WHEN** пользователь кликает по кнопке «Изменить» на карточке контакта
- **THEN** браузер переходит по ссылке на страницу редактирования этого контакта
- **AND** сервер отвечает 404, так как страница редактирования контакта ещё не реализована

### Requirement: Карточка общего числа организаций
The system SHALL render above the statistics figures a full-width card
displaying «Доступно организаций: Y», where Y SHALL be the total number of
organizations in the user's access scope. For an administrator Y SHALL be
the total number of organizations in the system. Y SHALL be computed once
and displayed as a single card, not repeated under each figure.

#### Scenario: Карточка показывает общее число организаций
- **WHEN** вошедший пользователь открывает домашнюю страницу
- **THEN** над секцией статистики отображается карточка «Доступно организаций: Y»
- **AND** Y — число организаций области доступа пользователя

#### Scenario: Администратор видит все организации
- **WHEN** администратор открывает домашнюю страницу
- **THEN** в карточке отображается Y = общее число организаций системы

#### Scenario: Карточка не отображается для гостей
- **WHEN** неаутентифицированный посетитель открывает домашнюю страницу
- **THEN** карточка «Доступно организаций» не отображается

### Requirement: Индикаторы статистики по организациям
The system SHALL render on the dashboard twelve statistics figures: nine call
figures and three optout figures. Under each of the nine call figures the
system SHALL render a «По организациям: N» indicator link, where N SHALL be the
number of distinct organizations of the user's access scope having calls of
that figure's category. For the called figures the category SHALL be determined
by the call date (`made_at`), for the waiting figures — by the scheduled date
(`scheduled_at`), within the same periods as the figure. For the overdue
figures the category SHALL be determined by the scheduled date (`scheduled_at`)
where `made_at IS NULL`, within the same periods as the figure. The indicator
SHALL be rendered as an anchor element linking to the organizations panel with
a `filter` query parameter identifying the category and the number of days of
the period in the form `<category><days>` (for example `called7`, `waiting30`,
`overdue30`), where `<days>` is the period length in days (1, 7 or 30). The
indicator SHALL be rendered even when N equals zero. The three optout figures SHALL NOT have
by-organization indicators.

#### Scenario: Индикатор по организациям под показателем
- **WHEN** пользователь открывает домашнюю страницу
- **THEN** под каждым из девяти показателей звонков отображается ссылка «По организациям: N»
- **AND** в качестве N отображается число уникальных организаций области доступа со звонками этой категории
- **AND** ссылка содержит параметр `filter` с ключом категории

#### Scenario: Пустая категория
- **WHEN** в области доступа пользователя нет организаций со звонками категории
- **AND** пользователь открывает домашнюю страницу
- **THEN** под показателем отображается «По организациям: 0»
- **AND** ссылка присутствует и ведёт на панель организаций с параметром filter

#### Scenario: Навигация на панель организаций
- **WHEN** пользователь кликает по индикатору категории
- **THEN** происходит переход на `/dashboard?filter=<category><days>`, где `<category>` — категория (called, waiting, overdue, optoutEmail), а `<days>` — число дней периода (например, called1, waiting7, overdue30)

#### Scenario: У показателей отписок нет индикатора «По организациям»
- **WHEN** пользователь открывает домашнюю страницу
- **THEN** под показателями «сегодня», «за 7 дней» и «за 30 дней» блока «Отписки организаций» ссылки «По организациям» не отображаются

### Requirement: Статистика просроченных звонков
The system SHALL render three overdue statistics figures counting distinct
organizations having calls with a scheduled date (`scheduled_at`) in the past
and no call date (`made_at IS NULL`). The three periods SHALL be: yesterday
(00:00–23:59 of the previous calendar day), last 7 days (today minus 6 days
through yesterday), and last 30 days (today minus 29 days through yesterday).
Figures SHALL update automatically when call data changes and SHALL NOT be
hardcoded.

#### Scenario: Просроченные вчера
- **WHEN** в системе существуют организации с запланированными звонками на вчера без `made_at`
- **AND** пользователь открывает домашнюю страницу
- **THEN** в секции «Просроченные звонки» отображается показатель с подписью «Вчера» с числом уникальных организаций области доступа

#### Scenario: Просроченные за 7 дней
- **WHEN** в системе существуют организации с запланированными звонками за последние 7 дней без `made_at`
- **AND** пользователь открывает домашнюю страницу
- **THEN** в секции «Просроченные звонки» отображается показатель с подписью «За 7 дней» с числом уникальных организаций области доступа

#### Scenario: Просроченные за 30 дней
- **WHEN** в системе существуют организации с запланированными звонками за последние 30 дней без `made_at`
- **AND** пользователь открывает домашнюю страницу
- **THEN** в секции «Просроченные звонки» отображается показатель с подписью «За 30 дней» с числом уникальных организаций области доступа

#### Scenario: Организация с просроченным и совершённым звонком
- **WHEN** организация «Ромашка» имеет запланированный звонок на вчера без `made_at` и совершённый звонок сегодня
- **THEN** организация «Ромашка» учитывается в показателе «Вчера» секции «Просроченные звонки»
- **AND** организация «Ромашка» учитывается в показателе «Сегодня» секции «Сделано звонков»

#### Scenario: Организация с частично нереализованными звонками
- **WHEN** организация «Вектор» запланировала 5 звонков на вчера, из них 3 совершены, 2 — нет
- **THEN** организация «Вектор» учитывается в показателе «Вчера» секции «Просроченные звонки»

### Requirement: Исключающая логика waiting-категорий
The system SHALL NOT count an organization in a waiting figure when that
organization has at least one call with a call date (`made_at`) within the
same period as the waiting figure. Specifically: an organization having a
call with `made_at` on the current day SHALL NOT appear in `waitingToday`;
an organization having a call with `made_at` within the last 7 days SHALL
NOT appear in `waitingWeek`; an organization having a call with `made_at`
within the last 30 days SHALL NOT appear in `waitingMonth`. An organization
MAY appear in both a called figure and a waiting figure of a different period
(e.g., called today AND waiting this week). The overdue figures SHALL be
independent: an organization MAY appear in both an overdue figure and a
called figure simultaneously.

#### Scenario: Организация с звонком сегодня не учитывается в «Ожидают сегодня»
- **WHEN** в области доступа пользователя организация «Ромашка» имеет звонок с `made_at` сегодня
- **AND** та же организация «Ромашка» имеет запланированный звонок с `scheduled_at` сегодня
- **THEN** организация «Ромашка» учитывается в показателе «Сегодня» секции «Сделано звонков»
- **AND** организация «Ромашка» НЕ учитывается в показателе «Сегодня» секции «Ожидают звонка»

#### Scenario: Организация с звонком за неделю не учитывается в «Ожидают на неделе»
- **WHEN** организация имеет звонок с `made_at` 5 дней назад
- **AND** та же организация имеет запланированный звонок через 3 дня
- **THEN** организация учитывается в показателе «За 7 дней» секции «Сделано звонков»
- **AND** организация НЕ учитывается в показателе «За 7 дней» секции «Ожидают звонка»

#### Scenario: Организация с звонком за месяц не учитывается в «Ожидают в месяце»
- **WHEN** организация имеет звонок с `made_at` 20 дней назад
- **AND** та же организация имеет запланированный звонок через 15 дней
- **THEN** организация учитывается в показателе «За 30 дней» секции «Сделано звонков»
- **AND** организация НЕ учитывается в показателе «За 30 дней» секции «Ожидают звонка»

#### Scenario: Организация в разных периодах — разрешено
- **WHEN** организация имеет звонок с `made_at` сегодня
- **AND** та же организация имеет запланированный звонок через 10 дней
- **THEN** организация учитывается в показателе «Сегодня» секции «Сделано звонков»
- **AND** организация учитывается в показателе «За 7 дней» секции «Ожидают звонка»

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

### Requirement: Область доступа индикаторов
The system SHALL compute the by-organization indicators, the total
organizations card and the navigation links within the user's access scope:
an administrator SHALL see organizations and indicators across all
organizations, while a manager SHALL see only the organizations in groups they created (`created_by`) and in the custom groups assigned to them
(`adr/0007, 0011`). Negative or foreign organizations SHALL NOT affect the
indicators.

#### Scenario: Менеджер видит индикаторы только своей области доступа
- **WHEN** в системе существуют организации в области доступа менеджера и вне её
- **AND** в обеих группах организаций зафиксированы звонки
- **AND** менеджер открывает домашнюю страницу
- **THEN** индикаторы «По организациям» учитывают только организации области доступа менеджера
- **AND** организации вне области доступа не влияют на числа индикаторов
- **AND** карточка «Доступно организаций» показывает Y = число организаций области доступа менеджера

#### Scenario: Администратор видит индикаторы по всем организациям
- **WHEN** администратор открывает домашнюю страницу
- **THEN** индикаторы «По организациям» учитывают все организации системы
- **AND** карточка «Доступно организаций» показывает Y = все организации системы

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

### Requirement: Статистика отписок на дашборде
The system SHALL show an «Отписки организаций» block on the dashboard (home
page) with three time-period metrics computed from Organization data: «сегодня»
(organizations whose optedOutAt falls within today), «за 7 дней» (optedOutAt
within the last 7 calendar days: today minus 6 days through today), and
«за 30 дней» (optedOutAt within the last 30 calendar days: today minus 29 days
through today). Each time-period metric SHALL display a sub-metric
«Из письма» counting organizations with isOptedOut = true, optedOutAt within
the same period, and optOutReason = «Отписка из письма» (opted out via the
campaign email unsubscribe link). The sub-metric SHALL be rendered as an anchor
element linking to the organizations panel with a `filter` query parameter
`optoutEmail<days>` (optoutEmail1, optoutEmail7, optoutEmail30), where `<days>`
is the period length in days. The sub-metric SHALL be rendered even when N
equals zero. The block SHALL render exactly these three
figures: a separate all-time «Из письма» figure SHALL NOT be rendered. The
metrics and their sub-metrics SHALL respect the user access scope (ADR-0007/0008,
ADR-0011): an administrator SHALL see all organizations, a manager SHALL see
only organizations in their access scope. Metrics SHALL be computed from data,
not hardcoded.

#### Scenario: Отписки за сегодня
- **WHEN** менеджер открывает главную страницу дашборда
- **AND** в его области доступа есть организация, отписавшаяся сегодня
- **THEN** показатель «сегодня» учитывает эту организацию

#### Scenario: Отписки за 7 дней
- **WHEN** менеджер открывает главную страницу дашборда
- **AND** в его области доступа есть организация, отписавшаяся в последние 7 календарных дней (включая сегодня)
- **THEN** показатель «за 7 дней» учитывает эту организацию

#### Scenario: Отписки за 30 дней
- **WHEN** менеджер открывает главную страницу дашборда
- **AND** в его области доступа есть организация, отписавшаяся в последние 30 календарных дней (включая сегодня)
- **THEN** показатель «за 30 дней» учитывает эту организацию

#### Scenario: Подметрика «Из письма» под каждым периодом
- **WHEN** пользователь открывает главную страницу дашборда
- **THEN** под показателем «сегодня» отображается подметрика «Из письма: N»
- **AND** под показателем «за 7 дней» отображается подметрика «Из письма: N»
- **AND** под показателем «за 30 дней» отображается подметрика «Из письма: N»
- **AND** подметрика под показателем «сегодня» является ссылкой на `/dashboard?filter=optoutEmail1`
- **AND** подметрика под показателем «за 7 дней» является ссылкой на `/dashboard?filter=optoutEmail7`
- **AND** подметрика под показателем «за 30 дней» является ссылкой на `/dashboard?filter=optoutEmail30`

#### Scenario: Навигация по подметрике «Из письма»
- **WHEN** пользователь кликает по подметрике «Из письма» под показателем отписок
- **THEN** происходит переход на `/dashboard?filter=optoutEmail<days>`, где `<days>` — число дней периода соответствующего показателя (1, 7 или 30)

#### Scenario: Отписка из письма учитывается в подметрике «Из письма»
- **WHEN** администратор открывает главную страницу дашборда
- **AND** организация отписалась по ссылке из письма (optOutReason «Отписка из письма»)
- **AND** optedOutAt попадает в период (сегодня / 7 дней / 30 дней)
- **THEN** подметрика «Из письма» соответствующего периода учитывает эту организацию

#### Scenario: Отписка не по ссылке не учитывается в подметрике «Из письма»
- **WHEN** администратор открывает главную страницу дашборда
- **AND** организация отписалась с другой причиной (optOutReason ≠ «Отписка из письма»)
- **THEN** подметрика «Из письма» не учитывает эту организацию
- **AND** основная метрика периода учитывает её

#### Scenario: Отдельной цифры «Из письма» нет
- **WHEN** пользователь открывает главную страницу дашборда
- **THEN** в блоке «Отписки организаций» отображаются ровно три цифры: «сегодня», «за 7 дней» и «за 30 дней»
- **AND** отдельная цифра «Из письма» (без периода) не отображается

#### Scenario: Администратор видит отписки всех организаций
- **WHEN** администратор открывает главную страницу дашборда
- **AND** организация с isOptedOut = true существует в системе
- **THEN** показатели блока «Отписки организаций» вычисляются по всем организациям системы

#### Scenario: Менеджер не видит отписки вне своей области доступа
- **WHEN** менеджер открывает главную страницу дашборда
- **AND** организация с isOptedOut = true находится вне его области доступа
- **THEN** показатели блока «Отписки организаций» не учитывают эту организацию

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
