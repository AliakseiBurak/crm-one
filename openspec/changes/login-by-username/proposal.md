## Why

Вход в систему осуществляется по email, но для B2B CRM удобнее вход по имени пользователя (username/никнейм) — email может меняться, а username остаётся постоянным идентификатором. Текущая реализация использует `property: email` в security.yaml и `getUserIdentifier()` возвращает email.

## What Changes

- **User**: добавляется поле `username` (string, unique, not null) — имя пользователя для входа
- **Security.yaml**: изменить `property: email` на `property: username` в provider
- **User::getUserIdentifier()**: изменить возврат с `$this->email` на `$this->username`
- **Форма входа**: переименовать поле «Email» → «Имя пользователя», изменить type с email на text
- **Сообщения об ошибках**: «Неверный email или пароль» → «Неверное имя пользователя или пароль»
- **Форма создания пользователя/менеджера**: добавить поле username
- **Email остаётся**: email продолжает храниться и использоваться для писем/коммуникаций, но не для аутентификации
- **Миграция**: для существующих пользователей username = email (или name.surname), с возможностью ручного изменения

## Capabilities

### New Capabilities

Нет новых возможностей.

### Modified Capabilities

- `authentication` — замена поля аутентификации с email на username; форма входа

## Impact

- **Сущность User**: миграция — новое поле `username` (unique, not null)
- **Security.yaml**: замена provider property
- **LoginController/login.html.twig**: обновить подписи
- **Форма создания пользователя**: добавить поле username
- **Существующие пользователи**: миграция данных — `username = email` (или name.surname)
- **Обновить фикстуры**: добавить username для тестовых пользователей