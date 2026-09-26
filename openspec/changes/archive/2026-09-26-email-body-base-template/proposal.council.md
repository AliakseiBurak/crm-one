# Council Notes: email-body-base-template / proposal

## Author Summary

Adversarial authoring **could not be completed**: the `adversarial-author` and
`adversarial-reviewer` subagents both fail in this environment with
`OpenCode's free tier can only be used from within OpenCode`. The user was asked
how to proceed and chose primary-agent authoring. The draft below is therefore
primary-agent work, self-challenged against the verified evidence rather than
challenged by a second model.

The author round re-read the relevant sources before writing:
`templates/emails/campaign.html.twig` (full shell, 61 lines),
`src/Service/CampaignEmailRenderer.php`, the `applyRequest()` and `previewLive()`
paths in `src/Controller/CampaignController.php`, `templates/campaign/form.html.twig`
(the `textarea[name="body"]` default at lines 104-109),
`assets/js/campaign-editor.js`, `config/packages/html_sanitizer.yaml`,
`src/Html/CampaignStyleAttributeSanitizer.php`, `adr/0014`, and the two affected
e2e specs.

The user's scope correction ("Идея простая… предустановленный шаблон в форме
создания… doctype, base CSS и visibility pixel может остаться в twig template")
replaced an earlier, more complex reading in which the whole document — including
doctype and `<head>` — would live in the editable field.

## Reviewer Challenges

These are the challenges a reviewer subagent would have raised, derived from the
evidence rather than from a second model.

- **C1 — The default template can be silently destroyed by the sanitizer.** The
  current footer link is `http://trainingcenter.by/catalog`, but
  `allowed_link_schemes` is `['https', 'mailto', 'tel']`. A prepopulated default
  that loses its `href` on first save is a broken promise. The owner confirmed
  that `https`, `mailto` and `tel` are the schemes that must survive; the
  allowlist is a given, not something to widen.
- **C2 — Two e2e specs assert the old architecture and will fail.** They are
  consequences of the change, not pre-existing breakage, and must be named in
  Impact so the apply phase fixes them instead of rediscovering them.
- **C3 — `adr/0014` decision 3 is contradicted.** It states the Twig shell is the
  complete email document including the footer with the unsubscribe link, and is
  the single source of truth. Leaving the ADR contradicting the code breaks the
  repo's "OpenSpec is the single source of truth" rule.
- **C4 — Ambiguity between "footer moves into the body" and "renderer stops
  wrapping".** Only the footer moves. The renderer keeps wrapping the body in the
  remaining shell, otherwise `inline_css` has no `<style>` to inline and the
  600px layout loses its presentation.
- **C5 — The preheader is a trap.** It is invisible in the mail client, so
  "весь видимый контент" does not cover it. Moving it would delete the
  `preview_text` field and contradict the «Создание рассылки» requirement for no
  user-visible gain.
- **C6 — Prepopulating on create changes validation semantics.** The existing
  scenario «создание рассылки с валидацией обязательных полей» expects
  «Текст письма обязателен» from an untouched form; with a prepopulated body that
  error can no longer fire.
- **C7 — Scope creep risk.** Earlier answers in the session pointed at a
  full-document field with new tokens (`{{subject}}`, `{{preview_text}}`,
  `{{tracking_pixel_url}}`) and a read-only visual frame. The user's final
  message rejects that complexity; those must not leak into the artifacts.
- **C8 — "Migration started from scratch" is ambiguous.** It could mean "backfill
  existing bodies" or "do not consider existing data". Interpreted as the latter,
  per the explicit "Do not consider existing content", and the consequence is
  recorded rather than silently absorbed.

## Resolutions

- Accepted: **C1** — the `https` correction for the catalog link is a named bullet
  in «What Changes» with the sanitizer config as the reason, so the apply phase
  cannot ship a default that loses its href.
- Accepted: **C2** — both specs are named in Impact with the specific assertions
  that become wrong.
- Accepted: **C3** — `adr/0014` is named in Impact as requiring an amendment.
- Accepted: **C4** — «What Changes» states the shell keeps doctype, `<head>` with
  base CSS, `<title>`, the preheader and the tracking pixel, and that the renderer
  still wraps and still applies `inline_css`.
- Accepted: **C5** — the preheader stays a separate `preview_text` field, stated
  explicitly with the reason, so the next reader does not re-open it.
- Accepted: **C6** — recorded in Impact as a spec-visible consequence; the
  validation requirement itself is not weakened, only the way its scenario is
  exercised.
- Accepted: **C7** — no new tokens, no read-only frame, no editor schema change.
  The body stays a plain fragment the existing TipTap schema already handles.
- Accepted: **C8** — no backfill, no renderer-level backward compatibility for
  fragment bodies; the consequence is stated in Impact as an accepted trade-off.
- Rejected: adding a renderer fallback that re-inserts the footer when the stored
  body has none. It would silently resurrect content the user deliberately
  deleted, contradicting the decision to make the footer editable and removable.
- Rejected: a new capability. This modifies `campaigns`; a separate capability
  would fragment one user-visible workflow.
- Deferred: CID-embedded images and markdown conversion of the text part — both
  were already deferred by `adr/0014` and are untouched by this change.

## Remaining Risks

- The prepopulated default couples three things that can drift independently: the
  body markup, the shell CSS in `<head>`, and the sanitizer allowlist. A future
  edit to any one of them can silently break the default. design.md must state
  this invariant and the test that guards it.
- Because the default is a large markup blob, its round-trip through the TipTap
  visual mode is a real risk (table serializer, `style` attribute normalization,
  the logo `img` with `width`). The consolidated round-trip e2e test from
  `adr/0014` is the guard and must be extended, not duplicated.
- Existing campaigns silently lose the footer. If production data matters before
  this ships, a backfill is a one-line change to revisit — the decision is
  recorded, not hidden.
