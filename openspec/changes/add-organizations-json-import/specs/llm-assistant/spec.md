# LLM Assistant (delta)

## Purpose

Позволяет администратору получить структурированные данные об организациях от
внешней языковой модели при подготовке импорта, не выдавая серверу приложения
ни API-ключа, ни содержимого запроса.

## ADDED Requirements

### Requirement: Доступ к LLM-компоненту ограничен администратором

The LLM tab SHALL be available at `/admin/import/llm` and SHALL require
`ROLE_ADMIN`. The system SHALL deny access to non-admin users with HTTP 403.
The tab SHALL be part of the import page, reachable from it.

#### Scenario: Администратор открывает LLM-компонент

- **WHEN** аутентифицированный администратор переходит на `/admin/import/llm`
- **THEN** отображается выбор провайдера, поле API-ключа, выбор модели, поле исходного текста и кнопка «Составить JSON»
- **AND** страница ссылается на вкладку «Промпт» с текстом той же инструкции, которую отправляет

#### Scenario: Менеджер не может открыть LLM-компонент

- **WHEN** аутентифицированный менеджер переходит на `/admin/import/llm`
- **THEN** система отклоняет запрос с ошибкой 403

### Requirement: Провайдеры OpenRouter и Ollama

The system SHALL support two providers selectable by the administrator:
OpenRouter and Ollama. Each provider SHALL be called through its own native API:
the two differ in the request body and in where the answer sits in the response,
so the tab SHALL speak to each of them in the way that provider documents.

| Provider | Chat endpoint | Model list | Answer |
| --- | --- | --- | --- |
| OpenRouter | `POST https://openrouter.ai/api/v1/chat/completions` | `GET /api/v1/models` | `choices[0].message.content` |
| Ollama | `POST <host>/api/chat` | `GET <host>/api/tags` | `message.content` |

OpenRouter SHALL be authenticated with `Authorization: Bearer <key>` and SHALL
send `HTTP-Referer` and `X-OpenRouter-Title` for attribution. Ollama SHALL be
called without authentication and SHALL send its format as a JSON Schema in the
`format` field, with `stream: false` and `temperature: 0`, so that the answer is
a single document rather than a stream of tokens.

For Ollama the administrator SHALL be able to set the host; an empty host SHALL
mean `http://localhost:11434`. The system SHALL request the model list from the
selected provider and SHALL populate the model selector with it. A failure to
reach the provider SHALL be shown as an error in the tab and SHALL NOT affect
the import.

#### Scenario: Список моделей OpenRouter

- **WHEN** администратор выбирает провайдера OpenRouter и вводит ключ
- **AND** нажимает «Получить модели»
- **THEN** список моделей провайдера подставляется в выбор модели

#### Scenario: Список моделей Ollama

- **WHEN** администратор выбирает провайдера Ollama и указывает хост
- **AND** нажимает «Получить модели»
- **THEN** система запрашивает `<host>/api/tags`
- **AND** список моделей провайдера подставляется в выбор модели

#### Scenario: Хост Ollama пуст

- **WHEN** администратор выбрал Ollama и оставил поле хоста пустым
- **THEN** система обращается к `http://localhost:11434`

#### Scenario: Провайдер недоступен

- **WHEN** запрос списка моделей завершается ошибкой
- **THEN** во вкладке отображается сообщение об ошибке
- **AND** существующий импорт при этом не затрагивается

### Requirement: API-ключ хранится только в браузере

The API key SHALL be held by the page in a JavaScript variable backed by
`sessionStorage`. The system SHALL NOT store the key on the server, SHALL NOT
send it to any application endpoint, and SHALL NOT write it to `localStorage`.
The tab SHALL provide no separate action to clear the key: closing the tab
clears it, and a button that means the same thing is a second way to do what
the browser already does. The tab SHALL state that the key is visible to the
browser and to anyone with access to the workstation, and does not survive
closing the tab. That statement SHALL be placed directly under the key field,
where the key is entered, rather than at the end of the page.

The requests to the provider SHALL be issued by the browser directly. The
application server SHALL neither receive the prompt, nor the provider's
response, nor the API key.

#### Scenario: Ключ не отправляется на сервер приложения

- **WHEN** администратор вводит API-ключ и отправляет промпт
- **THEN** запрос к провайдеру выполняется браузером
- **AND** сервер приложения не получает ни ключ, ни промпт, ни ответ

#### Scenario: Ключ не переживает закрытие вкладки

- **WHEN** администратор вводит API-ключ и закрывает вкладку браузера
- **THEN** при следующем открытии страницы ключ не восстанавливается

#### Scenario: Ключ исчезает вместе с вкладкой

- **WHEN** администратор закрывает вкладку браузера
- **THEN** ключ удалён из хранилища браузера
- **AND** отдельного действия для его удаления на странице нет

#### Scenario: Предупреждение о видимости ключа стоит под полем

- **WHEN** администратор открывает вкладку LLM-компонента
- **THEN** сразу под полем API-ключа отображается предупреждение, что ключ виден браузеру и пользователю рабочей станции и исчезает с вкладкой

### Requirement: Ответ модели виден и скачивается файлом

The request SHALL carry the JSON Schema published by the `organizations-import`
capability as the response format, so that the provider returns an object
matching that schema. The schema sent to the provider SHALL be that document
reduced to what a provider accepts: keywords a structured-output engine cannot
parse (`$ref`, `$id`, `$schema`, `pattern`, `maxLength`, `additionalProperties`)
SHALL be stripped, while the field names, types and required fields SHALL be
kept. The same keywords SHALL remain in the document served for download and
used for server-side validation.

The request SHALL consist of the rendered prompt and the administrator's source
text: the prompt describes the format, the source text is the material to be
parsed. The system SHALL NOT ask the model to repair, complete or guess a value.

The prompt SHALL carry no worked example. An example is returned as data: the
model handed back the sample organization, its contact and its phone along with
the organizations that were really in the source, and a sample built from
obviously invented values is still an organization the model can copy. What the
format is, the prompt already says in words — the field dictionary carries the
names, types, lengths and date shapes, and that is enough.

Because there is no sample, the rules SHALL say how a missing value is written:
a field whose data is absent SHALL be omitted rather than filled, a placeholder
such as «пример», «тест», «неизвестно» or «-» SHALL NOT be written, and the same
contact, phone or mailbox SHALL NOT be repeated across organizations. Repeated
values are how a model fills what it does not know.

The response format sent to the provider SHALL be the root of the published
document, object with the `organizations` array, not the array on its own: the
array schema makes the provider answer with a bare array, which the importer
does not accept. `unp` SHALL be carried over from the source as it is and
omitted when the source has none; the prompt SHALL say that an unp found in the
source is never dropped, since a rule that only forbids inventing a value reads
as a reason to leave the field out.

Absence SHALL never block an import: a missing optional field is a missing
field, and only an empty required `name` or a present-but-unparseable date makes
the answer fail.

On a successful response the system SHALL show the response text on the page, so
that the administrator sees exactly what will be saved, and SHALL offer a
download that writes that same text to a file named
`import-<дата>.<время>.json`. The name SHALL use Latin letters and digits only:
a browser drops a non-ASCII name and saves the file as `download`, taking the
date and time with it.

The system SHALL NOT send the response anywhere. There SHALL be no automatic
submission and no automatic transition to the JSON tab: the administrator
downloads the file and uploads it there like any other response file. The
uploaded file SHALL be validated by the server-side validation of the
`organizations-import` capability, so an answer that violates the schema is
rejected there, with the same violation report as any other uploaded file.

The result block SHALL be empty until a response arrives: it SHALL NOT be filled
from the browser's storage, and reopening the tab SHALL NOT show the answer to a
previous request. A result shown before a request was made cannot be told apart
from a fresh one, and the administrator would not know whether the model had
answered the text now in the field or the one from before.

#### Scenario: Ответ остаётся на странице

- **WHEN** провайдер возвращает ответ
- **THEN** текст ответа отображается на странице целиком
- **AND** в браузере не выполняется переход на другую вкладку импорта

#### Scenario: Ответ скачивается файлом с датой и временем

- **WHEN** администратор нажимает «Скачать ответ»
- **THEN** браузер сохраняет файл с именем вида `import-01.10.2026-19-30-00.json`
- **AND** содержимое файла совпадает с показанным на странице ответом

#### Scenario: Блок результата пуст до ответа

- **WHEN** администратор открывает вкладку «LLM» до отправки запроса
- **THEN** блок результата не виден, а кнопка «Скачать ответ» недоступна

#### Scenario: Прежний ответ не возвращается в пустой блок

- **WHEN** ответ уже получен, а администратор перезагружает страницу или открывает вкладку заново
- **THEN** блок результата снова пуст
- **AND** результат появляется только после ответа на новый запрос

#### Scenario: Новый запрос убирает прежний ответ

- **WHEN** администратор отправляет новый запрос, не дожидаясь прежнего
- **THEN** прежний ответ убирается со страницы до прихода нового
- **AND** при отказе провайдера страница остаётся без ответа

#### Scenario: В импорт попадает только загруженный файл

- **WHEN** администратор скачал ответ и загрузил его на вкладке «JSON»
- **THEN** проверка ответа выполняется сервером по опубликованной схеме
- **AND** ответ, оставшийся на вкладке «LLM», в импорт не попадает

#### Scenario: Ответ, не прошедший проверку

- **WHEN** администратор загружает на вкладке «JSON» ответ, нарушающий схему
- **THEN** отображается отчёт о нарушениях с номерами организаций и полями
- **AND** запись импорта не создаётся

#### Scenario: Пустой исходный текст

- **WHEN** администратор нажимает «Составить JSON», не вставив исходный текст
- **THEN** запрос к провайдеру не отправляется
- **AND** во вкладке отображается просьба вставить текст

#### Scenario: Схема ответа совпадает со скачиваемой

- **WHEN** формируется запрос к провайдеру
- **THEN** в качестве формата ответа передаётся та же JSON-схема, что доступна для скачивания на вкладке «Промпт»
- **AND** из неё убраны только те ключевые слова, которые движок структурированного вывода не понимает

#### Scenario: Промпт сообщает ограничения до генерации

- **WHEN** администратор отправляет промпт и исходный текст
- **THEN** модель получает в промпте допустимые форматы даты, максимальные длины и обязательные поля
- **AND** промпт говорит, что обязательно только название, а отсутствующие данные не возвращаются

#### Scenario: В промпте нет примера ответа

- **WHEN** формируется промпт
- **THEN** он не содержит ни одного примера организации, контакта или звонка
- **AND** формат ответа описан словами и словарём полей

#### Scenario: Правила записи отсутствующих данных

- **WHEN** формируется промпт
- **THEN** сказано, что поле без данных не возвращается вовсе, а не заполняется
- **AND** запрещены заглушки вроде «пример», «тест», «неизвестно» и «-»
- **AND** запрещено повторять один и тот же контакт, телефон или адрес у нескольких организаций

#### Scenario: Формат ответа провайдеру — объект с organizations

- **WHEN** формируется запрос к провайдеру
- **THEN** в качестве формата ответа передаётся корень схемы: объект с обязательным массивом `organizations`
- **AND** ответ в виде голого массива провайдеру не предлагается, потому что импорт такой ответ не принимает

#### Scenario: УНП из источника не выбрасывается

- **WHEN** в промпте объясняется поле `unp`
- **THEN** сказано, что найденное в источнике УНП переносится как есть, а отсутствующее поле не возвращается
- **AND** запрет выдумывать УНП не подан как запрет возвращать его

#### Scenario: Промпт не привязан к выгрузке CRM

- **WHEN** администратор открывает промпт
- **THEN** он сформулирован как разбор списка организаций, а не только выгрузки CRM
- **AND** в нём сказано, что `unp` и `annualPlan` выдумывать нельзя

#### Scenario: Одно название — тоже ответ

- **WHEN** модель возвращает организации, у которых заполнено только `name`
- **THEN** ответ проходит ту же проверку, что и полный
- **AND** в импорте создаётся по организации на каждое название

#### Scenario: Провайдер не поддерживает формат ответа

- **WHEN** провайдер отклоняет запрос с форматом ответа
- **THEN** во вкладке отображается сообщение об ошибке
- **AND** существующий импорт при этом не затрагивается

### Requirement: Заблокированный запрос сообщается как ошибка

A request the browser refuses to issue — a cross-origin block, a host that is
unreachable — SHALL be reported to the administrator as an error in the tab, and
SHALL NOT affect the import. How a third-party provider is reached and configured
is outside this capability: the tab speaks the provider's documented API and
reports what comes back.

#### Scenario: Браузер блокирует запрос

- **WHEN** браузер не отправляет запрос к выбранному провайдеру
- **THEN** во вкладке отображается сообщение об ошибке
- **AND** существующий импорт при этом не затрагивается
