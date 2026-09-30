# Design: JSON import path with a browser-side LLM client

## Context

See proposal.md — Why. This change starts from the state the parent change
`add-organizations-csv-import` leaves behind: an import page with a CSV upload
form, an import run record, a file store under `var/storage/imports/`, a
20-organization review table, a per-row transaction, a duplicate choice and a
completion notice. Everything below assumes that machinery exists; the
decisions D1–D11 of the parent design apply unchanged unless a JSON source
forces a difference, and each such difference is called out explicitly.

Two properties of the parent design are load-bearing here. The run's stored
payload is **a file**, whatever produced it, and the run records the format of
its source, so a second format is a new parser and a new value rather than a
new pipeline. And the review package is derived from the run's own progress, so
a second source needs no second notion of a position.

The fragile part of the CSV path is exactly what this change addresses: one
unstructured cell per organization holds names, positions, phones, emails and
a multi-year interaction log, and separating them is heuristic. A language
model returns the same data already separated, and additionally supplies
`industry`, `website` and `city`, which the export does not contain at all.

That last sentence is also where this design parts company with the parent in
exactly one place. The CSV format carries no city, so the parent hides the field
and its scenario asserts `city` stays null; here the city arrives pre-filled and
a human is asked to confirm it. Filling a field the reviewer cannot see is not
review, so the parent's prohibition is made format-conditional (D7) rather than
inherited wholesale. Everywhere else the parent's rules stand as written.

## Goals / Non-Goals

**Goals:**
- A second source format on the existing import page, sharing every stage after
  parsing
- One published contract driving the prompt, the download and the validation
- Accepting a model answer by paste or by file, treated identically
- Filling `industry`, `website` and `city` from the model, with the key and the
  request never reaching the server
- Keeping the human in the loop: a model answer is reviewed package by package
  like any other, and the two fields the model invents most are among the ones
  the review actually shows
- Strictness declared in the contract rather than applied downstream: a payload
  that does not satisfy the schema is refused whole, before it becomes a run, and
  nothing on this path repairs, infers or carries over a value

**Non-Goals:**
- Server-side calls to any provider, and any persistence of an API key
- Automatic submission of a model answer
- Automatic start of the import on submission — like the CSV tab, the run appears
  in the import list and «Импортировать» starts it (D3)
- A second progress model: `totalRows` counts organizations on both paths, so
  the review package, the progress indicator and the completion flash are the
  same code
- Any change to the duplicate dialog, the per-row transaction, the completion
  rule or the derived-chunk rule — these are format-neutral by construction and
  are not re-specified here
- Any heuristic on this path. There is no note-continuation, no year inference
  and no length repair, because there is nothing to repair: a model answer that
  needs repair is the wrong answer
- Storing alternative organization names to detect "same company, two rows"
  (ADR-0015 — the merge choice is the mechanism)
- Loading an answer from a URL, or detecting that a replacement answer is older
  than the one already imported

## Decisions

### D1: Two front-ends, one back-end

**Choice:** the import has two front-ends and one back-end, merging at the DTO
layer.

```mermaid
flowchart TB
    JSONTab["Вкладка JSON<br/>промпт, схема, вставка или файл"]
    LLMJS["LlmClient (JS)<br/>OpenAI-совместимый<br/>ключ только в sessionStorage"]
    JP["JsonImportParser<br/>ответ по схеме"]
    SCH["ImportJsonSchema<br/>схема — источник истины<br/>промпт, скачивание, валидация"]
    Dates["InteractionDateParser (parent D6a)"]
    DTO["OrganizationData / ContactData / CallData"]
    Proc["ImportProcessor (parent D8)"]

    LLMJS -. ответ в поле вставки .-> JSONTab
    JSONTab --> JP
    JP --> SCH
    JP --> Dates
    JP --> DTO
    SCH -. словарь полей .-> JSONTab
    DTO --> Proc
```

The JSON tab and the LLM tab converge before the server is involved: the LLM
client writes into the JSON tab's textarea and the administrator submits it
like any pasted text. The server therefore has exactly one entry point for this
path.

**Rationale:** the value of the JSON path is not a second import — it is that
contacts, calls and organization fields arrive already split, so the fragile
heuristics of the parent change (its D3) are not on this path at all. Splitting
the two paths after parsing would duplicate the hardest and least stable part of
the flow.

**Consequence:** «Следующий контакт» becomes a *planned* `Call` on this path
too, and the model's own reading of an already-past date is stored unchanged.
`response_format` reduces malformed JSON; it does not make the *content* correct,
so the package review stays the safety net (D5).

### D2: One JSON Schema document is the source of truth for three consumers

**Choice:** a single JSON Schema (draft 2020-12) document is stored in the
repository and drives all three of:

1. the field dictionary rendered inside the prompt shown on the JSON tab;
2. the file served by «Скачать JSON-схему» (`Content-Disposition: attachment`);
3. server-side validation of a pasted response.

**Rationale:** the failure this prevents is the prompt and the importer
disagreeing — the prompt tells the model a field is optional while the validator
rejects it, or the prompt documents a limit the validator does not enforce.
Deriving all three from one document makes that class of bug impossible rather
than merely unlikely. `justinrainbow/json-schema` is already present in
`composer.lock` as a transitive dependency and is promoted to a direct
requirement; no new package is introduced.

**Shape** — one object per organization, nested. The model's natural output
shape and the database's shape coincide, so no assembly step is needed:

```json
{
  "organizations": [
    {
      "name": "АбесТрейд",
      "industry": "ИТ-дистрибьютор",
      "city": "Минск",
      "website": "https://abeslab.by",
      "description": "Сертифицированный дистрибьютор ПО.",
      "coursesAttended": "",
      "contacts": [
        { "name": "Вячеслав", "position": "начальник отдела обучения",
          "phone": "+375339027636", "email": "V.Zakrevsky@naftan.by" }
      ],
      "calls": [
        { "date": "29.05.2026", "notes": "Направила КП по всем лагерям" }
      ],
      "nextContact": { "date": "08.06.2026", "purpose": "созвониться по КП" }
    }
  ]
}
```

`unp` is deliberately absent: a model invents those numbers and a wrong one is
worse than a missing one (ADR-0015). `is_main` is deliberately absent: the
export does not distinguish a primary contact, and
`MailingService::effectiveMainContact()` falls back to the lowest-ID contact.
`annualPlan` is deliberately absent for the same reason the CSV path does not
populate it. Call `date` values are carried **verbatim** — this path reuses
`InteractionDateParser` and does not impose ISO 8601, because the source dates
are not ISO and reformatting them is a lossy guess.

**The constraints live in the document, not in the parser.** `calls[].date` and
`nextContact.date` carry the same `pattern` the date grammar of the CSV tab
recognises, `maxLength` repeats the column widths, and `required` marks `name`
and `contacts[].name`. The consequences are deliberate and are the reason this
path is stricter than the CSV path:

- a date without a full unambiguous day, month and year — `31.08`, `09.04.202`,
  `09.04.20255` — is a **schema violation**, reported by organization index and
  field. It is not appended to the previous call's notes, no year is carried
  over, and no year is inferred. `InteractionDateParser::parseEntries()` and its
  note-continuation are therefore not on this path at all;
- a value longer than its column is a schema violation, not an
  `ImportRowStopped` at approval time;
- an absent optional field is null, never an empty string — the model writes what
  it knows and omits what it does not.

`InteractionDateParser::parseDate()` survives as a **normaliser**, not as a
validator: it maps `21.10.25` to 2025 and stores the call at 12:00 in the
database time zone. Its `DATE_PATTERN` and the schema's `pattern` are two
spellings of one grammar and could drift, so a test asserts that every date the
schema accepts is parsed by `parseDate()` and that `parseDate()` returns null for
none of them.

`justinrainbow/json-schema` is already present in `composer.lock`, but as a
**dev-only transitive dependency** of `friendsofphp/php-cs-fixer` and
`infection/infection` — it lives in `packages-dev`. Since `make prod-deploy`
runs `composer install --no-dev`, promoting the package to a direct
requirement must be accompanied by a regenerated lock file; without it the
validator class is absent in production. `format` keywords (`email`, `uri`) are
not used: this library treats them as annotations unless a format constraint
factory is wired in, and an unenforced keyword in a published contract is worse
than none.

### D3: One payload per run, from a paste or a file, chunked by the application

**Choice:** the JSON tab accepts a response containing any number of
organizations, supplied either as pasted text or as an uploaded file. The two
are the same thing from that point on: the application counts the
`organizations` array, writes the payload to the run's stored file, sets
`totalRows` to the array length, records `sourceFormat = json`, and then
**returns the administrator to the import list**, where the new run appears as a
row of its own. Parsing into DTOs and building a review package do **not** start
on submission: they start when the administrator presses «Импортировать» in that
row, exactly as on the CSV tab. `processedRows` therefore counts organizations,
not CSV records, on both paths.

**Rationale:** the user should not have to split a several-hundred-organization
answer into twenty pieces by hand. `totalRows` keeps its meaning —
organizations — so the progress indicator, the "Продолжить" link and the
completion flash behave identically, and `ImportRun` needs no second notion of
row count.

The split between *submitting* and *importing* is inherited deliberately rather
than re-decided. The parent's reason holds verbatim: counting records and
checking the format are cheap and happen on submission, so an unusable payload
is rejected before it becomes a run, while DTO construction is deferred so that a
pasted answer does not start parsing 300 organizations the moment it is
accepted. It also keeps `ImportRunRepository::findUnfinished()` meaningful — a run
created but not yet imported is an active run under the parent's D9 rule on both
tabs, and the JSON tab does not get a stricter single-active-run regime of its
own.

The run's `filename` is the uploaded file's client name, or `ответ.json` for a
pasted answer, because the run list column «Файл» must name something.

Accepting a file as well as a paste costs one form field and buys three things:
an answer produced earlier can be re-imported without being re-copied through
the clipboard; the payload is a file like any other, so the replacement flow is
available with no extra mechanism; and a large body of text has somewhere to go
other than a textarea.

**Cost:** one bad character invalidates the whole payload, so the validator
reports the offending organization by index and field rather than failing
silently. This is accepted: a per-chunk submission protocol moves the failure
handling burden onto the user for every chunk instead of once.

### D4: The LLM call is made by the browser, the key never reaches the server

**Choice:** a small JavaScript client calls the provider directly from the
page. Both supported providers are OpenAI-compatible, so one client with a
`{ baseUrl, apiKey, model }` configuration serves both:

| | OpenRouter | Ollama |
| --- | --- | --- |
| Endpoint | `POST https://openrouter.ai/api/v1/chat/completions` | `POST http://<host>:11434/v1/chat/completions` |
| Auth | `Authorization: Bearer <key>`, plus `HTTP-Referer` / `X-OpenRouter-Title` for attribution | any value, ignored |
| Model list | `GET /api/v1/models` | `GET /api/tags` |
| Structured output | `response_format: { type: "json_schema", json_schema: { … } }` | supported through the OpenAI-compatible route |

The key is held in a JavaScript variable backed by `sessionStorage`, never
`localStorage`, with an explicit «Забыть ключ» action. The response is written
into the JSON tab's textarea and validated by the same schema as a manual paste.

**Rationale:** keeping the key client-side removes the entire class of concerns
a server-side integration carries — no new entity, no secrets in the vault, no
key in backups, no audit log to leak, and no dependency on
`symfony/http-client`. The loss is a server-side record of what was sent, which
matters little here because the import is still reviewed organization by
organization and recorded through `ImportRun`.

Two consequences the specification must state:

- an Ollama host must set `OLLAMA_ORIGINS` to the CRM's origin, or the browser
  blocks the request; this is a deployment prerequisite, not an application
  setting;
- a browser-held key is exposed to XSS and to anyone with access to the
  workstation. This is why the key is session-scoped rather than persisted and
  why it is never written to a form field the server can read.

**Alternatives considered:**
- Server-side provider calls: would allow audit and rate limiting, but needs
  key storage, a new dependency and a new surface for a secret. Rejected for a
  one-shot migration tool.
- Copy-paste into the user's own chat application: already supported by D3 —
  the tab works with no key at all. The built-in client exists for convenience
  and for `response_format`, which makes a malformed response impossible.

### D5: LLM output that cannot be trusted is still reviewed

**Choice:** this path reuses the package review form unchanged, except for the
two fields the parent table does not have at all (D7). Every organization
arrives in an editable form where the administrator can correct fields, add or
remove contacts and calls, and remove a row. `response_format` reduces
malformed JSON; it does not make the *content* correct.

**Rationale:** a model filling `industry` and `website` from public sources will
occasionally produce a plausible wrong value, and a several-hundred-row import
is not a thing the user wants to reverse afterwards. The review form is the same
safety net the CSV path already relies on for heuristic mis-parsing, which is
why the two paths were merged at the DTO layer in the first place. This is also
why D7 exists: a safety net that does not render the field it is supposed to
check is not a safety net.

### D6: A JSON run replaces its file with the same report, compared structurally

**Choice:** the replacement flow needs no new mechanism and no new method. It is
the parent's `ImportController::replace()` and its private
`finishReplacement()`, with one thing changed: the candidate travels in the URL
(`/admin/import/{id}/replace?candidate=<storageKey>`), so the report is
reproducible from its address and nothing about the pending replacement is held
between the two requests — that rule is load-bearing and is inherited, not
reinvented. On confirmation the run switches to the new payload, `filename` and
`storageKey` are replaced, **the file the run pointed at before is deleted**
(the parent's D5/D8, and the only place the import flow deletes a file), and a
candidate whose organization count is below `processedRows` is confirmed with a
warning and completes the run. For a JSON run, two organizations differ when
they differ in any field, independent of key order and of how the payload is
written.

**Rationale:** a textual diff of two JSON payloads reports every line as changed
when nothing changed in the data, which would make the report noise. The
parent's rule — trusted state is the row numbers plus the retained previous
file — holds unchanged, and so does the exclusion of timestamps: a browser
supplied payload carries no client timestamp, and a replaced run's organizations
cannot be mapped back to rows.

**What the implementation actually needs here.** `ImportController::replacementReport()`
is presently written against `CsvParser::recordText()` and
`CsvParser::organizationName()` directly, so the format dispatch has to be lifted
out of the controller before a JSON run can be reported at all: the record source,
the row-comparison and the name-at-resume-row all become format-dependent. This
is in addition to `ImportProcessor::processChunk()` and `persistRows()`, which
are the dispatch points the parent's design names. The comparator lives beside
the parsers rather than in the controller, so a third format would add one class
instead of a third branch.

**Consequence:** a replacement of a JSON run validates against the published
schema, accepts a paste or a file, and renders the same confirmation page with
the counts in organizations, the resume row and its organization, the differing
positions within the processed prefix, and the shorter-file warning. The
single-active-run check still does not apply to a run's own replacement.

### D7: The review table shows `industry` and `city` for a JSON run, and hides them for a CSV one

**Choice:** `industry` and `city` become reviewable **as a function of the run's
source format**. A run created from JSON renders two extra columns and accepts
edits to them; a run created from CSV renders neither, and `city` stays null
exactly as the parent's requirement says.

```
                     review table
              +---------------------+---------------------+
              |  run.sourceFormat   | "Отрасль" "Город"   |
              +---------------------+---------------------+
              |  csv                |  нет                |  <- parent, unchanged
              |  json               |  да                 |  <- this change
              +---------------------+---------------------+

              save path:  OrganizationData -> ImportRow -> newOrganization()
                          needs industry + city on both hops
```

**Rationale:** the alternative is to fill two fields the reviewer cannot see.
D5 names a plausible wrong `industry` or `city` as the reason a human stays in
the loop, and an import that stores an invented city for three hundred
organizations is exactly the outcome the review exists to prevent. The parent's
prohibition was not "a city column is forbidden" but "there is no city in the
source format, so there is nothing to pre-fill and nothing to review" — on the
JSON tab that premise no longer holds. Keeping the prohibition conditional
rather than lifting it globally preserves the CSV rationale verbatim, including
its scenario «В прогоне нет города».

**Consequence:** `OrganizationData` and `ImportRow` gain two optional fields,
`newOrganization()` sets them, `ImportRow`'s length checks cover them, and the
controller reads them from the form only when the run's format asks for them — a
CSV review form carries no such inputs, so a CSV row cannot acquire a city by
form tampering. The review template needs the run's `sourceFormat`, which it does
not currently receive.

### D8: The three new routes are literal-first, because `/{id}` already exists

**Choice:** the new routes are declared on the existing controller with an `id`
requirement that keeps `/{id}` from swallowing them:

```php
#[Route('/{id}', requirements: ['id' => '\d+'])]          // existing review
#[Route('/json',  requirements: ['id' => '0'])]           // new JSON tab
#[Route('/json-schema', requirements: ['id' => '0'])]    // new schema download
#[Route('/llm',   requirements: ['id' => '0'])]           // new LLM tab
```

**Rationale:** `ImportController` already serves `/admin/import/{id}` for the
review, and `debug:router` confirms it today. Three new literals that collide
with it would 404 before any method of this change ran, and the failure would
look like a missing action rather than a routing collision. The parent's own
route already carries a `requirements` argument for the same reason, so the fix
is the established pattern in this file rather than a new mechanism.

**Alternative considered:** renaming `{id}` to a fixed segment. Rejected — it
would churn the parent's URLs, its spec scenarios and its bookmarkable pages for
no behavioural gain.

## Architecture

### Component Diagram

*Assumptions:* purpose = design for an existing monolith, following the parent
change; format = plain Mermaid `flowchart`; rigor = lightweight C4-inspired
(container + key components only). `totalRows` counts organizations on both
paths. Components inside the browser are shown with the server components they
talk to, because the trust boundary between them is the point of the diagram.

```mermaid
flowchart TB
  subgraph Browser["Browser — администратор"]
    ImportPage["Страница импорта<br/>вкладка CSV, вкладка JSON, вкладка LLM"]
    LLMJS["LlmClient (JS)<br/>OpenAI-совместимый<br/>ключ только в sessionStorage"]
  end

  subgraph Provider["Внешний провайдер (не входит в приложение)"]
    OR["OpenRouter"]
    OL["Ollama"]
  end

  subgraph App["Symfony — ROLE_ADMIN"]
    IC["ImportController<br/>list, json, jsonSchema, llm,<br/>review, approve, replace<br/>(finishReplacement — родительский)"]
    ST["ImportFileStorage (parent D5)<br/>var/storage/imports<br/>хранит; удаляет прежний файл при подтверждении замены"]
    SCH["ImportJsonSchema<br/>схема — источник истины<br/>промпт, скачивание, валидация"]
    JP["JsonImportParser<br/>ответ по схеме, дата через parseDate"]
    CMP["RowComparator (рядом с парсерами)<br/>csv: текст записи · json: поля по очереди"]
    IDP["InteractionDateParser (parent D6a)"]
    DTO["OrganizationData / ContactData / CallData<br/>+ industry, city"]
    IP["ImportProcessor (parent D8)<br/>пакет не более 20, транзакция на строку"]
    RV["Таблица проверки<br/>«Отрасль»/«Город» только для json"]
    IR["ImportRunRepository"]
  end

  DB[(MySQL<br/>import_run, organization, contact, call)]

  ImportPage --> IC
  ImportPage --> LLMJS
  LLMJS --> OR
  LLMJS --> OL
  LLMJS -. ответ в поле вставки .-> ImportPage

  IC --> ST
  IC --> JP
  IC --> SCH
  IC --> IP
  IC --> IR
  JP --> SCH
  JP --> IDP
  JP --> DTO
  DTO --> IP
  IP --> DB
  IR --> DB
  ST --> DB
```

### JSON submission and replacement (Dynamic)

The review, approval and failure paths are the parent's and are not repeated
here; what is new is how a JSON run gets created and how it is replaced.

```mermaid
sequenceDiagram
    actor Admin as Администратор
    participant Page as Вкладка JSON
    participant LLMJS as LlmClient (JS)
    participant IC as ImportController
    participant SCH as ImportJsonSchema
    participant ST as ImportFileStorage
    participant DB as MySQL

    Admin->>Page: нажимает «Отправить» (или вставляет вручную)
    LLMJS->>LLMJS: ключ из sessionStorage
    LLMJS-->>Page: ответ провайдера в поле вставки, без отправки
    Admin->>Page: подтверждает вставку
    Page->>IC: POST json (текст или файл)
    IC->>SCH: validate(payload)
    alt нарушения схемы
        SCH-->>Page: отчёт по индексу организации и полю, прогон не создан
    else годен
        IC->>ST: store(payload как файл)
        IC->>DB: INSERT import_run (sourceFormat = json,<br/>totalRows = len(organizations), processedRows = 0)
        IC-->>Admin: 302 к списку импортов, прогон — отдельной строкой
    end

    Note over Admin,DB: разбор в DTO и пакет для проверки здесь НЕ начинаются:<br/>«Импортировать» в строке списка запускает их (D3, как на вкладке CSV)

    Admin->>IC: «Импортировать» в строке прогона
    IC->>DB: пакет организаций processedRows+1 .. min(+20, totalRows),<br/>колонки «Отрасль» и «Город» включены (D7)
    Note over Admin,DB: дальше — транзакция на строку, диалог дубликата<br/>и итоговое сообщение общие (parent D8)

    Admin->>IC: POST replace (новый ответ — вставкой или файлом)
    IC->>SCH: validate(новый ответ)
    alt нарушения схемы
        IC-->>Admin: отчёт, текущий файл не изменён
    else годен
        IC->>ST: сохранить новый файл-кандидат
        IC-->>Admin: 302 на ?candidate=<storageKey> — отчёт по адресу,<br/>без состояния между запросами
        Admin->>IC: GET replace?candidate=... / POST confirm
        IC->>IC: сравнить организации 1..processedRows по полям (CMP)
        IC-->>Admin: страница подтверждения: числа, строка продолжения<br/>и её организация, изменившиеся позиции,<br/>предупреждение если файл короче processedRows
        Admin->>IC: подтверждение
        IC->>ST: удалить прежний файл прогона
        IC->>DB: totalRows = новое число, processedRows сохранён
        IC-->>Admin: 302 на проверку, уведомление о строке и организации
    end
```

## Risks / Trade-offs

- **A model fills `industry`, `website` or `city` with a plausible wrong
  value** → Mitigated by the review form (D5, D7), not by validation: the schema
  checks shape, not truth. `industry` and `city` are rendered and editable for a
  JSON run precisely so that "visible and correctable per row" is literally true
  for the fields a model is most likely to invent.
- **Strictness rejects a payload a human could have repaired** → Accepted, and it
  is the point: a date like `31.08` is not repairable without guessing the year,
  and a guessed year is wrong data written silently. The rejection names the
  organization index and the field, and the answer can be re-imported as a
  replacement file at no cost.
- **The schema's date `pattern` drifts from `InteractionDateParser::DATE_PATTERN`**
  → Pinned by a test that feeds every date shape the schema accepts through
  `parseDate()` and asserts none returns null, in both directions.
- **`justinrainbow/json-schema` is a dev-only package today** → The promotion is
  incomplete without a regenerated `composer.lock`; `make prod-deploy` installs
  `--no-dev`. A task verifies the class loads under `--no-dev`.
- **A browser-held API key is exposed to XSS and to a shared workstation** →
  Mitigated by scope, not removed: the key lives in `sessionStorage` only,
  `localStorage` is not used, it is never written into a field the server can
  read, and «Забыть ключ» clears it. The user is told this in the UI.
- **Ollama may refuse browser requests** → A deployment prerequisite: the Ollama
  host needs `OLLAMA_ORIGINS` set to the CRM origin. Not detectable from
  application code, so it is stated in the requirement and the setup notes.
- **One bad character invalidates a whole JSON paste** → Accepted (D3). The
  validator names the offending organization by index and field, so the user
  fixes one character rather than re-splitting twenty packages.
- **The prompt and the schema can still drift if the prompt is edited by hand**
  → The prompt's field dictionary is rendered from the schema document, and a
  test asserts that every schema property's description appears in the rendered
  prompt, so an edit to the prompt text alone cannot remove a field. The same
  rendering is where the model is told the constraints, so an unenforced field
  and an undescribed field are the same defect.
- **`response_format` support differs between providers** → Degraded to
  best-effort: if a provider ignores it, the answer still arrives as text and
  the same server-side schema validation reports any violation, which is the
  path a manual paste takes.
- **A replacement answer is compared positionally** → Accepted and stated in the
  requirement: a reordered answer moves the resume point, and the report lists
  the differing positions within the processed prefix so the admin sees it. The
  report informs, it does not block; content that changed inside the prefix
  stays as originally inserted and correcting it is a manual edit.
- **No rollback on stop** → By design, inherited from the parent: previously
  saved data persists, the stored file is kept, and the import can be resumed.

## Migration Plan

None. `import_run.source_format` is created by the first migration of
`add-organizations-csv-import`, defaulting to `csv`; this change writes
`json` into it and selects the parser by that value. Deploy order is therefore
parent change first, this change second, with no schema work in between.

Rollback: remove the second tab, the LLM client and the three routes, and drop
`industry`/`city` from the review table again. Runs created from JSON keep their
payload and their `source_format` value, so a CSV-only build would fail to parse
them — the run list is unaffected, but such a run cannot be resumed on a
rolled-back build. Organizations already imported keep whatever `industry` and
`city` they were given, since those columns already exist (ADR-0015) and no
migration removes them. No data is lost either way, because a run's current
payload is only ever deleted when an admin has confirmed its replacement.

Deploy order is therefore parent change first, this change second, with no
schema work in between — but note the `composer.lock` regeneration, which is not
schema work and is easy to overlook precisely because no migration is involved.

## Open Questions

- Whether a provider that rejects `response_format` should fall back to
  requesting the schema inside the prompt text. It can be answered without
  changing the specs, the approach, or the task breakdown: the current path
  already degrades to the same server-side validation, and the rendered prompt
  already carries the field descriptions.
- Whether `industry` and `city` should later appear in the CSV review table as
  empty editable inputs. Not this change: with nothing to pre-fill from the
  export, the parent deliberately keeps them out, and a CSV row has no reason to
  offer a city field.
