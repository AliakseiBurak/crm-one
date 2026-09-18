# Organizations

Организация — центральная сущность CRM: карточка компании, к которой привязаны
контакты, звонки, рассылки и предложения курсов. Видимость организаций
определяется моделью доступа (`adr/0006–0008, 0011`): менеджер видит организации
групп, созданных им (`created_by`), и назначенных групп, администратор — все.

## Purpose

Организация — центральная сущность CRM: карточка компании с контактами,
звонками, рассылками и предложениями курсов; видимость определяется моделью
доступа.

## Requirements

### Requirement: Администратор управляет карточками организаций
The system SHALL let the administrator create, view, update, and delete
organizations, and SHALL return organization details by identifier.
Organization name SHALL be required; industry SHALL be optional.

#### Scenario: Админ создаёт организацию
- **WHEN** аутентифицированный администратор создаёт организацию с названием "ООО Ромашка" и отраслью "IT"
- **THEN** в системе появляется организация "ООО Ромашка" с отраслью "IT"

#### Scenario: Админ создаёт организацию без отрасли
- **WHEN** аутентифицированный администратор создаёт организацию с названием "ООО Ромашка" и не указывает отрасль
- **THEN** в системе появляется организация "ООО Ромашка" с пустым значением отрасли

#### Scenario: Админ обновляет организацию
- **WHEN** в системе существует организация "ООО Ромашка" с отраслью "IT"
- **AND** администратор изменяет её отрасль на "Маркетинг"
- **THEN** отрасль организации становится "Маркетинг"

#### Scenario: Админ удаляет организацию
- **WHEN** в системе существует организация "ООО Ромашка"
- **AND** администратор удаляет её
- **THEN** организация "ООО Ромашка" больше не отображается в списке

### Requirement: Организация имеет годовой план и описание
The system SHALL support optional annualPlan (string 255) and description
(text) fields on Organization, both nullable.

#### Scenario: Создание организации с годовым планом и описанием
- **WHEN** администратор создаёт организацию "ООО Ромашка" с годовым планом "100 млн" и описанием "Крупная IT-компания"
- **THEN** годовой план и описание сохраняются в карточке организации

#### Scenario: Обновление годового плана
- **WHEN** администратор изменяет годовой план организации "ООО Ромашка" на "150 млн"
- **THEN** годовой план организации обновляется на "150 млн"

#### Scenario: Организация без годового плана и описания
- **WHEN** администратор создаёт организацию без указания годового плана и описания
- **THEN** годовой план и описание остаются пустыми (null)

### Requirement: Организация может быть отмечена как пользовавшаяся услугами
The system SHALL support a hasUsedServices boolean flag on Organization,
defaulting to false.

#### Scenario: Отметка об использовании услуг
- **WHEN** администратор отмечает организацию "ООО Ромашка" как пользовавшуюся услугами
- **THEN** флаг "Пользовались услугами" устанавливается в true

#### Scenario: Сброс отметки об услугах
- **WHEN** администратор снимает отметку "Пользовались услугами" с организации "ООО Ромашка"
- **THEN** флаг "Пользовались услугами" устанавливается в false

### Requirement: Менеджер управляет видимыми организациями
The system SHALL let the manager create organizations, and SHALL let the
manager view, update, and delete only the organizations visible to them
(organizations of groups they created (`created_by`) and assigned custom
groups). The
system SHALL deny the manager access to organizations outside this scope.

#### Scenario: Менеджер создаёт организацию
- **WHEN** аутентифицированный менеджер создаёт организацию с названием "ООО Ромашка"
- **AND** выбирает доступные группы (чекбоксы) при создании
- **THEN** организация "ООО Ромашка" появляется в списке менеджера
- **AND** организация добавляется в выбранные группы

#### Scenario: Менеджер изменяет видимую организацию
- **WHEN** в системе существует организация "ООО Ромашка", видимая менеджеру
- **AND** менеджер изменяет её отрасль на "Маркетинг"
- **THEN** отрасль организации становится "Маркетинг"
- **AND** изменения видны всем, у кого эта организация в области доступа

#### Scenario: Менеджер не может изменить невидимую организацию
- **WHEN** в системе существует организация "ООО Завод", отсутствующая в области доступа менеджера
- **AND** менеджер пытается изменить или удалить её
- **THEN** система отклоняет запрос
- **AND** запись об организации не изменяется

#### Scenario: Менеджер удаляет видимую организацию
- **WHEN** в системе существует организация "ООО Ромашка", видимая менеджеру
- **AND** менеджер удаляет её
- **THEN** организация "ООО Ромашка" больше не отображается в списке менеджера

### Requirement: Поиск организаций по названию и отрасли
The system SHALL search organizations by name and industry, and search results
SHALL be returned in less than 500 ms.

#### Scenario: Поиск по названию
- **WHEN** в системе есть организации "ООО Ромашка" и "ОАО Завод"
- **AND** пользователь выполняет поиск по слову "Ромашка"
- **THEN** в результатах есть только организация "ООО Ромашка"

#### Scenario: Поиск по отрасли
- **WHEN** в системе есть организации с отраслями "IT" и "Маркетинг"
- **AND** пользователь применяет фильтр по отрасли "Маркетинг"
- **THEN** в результатах есть только организации с отраслью "Маркетинг"

### Requirement: Синхронизация организаций из внешних источников
The system SHALL support importing organizations from external sources and
SHALL refresh the cards of existing organizations.

#### Scenario: Импорт новой организации
- **WHEN** во внешнем источнике есть организация "ООО Новый клиент"
- **AND** система выполняет синхронизацию из внешнего источника
- **THEN** в системе появляется организация "ООО Новый клиент"

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
