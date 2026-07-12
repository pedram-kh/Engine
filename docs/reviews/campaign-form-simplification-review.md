# Campaign-creation form simplification (AH-032) — Build Review

**Status: Closed**
**Date:** 2026-07-12
**Scope:** Single ad-hoc chunk (full loop). Form/validation/i18n only — no schema change, no API-response-shape change (the contract relaxes, never breaks). Admin untouched.

## Goal

Reduce the campaign create/edit form to the fields agencies actually use. Remove the `objective` select (server defaults `ugc`), the write-only `target_creator_count`, and the never-rendered structured brief block (`deliverables` / `hashtags` / `usage_rights`). Fold the prose role of usage rights + deliverables into `description`. The inventory in the kickoff thread established the evidence base: **nothing anywhere reads the campaign brief back** (agency, creator, or admin), counter is fee-only as shipped, and verification is pure URL/handle matching — so folding these fields regresses nothing.

## Per-decision evidence

### D1 — Objective leaves the form; server defaults `ugc`

- `CreateCampaignRequest`: `'objective' => ['sometimes', new Enum(CampaignObjective::class)]` + `prepareForValidation()` merging `CampaignObjective::Ugc->value` when absent/null/empty. Enum, column, `CampaignResource` emission, and the Overview-tab display row are all untouched.
- Form: the `<v-select campaign-objective>`, `objectiveOptions`, `objectiveErrors`, and the `CampaignObjective` type import removed from `CampaignForm.vue`; `objective` dropped from `CampaignCreatePage.emptyForm()`.
- **Contract only relaxes:** an explicit valid objective in a payload still validates against the enum and is honored. Both directions are tested (omitted → `ugc`; explicit `awareness` → honored).

### D2 — Target creator count leaves the form

- `<v-text-field campaign-target-count>` removed. Backend column, `sometimes|nullable` validation, and Resource emission untouched — write-only became API-only. No backend change (omission preserved by `sometimes`).

### D3 — Brief leaves the form; the form stops sending `brief`

- The three brief inputs and `assembleBrief()` removed; `onSubmit()` no longer reassembles `brief`. `CampaignDetailPage.seedEditForm()` no longer seeds `objective`/`target_creator_count`/`brief` (Q1 Option A — re-seeding would revive the overwrite path).
- Backend brief validation and Resource emission untouched. On edit, omission + `sometimes` preserves the stored blob byte-identical.

### D4 — Description absorbs the prose role

- `persistent-hint` bound to the new key `app.campaigns.fields.descriptionHint`; `rows` 2→3; `class="mb-3"` added to clear the following bare `d-flex` budget row (the hint was crowding the budget field's floating label). `max:5000` unchanged (no evidence surfaced to raise it).

### D5 — i18n orphan cleanup

- Removed `fields.{targetCreatorCount,deliverables,deliverablesHint,hashtags,hashtagsHint,usageRights}` and the independently-orphaned `board.drawer.detail.deliverables` across all 24 locales.
- Added `fields.descriptionHint` ×24 (real MT baseline for the 10 flaky locales — not English fallback).
- **`fields.objective` + the `objective.*` block are kept** — the Overview tab consumes them as its row title/labels. Scoped-diff proof (`en`): objective label retained, orphans gone, `descriptionHint` present.

## The wipe-bug — fixed by omission, not by design

The shipped form rebuilt the entire `brief` jsonb from only its three visible inputs on every save, silently wiping any other stored sub-keys (`dos`/`donts`/`mentions`/`links`/`attachments`) written by any other path. Removing the inputs so the form stops sending `brief` eliminates the wipe **as a side effect of the simplification — by omission, not a deliberate merge fix**. A named regression test pins this as an invariant (see Coverage). The forward-guard is recorded in `tech-debt.md` so a future brief editor can't reintroduce the class.

## Coverage (§5.34 negative-case; §5.35 break-revert)

| Area                                          | Test                                                                                                                                                              | Result                          |
| --------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------- |
| Objective default (D1)                        | `defaults a missing objective to ugc on create` (JSON path + persisted enum)                                                                                      | ✓                               |
| Contract relaxes (D1)                         | `honors an explicit objective on create` (`awareness` persisted + emitted)                                                                                        | ✓                               |
| Brief preservation (D3, §5.34)                | `preserves the stored brief byte-identical when the edit omits it` — whole blob `toBe($storedBrief)` + `dos`/`donts`/`mentions`/`links`/`attachments` each pinned | ✓                               |
| Existing brief/objective/budget create + edit | `CampaignCrudTest` (unchanged assertions)                                                                                                                         | ✓ (16 total)                    |
| FE campaigns                                  | `CampaignForm` host pages, list/detail specs                                                                                                                      | ✓ (68, **0 spec edits needed**) |

### Break-revert (§5.35 — verbatim)

Forced a wipe in the controller (`$updates['brief'] = null;`) → the preservation test went red on the byte-identity assertion, with the full rich-brief array in the failure output:

```
 FAIL  Tests\Feature\Modules\Campaigns\CampaignCrudTest
  ⨯ it preserves the stored brief byte-identical when the edit omits it…  0.47s
  Failed asserting that null is identical to Array &0 [
    'deliverables' => ... 'dos' => [ 0 => 'Tag the brand' ], 'donts' => ...
    'mentions' => ... 'links' => ... 'attachments' => [ 0 => 'brief.pdf' ] ].
  at tests/Feature/Modules/Campaigns/CampaignCrudTest.php:274
  Tests:    1 failed (3 assertions)
```

Reverted → `git diff` on the controller returned **empty** (byte-identical restore, verified).

## Full-suite verification (pre-push board, at HEAD)

| Gate                                                | Result                                                                                |
| --------------------------------------------------- | ------------------------------------------------------------------------------------- |
| Backend Pest (full, `-d memory_limit=1G`)           | **1803 passed, 1 skipped** (6311 assertions)                                          |
| `@catalyst/main` Vitest (full)                      | **1151 passed** (129 files)                                                           |
| `@catalyst/api-client` Vitest (full)                | **196 passed** (8 files)                                                              |
| `vue-tsc --noEmit` (main)                           | clean                                                                                 |
| ESLint (main, full)                                 | clean — 0 errors (2 pre-existing `vue/no-v-html` warnings in `onboarding`, unrelated) |
| Pint `--test` (all)                                 | passed                                                                                |
| PHPStan (full, `--memory-limit=1G`)                 | No errors                                                                             |
| Locale parity (`verify-locale`, 23 targets vs `en`) | 23/23 PASS                                                                            |

> **PHPStan note (found + fixed at the pre-push board):** the scoped sub-step PHPStan run covered only the two app files, not the test; the full board surfaced 5 `offsetAccess.notFound` on the brief sub-key assertions (`$brief['dos']` on `array<string,mixed>|null`). Fixed to the house pattern — the `?? null` null-coalesce guard already used by the existing create test — not a suppression/cast/`@var`. Folded into the `feat` commit; PHPStan re-run clean.

## Q1–Q4 — resolved as cleared

- **Q1** (Option A): all three dropped from `seedEditForm()`, `objective` from `emptyForm()` — omission is the whole D3 mechanism.
- **Q2**: `objective?: CampaignObjective` in the TS mirror — an addition-shaped relaxation, in scope.
- **Q3**: persistent-hint, key name as proposed, `max:5000` retained.
- **Q4**: brief preservation asserts `dos`/`donts`/`mentions` (+`links`/`attachments`) survive the PATCH byte-identical — the wipe-fix is now a named invariant, not a happy-path side effect.

## Out of scope — logged, not built (D6)

- **Creator-visible campaign description/brief** — a real product gap (creators see only id/name/window/brand on assignment detail). Logged in `tech-debt.md`, together with the forward-guard: _any future brief write path must merge or consciously replace, never rebuild from partial inputs._
- Vestigial `posting_window_*` form absence (validated backend, no input).
- Admin campaign surfaces.
- **No Playwright exposure exists for campaigns** — none created this chunk (per I7).

## Commits (two-commit pair — push HELD)

1. **feat** `feat(campaigns): simplify campaign form (drop objective/target/brief inputs)` — request + tests + api-client type + `CampaignForm.vue`/`CampaignCreatePage.vue`/`CampaignDetailPage.vue` + 24 locales.
2. **docs** `docs: log AH-032 … + brief-write forward-guard tech-debt` — ad-hoc log entry + tech-debt entry.

## 8. Independent review — verdict (appended to Cursor's draft)

**Approved.** What cleared it: P1 is genuine (forced wipe went red on the named preservation assertion with the full rich-brief array in the failure output; empty-diff restore) — the wipe-bug fix is a pinned invariant, not prose. D1 evidence is right (both directions tested; contract relaxed, never broken). D5 scoping held (Overview-tab objective label retained, orphans gone, `descriptionHint` in place; 23/23 parity matches the house convention). Q1–Q4 all landed as cleared; forward-guard verbatim in tech-debt; by-omission framing in the AH entry. Zero FE spec edits needed — exactly what the plan predicted from the read-pass. Full-suite pre-push board green (one PHPStan offset-access issue found in the test at the board and fixed to the existing `?? null` house pattern, folded into `feat`).

_Provenance (§4): drafted by Cursor as the closing artifact for the campaign-form-simplification chunk (AH-032; full loop with plan-pause honored — D1's default mechanism and D3's preserve-by-omission were the two spots a plausible shortcut would silently destroy data, and the break-revert proves neither did). Merged/closed by Claude via independent review (approved; P1 break-revert verified; Q1–Q4 resolved as cleared). Closed in the same docs commit that refreshes the resumption template — push HELD per the kickoff._
