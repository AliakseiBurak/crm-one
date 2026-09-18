## MODIFIED Requirements

### Requirement: Результат звонка — комбинация полей
The system SHALL let the manager record a call result as independent actions on the call form and dashboard modal: deal, refusal, no-answer, next-call date, and mailing campaign (any status except `archived`) with recipient contact. Next-call date and mailing campaign fields SHALL be empty by default on each open (commands); the next-call date field SHALL be omitted when the call already has a linked next call. Deal and no-answer SHALL persist as checkboxes placed at the bottom of the form. Refusal SHALL appear as a checkbox; when checked on the full call form, the system SHALL show additional checkboxes «отметить организацию как неактивную» (sets Organization.isActive = false) and/or «отметить отказ организации от рассылок» (sets Organization.isOptedOut = true). These additional checkboxes SHALL NOT be available in the dashboard edit modal. After a validation error the system SHALL restore all submitted result values. The form SHALL NOT offer refusal-remove of a campaign recipient.

#### Scenario: Результат — сделка
- **WHEN** менеджер завершает звонок по организации «ООО Ромашка»
- **AND** отмечает «сделка совершена»
- **AND** нажимает кнопку «Сохранить»
- **THEN** в карточке звонка отображается отметка о совершённой сделке

#### Scenario: Результат — отказ
- **WHEN** менеджер завершает звонок по организации «ООО Ромашка»
- **AND** отмечает «отказ»
- **AND** не отмечает «отметить организацию как неактивную» и «отметить отказ организации от рассылок»
- **AND** нажимает кнопку «Сохранить»
- **THEN** в строке звонка отображается отметка об отказе
- **AND** isActive и isOptedOut организации «ООО Ромашка» не изменяются

#### Scenario: Отказ — только неактивная
- **WHEN** менеджер завершает звонок по организации «ООО Ромашка»
- **AND** отмечает «отказ»
- **AND** отмечает «отметить организацию как неактивную»
- **AND** не отмечает «отметить отказ организации от рассылок»
- **AND** нажимает кнопку «Сохранить»
- **THEN** у организации «ООО Ромашка» isActive установлен в false
- **AND** isOptedOut не изменяется

#### Scenario: Отказ — неактивная и отказ от рассылок
- **WHEN** менеджер завершает звонок по организации «ООО Ромашка»
- **AND** отмечает «отказ»
- **AND** отмечает «отметить организацию как неактивную»
- **AND** отмечает «отметить отказ организации от рассылок» с причиной "Закрылись"
- **AND** нажимает кнопку «Сохранить»
- **THEN** у организации «ООО Ромашка» isActive установлен в false
- **AND** isOptedOut установлен в true
- **AND** optOutReason равен "Закрылись"

#### Scenario: Результат — нет ответа
- **WHEN** менеджер завершает звонок по организации «ООО Ромашка»
- **AND** отмечает «нет ответа»
- **AND** нажимает кнопку «Сохранить»
- **THEN** в строке звонка отображается отметка об отсутствии ответа

#### Scenario: Результат — будущий звонок
- **WHEN** менеджер завершает звонок по организации «ООО Ромашка»
- **AND** указывает дату следующего звонка
- **AND** нажимает кнопку «Сохранить»
- **THEN** создаётся новый звонок с этой датой
- **AND** организация «ООО Ромашка» попадает в планирование

#### Scenario: Комбинация результатов
- **WHEN** менеджер завершает звонок по организации «ООО Ромашка»
- **AND** одновременно отмечает сделку, «нет ответа», отказ с неактивной, выбирает рассылку и назначает следующий звонок
- **AND** нажимает кнопку «Сохранить»
- **THEN** все выбранные действия выполняются вместе со звонком
- **AND** isActive организации установлен в false

#### Scenario: Звонок без результата
- **WHEN** менеджер завершает звонок по организации «ООО Ромашка»
- **AND** не выбирает сделку, отказ, «нет ответа», рассылку и следующий звонок
- **AND** нажимает кнопку «Сохранить»
- **THEN** фиксируется только факт звонка (кто и когда)

#### Scenario: Ошибка валидации восстанавливает действия результата
- **WHEN** менеджер выбирает рассылку «Осенняя рассылка», отмечает сделку и дату следующего звонка в прошлом
- **AND** нажимает кнопку «Сохранить»
- **THEN** форма показывает ошибку «Дата следующего звонка должна быть в будущем»
- **AND** в полях остаются выбранная рассылка, сделка и введённая дата
- **AND** звонок не сохраняется
- **AND** адресат рассылки не создаётся

### Requirement: Макет формы звонка
The call form and dashboard edit modal SHALL order fields as: organization, contact, notes, «Будущий звонок» checkbox, then either the scheduled date (future-call mode) or the fact/result command fields (normal mode), then deal, no-answer, and refusal checkbox. On the full call form, when refusal is checked, additional checkboxes «отметить организацию как неактивную» and «отметить отказ организации от рассылок» SHALL appear below refusal. In the dashboard edit modal, only the refusal checkbox SHALL appear without the additional sub-checkboxes. When «Будущий звонок» is checked, the system SHALL show the scheduled date field and SHALL hide all other fields except notes (organization and contact remain). When «Будущий звонок» is unchecked, the scheduled date field SHALL NOT be shown. The checkbox SHALL be checked by default when the call has a scheduled date on or after today and no actual date. When the call already has an actual date, the «Будущий звонок» checkbox and scheduled date field SHALL be unavailable.

#### Scenario: Контакт под организацией
- **WHEN** менеджер открывает форму создания или редактирования звонка
- **THEN** поле «Контакт» отображается сразу под полем «Организация»

#### Scenario: Заметка под контактом
- **WHEN** менеджер открывает форму создания или редактирования звонка
- **THEN** поле «Заметка» отображается сразу под полем «Контакт»

#### Scenario: Режим будущего звонка показывает дату плана
- **WHEN** менеджер отмечает «Будущий звонок»
- **THEN** появляется поле «Запланированная дата звонка»
- **AND** поля факта звонка, рассылки, следующего звонка, сделки, отказа и «нет ответа» скрыты
- **AND** поле «Заметка» остаётся видимым

#### Scenario: Без будущего звонка дата плана скрыта
- **WHEN** менеджер открывает форму звонка
- **AND** не отмечает «Будущий звонок»
- **THEN** поле «Запланированная дата звонка» не отображается
- **AND** доступны поля факта и результата звонка

#### Scenario: Сделка и нет ответа внизу формы
- **WHEN** менеджер открывает форму звонка без режима «Будущий звонок»
- **THEN** чекбоксы «Сделка совершена» и «Нет ответа» отображаются в самом низу формы

#### Scenario: Сделка, отказ и нет ответа внизу формы
- **WHEN** менеджер открывает форму звонка без режима «Будущий звонок»
- **THEN** чекбоксы «Сделка совершена», «Нет ответа» и «Отказ» отображаются в самом низу формы
- **AND** при отметке «Отказ» на полной форме появляются условные чекбоксы «отметить организацию как неактивную» и «отметить отказ организации от рассылок»

#### Scenario: Планирование через режим будущего звонка
- **WHEN** менеджер отмечает «Будущий звонок»
- **AND** указывает запланированную дату «завтра»
- **AND** нажимает кнопку «Сохранить»
- **THEN** звонок сохраняется с датой «завтра»
- **AND** факт звонка и действия результата не фиксируются

#### Scenario: Будущий звонок без даты плана игнорирует факт и результат
- **WHEN** менеджер отмечает «Будущий звонок»
- **AND** не заполняет запланированную дату
- **AND** в запросе есть фактическая дата и прочие заполненные поля
- **AND** нажимает кнопку «Сохранить»
- **THEN** звонок сохраняется без фактической даты и прочих заполненных полей
- **AND** сохраняются организация, контакт и заметка

#### Scenario: Фактическая дата предустановлена сейчас
- **WHEN** менеджер открывает форму создания звонка
- **THEN** поле «Фактическая дата звонка» содержит текущие дату и время

#### Scenario: Проведённый звонок нельзя перевести в будущий
- **WHEN** у звонка уже есть фактическая дата
- **AND** менеджер открывает форму или модальное окно этого звонка
- **THEN** чекбокс «Будущий звонок» недоступен
- **AND** поле «Запланированная дата звонка» недоступно

### Requirement: Модальное окно быстрого редактирования
The system SHALL provide a modal window for quick call editing from the dashboard without page reload. The modal SHALL include the same field order and future-call mode as the full call form and the same result actions (deal, refusal, no-answer, next-call date, mailing campaign). Refusal in the modal SHALL record only the refusal mark: the modal SHALL NOT include the refusal sub-checkboxes («отметить организацию как неактивную» and «отметить отказ организации от рассылок») and SHALL NOT change Organization.isActive or Organization.isOptedOut; these checkboxes are available only on the full call form. The system SHALL provide the POST /organizations/{id}/opt-out endpoint for explicit organization opt-out.

#### Scenario: Открытие модального окна
- **WHEN** пользователь нажимает кнопку «Изменить» в строке звонка на дашборде
- **THEN** открывается модальное окно с формой редактирования звонка
- **AND** данные звонка загружаются в форму

#### Scenario: Сохранение изменений в модальном окне
- **WHEN** пользователь изменяет заметки в модальном окне
- **AND** нажимает кнопку «Сохранить»
- **THEN** изменения сохраняются
- **AND** модальное окно закрывается
- **AND** строка звонка на дашборде обновляется без перезагрузки страницы

#### Scenario: Новый следующий звонок появляется в списке после сохранения в модалке
- **WHEN** пользователь в модальном окне указывает дату следующего звонка
- **AND** нажимает кнопку «Сохранить»
- **THEN** создаётся новый звонок
- **AND** новая строка появляется в списке «Все звонки» организации без перезагрузки страницы
- **AND** счётчик «Все звонки» увеличивается на 1

#### Scenario: Закрытие модального окна
- **WHEN** пользователь нажимает кнопку «Отмена» или крестик в модальном окне
- **THEN** модальное окно закрывается
- **AND** изменения не сохраняются

#### Scenario: Действия результата в модальном окне
- **WHEN** пользователь открывает модальное окно проведённого звонка без связанного следующего звонка
- **THEN** в форме доступны отметки сделки, отказа, «нет ответа», поле даты следующего звонка и выбор рассылки (кроме архивных)
- **AND** чекбоксы «отметить организацию как неактивную» и «отметить отказ организации от рассылок» в модальном окне не отображаются

#### Scenario: Поле следующего звонка скрыто если он уже создан
- **WHEN** у звонка уже есть связанный следующий звонок
- **AND** пользователь открывает форму или модальное окно этого звонка
- **THEN** поле даты следующего звонка не отображается
- **AND** связанный следующий звонок из этой формы изменить или удалить нельзя

#### Scenario: После удаления следующего звонка поле даты снова доступно
- **WHEN** связанный следующий звонок удалён
- **AND** пользователь открывает форму исходного звонка
- **THEN** поле даты следующего звонка отображается пустым

## ADDED Requirements

### Requirement: Эндпоинт автоотписки организации
The system SHALL provide a POST /organizations/{id}/opt-out endpoint for administrators and managers with access to the organization. The endpoint SHALL accept { reason: string|null }, SHALL set Organization.isOptedOut = true and Organization.optOutReason, and SHALL return JSON with the updated organization data. Access SHALL be denied with 403 for managers whose access scope does not include the organization.

#### Scenario: Успешная отписка через эндпоинт
- **WHEN** аутентифицированный менеджер отправляет POST /organizations/1/opt-out с телом {"reason": "Не заинтересованы"}
- **THEN** система возвращает 200 OK с JSON-объектом организации
- **AND** у организации isOptedOut установлен в true
- **AND** optOutReason равен "Не заинтересованы"

#### Scenario: Отписка без причины
- **WHEN** аутентифицированный администратор отправляет POST /organizations/1/opt-out
- **THEN** система возвращает 200 OK
- **AND** у организации isOptedOut установлен в true
- **AND** optOutReason равен null

#### Scenario: Менеджер не может отписать недоступную организацию
- **WHEN** аутентифицированный менеджер отправляет POST /organizations/1/opt-out
- **AND** организация отсутствует в области доступа
- **THEN** система возвращает 403 Forbidden
- **AND** isOptedOut организации не изменяется

#### Scenario: Отписка несуществующей организации
- **WHEN** аутентифицированный пользователь отправляет POST /organizations/999/opt-out
- **AND** организация с ID 999 не существует
- **THEN** система возвращает 404 Not Found