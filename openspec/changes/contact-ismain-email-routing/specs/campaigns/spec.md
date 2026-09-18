## MODIFIED Requirements

### Requirement: Генерация письма по шаблону
The system SHALL generate each email from the campaign's stored subject, preview text, and body by filling tokens (`{{greeting}}`, `{{contact_name}}`, `{{organization_name}}`, `{{unsubscribe_url}}`). The `{{greeting}}` token SHALL resolve to "Уважаемый(ая) {contact_name}" when an addressee contact is set (even if the addressee has no email), or "Уважаемые сотрудники {organization_name}" otherwise. The `{{contact_name}}` token SHALL resolve to the addressee contact name when set, or to the organization name otherwise. The recipient display name SHALL be the addressee contact name when an addressee is set, or the organization name otherwise. When the addressee has an email, the email SHALL be sent to the addressee (TO) and all other organization contacts with an email SHALL receive a copy (CC). When the addressee has no email or no addressee is set, the email SHALL be sent to the organization's effective main contact (TO) — the contact with `isMain = true`, or the contact with the smallest ID when the organization has no such contact or has several of them — with all remaining organization contacts having an email in CC. The `{{unsubscribe_url}}` token SHALL resolve to an absolute URL that, when visited, opts the organization out of the campaign (sets Organization.isOptedOut = true). The unsubscribe URL SHALL use the recipient's tracking token for authentication.

#### Scenario: Приветствие с контактом
- **WHEN** рассылка «Новые курсы» отправляется организации «ООО Ромашка» контакту «Иван Петров»
- **AND** текст письма содержит токен `{{greeting}}`
- **THEN** в письме вместо токена `{{greeting}}` подставлено "Уважаемый(ая) Иван Петров"

#### Scenario: Приветствие без контакта
- **WHEN** рассылка «Новые курсы» отправляется организации «ООО Ромашка» без указания контакта
- **AND** текст письма содержит токен `{{greeting}}`
- **THEN** в письме вместо токена `{{greeting}}` подставлено "Уважаемые сотрудники ООО Ромашка"

#### Scenario: Приветствие без контакта с основным контактом
- **WHEN** рассылка «Новые курсы» отправляется организации «ООО Ромашка» без указания контакта
- **AND** у организации есть основной контакт «Мария Смирнова»
- **AND** текст письма содержит токен `{{greeting}}`
- **THEN** в письме вместо токена `{{greeting}}` подставлено "Уважаемые сотрудники ООО Ромашка"

#### Scenario: Приветствие без контакта и без основного
- **WHEN** рассылка «Новые курсы» отправляется организации «ООО Ромашка» без указания контакта
- **AND** у организации нет основного контакта
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

#### Scenario: Рассылка без адресата — письмо основному контакту
- **WHEN** рассылка отправляется организации «ООО Ромашка» без указания контакта
- **AND** у организации есть основной контакт «Мария Смирнова» с email
- **THEN** письмо отправляется на email «Марии Смирновой» (TO)
- **AND** остальные контакты организации с email получают копию (CC)
- **AND** в качестве имени получателя используется «ООО Ромашка»

#### Scenario: Письмо указанному контакту с email
- **WHEN** рассылка отправляется организации «ООО Ромашка» с контактом «Иван Петров» (email = ivan@example.com)
- **THEN** письмо отправляется на ivan@example.com (TO)
- **AND** остальные контакты организации с email получают копию (CC)
- **AND** в качестве имени получателя используется «Иван Петров»

#### Scenario: Письмо контакту без email — fallback на основного
- **WHEN** рассылка отправляется организации «ООО Ромашка» с контактом «Иван Петров» (без email)
- **AND** у организации есть основной контакт «Мария Смирнова» с email
- **THEN** письмо отправляется на email «Марии Смирновой» (TO)
- **AND** остальные контакты организации с email получают копию (CC)
- **AND** в приветствии письма используется имя «Иван Петров»

#### Scenario: Нет основного контакта — письмо первому по ID
- **WHEN** рассылка отправляется организации «ООО Ромашка» без указания контакта
- **AND** у организации нет ни одного контакта с isMain
- **AND** минимальный ID среди контактов организации — у «Алексея Сидорова» с email
- **THEN** письмо отправляется на email «Алексея Сидорова» (TO)
- **AND** остальные контакты организации с email получают копию (CC)
- **AND** в качестве имени получателя используется «ООО Ромашка»

#### Scenario: Встроенные курсы в письме
- **WHEN** рассылка содержит текст и вложения
- **AND** система формирует письмо
- **THEN** в письмо включается текст и вложения рассылки

#### Scenario: Тема письма
- **WHEN** рассылка «Новые курсы» имеет тему «Приглашаем на курсы»
- **AND** рассылка отправляется организации «ООО Ромашка»
- **THEN** тема отправленного письма содержит «Приглашаем на курсы»

#### Scenario: Подсказка токенов на форме редактирования
- **WHEN** менеджер открывает форму редактирования рассылки
- **THEN** над textarea тела письма отображается подсказка с перечнем доступных токенов
- **AND** в подсказке указан токен `{{unsubscribe_url}}` с описанием «ссылка для отписки»

### Requirement: Отправка писем (фоновая обработка, MailingService)
The system SHALL provide a `MailingService` invoked by a background command (`app:campaign:send` or similar) that polls for campaigns in `launched` status having `CampaignRecipient` rows with status `pending` or `failed` with `retry_at <= NOW()` and `retry_count < 3`. The worker SHALL process up to `MAILING_BATCH_SIZE` recipients globally (configurable via .env, default 10) per iteration. Symfony Scheduler SHALL define `SendCampaignBatch` every minute for environments where the externally managed `scheduler_default` consumer is enabled; development MAY start that consumer manually on demand. The application SHALL NOT require a dedicated Docker Compose scheduler service. The command SHALL accept an optional `--limit` CLI option that overrides `MAILING_BATCH_SIZE` for a single run, allowing the operator to control the batch size on each invocation (e.g. `app:campaign:send --limit=20`). The service SHALL read the email subject, preview text and body from the **campaign's own stored fields**, fill tokens `{{greeting}}`, `{{contact_name}}`, `{{organization_name}}`, and send via SMTP (Symfony Mailer). The system SHALL send one email per recipient organization. If a recipient specifies a contact with an email address, the email SHALL be sent to that contact (TO), with all other unique email addresses of the organization's contacts in CC, and the recipient display name SHALL be the specified contact's name. If a recipient specifies a contact without an email address, the email SHALL be sent to the email address of the organization's effective main contact (the contact with `isMain = true`; or the contact with the smallest ID when the organization has no such contact or has several of them) (TO), with all remaining organization email addresses in CC, and the recipient display name SHALL be the specified contact's name. If no contact is specified, the email SHALL be sent to the email address of the organization's effective main contact (same resolution rule) (TO), with all remaining organization email addresses in CC, and the recipient display name SHALL be the organization name. Organization email addresses SHALL be the unique email addresses of the organization's contacts. Each recipient SHALL be processed independently; a failure for one recipient SHALL NOT affect others. When the campaign starts processing its status remains `launched`; on an unrecoverable error it becomes `failed`. The sent HTML SHALL include a tracking-pixel image pointing at `GET /t/{trackingToken}.png`. Campaign attachments SHALL be sent as email attachments.

#### Scenario: Обработка запущенной рассылки фоновой командой
- **WHEN** фоновая команда запускается
- **AND** находит запущенную рассылку с получателями в статусе `pending`
- **THEN** она отправляет письма этим получателям и обновляет их статусы

#### Scenario: Индивидуальная фиксация ошибки
- **WHEN** конкретный получатель не отправляется (ошибка SMTP)
- **THEN** только этот получатель помечается ошибкой/отказом, а остальные продолжают обрабатываться

#### Scenario: Источник шаблона — поля рассылки
- **WHEN** фоновая команда формирует письмо получателю
- **THEN** она использует тему, превью и текст, сохранённые на самой рассылке

#### Scenario: Ограничение количества сообщений через --limit
- **WHEN** оператор запускает команду с опцией `--limit=N`
- **THEN** команда обрабатывает не более N получателей за один запуск
- **AND** если `--limit` не указан, используется значение `MAILING_BATCH_SIZE` из конфигурации