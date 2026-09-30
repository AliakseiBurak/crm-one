# Organizations (delta)

## ADDED Requirements

### Requirement: Необязательное поле сайта организации

The system SHALL support an optional website field (string 255, nullable) on
Organization. The website SHALL be editable in the organization create/edit form
and in the quick-edit modal, and SHALL be displayed in the expanded organization
details row on the dashboard as stored. The website SHALL NOT be rendered as a
column of the dashboard organization table and SHALL NOT be sortable there,
because that table holds five columns and the website is needed when reading a
card rather than when scanning the list. The stored value SHALL NOT be rewritten
for display.

#### Scenario: Создание организации с сайтом

- **WHEN** администратор создаёт организацию "ООО Ромашка" с сайтом "https://romashka.by"
- **THEN** в карточке организации сохраняется сайт "https://romashka.by"

#### Scenario: Создание организации без сайта

- **WHEN** администратор создаёт организацию "ООО Ромашка" без указания сайта
- **THEN** поле сайта организации остаётся пустым (null)

#### Scenario: Сайт виден в раскрытой строке организации

- **WHEN** пользователь раскрывает строку организации на панели
- **THEN** в раскрытой секции отображается значение поля сайта

#### Scenario: Пустой сайт в раскрытой строке

- **WHEN** организация не имеет сайта
- **THEN** в раскрытой строке вместо сайта отображается «—»

#### Scenario: Сайт колонкой таблицы не отображается

- **WHEN** администратор открывает панель с организациями
- **THEN** в таблице отсутствует колонка «Сайт»
- **AND** среди сортируемых заголовков отсутствует «Сайт»

#### Scenario: Сайт отображается как сохранён

- **WHEN** организация "ООО Ромашка" имеет сайт "https://romashka.by/contacts/"
- **AND** пользователь раскрывает строку организации на панели
- **THEN** в раскрытой секции отображается "https://romashka.by/contacts/"
- **AND** значение не переписывается в домен

#### Scenario: Редактирование сайта в quick-edit модалке

- **WHEN** пользователь открывает модальное окно быстрого редактирования организации "ООО Ромашка"
- **AND** вводит сайт "https://romashka.by"
- **AND** нажимает кнопку "Сохранить"
- **THEN** поле сайта организации становится равным "https://romashka.by"

### Requirement: Необязательное поле города организации

The system SHALL support an optional city field (string 255, nullable) on
Organization. The city SHALL be editable in the organization create/edit form
and in the quick-edit modal, and SHALL be displayed in the expanded organization
details row on the dashboard. The city SHALL NOT be rendered as a column of the
dashboard organization table.

#### Scenario: Создание организации с городом

- **WHEN** администратор создаёт организацию "ООО Ромашка" с городом "Минск"
- **THEN** в карточке организации сохраняется город "Минск"

#### Scenario: Создание организации без города

- **WHEN** администратор создаёт организацию "ООО Ромашка" без указания города
- **THEN** поле города организации остаётся пустым (null)

#### Scenario: Город виден в раскрытой строке организации

- **WHEN** пользователь раскрывает строку организации на панели
- **THEN** в раскрытой секции отображается значение поля города

#### Scenario: Город отсутствует в таблице организаций

- **WHEN** администратор открывает панель с организациями
- **THEN** в таблице отсутствует колонка «Город»

#### Scenario: Редактирование города в quick-edit модалке

- **WHEN** пользователь открывает модальное окно быстрого редактирования организации "ООО Ромашка"
- **AND** вводит город "Брест"
- **AND** нажимает кнопку "Сохранить"
- **THEN** поле города организации становится равным "Брест"
