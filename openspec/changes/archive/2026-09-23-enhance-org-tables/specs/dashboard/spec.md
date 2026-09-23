## MODIFIED Requirements

### Requirement: Таблица организаций на панели
The system SHALL render on the dashboard, below the statistics blocks, a
table of organizations with columns for the organization name, date of the
last completed call, date of the next scheduled call, activity status and
opt-out date. The industry («Сфера деятельности») column SHALL NOT be
rendered in this table. The last call date SHALL be derived from the
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
- **AND** колонка «Сфера деятельности» в таблице отсутствует

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
- **AND** остальные колонки неже заголовка с стрелкой сортировки и расширяются под содержимое
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

### Requirement: Сортировка таблицы организаций
The system SHALL let the user sort the organization table by organization
name, last call date, next call date, activity status and opt-out date.
Sorting by industry SHALL NOT be offered: the industry column is not
rendered and industry SHALL NOT appear among sortable headers. By default,
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
