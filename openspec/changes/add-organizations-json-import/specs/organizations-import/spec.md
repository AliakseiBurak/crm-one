# Organizations Import (delta)

## ADDED Requirements

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

## MODIFIED Requirements

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
