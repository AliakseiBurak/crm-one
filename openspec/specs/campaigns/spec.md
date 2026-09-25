# Campaigns

Модуль рассылок: заранее созданные кампании, отправка писем организациям
(выбранным вручную), отслеживание статусов и отписок. Отправка — outbox
через SMTP (см. ADR-0010).

## Purpose

Рассылки: создание кампаний с текстом письма (токены + встроенные курсы),
формирование адресатов вручную, отправка через outbox и отслеживание
статуса каждого письма (sent/delivered/bounced/opened).

## Requirements

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

### Requirement: Статусы рассылки
The system SHALL support the following campaign statuses: `draft`, `ready`, `launched`, `failed`, `archived`. Each status SHALL have a localized label. `launchedAt` SHALL be recorded when transitioning to `launched`; `failedAt` SHALL be recorded when transitioning to `failed`. A campaign SHALL store a `failureReason` (text, nullable) describing the last processing failure; it SHALL be set when the campaign transitions to `failed` and cleared when the campaign is launched again. `launched` means the campaign is active and being processed by the worker; `failed` means an unrecoverable error occurred during processing and SHALL be a technical status set exclusively by the MailingService — users SHALL NOT set `failed` manually via the form; users SHALL be able to reset a `failed` campaign to `ready` for re-launch. `archived` is a manual archive state for list display.

#### Scenario: Черновик
- **WHEN** менеджер создаёт новую рассылку
- **THEN** её статус по умолчанию — `draft` («Черновик»)

#### Scenario: Ошибка фиксирует время
- **WHEN** рассылка переходит в статус `failed`
- **THEN** поле `failedAt` заполняется текущим временем
- **AND** поле `failureReason` содержит понятное описание причины сбоя

#### Scenario: Переход в обработку
- **WHEN** фоновая команда начинает отправку писем запущенной рассылки
- **THEN** статус рассылки остаётся `launched`

### Requirement: Формирование адресатов
The system SHALL provide a dedicated recipients page for each campaign, accessible from the campaign list and show page. Each organization SHALL have at most one recipient per campaign (unique constraint on `campaign_id`, `organization_id`). A recipient MAY specify a contact; when a contact is set, the email SHALL be sent to that contact's email address instead of the organization. Recipients SHALL be editable for all campaign statuses except `archived`; for archived campaigns, the recipients list SHALL be view-only (no add/remove). When a recipient already exists for an organization — including the same contact — the system SHALL prompt the user with a replacement confirmation; on confirmation, the existing recipient is removed and a new one is created. If the campaign has been launched (`launchedAt` is not null), the replacement SHALL trigger a re-send to the organization, the `replacementCount` SHALL be incremented, and the system SHALL show a flash that the email will be resent. If the campaign has not been launched yet, the replacement SHALL NOT increment the counter and SHALL NOT claim a resend. The system SHALL display a warning on the replacement confirmation page when the campaign has been launched, informing the user that the replacement will trigger a re-send. The system SHALL support bulk-adding all accessible organizations as recipients at once. Each recipient SHALL track a `replacementCount` field showing the number of replacements performed while the campaign was active. The reset action for `failed` and `bounced` recipients SHALL be rendered as an ✕ emoji button inside the status cell, to the right of the status label, not in a separate actions column.

#### Scenario: Страница адресатов — колонка Повторно и кнопка ✕
- **WHEN** менеджер открывает страницу адресатов рассылки
- **THEN** он видит таблицу с колонками: Организация, Контакт, Статус, Повторно*, Действия
- **AND** в ячейке «Статус» отображается бейдж статуса и кнопка ✕ справа для `failed` и `bounced`
- **AND** кнопка ✕ для `failed` выполняет `POST …/reset` с confirm-диалогом
- **AND** кнопка ✕ для `bounced` ведёт на страницу подтверждения `GET …/reset`
- **AND** под таблицей отображается сноска: «* В случае повторного добавления организации/контакта в запущенную рассылку велика вероятность, что эл. письмо будет отправлено повторно.»

#### Scenario: Добавление адресата
- **WHEN** менеджер выбирает организацию «ООО Ромашка» и нажимает «Добавить»
- **THEN** организация добавляется как адресат рассылки

#### Scenario: Массовое добавление всех организаций
- **WHEN** менеджер нажимает «Выбрать все организации»
- **THEN** все доступные организации добавляются как адресаты
- **AND** уже существующие организации пропускаются

#### Scenario: Замена адресата организации
- **WHEN** рассылка «Акция» уже имеет адресата «ООО Ромашка» (без контакта)
- **AND** менеджер добавляет адресата для «ООО Ромашка» с контактом «Иван Петров»
- **THEN** система отображает страницу подтверждения замены
- **AND** при подтверждении текущий адресат удаляется и добавляется новый с контактом «Иван Петров»

#### Scenario: Замена адресата в запущенной рассылке
- **WHEN** рассылка «Акция» запущена (`launchedAt` не null) и имеет адресата «ООО Ромашка»
- **AND** менеджер заменяет адресата на другой контакт
- **THEN** система отображает предупреждение «Рассылка уже запущена. Замена адресата инициирует повторную отправку письма данной организации.»
- **AND** при подтверждении `replacementCount` текущего адресата увеличивается на 1
- **AND** новый адресат создаётся с увеличенным счётчиком
- **AND** система показывает flash о повторной отправке письма

#### Scenario: Повторное добавление того же контакта в запущенной рассылке
- **WHEN** рассылка «Акция» запущена и имеет адресата «ООО Ромашка» с контактом «Иван Петров»
- **AND** менеджер снова добавляет «ООО Ромашка» с контактом «Иван Петров»
- **THEN** система отображает страницу подтверждения замены с предупреждением о повторной отправке
- **AND** при подтверждении адресат заменяется
- **AND** `replacementCount` увеличивается на 1
- **AND** система показывает flash о повторной отправке письма

#### Scenario: Замена адресата до запуска рассылки
- **WHEN** рассылка «Акция» не запущена (`launchedAt` равен null) и имеет адресата «ООО Ромашка»
- **AND** менеджер заменяет адресата на другой контакт
- **THEN** система отображает страницу подтверждения замены без предупреждения о повторной отправке
- **AND** при подтверждении `replacementCount` НЕ увеличивается
- **AND** flash о повторной отправке не показывается

#### Scenario: Менеджер не может добавить недоступную организацию адресатом
- **WHEN** в системе существует организация «ООО Конкурент», отсутствующая в области доступа менеджера
- **AND** менеджер пытается добавить её адресатом standalone-рассылки
- **THEN** система отклоняет запрос с ошибкой 403
- **AND** организация не включается в получатели

#### Scenario: Адресаты нельзя добавить для архивированной рассылки
- **WHEN** рассылка «Новые курсы» имеет статус `archived`
- **AND** менеджер пытается добавить или удалить адресата
- **THEN** система отклоняет запрос с сообщением «Адресаты недоступны для рассылки в статусе «В архиве»»

#### Scenario: Просмотр адресатов архивированной рассылки
- **WHEN** менеджер открывает страницу адресатов архивированной рассылки
- **THEN** он видит таблицу адресатов без кнопок удаления и формы добавления

#### Scenario: Адресаты доступны для черновика
- **WHEN** рассылка имеет статус `draft`
- **THEN** менеджер может добавлять и удалять адресатов через страницу адресатов

#### Scenario: Адресаты доступны для готовой рассылки
- **WHEN** рассылка имеет статус `ready`
- **THEN** менеджер может добавлять и удалять адресатов через страницу адресатов

#### Scenario: Адресаты доступны для запущенной рассылки
- **WHEN** рассылка имеет статус `launched`
- **THEN** менеджер может добавлять и удалять адресатов через страницу адресатов

#### Scenario: Адресаты доступны для рассылки с ошибкой
- **WHEN** рассылка имеет статус `failed`
- **THEN** менеджер может добавлять и удалять адресатов через страницу адресатов

#### Scenario: Переход на страницу адресатов из списка
- **WHEN** менеджер нажимает кнопку «Адресаты» в анонимной колонке таблицы рассылок
- **THEN** он перенаправляется на страницу адресатов соответствующей рассылки

#### Scenario: Переход на страницу адресатов из карточки
- **WHEN** менеджер нажимает кнопку «Адресаты» на карточке рассылки
- **THEN** он перенаправляется на страницу адресатов соответствующей рассылки

#### Scenario: Получатели создаются до запуска
- **WHEN** менеджер создаёт рассылку и добавляет организации-получатели
- **AND** затем нажимает «Запустить»
- **THEN** получатели уже существуют как записи `CampaignRecipient` до момента запуска

### Requirement: Массовое добавление всех организаций
The system SHALL support bulk-adding all accessible organizations as recipients at once. Each organization SHALL have at most one recipient per campaign (unique constraint on `campaign_id`, `organization_id`). Organizations SHALL be filtered by the manager's access scope (custom groups: created + assigned). Already existing recipients SHALL be skipped. Organizations skipped because they have no deliverable e-mail SHALL be disclosed in the result message as «пропущено: M (в том числе нет e-mail: K)».

#### Scenario: Массовое добавление всех организаций
- **WHEN** менеджер нажимает «Выбрать все организации»
- **THEN** все доступные организации (из созданных и назначенных групп) добавляются как адресаты
- **AND** уже существующие организации пропускаются

#### Scenario: Массовое добавление раскрывает пропуск организаций без e-mail
- **WHEN** менеджер нажимает «Выбрать все организации»
- **AND** среди доступных организаций есть 2 без e-mail у контактов
- **THEN** они не добавляются в адресаты
- **AND** сообщение о результате содержит «(в том числе нет e-mail: 2)»

#### Scenario: Менеджер не может добавить недоступную организацию адресатом
- **WHEN** в системе существует организация «ООО Конкурент», отсутствующая в области доступа менеджера (ни в одной из групп)
- **AND** менеджер пытается добавить её адресатом рассылки
- **THEN** система отклоняет запрос с ошибкой 403
- **AND** организация не включается в получатели

### Requirement: Менеджер может массово добавлять организации в рассылку по группе
The system SHALL allow managers to bulk-add all organizations from a specific group they have access to as campaign recipients in a single action. The group selection SHALL be limited to groups the manager has access to (created + assigned custom groups). Organizations already added as recipients SHALL be skipped. Organizations without a deliverable e-mail SHALL be skipped as well (rule "Организация без e-mail не может стать адресатом" applies to every creation path). The result message SHALL report skipped organizations, and SHALL disclose the missing-e-mail count only when such organizations were skipped.

#### Scenario: Менеджер добавляет все организации своей группы в рассылку
- **WHEN** менеджер "Иван Петров" открывает страницу адресатов рассылки "Новые курсы"
- **AND** выбирает действие "Добавить по группе" и выбирает группу "Минский регион"
- **THEN** все организации группы "Минский регион" добавляются как адресаты рассылки
- **AND** организации, уже являющиеся адресатами, пропускаются

#### Scenario: Менеджер не может добавить организации чужой группы
- **WHEN** менеджер "Иван Петров" пытается выполнить массовое добавление по группе "Южный регион", к которой у него нет доступа
- **THEN** система отклоняет запрос с ошибкой 403
- **AND** организации не добавляются

#### Scenario: Пропуск организаций без e-mail при добавлении по группе
- **WHEN** менеджер "Иван Петров" добавляет адресатов по группе "Минский регион"
- **AND** в группе 4 организации, из которых 2 уже являются адресатами, а у 1 нет e-mail ни у одного контакта
- **THEN** организация без e-mail не добавляется в адресаты
- **AND** сообщение о результате содержит «пропущено: 3 (в том числе нет e-mail: 1)»

#### Scenario: Сообщение о результате без упоминания e-mail
- **WHEN** менеджер "Иван Петров" добавляет адресатов по группе, где все пропущенные организации уже являются адресатами
- **THEN** сообщение о результате содержит только «Добавлено: N, пропущено: M» без упоминания e-mail

#### Scenario: Группа без организаций
- **WHEN** менеджер выбирает группу, в которой нет организаций
- **THEN** система не добавляет ни одной организации
- **AND** возвращается сообщение о пустом результате: «В группе «Южный регион» нет организаций — добавлять нечего»

### Requirement: Навигация в таблице адресатов
The system SHALL display clickable links for Organization and Contact columns in the campaign recipients table, navigating to their respective edit pages.

#### Scenario: Ссылка на организацию
- **WHEN** менеджер открывает страницу адресатов рассылки
- **AND** у адресата есть организация "ООО Ромашка"
- **THEN** название организации отображается как кликабельная ссылка
- **AND** ссылка ведёт на `GET /organizations/{id}/edit` организации "ООО Ромашка"

#### Scenario: Ссылка на контакт
- **WHEN** менеджер открывает страницу адресатов рассылки
- **AND** у адресата указан контакт "Иван Петров"
- **THEN** имя контакта отображается как кликабельная ссылка
- **AND** ссылка ведёт на `GET /contacts/{id}/edit` контакта "Иван Петров"

#### Scenario: Контакт не указан
- **WHEN** менеджер открывает страницу адресатов рассылки
- **AND** у адресата контакт не указан (отправляется всей организации)
- **THEN** в колонке "Контакт" отображается прочерк "—"
- **AND** прочерк не является ссылкой

### Requirement: Сброс получателя failed или bounced в pending
The system SHALL provide `POST /campaigns/{id}/recipients/{recipientId}/reset` that resets a recipient with status `failed` or `bounced` to `pending` and clears `errorMessage`, `retryCount`, and `retryAt`. The same route SHALL be used from the recipients page, the contact error table, and the organization error table. Access SHALL follow the same scope rules as other recipient operations.

#### Scenario: Успешный сброс failed получателя
- **WHEN** менеджер с доступом к организации адресата сбрасывает получателя со статусом `failed`
- **THEN** статус получателя становится `pending`
- **AND** поля `errorMessage`, `retryCount`, `retryAt` очищаются
- **AND** получатель будет обработан при следующем цикле фоновой команды

#### Scenario: Успешный сброс bounced получателя
- **WHEN** менеджер с доступом к организации адресата подтверждает сброс получателя со статусом `bounced`
- **THEN** статус получателя становится `pending`
- **AND** поля `errorMessage`, `retryCount`, `retryAt` очищаются

#### Scenario: Получатель не найден
- **WHEN** менеджер пытается сбросить получателя с несуществующим recipientId
- **THEN** система возвращает ошибку 404 "Адресат не найден"

#### Scenario: Получатель не принадлежит рассылке
- **WHEN** менеджер пытается сбросить получателя, который не принадлежит данной рассылке
- **THEN** система возвращает ошибку 404 "Адресат не найден"

#### Scenario: Отказ вне области доступа
- **WHEN** менеджер пытается сбросить получателя организации вне своей области доступа
- **THEN** система отклоняет запрос с ошибкой 403

### Requirement: Организация без e-mail не может стать адресатом
The system SHALL NOT create a `CampaignRecipient` when the organization has no deliverable email among its contacts. The rule SHALL apply to every creation path: manual add, bulk add of all organizations, and mailing action from a call result. When rejected from the recipients UI, the system SHALL redirect back with a flash error. When a specific contact without email is chosen but the organization has another contact with email, the recipient MAY be created and the system SHALL show a flash that mail will go to the organization address.

#### Scenario: Добавление организации без e-mail
- **WHEN** менеджер выбирает организацию "ООО Ромашка" и нажимает "Добавить"
- **AND** у организации нет e-mail ни в одном из контактов
- **THEN** система перенаправляет обратно на страницу адресатов
- **AND** отображается flash-сообщение об ошибке: «У организации отсутствует e-mail. Добавьте e-mail контакту перед добавлением в рассылку.»
- **AND** организация не добавляется в адресаты

#### Scenario: Массовое добавление пропускает организации без e-mail
- **WHEN** менеджер нажимает «Выбрать все организации»
- **AND** среди доступных есть организация без e-mail у контактов
- **THEN** организации без e-mail не добавляются в адресаты
- **AND** организации с e-mail добавляются

#### Scenario: Добавление организации с e-mail у контакта
- **WHEN** менеджер выбирает организацию "ООО Ромашка" и нажимает "Добавить"
- **AND** у организации есть контакт "Иван Петров" с e-mail "ivan@example.com"
- **THEN** организация успешно добавляется в адресаты

#### Scenario: Добавление контакта без e-mail при наличии e-mail у организации
- **WHEN** менеджер выбирает организацию "ООО Ромашка" и контакт "Мария Смирнова" (без e-mail)
- **AND** у организации есть контакт с e-mail "info@romashka.ru"
- **THEN** контакт добавляется в адресаты
- **AND** отображается flash-уведомление: «У контакта «Мария Смирнова» отсутствует e-mail. Письмо будет отправлено организации: info@romashka.ru»

#### Scenario: Результат звонка не создаёт адресата без e-mail
- **WHEN** менеджер выбирает рассылку для звонка по организации без e-mail у контактов
- **AND** сохраняет звонок
- **THEN** адресат в рассылке не создаётся
- **AND** система сообщает об отсутствии e-mail у организации

### Requirement: Таблица ошибок на форме контакта и организации (кнопка ✕)
The error tables on the contact edit form and organization edit form SHALL use the same ✕ emoji button pattern for reset actions as the recipients page: ✕ inside the status cell, to the right of the status label, without a separate actions column.

#### Scenario: Кнопка ✕ в таблице ошибок контакта
- **WHEN** менеджер открывает форму редактирования контакта с ошибками доставки
- **THEN** в ячейке «Статус» отображается бейдж и кнопка ✕ справа
- **AND** кнопка ✕ для `failed` выполняет `POST …/reset` с confirm-диалогом
- **AND** кнопка ✕ для `bounced` ведёт на страницу подтверждения `GET …/reset`

#### Scenario: Кнопка ✕ в таблице ошибок организации
- **WHEN** менеджер открывает форму редактирования организации с ошибками доставки
- **THEN** в ячейке «Статус» отображается бейдж и кнопка ✕ справа
- **AND** кнопка ✕ для `failed` выполняет `POST …/reset` с confirm-диалогом
- **AND** кнопка ✕ для `bounced` ведёт на страницу подтверждения `GET …/reset`

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

### Requirement: Отправка через outbox
The system SHALL store an outbound email record in the database for each
recipient, and a separate worker command SHALL perform the actual sending
through SMTP. Email sending SHALL NOT depend on message queues or external task
brokers.

#### Scenario: Запись письма в базе данных
- **WHEN** рассылка "Новые курсы" запущена по организации "ООО Ромашка"
- **THEN** в базе данных уже есть запись «письмо к отправке» для организации "ООО Ромашка" со статусом pending

#### Scenario: Отдельная команда отправляет письма
- **WHEN** в базе данных есть записи «письмо к отправке» для рассылки "Новые курсы"
- **AND** запускается команда отправки писем
- **THEN** письма отправляются через SMTP-сервер получателям

### Requirement: Статусы писем и ход рассылки
The system SHALL track each email status: pending, sending, delivered, bounced,
failed, opened; the manager SHALL see the status of each email and the overall progress
of the campaign.

#### Scenario: Доставлено
- **WHEN** письмо рассылки передано на SMTP получателю
- **AND** SMTP подтверждает доставку
- **THEN** статус письма становится delivered

#### Scenario: Технический отказ
- **WHEN** письмо рассылки отправлено контакту с недостоверным адресом
- **AND** SMTP возвращает ошибку доставки
- **THEN** статус письма становится bounced

#### Scenario: Письмо прочитано
- **WHEN** получатель открывает письмо рассылки
- **AND** система получает запрос на tracking-pixel письма
- **THEN** статус письма становится opened

#### Scenario: Прогресс рассылки
- **WHEN** в рассылке "Новые курсы" 10 писем и 7 из них отправлено
- **AND** менеджер открывает карточку рассылки
- **THEN** он видит статус каждого письма и прогресс "7 из 10"

#### Scenario: Менеджер видит статусы только писем своего доступа
- **WHEN** в рассылке есть письма организации, отсутствующей в области доступа менеджера
- **AND** менеджер открывает карточку рассылки
- **THEN** он не видит письма и статусы недоступной организации
- **AND** прогресс рассылки рассчитывается по видимым менеджеру письмам

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

### Requirement: Статус получателя рассылки (per-letter)
The system SHALL track per-recipient send status on `CampaignRecipient` with the values `pending` → `sending` → (`delivered` | `bounced` | `failed`), plus `opened`. `pending` — создан, ещё не отправлен; `sending` — передан в обработку (SMTP); `delivered`/`bounced`/`failed` — результат SMTP-отправки; `opened` — по tracking-pixel. For transient errors (SMTP timeout, 4xx) the recipient SHALL be marked `failed` with `retry_count` and `retry_at` (exponential backoff + jitter, max 3 retries). After max retries the failure becomes permanent. This implements per-letter statuses from ADR-0010.

#### Scenario: Начальный статус получателя
- **WHEN** создаётся запись `CampaignRecipient`
- **THEN** её статус равен `pending`

#### Scenario: Пометка доставки, отказа или ошибки
- **WHEN** фоновая команда передаёт письмо в SMTP и получает результат
- **THEN** статус получателя становится `delivered`, `bounced` или `failed`

#### Scenario: Повторная попытка при transient ошибке
- **WHEN** SMTP возвращает timeout или 4xx
- **THEN** статус получателя становится `failed` с `retry_count` и `retry_at` (exponential backoff + jitter)
- **AND** при следующем цикле worker обработает этого получателя повторно (если `retry_count < 3` и `retry_at <= NOW()`)

#### Scenario: Permanent failure после исчерпания попыток
- **WHEN** `retry_count` достиг 3
- **THEN** ошибка становится permanent, получатель остаётся `failed` без повторных попыток

#### Scenario: Пометка прочтения
- **WHEN** получатель открывает письмо и клиент запрашивает tracking-pixel конкретного получателя
- **THEN** его статус становится `opened`

### Requirement: Счётчик отправленных писем
The system SHALL display on the campaign page a counter of processed recipients versus total recipients based on `CampaignRecipient.status`.

#### Scenario: Отображение счётчика
- **WHEN** пользователь открывает карточку рассылки в процессе отправки
- **THEN** на странице показан счётчик «обработано X из Y»

### Requirement: Статистика доставки в списке рассылок
The system SHALL show a statistics column in the campaigns list table displaying "x из y", where x is the number of the campaign's recipients with status `delivered` or `opened` and y is the total number of recipients of the campaign. The value SHALL be derived from `CampaignRecipient` statuses (count of `delivered` or `opened` vs total recipients). Opened implies the letter was delivered; after the tracking-pixel the status is `opened` rather than `delivered`.

#### Scenario: Колонка статистики в списке
- **WHEN** менеджер открывает список рассылок
- **THEN** в таблице есть колонка «Статистика» со значением «x из y», где x — число получателей со статусом `delivered` или `opened`, y — общее число получателей рассылки

#### Scenario: Статистика отражает доставку
- **WHEN** у рассылки 10 получателей, 6 из них доставлены (`delivered`) и 1 открыт (`opened`)
- **THEN** в колонке «Статистика» показано «7 из 10»

### Requirement: Обработка ошибок отправки
The system SHALL, on an unrecoverable error during processing (например, неверная конфигурация SMTP, отсутствие доставляемого адреса у всех получателей, ошибка формирования письма), установить статус рассылки `failed`, записать понятное и действие-ориентированное описание в `failureReason` (что сломалось и как исправить) и отправить email-уведомление администратору сайта. Пользователь исправляет проблему, нажимает «Сбросить» (статус становится `ready`, `failureReason` очищается), затем «Запустить» (статус `launched`); команда продолжает обработку оставшихся `pending` получателей. Campaign-level escalation SHALL NOT occur while any recipient is still `pending`, `sending`, or retriable `failed` (`retry_count < 3` and `retry_at` is set).

#### Scenario: Переход в статус ошибка
- **WHEN** во время отправки возникает неустранимая ошибка
- **THEN** статус рассылки становится `failed`
- **AND** поле `failureReason` содержит понятное сообщение для пользователя

#### Scenario: Уведомление администратора
- **WHEN** рассылка переходит в статус `failed`
- **THEN** администратору сайта отправляется email-уведомление об ошибке с описанием из `failureReason`

#### Scenario: Повторный запуск после исправления
- **WHEN** пользователь исправляет ошибку, нажимает «Сбросить», затем «Запустить»
- **THEN** статус сначала становится `ready` и `failureReason` очищается, после запуска — `launched`
- **AND** фоновая команда продолжает обработку оставшихся получателей со статусом `pending`

### Requirement: Получатель без адреса доставки
The system SHALL handle a `CampaignRecipient` whose organization (and specified contact, if any) has no email: the recipient is marked `failed` with an `errorMessage` describing the cause (e.g., "Отсутствует email-адрес организации") and processing continues with the other recipients; это не прерывает рассылку целиком (но если недоставляемы все получатели, возможна эскалация в `failed` согласно "Обработка ошибок отправки").

#### Scenario: Рассылка по организации без контактного email
- **WHEN** у организации-получателя (и указанного контакта) нет email
- **THEN** этот получатель помечается `failed` с `errorMessage`, а остальные получатели продолжают обрабатываться

#### Scenario: Отображение ошибки на странице Адресаты
- **WHEN** менеджер открывает страницу адресатов рассылки
- **AND** у адресата есть `errorMessage`
- **THEN** под строкой адресата отображается красное сообщение об ошибке из `errorMessage`
- **AND** если `errorMessage` отсутствует, сообщение об ошибке не отображается

### Requirement: Обработка отписки
The system SHALL process unsubscribe requests and SHALL exclude unsubscribed
contacts from later campaigns.

#### Scenario: Отписка контакта от рассылок
- **WHEN** контакт "Иван Петров" получил рассылку "Новые курсы"
- **AND** он переходит по ссылке отписки
- **THEN** контакт "Иван Петров" исключается из последующих рассылок

### Requirement: Вложения рассылки
The system SHALL let the administrator and manager attach one or more files to a campaign on both create and edit pages. Attached files SHALL be sent as email attachments when the campaign is launched. Multiple files SHALL be uploadable in a single form submission.

#### Scenario: Добавление вложений
- **WHEN** администратор редактирует рассылку «Новые курсы»
- **AND** загружает файлы «брошюра.pdf» и «прайс.xlsx»
- **THEN** оба файла сохраняются как вложения рассылки

#### Scenario: Удаление вложения
- **WHEN** администратор удаляет вложение «брошюра.pdf» у рассылки «Новые курсы»
- **THEN** файл удаляется из вложений рассылки

#### Scenario: Вложения при создании
- **WHEN** менеджер создаёт новую рассылку и выбирает файлы для загрузки
- **THEN** файлы сохраняются после создания рассылки

### Requirement: Запуск рассылки
The system SHALL support launching a campaign manually (administrator clicks launch). Launching SHALL set the campaign's `status` to `launched` and record `launchedAt`; actual sending is performed by a separate service. On the campaign card, the button order SHALL be: 1) Адресаты, 2) Клонировать (with recipients checkbox, if status is not draft), 3) action buttons (launch/stop/reset + Редактировать + Назад к списку).

#### Scenario: Ручной запуск
- **WHEN** администратор открывает карточку рассылки «Новые курсы» со статусом `ready`
- **AND** нажимает кнопку «Запустить»
- **THEN** рассылка помечается запущенной

#### Scenario: Запуск из списка
- **WHEN** администратор нажимает кнопку ▶ в строке рассылки со статусом `ready`
- **THEN** рассылка запускается и статус меняется на `launched`

#### Scenario: Остановка из списка
- **WHEN** администратор нажимает кнопку ■ в строке рассылки со статусом `launched`
- **THEN** статус рассылки меняется на `ready`

#### Scenario: Запуск недоступен для черновика
- **WHEN** рассылка имеет статус `draft`
- **THEN** кнопка «Запустить» не отображается на карточке и в списке
- **AND** в списке нет действий для этой строки (как для archived)

#### Scenario: Остановка запущенной рассылки
- **WHEN** администратор нажимает кнопку ■ на карточке рассылки со статусом `launched`
- **THEN** статус рассылки меняется на `ready`
- **AND** рассылка может быть запущена повторно

#### Scenario: Сброс failed-рассылки
- **WHEN** рассылка имеет статус `failed`
- **AND** администратор нажимает «Сбросить» на карточке
- **THEN** статус рассылки меняется на `ready`
- **AND** становится доступна кнопка «Запустить»

#### Scenario: Клонирование рассылки
- **WHEN** менеджер открывает карточку рассылки со статусом `ready`, `launched`, `failed` или `archived`
- **THEN** отображается кнопка «Клонировать» с тремя вариантами: «Без адресатов», «С адресатами», «С адресатами и контактами»
- **AND** при нажатии создаётся новая рассылка со статусом `draft`, копией темы, превью, текста, вложений (метаданные, файлы в storage общие)
- **AND** к названию добавляется суффикс «(копия)»

#### Scenario: Клонирование с адресатами без контактов
- **WHEN** менеджер выбирает «С адресатами» и нажимает «Клонировать»
- **THEN** копируются адресаты (организации) без контактов

#### Scenario: Клонирование с адресатами и контактами
- **WHEN** менеджер выбирает «С адресатами и контактами» и нажимает «Клонировать»
- **THEN** копируются адресаты с их контактами

#### Scenario: Клонирование недоступно для черновика
- **WHEN** рассылка имеет статус `draft`
- **THEN** кнопка «Клонировать» не отображается

#### Scenario: Статус после запуска
- **WHEN** рассылка «Новые курсы» запускается
- **THEN** её `status` устанавливается в `launched`
- **AND** `launchedAt` фиксируется

### Requirement: Удаление рассылки
The system SHALL let the administrator delete a campaign from the edit form. Deletion SHALL remove the campaign, its attachments (files from storage), and its recipients.

#### Scenario: Удаление с карточки
- **WHEN** администратор нажимает «Удалить» на форме редактирования рассылки
- **THEN** система отображает страницу подтверждения удаления

#### Scenario: Подтверждение удаления
- **WHEN** администратор подтверждает удаление рассылки
- **THEN** рассылка, её вложения и адресаты удаляются

### Requirement: Список рассылок и сортировка
The system SHALL display campaigns in a sortable table with columns: Name (with quick actions), Status, anonymous column (with "Адресаты" button), Subject. Sorting SHALL be available on name, status, and subject columns via clickable headers with ascending/descending indicators. Archived campaigns SHALL always appear at the bottom of the list regardless of sort order. The updated campaign SHALL be highlighted after save.

#### Scenario: Сортировка по столбцам
- **WHEN** менеджер кликает по заголовку столбца «Название»
- **THEN** список пересортировывается по названию (ASC/DESC переключается кликом)

#### Scenario: Архивные внизу
- **WHEN** в списке есть рассылки со статусом `archived`
- **THEN** они отображаются внизу списка независимо от выбранной сортировки

#### Scenario: Подсветка обновлённой
- **WHEN** менеджер сохраняет рассылку
- **THEN** он перенаправляется на список, где обновлённая рассылка подсвечена

#### Scenario: Визуальные индикаторы статусов
- **WHEN** рассылка имеет статус `failed`
- **THEN** её строка в списке имеет красную подсветку фона
- **AND** в колонке «Название» отображается индикатор ✕ (красный квадрат)

- **WHEN** рассылка имеет статус `launched`
- **THEN** в колонке «Название» отображается индикатор ■ (оранжевый квадрат)

- **WHEN** рассылка имеет статус `draft` или `ready`
- **THEN** в колонке «Название» отображается кнопка ▶ (зелёный квадрат) для запуска

- **WHEN** рассылка имеет статус `archived`
- **THEN** её строка в списке затемнена (greyout)

### Requirement: Данные прогресса рассылок
The system SHALL provide `GET /campaigns/statuses` returning for every campaign its `campaignId`, `delivered` and `total`, derived from `CampaignRecipient` statuses (`delivered` — recipients with status `delivered` or `opened`, `total` — all recipients of the campaign). The system SHALL provide `GET /campaigns/{id}/recipients/statuses` returning `recipientId` and `status` for every recipient of that campaign. Both endpoints SHALL be accessible to authenticated users only and SHALL NOT be cached by intermediaries.

#### Scenario: Получение статистики всех рассылок
- **WHEN** клиент запрашивает `GET /campaigns/statuses`
- **THEN** ответ содержит для каждой рассылки её `campaignId`, `delivered` и `total`
- **AND** `delivered` — число получателей со статусом `delivered` или `opened`, `total` — число всех получателей рассылки

#### Scenario: Получение статусов адресатов
- **WHEN** клиент запрашивает `GET /campaigns/123/recipients/statuses`
- **THEN** ответ содержит `recipientId` и `status` каждого адресата рассылки 123

#### Scenario: Несуществующая рассылка
- **WHEN** клиент запрашивает `GET /campaigns/999/recipients/statuses` для несуществующей рассылки
- **THEN** система возвращает ошибку 404

### Requirement: Обновление статистики в реальном времени
The system SHALL update the «Статистика» column («x из y») in the campaigns list table without a page reload, by polling `GET /campaigns/statuses` every 1.9 seconds. A cell SHALL be re-rendered only when its value changed.

#### Scenario: Статистика обновляется без перезагрузки
- **WHEN** рассылка обрабатывается фоновой командой
- **AND** менеджер находится на странице списка рассылок
- **THEN** колонка «Статистика» соответствующей рассылки обновляется автоматически без перезагрузки страницы

#### Scenario: Статистика отражает доставку
- **WHEN** у рассылки 10 получателей, 7 из них со статусом `delivered` или `opened`
- **AND** менеджер находится на странице списка рассылок
- **THEN** в колонке «Статистика» показано «7 из 10»

### Requirement: Обновление статусов адресатов в реальном времени
The system SHALL update the per-letter status badge of each recipient on the «Адресаты» page without a page reload, by polling `GET /campaigns/{id}/recipients/statuses` of that campaign every 1.1 seconds. A row SHALL be re-rendered only when its status changed. Statuses of recipients of other campaigns SHALL NOT affect the page.

#### Scenario: Статус адресата обновляется без перезагрузки
- **WHEN** фоновая команда меняет статус получателя (например, `pending` → `delivered`)
- **AND** менеджер находится на странице «Адресаты» этой рассылки
- **THEN** бейдж статуса этого адресата обновляется автоматически без перезагрузки страницы

#### Scenario: Статусы других рассылок не влияют на страницу
- **WHEN** менеджер открыл страницу «Адресаты» рассылки 123
- **AND** фоновая команда меняет статусы адресатов другой рассылки
- **THEN** статусы адресатов на открытой странице не изменяются
