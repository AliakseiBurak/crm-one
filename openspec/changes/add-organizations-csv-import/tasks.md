# Tasks: CSV Import

## 1. Entity + Migration

- [ ] 1.1 Create `ImportSession` entity with fields: id, filename, storageKey, totalRows, processedRows, savedRows, createdAt, createdBy (ManyToOne User), lastProcessedAt. Verify: entity file compiles and matches design.
- [ ] 1.2 Generate and run Doctrine migration to create `import_session` table. Verify: `php bin/console doctrine:migrations:migrate` succeeds and table exists.

## 2. File Storage Service

- [ ] 2.1 Create `CsvFileStorage` service (mirroring `CampaignAttachmentStorage`): store(UploadedFile) → storageKey, path(storageKey). Storage dir: `var/storage/csv-imports/`. Files are never deleted by the import flow. Verify: unit test — store a file, confirm path exists.
- [ ] 2.2 Register service in `config/services.yaml` with `kernel.project_dir` autowire. Verify: container compiles without errors.

## 3. CSV Parser

- [ ] 3.1 Create `CsvParser` service: read CSV from a file path, validate the header against the declared format (9 named columns, trailing empty fields ignored, header cells trimmed), return the list of non-empty row arrays. Handle multiline cells via `fgetcsv()`. Verify: test with a fixture — header validation passes and row count equals the number of non-empty records.
- [ ] 3.2 Add header mismatch error: when expected columns are missing or unexpected non-empty columns appear, return a structured error with the list of expected vs actual headers. Verify: test with a CSV missing «Взаимодействия» — error returned.
- [ ] 3.3 Create CSV fixtures under `tests/fixtures/import/`: a valid file with multiline cells, doubled quotes, a trailing empty column, a trailing-space header, date variants, a next-contact placeholder and blank rows; plus a file with a missing column. Verify: fixtures parse or fail as expected.

## 4. CSV Row Mapper

- [ ] 4.1 Create `CsvRowMapper` service: map one CSV row array to `OrganizationData`, `ContactData[]`, `CallData[]` DTOs. Map columns per the declared format (Компания→name, Актуальный курс→description, «Учились у нас»→coursesAttended free text, ignored columns skipped). Verify: test with a fixture row — correct DTOs produced.
- [ ] 4.2 Implement interaction parsing: split «Взаимодействия» at date tokens in the declared forms (`D.M.YYYY`, `DD.MM.YYYY`, `DD.MM.YY`, `DD/MM/YYYY`), extract date and notes per entry; text before the first date becomes a call without a date. Verify: fixture with date variants and leading undated text — expected number of CallData objects.
- [ ] 4.3 Implement contact heuristic parsing: regex for phones (`+ digits, spaces, dashes, parentheses`), emails (`@` with domain), remaining text as name/position. Split multiple contacts on name-like patterns. Verify: test with the fixture «Контакты» cell — contacts extracted.
- [ ] 4.4 Implement truncation of `Organization.name`, `annualPlan`, and `coursesAttended` to 255 chars; map «Учились у нас» as free text (empty/whitespace → null), no boolean coercion. Verify: 280-char name, 300-char plan, and 300-char «Учились у нас» each truncated to 255; «Курс по переговорам» stored as-is; empty cell → null.

## 5. DTOs

- [ ] 5.1 Create `OrganizationData` DTO (name, description, annualPlan, coursesAttended). Verify: class exists with correct properties (`coursesAttended` is `?string`).
- [ ] 5.2 Create `ContactData` DTO (name, phone, email, position). Verify: class exists with correct properties.
- [ ] 5.3 Create `CallData` DTO (nullable date, notes). Verify: class exists with correct properties.
- [ ] 5.4 Create `ChunkReviewData` DTO (rows: array of row DTOs, each with orgData, contacts[], calls[], skip flag, row number). Verify: class exists with correct properties.

## 6. Import Processor Service

- [ ] 6.1 Create `ImportProcessor` service: `processChunk(ImportSession, startRow, count)` — read up to 25 CSV rows, parse via CsvParser + CsvRowMapper, return `ChunkReviewData`. Verify: test — process chunk 1 of the fixture file — returns the expected parsed rows (≤ 25).
- [ ] 6.2 Implement `persistRows(ImportSession, approvedRows[])`: for each row open its own transaction (`wrapInTransaction`) creating the Organization (with `created_by` = `session.createdBy`, free-text `coursesAttended`, touched timestamps), Contact entities (linked to org) and Call entities (with madeAt from parsed date, madeBy from session.createdBy), increment processedRows by one (skipped rows count as processed), and increment savedRows by one only when a row is actually saved. On a row failure roll back only that row and stop. Verify: test — persist 2 rows, check DB has orgs with correct created_by/coursesAttended/contacts/calls and savedRows = 2; a skipped row increments processedRows but not savedRows; a failing row leaves no data for that row while earlier rows stay saved and processedRows stops at the last saved row.
- [ ] 6.3 Implement duplicate detection at insert time inside `persistRows`: before saving each row, match Organization.name against the database (including rows inserted earlier in the same chunk/session). On conflict stop persistence like a row save error and return the conflict (org name, existing org ID) for the merge/create dialog. Verify: test — import row with name matching existing org → conflict returned at insert; two rows in the same file with the same name → conflict on the second row after the first is saved.

## 7. Controller

- [ ] 7.1 Create `ImportController` with `#[IsGranted('ROLE_ADMIN')]` at class level. Add routes: GET `/admin/import` (list), POST `/admin/import/upload` (upload), GET `/admin/import/{id}` (review), POST `/admin/import/{id}/approve` (approve), POST `/admin/import/{id}/replace` (replace file). Verify: routes registered — `php bin/console debug:router | grep import`.
- [ ] 7.2 Implement `upload()` action: reject when any ImportSession has processedRows < totalRows; otherwise handle file upload, validate with CsvParser, store via CsvFileStorage, create ImportSession with processedRows = 0 and savedRows = 0, redirect to review. Handle errors (bad headers, empty file). Verify: upload with no active session → redirect to review, session created; upload while another session is incomplete → rejected with message.
- [ ] 7.3 Implement `list()` action: query all ImportSessions ordered by createdAt DESC, render table with «Начать» when processedRows = 0, «Продолжить» when 0 < processedRows < totalRows, and no action when processedRows = totalRows. Verify: page shows imports with filename, total/processed and the right action.
- [ ] 7.4 Implement `review()` action: load ImportSession, call ImportProcessor.processChunk() for the next up to 25 rows, render editable form; redirect to the list with a completion flash when processedRows = totalRows. Verify: open review page — up to 25 pre-parsed editable rows displayed.
- [ ] 7.5 Implement `approve()` action: receive form data, create ChunkReviewData from submitted edits, persistRows (one transaction per row, insert-time duplicate check with merge/create dialog), redirect to next review or list; on a row failure stop and flash the error for that row with the resume notice; on completion flash «Импортировано в этой сессии: X, импортировано всего: Y» (X = session savedRows, Y = SUM(savedRows) across sessions) and redirect to the list. Verify: approve a chunk — DB updated, redirected to next chunk; simulated row failure — message shown, that row absent, earlier rows of the chunk saved, processedRows at the last saved row; completion — flash shows session and cumulative counts.
- [ ] 7.6 Implement `replace()` action: validate the uploaded file via CsvParser, reject when its non-empty record count is less than processedRows or headers are invalid, otherwise store it via CsvFileStorage, update filename/totalRows, keep processedRows, redirect back to review. Verify: replace during review — processedRows unchanged, next chunk starts after it; shorter or invalid file — rejected, current file kept.

## 8. Templates

- [ ] 8.1 Create import list template (`templates/organization_import/index.html.twig`): table with columns (Файл, Всего строк, Обработано, Дата, Действия). Use existing table component pattern. Verify: page renders correctly with sample imports.
- [ ] 8.2 Create chunk review template (`templates/organization_import/review.html.twig`): progress indicator, up to 25 editable row cards with org fields (including free-text «Учились у нас» / coursesAttended, not a checkbox), contacts/calls fields, «Сохранить и продолжить» button, a replacement-file form (while processedRows < totalRows), and the flashed error with the resume notice after a failed chunk. Use existing form field component. Verify: page renders with pre-filled editable fields.
- [ ] 8.3 Create upload form partial: file input + submit button. Add to import list page. Verify: upload form renders on import list page.
- [ ] 8.4 Create replacement-file form partial (file input + submit) and add it to the review page. Verify: form renders on the review page and posts to the replace route.

## 9. Admin Menu Integration

- [ ] 9.1 Add «Импорт организаций» item to admin dropdown in header template (`templates/base.html.twig` or header partial). Link to `/admin/import`. Verify: admin dropdown shows 3 items: Пользователи, Скрытые организации, Импорт организаций.
- [ ] 9.2 Add admin dropdown item to mobile sidebar template. Verify: mobile sidebar shows «Импорт организаций» for admin users.

## 10. Integration + Verification

- [ ] 10.1 Run the full import flow with a generated fixture CSV (≥ 30 rows covering multiline cells, date variants and a duplicate name): upload → review chunk 1 → approve → continue through all chunks → verify organizations/contacts/calls in DB. Verify: all rows processed in chunks of ≤ 25, no errors.
- [ ] 10.2 Test duplicate detection at insert: re-import same file → conflict stops during persist → choose merge → verify contacts added to existing orgs; import a file with the same name twice → second row stops at insert. Verify: no duplicate orgs created unless «Создать новую» chosen.
- [ ] 10.3 Test resume: start import, approve 2 chunks (50 rows), leave the review page and reopen «Продолжить» → processing continues from row 51 without re-importing the first 50; when processedRows = totalRows no action is offered. Verify: resumed session shows correct progress.
- [ ] 10.4 Test access control: login as manager → navigate to `/admin/import` → verify 403. Verify: non-admin cannot access import.
- [ ] 10.5 Test per-row transaction: force a failure on one row of a chunk → verify that row is absent, rows saved before it in the same chunk remain, processedRows points at the last saved row, the error is shown, and «Продолжить» resumes from processedRows. Verify: DB contains exactly the rows before the failure.
- [ ] 10.6 Test CSV replacement: during review replace the file with a corrected export (same header, more rows) → verify filename/totalRows updated, processedRows unchanged, next chunk continues after processedRows; change content inside the processed prefix → those changes are not re-inserted; replace with a shorter file → rejected. Verify: no re-import of processed rows.
- [ ] 10.7 Test single active session: with an incomplete import, attempt a new upload → rejected; complete the import → new upload allowed. Verify: at most one session with processedRows < totalRows.
- [ ] 10.8 Test completion flash: finish an import with skips → flash shows session savedRows (excluding skips) and cumulative total across sessions. Verify: flash message text matches «Импортировано в этой сессии: X, импортировано всего: Y».
