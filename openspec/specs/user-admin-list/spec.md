## Purpose

Определяет страницу списка пользователей для администратора: какие данные
отображаются, как обеспечивается доступ и как реализуется удаление
со списка.

## Requirements

### Requirement: Список пользователей доступен только администратору
The system SHALL show the user list at `/admin/users` only to authenticated
administrators. Managers and anonymous users SHALL NOT be permitted to
access the user list.

#### Scenario: Администратор видит список пользователей
- **WHEN** аутентифицированный администратор открывает страницу `/admin/users`
- **THEN** система отображает список всех пользователей

#### Scenario: Менеджер не может видеть список пользователей
- **WHEN** аутентифицированный менеджер пытается открыть страницу `/admin/users`
- **THEN** система отклоняет доступ с ошибкой

#### Scenario: Анонимный пользователь не может видеть список пользователей
- **WHEN** неаутентифицированный пользователь пытается открыть страницу `/admin/users`
- **THEN** система перенаправляет на страницу входа

### Requirement: Список показывает ключевые данные пользователя
The user list SHALL display for each user: login as the first column,
email (if set, otherwise "—"), name (if set), surname (if set), and role.
The list SHALL include a delete button for each user except the current
administrator.

#### Scenario: Отображение данных пользователя в списке
- **WHEN** администратор открывает список пользователей
- **THEN** каждый пользователь отображается с логином (первым полем), email
  (если задан, иначе «—»), именем (если задано), фамилией (если задана) и ролью

#### Scenario: Кнопка удаления отсутствует для текущего пользователя
- **WHEN** администратор открывает список пользователей
- **THEN** кнопка удаления отсутствует рядом с записью текущего
  аутентифицированного администратора

### Requirement: Удаление со страницы списка
The user list SHALL provide a delete button that navigates to a confirmation
page. The confirmation page SHALL require CSRF protection and a POST
request to execute deletion.

#### Scenario: Нажатие кнопки удаления
- **WHEN** администратор нажимает кнопку удаления рядом с пользователем
- **THEN** система перенаправляет на страницу подтверждения удаления

#### Scenario: Подтверждение удаления
- **WHEN** администратор подтверждает удаление на странице подтверждения
- **THEN** система удаляет пользователя и возвращает на список пользователей
