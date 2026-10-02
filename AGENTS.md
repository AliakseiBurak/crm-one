# [AGENTS.md](http://AGENTS.md)

## What this repo is

The B2B Call CRM **application**: Symfony 7.4 / PHP 8.5 / Doctrine ORM 3 with Twig,
Webpack Encore and MySQL, plus the OpenSpec documentation that drives it. Source in
`src/`, templates in `templates/`, SCSS/JS in `assets/`, Doctrine migrations in
`migrations/`.

- Tests: PHPUnit 11 (`phpunit.xml.dist`, `tests/`), Playwright (`e2e/`) and
  Doctrine fixtures (`doctrine/doctrine-fixtures-bundle`).
- Quality tooling: PHPStan (level set in `phpstan.neon` + `phpstan-baseline.neon`),
  php-cs-fixer, Infection (mutation testing) — see ADR-0013.
- `make` targets wrap the usual commands; see `Makefile`.
- Tracked by git.

## Source of truth

- `openspec/project.md` — видение, миссия, цели и карта возможностей продукта B2B Call CRM.
- `openspec/specs/<capability>/spec.md` — спецификации возможностей (spec-driven:
`## Purpose`, `## Requirements` с `### Requirement` и `#### Scenario`).
- `adr/<adr>.md` — архитектурные решения (инфраструктура, организация, контакты,
  модель взаимодействия/обзвон, M2M членство, область доступа, фиксированные
  роли, e-mail/рассылки, скрытие организаций (ADR-0012), инструменты качества
  (ADR-0013), WYSIWYG-редактор и рендеринг email (ADR-0014)).
- `openspec/design/` — дизайн-артефакты (ER-схема БД, sequence-диаграммы),
сгенерированные из спек для верификации.
- OpenSpec — единственный источник истины.



## Language rule

- Сценарии (`#### Scenario`) и шаги (`- **WHEN**`/`- **THEN**`/`- **AND**`) пишутся
**на русском**; ключевые слова Gherkin — **английские** (`WHEN`, `THEN`, `AND`).
- Нормативные глаголы в тексте требований — **английские**: `SHALL`, `MUST`, `MAY`
(требование `openspec validate`; русские «ДОЛЖНА/ДОЛЖЕН» не распознаются
валидатором и дают warning).
- Сохраняйте русский при редактировании содержимого, унаследованного из продуктовой документации.



## Domain model constraints (hard, ADR-0003–0009, ADR-0011–0012)

Accredited without asking the user; keep consistent:

- **Область доступа — default-open deny-list** (ADR-0012, заменила формулу
  ADR-0007/ADR-0011). Менеджер видит **все** организации, кроме имеющих запись
  `OrganizationHide` для этого менеджера. Принадлежность к группе **не влияет**
  на доступ к организациям.
- **Администратор видит всё** (ADR-0008); записи скрытия не ограничивают его
  доступ никак. У админа нет персонального `user-<id>-group`, проверка групп для
  админа пропускается.
- Персональные группы (`user-<id>-group`) **ликвидированы** (ADR-0011). Менеджеры
  создают **custom-группы**, которыми владеют через `created_by`; полный CRUD
  только по своим группам (403 по чужим). Группы нужны для **категоризации**.
- Org ↔ group — **many-to-many** (`OrgGroupMembership`, таблица
  `org_group_membership`); одна группа может быть назначена многим менеджерам
  (`GroupAssignment`).
- Роли — фиксированный enum `admin|manager` (ADR-0009); CRUD ролей нет.
- Не возвращать per-org ACL-уровни.



## Common task traps

- Any edit touching access/roles must match the model above.
- A contact belongs to exactly one organization (`Contact` has no grouping
  entity); only `OrganizationGroup` exists for grouping.
- **No UNIQUE constraint exists on `organization.name` or on any `contact`
  column.** Duplicate organization names and the same email under two
  organizations are both legal today. `contact.is_main` "one per organization"
  is an application rule enforced in `ContactRepository::resetIsMainForOrganization()`,
  not a DB constraint — code that writes contacts outside `ContactController`
  must call it itself. `MailingService::effectiveMainContact()` falls back to the
  lowest-ID contact when no `is_main` is set.
- `openspec` CLI 1.8.0 is installed. Specs use the spec-driven format;
  `openspec validate <capability> --type spec` works out of the box.
- git repo exists (add `safe.directory` exception if needed).



## E2E test conventions (Playwright)

При написании или редактировании e2e тестов **обязательно** соблюдайте:

1. **Тесты не проверяют конкретные цифры на динамических полях** (количество
   звонков, контактов и т.д.). Используйте `toBeGreaterThanOrEqual(1)`,
   `toHaveCount(expect.any(Number))` или проверку наличия элементов, а не их
   точного числа. Числа меняются при добавлении фикстур и других тестов.

2. **Тесты, требующие логина, идут первыми** в файле. Smoke/логин-тесты
   — первые в прогоне. Порядок файлов: `smoke.spec.ts` → остальные.

3. **Playwright тесты — связные и последовательные:** логин → создание
   данных → проверки → удаление данных. Каждый тест создаёт только те
   данные, которые ему нужны, и **обязательно удаляет** их в конце.
   Общая БД фикстур не должна меняться между прогонами.

4. **При раскрытии аккордеона** (org-details) проверяйте, раскрыта ли строка
   перед кликом:
   ```ts
   if (!(await orgRow.evaluate((el) => el.classList.contains('org-table__row--expanded')))) {
     await orgRow.click();
   }
   ```
   После редиректа `?highlight=<id>` строка уже раскрыта (`--expanded`
   добавлен сервером). Повторный клик **сворачивает** её (JS toggle).

5. **Идентификаторы организаций/контактов не хардкодятся.** Ищите элементы
   по имени/тексту через `page.locator('.org-table__row', { hasText: '...' })`,
   а не по ID (`/organizations/6/edit`). ID могут измениться при изменении
   фикстур.

6. **Не используйте `const login = ...` внутри test-блока** — это
   конфликтует с функцией `login()`. Именуйте переменную `loginName` или
   иначе.

## Fixtures before tests

**Всегда перезагружайте фикстуры перед запуском тестов, обращающихся к данным.**
Тесты e2e жёстко завязаны на исходное состояние БД: `home-stats.spec.ts`
ожидает ровно 6 организаций у менеджера и 7 у администратора. Один
прерванный прогон оставляет организации и пользователей (`*-e2e-*`) в базе,
и следующий прогон падает не из-за вашего кода, а из-за дрейфа данных —
падения меняются от прогона к прогону, а изолированный прогон «чинит» их.

```sh
make fixtures                      # обычно
vendor/bin/phpunit                 # SQLite (.env.test) — фикстуры не нужны
cd e2e && ./node_modules/.bin/playwright test --workers=2
```

Без docker имя хоста `mysql` не резолвится; приложение доступно по
`https://b2b-crm.local`, а его база — по адресу docker-хоста:

```sh
DATABASE_URL='mysql://b2b_crm:1234@172.17.0.1:3306/b2b_crm?serverVersion=8.4' \
  php bin/console doctrine:fixtures:load --no-interaction
```

Если прогон e2e прервался или упал — перезагрузите фикстуры перед следующим,
иначе падения придётся расследовать заново.



## Git commit rules

- При создании коммитов указывай автором пользователя через `git config`,
  а себя добавляй только как `Co-authored-by:` в конце сообщения. Так же важно указать текущую модель.

## OpenSpec workflow

- spec-driven schema: proposal → specs → design → tasks.
- For OpenSpec propose/apply/verify/archive workflows, use the local
`openspec-git-discipline` skill to enforce proposal commits before apply and
merge-before-archive discipline.

