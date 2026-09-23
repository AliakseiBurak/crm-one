# Council Notes: design

## Author Summary
Primary-agent design.md for `enhance-org-tables`: lightweight C4 Mermaid sketch in Context (assumptions stated), decisions D1–D10 on schema, created_by, org_details DOM order, table widths, sorting, user-delete org fate, forms/JS, risks, migration plan. Adversarial subagents still unavailable.

## Reviewer Challenges
- (none — adversarial-reviewer not run)

## Resolutions
- Accepted: mirror OrganizationGroup createdBy/SET NULL; null legacy boolean values on retype; DOM reorder for org_details; PHP usort for group composition; org_action radios parallel to group_action.
- Rejected: prod backfill, CSV-import edits, deleting orgs on user delete, JS column autosize.
- Deferred: unp regex/length tightening; exact px widths; hide-registry sort for new columns.

## Remaining Risks
- e2e hardcoded nth-child / sortable count=6 will fail until tests updated in tasks.
- CSV-import active change remains inconsistent until a separate change.
