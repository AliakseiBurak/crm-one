## MODIFIED Requirements

### Requirement: Администратор управляет карточками организаций
The system SHALL let the administrator create, view, update, and delete organizations, and SHALL return organization details by identifier. The organization name SHALL be required; industry SHALL be optional.

#### Scenario: Админ создаёт организацию
- **WHEN** аутентифицированный администратор создаёт организацию с названием "ООО Ромашка" и отраслью "IT"
- **THEN** в системе появляется организация "ООО Ромашка" с отраслью "IT"

#### Scenario: Админ создаёт организацию без отрасли
- **WHEN** аутентифицированный администратор создаёт организацию с названием "ООО Ромашка" и без отрасли
- **THEN** в системе появляется организация "ООО Ромашка" с пустым полем отрасли

#### Scenario: Админ обновляет организацию
- **WHEN** в системе существует организация "ООО Ромашка" с отраслью "IT"
- **AND** администратор изменяет её отрасль на "Маркетинг"
- **THEN** отрасль организации становится "Маркетинг"

#### Scenario: Админ удаляет организацию
- **WHEN** в системе существует организация "ООО Ромашка"
- **AND** администратор удаляет её
- **THEN** организация "ООО Ромашка" больше не отображается в списке

## ADDED Requirements

### Requirement: Организация имеет годовой план и описание
The system SHALL store an optional annual plan indicator (annualPlan) and an optional description on the organization. The annualPlan SHALL be a free-text or date string indicating the month or date of the training plan. The description SHALL be free-form text.

#### Scenario: Создание организации с годовым планом и описанием
- **WHEN** администратор создаёт организацию с названием "ООО Ромашка"
- **AND** указывает годовой план "Сентябрь 2026"
- **AND** указывает описание "Крупный клиент из сферы IT"
- **THEN** в системе появляется организация "ООО Ромашка" с годовым планом "Сентябрь 2026" и описанием "Крупный клиент из сферы IT"

#### Scenario: Обновление годового плана
- **WHEN** в системе существует организация "ООО Ромашка" с годовым планом "Сентябрь 2026"
- **AND** администратор изменяет годовой план на "Октябрь 2026"
- **THEN** годовой план организации становится "Октябрь 2026"

#### Scenario: Организация без годового плана и описания
- **WHEN** администратор создаёт организацию с названием "ООО Ромашка"
- **AND** не заполняет годовой план и описание
- **THEN** в системе появляется организация "ООО Ромашка" с пустыми полями годового плана и описания

### Requirement: Организация может быть отмечена как пользовавшаяся услугами
The system SHALL store a boolean flag hasUsedServices on the organization, defaulting to false. The flag SHALL be editable by administrators and managers with access to the organization.

#### Scenario: Отметка об использовании услуг
- **WHEN** администратор редактирует организацию "ООО Ромашка"
- **AND** отмечает "пользовались услугами"
- **THEN** у организации "ООО Ромашка" флаг hasUsedServices установлен в true

#### Scenario: Сброс отметки об услугах
- **WHEN** у организации "ООО Ромашка" установлен флаг hasUsedServices
- **AND** администратор снимает отметку "пользовались услугами"
- **THEN** у организации "ООО Ромашка" флаг hasUsedServices установлен в false
