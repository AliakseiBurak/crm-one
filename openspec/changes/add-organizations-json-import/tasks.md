# Tasks: JSON import path with a browser-side LLM client

> Requires `add-organizations-csv-import` to be implemented first: this change
> reuses its import page, `ImportRun`, `ImportFileStorage`, the review table,
> the per-row transaction, the duplicate choice and the completion notice. It
> adds **no** migration.

## 1. JSON Schema as the single source of truth

- [ ] 1.1 Write `resources/import/organization-import.schema.json`: a draft 2020-12 schema with a required `organizations` array and the fields `name` (required, maxLength 255), `industry`, `city`, `website` (maxLength 255), `description`, `coursesAttended` (maxLength 255), `contacts[]` (`name` required, `position`, `phone` maxLength 32, `email`), `calls[]` (`date`, `notes`), `nextContact` (`date`, `purpose`). It SHALL contain no `unp`, no `is_main` and no `annualPlan`. Each property SHALL carry a description. Verify: a test asserts the forbidden keys are absent and every property has a description.
- [ ] 1.2 Promote `justinrainbow/json-schema` from a transitive to a direct requirement in `composer.json`. Verify: `composer validate` passes and the class autoloads.
- [ ] 1.3 Create `ImportJsonSchema` service: `path()`, `raw(): array`, `fieldDictionary(): array` (property name → description), `validate(array $payload): JsonValidationResult`. The result SHALL list every violation as `{organizationIndex, field, message}`. Verify: test — a payload with an empty `name` at index 4 and a 40-character `phone` at index 6 reports both with the correct indices.
- [ ] 1.4 Render the JSON tab prompt from `fieldDictionary()` so the prompt and the downloadable schema cannot drift. Verify: a test asserts each schema property description appears in the rendered prompt.

## 2. JsonImportParser

- [ ] 2.1 Parse the submitted payload — pasted text or uploaded file, handled identically: `json_decode`, then validate against `ImportJsonSchema`. Report every violation with the organization index and field; do not create a run. Verify: a test asserts a non-JSON payload and a schema-violating payload both fail with a report and leave no run, and that the same payload submitted as a file produces the identical report.
- [ ] 2.2 Map a valid payload to the same `OrganizationData` / `ContactData[]` / `CallData[]` DTOs the CSV path uses, with `calls[].date` passed verbatim to `InteractionDateParser` — no ISO coercion. `nextContact` becomes a planned `Call`. A missing or empty optional field yields null, never an empty string. `annualPlan` is not mapped. Verify: a test asserts `17/09/2025` is stored as 17.09.2025, that `31.08` stays in the notes and produces no dated call, and that an absent `industry` is null.
- [ ] 2.3 Dispatch by the run's `sourceFormat` in `ImportProcessor::processChunk` and `persistRows`, so a run created from CSV is parsed as CSV and a run created from JSON as JSON regardless of how the page was reached. Verify: a test creates a run of each kind, reopens each from the run list, and asserts each is parsed by its own parser.
- [ ] 2.4 Store the payload as the run's file — written to disk whether it was pasted or uploaded — and set `sourceFormat` = `json`, `totalRows` to the length of `organizations`, `processedRows` = 0. Verify: a test pastes 120 organizations and asserts totalRows = 120 and that the stored file holds the payload verbatim.

## 3. Controller + routes

- [ ] 3.1 Add routes to the existing admin import controller: GET `/admin/import/json`, POST `/admin/import/json`, GET `/admin/import/json-schema`, GET `/admin/import/llm`, all under the controller's existing `#[IsGranted('ROLE_ADMIN')]`. There is no new conflict route and no new chunk-position parameter. Verify: `php bin/console debug:router | grep import` lists them and a manager is refused with 403.
- [ ] 3.2 `json()`: accept the answer as pasted text or as an uploaded file and treat both identically; reject while a *different* run has `processedRows < totalRows`; otherwise validate, store, set `sourceFormat` = `json` and `totalRows` to the array length, redirect to the review of the first package. A run's own replacement is not a submission and SHALL NOT be rejected by that check. Verify: a test pastes a valid payload and asserts the run; a test uploads the same payload as a file and asserts an identical run; a test pastes an invalid one and asserts rejection with the violation report and no run.
- [ ] 3.3 `jsonSchema()`: serve `resources/import/organization-import.schema.json` with `Content-Disposition: attachment`. Verify: a functional test asserts the download headers and that the body validates against the published contract.
- [ ] 3.4 Extend `replace()` and `confirmReplace()` for a JSON run: accept the replacement as pasted text or an uploaded file, validate it against the published schema, build the report (organization counts before/after, resume row, the organization at it, and the positions within `1..processedRows` that differ in any field regardless of key order or payload formatting), render the confirmation page, and mutate the run only on confirmation. The single-active-run check SHALL NOT reject a run's own replacement. No timestamp or recency information is reported. Verify: tests assert the run is unchanged before confirmation, that a reordered answer reports no differing positions, that confirming sets `totalRows` to the new count and keeps `processedRows`, that an invalid replacement never reaches the confirmation page, and that a CSV run's replacement is unaffected.

## 4. Templates

- [ ] 4.1 Add a tabs component to `assets/scss/components/` and a `tabs` partial; render «CSV» and «JSON» on the import page with «CSV» active. Verify: the page renders both tabs and switching works.
- [ ] 4.2 JSON tab template: the prompt in a copyable block, the «Скачать JSON-схему» link, the paste field, and a file field for uploading a response. Verify: renders, the download works, and both input paths are present.
- [ ] 4.3 LLM tab template: provider select, host field for Ollama, key field, model select with «Получить модели», send button, the key-visibility warning and the «Забыть ключ» action. Verify: renders and the warning is present.
- [ ] 4.4 Extend the confirmation template for a JSON run's replacement with the report in organizations, resume row and its organization, differing positions, and both actions; no recency or timestamp information. Verify: renders the report rows and both buttons.

## 5. LLM client (browser-side)

- [ ] 5.1 Implement the OpenAI-compatible browser client in `assets/js/`: `{ baseUrl, apiKey, model }`, `listModels()`, `send(messages, schema)`. Used by both providers. Verify: no request in the JS targets an application route; the only outbound calls are to the provider.
- [ ] 5.2 Provider presets: OpenRouter (`https://openrouter.ai/api/v1`, `Authorization: Bearer`, `HTTP-Referer`, `X-OpenRouter-Title`, models at `/api/v1/models`) and Ollama (`http://<host>:11434/v1`, any key, models at `/api/tags`). Verify: each preset issues the documented request shape.
- [ ] 5.3 Send with `response_format` carrying the published JSON Schema, and write a successful response into the JSON tab's paste field without submitting it. Verify: a test asserts the response lands in the field and no import run is created.
- [ ] 5.4 Hold the key in a JavaScript variable backed by `sessionStorage`; never use `localStorage`, never send it to the application, and provide «Забыть ключ». Verify: a test asserts the key is absent from `localStorage` and from every request to the application.
- [ ] 5.5 Handle a provider failure — including a browser CORS block from an Ollama host without `OLLAMA_ORIGINS` — with a message in the tab, leaving the import untouched. Verify: a test asserts the error surfaces.
- [ ] 5.6 Document the `OLLAMA_ORIGINS` prerequisite in the deployment notes. Verify: the note exists.

## 6. Integration + quality gates

- [ ] 6.1 Functional tests: the full JSON flow end to end, creating and then removing its own data, and a test that a CSV run is unaffected by the second tab. Verify: `php bin/phpunit` passes.
- [ ] 6.2 E2E (Playwright): admin reaches the import, sees both tabs, submits a response by file as well as by paste, downloads the schema, and runs a small import; a manager is refused. Per the repo conventions: login-first, no hardcoded IDs, locate by text, delete what the test created. Verify: `cd e2e && npx playwright test` passes.
- [ ] 6.3 `make lint`, `make phpstan`, `make test` clean. Verify: all pass.
