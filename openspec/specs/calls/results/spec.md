# calls/results Specification

## Purpose

Операционные действия по итогам проведённого звонка: поставить организацию в
рассылку или отправить письмо повторно, запланировать следующий звонок,
отметить сделку или отсутствие ответа — по отдельности или вместе.

## Requirements

### Requirement: Действия результата только у проведённого звонка
The system SHALL accept mailing, next-call, deal, and no-answer actions only when the call records the fact of the call (`madeAt` and `madeBy`). A call missing either SHALL reject those actions with a validation error.

#### Scenario: Действие без фактической даты отклоняется
- **WHEN** менеджер указывает кампанию рассылки для звонка без фактической даты
- **AND** сохраняет звонок
- **THEN** система отвечает ошибкой валидации
- **AND** адресат в рассылке не создаётся

#### Scenario: Действие без автора звонка отклоняется
- **WHEN** у звонка заполнена фактическая дата, но автор не указан
- **AND** менеджер выбирает рассылку и сохраняет звонок
- **THEN** система отвечает ошибкой валидации
- **AND** адресат в рассылке не создаётся

#### Scenario: Проведённый звонок принимает действия
- **WHEN** менеджер заполняет фактическую дату звонка по организации «ООО Ромашка»
- **AND** выбирает рассылку «Осенняя рассылка»
- **AND** сохраняет звонок
- **THEN** организация «ООО Ромашка» становится адресатом рассылки «Осенняя рассылка»

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

### Requirement: Следующий звонок создаётся по дате
The system SHALL create a new call for the same organization when the manager submits a next-call date in the future and the current call has no linked next call. An empty next-call date SHALL NOT create a call. A next-call date in the past SHALL be rejected with a validation error; no new call SHALL be created. When the call already has a linked next call, the system SHALL NOT create another call from this form and SHALL NOT let the manager edit or delete that next call here; the manager SHALL change or delete that future call through its own form (dashboard list of the organization). Deleting the linked next call SHALL set the originating call's next-call reference to empty; the originating call's form SHALL then show the next-call date field again.

#### Scenario: Дата создаёт новый звонок
- **WHEN** менеджер указывает дату следующего звонка «10.09.2026» для звонка по организации «ООО Ромашка»
- **AND** сохраняет звонок
- **THEN** создаётся новый звонок организации «ООО Ромашка» с запланированной датой «10.09.2026»
- **AND** исходный звонок ссылается на этот новый звонок

#### Scenario: Пустая дата не создаёт звонок
- **WHEN** менеджер сохраняет форму с пустой датой следующего звонка
- **THEN** новый звонок не создаётся

#### Scenario: Дата в прошлом отклоняется
- **WHEN** менеджер указывает дату следующего звонка «01.09.2026» при текущей дате «02.09.2026»
- **AND** сохраняет звонок
- **THEN** форма показывает ошибку «Дата следующего звонка должна быть в будущем»
- **AND** новый звонок не создаётся

#### Scenario: Связанный следующий звонок не меняется из этой формы
- **WHEN** у звонка уже есть связанный следующий звонок на «10.09.2026»
- **AND** менеджер открывает форму редактирования исходного звонка
- **THEN** поле даты следующего звонка недоступно
- **AND** следующий звонок на «10.09.2026» можно изменить или удалить только через его собственную форму в списке звонков организации

#### Scenario: Удаление следующего звонка снова позволяет назначить дату
- **WHEN** у исходного звонка связан следующий звонок на «10.09.2026»
- **AND** менеджер удаляет звонок на «10.09.2026» из списка организации
- **THEN** ссылка исходного звонка на следующий звонок становится пустой
- **AND** в форме исходного звонка снова доступно поле даты следующего звонка

### Requirement: Рассылка ставит организацию в адресаты
The system SHALL offer campaigns in any status except `archived` for the mailing action. Submitting a campaign SHALL create a `CampaignRecipient` for the call's organization when none exists. The recipient contact SHALL default to the call's contact and MAY be cleared to mean the whole organization. An empty campaign field SHALL NOT change recipients. An archived campaign SHALL NOT appear in the mailing list and SHALL be rejected if submitted.

#### Scenario: Первое включение в готовую рассылку
- **WHEN** менеджер выбирает готовую рассылку «Осенняя рассылка» для звонка по организации «ООО Ромашка»
- **AND** сохраняет звонок
- **THEN** создаётся адресат «ООО Ромашка» у «Осенняя рассылка»
- **AND** повторная отправка не выполняется

#### Scenario: Черновик доступен для выбора
- **WHEN** в системе есть рассылка «Новые курсы» со статусом `draft`
- **AND** менеджер открывает блок рассылки в результате звонка
- **THEN** «Новые курсы» доступна в списке рассылок

#### Scenario: Архивная рассылка недоступна
- **WHEN** в системе есть рассылка «Прошлая акция» со статусом `archived`
- **AND** менеджер открывает блок рассылки в результате звонка
- **THEN** «Прошлая акция» отсутствует в списке рассылок

#### Scenario: Контакт звонка предвыбирается
- **WHEN** звонок по организации «ООО Ромашка» связан с контактом «Иван Петров»
- **AND** менеджер открывает блок рассылки
- **THEN** в поле контакта адресата выбран «Иван Петров»
- **AND** менеджер может выбрать «вся организация»

#### Scenario: Пустое поле рассылки не трогает адресатов
- **WHEN** организация «ООО Ромашка» уже адресат рассылки «Осенняя рассылка»
- **AND** менеджер сохраняет звонок, не выбирая кампанию в поле рассылки
- **THEN** состав адресатов не меняется

### Requirement: Повторный выбор рассылки заменяет адресата
When a recipient already exists for the organization and the chosen campaign, submitting the mailing action SHALL replace that recipient (including the same contact). If the campaign has `launchedAt` set, the system SHALL increment `replacementCount`, leave the new recipient pending so the letter is sent again, and show a flash that the email will be resent. If the campaign has not been launched, the system SHALL replace without incrementing the counter and SHALL NOT claim a resend.

#### Scenario: Тот же контакт в запущенной рассылке — повторная отправка
- **WHEN** рассылка «Акция» уже запущена и имеет адресата «ООО Ромашка» с контактом «Иван Петров»
- **AND** менеджер снова выбирает «Акция» и контакт «Иван Петров» в результате звонка
- **AND** сохраняет звонок
- **THEN** адресат заменяется
- **AND** счётчик повторных отправок увеличивается на 1
- **AND** система показывает flash о повторной отправке письма

#### Scenario: Замена контакта в ещё не запущенной рассылке
- **WHEN** рассылка «Осенняя рассылка» не запускалась и имеет адресата «ООО Ромашка»
- **AND** менеджер выбирает ту же рассылку с другим контактом
- **AND** сохраняет звонок
- **THEN** адресат заменяется
- **AND** счётчик повторных отправок не увеличивается
- **AND** flash о повторной отправке не показывается

### Requirement: Удаление звонка не откатывает рассылку и следующие звонки
Deleting a call SHALL remove the call record and SHALL NOT remove `CampaignRecipient` rows created from its mailing action. Generated next calls SHALL remain. When the call has a campaign reference, the confirmation page SHALL state that the recipient stays and SHALL offer a link to that campaign's recipients page opening in a new browsing context.

#### Scenario: Адресат остаётся после удаления звонка
- **WHEN** звонок по организации «ООО Ромашка» связан с рассылкой «Осенняя рассылка»
- **AND** менеджер подтверждает удаление звонка
- **THEN** звонок удаляется
- **AND** адресат «ООО Ромашка» у «Осенняя рассылка» остаётся

#### Scenario: Предупреждение со ссылкой на адресатов
- **WHEN** менеджер открывает страницу «Удаление звонка» для звонка с выбранной рассылкой «Осенняя рассылка»
- **THEN** предупреждение сообщает, что адресат рассылки не будет удалён
- **AND** на странице есть ссылка на страницу адресатов «Осенняя рассылка», открываемая в новом окне

### Requirement: Доступ к действиям результата ограничен областью доступа
The system SHALL allow a manager to perform result actions only for calls of organizations in their access scope (`adr/0007`) and SHALL deny others with HTTP 403.

#### Scenario: Отказ вне области доступа
- **WHEN** звонок по организации «ООО Конкурент» отсутствует в области доступа менеджера
- **AND** менеджер пытается выбрать рассылку в результате этого звонка
- **THEN** система отклоняет запрос с ошибкой 403
- **AND** адресат не создаётся
