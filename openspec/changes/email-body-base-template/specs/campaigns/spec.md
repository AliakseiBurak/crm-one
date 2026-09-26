## MODIFIED Requirements

### Requirement: Создание рассылки
The system SHALL let the administrator and managers create campaigns with a name, an email subject, an optional preview text (preheader), an HTML email body with tokens, and optional attachments. The body SHALL be entered through the WYSIWYG editor (visual or source mode) and SHALL be sanitized before saving. The body field on the campaign creation form SHALL be prepopulated with the base email template described in «Базовый шаблон тела письма», and the user SHALL be able to keep it, change it or replace it entirely. A campaign SHALL NOT be bound to a single organization. The campaign's email subject, preview text, body, and attachments SHALL be stored on the campaign itself. A newly created campaign's `status` SHALL default to `draft`. After saving, the user SHALL be redirected to the campaign list.

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

#### Scenario: Предзаполненное тело полностью заменено
- **WHEN** менеджер открывает форму создания рассылки и полностью заменяет предзаполненное тело своим текстом
- **THEN** рассылка сохраняется с текстом менеджера
- **AND** базовый шаблон в сохранённом теле не остаётся

### Requirement: Структура email-письма
The system SHALL form each letter as a complete HTML email document: a doctype, a `head` carrying the base email CSS, the preheader text hidden at the top, the stored campaign body, and the tracking pixel. The 600-pixels-wide table layout and the footer SHALL be part of the campaign body and not of the shell, so that the footer is visible and editable in the campaign form as described in «Базовый шаблон тела письма». The shell SHALL NOT add a footer of its own. Base CSS SHALL be inlined into element `style` attributes. The letter SHALL include a plain-text alternative generated from the HTML. The same document structure SHALL be used both for sending and for every preview.

#### Scenario: Полный HTML-документ
- **WHEN** система формирует письмо
- **THEN** письмо содержит doctype и `head` с базовым CSS шелла
- **AND** раскладка шириной 600 пикселей присутствует в письме, когда тело рассылки её содержит

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

#### Scenario: Футер берётся из тела рассылки
- **WHEN** тело рассылки содержит футер
- **THEN** футер присутствует в письме ровно в том виде, в каком сохранён в теле
- **AND** шелл не добавляет собственный футер

#### Scenario: Ссылка отписки из тела
- **WHEN** тело рассылки содержит в футере токен `{{unsubscribe_url}}`
- **AND** письмо отправляется получателю
- **THEN** в футере письма вместо токена подставлен абсолютный URL отписки этого получателя

#### Scenario: Письмо без футера
- **WHEN** менеджер удалил футер из тела рассылки
- **AND** письмо отправляется получателю
- **THEN** письмо не содержит футера

## ADDED Requirements

### Requirement: Базовый шаблон тела письма
The system SHALL provide a base email template and SHALL prepopulate the campaign body field with it on the campaign creation form. The base template SHALL contain the complete visible content of a letter: the table-based layout of 600 pixels width, the content area, and the footer with the company name, the postal address, the telephone numbers, a catalog link, a logo image, and an unsubscribe link built on the `{{unsubscribe_url}}` token. The base template SHALL be ordinary editable body content: the user SHALL be able to change the signature or the telephone numbers and SHALL be able to remove the unsubscribe link, and the letter SHALL be sent with whatever the user left. The base template SHALL survive saving without loss: every element, attribute and CSS property it uses SHALL be within the body sanitizer allowlist, and every link it contains SHALL use the `https`, `mailto` or `tel` scheme — the schemes the body sanitizer preserves — so that no link is stripped. The body sanitizer's set of allowed link schemes SHALL NOT be widened. The base template SHALL be applied on the creation form only; the edit form SHALL show the stored body unchanged.

#### Scenario: Форма создания предзаполнена базовым шаблоном
- **WHEN** менеджер открывает форму создания рассылки
- **THEN** поле «Текст письма» уже содержит базовый шаблон письма
- **AND** в нём видны раскладка шириной 600 пикселей, контентная область и футер с подписью, телефонами, логотипом и ссылкой «Отписаться от рассылки»

#### Scenario: Базовый шаблон переживает сохранение
- **WHEN** менеджер сохраняет рассылку, не изменяя предзаполненное тело
- **THEN** сохранённое тело сохраняет раскладку 600 пикселей, футер и логотип
- **AND** ссылки футера остаются рабочими, в том числе ссылка на каталог

#### Scenario: Изменение подписи и телефонов
- **WHEN** менеджер меняет в предзаполненном футере название компании и один из телефонов
- **AND** сохраняет рассылку
- **THEN** в отправленном письме используются изменённое название и изменённый телефон

#### Scenario: Удаление ссылки отписки
- **WHEN** менеджер удаляет из предзаполненного футера ссылку «Отписаться от рассылки»
- **AND** сохраняет рассылку
- **THEN** рассылка сохраняется без ссылки отписки
- **AND** отправленное письмо не содержит ссылки отписки

#### Scenario: Ссылка отписки в предзаполненном футере
- **WHEN** менеджер сохраняет предзаполненный базовый шаблон без изменений
- **AND** рассылка отправляется получателю
- **THEN** в футере письма вместо токена `{{unsubscribe_url}}` подставлен абсолютный URL отписки получателя

#### Scenario: Форма редактирования показывает сохранённое тело
- **WHEN** менеджер открывает форму редактирования существующей рассылки
- **THEN** поле «Текст письма» содержит сохранённое тело рассылки, а не базовый шаблон
