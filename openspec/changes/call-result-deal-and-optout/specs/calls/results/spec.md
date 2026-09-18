## MODIFIED Requirements

### Requirement: Независимые действия результата
The system SHALL treat deal, refusal, no-answer, next call, and mailing as independent actions. Any combination MAY be recorded in one save. When refusal is marked together with the checkbox «отметить отказ организации от рассылок» (full call form), the system SHALL set Organization.isOptedOut = true. The call result form SHALL NOT offer refusal-remove of a campaign recipient.

#### Scenario: Письмо и следующий звонок вместе
- **WHEN** менеджер завершает звонок по организации «ООО Ромашка»
- **AND** выбирает рассылку «Осенняя рассылка»
- **AND** указывает дату следующего звонка «завтра»
- **THEN** организация становится адресатом «Осенняя рассылка»
- **AND** создаётся новый звонок на «завтра»

#### Scenario: Нет ответа не мешает рассылке
- **WHEN** менеджер отмечает «нет ответа» для звонка по организации «ООО Ромашка»
- **AND** выбирает рассылку «Осенняя рассылка»
- **AND** сохраняет звонок
- **THEN** отметка «нет ответа» сохраняется
- **AND** организация становится адресатом «Осенняя рассылка»

#### Scenario: Отказ с отпиской от рассылок
- **WHEN** менеджер отмечает «отказ» для звонка по организации «ООО Ромашка»
- **AND** отмечает «отметить отказ организации от рассылок»
- **AND** сохраняет звонок
- **THEN** отметка «отказ» сохраняется
- **AND** у организации «ООО Ромашка» isOptedOut устанавливается в true

#### Scenario: Отказ без отписки от рассылок
- **WHEN** менеджер отмечает «отказ» для звонка по организации «ООО Ромашка»
- **AND** не отмечает «отметить отказ организации от рассылок»
- **AND** сохраняет звонок
- **THEN** отметка «отказ» сохраняется
- **AND** isOptedOut организации «ООО Ромашка» не изменяется

#### Scenario: Отказ и рассылка вместе
- **WHEN** менеджер отмечает «отказ» для звонка по организации «ООО Ромашка»
- **AND** отмечает «отметить отказ организации от рассылок»
- **AND** выбирает рассылку «Осенняя рассылка»
- **AND** сохраняет звонок
- **THEN** отметка «отказ» сохраняется
- **AND** организация становится адресатом «Осенняя рассылка»
- **AND** у организации «ООО Ромашка» isOptedOut устанавливается в true

#### Scenario: Только факт звонка
- **WHEN** менеджер завершает звонок по организации «ООО Ромашка»
- **AND** не выбирает рассылку, дату следующего звонка, сделку, отказ и «нет ответа»
- **THEN** фиксируется только факт звонка (кто и когда)

## ADDED Requirements

### Requirement: Эндпоинт автоотписки организации
The system SHALL provide a POST endpoint /organizations/{id}/opt-out for administrators and managers with access to the organization. The endpoint SHALL accept an optional reason field, SHALL set Organization.isOptedOut = true, and SHALL return the updated organization.

#### Scenario: Успешная отписка через эндпоинт
- **WHEN** аутентифицированный администратор отправляет POST /organizations/1/opt-out с телом {"reason": "Не актуально"}
- **THEN** система возвращает 200 OK
- **AND** у организации isOptedOut установлен в true
- **AND** optOutReason равен "Не актуально"

#### Scenario: Отписка без причины
- **WHEN** аутентифицированный администратор отправляет POST /organizations/1/opt-out с пустым телом
- **THEN** система возвращает 200 OK
- **AND** у организации isOptedOut установлен в true
- **AND** optOutReason равен null

#### Scenario: Менеджер отписывает доступную организацию
- **WHEN** аутентифицированный менеджер отправляет POST /organizations/{id}/opt-out
- **AND** организация входит в область доступа менеджера
- **THEN** система возвращает 200 OK
- **AND** у организации isOptedOut установлен в true

#### Scenario: Менеджер не может отписать недоступную организацию
- **WHEN** аутентифицированный менеджер отправляет POST /organizations/1/opt-out
- **AND** организация отсутствует в области доступа
- **THEN** система возвращает 403 Forbidden
- **AND** isOptedOut организации не изменяется