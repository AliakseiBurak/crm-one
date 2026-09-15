## MODIFIED Requirements

### Requirement: Генерация письма по шаблону
The system SHALL generate each email from the campaign's stored subject, preview text, and body by filling tokens (`{{greeting}}`, `{{contact_name}}`, `{{organization_name}}`, `{{unsubscribe_url}}`). The `{{greeting}}` token SHALL resolve to "Уважаемый(ая) {contact_name}" when a contact is set, or "Уважаемые сотрудники {organization_name}" otherwise. The `{{unsubscribe_url}}` token SHALL resolve to an absolute URL that, when visited, opts the organization out of the campaign (sets Organization.isOptedOut = true). The unsubscribe URL SHALL use the recipient's tracking token for authentication.

#### Scenario: Приветствие с контактом
- **WHEN** рассылка «Новые курсы» отправляется организации «ООО Ромашка» контакту «Иван Петров»
- **AND** текст письма содержит токен `{{greeting}}`
- **THEN** в письме вместо токена `{{greeting}}` подставлено "Уважаемый(ая) Иван Петров"

#### Scenario: Приветствие без контакта
- **WHEN** рассылка «Новые курсы» отправляется организации «ООО Ромашка» без указания контакта
- **AND** текст письма содержит токен `{{greeting}}`
- **THEN** в письме вместо токена `{{greeting}}` подставлено "Уважаемые сотрудники ООО Ромашка"

#### Scenario: Подстановка имени организации
- **WHEN** рассылка «Новые курсы» содержит текст с токеном `{{organization_name}}`
- **AND** рассылка отправляется организации «ООО Ромашка»
- **THEN** в письме вместо токена `{{organization_name}}` подставлено "ООО Ромашка"

#### Scenario: Подстановка ссылки отписки
- **WHEN** рассылка «Новые курсы» содержит текст с токеном `{{unsubscribe_url}}`
- **AND** рассылка отправляется организации «ООО Ромашка» с tracking_token = "abc123"
- **THEN** в письме вместо токена `{{unsubscribe_url}}` подставлен абсолютный URL вида `https://домен/unsubscribe/abc123`

#### Scenario: Переход по ссылке отписки отписывает организацию
- **WHEN** получатель переходит по URL, сгенерированному из `{{unsubscribe_url}}`
- **THEN** организация получателя отмечается как isOptedOut = true
- **AND** отображается страница подтверждения «Вы отписались от рассылки»

#### Scenario: Встроенные курсы в письме
- **WHEN** рассылка содержит текст и вложения
- **AND** система формирует письмо
- **THEN** в письмо включается текст и вложения рассылки

#### Scenario: Тема письма
- **WHEN** рассылка «Новые курсы» имеет тему «Приглашаем на курсы»
- **AND** рассылка отправляется организации «ООО Ромашка»
- **THEN** тема отправленного письма содержит «Приглашаем на курсы»

## ADDED Requirements

### Requirement: Интерфейс вставки токена отписки на странице кампании
The system SHALL provide a button «Вставить отписку» on the campaign edit page, positioned above or next to the email body editor. Clicking the button SHALL insert the `{{unsubscribe_url}}` token at the cursor position in the body textarea. The button SHALL be available for all campaign statuses except `archived`. A tooltip or label next to the button SHALL explain: «Вставляет ссылку отписки от рассылки. Получатель сможет отказаться от писем по этой ссылке.»

#### Scenario: Кнопка вставки отписки на странице редактирования
- **WHEN** администратор открывает страницу редактирования кампании в статусе `draft`
- **THEN** над редактором текста письма отображается кнопка «Вставить отписку»
- **AND** рядом с кнопкой есть подсказка о её назначении

#### Scenario: Вставка токена в тело письма
- **WHEN** администратор нажимает кнопку «Вставить отписку»
- **THEN** в тело письма на позиции курсора вставляется токен `{{unsubscribe_url}}`

#### Scenario: Кнопка недоступна для архивированной кампании
- **WHEN** кампания имеет статус `archived`
- **THEN** кнопка «Вставить отписку» не отображается

#### Scenario: Дважды нажатая кнопка вставляет второй токен
- **WHEN** администратор дважды нажимает «Вставить отписку»
- **THEN** токен `{{unsubscribe_url}}` вставляется дважды (в первой и второй позициях курсора)

### Requirement: Исключение отписавшихся организаций из рассылок
The system SHALL exclude organizations with `isOptedOut = true` from campaign recipients on every creation path (manual add, «Выбрать все организации», добавление по группе) and SHALL re-check the flag at send time: a recipient whose organization opted out after being added SHALL be marked `failed` with errorMessage «Организация отписана от рассылок» and SHALL NOT receive the email. The rule SHALL apply before the «нет e-mail» check.

#### Scenario: Ручное добавление отписавшейся организации
- **WHEN** менеджер выбирает организацию «ООО Ромашка» с `isOptedOut = true` и нажимает «Добавить»
- **THEN** система перенаправляет обратно на страницу адресатов
- **AND** отображается flash-сообщение: «Организация отписана от рассылок»
- **AND** организация не добавляется в адресаты

#### Scenario: Массовое добавление пропускает отписавшиеся
- **WHEN** менеджер нажимает «Выбрать все организации»
- **AND** среди доступных есть 1 отписавшаяся организация
- **THEN** она не добавляется в адресаты
- **AND** сообщение о результате учитывает её в пропущенных

#### Scenario: Отписка после добавления — письмо не отправляется
- **WHEN** организация уже добавлена адресатом рассылки со статусом `pending`
- **AND** организация отписалась (`isOptedOut = true`) до обработки получателя
- **THEN** при отправке получатель помечается `failed` с сообщением «Организация отписана от рассылок»
- **AND** письмо не формируется и не отправляется
