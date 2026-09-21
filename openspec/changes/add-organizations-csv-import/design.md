# Design: CSV Import

## Context

The CRM needs a one-time bulk import of ~400 organizations from a legacy CSV
export. The input format is declared normatively in the `organizations-import`
spec («Формат CSV-файла»); the observed export contains 398 records
(386 non-empty), 9 named columns plus a trailing empty field, multiline cells
in every column, and ~4 650 dated interaction entries. Contact data is
unstructured and interaction logs span multiple years. The import is
admin-only and requires interactive review of parsed data before persistence.

Existing patterns used: `CampaignAttachmentStorage` for file storage,
`CampaignSendProcessor` for batch logic, `#[IsGranted]` for access control,
AJAX polling for progress (campaigns). Messenger is sync-only; import uses
controller-driven chunk processing.

## Goals / Non-Goals

**Goals:**
- Interactive wizard: upload → parse → review/approve chunks of 10 → persist
- Heuristic parsing of unstructured CSV columns (contacts, interactions)
- User can edit, add, remove, or skip rows before approving each chunk
- Duplicate detection with merge/create-new choice
- Track import progress in DB; resume across sessions
- Abort at any point without losing previously saved data

**Non-Goals:**
- Background/async processing (controller-driven polling chosen)
- Auto-group assignment for imported organizations
- Import of non-CSV formats
- Bulk update of existing organizations (only create/merge)
- Import of «Следующий контакт», «Для чего звонок?» and «Текущее состояние»
  as calls — only «Взаимодействия» produces made calls
- Storing the sample export in the repository — the format is declared in the spec
- Converting «Учились у нас» into a text field — separate change; this import
  keeps the boolean mapping

## Decisions

### D1: Controller-driven chunk processing (not Messenger)

**Choice:** Each chunk is processed via a POST endpoint triggered by the user.
No background worker.

**Rationale:** The user wants interactive control — review, edit, approve each
chunk before the next is loaded. This is inherently synchronous from the user's
perspective. Messenger with sync transport would add complexity without benefit.

**Alternatives considered:**
- Messenger + database transport: more robust for fire-and-forget imports, but
  the user needs to review each chunk interactively.
- Scheduler recurring: would process chunks on a timer, not on user approval.

### D2: ImportSession entity for progress tracking

**Choice:** A dedicated `ImportSession` entity stores filename, storageKey,
totalRows, processedRows, createdBy, and timestamps. There is no status
field.

**Rationale:** Matches existing patterns (Campaign entity tracks progress).
The only state that matters is how far processing got: `processedRows`
against `totalRows`. Resuming is available while `processedRows < totalRows`
and unavailable once they are equal; a user pause and a chunk failure are
indistinguishable and need no persisted status. The error message is shown
when it occurs and is not stored on the session.

### D3: Heuristic contact parsing with user verification

**Choice:** Parse contacts using regex for phones and emails, guess names from
remaining text. Show parsed results in editable form for user correction.

**Rationale:** The CSV contact column is completely unstructured — names,
positions, phones, emails mixed freely. No reliable automatic parsing is
possible. The interactive review step is the safety net.

### D4: Chunk size of 25 rows

**Choice:** Process up to 25 rows per chunk. Each chunk is a full page with
editable fields for all rows in the chunk.

**Rationale:** 25 is the agreed maximum for one review page, while keeping the
review burden manageable. The chunk is a review batch only, not a transaction
boundary (see D7). The last chunk may be smaller (e.g., 5 rows for a 400-row
file).

### D5: File storage in `var/storage/csv-imports/`

**Choice:** Store uploaded CSV files with random hex keys, same pattern as
`CampaignAttachmentStorage`.

**Rationale:** Consistent with existing storage approach. Files are never
deleted by the import flow: they are kept for reference after completion and
for resuming after a stop or an error.

### D6: Input format declared in the spec, quirks observed from the export

**Choice:** The file contract (encoding, quoting, header, column mapping,
date grammar, limits, ignored columns) is declared as a requirement in
`specs/organizations-import/spec.md`; the sample export is not stored in the
repository.

**Rationale:** The import is fed through the upload form at runtime; tests and
future work must not depend on a 1.1 MB sample file. The declaration accounts
for the observed quirks:

- the header ends with a trailing comma (empty 10th field) and
  «Текущее состояние » carries a trailing space — header cells are trimmed
  and trailing empty fields ignored;
- 378 of 398 records contain embedded line breaks; 255 doubled quotes;
- interaction dates vary: `D.M.YYYY`, `DD.MM.YY`, `DD/MM/YYYY`, while
  malformed tokens (`(05.11.2025_)`, `(09.04.20255)`) fall back to undated
  entries;
- «Следующий контакт» mixes real dates with placeholders (`-`, `_`);
- one `Компания` value is 280 characters, over the 255 column limit;
- 12 records are fully empty and 2 have an empty `Компания` — empty records
  are skipped, empty names surface in the review editor;
- LF and CRLF line endings are mixed, including inside quoted cells.

### D7: One transaction per row, resume after failure

**Choice:** Each approved row is persisted in its own Doctrine transaction
(`wrapInTransaction`), incrementing `processedRows` per row. On a row failure
only that row's transaction rolls back; rows already saved in the same chunk
stay, processing stops, the error is shown with a resume notice, and the
import continues from `processedRows`.

**Rationale:** A row is the atomic unit of import; rolling back an entire
chunk would discard rows that saved without issues. A chunk is only the
review batch. The stored file is never deleted, so after an error the user
can replace the CSV file and continue from `processedRows`.

### D8: CSV replacement preserves progress

**Choice:** A replacement upload validates the new file, stores it, updates
filename and `totalRows`, and keeps `processedRows`; a file with fewer records
than `processedRows` is rejected. Replacement is available while
`processedRows < totalRows`.

**Rationale:** The source export may be corrected while an import is paused or
stopped by an error; the reviewed/saved prefix must not be re-imported.
Rejecting a shorter file avoids an out-of-range resume position.

## Architecture

### Component Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                     BROWSER (Admin)                         │
│                                                             │
│  ┌──────────┐  ┌──────────────┐  ┌───────────────────────┐ │
│  │ Upload   │  │ Chunk Review │  │ Import List           │ │
│  │ Form     │  │ (editable)   │  │ (table + actions)     │ │
│  └────┬─────┘  └──────┬───────┘  └───────────┬───────────┘ │
└───────┼────────────────┼──────────────────────┼─────────────┘
        │ POST           │ POST                 │ GET
        v                v                      v
┌─────────────────────────────────────────────────────────────┐
│                    SYMFONY APP                               │
│                                                             │
│  ImportController                                           │
│  ├── upload()          → CsvParser + CsvFileStorage         │
│  ├── replace()         → CsvParser + CsvFileStorage         │
│  ├── review()          → CsvParser → editable DTOs          │
│  ├── approve()         → ImportProcessor → persist          │
│  └── list()            → ImportSession repository           │
│                                                             │
│  ┌─────────────────┐  ┌──────────────┐  ┌───────────────┐  │
│  │ CsvFileStorage  │  │ CsvParser    │  │ CsvRowMapper  │  │
│  │ (store/       │  │ (read CSV,   │  │ (row → DTOs,  │  │
│  │  path)          │  │  validate)   │  │  heuristic)   │  │
│  └─────────────────┘  └──────────────┘  └───────────────┘  │
│                                                             │
│  ┌──────────────────────────────────────────────────────┐   │
│  │ ImportProcessor                                      │   │
│  │ ├── processChunk(): parse 25 rows → review DTOs      │   │
│  │ ├── persistRows(): per-row transaction → DB          │   │
│  │ └── checkDuplicates(): match org names               │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                             │
└───────────────────────────┬─────────────────────────────────┘
                            │ Doctrine ORM
                            v
┌─────────────────────────────────────────────────────────────┐
│                        MySQL                                 │
│                                                             │
│  import_session       organization       contact            │
│  ├── id               ├── id             ├── id             │
│  ├── filename         ├── name           ├── organization_id│
│  ├── storage_key      ├── annual_plan    ├── name           │
│  ├── total_rows       ├── has_used_...   ├── phone          │
│  ├── processed_rows   ├── description    ├── email          │
│  ├── created_by       └── ...            ├── position       │
│  └── ...                                 └── ...            │
│  call                                                       │
│  ├── id                                                     │
│  ├── organization_id                                        │
│  ├── made_at                                                │
│  ├── made_by                                                │
│  ├── notes                                                  │
│  └── ...                                                    │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Import Flow (Dynamic)

```
Admin                 ImportController        Services           DB
 │                         │                    │                │
 │  POST /admin/import     │                    │                │
 │  (upload CSV)           │                    │                │
 │────────────────────────>│                    │                │
 │                         │  CsvFileStorage    │                │
 │                         │  .store(file)      │                │
 │                         │───────────────────>│                │
 │                         │<───────────────────│                │
 │                         │                    │                │
 │                         │  CsvParser         │                │
 │                         │  .validate()       │                │
 │                         │───────────────────>│                │
 │                         │<───────────────────│                │
 │                         │                    │                │
 │                         │  INSERT import_session              │
 │                         │───────────────────────────────────>│
 │  redirect → /admin/import/{id}              │                │
 │<────────────────────────│                    │                │
 │                         │                    │                │
 │  GET /admin/import/{id} │                    │                │
 │────────────────────────>│                    │                │
│                         │  CsvParser         │                │
│                         │  .parseChunk(rows 1-25)            │
│                         │───────────────────>│                │
 │                         │<───────────────────│                │
 │                         │                    │                │
 │                         │  CsvRowMapper      │                │
 │                         │  .mapRows()        │                │
 │                         │───────────────────>│                │
 │                         │<───────────────────│                │
 │                         │                    │                │
 │  editable chunk form    │                    │                │
 │<────────────────────────│                    │                │
 │                         │                    │                │
 │  POST /admin/import/{id}/approve             │                │
 │  (edited rows)          │                    │                │
 │────────────────────────>│                    │                │
 │                         │  ImportProcessor   │                │
 │                         │  .persistRows()    │                │
 │                         │───────────────────>│                │
 │                         │                    │  INSERT orgs   │
 │                         │                    │───────────────>│
 │                         │                    │  INSERT contacts│
 │                         │                    │───────────────>│
 │                         │                    │  INSERT calls   │
 │                         │                    │───────────────>│
│                         │  UPDATE import_session              │
│                         │  processedRows += 1 per row         │
│                         │───────────────────────────────────>│
 │  redirect → review next │                    │                │
 │<────────────────────────│                    │                │
```

## Risks / Trade-offs

- **Heuristic parsing may misclassify contacts** → Mitigated by interactive
  review; user can correct any field before approval.
- **Large multiline cells may exceed PHP memory** → CSV is read row-by-row
  with `fgetcsv()`, not loaded entirely into memory. Chunk size of 25 limits
  per-request memory.
- **Duplicate detection is name-only** → No other unique identifier in the CSV.
  The merge dialog gives the user full control.
- **No rollback on stop** → By design. Previously saved data persists, the
  stored file is kept, and the import can be resumed. The proposal explicitly
  states this.
- **A failure stops the chunk midway** → By design: rows saved before the
  failure stay, `processedRows` points at the last saved row, and the import
  resumes from there.
- **CSV replacement assumes the same record order up to `processedRows`** →
  Only the already-processed prefix is trusted; a reordered file would shift
  the resume point. The requirement only rejects shorter files, not reordered
  ones; documented as an accepted limitation.
- **Single active import session** → The proposal states only one import can be
  active. Not enforced at DB level — relies on UI flow. Could be extended by
  rejecting an upload while another session has `processedRows < totalRows`.
