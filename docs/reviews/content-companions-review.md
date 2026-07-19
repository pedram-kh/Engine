# AH-050 — "Who appears in your content?" (`content_companions`) — Review

- **Scope:** one new optional, self-declared, multi-select creator-profile field
  (`creators.content_companions`, jsonb) with an 11-key fixed registry, rendered as a
  chip group in wizard Step 2 and displayed (read-only) on discover profile, roster
  detail, and admin creator detail.
- **Loop:** full house loop — I1–I7 read-only inventory → kickoff with locked D1–D11 →
  plan-pause (Q1–Q6 ruled) → build S1–S8 → this review. Ad-hoc log entry: AH-050.
- **Precedents walked:** AH-022 (accent — the single-field visibility class),
  AH-019 (categories — the multi-select mechanics + the FE-registry debt class).
- **Status:** built; push HELD pending clearance (standing rule).

---

## Production posture (§5.40) — PROD-DATA RISK: NONE

Re-derived at final HEAD (branch `main`, on top of `7b12a8b`):

- **Migration is additive-nullable only.** `2026_07_19_100000_add_content_companions_to_creators_table`
  adds ONE nullable `jsonb` column after `accent`. No default, no backfill, no index,
  no data rewrite — `up()` is a single `ALTER TABLE ... ADD COLUMN` that Postgres
  executes as a catalogue change; **zero existing rows are read or written**.
- **`down()` is honest:** drops exactly the one column it added.
- **The ~200 live users are untouched by deploy.** Every existing row reads the new
  column as `NULL`, and `NULL` renders as the chips' empty "undisclosed" state on every
  surface. Nothing is displayed, computed, filtered, or scored from the field until a
  creator opts in by selecting chips and saving.
- **No existing behaviour changes:** completeness score (D6, proven inert below),
  floor gate, admin editability set, filters, and audit allowlist are all byte-identical
  in behaviour for a creator who never touches the field.

## Decision evidence (D1–D11)

- **D1 — naming / framing (Q1 ruling: `content_companions`).** Column, migration name,
  jsonb key, `CONTENT_COMPANION_KEYS` const, all six TS declarations, testids
  (`profile-companions-chip-<key>`, Q6) and i18n namespaces (`fields.companions`,
  `creator.ui.wizard.companions.*`) all follow the casting-framed name. "Household" was
  rejected per the ruling (demographic frame; pets/roommates/friends aren't a household).
- **D2 — no new parity mechanism (Q2 ruling: inherit-as-debt).**
  `UpdateProfileRequest::CONTENT_COMPANION_KEYS` is the SOT; the wizard's `COMPANION_KEYS`
  copy carries an honest in-code comment naming the drift class, and the AH-019
  tech-debt entry is extended to cover both fields with one resolution sketch
  (source-parse spec against the wizard file). The 11-key set is pinned server-side by
  the catalogue tripwire in `ContentCompanionsTest` ("registry catalogue pins the exact
  11-key set").
- **D3 — optional, empty = undisclosed, no select-all.** Validation is
  `sometimes|nullable|array|max:11` with NO `min:1` (unlike categories) — an empty save
  is valid. The chip group renders without any select-all affordance; a Vitest case pins
  that categories keeps its select-all and companions has none.
- **D4 — form placement.** The chip fieldset sits in `ProfileBasicsForm` directly after
  accent and before categories (the language/identity cluster), using the categories
  fieldset pattern minus select-all.
- **D5 — display-only v1; card non-render.** Discover profile, roster detail, and admin
  detail render the field via the shared `CategoryChips` (pre-localized labels; em-dash
  empty state). The dense discover CARD does not render it (the category `+N` row owns
  that slot); the value ships on the card resource anyway — see the Q4 trade below.
  No filtering in v1 (`FiltersCreatorColumns` untouched); `whereJsonContains` filtering
  is a logged follow-up, not built.
- **D6 — completeness-inert (§5.34 + break-revert).** The behavioural twin test proves
  two byte-identical creators (one with companions, one without) score identically, and
  a source pin proves the field is in neither `PROFILE_OPTIONAL_WEIGHTS` nor
  `isProfileComplete()`. Break-revert executed — see below. Inertness is deliberate
  GDPR posture: no score incentive to disclose. (Inventory correction stands: accent IS
  weighted (+2); companions is explicitly NOT walked through that half of the accent
  precedent.)
- **D7 — admin read-only (§5.34 + break-revert).** NOT added to
  `AdminUpdateCreatorRequest::EDITABLE_FIELDS`; admin PATCH with the field alone → 422;
  a piggy-back alongside `display_name` is ignored (never applied); the constant itself
  is pinned by a rule-parity-file case. Admin UI renders a plain read-only row (label +
  `CategoryChips`, no pencil) via the account-details read-only pattern (Q3 ruling — no
  `EditFieldRow` read-only mode). Break-revert executed — see below.
- **D8 — resource fan-out (5 surfaces).** Emitted verbatim (null/[] as stored) on:
  `CreatorResource` (creator-self + admin), `CreatorDiscoveryResource` (card),
  `CreatorPublicProfileResource` (discover detail), `AgencyCreatorDetailResource`
  (roster detail), and the roster list's hand-rolled `toRow` (+ its eager-load select).
  Both column projections (discovery controller select, roster eager-load select)
  updated. Value assertions added on every surface; the discover-card exact-keyset
  assertion extended.
- **D9 — i18n.** Both apps × 24 locales: field label, helper text, and the 11 option
  keys (13 keys main, 12 keys admin, per locale). All 23 non-en locales carry real MT
  baselines — including the flaky 10 (bg, el, et, fi, ga, hu, lt, lv, mt, ro; e.g. mt:
  "Min jidher fil-kontenut tiegħek?", ga: "Cé a fheictear i do chuid ábhair?"). Both
  locale-parity architecture specs green.
- **D10 — tests.** Backend: new `ContentCompanionsTest` (14 cases: round-trip ×4,
  validation ×4, catalogue tripwire, D6 ×2, admin-read + D7 ×3) + extended
  discovery/roster/detail/rule-parity files. Frontend: 4 new Step-2 cases (hydrate,
  null→[] round-trip, chip toggle, no-select-all), 2 discover-profile + 2 roster-detail
  display cases, 1 admin read-only-row case, 6 fixture updates. E2E: the wizard
  happy-path gains the `profile-companions-chip-partner` click (optional field —
  asserted not to gate the step).
- **D11 — GDPR purpose.** See the dedicated section below.

## Break-reverts (§5.35) — executed verbatim

**(1) D6 inertness.** Added `'content_companions' => 2` to
`CompletenessScoreCalculator::PROFILE_OPTIONAL_WEIGHTS` → ran `ContentCompanionsTest` →
**6 failed** (the behavioural twin case "D6 — a creator with companions populated scores
IDENTICALLY", the source pin "in neither the floor nor the optional-credit map", plus the
weights-sum collateral: `PROFILE_OPTIONAL_WEIGHTS` regression pin and sub-split-sum
invariant in `CompletenessScoreCalculatorTest` red through the same constant). Reverted;
`git status`/`git diff` on the calculator confirmed byte-clean restore; file re-ran
**14 passed**.

**(2) D7 admin read-only.** Added `'content_companions'` to
`AdminUpdateCreatorRequest::EDITABLE_FIELDS` → ran `ContentCompanionsTest` +
`AdminUpdateCreatorRequestRuleParityTest` → **3 failed** ("admin PATCH with
content_companions alone is rejected" now 200s, the piggy-back case now applies the
value, and the EDITABLE_FIELDS constant pin reds). Reverted; `git diff` on the Request
confirmed byte-clean restore; both files re-ran **25 passed**.

## Empty-vs-null round-trip proof (Q5, review priority 3)

Pinned in `ContentCompanionsTest` and the Step-2 Vitest specs, no normalization anywhere:

- Save `['partner','pets_dogs','young_kids']` → DB stores exactly that → `/creators/me`
  emits it verbatim → chips hydrate selected.
- Save `[]` (creator clears every chip) → DB stores `[]` (NOT coerced to null) →
  emitted as `[]` → hydrates to an empty chip group.
- Save explicit `null` → DB stores `NULL` → emitted as `null` → hydrates to an empty
  chip group.
- The SPA always sends the array as-is (clearing all chips sends `[]`), and hydrates
  `attrs.content_companions ?? []` — so `null` and `[]` are indistinguishable in the UI
  by design (both = undisclosed), with **no phantom state** on re-entry.
- Omitting the key from a PATCH leaves the stored value untouched (`sometimes`).

## Q4 payload trade (recorded, deliberate)

The field is emitted on `CreatorDiscoveryResource` even though the dense discover card
does not render it in v1: once populated, every discover card row carries one extra
array. This cost is accepted deliberately for resource-shape symmetry with the accent
set and to keep the fan-out simple — a recorded trade, not an accident.

## D11 — GDPR purpose reasoning

- **Purpose (casting, not profiling).** The field answers one production question — "who
  regularly appears in this creator's content?" — so agencies can cast campaign briefs
  (e.g. a dog-food brand finds creators whose content features dogs). The helper text
  says exactly this ("Helps brands cast the right creators for a campaign") — purpose
  framing shown at the point of collection.
- **Data minimization.** Eleven coarse, fixed keys. Deliberately NO exact counts, NO
  ages (only broad content-relevant age bands for minors appearing on camera), NO names,
  NO partner attributes (gender/marital status), NO free text. The registry cannot
  express anything finer than the casting question needs.
- **No Art. 9 special-category inference.** No key reveals or implies health, religion,
  political opinion, sexual orientation, or ethnicity. "Partner" is deliberately
  gender-free; family keys describe on-camera presence, not legal or biological
  relationships.
- **Freely given, optional, revocable.** The field is optional, defaults to empty, and
  empty = undisclosed. The creator can clear it at any time (save `[]`); clearing is a
  first-class, tested path. Every stored value is an individual, deliberate chip click
  (no select-all affordance — D3).
- **No disclosure incentive.** Completeness-inert (D6, break-revert-proven): the profile
  score neither rewards disclosure nor punishes silence, so consent isn't nudged by
  gamification.
- **Self-declared; admins never write it** (D7, break-revert-proven): the platform never
  asserts on a creator's behalf who appears in their content.
- **Not audited:** excluded from `auditableAllowlist()` (the accent precedent) — no
  before/after snapshots of this personal data in audit logs.
- **Visibility class = accent (AH-022):** creator-self, agency discover (card payload +
  detail), agency roster (list + detail), admin detail. Same audience that already sees
  the creator's public profile; no new audience introduced.

## Gate table (final HEAD)

| Gate                                                          | Scope                  | Result                                                                                                                                                                                                                                                     |
| ------------------------------------------------------------- | ---------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Pest (full, serial, 2G)                                       | apps/api               | **1885 passed**, 1 skipped (6636 assertions), 84s                                                                                                                                                                                                          |
| Pint `--test` (all)                                           | apps/api               | **passed**                                                                                                                                                                                                                                                 |
| PHPStan (2G)                                                  | apps/api               | **No errors**                                                                                                                                                                                                                                              |
| Vitest (full)                                                 | apps/main              | **1195 passed** (130 files) — incl. i18n-locale-parity + floor-mirror-parity                                                                                                                                                                               |
| Vitest (full)                                                 | apps/admin             | **426 passed** (51 files) — incl. admin locale parity + field-edit-config parity                                                                                                                                                                           |
| Vitest + tsc                                                  | packages/api-client    | **196 passed**; typecheck clean                                                                                                                                                                                                                            |
| vue-tsc                                                       | apps/main + apps/admin | clean                                                                                                                                                                                                                                                      |
| ESLint                                                        | apps/main + apps/admin | **0 errors** (2 pre-existing `vue/no-v-html` warnings: `ClickThroughAccept` and `ProfileBasicsForm`'s bio-preview directive — both predate AH-050)                                                                                                         |
| Locale parity                                                 | both apps × 24         | green; flaky-10 carry real MT values (spot-checked mt/ga/hu in the diff)                                                                                                                                                                                   |
| Playwright (full, dev stack down, isolated `catalyst_e2e` DB) | apps/main              | **20/22 green under CI-parity `--retries=2`** (19 passed + 1 flaky-passed); the 2 remaining (#19/#20) reproduce on clean `main` — pre-existing local flakes, see E2E note. Wizard happy-path (with the new `profile-companions-chip-partner` click) green. |

**E2E note (pre-existing, environment-layer):** three auth-flow cases failed in the full
run: `2fa-enrollment-and-sign-in` (#19), `failed-login-lockout-and-reset` (#20), and one
invitations case. Disposition: (a) #19 and #20 fail **identically on a clean stash of
`main` with zero AH-050 changes** — the stash-baseline run reproduced both failures
verbatim, so they are local-env/timing artifacts of the long-documented spec-#19/#20
flake class (see `tech-debt.md`'s Playwright-flake entries), not regressions; (b) the
invitations case passed in isolation and under `--retries=2` (CI parity — where the full
suite lands at 19 passed / 1 flaky-passed / #19+#20). No AH-050 surface is exercised by
any of the three. Dev stack was down for the run (port-8000 guard held); the suite ran
`migrate:fresh` against the isolated `catalyst_e2e` DB; dev stack restarted +
health-checked (API `/up` 200, SPA 200) after the run.

## Touched files

Backend: migration `2026_07_19_100000`, `Creator` model (fillable/cast/docblock/audit-
allowlist comment), `UpdateProfileRequest` (SOT const + rules), 4 resources + 2 controller
column projections, 5 test files (1 new). Frontend main: `ProfileBasicsForm.vue`,
`DiscoverProfilePage.vue`, roster `CreatorDetailPage.vue`, Playwright happy-path,
8 spec files, 24×2 locale files. Frontend admin: `CreatorDetailPage.vue` (+spec),
24 locale files. Packages: `api-client` types (6 declarations, 2 files). Docs: this file,
ad-hoc log (AH-050), `tech-debt.md` (AH-019 entry extended), resumption template.
