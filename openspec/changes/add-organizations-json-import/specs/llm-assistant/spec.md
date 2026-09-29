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
- **THEN** отображается выбор провайдера, поле API-ключа, выбор модели и поле отправки промпта

#### Scenario: Менеджер не может открыть LLM-компонент

- **WHEN** аутентифицированный менеджер переходит на `/admin/import/llm`
- **THEN** система отклоняет запрос с ошибкой 403

### Requirement: Провайдеры OpenRouter и Ollama

The system SHALL support two providers selectable by the administrator:
OpenRouter and Ollama. Both SHALL be called through their OpenAI-compatible
chat-completions endpoint, configured by a base URL, an API key and a model
name:

| Provider | Base URL | Authentication | Model list |
| --- | --- | --- | --- |
| OpenRouter | `https://openrouter.ai/api/v1` | `Authorization: Bearer <key>`, plus `HTTP-Referer` and `X-OpenRouter-Title` for attribution | `GET /api/v1/models` |
| Ollama | `http://<host>:11434/v1` | any value, ignored by the provider | `GET /api/tags` |

For Ollama the administrator SHALL be able to set the host. The system SHALL
request the model list from the selected provider and SHALL populate the model
selector with it. A failure to reach the provider SHALL be shown as an error in
the tab and SHALL NOT affect the import.

#### Scenario: Список моделей OpenRouter

- **WHEN** администратор выбирает провайдера OpenRouter и вводит ключ
- **AND** нажимает «Получить модели»
- **THEN** список моделей провайдера подставляется в выбор модели

#### Scenario: Список моделей Ollama

- **WHEN** администратор выбирает провайдера Ollama, указывает хост и вводит ключ
- **AND** нажимает «Получить модели»
- **THEN** список моделей провайдера подставляется в выбор модели

#### Scenario: Провайдер недоступен

- **WHEN** запрос списка моделей завершается ошибкой
- **THEN** во вкладке отображается сообщение об ошибке
- **AND** существующий импорт при этом не затрагивается

### Requirement: API-ключ хранится только в браузере

The API key SHALL be held by the page in a JavaScript variable backed by
`sessionStorage`. The system SHALL NOT store the key on the server, SHALL NOT
send it to any application endpoint, and SHALL NOT write it to `localStorage`.
The system SHALL provide an action that clears the key from `sessionStorage`.
The tab SHALL state that the key is visible to the browser and to anyone with
access to the workstation, and does not survive closing the tab.

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

#### Scenario: Забыть ключ

- **WHEN** администратор нажимает «Забыть ключ»
- **THEN** ключ удаляется из хранилища браузера
- **AND** поле ввода ключа очищается

#### Scenario: Предупреждение о видимости ключа

- **WHEN** администратор открывает вкладку LLM-компонента
- **THEN** отображается предупреждение, что ключ виден браузеру и пользователю рабочей станции

### Requirement: Ответ модели попадает в проверку импорта

The request SHALL be sent with the JSON Schema published by the
`organizations-import` capability as the response format, so that the provider
returns an object matching that schema. On a successful response the system
SHALL place the response text into the JSON tab's input field, and the
response SHALL be validated by the same server-side validation as a manually
pasted answer. A response that fails validation SHALL be shown with the same
violation report and SHALL NOT create an import run.

The system SHALL NOT automatically submit the response. The administrator
confirms it, and the organization review of the import remains the step where
the content is confirmed.

#### Scenario: Ответ модели попадает в поле вкладки JSON

- **WHEN** администратор отправляет промпт и провайдер отвечает
- **THEN** текст ответа подставляется в поле вставки на вкладке «JSON»
- **AND** поле не отправляется в импорт автоматически

#### Scenario: Ответ, не прошедший проверку

- **WHEN** провайдер возвращает ответ, нарушающий схему
- **THEN** отображается отчёт о нарушениях с номерами организаций и полями
- **AND** запись импорта не создаётся

#### Scenario: Схема ответа совпадает со скачиваемой

- **WHEN** формируется запрос к провайдеру
- **THEN** в качестве формата ответа передаётся та же JSON-схема, что доступна для скачивания на вкладке «JSON»

### Requirement: Развёртывание Ollama требует настройки источника

An Ollama host used from the browser SHALL have `OLLAMA_ORIGINS` configured to
allow the CRM's origin, otherwise the browser blocks the request. This SHALL be
documented in the deployment notes for the feature. It is a property of the
Ollama host, not an application setting, and the system SHALL NOT attempt to
change it.

#### Scenario: Браузер блокирует запрос к Ollama

- **WHEN** хост Ollama не настроен на источник CRM
- **THEN** браузер блокирует запрос
- **AND** во вкладке отображается сообщение об ошибке
