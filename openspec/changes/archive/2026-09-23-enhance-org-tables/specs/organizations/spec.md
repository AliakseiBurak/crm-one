## MODIFIED Requirements

### Requirement: Организация хранит курсы посещения
The system SHALL support a coursesAttended free-text field on Organization
(nullable string, column `courses_attended`). The field SHALL store
free-form text such as course names the organization's people have taken;
it SHALL NOT be a boolean flag. The database column SHALL be renamed from
`has_used_services` to `courses_attended` with type VARCHAR(255) DEFAULT NULL.
Existing boolean production data SHALL NOT be cleaned; MySQL will coerce
legacy `0`→`'0'`, `1`→`'1'`.

#### Scenario: Отметка о курсах
- **WHEN** администратор редактирует организацию "ООО Ромашка"
- **AND** вводит в поле "Учились у нас" текст "Курс по продажам"
- **THEN** поле coursesAttended организации сохраняет значение "Курс по продажам"

#### Scenario: Сброс курсов
- **WHEN** у организации "ООО Ромашка" заполнено поле coursesAttended
- **AND** администратор очищает это поле
- **THEN** поле coursesAttended сохраняется пустым (null)

#### Scenario: Организация без значения поля
- **WHEN** администратор создаёт организацию без указания поля "Учились у нас"
- **THEN** поле coursesAttended остаётся пустым (null)

## ADDED Requirements

### Requirement: Необязательное поле УНП организации
The system SHALL support an optional UNP field (Belarusian taxpayer
identification number, string, nullable) on Organization. The UNP SHALL be
editable in the organization create/edit form and in the quick-edit modal,
and SHALL be displayed in the expanded organization details row on the
dashboard. The UNP SHALL NOT be rendered as a column of the dashboard
organization table.

#### Scenario: Создание организации с УНП
- **WHEN** администратор создаёт организацию "ООО Ромашка" с УНП "100123456"
- **THEN** в карточке организации сохраняется УНП "100123456"

#### Scenario: Создание организации без УНП
- **WHEN** администратор создаёт организацию "ООО Ромашка" без указания УНП
- **THEN** поле УНП организации остаётся пустым (null)

#### Scenario: Редактирование УНП в quick-edit модалке
- **WHEN** пользователь открывает модальное окно быстрого редактирования организации "ООО Ромашка"
- **AND** вводит УНП "100987654"
- **AND** нажимает кнопку "Сохранить"
- **THEN** УНП организации становится "100987654"

#### Scenario: УНП виден в раскрытой строке организации
- **WHEN** пользователь раскрывает строку организации на панели
- **THEN** в раскрытой секции отображается значение УНП организации
- **AND** колонка УНП отсутствует в самой таблице панели

### Requirement: Организация хранит создателя
The system SHALL store a created_by reference on Organization identifying
the user who created the organization. The system SHALL set created_by to
the authenticated administrator or manager performing the create action.
Organization field order SHALL place created_by after the business fields
and before created_at and updated_at. Fixtures SHALL supply created_by
values; production data migration is out of scope for this change.

#### Scenario: Менеджер создаёт организацию — created_by заполняется
- **WHEN** аутентифицированный менеджер "Иван Петров" создаёт организацию "ООО Ромашка"
- **THEN** created_by организации равен пользователю "Иван Петров"

#### Scenario: Администратор создаёт организацию — created_by заполняется
- **WHEN** аутентифицированный администратор создаёт организацию "ООО Ромашка"
- **THEN** created_by организации равен этому администратору
