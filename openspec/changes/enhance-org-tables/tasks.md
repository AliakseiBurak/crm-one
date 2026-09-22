## 1. Schema and entity

- [ ] 1.1 Add Doctrine migration: `CHANGE COLUMN has_used_services courses_attended VARCHAR(255) DEFAULT NULL` (rename + type change), `unp VARCHAR(32) DEFAULT NULL`, `created_by BIGINT` + FK to `user` `ON DELETE SET NULL`, reorder `created_at`/`updated_at` after `created_by`; verify with `php bin/console doctrine:migrations:migrate` (and `migrate:down` restores bool column `has_used_services`)
- [ ] 1.2 Update `Organization` entity: rename `$hasUsedServices` → `$coursesAttended` (column `courses_attended`, type `?string`), add `?string $unp`, add `?User $createdBy` (ManyToOne, `SET NULL`) placed before `createdAt`/`updatedAt`; verify `doctrine:schema:validate` / `orm:validate-schema` passes
- [ ] 1.3 Set `createdBy` from authenticated user only on create in `OrganizationController`; verify create as admin and as manager stores correct creator and update does not change it
- [ ] 1.4 Bind `unp` and string `coursesAttended` in `applyRequest` / JSON payload; verify empty УНП → null and free text round-trips on save
- [ ] 1.5 Update `AppFixtures`: sample course-name strings for `coursesAttended`, `createdBy` on orgs, optional sample `unp`; verify fixtures load without type errors

## 2. Organization form and quick-edit modal

- [ ] 2.1 In `organization/form.html.twig`: replace «Пользовались услугами» checkbox with text input «Учились у нас»; add optional «УНП» input after «Сфера деятельности»; verify form create/edit saves both fields
- [ ] 2.2 In `organization/_edit_modal.html.twig`: text inputs for coursesAttended + unp with `data-organization-field`; verify modal open prefills values and save updates without reload
- [ ] 2.3 Update `organization-modal.js`: rename `hasUsedServices` → `coursesAttended` in field mapping and dataset, treat as string dataset (no `'1'/'0'` coercion), include `unp` in text-field loop and payload; verify editing free text does not collapse to boolean-like values

## 3. Dashboard table and org_details

- [ ] 3.1 In `_organizations_table.html.twig`: remove industry header/cell and industry `sort_header`; fix details-row `colspan` to match remaining columns; verify table renders 5 sortable headers (name, lastCall, nextCall, isActive, optedOutAt) and no «Сфера деятельность» column
- [ ] 3.2 Reorder `.org-details__box` DOM: Описание → metadata row (Сфера деятельности + УНП + Учились у нас) → Последний звонок → Все звонки → Контакты → «Добавить звонок» + «Добавить контакт» at bottom; add description/industry/unp/coursesAttended display blocks; verify expand order in browser matches spec
- [ ] 3.3 Row datasets: rename `data-org-hasusedservices` → `data-org-coursesattended`; text values for description/industry/unp/coursesAttended; verify modal prefill uses text, not `'1'/'0'`
- [ ] 3.4 Remove industry from dashboard sort whitelist if unused elsewhere (`OrganizationRepository::SQL_SORT_COLUMNS` or leave with comment if still required); verify `?sort=industry` is not offered by UI headers
- [ ] 3.5 SCSS: shared org-table width rules (`.table--org` or equivalent): name column max/fixed, other columns static; apply to dashboard table; verify no layout reflow with long/short names

## 4. Hide registry and group composition

- [ ] 4.1 `organization_hide/list.html.twig`: add «Дата создания» and «Отрасль» columns (industry `—` when empty); verify registry shows both columns for hide rows
- [ ] 4.2 Apply shared column-width classes to hide registry table; verify name column takes max width, others static
- [ ] 4.3 `GroupController::members` + `group/members.html.twig`: columns «Дата создания», «Создатель» (org creator); default sort name ASC; verify members table shows new columns with defaults
- [ ] 4.4 Add `sort_header` for Название, Отрасль, Дата создания, Создатель (readonly + edit modes; checkbox not sortable); PHP `usort` match for `name|industry|createdAt|creator`; verify clicking each header sorts and toggles direction
- [ ] 4.5 Apply shared column-width classes to group composition table; verify static widths + max name column

## 5. User delete — organizations auto-reassign

- [ ] 5.1 `UserController::delete` GET: load orgs with `created_by = user`; pass count + list to template; verify page shows informational note about auto-reassignment
- [ ] 5.2 `templates/user/delete.html.twig`: add informational note listing orgs that will be reassigned to admin (no per-org radios); verify note displays when manager has created orgs
- [ ] 5.3 `UserController::remove` POST: auto-reassign all orgs `created_by = deleted_user` to current admin (`created_by = current_admin`); never delete orgs; admin-delete path skips org section; verify transaction updates `created_by` then deletes user
- [ ] 5.4 Functional/e2e coverage: manager with created orgs shows note; auto-reassign path; admin-delete skips orgs; verify tests pass

## 6. Tests and verification

- [ ] 6.1 Update `OrganizationControllerTest` (and related) for string coursesAttended + unp + created_by on create; verify PHPUnit suite green for organization controllers
- [ ] 6.2 Update `e2e/tests/dashboard-organizations.spec.ts`: sortable count 6→5, remove/adjust industry sort and nth-child indices, assert org_details content order (metadata row grouping) and buttons at bottom; verify Playwright dashboard tests pass
- [ ] 6.3 Update e2e for hide registry columns and group members sort/columns if present; verify organization-hiding / group specs pass
- [ ] 6.4 Run lint/typecheck as configured for the repo (`composer` scripts / `npm run lint` if present); verify no new errors
- [ ] 6.5 Manual smoke per design Migration Plan: create/edit with УНП + text courses; panel expand order (metadata row); hide + group tables; delete manager with orgs (verify auto-reassign + note); verify checklist against `specs/` scenarios

## 7. Out-of-scope guardrails

- [ ] 7.1 Do not modify `openspec/changes/add-organizations-csv-import/**`; verify that change folder is untouched (`git status` / diff)
- [ ] 7.2 Confirm no ACL/access-rule edits beyond display/sort/create-by; verify ADR-0006–0008/0011 behavior unchanged in access tests
