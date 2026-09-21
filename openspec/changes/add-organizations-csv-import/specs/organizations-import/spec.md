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
| `Актуальный курс` | free text | `Organization.description` |
| `Взаимодействия` | multiline interaction log, entries separated by date tokens | one `Call` per dated entry |
| `Контакты` | free-form multiline contact data: names, positions, phones, emails, notes | one or more `Contact` entities |
| `Следующий контакт` | a date or a placeholder (`-`, `_`) | not imported |
| `Для чего звонок?` | free text: purpose of the next contact | not imported |
| `Текущее состояние` | free text log | not imported |
| `Учились у нас` | free text about previous training | `Organization.hasUsedServices` |
| `Составление плана на год` | free text | `Organization.annualPlan` (truncated to 255 characters) |

A `Взаимодействия` entry SHALL begin with a date token in one of the forms
`D.M.YYYY`, `DD.MM.YYYY`, `DD.MM.YY` or `DD/MM/YYYY` (separators `.` or
`/`, a two-digit year read as `20YY`) enclosed in parentheses. The text
before the first date token SHALL be treated as an entry without a date;
the text after a date token, up to the next date token, SHALL be the notes
of that entry. Text that does not match a date token SHALL NOT be
discarded.

#### Scenario: Многострочные ячейки и кавычки
- **WHEN** администратор загружает файл, в котором ячейки содержат переводы строк, запятые и удвоенные кавычки
- **THEN** каждая запись разбирается на объявленные колонки без потери содержимого

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

#### Scenario: Текст без даты во взаимодействиях
- **WHEN** во «Взаимодействиях» текст перед первой датой не начинается с даты
- **THEN** создаётся звонок без даты с этим текстом в заметке
- **AND** запись остаётся доступной для исправления при проверке

#### Scenario: Плейсхолдер в «Следующий контакт»
- **WHEN** в колонке «Следующий контакт» указано «-» или «_»
- **THEN** значение не импортируется и не порождает звонок

### Requirement: Загрузка CSV-файла

The system SHALL provide an upload form that accepts a single CSV file.
On upload, the system SHALL validate the file against the declared format
(«Формат CSV-файла»): the header row SHALL contain the expected columns,
and the file SHALL contain at least one non-empty data record. The system
SHALL reject files with missing or unexpected non-empty columns and SHALL
display the list of expected headers. The system SHALL store the uploaded
file and create an `ImportSession` record with `totalRows` equal to the
number of non-empty data records (excluding the header) and
`processedRows` = 0.

#### Scenario: Успешная загрузка CSV-файла

- **WHEN** администратор загружает CSV-файл с правильными заголовками и 400 строками данных
- **THEN** файл сохраняется на сервере
- **AND** создаётся запись импорта с totalRows = 400 и processedRows = 0

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

The system SHALL display a table of all import sessions on the
`/admin/import` page with columns: filename, total rows, processed rows,
creation date, and an action column. The action column SHALL show a
«Начать» link when `processedRows = 0`, a «Продолжить» link when
`0 < processedRows < totalRows`, and nothing when `processedRows` equals
`totalRows`. The table SHALL be ordered by creation date descending
(newest first).

#### Scenario: Отображение списка импортов

- **WHEN** администратор открывает `/admin/import`
- **THEN** отображается таблица со всеми ранее созданными импортами
- **AND** каждый ряд показывает имя файла, количество строк, обработанные строки и дату

#### Scenario: Кнопка «Начать» для нового импорта

- **WHEN** в системе есть импорт с processedRows = 0
- **THEN** в колонке действий отображается ссылка «Начать»

#### Scenario: Кнопка «Продолжить» для незавершённого импорта

- **WHEN** в системе есть импорт с processedRows = 50 и totalRows = 400
- **THEN** в колонке действий отображается ссылка «Продолжить»

#### Scenario: Действий нет для завершённого импорта

- **WHEN** в системе есть импорт с processedRows = totalRows = 400
- **THEN** в колонке действий ничего не отображается

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
split when a new name-like pattern is detected. The system SHALL truncate
`Organization.name` and `Organization.annualPlan` to 255 characters and
SHALL treat `hasUsedServices` as true only for the truthy values
да/yes/1/true (case-insensitive); any other text SHALL produce false.

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

#### Scenario: Обрезка годового плана до 255 символов

- **WHEN** CSV-строка содержит «Составление плана на год» длиной 300 символов
- **THEN** значение Organization.annualPlan обрезается до 255 символов

#### Scenario: Обрезка названия организации до 255 символов

- **WHEN** CSV-строка содержит «Компания» длиной 280 символов
- **THEN** значение Organization.name обрезается до 255 символов

#### Scenario: Преобразование «Учились у нас» в булево

- **WHEN** CSV-строка содержит «Учились у нас» = «Да»
- **THEN** Organization.hasUsedServices устанавливается в true

- **WHEN** CSV-строка содержит «Учились у нас» = «Нет»
- **THEN** Organization.hasUsedServices устанавливается в false

### Requirement: Отображение пакета для проверки

The system SHALL display the next up to 25 unprocessed rows from the import
file in an editable form. The chunk size SHALL define only how many rows the
user reviews at a time and SHALL NOT affect how rows are persisted (each row
is saved independently — «Утверждение пакета»). Each row SHALL show
pre-parsed fields:
Organization name, description, annualPlan, hasUsedServices, an
editable list of contacts (name, phone, email, position), and an
editable list of calls (date, notes). The user SHALL be able to edit
any field, add or remove contacts, add or remove calls, and skip
a row entirely. The page SHALL display the current progress
(`processedRows / totalRows`).

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

#### Scenario: Все строки обработаны

- **WHEN** processedRows = totalRows
- **THEN** система отображает итоги импорта (количество сохранённых организаций, контактов, звонков)

### Requirement: Утверждение пакета

The system SHALL process the rows of the current chunk when the user submits
the approval form. A chunk is only the number of rows the user reviews at a
time (up to 25) and SHALL NOT be a transaction boundary: each row SHALL be
saved in its own database transaction. For each row the system SHALL create
an Organization entity, associated Contact entities, and associated Call
entities, and SHALL increment `processedRows` by one (skipped rows count as
processed as well). The system SHALL redirect back to the review page for
the next chunk, or to the import list when `processedRows` equals
`totalRows`.

If a row fails to save, its transaction SHALL roll back (no partial data for
that row), rows already saved in the same chunk SHALL remain, processing
SHALL stop, and the system SHALL display the error message for the failed
row together with a notice that the import can be resumed from the last
processed row. The error message SHALL NOT be stored on the session.

#### Scenario: Сохранение пакета из 25 строк

- **WHEN** администратор утверждает пакет из 25 строк, все одобрены
- **THEN** создаётся 25 организаций с контактами и звонками
- **AND** processedRows увеличивается на 25

#### Scenario: Сохранение пакета с пропущенными строками

- **WHEN** администратор утверждает пакет из 25 строк, 3 пропущены
- **THEN** создаётся 22 организации
- **AND** processedRows увеличивается на 25 (включая пропущенные)

#### Scenario: Ошибка при сохранении строки

- **WHEN** при сохранении одной из строк пакета происходит ошибка
- **THEN** эта строка не сохраняется
- **AND** строки пакета, сохранённые до неё, остаются в базе
- **AND** processedRows указывает на последнюю успешно обработанную строку
- **AND** отображается сообщение об ошибке для этой строки и о возможности продолжить с последней обработанной позиции

#### Scenario: Завершение импорта

- **WHEN** processedRows становится равным totalRows после сохранения пакета
- **THEN** отображается страница с итогами
- **AND** в списке импортов действия недоступны

### Requirement: Обнаружение дубликатов названий

The system SHALL check Organization.name uniqueness during chunk
processing. When an organization name already exists in the system,
the system SHALL pause and display a conflict dialog offering the
user a choice: merge (add contacts and calls to the existing
organization) or create new. The merge option SHALL append contacts
and calls to the existing organization without modifying its fields.
The create-new option SHALL create a new organization with the same
name.

#### Scenario: Обнаружен дубликат — выбор слияния

- **WHEN** в пакете есть строка с организацией «Нафтан», и в системе уже существует «Нафтан»
- **AND** администратор выбирает «Слить с существующей»
- **THEN** контакты и звонки из строки добавляются к существующей организации «Нафтан»

#### Scenario: Обнаружен дубликат — выбор создания нового

- **WHEN** в пакете есть строка с организацией «Нафтан», и в системе уже существует «Нафтан»
- **AND** администратор выбирает «Создать новую»
- **THEN** создаётся новая организация «Нафтан» (допускается дублирование имени)

### Requirement: Обработка ошибочных данных

The system SHALL validate each row during chunk display. When a
required field (Organization.name) is empty, the system SHALL
highlight the field with an error state. When a date field cannot
be parsed, the system SHALL show the raw text and let the user
correct it. Invalid data SHALL NOT prevent saving the rest of the
chunk — skipped or corrected rows are handled as described in the
approval requirement.

#### Scenario: Пустое название организации

- **WHEN** в строке колонка «Компания» пуста
- **THEN** поле названия организации подсвечивается как ошибочное
- **AND** строка может быть пропущена или исправлена перед утверждением

#### Scenario: Нераспознанная дата в звонке

- **WHEN** в колонке «Взаимодействия» встречается запись без даты в формате (DD.MM.YYYY)
- **THEN** запись отображается как звонок без даты с исходным текстом в поле заметки
- **AND** пользователь может исправить дату или пропустить запись

### Requirement: Замена CSV-файла

The system SHALL let the administrator upload a replacement CSV file on the
import review page while `processedRows < totalRows`. The replacement file
SHALL be validated against the declared format («Формат CSV-файла»). On
success the system SHALL store the new file, update the filename and
`totalRows` to the new file's non-empty record count, and SHALL preserve
`processedRows` so that processing continues from the same position in the
new file. The system SHALL reject a replacement whose non-empty record count
is less than `processedRows`, keeping the current file and `processedRows`
unchanged and showing an error. When `processedRows` equals `totalRows`, the
replacement form SHALL NOT be available.

#### Scenario: Замена файла во время проверки

- **WHEN** администратор заменяет файл у импорта с processedRows = 50 и totalRows = 400
- **THEN** новый файл сохраняется, имя файла и totalRows обновляются
- **AND** processedRows остаётся равным 50
- **AND** следующий пакет начинается с 51-й строки нового файла

#### Scenario: Замена файла с недостаточным количеством строк

- **WHEN** администратор загружает файл с 30 строками для импорта, у которого processedRows = 50
- **THEN** замена отклоняется с сообщением об ошибке
- **AND** текущий файл и processedRows не изменяются

#### Scenario: Замена файла с неправильными заголовками

- **WHEN** администратор загружает файл с отсутствующей колонкой
- **THEN** замена отклоняется со списком ожидаемых заголовков
- **AND** текущий файл не изменяется

#### Scenario: Замена недоступна после завершения

- **WHEN** в импорте processedRows = totalRows
- **THEN** форма замены файла не отображается

### Requirement: Данные импорта привязаны к администратору

The system SHALL record the admin user who initiated each import
(`createdBy`). Imported organizations SHALL NOT be assigned to any
group. Imported calls SHALL have `madeBy` set to the admin who
ran the import. Imported calls SHALL have `madeAt` set to the
parsed date from the CSV (with time 12:00) and SHALL NOT have
result flags (isDeal, isRefusal, isNoAnswer) set.

#### Scenario: Импортированные организации без групп

- **WHEN** администратор завершает импорт организации «Нафтан»
- **THEN** организация «Нафтан» не принадлежит ни одной группе

#### Scenario: Импортированные звонки с автором

- **WHEN** администратор «admin» завершает импорт звонка по организации «Нафтан» от 25.08.2026
- **THEN** у звонка madeBy = «admin» и madeAt = 25.08.2026 12:00:00
