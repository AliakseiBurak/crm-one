## Context

Сущность `Contact` (Doctrine ORM 3.x) имеет поля: id, organization, name, phone, email, position, notes, createdAt, updatedAt. Добавляется isMain (boolean, default false). Уникальность isMain = true на уровне организации обеспечивается через логику в сервисе/контроллере, а не через БД-ограничение.

Сущность `CampaignRecipient` уже поддерживает поле contact_id (nullable). MailingService уже умеет выбирать email для отправки: если contact_id задан, использует email контакта; иначе — первый email организации.

## Goals / Non-Goals

**Goals:**
- Добавить поле `isMain` (boolean, default false) на Contact
- Реализовать ограничение: только один isMain = true на организацию (через PHP-логику, не через DB constraint)
- Форма контакта: чекбокс «Основной контакт»
- MailingService: обновить маршрутизацию email с учётом isMain

**Non-Goals:**
- Добавление isMain в API-эндпоинты для внешних систем
- Автоматическое назначение isMain при создании первого контакта организации

## Decisions

### Decision 1: Ограничение уникальности — на уровне PHP
**Решение**: Не использовать UNIQUE constraint в БД (MySQL не поддерживает частичные уникальные индексы для условия WHERE isMain = 1 в стандартном синтаксисе). Вместо этого: в контроллере/сервисе при сохранении контакта сбрасывать isMain у предыдущего основного контакта той же организации.

### Decision 2: Маршрутизация email
**Логика MailingService** (в порядке приоритета):
1. Если `CampaignRecipient.contact` задан и у него есть email → TO: email контакта (только ему)
2. Если `CampaignRecipient.contact` задан, но email = null → TO: email isMain-контакта организации; CC: все остальные контакты с email
3. Если `CampaignRecipient.contact = null` → TO: email isMain-контакта организации; CC: все остальные контакты с email
4. Если у организации нет контактов с email → письмо не отправляется (существующее поведение)

### Decision 3: Отображение и сортировка контактов
В списке контактов на форме редактирования организации — пометить isMain-контакт меткой «Основной». isMain-контакт всегда отображается первым в списке.

**Сортировка**: на дашборде (`ContactRepository::findByOrganizations`) — `ORDER BY c.isMain DESC, c.name ASC`. В форме организации — контроллер передаёт отсортированный список через отдельную переменную (Option A), а не через Doctrine-коллекцию `organization.contacts`.

**Бейдж**: в `contact/_card.html.twig` добавляется `<span class="card__badge card__badge--primary">Основной</span>` при `contact.isMain = true`.

### Decision 4: Подстановка токенов — fillTokens
Метод `Campaign::fillTokens` получает `?Contact $contact` и `Organization $organization`. Когда `$contact = null`, метод самостоятельно ищет isMain-контакт через итерацию `$organization->contacts` (Option B). Коллекция уже загружена к моменту вызова (resolveEmailTargets вызывается ранее). Подпись метода не меняется.

## Risks / Trade-offs

- **[Нет isMain]** Если у организации нет ни одного контакта с isMain = true — используется существующее поведение (первый email организации)
- **[Конфликт при массовом импорте]** Если при импорте несколько контактов имеют isMain = true → сохраняется последний, предыдущие сбрасываются

## Migration Plan

1. Сгенерировать миграцию: ALTER TABLE contact ADD is_main TINYINT(1) DEFAULT 0 NOT NULL
2. Накатить миграцию
3. Обновить MailingService (логика маршрутизации)
4. Обновить формы (чекбокс isMain)