# Organizations Import (delta)

## ADDED Requirements

### Requirement: Вкладки импорта CSV и JSON

The import page SHALL offer two tabs: «CSV» and «JSON». The CSV tab SHALL hold
the upload form, the import list and the package review. The JSON tab SHALL show
a ready-to-copy prompt, a link to download the JSON schema, a field for pasting
a response, and a file field for uploading a response. The JSON tab SHALL accept
a response either way. Both tabs SHALL feed the same import run, the same review
packages of at most 25 organizations, the same per-row transaction, the same
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

#### Scenario: Менеджер не видит вкладки импорта

- **WHEN** аутентифицированный менеджер открывает страницу импорта
- **THEN** система отклоняет запрос с ошибкой 403

#### Scenario: Пакеты на обеих вкладках одинаковой величины

- **WHEN** администратор проверяет пакет на вкладке «JSON»
- **THEN** отображается не более 25 организаций одной таблицей с теми же полями, что и на вкладке «CSV»

### Requirement: Формат JSON-данных

The JSON tab SHALL accept a response as pasted text or as an uploaded file, and
a file offered through the file field SHALL be treated exactly as pasted text.
The response SHALL be a single object with a required `organizations` array.
Each element of that array describes one organization and MAY carry the fields
below. The system SHALL publish this contract as a JSON Schema document and
SHALL use that same document to render the field dictionary inside the prompt,
to serve the download, and to validate a submitted response.

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

A `calls[].date` value SHALL be carried verbatim into the same date parser used
by the CSV tab and SHALL be read under the same grammar declared in «Формат
CSV-файла». The system SHALL NOT require or impose ISO 8601 on it. A value that
does not carry an unambiguous day, month and year SHALL stay in the entry's
notes and SHALL NOT become a dated call.

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

#### Scenario: Дата без года остаётся в заметке

- **WHEN** в `calls` указана дата "31.08" без года
- **THEN** текст записи остаётся в заметке
- **AND** звонок с этой датой не создаётся

#### Scenario: Отсутствующее необязательное поле не сохраняется как пустая строка

- **WHEN** в объекте организации отсутствует поле `industry`
- **THEN** `Organization.industry` остаётся пустым (null)
- **AND** пустая строка не сохраняется

#### Scenario: Пустые массивы контактов и звонков

- **WHEN** в объекте организации `contacts` и `calls` — пустые массивы
- **THEN** организация сохраняется без контактов и без звонков

### Requirement: Загрузка JSON-ответа

On submission, the system SHALL reject the request while any *other* import run
has `processedRows < totalRows`, exactly as for a CSV upload. Replacing the file
of the run being viewed is not a submission under this requirement and SHALL NOT
be rejected by this check. Otherwise the system SHALL parse the payload,
validate it against the published JSON Schema, and SHALL report every violation
it finds, naming the organization by its position in the `organizations` array
and the offending field. A payload with at least one organization SHALL be stored
as the run's file, SHALL create an import run with `totalRows` equal to the
length of the `organizations` array and `processedRows` = 0, and SHALL redirect
to the review of the first package. `totalRows` counts organizations, so the
review package, the progress indicator and the completion notice behave
identically on both tabs.

A payload that is not valid JSON, or that violates the schema, SHALL NOT create
an import run and SHALL NOT be stored.

#### Scenario: Успешная вставка ответа

- **WHEN** администратор вставляет ответ со 120 организациями
- **THEN** создаётся запись импорта с totalRows = 120 и processedRows = 0
- **AND** отображается проверка первых 25 организаций

#### Scenario: Успешная загрузка файла с ответом

- **WHEN** администратор выбирает файл с ответом на 40 организациях вместо вставки текста
- **THEN** создаётся запись импорта с totalRows = 40 и processedRows = 0
- **AND** отображается проверка первых 25 организаций

#### Scenario: Вставка при наличии активного прогона импорта

- **WHEN** администратор вставляет ответ, пока существует другой прогон с processedRows < totalRows
- **THEN** вставка отклоняется с сообщением о незавершённом импорте

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
regardless of the order of keys and of how the payload is written. The page
SHALL offer a confirmation action and a cancel action, and SHALL NOT report any
recency or timestamp information. When `processedRows` equals `totalRows`, the
replacement form SHALL NOT be available.

#### Scenario: Замена файла на вкладке JSON

- **WHEN** администратор заменяет файл прогона из JSON, у которого processedRows = 50 и totalRows = 120, на ответ со 118 организациями
- **THEN** отображается страница подтверждения с числом организаций до и после, строкой продолжения 51 и названием организации на ней
- **AND** в отчёте перечислены номера изменившихся организаций из уже обработанных 1–50
- **AND** сессия импорта ещё не изменена

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

#### Scenario: Отмена замены файла прогона из JSON

- **WHEN** администратор отменяет замену на странице подтверждения
- **THEN** текущий файл, processedRows и totalRows остаются прежними
