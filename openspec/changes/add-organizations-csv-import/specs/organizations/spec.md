# Organizations (delta)

## ADDED Requirements

### Requirement: Необязательное поле сайта организации

The system SHALL support an optional website field (string 255, nullable) on
Organization. The website SHALL be editable in the organization create/edit form
and in the quick-edit modal, and SHALL be displayed in the expanded organization
details row on the dashboard. The website SHALL also be a sortable column of the
dashboard organization table, rendered as the bare domain (for example
`armis.by`) and linking to the full address. The stored value SHALL NOT be
silently rewritten when it is displayed as a domain.

#### Scenario: Создание организации с сайтом

- **WHEN** администратор создаёт организацию "ООО Ромашка" с сайтом "https://romashka.by"
- **THEN** в карточке организации сохраняется сайт "https://romashka.by"

#### Scenario: Создание организации без сайта

- **WHEN** администратор создаёт организацию "ООО Ромашка" без указания сайта
- **THEN** поле сайта организации остаётся пустым (null)

#### Scenario: Сайт виден в раскрытой строке организации

- **WHEN** пользователь раскрывает строку организации на панели
- **THEN** в раскрытой секции отображается значение поля сайта

#### Scenario: Сайт колонкой таблицы организаций

- **WHEN** администратор открывает панель с организациями
- **THEN** в таблице присутствует колонка «Сайт»
- **AND** для организации "ООО Ромашка" с сайтом "https://romashka.by" в ячейке отображается "romashka.by"
- **AND** значение в ячейке является ссылкой на "https://romashka.by"

#### Scenario: Пустой сайт в таблице

- **WHEN** организация не имеет сайта
- **THEN** ячейка колонки «Сайт» пуста

#### Scenario: Сортировка таблицы по сайту

- **WHEN** администратор сортирует таблицу организаций по колонке «Сайт»
- **THEN** организации располагаются в порядке возрастания или убывания значения сайта
- **AND** при равных значениях порядок определяется названием по возрастанию

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
