## ADDED Requirements

### Requirement: WYSIWYG-редактор тела письма
The campaign form SHALL provide a visual (WYSIWYG) editor for the HTML body alongside a raw-HTML source mode. The visual editor SHALL support bold, italic, underline, strikethrough, headings, bulleted and ordered lists, links, text color, and text alignment. The toolbar SHALL expose H1, H2, and H3 heading controls; source-authored H4, H5, and H6 SHALL also survive visual round-trips. The user SHALL be able to insert an image by external URL, specifying the URL, alternative text, and optionally width; the system SHALL NOT upload, store, or serve image files. The source mode SHALL display and edit the raw HTML of the body. Switching between visual and source modes SHALL NOT lose allowed formatting, images, or tables present in the body. Every element and attribute allowed by the body sanitizer that forms a valid email document SHALL survive source → visual → source in canonical form; synonym elements MAY be normalized (for example `b` to `strong`, `i` to `em`, `del` to `s`), but allowed formatting, styled containers, images, and tables SHALL NOT be lost. Inline `code` MAY be used; `pre`/code-block markup is not part of the allowlist. Tokens SHALL remain unchanged in both modes. Table creation UI is out of scope: tables are authored in source mode, but a table present in the body SHALL survive a visual-mode round-trip.

#### Scenario: Форматирование текста
- **WHEN** менеджер применяет к выделенному тексту полужирное начертание
- **THEN** в сохранённом теле присутствует соответствующая HTML-разметка

#### Scenario: Заголовок H1 из панели форматирования
- **WHEN** менеджер применяет к выделенному тексту кнопку H1
- **THEN** в сохранённом теле присутствует тег `h1`

#### Scenario: Вставка изображения по внешнему URL
- **WHEN** менеджер вставляет изображение с внешним https-URL и alt-текстом
- **THEN** в теле появляется тег img с этим URL
- **AND** система не сохраняет файл изображения

#### Scenario: Режим исходного HTML
- **WHEN** менеджер переключается в режим исходного HTML
- **THEN** он видит и может править HTML-код тела письма

#### Scenario: Таблица переживает переключение режимов
- **WHEN** тело письма содержит таблицу
- **THEN** после переключения «исходный HTML → визуальный режим → исходный HTML» таблица сохраняется в теле

#### Scenario: Токены не повреждаются редактором
- **WHEN** в теле письма присутствует токен `{{greeting}}`
- **THEN** после сохранения токен остаётся в теле без изменений

#### Scenario: Все разрешённые элементы переживают переключение режимов
- **WHEN** тело письма содержит все элементы и атрибуты, разрешённые санитайзером тела
- **THEN** после двух переключений «исходный HTML → визуальный режим → исходный HTML» форматирование, стили, изображение, `div` и таблица сохраняются

### Requirement: Санитизация HTML тела письма
On create and update the system SHALL sanitize the campaign body against an allowlist of elements and attributes (including text formatting, headings, lists, links, images, styled containers, and tables) and SHALL remove scripts, event handlers, embedded frames, and disallowed URL schemes. Inline `style` SHALL be limited to an allowlist of safe CSS properties. Campaign tokens SHALL NOT be used inside CSS declaration values: a declaration whose value contains a `{{...}}` token SHALL be removed while unrelated allowed declarations in the same `style` attribute SHALL be preserved. The body SHALL be limited to 200 000 characters. If sanitization leaves no allowed content, saving SHALL fail with a validation error and the campaign SHALL NOT be saved. The stored body SHALL be the sanitized HTML used later for previews and emails.

#### Scenario: Скрипты и обработчики событий удаляются
- **WHEN** тело письма содержит тег script или атрибут-обработчик события (например onerror)
- **THEN** после сохранения они отсутствуют в теле

#### Scenario: Таблица и изображение сохраняются
- **WHEN** тело письма содержит таблицу с объединёнными ячейками и изображение с внешним https-URL, alt-текстом и шириной
- **THEN** эти элементы и атрибуты сохраняются в теле

#### Scenario: Опасные CSS-свойства удаляются
- **WHEN** в атрибуте style указано свойство position: fixed
- **THEN** это свойство не сохраняется
- **AND** разрешённые свойства (например color, text-align) сохраняются

#### Scenario: Токены в CSS-значении удаляются
- **WHEN** значение CSS-свойства содержит токен `{{organization_name}}`
- **THEN** это CSS-свойство не сохраняется
- **AND** остальные разрешённые свойства того же атрибута `style` сохраняются

#### Scenario: Превышение лимита длины
- **WHEN** тело письма длиннее 200 000 символов
- **THEN** сохранение отклоняется с ошибкой валидации
- **AND** рассылка не сохраняется

#### Scenario: Тело без допустимого содержимого
- **WHEN** тело письма состоит только из запрещённых элементов
- **THEN** сохранение отклоняется с ошибкой валидации
- **AND** рассылка не сохраняется

### Requirement: Структура email-письма
The system SHALL form each letter as a complete HTML email document: a doctype, a table-based layout shell of 600 pixels width, the preheader text hidden at the top, the body content, and a footer. Shell CSS SHALL be inlined into element `style` attributes. The letter SHALL include a plain-text alternative generated from the HTML. The same document structure SHALL be used both for sending and for every preview.

#### Scenario: Полный HTML-документ
- **WHEN** система формирует письмо
- **THEN** письмо содержит doctype и табличную раскладку шириной 600 пикселей

#### Scenario: Прехедер в письме
- **WHEN** у рассылки заполнен прехедер
- **THEN** в начале письма присутствует скрытый блок с его текстом
- **AND** значения токенов в прехедер подставлены и экранированы

#### Scenario: Текстовая часть письма
- **WHEN** письмо сформировано
- **THEN** у письма есть текстовая часть, не содержащая HTML-тегов

#### Scenario: Инлайн-стили письма
- **WHEN** письмо сформировано
- **THEN** CSS шелла присутствует в атрибутах style элементов

### Requirement: Предпросмотр письма
The system SHALL provide a preview of the letter rendered by the same template as real sending, with fixed demo values substituted for tokens. Three surfaces SHALL exist: a preview page for a saved campaign, a modal window on the campaign show page, and a live preview in the create/edit form that renders the current unsaved body. The live preview SHALL NOT persist any changes. Preview SHALL follow the same access rules as viewing the campaign card. The preview SHALL NOT include the tracking pixel.

#### Scenario: Страница предпросмотра
- **WHEN** пользователь открывает страницу предпросмотра сохранённой рассылки
- **THEN** он видит письмо целиком, как оно будет отправлено

#### Scenario: Модальное окно на карточке рассылки
- **WHEN** менеджер нажимает «Предпросмотр» на карточке рассылки
- **THEN** письмо открывается в модальном окне

#### Scenario: Живое превью в форме
- **WHEN** менеджер запрашивает предпросмотр в форме с несохранённым телом письма
- **THEN** отображается письмо с текущим телом
- **AND** изменения не сохраняются в базе данных

#### Scenario: Демо-значения токенов
- **WHEN** тело письма содержит токены
- **THEN** в предпросмотре они заменены фиксированными демонстрационными значениями

#### Scenario: Предпросмотр без tracking-pixel
- **WHEN** открывается предпросмотр письма
- **THEN** в письме отсутствует tracking-pixel

### Requirement: Отображение тела письма на карточке рассылки
The campaign show page SHALL render the sanitized body HTML as formatted content instead of raw text, and SHALL provide a button to open the preview modal.

#### Scenario: Форматированный HTML на карточке
- **WHEN** менеджер открывает карточку рассылки с HTML-телом
- **THEN** тело отображается как форматированный HTML, а не как исходный код

#### Scenario: Кнопка предпросмотра на карточке
- **WHEN** менеджер открывает карточку рассылки
- **THEN** на карточке есть кнопка открытия предпросмотра письма

### Requirement: Страницы отписки

For a valid unsubscribe URL, the system SHALL render the successful-unsubscription and already-unsubscribed outcomes as two standalone HTML documents. Each document SHALL contain only the heading and explanatory text for its outcome. Neither document SHALL include the site header, footer, navigation, external assets, or any links to the application. Each document SHALL carry the `noindex, nofollow` robots meta.

#### Scenario: Подтверждение отписки
- **WHEN** получатель переходит по действительной ссылке отписки
- **THEN** отображается только сообщение подтверждения «Вы отписались от рассылки»
- **AND** шапка, подвал и ссылки на сайт отсутствуют

#### Scenario: Повторный переход по ссылке отписки
- **WHEN** организация уже отписана и получатель переходит по той же ссылке
- **THEN** отображается только сообщение «Вы уже отписались»
- **AND** шапка, подвал и ссылки на сайт отсутствуют

## MODIFIED Requirements

### Requirement: Создание рассылки
The system SHALL let the administrator and managers create campaigns with a name, an email subject, an optional preview text (preheader), an HTML email body with tokens, and optional attachments. The body SHALL be entered through the WYSIWYG editor (visual or source mode) and SHALL be sanitized before saving. A campaign SHALL NOT be bound to a single organization. The campaign's email subject, preview text, body, and attachments SHALL be stored on the campaign itself. A newly created campaign's `status` SHALL default to `draft`. After saving, the user SHALL be redirected to the campaign list.

#### Scenario: Создание рассылки
- **WHEN** администратор создаёт рассылку "Новые курсы" с темой "Приглашаем на курсы 2026", текстом "{{greeting}}! Приглашаем вас на курсы." и вложениями
- **THEN** рассылка "Новые курсы" появляется в списке рассылок
- **AND** её тема, превью, текст и вложения сохраняются на самой рассылке
- **AND** пользователь перенаправляется на список рассылок

#### Scenario: Тема письма рассылки
- **WHEN** администратор создаёт рассылку "Акция" с темой "Скидки недели"
- **THEN** тема "Скидки недели" сохраняется и используется как тема письма при отправке

#### Scenario: Рассылка без курсов
- **WHEN** менеджер создаёт рассылку "Приглашение на вебинар" с HTML-телом и вложениями
- **THEN** рассылка создаётся и не привязана ни к одному курсу или организации
- **AND** вложения сохраняются на самой рассылке

### Requirement: Генерация письма по шаблону
The system SHALL generate each email from the campaign's stored subject, preview text, and body by filling tokens (`{{greeting}}`, `{{contact_name}}`, `{{organization_name}}`, `{{unsubscribe_url}}`). The `{{greeting}}` token SHALL resolve to "Уважаемый(ая) {contact_name}" when an addressee contact is set (even if the addressee has no email), or "Уважаемые сотрудники {organization_name}" otherwise. The `{{contact_name}}` token SHALL resolve to the addressee contact name when set, or to the organization name otherwise. The recipient display name SHALL be the addressee contact name when an addressee is set, or the organization name otherwise. When the addressee has an email, the email SHALL be sent to the addressee (TO) and all other organization contacts with an email SHALL receive a copy (CC). When the addressee has no email or no addressee is set, the email SHALL be sent to the organization's effective main contact (TO) — the contact with `isMain = true`, or the contact with the smallest ID when the organization has no such contact or has several of them — with all remaining organization contacts having an email in CC. If the effective main contact has no email address but the organization has other contacts with email addresses, the email SHALL be sent to the first available email address of the organization's contacts (TO), with the remaining email addresses in CC. The `{{unsubscribe_url}}` token SHALL resolve to an absolute URL that, when visited, opts the organization out of the campaign (sets Organization.isOptedOut = true). The unsubscribe URL SHALL use the recipient's tracking token for authentication. Token values substituted into the body and the preheader SHALL be HTML-escaped; the subject SHALL be filled without HTML escaping. The letter SHALL be formed using the single email template described in «Структура email-письма».

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
- **THEN** рядом с редактором тела письма отображается подсказка с перечнем доступных токенов
- **AND** в подсказке указан токен `{{unsubscribe_url}}` с описанием «ссылка для отписки»

#### Scenario: Экранирование значений токенов в HTML
- **WHEN** название организации содержит символ `<` и рассылка отправляется этой организации
- **AND** тело письма содержит токен `{{organization_name}}`
- **THEN** в письме символ экранирован, а HTML-разметка письма не ломается

### Requirement: Отправка писем (фоновая обработка, MailingService)

The system SHALL provide a `MailingService` invoked by a background command (`app:campaign:send` or similar) that polls for campaigns in `launched` status having `CampaignRecipient` rows with status `pending` or `failed` with `retry_at <= NOW()` and `retry_count < 3`. The worker SHALL process up to `MAILING_BATCH_SIZE` recipients globally (configurable via .env, default 10) per iteration. Symfony Scheduler SHALL define `SendCampaignBatch` every minute for environments where the externally managed `scheduler_default` consumer is enabled; development MAY start that consumer manually on demand. The application SHALL NOT require a dedicated Docker Compose scheduler service. The command SHALL accept an optional `--limit` CLI option that overrides `MAILING_BATCH_SIZE` for a single run, allowing the operator to control the batch size on each invocation (e.g. `app:campaign:send --limit=20`). The service SHALL read the email subject, preview text, and sanitized HTML body from the **campaign's own stored fields** and SHALL pass them, together with recipient context, to `CampaignEmailRenderer`. `MailingService` SHALL construct a `Symfony\Component\Mime\Email` with the renderer's subject, complete HTML document, and plain-text alternative and SHALL send it via SMTP (Symfony Mailer). The renderer SHALL apply the token, escaping, and email-structure rules defined in «Генерация письма по шаблону» and «Структура email-письма». The system SHALL send one email per recipient organization. If a recipient specifies a contact with an email address, the email SHALL be sent to that contact (TO), with all other unique email addresses of the organization's contacts in CC, and the recipient display name SHALL be the specified contact's name. If a recipient specifies a contact without an email address, the email SHALL be sent to the email address of the organization's effective main contact (the contact with `isMain = true`; or the contact with the smallest ID when the organization has no such contact or has several of them) (TO), with all remaining organization email addresses in CC, and the recipient display name SHALL be the specified contact's name. If no contact is specified, the email SHALL be sent to the email address of the organization's effective main contact (same resolution rule) (TO), with all remaining organization email addresses in CC, and the recipient display name SHALL be the organization name. Organization email addresses SHALL be the unique email addresses of the organization's contacts. Each recipient SHALL be processed independently; a failure for one recipient SHALL NOT affect others. When the campaign starts processing its status remains `launched`; on an unrecoverable error it becomes `failed`. The sent HTML SHALL include a tracking-pixel image pointing at `GET /t/{trackingToken}.png`. Campaign attachments SHALL be sent as email attachments.

#### Scenario: Обработка запущенной рассылки фоновой командой
- **WHEN** фоновая команда запускается
- **AND** находит запущенную рассылку с получателями в статусе `pending`
- **THEN** она отправляет письма этим получателям и обновляет их статусы

#### Scenario: Индивидуальная фиксация ошибки
- **WHEN** конкретный получатель не отправляется (ошибка SMTP)
- **THEN** только этот получатель помечается ошибкой/отказом, а остальные продолжают обрабатываться

#### Scenario: Источник шаблона — поля рассылки
- **WHEN** фоновая команда формирует письмо получателю
- **THEN** `MailingService` использует тему, прехедер и санитизированное HTML-тело, сохранённые на самой рассылке
- **AND** `CampaignEmailRenderer` возвращает тему, полный HTML-документ и текстовую часть
- **AND** письмо отправляется как `Email` с обеими частями через SMTP

#### Scenario: Ограничение количества сообщений через --limit
- **WHEN** оператор запускает команду с опцией `--limit=N`
- **THEN** команда обрабатывает не более N получателей за один запуск
- **AND** если `--limit` не указан, используется значение `MAILING_BATCH_SIZE` из конфигурации
