# [AGENTS.md](http://AGENTS.md)

## What this repo is

Documentation-only OpenSpec workspace. No application code, build system, tests, or CI exist. Tracked by git.

## Source of truth

- `openspec/project.md` — видение, миссия, цели и карта возможностей продукта B2B Call CRM.
- `openspec/specs/<capability>/spec.md` — спецификации возможностей (spec-driven:
`## Purpose`, `## Requirements` с `### Requirement` и `#### Scenario`).
- `adr/<adr>.md` — архитектурные решения (инфраструктура, организация, контакты, модель взаимодействия/обзвон, группы `created_by`-владение (ADR-0011),
M2M членство, область доступа, фиксированные роли, e-mail/рассылки).
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



## Domain model constraints (hard, ADR-0003–0008, ADR-0011)

Accredited without asking the user; keep consistent:

- Personal groups (`user-<id>-group`) are **eliminated** (ADR-0011). Managers
  create **custom groups** they own via `created_by`; full CRUD on own groups
  only (403 on foreign groups). **Admin has no personal group**; groups are not
  checked for admin.
- Org ↔ group is **many-to-many** (`OrgGroupMembership`, table
  `org_group_membership`); one group can be assigned to many managers
  (`GroupAssignment`).
- Managers get **full access** to orgs in groups they created (`created_by`) +
  all assigned groups.
- **Admin sees everything**, manages groups and assignments; on manager
  deletion chooses per-group fate (reassign to admin / delete).
- Do not re-introduce per-org ACL tiers.



## Common task traps

- Any edit touching access/roles must match the model above.
- A contact belongs to exactly one organization (`Contact` has no grouping
entity); only `OrganizationGroup` exists for grouping.
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



## Git commit rules

- При создании коммитов указывай автором пользователя через `git config`,
  а себя добавляй только как `Co-authored-by:` в конце сообщения. Так же важно указать текущую модель.

## OpenSpec workflow

- spec-driven schema: proposal → specs → design → tasks.
- For OpenSpec propose/apply/verify/archive workflows, use the local
`openspec-git-discipline` skill to enforce proposal commits before apply and
merge-before-archive discipline.

