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
The system SHALL form each letter as a complete HTML email document: a doctype, a `head` carrying the base email CSS, the preheader text hidden at the top, the table-based layout shell of 600 pixels width, the stored campaign body, and the tracking pixel. The layout shell SHALL remain part of the document wrapper so that the 600-pixels-wide card does not break when the user edits the body, and the shell SHALL NOT contain a footer. The footer and the rest of the visible letter content SHALL be part of the campaign body, so that they are visible and editable in the campaign form as described in «Базовый шаблон тела письма». Base CSS SHALL be inlined into element `style` attributes. The letter SHALL include a plain-text alternative generated from the HTML. The same document structure SHALL be used both for sending and for every preview.

#### Scenario: Полный HTML-документ
- **WHEN** система формирует письмо
- **THEN** письмо содержит doctype, `head` с базовым CSS шелла и табличную раскладку шириной 600 пикселей

#### Scenario: Раскладка не дублируется телом
- **WHEN** тело рассылки создано с базовым шаблоном
- **THEN** письмо содержит ровно одну раскладку шириной 600 пикселей
- **AND** тело не добавляет вторую раскладку внутрь раскладки шелла

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

### Requirement: Санитизация HTML тела письма
On create and update the system SHALL sanitize the campaign body. The sanitizer SHALL follow a permissive model: it SHALL allow the full set of HTML elements and attributes considered safe by the W3C standard, so that a manager may paste arbitrary layout markup, and SHALL deny an explicit list of document, interactive, embedded and legacy elements. The denied list SHALL cover scripts, embedded frames and objects, and document-level elements; it is not required to be exhaustive, because scripts, frames, objects and form controls are in any case absent from the safe set and are dropped by the sanitizer's default action. Event-handler attributes SHALL NOT survive sanitization under any configuration, since the safe attribute set contains none. Elements that the WYSIWYG editor does not support — `pre`, `thead`, `tfoot` — and legacy presentation attributes (`align`, `valign`, `bgcolor`, `border`, `cellpadding`, `cellspacing`, `hspace`, `vspace`, `nowrap`) SHALL be removed, because the editor would otherwise lose them when the user switches to the visual mode. Script-executing URL schemes (`javascript`, `vbscript`, `data`) SHALL NOT be permitted in links, because the body is rendered on the campaign card inside the CRM page; every other link scheme SHALL be preserved, so that a pasted link keeps working, and relative addresses SHALL be preserved because the `{{unsubscribe_url}}` token carries no URL scheme and would otherwise lose its `href` on save. Image sources SHALL remain restricted to `https`. Inline `style` SHALL be limited to an allowlist of safe CSS properties. Campaign tokens SHALL NOT be used inside CSS declaration values: a declaration whose value contains a `{{...}}` token SHALL be removed while unrelated allowed declarations in the same `style` attribute SHALL be preserved. The body SHALL be limited to 200 000 characters. If sanitization leaves no allowed content, saving SHALL fail with a validation error and the campaign SHALL NOT be saved. The stored body SHALL be the sanitized HTML used later for previews and emails. The system SHALL report which elements did not survive sanitization and SHALL warn the user about them on save.

#### Scenario: Скрипты и обработчики событий удаляются
- **WHEN** тело письма содержит тег script или атрибут-обработчик события (например onerror)
- **THEN** после сохранения они отсутствуют в теле

#### Scenario: Произвольная разметка из внешнего источника сохраняется
- **WHEN** менеджер вставляет в тело письма верстку с элементами, которых нет в узком списке (например `abbr`, `center`, `figure`)
- **THEN** эти элементы сохраняются в теле

#### Scenario: Запрещённые элементы удаляются
- **WHEN** тело письма содержит документный, интерактивный или встраиваемый элемент (например `iframe`, `button`, `template`)
- **THEN** после сохранения этот элемент и его содержимое отсутствуют в теле

#### Scenario: Элементы, не поддерживаемые редактором, удаляются
- **WHEN** тело письма содержит `pre`, `thead`, `tfoot` или legacy-атрибут таблицы (например `cellpadding`)
- **THEN** после сохранения они отсутствуют в теле
- **AND** разметка письма не теряется при переключении редактора в визуальный режим

#### Scenario: Ссылка отписки на токене переживает сохранение
- **WHEN** тело письма содержит ссылку с `href="{{unsubscribe_url}}"`
- **THEN** после сохранения атрибут href сохраняется в теле

#### Scenario: Скриптовые схемы ссылок не допускаются
- **WHEN** тело письма содержит ссылку со схемой `javascript`, `vbscript` или `data`
- **THEN** такая ссылка не сохраняется в теле

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

#### Scenario: Предупреждение о потерянной разметке
- **WHEN** менеджер сохраняет тело, часть элементов которого не пережила очистку
- **THEN** после сохранения показывается предупреждение с названиями удалённых элементов
- **AND** рассылка сохраняется

#### Scenario: Предупреждение не показывается без потерь
- **WHEN** менеджер сохраняет тело, из которого ничего не было удалено
- **THEN** предупреждение о потерянной разметке не показывается

## ADDED Requirements

### Requirement: Базовый шаблон тела письма
The system SHALL provide a base email template and SHALL prepopulate the campaign body field with it on the campaign creation form. The base template SHALL contain the complete visible content of a letter that the user is meant to edit: a content paragraph, and the footer with the company name, the postal address, the telephone numbers as `tel:` links, a catalog link, a logo image, and an unsubscribe link built on the `{{unsubscribe_url}}` token. The base template SHALL NOT contain the letter layout — the doctype, the 600-pixels-wide layout shell, the base CSS and the tracking pixel SHALL remain in the document wrapper, so that the card does not break when the user edits the body. The base template SHALL be ordinary editable body content: the user SHALL be able to change the signature or the telephone numbers and SHALL be able to remove the unsubscribe link, and the letter SHALL be sent with whatever the user left. The base template SHALL survive saving without loss: every element, attribute and CSS property it uses SHALL be within the body sanitizer's allowed set, and every link it contains SHALL survive sanitization, so that no link is stripped. The base template SHALL carry its presentation in inline `style` attributes rather than in CSS classes, because the body sanitizer does not preserve `class` and the letter's styles would otherwise be lost on the first save. The base template SHALL be applied on the creation form only; the edit form SHALL show the stored body unchanged.

#### Scenario: Форма создания предзаполнена базовым шаблоном
- **WHEN** менеджер открывает форму создания рассылки
- **THEN** поле «Текст письма» уже содержит базовый шаблон письма
- **AND** в нём видны абзац контента и футер с подписью, телефонами, логотипом и ссылкой «Отписаться от рассылки»
- **AND** раскладки письма в поле нет — она остаётся в письме и не ломается от правок тела

#### Scenario: Базовый шаблон переживает сохранение
- **WHEN** менеджер сохраняет рассылку, не изменяя предзаполненное тело
- **THEN** сохранённое тело сохраняет подпись, футер, логотип и рабочие ссылки, включая `tel:`-ссылки телефонов
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
