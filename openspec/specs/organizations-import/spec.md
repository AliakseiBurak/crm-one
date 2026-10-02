# Organizations Import

Импорт организаций из CSV-файла или из структурированного JSON-ответа:
загрузка, проверка структуры, построчная интерактивная проверка и
редактирование с пакетным сохранением.

## Purpose

Позволяет администратору загружать CSV-файл с организациями, проверять
заголовки и содержимое, интерактивно редактировать и утверждать данные
перед сохранением в систему.

## Requirements

### Requirement: Доступ к импорту ограничен администратором
The system SHALL provide the import page at `/admin/import` and all
import endpoints exclusively to users with `ROLE_ADMIN`. The system
SHALL deny access to non-admin users with HTTP 403.

#### Scenario: Администратор открывает страницу импорта
- **WHEN** аутентифицированный администратор переходит на `/admin/import`
- **THEN** отображается страница со списком ранее выполненных импортов

#### Scenario: Менеджер не может открыть страницу импорта
- **WHEN** аутентифицированный менеджер переходит на `/admin/import`
- **THEN** система отклоняет запрос с ошибкой 403

### Requirement: Вкладки импорта: Результаты, CSV, JSON, LLM и Промпт

The import page SHALL offer five tabs in this order: «Результаты», «CSV»,
«JSON», «LLM» and «Промпт». The «Результаты» tab SHALL hold the list of import
runs and no form; the CSV tab a file field for a CSV export; the JSON tab a file
field for a response file; the LLM tab the provider controls, a field for the
source text and the response obtained from the model; the «Промпт» tab no form
at all but the prompt itself. All of them SHALL feed the same import run, the
same review packages of at most 20 organizations, the same per-row
transaction, the same duplicate choice and the same completion notice. The only
difference SHALL be how a row is obtained: the CSV tab parses cells, the JSON tab
receives already-structured objects, the LLM tab hands them over as a file the
administrator downloaded.

The list of import runs SHALL be shown on the «Результаты» tab only. A run is
where it was created, not where the format happens to be, so a tab about how a
row is obtained SHALL NOT carry a row of the finished state either; rendered on
every tab, the same list repeated itself on four screens and told the
administrator nothing about the tab they were on. The list SHALL be rendered
under the tabs of that tab, which is the first one.

The «Результаты» tab SHALL be where the administrator lands after any action on a
run: uploading a file, approving a package and replacing a file SHALL all return
them there, because that is where the resulting row is visible.

No tab SHALL render section headings: the import pages carry a form, a table or
a prompt, and a heading above each of them repeats what the element below it
already says while pushing the useful part of the page below the fold. What a
tab needs to explain, it says in one line under the tabs.

The prompt SHALL be rendered on its own tab and SHALL NOT be shown as a block on
the LLM tab. The LLM tab sends that same prompt itself and links to the tab;
whether to copy the prompt into a chat of one's own is a separate decision from
whether to call the provider from here, and a prompt hidden behind a disclosure
on the model's own tab serves neither.

#### Scenario: Пять вкладок, первая — «Результаты»

- **WHEN** администратор открывает страницу импорта
- **THEN** отображаются пять вкладок: «Результаты», «CSV», «JSON», «LLM» и «Промпт»
- **AND** активна вкладка «Результаты»

#### Scenario: Список прогонов только на своей вкладке

- **WHEN** администратор открывает вкладку «Результаты»
- **THEN** под вкладками отображается список всех прогонов импорта

#### Scenario: На остальных вкладках списка прогонов нет

- **WHEN** администратор открывает вкладку «CSV», «JSON», «LLM» или «Промпт»
- **THEN** список прогонов на странице не отображается
- **AND** вкладка содержит только свою форму или свой текст

#### Scenario: После действия администратор попадает к списку

- **WHEN** администратор загружает файл, утверждает пакет или заменяет файл прогона
- **THEN** открывается вкладка «Результаты»
- **AND** изменённый прогон виден в списке

#### Scenario: Вкладка JSON — только загрузка файла

- **WHEN** администратор открывает вкладку «JSON»
- **THEN** отображается поле для выбора файла с ответом и одна строка о том, откуда берётся ответ
- **AND** описание формата ответа и кнопка «Скачать JSON-схему» на этой вкладке не показываются — они на вкладке «Промпт»
- **AND** переход между вкладками является переходом по адресу, а не переключением видимости на месте

#### Scenario: Переключение на вкладку «Промпт»

- **WHEN** администратор открывает вкладку «Промпт»
- **THEN** отображается текст промпта целиком, готовый к копированию
- **AND** рядом — описание формата ответа и ссылка «Скачать JSON-схему»
- **AND** на вкладке «LLM» промпт отдельным блоком не показывается, а вкладка на него ссылается

#### Scenario: На вкладке «Промпт» описано, чего ждёт импорт

- **WHEN** администратор открывает вкладку «Промпт»
- **THEN** отображается описание формата ответа: обязательный массив `organizations` и то, что у организации обязательно только название

#### Scenario: Адрес вкладки «Промпт» не перехватывается адресом прогона

- **WHEN** администратор открывает `/admin/import/prompt`
- **THEN** открывается страница с промптом, а не прогон импорта

#### Scenario: Менеджер не видит вкладки импорта

- **WHEN** аутентифицированный менеджер открывает страницу импорта
- **THEN** система отклоняет запрос с ошибкой 403

#### Scenario: Пакеты на вкладке JSON той же величины

- **WHEN** администратор проверяет пакет на вкладке «JSON»
- **THEN** отображается не более 20 организаций одной таблицей с теми же полями, что и на вкладке «CSV», кроме «Отрасли», «Города», «УНП» и «Годового плана», объявленных в «Отображение пакета для проверки»

#### Scenario: Адреса вкладок не перехватываются адресом прогона

- **WHEN** администратор открывает `/admin/import/json`, `/admin/import/json-schema`, `/admin/import/llm` или `/admin/import/prompt`
- **THEN** открывается соответствующая страница, а не прогон импорта
- **AND** ни один из этих адресов не трактуется как идентификатор прогона

### Requirement: Формат CSV-файла
The import SHALL accept UTF-8 encoded files with comma-separated values in
the RFC 4180 format: fields MAY be enclosed in double quotes, and quoted
fields MAY contain commas, line breaks (LF or CRLF) and doubled quotes
(`""`). A leading byte-order mark, if present, SHALL be ignored.

A backslash SHALL have no special meaning in the format. It is an ordinary
character: a backslash placed immediately before a closing quote SHALL NOT
escape that quote, the quoted field SHALL end at that quote, and the backslash
SHALL remain part of the field's text. Only a doubled quote (`""`) inside a
quoted field represents a literal quote.

The first record SHALL be the header row with the following columns in
order: `Компания`, `Актуальный курс`, `Взаимодействия`, `Контакты`,
`Следующий контакт`, `Для чего звонок?`, `Текущее состояние`,
`Учились у нас`, `Составление плана на год`. Header values SHALL be
compared after trimming surrounding whitespace, and trailing empty header
fields SHALL be ignored. Every data record SHALL contain the declared
columns; records whose fields are all empty SHALL be skipped.

The columns SHALL be interpreted as follows:

| Column | Content | Target |
| --- | --- | --- |
| `Компания` | organization name; MAY contain a line break followed by a parenthetical description | `Organization.name` (truncated to 255 characters) |
| `Актуальный курс` | free text | labelled fragment in `Organization.description` |
| `Взаимодействия` | multiline interaction log, entries separated by date tokens | one made `Call` per dated entry |
| `Контакты` | free-form multiline contact data: names, positions, phones, emails, notes | one or more `Contact` entities |
| `Следующий контакт` | a date or a placeholder (`-`, `_`, `не актуально`, `нет`) | one planned `Call`: `scheduledAt` set, `madeAt` null |
| `Для чего звонок?` | free text: purpose of the next contact | notes of the planned call |
| `Текущее состояние` | free text log | **ignored** — the column is required in the header and its content is not read |
| `Учились у нас` | free text about previous training | `Organization.coursesAttended` (truncated to 255 characters) |
| `Составление плана на год` | free text that MAY hold a website address or a domain | `Organization.website` when the value is a website address or a domain; **ignored** otherwise |

A `Следующий контакт` cell that is empty or holds a placeholder SHALL NOT create
a planned call. A date in the past SHALL create a planned call like any other:
the planned call is stored with the date as given, and an already-past date is
not adjusted, skipped or rejected.

`Текущее состояние` SHALL be ignored: the column is required in the header so
that the declared format stays stable, but its content is not read and not
stored. `Актуальный курс` has no dedicated field either; the system SHALL append
its content to `Organization.description` as a labelled fragment
(`Актуальный курс: …`). The system SHALL route a `Составление плана на год`
value that is a bare website address or a domain to `Organization.website`; any
other value in that column SHALL be ignored. The import SHALL NOT populate
`Organization.annualPlan`.

#### Scenario: «Следующий контакт» создаёт планированный звонок
- **WHEN** в колонке «Следующий контакт» указано 08.06.2026, а в «Для чего звонок?» — «созвониться по КП»
- **THEN** создаётся звонок с `scheduledAt` = 08.06.2026 и `madeAt` = null
- **AND** в заметке звонка сохраняется текст «созвониться по КП»

#### Scenario: Дата следующего контакта в прошлом
- **WHEN** в колонке «Следующий контакт» указана дата, уже прошедшая
- **THEN** создаётся планированный звонок с этой датой без изменений
- **AND** запись не отбрасывается и дата не корректируется

#### Scenario: Плейсхолдер следующего контакта
- **WHEN** в колонке «Следующий контакт» указано «-», «_», «не актуально» или «нет»
- **THEN** планированный звонок не создаётся

#### Scenario: «Текущее состояние» игнорируется
- **WHEN** в колонке «Текущее состояние» указан текст
- **THEN** этот текст не читается при импорте
- **AND** он не попадает ни в описание организации, ни в другое поле

#### Scenario: «Актуальный курс» переносится в описание
- **WHEN** в колонке «Актуальный курс» указан текст
- **THEN** в `Organization.description` добавляется фрагмент с этим текстом
- **AND** исходный текст не теряется

#### Scenario: Ссылка из столбца годового плана попадает в сайт
- **WHEN** в колонке «Составление плана на год» указано значение, являющееся адресом сайта или доменом
- **THEN** это значение сохраняется в `Organization.website`
- **AND** `Organization.annualPlan` не заполняется этим значением

#### Scenario: Утверждение о годовом плане игнорируется
- **WHEN** в колонке «Составление плана на год» указано утверждение о составлении годового плана, а не адрес сайта
- **THEN** это значение не сохраняется
- **AND** `Organization.annualPlan` остаётся пустым (null)

This date grammar governs the columns that produce calls only: `Взаимодействия`
and `Следующий контакт`. It does not apply to any other column.

An interaction entry SHALL begin with a date token enclosed in parentheses. A
parenthesised group is read as a date token only when it starts with a date whose
day, month and year are all unambiguous. A date is read in the time zone of the
database, so a call made from a date token is stored at 12:00 of that day in
that time zone. The recognised shapes are:

| Shape | Example | Reading |
| --- | --- | --- |
| `D.M.YYYY` | `(8.04.2025)` | one- or two-digit day and month |
| `DD.MM.YYYY` | `(29.05.2026)` | dot separator |
| `DD.MM.YY` | `(21.10.25)` | two-digit year read as `20YY` |
| `DD/MM/YYYY` | `(17/09/2025)` | slash separator |
| `DD,MM.YYYY` | `(08,07.2025)` | comma separator |
| trailing `_` or `.` | `(05.11.2025_)`, `(05.11.2025.)` | trailing characters ignored |

A group that does not start with such a date is not a date token, and its text
SHALL remain part of the surrounding notes. This covers both a group that does
not begin with a date at all — «(приятная девушка)», «(отдел кадров)»,
«(+375171234567)» — and a group that looks like a date but whose year is absent
or unusable, such as «(09.04)», «(09.04.202)» or «(09.04.20255)». The system
SHALL NOT infer a year, SHALL NOT repair or truncate one, and SHALL NOT carry a
year over from a preceding entry.

The text before the first date token SHALL be treated as an entry without a
date; the text after a date token, up to the next date token, SHALL be the notes
of that entry. Text that does not match a date token SHALL NOT be discarded.

A group that starts with a date and then continues with further text — a range
such as `(26.09.2025-06.10.2025- 13.10.2025)` — SHALL be read as one entry dated
with the first date, and the whole remaining text SHALL be kept in the notes of
that entry.

#### Scenario: Многострочные ячейки и кавычки
- **WHEN** администратор загружает файл, в котором ячейки содержат переводы строк, запятые и удвоенные кавычки
- **THEN** каждая запись разбирается на объявленные колонки без потери содержимого

#### Scenario: Обратный слэш перед закрывающей кавычкой
- **WHEN** ячейка в кавычках заканчивается текстом «КП можно\",»
- **THEN** ячейка заканчивается на этой кавычке, а обратный слэш остаётся в её тексте
- **AND** следующая запись файла разбирается отдельно, а не поглощается в эту ячейку

#### Scenario: Пустой столбец в конце заголовка
- **WHEN** строка заголовка заканчивается запятой и содержит пустой десятый столбец
- **THEN** проверка заголовков проходит
- **AND** пустой столбец не влияет на разбор данных

#### Scenario: Пробел в конце имени колонки
- **WHEN** заголовок содержит колонку «Текущее состояние » с пробелом в конце имени
- **THEN** проверка заголовков проходит

#### Scenario: Полностью пустые строки
- **WHEN** файл содержит строки, все поля которых пусты
- **THEN** такие строки пропускаются
- **AND** они не учитываются в общем количестве строк

#### Scenario: Варианты формата даты во взаимодействиях
- **WHEN** во «Взаимодействиях» встречается запись «(8.04.2025) Созвон»
- **THEN** создаётся звонок с датой 08.04.2025 и заметкой «Созвон»

#### Scenario: Запись с косой чертой в дате
- **WHEN** во «Взаимодействиях» встречается запись «(17/09/2025) Недозвон»
- **THEN** создаётся звонок с датой 17.09.2025 и заметкой «Недозвон»

#### Scenario: Дата без года не распознаётся
- **WHEN** во «Взаимодействиях» встречаются записи «(25.08.2026) Созвон» и «(31.08) Договорённость»
- **THEN** запись «(31.08) Договорённость» остаётся текстом в заметках
- **AND** отдельный звонок с датой 31.08.2026 не создаётся

#### Scenario: Год не восстанавливается из предыдущей записи
- **WHEN** во «Взаимодействиях» встречаются записи «(25.08.2026) Созвон» и «(09.04.202) Созвон»
- **THEN** текст «(09.04.202) Созвон» остаётся в заметках и не превращается в звонок
- **AND** год 2026 из предыдущей записи для него не подставляется

#### Scenario: Интервал дат в одной записи
- **WHEN** во «Взаимодействиях» встречается запись «(26.09.2025-06.10.2025- 13.10.2025) Недозвон снова»
- **THEN** создаётся один звонок с датой 26.09.2025
- **AND** в заметке сохраняется исходный текст записи целиком

#### Scenario: В скобках не дата
- **WHEN** во «Взаимодейстиях» встречается текст «(отдел кадров) не поднимают»
- **THEN** этот текст остаётся частью заметки звонка
- **AND** он не считается датой

#### Scenario: Дата с негодным годом остаётся в тексте
- **WHEN** во «Взаимодействиях» встречается запись «(09.04.20255) Созвон»
- **THEN** эта запись остаётся текстом в заметках и не создаёт звонок с датой

#### Scenario: Текст без даты во взаимодействиях
- **WHEN** во «Взаимодействиях» текст перед первой датой не начинается с даты
- **THEN** создаётся звонок без даты с этим текстом в заметке
- **AND** запись остаётся доступной для исправления при проверке

### Requirement: Формат JSON-данных

The JSON tab SHALL accept a response as an uploaded file. A response obtained
from a model SHALL reach it the same way: the administrator downloads it from
the LLM tab and uploads it here, so one delivery path serves both.
The response SHALL be a single object with a required `organizations` array
holding at least one element. Each element of that array describes one
organization and MAY carry the fields below. The system SHALL publish this
contract as a JSON Schema document and SHALL use that same document to render
the field dictionary inside the prompt, to serve the download, and to validate a
submitted response.

| Field | Type | Required | Target |
| --- | --- | --- | --- |
| `name` | string | yes | `Organization.name`, max 255 |
| `industry` | string | no | `Organization.industry`, max 255 |
| `city` | string | no | `Organization.city`, max 255 |
| `website` | string | no | `Organization.website`, max 255 |
| `description` | string | no | `Organization.description` |
| `coursesAttended` | string | no | `Organization.coursesAttended`, max 255 |
| `contacts` | array | no | `Contact` entities; each with `name` (required), `position`, `phone` (max 32), `email`, `isMain` (boolean) |
| `calls` | array | no | made `Call` entities; each with `date` and `notes` |
| `nextCall` | object | no | planned `Call`; with `date` and `purpose` |
| `unp` | string | no | `Organization.unp`, max 32 |
| `annualPlan` | string | no | `Organization.annualPlan`, max 255 |

The format SHALL contain `unp`, `annualPlan` and `contacts[].isMain`, and none
of them SHALL be required. A payload handed over from another source carries
them for real, and rejecting such an answer wholesale rejected the plain list of
organizations this format is also for. What the format cannot do is make a model
report a value it does not have: the prompt asks the model not to invent `unp`
and `annualPlan` and to carry them over as they are when the source has them.

`contacts[].isMain` SHALL mean "основной контакт в организации": true is
admissible for at most one contact of an organization. When no contact carries
the flag, contacts are saved with it unset and
`MailingService::effectiveMainContact()` falls back to the contact with the
lowest ID, so absence is not an error.

A `calls[].date` and a `nextCall.date` value SHALL match the date shapes of
«Формат CSV-файла» — `D.M.YYYY`, `DD.MM.YYYY`, `DD.MM.YY` read as `20YY`,
`DD/MM/YYYY`, `DD,MM.YYYY`, with an optional trailing `_` or `.` — but SHALL be
written **without** the parentheses that delimit a CSV entry group and SHALL NOT
be surrounded by entry text. The format SHALL declare this as a `pattern`. The
system SHALL NOT require or impose ISO 8601.

A missing or empty optional field SHALL be treated as absent and SHALL NOT be
stored as an empty string. An empty `contacts` or `calls` array SHALL mean no
contacts and no calls for that organization.

#### Scenario: Скачивание JSON-схемы

- **WHEN** администратор нажимает «Скачать JSON-схему» на вкладке «Промпт»
- **THEN** браузер получает файл JSON Schema как вложение
- **AND** файл описывает те же поля, что перечислены в формате JSON-данных

#### Scenario: Промпт согласован со схемой

- **WHEN** администратор открывает вкладку «Промпт»
- **THEN** описание каждого поля в промпте совпадает с описанием того же поля в скачиваемой на этой же вкладке схеме

#### Scenario: УНП из файла сохраняется

- **WHEN** в ответе указано `unp` длиной не более 32 символов
- **THEN** ответ проходит проверку, а значение видно в пакете и сохраняется в `Organization.unp`

#### Scenario: Слишком длинный УНП отклоняет ответ

- **WHEN** в ответе указано `unp` длиннее 32 символов
- **THEN** ответ отклоняется с указанием номера организации и поля `unp`

#### Scenario: Годовой план из файла сохраняется

- **WHEN** в ответе указано `annualPlan`
- **THEN** ответ проходит проверку, а значение видно в пакете и сохраняется в `Organization.annualPlan`

#### Scenario: Отметка основного контакта из файла сохраняется

- **WHEN** в ответе у контакта указано `isMain: true`
- **THEN** в пакете отметка основного уже стоит, и контакт сохраняется основным
- **AND** остальные контакты организации сохраняются неосновными

#### Scenario: Модель не выдумывает УНП и годовой план

- **WHEN** промпт объясняет поля `unp` и `annualPlan`
- **THEN** сказано, что выдумывать их нельзя, а найденное в источнике переносится как есть

#### Scenario: Ответ из одних названий принимается

- **WHEN** администратор отправляет ответ, где у каждой организации есть только `name`
- **THEN** ответ проходит проверку
- **AND** каждая организация создаётся при утверждении пакета, с пустыми отраслью, городом, УНП и годовым планом

#### Scenario: Дата в JSON не переформатируется

- **WHEN** в `calls` указана дата "17/09/2025"
- **THEN** дата читается как 17.09.2025
- **AND** исходная запись даты не заменяется на другой формат при сохранении

#### Scenario: Двузначный год читается как двадцатый

- **WHEN** в `calls` указана дата "21.10.25"
- **THEN** дата читается как 21.10.2025

#### Scenario: Дата без года отклоняется проверкой

- **WHEN** в `calls` указана дата "31.08" без года
- **THEN** ответ отклоняется с указанием номера организации и поля `calls[].date`
- **AND** запись не превращается в звонок и не сохраняется текстом в заметке другого звонка

#### Scenario: Отсутствующее необязательное поле не сохраняется как пустая строка

- **WHEN** в объекте организации отсутствует поле `industry`
- **THEN** `Organization.industry` остаётся пустым (null)
- **AND** пустая строка не сохраняется

#### Scenario: Пустые массивы контактов и звонков

- **WHEN** в объекте организации `contacts` и `calls` — пустые массивы
- **THEN** организация сохраняется без контактов и без звонков

### Requirement: Строгая проверка JSON-ответа

The JSON path SHALL be stricter than the CSV path, and the strictness SHALL be
declared in the published schema rather than applied downstream. For every field
it constrains, the schema SHALL carry the constraint the validator enforces: a
`pattern` on `calls[].date` and `nextCall.date`, a `maxLength` on `name`,
`industry`, `city`, `website`, `coursesAttended`, `contacts[].phone`, `unp` and
`annualPlan`, a `type` of boolean on `contacts[].isMain`, `additionalProperties`
of false on every object, and `required` on `name` and `contacts[].name`.

A response violating any declared constraint SHALL be rejected in full: no
import run SHALL be created, no payload SHALL be stored, and no value SHALL be
repaired, shortened, rounded, completed or inferred. In particular the system
SHALL NOT infer a missing year, SHALL NOT truncate or complete a year, SHALL NOT
carry a value over from a preceding or following entry, and SHALL NOT keep an
unusable value as free text in the notes of another call — the CSV path's
note-continuation behaviour has no counterpart here.

The schema SHALL NOT declare `format` keywords (`email`, `uri`) as validation:
this validator treats them as annotations, and an unenforced keyword in a
published contract is worse than its absence.

The field dictionary rendered inside the prompt SHALL carry, for each field, the
same constraints as its schema keywords — the accepted date shapes, the maximum
lengths, the required fields — so that the model is told the format before it
writes rather than after it fails.

The prompt SHALL carry no worked example. An example is returned as data: the
model handed back the sample organization with its contact and phone alongside
the organizations that really were in the source. What the format is, the field
dictionary already says in words, and that is what an example repeated.

Because there is no sample to copy, the prompt SHALL say how absence is
written: a field without data SHALL be omitted rather than filled, a placeholder
such as «пример», «тест», «неизвестно» or «-» SHALL NOT be written, and the
same contact, phone or mailbox SHALL NOT be repeated across organizations.

A missing optional field SHALL never block an import. Only an empty required
`name` or a present-but-unparseable date makes an answer fail.

#### Scenario: Пустое обязательное имя отклоняет весь ответ

- **WHEN** в ответе у третьей организации поле `name` — пустая строка
- **THEN** ответ отклоняется с указанием третьей организации и поля `name`
- **AND** запись импорта не создаётся, а остальные организации ответа не импортируются

#### Scenario: Телефон длиннее 32 символов отклоняет весь ответ

- **WHEN** в ответе у седьмой организации `contacts[0].phone` длиннее 32 символов
- **THEN** ответ отклоняется с указанием седьмой организации и поля `phone`

#### Scenario: Год не подставляется

- **WHEN** в ответе указаны даты "25.08.2026" и "09.04.202"
- **THEN** ответ отклоняется, а год 2026 из первой даты ко второй не подставляется

#### Scenario: Опечатка в имени поля отклоняет ответ

- **WHEN** в ответе поле названо `industri` вместо `industry`
- **THEN** ответ отклоняется как нарушение схемы
- **AND** поле не переносится в организацию под другим именем

#### Scenario: Отметка основного должна быть логическим значением

- **WHEN** в ответе у контакта `isMain` — строка «да»
- **THEN** ответ отклоняется с указанием организации и поля `contacts[].isMain`

#### Scenario: В промпте нет примера ответа

- **WHEN** формируется промпт
- **THEN** он не содержит ни одного примера организации, контакта или звонка
- **AND** формат ответа описан словами и словарём полей

#### Scenario: Отсутствующее необязательное поле не блокирует импорт

- **WHEN** в ответе у организации указано только `name`
- **THEN** ответ принимается и создаёт прогон

#### Scenario: Схема не содержит непроверяемых ключевых слов

- **WHEN** администратор скачивает JSON-схему
- **THEN** в ней нет ключевых слов `format`
- **AND** все объявленные ограничения проверяются валидатором

#### Scenario: Промпт сообщает ограничения до проверки

- **WHEN** администратор открывает промпт на вкладке «Промпт»
- **THEN** в нём названы допустимые форматы даты, максимальные длины полей и обязательные поля
- **AND** эти ограничения совпадают с объявленными в скачиваемой схеме

### Requirement: Загрузка CSV-файла
The system SHALL provide an upload form that accepts a single CSV file.
Import runs SHALL be independent: an unfinished run SHALL NOT block another
upload. The system SHALL validate the file against
the declared format («Формат CSV-файла»): the header row SHALL contain
the expected columns, and the file SHALL contain at least one non-empty
data record. The system SHALL reject files with missing or unexpected
non-empty columns and SHALL display the list of expected headers. The
system SHALL store the uploaded file and create an `ImportRun`
record with `totalRows` equal to the number of non-empty data records
(excluding the header) and `processedRows` = 0, and SHALL return the
administrator to the import list where the new run appears as a row. Parsing the
records into organization/contact/call DTOs and the 20-row review SHALL NOT start
on upload: it starts when the administrator chooses «Импортировать» in that
row («Список импортов»). Counting the records and checking the header are cheap
and happen on upload, so an unusable file is still rejected before it is
stored.

#### Scenario: Успешная загрузка CSV-файла
- **WHEN** администратор загружает CSV-файл с правильными заголовками и 400 строками данных
- **THEN** файл сохраняется на сервере
- **AND** создаётся запись импорта с totalRows = 400 и processedRows = 0
- **AND** администратор возвращается к списку импортов, где файл виден отдельной строкой

#### Scenario: Разбор начинается по отдельному действию
- **WHEN** CSV-файл только что загружен и ещё не импортирован
- **THEN** пакет для проверки не сформирован
- **AND** разбор записей файла не выполняется
- **AND** пакет появляется только после нажатия «Импортировать»

#### Scenario: Загрузка при наличии активного прогона импорта
- **WHEN** администратор загружает CSV-файл, пока существует импорт с processedRows < totalRows
- **THEN** загрузка не отклоняется и создаёт ещё одну запись импорта
- **AND** оба прогона остаются в списке, у каждого своя строка и свои действия

#### Scenario: Загрузка файла с неправильными заголовками
- **WHEN** администратор загружает CSV-файл, в котором отсутствует колонка «Взаимодействия»
- **THEN** загрузка отклоняется
- **AND** отображается сообщение со списком ожидаемых заголовков

#### Scenario: Загрузка пустого файла
- **WHEN** администратор загружает CSV-файл без строк данных (только заголовки)
- **THEN** загрузка отклоняется с сообщением об отсутствии данных

#### Scenario: Повторная загрузка того же файла
- **WHEN** администратор загружает файл с именем, идентичным ранее загруженному
- **THEN** создаётся новая запись импорта (допускаются дубли файлов)

### Requirement: Загрузка JSON-ответа

Import runs SHALL be independent: an unfinished run SHALL NOT block submitting
an answer, and one run's progress SHALL NOT affect another's. The system SHALL
parse the payload,
validate it against the published JSON Schema, and SHALL report every violation
it finds, naming the organization by its position in the `organizations` array
and the offending field. A payload with at least one organization SHALL be stored
as the run's file, SHALL create an import run with `totalRows` equal to the
length of the `organizations` array and `processedRows` = 0, and SHALL return the
administrator to the import list, where the new run appears as a row of its own.
`totalRows` counts organizations, so the review package, the progress indicator
and the completion notice behave identically on every tab.

Submitting a response SHALL NOT start the import: parsing the payload into
organization, contact and call DTOs and building a review package SHALL NOT begin
on submission. They SHALL begin when the administrator chooses «Импортировать» in
the run's row, exactly as on the CSV tab.

The run's `filename` SHALL be the uploaded file's original name. The response
SHALL be uploaded as a file even when the administrator obtained it from the LLM
tab: a name that came down with the file is more useful in the run list than any
name the application could invent.

A payload that is not valid JSON, or that violates the schema, SHALL NOT create
an import run and SHALL NOT be stored.

#### Scenario: Успешная загрузка файла с ответом

- **WHEN** администратор загружает файл с ответом на 40 организациях
- **THEN** создаётся запись импорта с totalRows = 40 и processedRows = 0
- **AND** администратор возвращается на вкладку «Результаты», где прогон виден отдельной строкой

#### Scenario: Имя прогона — имя загруженного файла

- **WHEN** администратор загружает файл `import-01.10.2026-19-30-00.json`
- **THEN** в колонке «Файл» строки прогона указано именно это имя

#### Scenario: Разбор ответа начинается по отдельному действию

- **WHEN** файл с ответом только что загружен и прогон ещё не импортирован
- **THEN** пакет для проверки не сформирован
- **AND** разбор ответа в DTO не выполняется
- **AND** пакет появляется только после нажатия «Импортировать»

#### Scenario: Загрузка ответа при незавершённом прогоне

- **WHEN** администратор загружает ответ, пока существует другой прогон с processedRows < totalRows
- **THEN** загрузка не отклоняется и создаёт ещё одну запись импорта
- **AND** оба прогона остаются в списке, у каждого своя строка и свои действия

#### Scenario: Файл не выбран

- **WHEN** администратор отправляет форму без выбранного файла
- **THEN** загрузка отклоняется с сообщением, что файл не выбран
- **AND** запись импорта не создаётся

#### Scenario: Замена файла своего прогона не отклоняется

- **WHEN** администратор заменяет файл прогона, у которого processedRows < totalRows
- **THEN** замена не отклоняется как незавершённый импорт
- **AND** отображается страница подтверждения замены

#### Scenario: Некорректный JSON

- **WHEN** администратор загружает файл, содержимое которого не является корректным JSON
- **THEN** загрузка отклоняется с указанием места ошибки
- **AND** запись импорта не создаётся

#### Scenario: Нарушение схемы указывает организацию и поле

- **WHEN** в ответе у пятой организации поле `name` пустое, а у седьмой поле `phone` длиннее 32 символов
- **THEN** отклонение перечисляет обе проблемы с номерами организаций и названиями полей
- **AND** номер организации соответствует её позиции в массиве organizations

#### Scenario: Пустой массив организаций

- **WHEN** администратор загружает ответ с пустым массивом organizations
- **THEN** загрузка отклоняется с сообщением об отсутствии данных

### Requirement: Формат источника определяет разбор прогона

The system SHALL record the format of a run's source when the run is created,
and SHALL use it to decide how the run's stored file is parsed when the run is
reopened. A run created from a CSV file SHALL be parsed as CSV, and a run
created from a JSON response SHALL be parsed as JSON, including when the run is
reached from the import list, where no tab is known. The format SHALL NOT be
rendered as a column of the import list, and SHALL be recorded once, at the
creation of the run.

#### Scenario: Прогон, созданный из JSON, разбирается как JSON

- **WHEN** администратор возвращается к проверке прогона, созданного из ответа JSON
- **THEN** сохранённый ответ разбирается как JSON независимо от того, откуда открыта страница

#### Scenario: Прогон, созданный из CSV, разбирается как CSV

- **WHEN** администратор возвращается к проверке прогона, созданного из CSV-файла
- **THEN** сохранённый файл разбирается как CSV

#### Scenario: Отчёт о замене читает файлы в формате прогона

- **WHEN** администратор заменяет файл прогона, созданного из JSON
- **THEN** и сохранённый ответ, и новый ответ читаются как JSON, а не как CSV

### Requirement: Список импортов
The system SHALL display a table of all import runs on the «Результаты» tab
(`/admin/import/results`), and no upload form there, with columns: «Файл»,
«Всего», «Обработано», «Загружен», «Импортирован» and an actions column.
«Загружен» SHALL show the date
the file was stored; «Импортирован» SHALL show when a row of this run was last
saved, and SHALL be empty while the run has no saved rows. The system SHALL
update «Импортирован» every time a row of the run is saved.

The actions column SHALL offer three actions:

- «Скачать» — download the run's current file. Available for every run.
- «Перезагрузить» — supply a replacement file, which builds the confirmation
  report before anything is written («Подтверждение замены файла импорта»).
  Available only while `processedRows < totalRows`.
- «Импортировать» — open the review package, which is where the file is parsed
  and validated 20 rows at a time. Available only while
  `processedRows < totalRows`.

A run is completed when `processedRows` is greater than or equal to `totalRows`,
not only when the two are equal; a completed run offers no «Перезагрузить» and
no «Импортировать». The table SHALL be ordered by upload date descending
(newest first).

#### Scenario: Список прогонов открыт на первой вкладке импорта
- **WHEN** администратор открывает страницу импорта
- **THEN** сначала отображается таблица прогонов — на вкладке «Результаты», которая и есть первая вкладка импорта
- **AND** форма загрузки CSV отображается на вкладке «CSV», следующей за ней

#### Scenario: Отображение списка импортов
- **WHEN** администратор открывает `/admin/import/results`
- **THEN** отображается таблица со всеми ранее загруженными файлами
- **AND** каждый ряд показывает имя файла, количество строк, обработанные строки, дату загрузки и дату последней импортированной строки

#### Scenario: Загруженный файл появляется в списке и импорт запускается отдельно
- **WHEN** администратор загружает CSV-файл
- **THEN** файл появляется в таблице как новый прогон, а администратор возвращается на вкладку «Результаты»
- **AND** разбор файла и проверка пакета по 20 строк не начинаются
- **AND** импорт начинается только после действия «Импортировать» в этой строке

#### Scenario: Дата последней импортированной строки
- **WHEN** у импорта с processedRows = 50 последняя строка сохранена 12 марта
- **THEN** в его строке таблицы отображается 12 марта в колонке «Импортирован»

#### Scenario: Дата последней импортированной строки ещё пуста
- **WHEN** файл загружен, но ни одна строка не импортирована (processedRows = 0)
- **THEN** колонка «Импортирован» в его строке таблицы пуста
- **AND** колонка «Загружен» показывает дату загрузки файла

#### Scenario: Кнопка «Импортировать» у незавершённого импорта
- **WHEN** в системе есть импорт с processedRows = 50 и totalRows = 400
- **THEN** в колонке действий отображается кнопка «Импортировать»
- **AND** нажатие открывает пакет для проверки, начинающийся со строки 51

#### Scenario: Кнопка «Импортировать» у только что загруженного файла
- **WHEN** в системе есть импорт с processedRows = 0
- **THEN** в колонке действий отображается кнопка «Импортировать»

#### Scenario: Кнопка «Скачать» есть у любого прогона
- **WHEN** в системе есть завершённый импорт
- **THEN** в его строке доступна кнопка «Скачать»

#### Scenario: Импортировать и перезагрузить недоступны для завершённого импорта
- **WHEN** в системе есть импорт с processedRows = totalRows = 400
- **THEN** в его строке нет кнопок «Импортировать» и «Перезагрузить»
- **AND** остаётся кнопка «Скачать»

#### Scenario: Импорт завершён, когда строк в файле стало меньше
- **WHEN** в системе есть импорт с processedRows = 50 и totalRows = 30
- **THEN** импорт считается завершённым
- **AND** в его строке нет кнопок «Импортировать» и «Перезагрузить»

#### Scenario: Скачивание файла прогона
- **WHEN** администратор нажимает «Скачать» в строке прогона
- **THEN** отдаётся текущий файл этого прогона под его именем

### Requirement: Парсинг CSV-строк
The system SHALL parse each data record into structured DTOs for
Organization, Contact array, and Call array according to the column
mapping and the date grammar declared in «Формат CSV-файла». The system
SHALL split the «Взаимодействия» column into entries at each date token;
each dated entry SHALL become a separate Call with `madeAt` set to the
parsed date at 12:00 and `notes` set to the remaining text; entries
without a recognized date SHALL become calls without `madeAt` and the raw
text as `notes`. The system SHALL parse the «Контакты» column
heuristically: extract phone numbers by regex (`+ digits, spaces, dashes,
parentheses`), email addresses by regex (`@` with domain), and remaining
text as contact name and position. Multiple contacts in one cell SHALL be
split when a new name-like pattern is detected. `Contact.name` is mandatory in
the database, so a fragment that yields no name-like text SHALL still be
imported as a contact whose name is the constant «Без имени»; the import SHALL
NOT drop such a fragment. Extracted contact values SHALL be truncated to the
column widths of `Contact`: name, email and position to 255 characters, phone
to 32. The system SHALL truncate
`Organization.name` and `Organization.coursesAttended` to 255 characters. The
system SHALL store the «Учились у нас» cell as free text on
`Organization.coursesAttended` without boolean coercion; an empty or
whitespace-only cell SHALL yield null.

#### Scenario: Парсинг одной строки с одной организацией
- **WHEN** CSV-строка содержит «Компания» = «Нафтан», «Взаимодействия» = «(25.08.2026) Пока потребности нет»
- **THEN** создаётся DTO организации с именем «Нафтан»
- **AND** создаётся один DTO звонка с датой 25.08.2026 и заметкой «Пока потребности нет»

#### Scenario: Парсинг строки с несколькими звонками
- **WHEN** CSV-строка содержит «Взаимодействия» = «(25.08.2026) Звонок 1\n(17.10.2025) Звонок 2»
- **THEN** создаются два DTO звонка: один с датой 25.08.2026 и заметкой «Звонок 1», другой с датой 17.10.2025 и заметкой «Звонок 2»

#### Scenario: Парсинг строки с несколькими контактами
- **WHEN** CSV-строка содержит «Контакты» = «Иван Петров, тел: +7-900-111-11-11, ivan@mail.ru; Мария Сидорова, тел: +7-900-222-22-22»
- **THEN** создаются два DTO контакта: «Иван Петров» с телефоном и email, и «Мария Сидорова» с телефоном

#### Scenario: Парсинг строки без контактов
- **WHEN** CSV-строка содержит пустую колонку «Контакты»
- **THEN** создаётся пустой массив DTO контактов

#### Scenario: Контакт без выделенного имени
- **WHEN** в колонке «Контакты» есть фрагмент, состоящий только из телефона «+7-900-111-11-11»
- **THEN** создаётся контакт с именем «Без имени» и телефоном «+7-900-111-11-11»
- **AND** фрагмент не отбрасывается

#### Scenario: Телефон приводится к каноническому виду
- **WHEN** из ячейки «Контакты» извлечён телефон «8017 2XX XXX-XX» (с кодом города после восьмёрки и нулём)
- **THEN** он сохраняется в виде «+375 17 XXX-XX-XX»

#### Scenario: Телефон без кода города
- **WHEN** из ячейки «Контакты» извлечён телефон «(29) XXX-XX-XX»
- **THEN** он сохраняется в виде «+375 29 XXX-XX-XX»

#### Scenario: Телефон с кодом оператора
- **WHEN** из ячейки «Контакты» извлечён телефон «+375 17 XXX-XX-XX»
- **THEN** он сохраняется без изменений

#### Scenario: Номер без кода и без восьмёрки
- **WHEN** из ячейки «Контакты» извлечён телефон «XXX-XX-XX»
- **THEN** он сохраняется в виде, в котором девять цифр не набирается, то есть без изменений

#### Scenario: Нераспознанная длина не выдумывается
- **WHEN** из ячейки «Контакты» извлечён телефон, у которого после нормализации остаётся не девять цифр
- **THEN** он сохраняется в исходном виде, без дописанных и отброшенных цифр

#### Scenario: Обрезка телефона контакта
- **WHEN** из ячейки «Контакты» извлекается значение длиннее 32 символов
- **THEN** значение phone обрезается до 32 символов

#### Scenario: Обрезка названия организации до 255 символов
- **WHEN** CSV-строка содержит «Компания» длиной 280 символов
- **THEN** значение Organization.name обрезается до 255 символов

#### Scenario: Обрезка «Учились у нас» до 255 символов
- **WHEN** CSV-строка содержит «Учились у нас» длиной 300 символов
- **THEN** значение Organization.coursesAttended обрезается до 255 символов

#### Scenario: Сохранение текста «Учились у нас» без приведения к булеву
- **WHEN** CSV-строка содержит «Учились у нас» = «Курс по переговорам»
- **THEN** Organization.coursesAttended сохраняет значение «Курс по переговорам»

#### Scenario: Пустое «Учились у нас»
- **WHEN** CSV-строка содержит пустую колонку «Учились у нас»
- **THEN** Organization.coursesAttended устанавливается в null

### Requirement: Отображение пакета для проверки
The system SHALL display the next up to 20 unprocessed rows from the import
file as a **table**, one organization per row. The chunk size SHALL define only
how many rows the user reviews at a time and SHALL NOT affect how rows are
persisted (each row is saved independently — «Утверждение пакета»). Each table
row SHALL show pre-parsed and editable values: organization name, description,
coursesAttended, website, contacts (name, phone, email, position) and
calls (date, notes). The table SHALL NOT show a list of
unrecognised date tokens. The user SHALL be able to edit any
field, and SHALL be able to add or remove contacts and add or remove calls. The
page SHALL display the current progress (`processedRows / totalRows`). The
system SHALL derive the reviewed chunk from the progress of the import itself:
it SHALL show rows `processedRows + 1` through
`min(processedRows + 20, totalRows)`. A chunk is therefore reached by its
address alone, reloading that address shows the same chunk, and the import does
not depend on server-side run state. The system SHALL NOT take the chunk
position from the request: a position supplied by the client SHALL NOT shift
the chunk.

The four columns «Отрасль», «Город», «УНП» and «Годовой план» SHALL be a
function of the run's recorded source format, and the system SHALL read them from
the form only for a run created from a JSON response:

| `run.sourceFormat` | «Отрасль» / «Город» / «УНП» / «Годовой план» |
| --- | --- |
| `csv` | not rendered, no inputs; the four columns stay null |
| `json` | rendered as editable inputs, pre-filled from the answer |

A CSV source declares no city, annual-plan or industry column, so there is
nothing to pre-fill and nothing to review: hiding them there keeps the parent's
rationale verbatim. A JSON answer carries all four, and a value the reviewer
cannot see is not review — so they are rendered and editable for that run. A CSV
package SHALL NOT acquire any of the four by submitting tampered form fields:
with no input of that name rendered, a value posted under it SHALL be ignored and
the column SHALL be saved as null.

The review page SHALL offer a «Назад к списку» action next to the «Импортировать»
button. It SHALL return the administrator to the import list and SHALL NOT change
the run in any way: the run stays unfinished, `processedRows` does not move, and
it does not block any other run (design D12). The action SHALL be a navigation
control rather than a form submission, so returning to the list cannot approve the
package.

The review page SHALL NOT offer a file replacement: replacing the run's file is
started from the actions column of the import list («Список импортов»), so the
package page holds only the package. The form SHALL be submitted by a button
labelled «Импортировать». A call SHALL carry a «Планируемый» mark when it comes
from the «Следующий контакт» column of the CSV source or from `nextCall` of a
JSON response: such a call has a scheduled date and no call date, and the mark is
what tells the two kinds of call apart in the table.

The approval form MAY be submitted more than once. On every submission the
system SHALL persist starting from `processedRows + 1` and SHALL ignore any
submitted row at or below `processedRows`, so a re-submitted form inserts
nothing a second time and skips no row of the source file.

The import MAY be interrupted at any point and continued from the last inserted
row, which is the purpose of the progress indicator.

Runs SHALL NOT influence each other on this page: opening the page of a run
SHALL show a package of that run, whether or not other runs are unfinished.

#### Scenario: Отображение первого пакета
- **WHEN** администратор нажимает «Импортировать» на импорте с 400 строками и processedRows = 0
- **THEN** отображается форма с 20 первыми строками, каждая с распарсенными и редактируемыми полями
- **AND** прогресс-индикатор показывает «0 / 400»
- **AND** форма отправляется кнопкой «Импортировать»

#### Scenario: Возврат к списку не меняет прогон
- **WHEN** администратор нажимает «Назад к списку» на странице пакета
- **THEN** открывается список прогонов
- **AND** `processedRows` прогона не изменился
- **AND** организация из пакета не создана

#### Scenario: Отметка «Планируемый» ставится по колонке «Следующий контакт»
- **WHEN** в колонке «Следующий контакт» указана дата 08.06.2026
- **THEN** соответствующий звонок в пакете отмечен «Планируемый»
- **AND** у него заполнено поле даты, а «Взаимодействия» дают звонки без этой отметки

#### Scenario: Отметка «Планируемый» ставится по nextCall ответа
- **WHEN** у организации в ответе JSON указано `nextCall` с датой 08.06.2026
- **THEN** соответствующий звонок в пакете отмечен «Планируемый»
- **AND** у него заполнено поле даты, а `calls` дают звонки без этой отметки

#### Scenario: Размер пакета не превышает 20 строк
- **WHEN** администратор продолжает импорт с processedRows = 0 и totalRows = 400
- **THEN** в форме отображается не более 20 строк

#### Scenario: Отображение последнего неполного пакета
- **WHEN** администратор продолжает импорт с processedRows = 395 и totalRows = 400
- **THEN** отображается форма с оставшимися 5 строками

#### Scenario: Пакет начинается со следующей необработанной строки
- **WHEN** администратор открывает страницу импорта с processedRows = 50 и totalRows = 400
- **THEN** отображается пакет со строк 51 по 75
- **AND** прогресс-индикатор показывает «50 / 400»

#### Scenario: Повторное открытие страницы импорта
- **WHEN** администратор открывает один и тот же адрес страницы импорта дважды, не утверждая пакет
- **THEN** оба раза отображается один и тот же пакет

#### Scenario: Повторная отправка формы не дублирует организации
- **WHEN** администратор утвердил пакет, и ту же форму отправляет повторно
- **THEN** повторная отправка не создаёт ни одной организации
- **AND** ни одна строка файла не пропускается: обработка начинается с processedRows + 1

#### Scenario: В прогоне из выгрузки нет города
- **WHEN** отображается пакет для проверки прогона, созданного из CSV-файла
- **THEN** в таблице нет поля «Город»
- **AND** при сохранении строки `Organization.city` остаётся равным null

#### Scenario: В пакете прогона из JSON поля ответа видны и правятся
- **WHEN** отображается пакет для проверки прогона, созданного из ответа JSON
- **THEN** в таблице есть поля «Город», «Отрасль», «УНП» и «Годовой план» со значениями ответа
- **AND** правленое значение сохраняется вместо значения ответа

#### Scenario: Пакет из выгрузки не принимает подставленные поля
- **WHEN** пакет прогона из CSV отправлен с подставленными значениями `city`, `industry`, `unp` и `annualPlan`
- **THEN** подставленные значения игнорируются, и все четыре поля сохраняются равными null

#### Scenario: Проверка чужого импорта при незавершённом
- **WHEN** администратор открывает страницу импорта, у которого processedRows = 0, пока другой импорт не завершён
- **THEN** отображается пакет именно этого импорта, без перенаправления

#### Scenario: Все строки обработаны
- **WHEN** processedRows >= totalRows
- **THEN** отображается flash-сообщение об итогах: сколько строк обработано в этом прогоне из скольких
- **AND** число относится только к этому прогону, а не к сумме по всем прогонам

### Requirement: Импорт пакета
The system SHALL process the rows of the current chunk when the user submits
the form by the «Импортировать» button, starting from `processedRows + 1` and ignoring any submitted
row at or below it, so that a re-submitted form saves nothing a second time and
skips no row of the source file. A chunk is only the number of rows the user
reviews at a time (up to 20) and SHALL NOT be a transaction boundary: each row
SHALL be saved in its own database transaction. For each row the system SHALL
create an Organization entity, associated Contact entities, and associated Call
entities, and SHALL increment `processedRows` by one. `processedRows` counts
rows that were saved; there is no separate counter for saved rows. The system
SHALL redirect back to the review page for the next chunk, or to the import
list when `processedRows` is greater than or equal to `totalRows`.

If a row fails to save, its transaction SHALL roll back (no partial data for
that row), rows already saved in the same chunk SHALL remain, processing
SHALL stop, and the system SHALL display the error message for the failed
row together with a notice that the import can be resumed from the last
processed row. The error message SHALL name the number of the row in the source
file and SHALL describe what is wrong with it, and SHALL state that the admin
can either correct the value in the table and approve again, or fix the source
file at that row and upload it again as a replacement, after which the import
continues from the same row. The error message SHALL NOT be
stored on the run. The package SHALL then be re-displayed starting from the row
that was not saved, with the values the user entered for the rows after it
preserved, so that processing resumes from the last inserted row without
re-entering them. The failed row SHALL be editable in place, and rows after it
SHALL NOT be saved while the stop stands.

A row with an empty organization name SHALL stop the import in the same way:
the row is not saved, `processedRows` is not advanced, and the row is displayed
for correction with an error state. There is no partial acceptance of the rest
of the chunk: the rows after a stopped row are not saved.

One row kind is exempt: a record that carries no organization data — no name and
no contacts. Such a record is not necessarily an empty line of the file: records
whose every field is empty are dropped by the reader before the package is
built, and a record that keeps, say, only «Актуальный курс» reaches the review
table nameless and is the case this rule is about. Such a row has nothing to
correct, and because a package always starts at `processedRows + 1`, leaving it
undecided would put it at the head of every package forever. The system SHALL
skip it, SHALL advance `processedRows` by one as if the row were decided, and
SHALL report the skipped row numbers in a notice of its own. A record with an
empty name but any contact is NOT empty and still stops the import for
correction.

A call does not lift a record out of this exemption. A call belongs to an
organization, so a call on a row that has none has nowhere to be stored, and the
«Актуальный курс» fragment in the description does not stand in for a name:
naming the row is what makes its data savable, and a row that cannot be named
cannot be saved at all. The real export contains such rows — an empty `Компания`,
an empty `Контакты` and one interaction entry — and stopping on each of them
would wedge the import on data that can never be corrected.

`processedRows` therefore counts decided rows — saved or skipped — and there is
no second counter.

A contact or a call of a saved row is a case of its own, and it is decided at
saving time rather than by stopping the import. The review table offers no
control for removing a nested contact or call — only fields — so clearing a
field is the only way an admin can express «this is not a contact» or «this is
not a call», and that must not leave the row unsavable. The system SHALL NOT
stop the import because of a nested contact or call, and SHALL NOT report a
validation error for one. A contact with no filled field at all — no name, no
phone, no email, no position, no notes — SHALL NOT be created; a checked «Основной» mark alone is a flag and not a value, so it does not make a contact savable either. A call with no parsed
date, no notes and no «Планируемый» mark SHALL NOT be created. A contact that
carries a phone, an email, a position or notes but no name SHALL be created
under the constant anonymous name «Без имени», because the admin cleared the
name and kept the values deliberately, and dropping it would discard data
silently. An empty organization name still stops the import as described above:
a row with no name is a different case and is not exempted by this rule. The
system SHALL NOT report which nested contacts and calls were dropped, because
dropping them is the admin's own edit at the review stage and the package shows
the fields that are filled.

Every contact in the review form SHALL offer a notes field and a «Основной»
checkbox, and the values the admin typed there SHALL be saved on the contact.
The «Планируемый» call, its notes and the contact notes come from the form
alone: the source format declares no column for them, so they arrive empty from
the file. A checked «Основной» mark SHALL be stored on that contact, and because
one organization has at most one main contact, the mark SHALL be cleared from
the organization's other contacts — the same rule
`ContactRepository::resetIsMainForOrganization()` applies to the contact
screen. When no contact is marked, `MailingService::effectiveMainContact()`
keeps falling back to the contact with the lowest ID.

#### Scenario: Пустой контакт не создаётся и не останавливает импорт
- **WHEN** администратор очистил все поля контакта в строке пакета и утвердил пакет
- **THEN** строка сохраняется вместе с организацией
- **AND** контакт не создаётся
- **AND** ошибка валидации не отображается и импорт не останавливается

#### Scenario: Контакт без имени, но со значениями сохраняется под «Без имени»
- **WHEN** администратор очистил имя контакта, оставив его телефон
- **THEN** строка сохраняется
- **AND** контакт создаётся с именем «Без имени» и с оставленным телефоном

#### Scenario: Звонок без даты и без заметок не создаётся
- **WHEN** администратор очистил дату и заметки звонка в строке пакета
- **THEN** строка сохраняется вместе с организацией
- **AND** звонок не создаётся
- **AND** остальные контакты и звонки этой строки создаются как обычно

#### Scenario: Заметка контакта сохраняется из формы
- **WHEN** администратор ввёл заметку в поле «Заметки» контакта
- **THEN** контакт сохраняется с этой заметкой

#### Scenario: Отмеченный контакт становится основным
- **WHEN** администратор отметил «Основной» у одного контакта строки
- **THEN** этот контакт сохраняется с отметкой основного
- **AND** у остальных контактов той же организации отметка снята

#### Scenario: Пустое название организации при пустых контактах всё равно останавливает импорт
- **WHEN** у строки пустое название, а её единственный контакт очищен администратором
- **THEN** строка не сохраняется и импорт останавливается на ней
- **AND** отображается ошибка о незаполненном названии организации

#### Scenario: Запись без данных пропускается с уведомлением
- **WHEN** в пакете есть строка, у которой пустое название, нет контактов и нет звонков
- **THEN** строка не создаёт организацию
- **AND** `processedRows` увеличивается на один, как если бы строка была сохранена
- **AND** система показывает отдельное уведомление с номерами пропущенных строк
- **AND** остальные строки пакета сохраняются как обычно

#### Scenario: Уведомление о пропуске не мешает остальным
- **WHEN** в пакете три строки без данных и пять заполненных
- **THEN** уведомление называет все три номера пропущенных строк
- **AND** пять заполненных строк сохраняются

#### Scenario: Пустое название при непустых контактах не пропускается
- **WHEN** у строки пустое название, но есть хотя бы один контакт
- **THEN** строка не пропускается
- **AND** импорт останавливается на ней для исправления названия

#### Scenario: Запись без названия, но со звонком, пропускается
- **WHEN** в пакете есть строка, у которой пустое название и нет контактов, но есть звонок
- **THEN** строка не создаёт организацию, а звонок ни к чему не прикрепляется
- **AND** `processedRows` увеличивается на один, как если бы строка была сохранена
- **AND** номер строки называется в уведомлении о пропуске

#### Scenario: Сохранение пакета из 20 строк
- **WHEN** администратор утверждает пакет из 20 строк
- **THEN** создаётся 20 организаций с контактами и звонками
- **AND** processedRows увеличивается на 20

#### Scenario: Ошибка при сохранении строки
- **WHEN** при сохранении одной из строк пакета происходит ошибка
- **THEN** эта строка не сохраняется
- **AND** строки пакета, сохранённые до неё, остаются в базе
- **AND** processedRows указывает на последнюю успешно обработанную строку
- **AND** отображается сообщение об ошибке, в котором назван номер строки в файле и суть проблемы
- **AND** отображается сообщение о возможности продолжить с последней обработанной позиции

#### Scenario: Пустое название останавливает пакет
- **WHEN** администратор утверждает пакет, в котором у 13-й строки пустое название
- **THEN** строки 1–12 сохраняются, 13-я и последующие не сохраняются
- **AND** processedRows остаётся равным 12
- **AND** пакет отображается снова с 13-й строки, с пустым названием, помеченным как ошибочное
- **AND** после исправления названия и повторного утверждения сохраняются 13-я строка и остаток пакета

#### Scenario: Исправление остановившейся строки на месте
- **WHEN** пакет отображается заново с остановившейся строки
- **THEN** значения, введённые администратором в этой и последующих строках, отображаются без изменений
- **AND** остановившаяся строка доступна для правки в той же таблице

#### Scenario: Исправление файла и повторная загрузка
- **WHEN** при сохранении строки 13 администратору сообщают номер строки в файле и суть проблемы
- **THEN** сообщение предлагает исправить значение в таблице либо исправить файл на этой строке и загрузить его заново
- **AND** после загрузки исправленного файла импорт продолжается со строки 13

#### Scenario: Завершение импорта
- **WHEN** processedRows становится больше или равным totalRows после сохранения пакета
- **THEN** отображается flash-сообщение: «Обработано строк в этом прогоне: X, обработано всего: Y», где X — processedRows текущего прогона, Y — сумма processedRows по всем прогонам
- **AND** строки, пропущенные как не содержащие данных, учтены в X и отдельно названы в своём уведомлении в момент пропуска
- **AND** в списке импортов действия недоступны

### Requirement: Обнаружение дубликатов названий
The system SHALL check Organization.name uniqueness when each row is
persisted (at insert time), against the database state at that moment —
including organizations inserted earlier in the same chunk or run. The check
SHALL be a name comparison against the database, so it follows the database's
own comparison semantics for text; no additional normalisation of the name is
applied beyond truncation to 255 characters. The
check SHALL run only when a row is saved; a package SHALL NOT be rejected for
a name that exists in the database.

When an organization name already exists, the system SHALL stop before that
row, keep the rows of the same chunk already saved, and re-display the package
starting from the conflicting row with the values the user entered preserved
and the conflict marked on that row. The row SHALL offer a choice: merge (add
contacts and calls to the existing organization) or create new. The merge
option SHALL append contacts and calls to the existing organization
without modifying its fields. The create-new option SHALL create a new
organization with the same name. When the choice is submitted, the conflicting
row SHALL be saved accordingly and the system SHALL continue persisting the
remaining rows of the same chunk. The user SHALL be able to decline both
options and change the name, so that a conflict is never a dead end; the import
is not abandoned for it.

#### Scenario: Обнаружен дубликат — выбор слияния
- **WHEN** при вставке строки организация «Нафтан» уже существует в системе
- **AND** администратор выбирает «Слить с существующей»
- **THEN** контакты и звонки из строки добавляются к существующей организации «Нафтан»

#### Scenario: Обнаружен дубликат — выбор создания нового
- **WHEN** при вставке строки организация «Нафтан» уже существует в системе
- **AND** администратор выбирает «Создать новую»
- **THEN** создаётся новая организация «Нафтан» (допускается дублирование имени)

#### Scenario: Дубликат внутри того же файла
- **WHEN** в файле две строки с названием «Нафтан»
- **AND** первая строка уже сохранена этим же импортом
- **THEN** при вставке второй строки система останавливается и помечает конфликт
- **AND** продолжение возможно с этой строки

#### Scenario: Сравнение дубликата средствами базы
- **WHEN** в базе есть организация «Нафтан», а в строке импорта указано «нафтан»
- **THEN** проверка дубликата опирается на сравнение средствами базы и учитывает её правила регистра

#### Scenario: Конфликт в середине пакета — пакет продолжается
- **WHEN** конфликт по названию возникает на 13-й строке пакета из 20 строк
- **THEN** отображается пакет, начинающийся с этой строки, с сохранёнными введёнными значениями
- **AND** после выбора администратора оставшиеся строки пакета сохраняются без повторного открытия пакета

#### Scenario: Конфликт — отказ от обоих вариантов
- **WHEN** администратор не выбирает ни слияние, ни создание новой организации
- **AND** меняет название так, чтобы оно больше не совпадало
- **THEN** строка сохраняется при следующем утверждении пакета

### Requirement: Обработка ошибочных данных
The system SHALL validate each row during chunk display (review time
only — this SHALL NOT pause persistence of the other values). A call that
carries no unambiguous date SHALL be shown with an empty date field and its
original text in the notes, and the user SHALL be able to correct the date
before approval. An empty organization name SHALL stop the approval of the
package at that row, with the name field marked as an error and the row
available for correction in place. Data that has no unambiguous reading — a
date that is not a date, a `Следующий контакт` value outside the date grammar —
SHALL NOT stop the import: it is displayed as given and the user MAY edit it in
place.

#### Scenario: Пустое название организации
- **WHEN** в строке колонка «Компания» пуста
- **THEN** поле названия организации подсвечивается как ошибочное
- **AND** утверждение пакета останавливается на этой строке до заполнения названия

#### Scenario: Звонок без даты в проверке пакета
- **WHEN** в колонке «Взаимодействия» встречается запись, у которой нет однозначной даты
- **THEN** запись отображается как звонок с пустым полем даты и исходным текстом в поле заметки
- **AND** пользователь может ввести дату вручную при проверке пакета
- **AND** при утверждении пакета отдельная остановка для этой записи не происходит

#### Scenario: «Следующий контакт» не является датой
- **WHEN** в колонке «Следующий контакт» указано значение, не читающееся как дата по базовой грамматике
- **THEN** значение отображается в проверке пакета как есть, с возможностью правки на месте
- **AND** утверждение пакета из-за него не останавливается

### Requirement: Подтверждение замены файла импорта
The system SHALL let the administrator supply a replacement file from the
actions column of the import list while `processedRows < totalRows`. In every case the system SHALL
NOT modify the run on submission. It SHALL validate the replacement against
the format declared for the import's own source — the format the run's
source is stored with — SHALL keep the run pointing at its current file, and
SHALL render a confirmation page carrying a report before anything is written.
The confirmation page SHALL address the run and the candidate file by URL
(`/admin/import/{id}/replace?candidate=<storageKey>`), so that the report is
reproducible from its address and no server-side state is required to confirm
it. A replacement that fails validation SHALL be rejected with the
corresponding report, the current file SHALL NOT be changed, and the
confirmation page SHALL NOT be shown.

The report SHALL contain the non-empty record count of the previous file and of
the new file, the resume row (`processedRows + 1`), the organization name at
that row in the new file, and the numbers of the rows within
`1..processedRows` whose content differs between the previous and the new file.
A row's content SHALL be compared as the format defines a row, so that a
difference in how the payload is written is not reported as a changed row. The
page SHALL offer a confirmation action and a cancel action.

The system SHALL rely only on the row numbers and the run's current file for
this decision. It SHALL NOT use `Organization.createdAt`, `Organization.updatedAt`
or any other database state of previously imported organizations.

The confirmation action SHALL point the run at the new file: `filename` and
`storageKey` SHALL be replaced with the new file's, the file the run pointed at
before SHALL be deleted, `totalRows` SHALL be set to the new file's non-empty
record count, and `processedRows` SHALL be preserved. Processing SHALL then
continue with the row next to the last processed one. The system SHALL show a
notification naming that row and the organization at it. A replacement file
whose record count is below `processedRows` SHALL be confirmed with a warning
instead of being rejected, and the import SHALL then be treated as completed.

The already-processed prefix SHALL stay frozen: row content that changed within
`processedRows` SHALL NOT be re-parsed, re-reviewed, or re-inserted. When
`processedRows` equals `totalRows`, the replacement form SHALL NOT be available.

#### Scenario: Замена запускается из списка
- **WHEN** администратор нажимает «Перезагрузить» в строке прогона с processedRows = 50 и totalRows = 400
- **AND** выбирает новый CSV-файл
- **THEN** система переходит к отчёту о замене до внесения изменений
- **AND** прогон остаётся без изменений

#### Scenario: На странице пакета замены файла нет
- **WHEN** администратор открывает страницу проверки пакета
- **THEN** на ней нет ни формы замены файла, ни ссылки на замену
- **AND** замена запускается только из списка прогонов

#### Scenario: Отчёт о замене до внесения изменений
- **WHEN** администратор загружает новый файл для импорта с processedRows = 50 и totalRows = 400
- **THEN** отображается страница подтверждения с отчётом
- **AND** отчёт содержит число строк до и после, строку продолжения 51 и название организации на ней
- **AND** в отчёте перечислены номера изменившихся строк из уже обработанных 1–50
- **AND** прогон импорта ещё не изменён

#### Scenario: Отмена замены
- **WHEN** администратор отменяет замену на странице подтверждения
- **THEN** текущий файл, processedRows и totalRows остаются прежними

#### Scenario: Подтверждение замены
- **WHEN** администратор подтверждает замену файла с 400 строк на файл с 402 строками при processedRows = 50
- **THEN** прогон переключается на новый файл: имя файла обновляется, прежний файл удаляется
- **AND** totalRows обновляется до 402
- **AND** processedRows остаётся равным 50
- **AND** отображается уведомление о том, что импорт продолжается со строки 51 и с названием организации на ней
- **AND** следующий пакет начинается со строки 51 и читается из нового файла

#### Scenario: Отмена замены не удаляет текущий файл
- **WHEN** администратор отменяет замену на странице подтверждения
- **THEN** текущий файл остаётся у прогона, а файл-кандидат удаляется как невостребованный
- **AND** processedRows и totalRows остаются прежними

#### Scenario: Изменение строк в уже обработанной части
- **WHEN** администратор заменяет файл, в котором изменены строки 1–50, а processedRows = 50
- **THEN** в отчёте указаны номера изменённых строк
- **AND** изменённые строки не переобрабатываются и не вставляются повторно
- **AND** обработка продолжается со строки 51

#### Scenario: Замена файла, нарушающего формат
- **WHEN** администратор загружает файл, не проходящий проверку объявленного формата
- **THEN** замена отклоняется с перечнем нарушений
- **AND** текущий файл не изменяется
- **AND** страница подтверждения не отображается

#### Scenario: Замена недоступна после завершения
- **WHEN** в импорте processedRows = totalRows
- **THEN** форма замены файла не отображается

### Requirement: Замена файла прогона, созданного из JSON

The system SHALL let the administrator replace the file of a run created from a
JSON response while `processedRows < totalRows`, supplying the replacement
either as pasted text or as an uploaded file, and SHALL treat both identically.
The replacement SHALL be validated against the published JSON Schema, and a
replacement that fails validation SHALL be rejected with the violation report,
the current file SHALL NOT be changed, and the confirmation page SHALL NOT be
shown.

Before anything is written, the system SHALL render a confirmation page with the
report: the number of organizations in the previous file and in the new one, the
resume row (`processedRows + 1`), the organization name at that row in the new
file, and the numbers of the organizations within `1..processedRows` that differ
between the two files. Two organizations differ when they differ in any field,
regardless of the order of keys and of how the payload is written.

The confirmation page SHALL address the run and the candidate file by URL
(`/admin/import/{id}/replace?candidate=<storageKey>`), so that the report is
reproducible from its address and no server-side state is required to confirm it.

On confirmation the system SHALL point the run at the new file: `filename` and
`storageKey` SHALL be replaced with the new file's, the file the run pointed at
before SHALL be deleted, `totalRows` SHALL be set to the new organization count,
and `processedRows` SHALL be preserved. A replacement whose organization count is
below `processedRows` SHALL be confirmed with a warning instead of being
rejected, and the import SHALL then be treated as completed.

The page SHALL offer a confirmation action and a cancel action, and SHALL NOT
report any recency or timestamp information. The already-processed prefix SHALL
stay frozen: organization content that changed within `processedRows` SHALL NOT
be re-parsed, re-reviewed or re-inserted. When `processedRows` equals
`totalRows`, the replacement form SHALL NOT be available.

#### Scenario: Замена файла на вкладке JSON

- **WHEN** администратор заменяет файл прогона из JSON, у которого processedRows = 50 и totalRows = 120, на ответ со 118 организациями
- **THEN** отображается страница подтверждения с числом организаций до и после, строкой продолжения 51 и названием организации на ней
- **AND** в отчёте перечислены номера изменившихся организаций из уже обработанных 1–50
- **AND** сессия импорта ещё не изменена
- **AND** адрес страницы содержит прогон и файл-кандидат

#### Scenario: Замена файла вкладки JSON вставкой текста

- **WHEN** администратор заменяет файл прогона из JSON вставкой нового ответа вместо выбора файла
- **THEN** отчёт и подтверждение работают так же, как при загрузке файла

#### Scenario: Порядок ключей не считается изменением

- **WHEN** новая версия ответа отличается от сохранённой только порядком ключей и записью payload
- **THEN** в отчёте не указаны изменившиеся организации

#### Scenario: Замена файла, нарушающего формат

- **WHEN** администратор загружает на вкладку JSON файл, не проходящий проверку схемы
- **THEN** замена отклоняется с перечнем нарушений
- **AND** текущий файл не изменяется
- **AND** страница подтверждения не отображается

#### Scenario: Подтверждение замены удаляет прежний файл

- **WHEN** администратор подтверждает замену файла прогона из JSON
- **THEN** прогон переключается на новый файл, прежний файл удаляется
- **AND** totalRows обновляется до нового числа организаций, а processedRows сохраняется

#### Scenario: Ответ короче уже обработанной части подтверждается с предупреждением

- **WHEN** администратор подтверждает замену прогна с processedRows = 50 на ответ со 30 организациями
- **THEN** замена подтверждается с предупреждением, а не отклоняется
- **AND** импорт считается завершённым

#### Scenario: Отмена замены файла прогона из JSON

- **WHEN** администратор отменяет замену на странице подтверждения
- **THEN** текущий файл, processedRows и totalRows остаются прежними

### Requirement: Данные импорта привязаны к администратору
The system SHALL record the admin user who initiated each import
(`createdBy`). Imported organizations SHALL NOT be assigned to any
group. Imported organizations SHALL have `created_by` set to the
admin who ran the import. Fields the source format does not carry SHALL keep
their defaults: an imported organization SHALL be active (`isActive = true`),
SHALL NOT be opted out (`isOptedOut = false`), and `unp` and `industry` SHALL
remain null. Imported calls that record a made call SHALL have `madeBy` set to
the admin who ran the import; a planned call SHALL NOT have `madeBy` set,
since nobody made it. Imported calls SHALL have `madeAt` set
to the parsed date from the CSV (with time 12:00, in the database time zone)
and SHALL NOT have result flags (isDeal, isRefusal, isNoAnswer) set.

#### Scenario: Импортированные организации без групп
- **WHEN** администратор завершает импорт организации «Нафтан»
- **THEN** организация «Нафтан» не принадлежит ни одной группе

#### Scenario: Импортированные организации с создателем
- **WHEN** администратор «admin» завершает импорт организации «Нафтан»
- **THEN** created_by организации «Нафтан» равен пользователю «admin»
- **AND** организация активна, не отписана, а unp и industry равны null

#### Scenario: Импортированные звонки с автором
- **WHEN** администратор «admin» завершает импорт звонка по организации «Нафтан» от 25.08.2026
- **THEN** у звонка madeBy = «admin» и madeAt = 25.08.2026 12:00:00

#### Scenario: Плановый звонок без автора
- **WHEN** импортирован плановый звонок из колонки «Следующий контакт»
- **THEN** у звонка madeAt = null, scheduledAt задан, а madeBy = null

#### Scenario: Импортированные контакты без основного контакта
- **WHEN** администратор импортирует организацию с тремя контактами
- **THEN** у всех трёх контактов is_main = false
- **AND** система не назначает основной контакт автоматически
- **AND** `MailingService::effectiveMainContact()` выбирает контакт с минимальным ID
