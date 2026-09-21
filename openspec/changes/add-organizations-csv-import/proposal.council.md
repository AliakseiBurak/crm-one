# Council Notes: proposal

## Author Summary

Draft produced by `adversarial-author` prompt on model `opencode/big-pickle`
(via `opencode run -m opencode/big-pickle`, since the subagent cannot be
addressed with `--agent` and the default build model has broken auth). The
draft proposed: one new capability `organizations-import` holding the whole
CSV import flow (header check, 100-org batches, polling progress, two
interactive stop conditions — date-edit resume and duplicate merge/create),
plus a `web-interface` delta for the «⚙ Админ ▾» menu item; entities
`organizations`/`calls`/`contacts` left unchanged; assumptions list
(12:00 convention, annualPlan truncation, labeled description blocks,
default contact name, no groups, База.csv not auto-loaded).

## Reviewer Challenges

- Reviewer round could not run as configured: `openai/gpt-5.5` (agent file
  model) returns a provider server error; the `claude-code` delegate fails to
  spawn (`claude-agent-acp` ENOENT); the `codex` delegate turn ended with
  status failed. The user selected the claude reviewer; after both delegates
  proved unavailable, the flow continued with primary-agent review (the
  user-visible fallback option «Только автор + моя сверка»).
- Primary review findings on the draft:
  - R1: stray `# Change:` H1 — template must be followed exactly.
  - R2: Capabilities sections used bold prose instead of the template's
    `- \`path\`: description` list items.
  - R3: scope leak — session status enum (`running/waiting-for-user-action/...`),
    parser continuation rules and `CampaignProgressController` precedent are
    HOW-level for design.md.
  - R4: missing scope boundaries: concurrency of import sessions, cancel/no
    rollback semantics, behavior on repeated import of the same file, madeBy
    attribution.
  - R5: garbled sentence («дня месяца-года»).
  - R6: «Контакты добавляются без дубликатной проверки» was an unflagged new
    assumption; either flag it or drop it to spec level.

## Resolutions

- Accepted: R1, R2, R5 — final proposal follows the template verbatim.
- Accepted: R3 — mechanics compressed to behavior wording; session-state and
  parsing details deferred to design.md.
- Accepted: R4 — added to «Границы скоупа (допущения)»: single active session,
  cancel without rollback, re-import pauses on name matches, madeBy = importing
  admin without result marks.
- Rejected: removing the polling/batch wording entirely — the user's request
  explicitly prescribes batches of 100 and polling progress, so it stays as
  scope wording.
- Deferred: contact dedup semantics (R6) — resolved at spec level as «no dedup
  check for imported contacts», noted below as risk.

## Remaining Risks

- Review round is single-perspective (primary agent) due to reviewer-model
  unavailability; cross-model challenge was preserved only on the author side.
- Merge-collision rules (e.g. description content of existing org vs appended
  import blocks) to be pinned in spec scenarios; ambiguity may surface during
  apply.
- `config.yaml` rule «Must use grill-with-docs skill» — no such skill exists in
  this project; requirement-clarification was done interactively with the user
  instead (4+2 clarification questions, all answers recorded in the task pack).
