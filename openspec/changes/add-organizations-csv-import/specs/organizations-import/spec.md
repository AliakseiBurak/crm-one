# Organizations Import

Импорт организаций из CSV-файла: загрузка, проверка структуры, построчная
интерактивная проверка и редактирование с пакетным сохранением.

## Purpose

Позволяет администратору загружать CSV-файл с организациями, проверять
заголовки и содержимое, интерактивно редактировать и утверждать данные
перед сохранением в систему.

## ADDED Requirements

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

### Requirement: Загрузка CSV-файла

The system SHALL provide an upload form that accepts a single CSV file.
On upload, the system SHALL reject the request when any existing
`ImportRun` has `processedRows < totalRows` (at most one active
import run). Otherwise, the system SHALL validate the file against
the declared format («Формат CSV-файла»): the header row SHALL contain
the expected columns, and the file SHALL contain at least one non-empty
data record. The system SHALL reject files with missing or unexpected
non-empty columns and SHALL display the list of expected headers. The
system SHALL store the uploaded file and create an `ImportRun`
record with `totalRows` equal to the number of non-empty data records
(excluding the header) and `processedRows` = 0.

#### Scenario: Успешная загрузка CSV-файла

- **WHEN** администратор загружает CSV-файл с правильными заголовками и 400 строками данных
- **THEN** файл сохраняется на сервере
- **AND** создаётся запись импорта с totalRows = 400 и processedRows = 0

#### Scenario: Загрузка при наличии активного прогона импорта

- **WHEN** администратор загружает CSV-файл, пока существует импорт с processedRows < totalRows
- **THEN** загрузка отклоняется с сообщением о незавершённом импорте

#### Scenario: Загрузка файла с неправильными заголовками

- **WHEN** администратор загружает CSV-файл, в котором отсутствует колонка «Взаимодействия»
- **THEN** загрузка отклоняется
- **AND** отображается сообщение со списком ожидаемых заголовков

#### Scenario: Загрузка пустого файла

- **WHEN** администратор загружает CSV-файл без строк данных (только заголовки)
- **THEN** загрузка отклоняется с сообщением об отсутствии данных

#### Scenario: Повторная загрузка того же файла

- **WHEN** администратор загружает файл с именем, идентичным ранее загруженному
- **AND** незавершённых импортов нет
- **THEN** создаётся новая запись импорта (допускаются дубли файлов)

### Requirement: Список импортов

The system SHALL display a table of all import runs on the
`/admin/import` page with columns: filename, total rows, processed rows,
creation date, last processed date, and an action column. The last processed
date SHALL show when a row of this run was last saved, and be empty while the
run has no saved rows. The system SHALL update it every time a row of the run is
saved. The action column SHALL show a
«Начать» link when `processedRows = 0`, a «Продолжить» link when
`0 < processedRows < totalRows`, and nothing when `processedRows >= totalRows`.
A run is completed when `processedRows` is greater than or equal to
`totalRows`, not only when the two are equal. The table SHALL be ordered by
creation date descending (newest first).

#### Scenario: Отображение списка импортов

- **WHEN** администратор открывает `/admin/import`
- **THEN** отображается таблица со всеми ранее созданными импортами
- **AND** каждый ряд показывает имя файла, количество строк, обработанные строки, дату создания и дату последней обработанной строки

#### Scenario: Дата последней обработанной строки

- **WHEN** у импорта с processedRows = 50 последняя строка сохранена 12 марта
- **THEN** в его строке таблицы отображается 12 марта как дата последней обработанной строки

#### Scenario: Дата последней обработанной строки ещё пуста

- **WHEN** импорт создан, но ни одна строка не сохранена (processedRows = 0)
- **THEN** дата последней обработанной строки в его строке таблицы пуста

#### Scenario: Кнопка «Начать» для нового импорта

- **WHEN** в системе есть импорт с processedRows = 0
- **THEN** в колонке действий отображается ссылка «Начать»

#### Scenario: Кнопка «Продолжить» для незавершённого импорта

- **WHEN** в системе есть импорт с processedRows = 50 и totalRows = 400
- **THEN** в колонке действий отображается ссылка «Продолжить»

#### Scenario: Действий нет для завершённого импорта

- **WHEN** в системе есть импорт с processedRows = totalRows = 400
- **THEN** в колонке действий ничего не отображается

#### Scenario: Импорт завершён, когда строк в файле стало меньше

- **WHEN** в системе есть импорт с processedRows = 50 и totalRows = 30
- **THEN** импорт считается завершённым
- **AND** в колонке действий ничего не отображается

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

The system SHALL display the next up to 25 unprocessed rows from the import
file as a **table**, one organization per row. The chunk size SHALL define only
how many rows the user reviews at a time and SHALL NOT affect how rows are
persisted (each row is saved independently — «Утверждение пакета»). Each table
row SHALL show pre-parsed and editable values: organization name, description,
coursesAttended, website, contacts (name, phone, email, position) and
calls (date, notes). The table SHALL NOT show an annual-plan field, SHALL NOT
show a city field (the source format declares no city column, so there is
nothing to pre-fill and nothing to review) and SHALL NOT show a list of
unrecognised date tokens. The user SHALL be able to edit any
field, and SHALL be able to add or remove contacts and add or remove calls. The
page SHALL display the current progress (`processedRows / totalRows`). The
system SHALL derive the reviewed chunk from the progress of the import itself:
it SHALL show rows `processedRows + 1` through
`min(processedRows + 25, totalRows)`. A chunk is therefore reached by its
address alone, reloading that address shows the same chunk, and the import does
not depend on server-side run state. The system SHALL NOT take the chunk
position from the request: a position supplied by the client SHALL NOT shift
the chunk.

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

- **WHEN** администратор нажимает «Начать» на импорте с 400 строками и processedRows = 0
- **THEN** отображается форма с 25 первыми строками, каждая с распарсенными и редактируемыми полями
- **AND** прогресс-индикатор показывает «0 / 400»

#### Scenario: Размер пакета не превышает 25 строк

- **WHEN** администратор продолжает импорт с processedRows = 0 и totalRows = 400
- **THEN** в форме отображается не более 25 строк

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

#### Scenario: В прогоне нет города

- **WHEN** отображается пакет для проверки
- **THEN** в таблице нет поля «Город»
- **AND** при сохранении строки `Organization.city` остаётся равным null

#### Scenario: Проверка чужого импорта при незавершённом

- **WHEN** администратор открывает страницу импорта, у которого processedRows = 0, пока другой импорт не завершён
- **THEN** система перенаправляет на страницу незавершённого импорта

#### Scenario: Все строки обработаны

- **WHEN** processedRows >= totalRows
- **THEN** отображается flash-сообщение об итогах: сколько строк импортировано в этом прогоне и сколько всего по всем прогонам

### Requirement: Утверждение пакета

The system SHALL process the rows of the current chunk when the user submits
the approval form, starting from `processedRows + 1` and ignoring any submitted
row at or below it, so that a re-submitted form saves nothing a second time and
skips no row of the source file. A chunk is only the number of rows the user
reviews at a time (up to 25) and SHALL NOT be a transaction boundary: each row
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

#### Scenario: Сохранение пакета из 25 строк

- **WHEN** администратор утверждает пакет из 25 строк
- **THEN** создаётся 25 организаций с контактами и звонками
- **AND** processedRows увеличивается на 25

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
- **THEN** отображается flash-сообщение: «Импортировано в этом прогоне: X, импортировано всего: Y», где X — processedRows текущего прогона, Y — сумма processedRows по всем прогонам
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

- **WHEN** конфликт по названию возникает на 13-й строке пакета из 25 строк
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

The system SHALL let the administrator supply a replacement file on the import
review page while `processedRows < totalRows`. In every case the system SHALL
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
