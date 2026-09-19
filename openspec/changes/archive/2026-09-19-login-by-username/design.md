## Context

Текущая аутентификация: `security.yaml` — `property: email`, `User::getUserIdentifier()` возвращает `$this->email`. Форма входа использует поле `_username` (HTML name) с лейблом «Email». Пользователь (User entity) имеет поля: id, email (unique), passwordHash, role, name, surname, createdAt. Поля `login` нет. Email обязателен.

## Goals / Non-Goals

**Goals:**
- Добавить поле `login` (string, unique, not null, min=5) на User — логин для входа
- Сделать `email` nullable (необязательным)
- Расположить поля `name` и `surname` после `email` в entity
- Изменить `getUserIdentifier()` на `$this->login`
- Изменить `security.yaml` provider на `property: login`
- Обновить форму входа — лейбл «Логин», type=text
- Обновить форму создания пользователя — «Логин» обязательное (min=5), email необязательное
- Обновить список пользователей (admin/users) — «Логин» первым полем
- Обновить профиль пользователя (header) — логин первым, имя/фамилия вторым, email третьим
- Обновить форму установки пароля (Новый пользователь) — поиск по логину вместо email
- Миграция данных: login = email для существующих пользователей

**Non-Goals:**
- Смена email в профиле
- Добавление login в API-токены
- Восстановление пароля по login (остаётся email)

## Decisions

### Decision 1: Новое поле login (логин)
- Тип: string(180), unique, not null
- Валидация: NotBlank, Length(min=5, max=180)
- Название колонки: `login`
- Лейбл в UI: «Логин»
- Минимальная длина: 5 символов

### Decision 2: Email становится nullable
- Поле `email` изменяется на `nullable: true`
- Валидация: Email (если указан), уникальность (если указан)
- Миграция: `ALTER TABLE user MODIFY email VARCHAR(255) DEFAULT NULL`

### Decision 3: Порядок полей в User entity
- Поля располагаются в порядке: id, login, email, name, surname, passwordHash, role, createdAt
- Поля `name` и `surname` следуют сразу после `email`

### Decision 4: Миграция данных
- Для всех существующих пользователей: `UPDATE user SET login = email`
- Это безопасно, т.к. email уникален
- В будущем администратор может изменить login через CRUD

### Decision 5: Security.yaml
```yaml
providers:
  app_user_provider:
    entity:
      class: App\Entity\User
      property: login
```

### Decision 6: Форма входа
- Лейбл: «Логин» (вместо «Email»)
- type: text (вместо email)
- Сообщение об ошибке: «Неверный логин или пароль»
- Валидация HTML5: required, minlength

### Decision 7: Форма создания пользователя
- «Логин» — обязательное поле (первым)
- Email — необязательное поле
- Валидация: NotBlank для логина, Email (если указан) для email

### Decision 8: Список пользователей (admin/users)
- «Логин» — первое поле в таблице
- Email отображается с «—» если не задан

### Decision 9: Профиль пользователя (header)
- В выпадающем меню «Профиль» отображается:
  1. Логин — всегда
  2. Имя и фамилия — если указаны
  3. Email — если указан
- В мобильной версии (sidebar) отображается аналогичная информация

### Decision 10: Форма установки пароля (Новый пользователь)
- Поле email заменено на login (логин)
- Поиск пользователя по логину вместо email
- Добавлен метод `UserRepository::findOneByLoginWithNoPassword()`

## Risks / Trade-offs

- **[Смена аутентификации]** Существующие пользователи будут логиниться по email (пока не задан login) → **Mitigation**: login = email при миграции; можно сменить позже
- **[Форма установки пароля]** Использовала email для идентификации нового пользователя → **Mitigation**: переключена на логин (login)
- **[Интеграции]** API-клиенты, аутентифицирующиеся по email + пароль — могут сломаться → **Mitigation**: API использует token, не email

## Migration Plan

1. Сгенерировать миграцию:
   - ALTER TABLE user ADD login VARCHAR(180) NOT NULL
   - CREATE UNIQUE INDEX ON login
   - UPDATE user SET login = email (для существующих пользователей)
   - ALTER TABLE user MODIFY email VARCHAR(255) DEFAULT NULL
   - ALTER TABLE user MODIFY name VARCHAR(255) DEFAULT NULL AFTER email
   - ALTER TABLE user MODIFY surname VARCHAR(255) DEFAULT NULL AFTER name
2. Обновить security.yaml
3. Обновить User::getUserIdentifier()
4. Обновить форму входа (лейбл «Логин»)
5. Обновить форму создания пользователя (логин обязательное, email необязательное)
6. Обновить список пользователей (Логин первым полем)
7. Протестировать вход