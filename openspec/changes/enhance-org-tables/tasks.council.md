# Council Notes: tasks

## Author Summary
Primary-agent tasks.md for `enhance-org-tables`: 7 groups, checkbox tasks with in-task verification, ordered schema → forms → dashboard → tables → user-delete → tests → guardrails. No design open questions blocked breakdown.

## Reviewer Challenges
- (none — adversarial-reviewer not run)

## Resolutions
- Accepted: each task states verify command/behavior; out-of-scope CSV-import as explicit guardrail task.
- Rejected: tasks that would edit `add-organizations-csv-import` (user deferred).
- Deferred: unp regex validation task left as optional later (design Open Questions).

## Remaining Risks
- e2e nth-child updates are brittle until run against live fixtures.
- Task 3.2 dual-numbered note (2.3b) is cosmetic only — tracked under group 3.
