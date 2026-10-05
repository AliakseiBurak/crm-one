# OpenSpec Project Agent Guidelines

Project-specific conventions for agents working in this OpenSpec workspace.

## Language

- Spec-driven format: `## Purpose`, `## Requirements` with `### Requirement` and
  `#### Scenario` blocks.
- Scenario names and step text (`- **WHEN**`/`- **THEN**`/`- **AND**`): **Russian**.
- Normative verbs in requirement text: **English** `SHALL`, `MUST`, `MAY`
  (required by `openspec validate`; Russian «ДОЛЖНА» is not recognized).
- If scenarios become executable acceptance tests, step definitions must match
  the Russian step text (Russian regexes or the `# language: ru` dialect).

## Access model (hard constraints)

Do not re-introduce per-org ACL tiers. See `adr/0006–0008, 0011, 0012`:

- ADR-0012 (инвертировал ADR-0007/ADR-0011): область доступа к организациям —
  **default-open с deny-list**. Менеджер видит **все** организации, кроме
  имеющих запись `OrganizationHide` для него. Принадлежность к группе на доступ
  к организациям **не влияет** — группы остались только категоризацией.
- ADR-0011: personal groups (`user-<id>-group`) eliminated; managers own custom
  groups via `created_by`, full CRUD on own groups, read-only on assigned ones.
  Права на группы ADR-0012 не меняет.
- ADR-0006: org ↔ group many-to-many (`OrgGroupMembership`, table
  `org_group_membership`); one group assignable to many managers
  (`GroupAssignment`).
- ADR-0008: admin sees everything, manages groups and assignments; admin has no
  personal group, groups are not checked for admin. Скрытие организаций админа
  тоже не ограничивает.
- Гейтвей доступа един: `OrganizationRepository::findAccessibleIds(?User)` —
  `null` для администратора/гостя, для менеджера все id, кроме скрытых от него.

## Terminology

- `Contact` — contact entity bound to an organization.
- `OrganizationGroup` — organizations grouping.
Do not rename inconsistently.

## Source of truth

- OpenSpec — единственный источник истины. Исходный PRD удалён.
- `openspec/specs/` — спецификации возможностей; `adr/0000–0016` —
  архитектурные решения (инфраструктура, организация, контакты, модель
  взаимодействия/обзвон, владение группами через `created_by` (ADR-0011),
  M2M членство,
  область доступа, фиксированные роли, e-mail/рассылки, скрытие
  организаций (ADR-0012), инструменты качества (ADR-0013),
  WYSIWYG-редактор и рендеринг email (ADR-0014), справочные поля
  организации (ADR-0015), журнал результатов отправки писем (ADR-0016)).

## Tooling caveats

- `openspec` CLI 1.8.0 is installed. Specs use the spec-driven format;
  `openspec validate <capability> --type spec` works out of the box.
- git repo exists (add `safe.directory` exception if needed).

## Workflow

spec-driven schema: proposal → specs → design → tasks. Skill gates in
`openspec/config.yaml` must be honored.