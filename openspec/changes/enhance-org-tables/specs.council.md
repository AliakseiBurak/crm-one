# Council Notes: specs

## Author Summary
Primary-agent authoring of seven delta specs under `enhance-org-tables/specs/` for modified capabilities: organizations, organizations/crud, organization-hiding, organization-groups, dashboard, web-interface, user-delete. Adversarial subagents remain unavailable (free-tier); same exception as proposal.

## Reviewer Challenges
- (none — adversarial-reviewer not run)

## Resolutions
- Accepted: MODIFIED only where existing requirement text/scenarios change (hasUsedServices, form field lists, dashboard table/sort/org_details/buttons, web-interface tables); ADDED for new concerns (UNP, created_by, hiding registry columns, group composition columns/sort, user-delete org fate).
- Rejected: inventing a new capability for UNP/tables — all fit existing specs per proposal.
- Deferred: exact static px widths for columns → design.md; CSV-import delta left out of scope per user decision.

## Remaining Risks
- `dashboard` «Все звонки организации» requirement left content-focused; order of blocks is enforced via MODIFIED «Контакты организации…» and «Кнопки действий…».
- `organizations/crud` create/edit field lists updated; other incidental checkbox phrasing in unrelated scenarios may still linger in main specs until archive merge review.
- user-delete org options (reassign vs clear created_by) need UX parity with group choice in design/tasks.
