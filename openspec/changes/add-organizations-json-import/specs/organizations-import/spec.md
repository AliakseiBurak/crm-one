# Organizations Import (delta)

## ADDED Requirements

### Requirement: Вкладки импорта CSV и JSON

The import page SHALL offer two tabs: «CSV» and «JSON». The CSV tab SHALL hold
the upload form, the import list and the package review. The JSON tab SHALL show
a ready-to-copy prompt, a link to download the JSON schema, a field for pasting
a response, and a file field for uploading a response. The JSON tab SHALL accept
a response either way. Both tabs SHALL feed the same import run, the same review
packages of at most 20 organizations, the same per-row transaction, the same
duplicate choice and the same completion notice. The only difference SHALL be
how a row is obtained: the CSV tab parses cells, the JSON tab receives
already-structured objects.

#### Scenario: Две вкладки на странице импорта

- **WHEN** администратор открывает страницу импорта
- **THEN** отображаются две вкладки: «CSV» и «JSON»
- **AND** активна вкладка «CSV»

#### Scenario: Переключение на вкладку JSON

- **WHEN** администратор открывает вкладку «JSON»
- **THEN** отображаются готовый к копированию промпт, ссылка «Скачать JSON-схему», поле для вставки ответа и поле для выбора файла
- **AND** переход между вкладками является переходом по адресу, а не переключением видимости на месте

#### Scenario: Менеджер не видит вкладки импорта

- **WHEN** аутентифицированный менеджер открывает страницу импорта
- **THEN** система отклоняет запрос с ошибкой 403

#### Scenario: Пакеты на обеих вкладках одинаковой величины

- **WHEN** администратор проверяет пакет на вкладке «JSON»
- **THEN** отображается не более 20 организаций одной таблицей с теми же полями, что и на вкладке «CSV», кроме отраслей и города, объявленных ниже

#### Scenario: Адреса вкладок не перехватываются адресом прогона

- **WHEN** администратор открывает `/admin/import/json`, `/admin/import/json-schema` или `/admin/import/llm`
- **THEN** открывается соответствующая страница, а не прогон импорта
- **AND** ни один из этих адресов не трактуется как идентификатор прогона

### Requirement: Формат JSON-данных

The JSON tab SHALL accept a response as pasted text or as an uploaded file, and
a file offered through the file field SHALL be treated exactly as pasted text.
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
| `contacts` | array | no | `Contact` entities; each with `name` (required), `position`, `phone` (max 32), `email` |
| `calls` | array | no | made `Call` entities; each with `date` and `notes` |
| `nextContact` | object | no | planned `Call`; with `date` and `purpose` |

The format SHALL NOT contain a `unp` field: a model invents those numbers and a
wrong one is worse than a missing one. The format SHALL NOT contain an
`is_main` flag: contacts are created with it unset, and
`MailingService::effectiveMainContact()` falls back to the contact with the
lowest ID. The format SHALL NOT contain `annualPlan`.

A `calls[].date` and a `nextContact.date` value SHALL match the date shapes of
«Формат CSV-файла» — `D.M.YYYY`, `DD.MM.YYYY`, `DD.MM.YY` read as `20YY`,
`DD/MM/YYYY`, `DD,MM.YYYY`, with an optional trailing `_` or `.` — but SHALL be
written **without** the parentheses that delimit a CSV entry group and SHALL NOT
be surrounded by entry text. The format SHALL declare this as a `pattern`. The
system SHALL NOT require or impose ISO 8601.

A missing or empty optional field SHALL be treated as absent and SHALL NOT be
stored as an empty string. An empty `contacts` or `calls` array SHALL mean no
contacts and no calls for that organization.

#### Scenario: Файл с ответом обрабатывается как вставка

- **WHEN** администратор выбирает файл с ответом вместо вставки текста
- **THEN** содержимое файла проверяется по той же схеме, что и вставленный текст
- **AND** отчёт о нарушениях при его наличии показывается так же

#### Scenario: Скачивание JSON-схемы

- **WHEN** администратор нажимает «Скачать JSON-схему» на вкладке «JSON»
- **THEN** браузер получает файл JSON Schema как вложение
- **AND** файл описывает те же поля, что перечислены в формате JSON-данных

#### Scenario: Промпт согласован со схемой

- **WHEN** администратор открывает вкладку «JSON»
- **THEN** описание каждого поля в промпте совпадает с описанием того же поля в скачиваемой схеме

#### Scenario: Поле УНП не запрашивается

- **WHEN** администратор просматривает промпт или схему
- **THEN** в них нет поля `unp`

#### Scenario: Поле основного контакта не запрашивается

- **WHEN** администратор просматривает промпт или схему
- **THEN** в них нет поля `is_main`

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
`pattern` on `calls[].date` and `nextContact.date`, a `maxLength` on `name`,
`industry`, `city`, `website`, `coursesAttended` and `contacts[].phone`, and
`required` on `name` and `contacts[].name`.

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

#### Scenario: Схема не содержит непроверяемых ключевых слов

- **WHEN** администратор скачивает JSON-схему
- **THEN** в ней нет ключевых слов `format`
- **AND** все объявленные ограничения проверяются валидатором

#### Scenario: Промпт сообщает ограничения до проверки

- **WHEN** администратор открывает промпт на вкладке «JSON»
- **THEN** в нём названы допустимые форматы даты, максимальные длины полей и обязательные поля
- **AND** эти ограничения совпадают с объявленными в скачиваемой схеме

### Requirement: Загрузка JSON-ответа

On submission, the system SHALL reject the request while any *other* import run
has `processedRows < totalRows`, exactly as for a CSV upload. Replacing the file
of the run being viewed is not a submission under this requirement and SHALL NOT
be rejected by this check. Otherwise the system SHALL parse the payload,
validate it against the published JSON Schema, and SHALL report every violation
it finds, naming the organization by its position in the `organizations` array
and the offending field. A payload with at least one organization SHALL be stored
as the run's file, SHALL create an import run with `totalRows` equal to the
length of the `organizations` array and `processedRows` = 0, and SHALL return the
administrator to the import list, where the new run appears as a row of its own.
`totalRows` counts organizations, so the review package, the progress indicator
and the completion notice behave identically on both tabs.

Submitting a response SHALL NOT start the import: parsing the payload into
organization, contact and call DTOs and building a review package SHALL NOT begin
on submission. They SHALL begin when the administrator chooses «Импортировать» in
the run's row, exactly as on the CSV tab. A run created from JSON and not yet
imported is an unfinished run and blocks further submissions, like any other.

The run's `filename` SHALL be the uploaded file's original name, or `ответ.json`
when the response was pasted.

A payload that is not valid JSON, or that violates the schema, SHALL NOT create
an import run and SHALL NOT be stored.

#### Scenario: Успешная вставка ответа

- **WHEN** администратор вставляет ответ со 120 организациями
- **THEN** создаётся запись импорта с totalRows = 120 и processedRows = 0
- **AND** администратор возвращается к списку импортов, где прогон виден отдельной строкой

#### Scenario: Успешная загрузка файла с ответом

- **WHEN** администратор выбирает файл с ответом на 40 организациях вместо вставки текста
- **THEN** создаётся запись импорта с totalRows = 40 и processedRows = 0
- **AND** администратор возвращается к списку импортов, где прогон виден отдельной строкой

#### Scenario: Разбор ответа начинается по отдельному действию

- **WHEN** ответ только что вставлен и ещё не импортирован
- **THEN** пакет для проверки не сформирован
- **AND** разбор ответа в DTO не выполняется
- **AND** пакет появляется только после нажатия «Импортировать»

#### Scenario: Вставка при наличии активного прогона импорта

- **WHEN** администратор вставляет ответ, пока существует другой прогон с processedRows < totalRows
- **THEN** вставка отклоняется с сообщением о незавершённом импорте

#### Scenario: Ответ без файла получает имя прогона

- **WHEN** администратор вставляет ответ текстом, а не файлом
- **THEN** в строке прогона в списке импортов в колонке «Файл» указано `ответ.json`

#### Scenario: Замена файла своего прогона не отклоняется

- **WHEN** администратор заменяет файл прогона, у которого processedRows < totalRows
- **THEN** замена не отклоняется как незавершённый импорт
- **AND** отображается страница подтверждения замены

#### Scenario: Некорректный JSON

- **WHEN** администратор вставляет текст, который не является корректным JSON
- **THEN** вставка отклоняется с указанием места ошибки
- **AND** запись импорта не создаётся

#### Scenario: Нарушение схемы указывает организацию и поле

- **WHEN** в ответе у пятой организации поле `name` пустое, а у седьмой поле `phone` длиннее 32 символов
- **THEN** отклонение перечисляет обе проблемы с номерами организаций и названиями полей
- **AND** номер организации соответствует её позиции в массиве organizations

#### Scenario: Пустой массив организаций

- **WHEN** администратор вставляет ответ с пустым массивом organizations
- **THEN** вставка отклоняется с сообщением об отсутствии данных

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

### Requirement: Отображение пакета для проверки

The system SHALL display the next up to 20 unprocessed rows from the import
file as a **table**, one organization per row. The chunk size SHALL define only
how many rows the user reviews at a time and SHALL NOT affect how rows are
persisted (each row is saved independently — «Утверждение пакета»). Each table
row SHALL show pre-parsed and editable values: organization name, description,
coursesAttended, website, contacts (name, phone, email, position) and
calls (date, notes). The table SHALL NOT show an annual-plan field and SHALL NOT
show a list of unrecognised date tokens.

Which of `industry` and `city` the table shows SHALL depend on the format of the
run's source. For a run created from a CSV file the table SHALL NOT show an
industry or a city field, because the declared source format carries neither, so
there is nothing to pre-fill and nothing to review, and `Organization.city` SHALL
remain null for every row of that run. For a run created from a JSON response the
table SHALL show both fields pre-filled from the response and SHALL accept edits
to them, and the saved row SHALL carry them into `Organization.industry` and
`Organization.city`.

The user SHALL be able to edit any field, and SHALL be able to add or remove
contacts and add or remove calls. The page SHALL display the current progress
(`processedRows / totalRows`). The system SHALL derive the reviewed chunk from
the progress of the import itself: it SHALL show rows `processedRows + 1` through
`min(processedRows + 20, totalRows)`. A chunk is therefore reached by its
address alone, reloading that address shows the same chunk, and the import does
not depend on server-side run state. The system SHALL NOT take the chunk
position from the request: a position supplied by the client SHALL NOT shift the
chunk.

The review page SHALL offer a «Назад к списку» action next to the «Импортировать»
button. It SHALL return the administrator to the import list and SHALL NOT change
the run in any way: the run stays unfinished, `processedRows` does not move, and
it keeps blocking further uploads (design D9). The action SHALL be a navigation
control rather than a form submission, so returning to the list cannot approve the
package.

The review page SHALL NOT offer a file replacement: replacing the run's file is
started from the actions column of the import list («Список импортов»), so the
package page holds only the package. The form SHALL be submitted by a button
labelled «Импортировать». A call SHALL carry a «Планируемый» mark when it comes
from the «Следующий контакт» column of the CSV source or from `nextContact` of a
JSON response: such a call has a scheduled date and no call date, and the mark is
what tells the two kinds of call apart in the table.

The approval form MAY be submitted more than once. On every submission the
system SHALL persist starting from `processedRows + 1` and SHALL ignore any
submitted row at or below `processedRows`, so a re-submitted form inserts
nothing a second time and skips no row of the source file.

The import MAY be interrupted at any point and continued from the last inserted
row, which is the purpose of the progress indicator.

When the page of a run is opened while a *different* `ImportRun` has
`processedRows < totalRows`, the system SHALL redirect to the page of that
unfinished import — the most recently created one — instead of showing a
package of the other run.

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

#### Scenario: Отметка «Планируемый» ставится по nextContact ответа
- **WHEN** у организации в ответе JSON указано `nextContact` с датой 08.06.2026
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

#### Scenario: Проверка чужого импорта при незавершённом
- **WHEN** администратор открывает страницу импорта, у которого processedRows = 0, пока другой импорт не завершён
- **THEN** система перенаправляет на страницу незавершённого импорта

#### Scenario: В прогоне нет города
- **WHEN** отображается пакет для проверки прогона, созданного из CSV-файла
- **THEN** в таблице нет полей «Город» и «Отрасль»
- **AND** при сохранении строки `Organization.city` остаётся равным null

#### Scenario: В прогоне из JSON отрасль и город видны и правятся
- **WHEN** отображается пакет для проверки прогона, созданного из ответа JSON, где у организации указаны `industry` и `city`
- **THEN** в таблице есть поля «Отрасль» и «Город» с значениями из ответа
- **AND** администратор может изменить их в форме пакета

#### Scenario: Отредактированные отрасль и город сохраняются
- **WHEN** администратор исправляет «Отрасль» и «Город» в пакете прогона из JSON и утверждает пакет
- **THEN** организация сохраняется с исправленными `industry` и `city`

#### Scenario: CSV-прогон не получает город из формы
- **WHEN** прогон создан из CSV-файла и его форма пакета отправлена с подставленными значениями отрасли и города
- **THEN** `Organization.industry` и `Organization.city` остаются равными null

#### Scenario: Все строки обработаны
- **WHEN** processedRows >= totalRows
- **THEN** отображается flash-сообщение об итогах: сколько строк импортировано в этом прогоне и сколько всего по всем прогонам
