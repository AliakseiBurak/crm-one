## Context

Сущность `Organization` в текущей реализации (Doctrine ORM 3.x) имеет поля: `id`, `name`, `industry`, `createdAt`, `updatedAt`. Поле `industry` — `string`, not nullable. Формы создания/редактирования — ручные Twig-шаблоны с Symfony Form-типами. Дизайн-система: underline-поля, пилюльные кнопки, без теней (см. spec organizations/crud). Модель доступа не изменяется — все организации видимы в рамках области доступа менеджера (ADR-0007) или глобально для админа (ADR-0008).

Развязка с change `call-result-deal-and-optout` (вариант C): opt-out пара `isOptedOut`/`optOutReason`, условное отображение причины в форме и сброс причины при снятии флага принадлежат только тому change. Здесь они не добавляются — миграции не конфликтуют, поля не дублируются.

Новые поля не затрагивают существующую модель доступа, звонки, рассылки или курсы — только сущность `Organization`, её форму и валидацию.

## Goals / Non-Goals

**Goals:**
- Сделать `Organization.industry` nullable без потери данных
- Добавить поля `annualPlan`, `description`, `hasUsedServices` на сущность `Organization`
- Обновить ADR-0001 и ER-диаграмму
- Обновить фикстуры/датафикстуры для тестовых данных

**Non-Goals:**
- Opt-out поля `isOptedOut`/`optOutReason` и их форма — владение передано `call-result-deal-and-optout` (вариант C развязки)
- Изменение модели доступа к организациям
- Изменение сущностей `Call`, `Campaign`, `Contact` или `Course`
- Автоматическая простановка любых флагов из результатов звонков (в change `call-result-deal-and-optout`)
- Изменение поискового индекса (search-indexing capability)

## Decisions

### Decision 1: Industry nullable через миграцию
**Решение**: Doctrine-миграция `ALTER TABLE organization MODIFY industry VARCHAR(255) DEFAULT NULL`. Существующие записи сохраняют отрасль; новые — могут не указывать. Валидация формы: убрать `NotBlank` с поля `industry`, оставить только на `name`.

**Альтернатива**: Создать новое поле `industryNullable` и мигрировать данные — отвергнуто как избыточное; прямое изменение колонки проще и безопаснее (значения не теряются).

### Decision 2: Типы новых полей в Doctrine
| Поле | Тип | Doctrine | Nullable | Default |
|---|---|---|---|---|
| `annualPlan` | string(255) | `string` | true | null |
| `description` | text | `text` | true | null |
| `hasUsedServices` | boolean | `boolean` | false | false |

`annualPlan` — строка, а не дата: заказчик указал «дата или месяц составления», т.е. может быть и "Сентябрь 2026", и "2026-09-15". String покрывает оба случая без избыточной валидации.

### Decision 3: Названия полей в форме (русские labels)
| Поле | Label | Тип поля |
|---|---|---|
| annualPlan | Годовой план | `text` |
| description | Описание | `textarea` |
| hasUsedServices | Пользовались услугами | `checkbox` |

## Risks / Trade-offs

- **[Migration] Изменение industry с NOT NULL на NULL**: MySQL может потребовать `CHECK`-ограничения. → **Mitigation**: Doctrine `ChangeColumnType` генерирует корректный `MODIFY COLUMN`; проверить на тестовой БД.
- **[Backward compat] Существующие API-клиенты**: Если есть внешние интеграции, ожидающие `industry` всегда непустым. → **Mitigation**: API-эндпоинты пока не документированы как публичные; риск низкий.

## Migration Plan

1. Сгенерировать Doctrine-миграцию: `php bin/console make:migration`
2. Проверить SQL: `ALTER TABLE organization ADD ...`, `MODIFY industry VARCHAR(255) DEFAULT NULL`
3. Накатить: `php bin/console doctrine:migrations:migrate`
4. Откат: `php bin/console doctrine:migrations:migrate prev` (новые колонки удалятся, industry станет NOT NULL — значения не теряются)
