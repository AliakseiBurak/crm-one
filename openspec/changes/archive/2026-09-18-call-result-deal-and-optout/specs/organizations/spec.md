## ADDED Requirements

### Requirement: Организация имеет метку активности
The system SHALL store an active flag isActive (boolean, default true) on the organization. The flag SHALL be editable by administrators and managers with access to the organization. When isActive is false, the organization SHALL be considered inactive by the system.

#### Scenario: Создание организации с isActive по умолчанию
- **WHEN** администратор создаёт организацию "ООО Ромашка"
- **THEN** isActive организации равен true

#### Scenario: Отметка организации как неактивной
- **WHEN** администратор редактирует организацию "ООО Ромашка"
- **AND** снимает отметку "Активна"
- **THEN** isActive организации становится false

#### Scenario: Восстановление активности
- **WHEN** администратор редактирует неактивную организацию "ООО Ромашка"
- **AND** устанавливает отметку "Активна"
- **THEN** isActive организации становится true

### Requirement: Организация может быть отмечена как отказавшаяся от рассылки
The system SHALL store an opt-out flag isOptedOut (boolean, default false), an optional reason optOutReason (text, nullable) and an opt-out timestamp optedOutAt (datetime, nullable) on the organization. The call result form for a call with result «отказ» SHALL offer the checkbox «отметить отказ организации от рассылок»; when the manager marks it, the system SHALL set isOptedOut = true. The optOutReason SHALL be editable only when isOptedOut is true. When isOptedOut changes from false to true, the system SHALL set optedOutAt; when isOptedOut becomes false, the system SHALL reset optOutReason and optedOutAt to null.

#### Scenario: Автоотписка при отказе
- **WHEN** менеджер завершает звонок по организации "ООО Ромашка" с результатом «отказ»
- **AND** отмечает чекбокс «отметить отказ организации от рассылок»
- **THEN** у организации "ООО Ромашка" isOptedOut установлен в true
- **AND** optedOutAt содержит момент сохранения звонка

#### Scenario: Автоотписка с причиной
- **WHEN** менеджер завершает звонок по организации "ООО Ромашка" с результатом «отказ»
- **AND** отмечает чекбокс «отметить отказ организации от рассылок» и указывает причину отказа "Не заинтересованы"
- **THEN** у организации "ООО Ромашка" isOptedOut установлен в true
- **AND** optOutReason равен "Не заинтересованы"

#### Scenario: Отписка через эндпоинт
- **WHEN** администратор отправляет POST /organizations/{id}/opt-out с причиной "Другая причина"
- **THEN** у организации isOptedOut установлен в true
- **AND** optOutReason равен "Другая причина"

#### Scenario: Отказ от рассылки с причиной в форме организации
- **WHEN** администратор редактирует организацию "ООО Ромашка"
- **AND** отмечает "отказ от рассылки"
- **AND** заполняет причину отказа "Не заинтересованы"
- **THEN** у организации "ООО Ромашка" isOptedOut установлен в true
- **AND** optOutReason равен "Не заинтересованы"

#### Scenario: Отказ от рассылки без причины
- **WHEN** администратор редактирует организацию "ООО Ромашка"
- **AND** отмечает "отказ от рассылки"
- **AND** оставляет причину отказа пустой
- **THEN** у организации "ООО Ромашка" isOptedOut установлен в true
- **AND** optOutReason равен null

#### Scenario: Снятие отказа сбрасывает причину и дату
- **WHEN** у организации "ООО Ромашка" установлен isOptedOut с причиной отказа и optedOutAt
- **AND** администратор снимает отметку "отказ от рассылки"
- **THEN** isOptedOut становится false
- **AND** optOutReason сбрасывается в null
- **AND** optedOutAt сбрасывается в null

#### Scenario: Редактирование причины при активном отказе
- **WHEN** у организации "ООО Ромашка" isOptedOut установлен в true с причиной "Не заинтересованы"
- **AND** администратор изменяет причину на "Другая причина"
- **THEN** optOutReason становится "Другая причина"
- **AND** optedOutAt не изменяется
