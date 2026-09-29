# Council Notes: proposal

## Author Summary
Draft produced by the primary agent after the author subagent failed. The
proposal scopes a second import path on top of `add-organizations-csv-import`:
a `JSON` tab beside `CSV`, one published JSON Schema driving the prompt, the
download and the validation, acceptance of a response by paste or file, a
browser-side LLM client for OpenRouter and Ollama with the key never reaching
the server, and the structural row comparison for replacing a JSON run. It
declares `llm-assistant` as the only new capability and `organizations-import`
as the only modified one, and states that no migration is required.

## Reviewer Challenges
- Author round could not run as configured: dispatching the `adversarial-author`
  subagent fails on this machine with `OpenCode's free tier can only be used
  from within OpenCode`. No reviewer round was possible, so the proposal is
  single-perspective — the same condition recorded in
  `add-organizations-csv-import/proposal.council.md`.
- `config.yaml` also requires the `grill-with-docs` skill for a proposal. Its
  interview did not run for the same reason: the decisions this change inherits
  were made interactively in the preceding exploration and are recorded in the
  parent change, so a fresh interview would re-ask settled questions.

## Resolutions
- Proceeded with primary-agent authoring, as the parent change did, and recorded
  the unavailable rounds here rather than leaving a silent gap.
- Kept `organizations` out of Modified Capabilities: the fields exist after
  `add-organizations-csv-import` and B only changes where their values come from,
  which is not spec-level behaviour of that capability.
- Moved the class-level breakdown, route lists and the per-shape date grammar out
  of the proposal; they belong to `design.md` and the spec deltas.
- Stated the dependency on `add-organizations-csv-import` in Impact rather than
  treating this change as standalone.

## Remaining Risks
- Single-perspective authoring. The proposal was checked against the parent
  change's decisions, but no independent challenge round reviewed it.
- Merge-collision rules for the JSON path (what happens to description content of
  an existing organization when a row merges into it) are not pinned in a
  scenario here; the parent change's duplicate requirement covers merge
  behaviour, and the ambiguity noted in the parent council file still stands.
- The scope of "B inherits what it can" was interpreted as: the parent's rules
  stand unchanged unless a JSON format makes them insufficient. If the JSON path
  needs a different chunk, progress or duplicate rule, that is a change to the
  parent requirement, not a parallel one.
