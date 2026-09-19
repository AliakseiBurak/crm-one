## Purpose

Определяет процедуру создания пользователя системы администратором: какие поля
принимаются при создании, как валидируется роль и как обеспечивается доступ к
операции создания.

## Requirements

### Requirement: Создавать пользователя может только администратор
The system SHALL allow creating a user only to an authenticated administrator.
Managers and anonymous users SHALL NOT be permitted to create users.

#### Scenario: Администратор создаёт пользователя
- **WHEN** аутентифицированный администратор открывает форму создания пользователя и отправляет валидные данные
- **THEN** система создаёт нового пользователя

#### Scenario: Менеджер не может создавать пользователей
- **WHEN** аутентифицированный менеджер пытается открыть форму или выполнить запрос создания пользователя
- **THEN** система отклоняет операцию с ошибкой доступа

#### Scenario: Анонимный пользователь не может создавать пользователей
- **WHEN** неаутентифицированный пользователь пытается выполнить запрос создания пользователя
- **THEN** система возвращает ошибку аутентификации

### Requirement: Логин обязателен при создании пользователя
The system SHALL require the `login` field when creating a user and
SHALL reject creation with a missing login. The login SHALL be unique
across all users. The login is used for authentication. The login MUST
be at least 5 characters long.

#### Scenario: Создание пользователя с логином
- **WHEN** администратор создаёт пользователя с указанием логина
- **THEN** пользователь создаётся с указанным логином

#### Scenario: Отклонение создания без логина
- **WHEN** администратор создаёт пользователя и не указывает логин
- **THEN** система отклоняет создание с ошибкой валидации

#### Scenario: Отклонение создания с коротким логином
- **WHEN** администратор создаёт пользователя с логином короче 5 символов
- **THEN** система отклоняет создание с ошибкой валидации «не менее 5 символов»

#### Scenario: Отклонение создания с существующим логином
- **WHEN** администратор создаёт пользователя с логином, который уже существует в системе
- **THEN** система отклоняет создание с ошибкой уникальности

### Requirement: Email необязателен при создании пользователя
The system SHALL accept the `email` field as optional when creating a user.
If provided, the email SHALL be valid and unique across all users. The email
is used for communications, not for authentication.

#### Scenario: Создание пользователя с email
- **WHEN** администратор создаёт пользователя с указанием email
- **THEN** пользователь создаётся с указанным email

#### Scenario: Создание пользователя без email
- **WHEN** администратор создаёт пользователя без указания email
- **THEN** пользователь создаётся с пустым email

#### Scenario: Отклонение создания с существующим email
- **WHEN** администратор создаёт пользователя с email, который уже существует в системе
- **THEN** система отклоняет создание с ошибкой уникальности

#### Scenario: Отклонение создания с некорректным email
- **WHEN** администратор создаёт пользователя с email "not-an-email"
- **THEN** система отклоняет создание с ошибкой валидации

### Requirement: Роль обязательна при создании пользователя
The system SHALL require the `role` field (`admin` or `manager`) when creating
a user and SHALL reject creation with a missing or invalid role. The role set
SHALL be fixed (ADR-0009).

#### Scenario: Создание пользователя с ролью manager
- **WHEN** администратор создаёт пользователя и указывает роль manager
- **THEN** пользователь создаётся с ролью manager

#### Scenario: Создание пользователя с ролью admin
- **WHEN** администратор создаёт пользователя и указывает роль admin
- **THEN** пользователь создаётся с ролью admin

#### Scenario: Отклонение создания без роли
- **WHEN** администратор создаёт пользователя и не указывает роль
- **THEN** система отклоняет создание с ошибкой валидации

#### Scenario: Отклонение произвольной роли
- **WHEN** администратор создаёт пользователя и указывает роль supervisor
- **THEN** система отклоняет создание с ошибкой валидации

### Requirement: Поля имени и фамилии необязательны
The system SHALL accept the `name` and `surname` fields as optional at user
creation. The fields MAY be empty or omitted; creation SHALL succeed without
them.

#### Scenario: Создание пользователя с именем и фамилией
- **WHEN** администратор создаёт пользователя "Мария Смирнова" с указанием имени и фамилии
- **THEN** пользователь создаётся с сохранёнными значениями name="Мария" и surname="Смирнова"

#### Scenario: Создание пользователя без имени и фамилии
- **WHEN** администратор создаёт пользователя, не указывая имя и фамилию
- **THEN** пользователь создаётся, а поля name и surname остаются пустыми

#### Scenario: Создание пользователя с именем без фамилии
- **WHEN** администратор создаёт пользователя, указывая только имя
- **THEN** пользователь создаётся с указанным именем и пустой фамилией

### Requirement: Группы при создании пользователя не создаются
The system SHALL NOT create any group automatically when a user is created,
for either role (`manager` or `admin`) — personal groups are eliminated
(ADR-0011). Group access starts empty until a custom group is created by the
manager or assigned by the administrator.

#### Scenario: Создание менеджера не создаёт группу
- **WHEN** администратор создаёт пользователя с ролью manager
- **THEN** пользователь создаётся без какой-либо автоматически созданной группы

#### Scenario: Создание администратора не создаёт группу
- **WHEN** администратор создаёт пользователя с ролью admin
- **THEN** группа для пользователя не создаётся
