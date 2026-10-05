# Sequence-диаграммы (ядро: организации + контакты + обзвон + рассылки)

Сгенерировано из спецификаций `organizations`, `contacts`, `calls`,
`campaigns`, `organization-groups`, `access-control`. Диаграммы проверяют, что
каждый Scenario выводим без придумывания деталей.

## 1. Создание организации менеджером → попадание в user-<id>-group

```mermaid
sequenceDiagram
    actor Manager as Менеджер (role=manager)
    participant API as API (Symfony)
    participant DB as MySQL

    Manager->>API: POST /organizations {name, industry}
    API->>DB: проверка role = manager
    API->>DB: найти группу type=user owner_user_id=<id>
    API->>DB: INSERT organization
    API->>DB: INSERT org_group_membership (org_id, user_group_id)
    API-->>Manager: 201 Organization
```

## 2. Создание организации администратором (группы не проверяются)

```mermaid
sequenceDiagram
    actor Admin as Администратор (role=admin)
    participant API as API (Symfony)
    participant DB as MySQL

    Admin->>API: POST /organizations {name, industry}
    API->>DB: проверка role = admin
    Note over API,DB: группы не проверяются, user-<id>-group нет
    API->>DB: INSERT organization
    API-->>Admin: 201 Organization (без членства в группах)
```

## 3. Поиск организаций по названию/отрасли (область доступа)

```mermaid
sequenceDiagram
    actor Manager as Менеджер
    participant API as API
    participant DB as MySQL

    Manager->>API: GET /organizations?q=Ромашка&industry=IT
    alt role = admin
        API->>DB: SELECT * FROM organization WHERE ...
    else role = manager
        API->>DB: SELECT group_id FROM group_assignment WHERE user_id=<id>
        API->>DB: SELECT group_id FROM organization_group WHERE type=user AND owner_user_id=<id>
        API->>DB: SELECT org_id FROM org_group_membership WHERE group_id IN (...)
        API->>DB: SELECT * FROM organization WHERE id IN (...) AND (name LIKE ... OR industry=...)
    end
    API-->>Manager: 200 список (без недоступных организаций)
```

## 4. CRUD контакта в карточке организации

```mermaid
sequenceDiagram
    actor Manager as Менеджер
    participant API as API
    participant DB as MySQL

    Manager->>API: POST /organizations/{id}/contacts {name, phone, email, ...}
    API->>DB: доступна ли организация менеджеру? (группы)
    alt доступна
        API->>DB: INSERT contact (organization_id=<id>)
        API-->>Manager: 201 Contact
    else недоступна
        API-->>Manager: 403 Forbidden
    end
```

## 5. Создание менеджера → автосоздание user-<id>-group

```mermaid
sequenceDiagram
    actor Admin as Администратор
    participant API as API
    participant DB as MySQL

    Admin->>API: POST /users {email, role=manager}
    API->>DB: INSERT user (role=manager)
    API->>DB: INSERT organization_group (name="user-<id>-group", type=user, owner_user_id=<id>)
    API-->>Admin: 201 User + группа создана

    Admin->>API: POST /users {email, role=admin}
    API->>DB: INSERT user (role=admin)
    Note over API,DB: группа НЕ создаётся
    API-->>Admin: 201 User
```

## 6. Сеанс обзвона по группе организаций

```mermaid
sequenceDiagram
    actor Manager as Менеджер
    participant API as API
    participant DB as MySQL

    Manager->>API: POST /calls/session/start {group_id, sort}
    API->>DB: SELECT орг-и группы (own + назначенные custom)
    API->>DB: сортировка (next_call_date / last_call / last_note)
    API-->>Manager: список организаций

    loop пока не закончились организации
        Manager->>API: POST /calls {organization_id, contact_id, ...}
        API->>DB: INSERT call
        Manager->>API: POST /calls/{id}/result {notes, is_deal, campaign_id?, next_call_id?}
        API->>DB: UPDATE call (заметки, результат)
        alt результат = рассылка
            API->>DB: INSERT call.campaign_id = <campaign>
        end
        alt результат = будущий звонок
            API->>DB: INSERT call (scheduled_at = future) -> next_call_id
        end
    end

    Manager->>API: POST /calls/session/finish
    API->>DB: собрать org-и c campaign_id по звонкам сеанса
    API-->>Manager: окно запуска рассылки (правка писем)
```

## 7. Запуск рассылки и отправка через outbox

```mermaid
sequenceDiagram
    actor Manager as Менеджер
    participant API as API
    participant DB as MySQL
    participant Mailer as Symfony Mailer / SMTP

    Manager->>API: POST /campaigns/{id}/launch
    API->>DB: для каждой организации-адресата INSERT email_outbox (pending, token)
    API-->>Manager: 200 рассылка запущена

    loop worker (команда)
        Worker->>DB: SELECT email_outbox WHERE status=pending
        Worker->>Mailer: отправка письма (шаблон с токенами заполнен)
        alt успех
            Mailer-->>Worker: доставлено
            Worker->>DB: UPDATE status=delivered
        else отказ
            Mailer-->>Worker: ошибка
            Worker->>DB: UPDATE status=bounced
        end
    end

    Manager->>API: GET /campaigns/{id}/progress
    API->>DB: SELECT статусы outbox
    API-->>Manager: статус по каждому письму + прогресс (X из N)

    Note over API,DB: opened: получатель запрашивает 1x1 pixel -> GET /track/{token} -> status=opened
```

## 8. Загрузка страницы пагинированного списка с разрешением `highlight`

```mermaid
sequenceDiagram
    actor U as Менеджер
    participant C as HomeController
    participant P as Pagination
    participant R as OrganizationRepository
    participant DB as MySQL

    U->>C: GET /dashboard?page=999&sort=lastCall&dir=desc&highlight=<id>
    C->>C: q, sort, dir, inactive, optout, highlight
    C->>R: COUNT по области доступа + условиям (без подзапросов звонков)
    R->>DB: SELECT COUNT(o.id) ... WHERE ...
    DB-->>R: total
    C->>P: зажать page к последней существующей
    P-->>C: page, pages, offset, window
    alt номер страницы вне диапазона или пустые параметры
        C-->>U: 302 на канонический URL открытой страницы
    end
    opt highlight вне открытой страницы
        C->>R: позиция организации в текущем порядке
        R->>DB: SELECT COUNT(o.id) ... WHERE ключ сортировки «до» целевой строки
        DB-->>C: rank
        C->>P: открыть страницу floor(rank / 50) + 1
    end
    C->>R: срез страницы
    R->>DB: SELECT ... ORDER BY CASE WHEN дата IS NULL THEN 1 ELSE 0 END ASC, дата DESC, o.id ASC LIMIT 50 OFFSET 50
    DB-->>R: 50 строк
    C->>R: контакты и звонки только для строк страницы
    C-->>U: таблица страницы + макрос навигации (page в ссылках)
```

Тот же разбор — у списка групп, таблицы состава группы и реестра скрытых
организаций: `COUNT` по тому же `WHERE`, затем `LIMIT/OFFSET 50`. Сортировка
везде в SQL, поэтому страницу выбирает база, а страница — `OFFSET`, то есть
позиция: последним ключом `ORDER BY` идёт первичный ключ строки, иначе две
организации с одинаковым названием попадали бы на соседние страницы.

## Сверка со сценариями

| Spec (Requirement) | Покрытие |
|---|---|
| organizations: создание/обновление/удаление | диаграммы 1–2 |
| organizations: поиск по названию и отрасли | диаграмма 3 |
| contacts: добавление/обновление/удаление | диаграмма 4 |
| organization-groups: автосоздание группы менеджера | диаграмма 5 |
| organization-groups: admin не имеет группы | диаграммы 2, 5 |
| access-control: видимость менеджера/админа | диаграммы 2, 3 |
| calls: сеанс обзвона, результат, завершение | диаграмма 6 |
| campaigns: launch, outbox, статусы + opened | диаграмма 7 |
| dashboard: постраничный просмотр панели, `highlight` на нужной странице | диаграмма 8 |
| web-interface: пагинация списков | диаграмма 8 |
| organization-groups: постраничный просмотр списка групп и состава | диаграмма 8 |
| organization-hiding: постраничный просмотр реестра | диаграмма 8 |

## Open questions

1. Slug custom-группы: генерируется автоматически из имени или вводится
   администратором вручную? (в сценарии указан явно)
2. Удаление организации: каскадное удаление контактов или soft-delete?
   (в спеках не оговорено)
3. Сеанс обзвона: список фиксируется на старте или пересчитывается при
   каждом шаге (например, если просроченные звонки добавляются динамически)?
4. `next_call_id` и `scheduled_at` будущего звонка: создаётся один `Call` сразу
   (в одном POST) или результат сначала сохраняется, потом создаётся новый?
   Рекомендация: одной транзакцией, чтобы `next_call_id` не висел без цели.