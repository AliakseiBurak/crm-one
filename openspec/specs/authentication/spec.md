# Authentication

Аутентификация построена на сессиях Symfony: `form_login` для браузерных
пользователей, `remember_me` для долгих сессий, `same_site` cookie.
API-эндпоинты всегда stateless и используют токен. В коде доступ проверяется
через `Security::getUser()`.

## Purpose

Единая модель аутентификации: сессионный вход через форму для UI и stateless
API с токенами, единые правила cookie и проверки доступа в коде.

## Requirements

### Requirement: Вход через форму (session-based auth)
The system SHALL authenticate UI users through `form_login` with session-based
cookies; the login form SHALL accept login and password, and successful
authentication SHALL start a server-side session. The system SHALL use `login`
field as the user identifier for authentication; `email` SHALL remain stored
(optional) but SHALL NOT be used for login.

#### Scenario: Успешный вход через форму
- **WHEN** незалогиненный пользователь открывает страницу входа
- **AND** вводит корректные логин и пароль
- **THEN** пользователь оказывается аутентифицированным
- **AND** в браузере устанавливается session cookie

#### Scenario: Успешный вход через форму после смены email
- **WHEN** пользователь изменил email в профиле
- **AND** входит по своему неизменному логину
- **THEN** пользователь оказывается аутентифицированным

#### Scenario: Ошибка при неверном пароле
- **WHEN** незалогиненный пользователь вводит неверный пароль
- **THEN** система возвращает ошибку аутентификации «Неверный логин или пароль»
- **AND** сессия пользователя не создаётся

#### Scenario: Ошибка при неверном логине
- **WHEN** незалогиненный пользователь вводит несуществующий логин
- **THEN** система возвращает ошибку аутентификации «Неверный логин или пароль»
- **AND** сессия пользователя не создаётся

### Requirement: User entity — поле login и nullable email
The User entity SHALL have a `login` field (string, length=180, unique, not null, min=5) used as the login identifier. The `email` field SHALL be nullable (optional). The fields `name` and `surname` SHALL be placed after `email` in the entity definition.

#### Scenario: Создание пользователя с логином и без email
- **WHEN** создаётся новый пользователь с логином «ivanov» и без email
- **THEN** пользователь сохраняется с login="ivanov" и email=null

#### Scenario: Отклонение создания с коротким логином
- **WHEN** создаётся новый пользователь с логином «abc» (менее 5 символов)
- **THEN** система отклоняет создание с ошибкой валидации «не менее 5 символов»

#### Scenario: Список пользователей показывает логин первым
- **WHEN** администратор открывает список пользователей на `/admin/users`
- **THEN** первым полем в таблице отображается «Логин» (login)

### Requirement: Установка пароля новым пользователем
The password setup form on the login page SHALL use `login` instead
of `email` to identify the user. The form SHALL contain fields: `login`
(required), `new_password`, `confirm_password`. The system SHALL find
the user by login and verify no password is set.

#### Scenario: Успешная установка пароля по логину
- **WHEN** новый пользователь указывает свой логин и вводит пароль
- **THEN** система находит пользователя по логину и устанавливает пароль

#### Scenario: Ошибка — логин не найден
- **WHEN** пользователь указывает несуществующий логин
- **THEN** система возвращает ошибку «Пользователь не найден или пароль уже установлен»

### Requirement: Профиль пользователя в шапке
The header user dropdown («Профиль») SHALL display the user's login
as the first line, name and surname (if present) as the second line, and email
(if present) as the third line, followed by the «Выйти» link. The sidebar
(mobile view) SHALL display the same information.

#### Scenario: Профиль отображает логин, имя и email
- **WHEN** вошедший пользователь открывает выпадающее меню «Профиль»
- **THEN** первым пунктом отображается логин пользователя
- **AND** вторым пунктом отображаются имя и фамилия (если указаны)
- **AND** третьим пунктом отображается email (если указан)

#### Scenario: Профиль без имени и email
- **WHEN** вошедший пользователь с пустыми name, surname и email открывает меню «Профиль»
- **THEN** отображается только логин пользователя
- **AND** строки имени и email отсутствуют

### Requirement: Remember me
The system SHALL support `remember_me` to keep the user authenticated after the
session expires; the remember-me cookie SHALL have `same_site` and security
flags configured, and re-authentication SHALL be required when the remember-me
cookie is invalid or missing.

#### Scenario: Вход с опцией запомнить меня
- **WHEN** пользователь входит через форму с опцией "запомнить меня"
- **THEN** система устанавливает remember-me cookie с флагом `SameSite`
- **AND** после истечения сессии пользователь остаётся аутентифицированным

#### Scenario: Невалидная remember-me cookie
- **WHEN** у пользователя есть невалидная или просроченная remember-me cookie
- **THEN** пользователь не аутентифицируется автоматически
- **AND** система требует повторный вход через форму

### Requirement: Stateless API
The system SHALL run API endpoints with `stateless: true` and SHALL NOT rely on
session cookies for them; API requests SHALL be authenticated with a token.

#### Scenario: API не использует сессию
- **WHEN** клиент API выполняет запрос с токеном
- **THEN** запрос проходит аутентификацию по токену без использования сессии
- **AND** ответ не зависит от cookies браузера

#### Scenario: API без токена отклоняется
- **WHEN** клиент API выполняет запрос без токена
- **THEN** система возвращает 401 Unauthorized

### Requirement: Проверка доступа через Security::getUser()
The system SHALL use `Security::getUser()` for access control in controllers,
and SHALL NOT rely on global or static state to determine the current user.

#### Scenario: Действие с неаутентифицированным пользователем
- **WHEN** анонимный пользователь обращается к защищённому маршруту
- **THEN** `Security::getUser()` возвращает null
- **AND** система перенаправляет на страницу входа или возвращает 401

#### Scenario: Роль пользователя учитывается в доступе
- **WHEN** пользователь с ролью manager обращается к защищённому маршруту
- **THEN** доступ определяется `Security::getUser()` и его ролью по модели
  доступа (`adr/0005–0008`)