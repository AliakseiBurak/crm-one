## Context

Текущая аутентификация: `security.yaml` — `property: email`, `User::getUserIdentifier()` возвращает `$this->email`. Форма входа использует поле `_username` (HTML name) с лейблом «Email». Пользователь (User entity) имеет поля: id, email (unique), passwordHash, role, name, surname, createdAt. Поля `username` нет.

## Goals / Non-Goals

**Goals:**
- Добавить поле `username` (string, unique, not null) на User
- Изменить `getUserIdentifier()` на `$this->username`
- Изменить `security.yaml` provider на `property: username`
- Обновить форму входа — лейбл «Имя пользователя», type=text
- Миграция данных: username = email для существующих пользователей

**Non-Goals:**
- Смена email в профиле
- Добавление username в API-токены
- Восстановление пароля по username (остаётся email)

## Decisions

### Decision 1: Новое поле username
- Тип: string(180), unique, not null
- Валидация: NotBlank, Length(max=180)
- Название колонки: `username`

### Decision 2: Миграция данных
- Для всех существующих пользователей: `UPDATE user SET username = email`
- Это безопасно, т.к. email уникален
- В будущем администратор может изменить username через CRUD

### Decision 3: Security.yaml
```yaml
providers:
  app_user_provider:
    entity:
      class: App\Entity\User
      property: username
```

### Decision 4: Форма входа
- Лейбл: «Имя пользователя» (вместо «Email»)
- type: text (вместо email)
- Сообщение об ошибке: «Неверное имя пользователя или пароль»
- Валидация HTML5: required, minlength

## Risks / Trade-offs

- **[Смена аутентификации]** Существующие пользователи будут логиниться по email (пока не задан username) → **Mitigation**: username = email при миграции; можно сменить позже
- **[Форма установки пароля]** Использует email для идентификации нового пользователя — остаётся без изменений
- **[Интеграции]** API-клиенты, аутентифицирующиеся по email + пароль — могут сломаться → **Mitigation**: API использует token, не email

## Migration Plan

1. Сгенерировать миграцию: ALTER TABLE user ADD username VARCHAR(180) NOT NULL; CREATE UNIQUE INDEX ON username
2. Заполнить username = email для существующих пользователей
3. Обновить security.yaml
4. Обновить User::getUserIdentifier()
5. Обновить форму входа
6. Протестировать вход