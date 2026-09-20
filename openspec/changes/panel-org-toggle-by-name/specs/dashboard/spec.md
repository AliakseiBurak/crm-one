## MODIFIED Requirements

### Requirement: Контакты организации на панели
The system SHALL let the user expand an organization row on the dashboard
to reveal the contact cards of that organization by clicking anywhere on the
organization row. The system SHALL NOT render a separate expand link
or summary element (such as «Звонки и контакты организации») inside the
expanded section. The entire organization row SHALL act as a toggle area
that expands and collapses the contacts and calls section, except for
interactive elements within the row (the «Изменить» button and the
organization name link). The system SHALL render contact cards with the
contact name, the phone as a clickable `tel:` link and the email as a
clickable `mailto:` link. A card SHALL also render the non-empty
`Contact.notes` of the contact under a «Заметка» label. The cards SHALL
NOT contain call or other action buttons. When the contact has at least
one `CampaignRecipient` with status `bounced` in a non-archived campaign,
the card SHALL show a visible mark indicating a bounced email.

#### Scenario: Раскрытие контактов организации по клику на строку
- **WHEN** пользователь на панели кликает по любой части строки организации (название, сфера деятельности, даты звонков), у которой есть контакты
- **THEN** под строкой отображаются карточки всех контактов этой организации
- **AND** каждая карточка содержит имя, телефон как кликабельную ссылку, email как кликабельную ссылку и кнопку «Изменить»-заглушку
- **AND** при заполненной заметке контакта карточка содержит строку «Заметка: …»
- **AND** на карточке нет кнопки «Позвонить» и других кнопок звонка
- **AND** повторный клик по строке организации скрывает контакты

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
- **THEN** под строкой отображается только кнопка «Добавить контакт»
- **AND** никакие карточки контактов и сообщения не показываются

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

#### Scenario: Автоматическое раскрытие при переходе с highlight
- **WHEN** пользователь создаёт контакт или добавляет звонок для организации
- **AND** происходит переход на панель с параметром `highlight=<organization_id>` в URL
- **THEN** строка организации подсвечивается
- **AND** секция контактов и звонков этой организации автоматически раскрыта
- **AND** пользователь видит карточки контактов и список звонков без дополнительного клика
