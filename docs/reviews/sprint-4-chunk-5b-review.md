# Sprint 4 — Chunk 5b Review

**Status:** Closed.

**Author:** Cursor (build + self-review draft).

**Scope:** A tiny display-only follow-up to Chunk 5 (the agency roster list). The eyes-on pass surfaced a gap: the roster showed **relationship** status (`roster`/`prospect`/`external`) but not the creator's **application** status (`approved`/`pending`/`rejected`/`incomplete`), so an agency couldn't tell from the list whether a roster creator is actually approved/usable. This adds `creators.application_status` to the slim row resource and renders it as a **second, visually-distinct status chip** on the roster table. **Display-only — no filter, no write surface, no new endpoint, no permission change.**

**Reviewed against:** the Chunk-5 review (`sprint-4-chunk-5-review.md`) and its locked decisions **D-c5-1…D-c5-6** (all held), `03-DATA-MODEL.md` (`creators.application_status`), `07-TESTING.md` §5.35 (break-revert), and the kickoff scope line (display-only, not filterable, all four states raw, distinct from the relationship chip).

---

## Divergences from the kickoff

No scope divergences. Both honest-deviation triggers were checked and resolved cleanly:

- **`application_status` is cleanly available on the existing join — no new query path.** It lives on the `creators` table the roster query already joins via `with('creator:…')`; the only change is adding the column to that existing eager-load select. No new join, no new query shape, no extra round-trip. (Trigger said: flag if it'd need a new query path — it didn't.)
- **i18n keys are unavoidable net-new, not dodgeable duplication.** The admin queue (Chunk 3) surfaces application-status labels, but those live in the **admin** SPA bundle — you can't share i18n across SPAs, and the creator-facing wizard/banner strings are the wrong register for a terse table chip. So `app.roster.applicationStatus.*` is genuine net-new in the agency bundle, not a cross-import that was skipped. (Trigger said: flag if reusable keys exist and you'd be duplicating — they don't.)

Two judgment calls (both pre-approved at plan-pause):

- **All four states surfaced raw, none collapsed.** `incomplete` (hasn't finished the wizard) vs `pending` (finished, awaiting admin) is exactly the distinction the agency needs; collapsing them would hide the "usable yet?" signal. Kept raw.
- **Colour-coded semantic chip vs the neutral tonal relationship chip.** The application chip uses `variant="flat"` + a semantic colour (`approved`→success, `pending`→warning, `rejected`→error, `incomplete`→grey); the relationship chip stays `variant="tonal"` and neutral. Different visual register + distinct `data-test` (`roster-app-status-*` vs `roster-status-*`) + the "Application" column header next to "Status" makes the two axes legibly separate rather than interchangeable.

---

## What was built

### Backend — slim row gains `application_status` (no query-shape change)

`AgencyCreatorController::index`:

- Added `application_status` to the existing slim eager-load select: `with('creator:id,ulid,display_name,country_code,primary_language,categories,application_status')`.
- Added `'application_status' => $creator?->application_status->value` to the hand-rolled `toRow()` payload (nullsafe-guarded like the other creator columns; reads the **real column value** per creator — not a constant).
- **No new filter.** No `?application_status=` query param, no `tryFrom`/`where` branch — the held line. Relationship-status filtering is unchanged.

### Frontend — second status chip on the roster table (`apps/main`)

- New **"Application"** column on the `v-data-table-server`, immediately after the existing "Status" (relationship) column.
- Cell template renders a colour-coded `v-chip` (`variant="flat"`, `:color` from a local `applicationStatusColor()` map) with `data-test="roster-app-status-{id}"`, labelled from `app.roster.applicationStatus.{status}`.
- `application_status: CreatorApplicationStatus` added to `RosterCreatorListItem.attributes` in `@catalyst/api-client` — **reusing the existing `CreatorApplicationStatus` type** (no new type), imported into `types/agency.ts`.
- New i18n in **en / pt / it**: `app.roster.fields.applicationStatus` (column header) + `app.roster.applicationStatus.{incomplete,pending,approved,rejected}` (the four labels).

---

## Coverage (§5.35 break-revert, git-verified)

**Backend — `AgencyCreatorRosterTest` (now 18):**

- The slim-shape test asserts `application_status` is present in the row keys.
- **New:** "surfaces each creator application*status on the slim row, reflecting actual state" — four creators in the four distinct states, all roster relations to one agency, asserted via parallel `display_name`/`application_status` wildcard arrays under the default `display_name` ASC sort. \_Break-revert: hardcode a literal in `toRow()` → the per-name pairing fails (the values no longer track each creator's real state).*

**Frontend — `CreatorRosterPage.spec.ts` (5):** the real-table row test now also asserts the application chip renders (`roster-app-status-{id}`, label "Approved"), is a **distinct element** from the relationship chip (`roster-status-{id}`), and does **not** carry the relationship label — pinning the two-axes-must-not-read-the-same invariant. (Asserts label + `data-test`, not rendered colour — see eyes-on note below.)

**Results:** backend `AgencyCreatorRosterTest` 18 passed (54 assertions). PHPStan (touched files): `No errors`. Pint: clean. Frontend roster spec 5 passed; `eslint src/modules/roster` clean; `vue-tsc --noEmit` clean.

---

## Eyes-on (not test-covered)

The colour mapping leans on the semantic theme colours (`success`/`warning`/`error`), and colour regressions only show in the real rendered theme — the unit test asserts the label + `data-test`, not the rendered colour. **Dark-mode legibility is the one eyes-on anchor here:** check the application chips against the zinc dark surface (success-green / warning-amber / error-red legible, grey `incomplete` distinguishable), not just in light. The chips use Vuetify's theme-token colours via `:color` (the same tokens the harness renders in both themes), so this is expected to hold — but it's an eyes-on check on the live render, not a test-covered one.

---

## Spot-check anchors

1. **`application_status` present + correct in the slim row** — `AgencyCreatorRosterTest` "surfaces each creator application_status…". Break-revert: hardcode a literal in `toRow()` → the per-name pairing fails (proves it reflects the creator's actual state, not a constant).
2. **The application chip is visually/data-test-distinct from the relationship chip** — frontend real-table test: separate `data-test`, distinct label, different element + variant/colour treatment.
3. **No `?application_status=` filter snuck in** — the controller adds no new query param/branch; route file unchanged (still only `GET creators`).

---

## Out of scope (unchanged from Chunk 5)

- **Application-status filtering** — explicitly out; a second filter axis risks conflating with relationship-status filtering. (Separate follow-up if wanted.)
- **Email / contact details** — Sprint-6 creator-detail view.
- **Row navigation** — still deferred (D-c5-4 holds).

## Docs updated

- This review. **No new tech-debt** — a clean display-only addition on an existing join.
- `docs/tech-debt.md`, `docs/services.md` — no change.

---

## Commit pair (proposed — not committed until spot-check)

1. **feat(agencies): surface creator application_status on the roster list** — `AgencyCreatorController` slim select + row; `RosterCreatorListItem.application_status` (api-client); `CreatorRosterPage.vue` column + chip + colour map; en/pt/it i18n; backend + frontend tests.
2. **docs(reviews): sprint-4 chunk-5b review** — this review.
