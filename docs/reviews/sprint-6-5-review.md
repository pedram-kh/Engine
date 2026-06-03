# Sprint 6.5 — Review: Agency roster availability filter (functional)

**Status:** Closed. Spot-check passed (no PMC) — every anchor holds. The load-bearing one is pinned structurally: the `assemble()` extraction makes batch == loop by construction (both paths funnel through the same assembly + `expandRecurring()`, so the batch can't drift without changing shared code), proven by the identical-occurrences test + the 2-query property + the break-revert. Pagination counts reflect the filtered set (`whereNotIn(busyIds)` before `paginate()` → real SQL predicate; break-revert: filter-within-page would give total=5 not 3). Soft doesn't exclude (break-revert would zero `meta.total`). The day-granular inclusive boundary is the sharpest test — a hard block on the `to` day still excludes, with the break-revert that a raw half-open window stopping at 00:00 would wrongly include it. Recurrence path covered. The §5.36 asymmetric-coverage spot (Playwright verifies the enabled control + end-to-end threading; filtering correctness deferred to the exhaustive backend suite) is flagged honestly. Both tech-debt changes record the reframing (meaning-not-mechanism) + name the bounding axes, the promote-to-C trigger, and the `creator_busy_intervals` resolution.

**Reviewer:** drafted by Cursor (build pass); spot-checked + closed.

**Reviewed against:** the Sprint-6.5 kickoff (locked decisions D-1…D-6, the honest-deviation triggers, the spot-check anchors) + the four plan-pause divergences (all four confirmed by the user before the build) + `PROJECT-WORKFLOW.md` § 5 standards (5.17 defense-in-depth, 5.35 break-revert claim verification, 5.36 asymmetric-coverage acknowledgement) + the Sprint-6.5 read-pass inventory + the Sprint 5 Chunk A availability engine (`AvailabilityExpansionService` / `AvailabilityConflictService`).

A **very small mini-sprint**: make the roster availability filter — a disabled affordance since Sprint 6 Chunk 1 (D-5) — **functional**. The inventory reframed the problem: the cost isn't the per-page expansion (~50 light queries/page is fine), it's that **availability can't be a SQL predicate** (no stored status; it's per-creator RRULE intervals), so it can't join the paginated `whereHas`. Built deliberately as **date-range meaning + approach A (whole-filtered-set expansion, correct counts), hard-only**, with the scale ceiling **logged** as the documented upgrade to a materialized busy-intervals table (approach C).

---

## What shipped

### D-1 / D-2 — meaning: "available within [from, to]", hard-only

The filter answers **"who has no overlapping `hard` block in the window?"** — the question agencies staff against ("who's free the week of the shoot?"). NOT "available now" and NOT a stored status (none exists). **Soft blocks never exclude** — mirrors `AvailabilityConflictService` exactly (only `BlockType::Hard` is a conflict). A single date is a degenerate range (`from == to`), same machinery.

### D-4 — batch `expandMany()` on `AvailabilityExpansionService` (same logic, batched)

`apps/api/app/Modules/Creators/Services/Availability/AvailabilityExpansionService.php`:

- The per-creator assembly (the one-off loop + the `expandRecurring` loop + the `usort`) was extracted into a private **`assemble(iterable $oneOff, iterable $recurring, $start, $end)`**.
- The existing single **`expand(Creator, …)`** stays — it still issues its 2 per-creator queries, then calls `assemble()`. The calendar + conflict service are untouched.
- New **`expandMany(array $creatorIds, $start, $end): array<int, list<AvailabilityOccurrence>>`** loads ALL creators' one-off + recurring blocks in **exactly 2 queries** (`creator_id IN (...)` each), groups them in PHP, then runs the **same** `assemble()` per creator.

Because both paths funnel through the **same** `assemble()`/`expandRecurring()` code, **batch == loop by construction** — it's a query-layer batching of identical logic, not a reimplementation (honest-deviation trigger #2: cleanly reused, no duplicated expansion). This is the load-bearing correctness property, pinned by the equality test below.

### D-3 — filter-before-pagination with correct counts

`apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorController.php` `applyAvailabilityFilter()`:

1. `pluck` the **filtered relation set's** `creator_id`s (one light query, bounded by the agency's roster size — runs on a `clone` so the live builder is untouched);
2. `expandMany()` them over the window (2 queries);
3. collect the **busy** ids — any creator with ≥1 `BlockType::Hard` occurrence in-window;
4. apply `whereNotIn('creator_id', $busyIds)` to the **live** query **before** `->paginate()`.

Step 4 is the key move: once PHP knows the busy set, the availability exclusion becomes a **real SQL predicate** that the paginator counts + slices correctly — so `meta.total` / `last_page` / page contents reflect the **availability-filtered** set. **No filter-within-page** (which would leave `meta.total` counting the pre-availability set and desync the pager). Total query cost is flat in page count (~1 pluck + 2 block + 2 paginate), not O(creators × pages).

### Frontend — D-6 (the affordance flipped to a real range control)

`apps/main/src/modules/roster/pages/CreatorRosterPage.vue`:

- The static `AVAILABILITY_FILTER_CONNECTED = false` constant is **gone**; the disabled availability `v-select` affordance is replaced by **two native `type="date"` inputs** (from/to). The **metrics** affordances (follower range + engagement) stay disabled (still blocked on data, D-4).
- New `availableFrom` / `availableTo` refs (`string | null` — `clearable` sets null on clear; all reads use truthiness), a `hasAvailabilityWindow` computed, inclusion in `hasActiveFilters`, and a watcher that page-1-resets + reloads (mirrors the other filters).
- The window threads through the **three places** the inventory pinned: `RosterListParams` (`available_from` / `available_to` on `@catalyst/api-client`), `loadRoster()`'s param assembly, and `roster.api.ts`'s query builder. **Both-or-neither**: a one-sided / empty range sends no availability param.

### i18n

`app.roster.filters.availability.from` / `.to` labels added to **en / pt / it**; the retired `app.roster.affordances.availability.*` keys (label + the "coming soon" tooltip) **removed** from all three locales.

### Docs

- `docs/tech-debt.md`: the **"real availability filter (the cheap-signal design problem)"** entry is **CLOSED** (built via approach A — the reframing from cheap-signal-(a)-vs-(b) to date-range-meaning is recorded). A **new scale-ceiling entry** is added with the **promote-to-C trigger** (materialized busy-intervals table + scheduled expansion job when rosters grow / the query shows in slow-query logs).
- `services.md`: no change (as specified).

---

## Locked-decision divergences (surfaced at plan-pause, all four confirmed)

1. **Day-granular, inclusive window — server-side.** The FE date picker emits date-only values, so the controller normalizes: `windowStart = from->startOfDay()`, `windowEnd = to->startOfDay()->addDay()` (half-open, **inclusive of the `to` day**). "Available June 8–12" means the whole of those days; a single-date pick (`from == to`) becomes a full day rather than a zero-length window that matches nothing. Kept **server-side** (not "make the FE send end-of-day") so the server never trusts the client to construct the boundary and the window math lives in one place. **Confirmed.**
2. **366-day span clamp.** Mirrors `CreatorAvailabilityController::MAX_WINDOW_DAYS` — bounds recurrence expansion. Combined with the per-agency bound on the filtered set, neither expansion vector is unbounded, so the D-5 honest-deviation **stop does not fire**. The ">366-day reads as available" trade-off is already the availability list's documented behavior — consistent, not new. **Confirmed.**
3. **Explicit `available_from` / `available_to` + both-required.** Explicit names avoid collision with the generic filters; both-required (a one-sided range is ignored, never defaulted-forward — unlike the availability list's forward-90 default) is the right filter semantic. **Confirmed** (with a one-sided → no-filtering test).
4. **Two native `type="date"` inputs, not `DateTimeField`/`VDatePicker`.** Same jsdom discipline as the read-only star icons — native date fields thread + test cleanly, and two fields are honestly better UX for a range filter than a heavy picker. **Confirmed.**

---

## Honest deviations & notes

- **Validation is a real FormRequest (422), the other roster filters are not.** `ListAgencyRosterRequest` validates only the availability window (`available_from`/`available_to`: `sometimes|date`, `to` `after_or_equal:from`) — mirroring `ListAvailabilityBlocksRequest`. The existing status/country/language/category/q filters stay permissive query-string reads (an unknown value yields an empty page, never a 422 — the SPA only sends valid values). A FormRequest **is** a Request, so those reads are unchanged. `available_to` before `available_from` → 422 (tested).
- **Playwright: real control verified, filtering correctness deferred to the backend suite.** The roster E2E spec's old "availability is disabled + coming-soon hover" assertions are **retired** (the affordance became a feature — its disabled assertions correctly retire) and replaced with: the two date inputs render **enabled**, and filling a window threads through the live stack without error (neither seeded creator has a block, so both remain — proving the param is accepted end-to-end). A full "hard block excludes a seeded creator in the browser" path would need net-new availability-block seed infra; the **filtering correctness** (hard excludes / soft includes / recurrence / counts) is exhaustively pinned by the backend feature suite. Flagged here per §5.36; Playwright not run locally (the harness's `migrate:fresh` would wipe dev data — it runs in the `e2e-main` CI job).
- **No new index.** The `whereNotIn(creator_id, …)` runs inside the already-agency-scoped relation set; at Phase-1 volumes this is negligible (same reasoning as the existing unindexed `relationship_status`/`primary_language` filters, already a tech-debt entry). The expansion bound, not an index, is the scale lever — captured in the new scale-ceiling entry.
- **Deferred, per the kickoff (logged at close):** approach C (materialized busy-intervals table + scheduler) — the documented upgrade, deferred with its trigger; "available now" / scalar denormalization (B) — ruled out by the range meaning; follower/engagement filters (social adapters); handle search (its own deferred entry).

---

## Coverage (§5.17; break-revert §5.35)

| Area                                    | Coverage                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| --------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Batch == loop (D-4, load-bearing)**   | `AvailabilityExpandManyTest` — `expandMany()` produces **identical** per-creator occurrences (source block id + instants, order included) to a per-creator `expand()` loop, for a mixed set (one-off + recurring, in-window + out, hard + soft, a creator with no blocks). **Break-revert:** a batch method that drops/double-counts/mis-groups a creator's occurrences fails the `toEqual`. Plus: the batch runs in **exactly 2 block queries** regardless of creator count, and an empty id set returns `[]` without querying. |
| **Filter correctness (D-2)**            | `AgencyCreatorRosterTest` — overlapping **hard** block in-window → **excluded**; **soft**-only block in-window → **included** (break-revert: if soft excluded, `meta.total` would be 0); hard block **outside** window → included; **no blocks** → included.                                                                                                                                                                                                                                                                     |
| **Recurrence**                          | A weekly recurring **hard** block whose Thursday expansion lands in the window → **excluded** (the RRULE path, not just one-off).                                                                                                                                                                                                                                                                                                                                                                                                |
| **Pagination correctness (D-3)**        | 5 creators, 2 busy, `per_page=2` → `meta.total=3`, `last_page=2`, page rows are the available survivors sorted by name. **Break-revert:** filter-within-page would leave `meta.total=5` / `last_page=3`.                                                                                                                                                                                                                                                                                                                         |
| **Day-granular window (divergence #1)** | A hard block on the `to` day (`available_to=2026-06-12`, block on June 12) **still excludes** — break-revert: a raw half-open window stopping at `2026-06-12 00:00` would wrongly include it.                                                                                                                                                                                                                                                                                                                                    |
| **Empty / one-sided range**             | No params → roster unchanged (busy creator listed); only `available_from` → no filtering; `available_to < available_from` → 422.                                                                                                                                                                                                                                                                                                                                                                                                 |
| **FE threading (3 places)**             | `CreatorRosterPage.spec.ts` — one-sided range sends **no** availability param; completing the range threads **both** bounds; clearing a side drops them. `roster.api.spec.ts` — the query builder sends both-or-neither (both present / one-sided / empty-string / no-params).                                                                                                                                                                                                                                                   |
| **FE affordance flip**                  | `CreatorRosterPage.spec.ts` — the old disabled `roster-availability-affordance` is **absent**; metrics affordances stay disabled; mount still issues exactly 1 query (no spurious availability query).                                                                                                                                                                                                                                                                                                                           |

**Test runs (local):**

- Backend: `AvailabilityExpandManyTest` (3) + `AgencyCreatorRosterTest` (33, 1 pre-existing postgres-only skip) + `AvailabilityConflictTest` (5) — all green. PHPStan (`composer stan`): no errors. Pint: clean.
- FE: `CreatorRosterPage.spec.ts` (9) + `roster.api.spec.ts` (4) — all green. `vue-tsc --noEmit`: clean. ESLint on changed files: clean.

---

## Spot-check anchors

- **batch `expandMany()` == per-creator `expand()` loop** (break-revert — the load-bearing correctness pin) ✓
- **hard excludes / soft does not** (break-revert) ✓
- **recurring hard block in-window excludes** (the RRULE path) ✓
- **pagination counts reflect the filtered set, not the pre-filter count** (break-revert — no filter-within-page) ✓
- **empty range = no filtering** ✓
- **the scale-ceiling tech-debt entry present** with the promote-to-C trigger (materialized table + scheduler) ✓
- **the FE affordance flipped to a working range picker** ✓

---

## Files touched

**Backend**

- `apps/api/app/Modules/Creators/Services/Availability/AvailabilityExpansionService.php` — `assemble()` extraction + `expandMany()`.
- `apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorController.php` — `applyAvailabilityFilter()` + constructor injection + `MAX_WINDOW_DAYS`.
- `apps/api/app/Modules/Agencies/Http/Requests/ListAgencyRosterRequest.php` — new (from/to validation).
- `apps/api/tests/Feature/Modules/Creators/AvailabilityExpandManyTest.php` — new.
- `apps/api/tests/Feature/Modules/Agencies/AgencyCreatorRosterTest.php` — availability filter section.

**Frontend**

- `packages/api-client/src/types/agency.ts` — `RosterListParams.available_from` / `available_to`.
- `apps/main/src/modules/roster/api/roster.api.ts` — query builder (both-required).
- `apps/main/src/modules/roster/pages/CreatorRosterPage.vue` — range control + plumbing + constant removal.
- `apps/main/src/modules/roster/api/roster.api.spec.ts` — new.
- `apps/main/src/modules/roster/pages/CreatorRosterPage.spec.ts` — affordance + threading tests.
- `apps/main/playwright/specs/roster-search-and-affordances.spec.ts` + `apps/main/playwright/helpers/selectors.ts` — retired disabled assertions, added the range-control ids.
- `apps/main/src/core/i18n/locales/{en,pt,it}/app.json` — range labels; retired "coming soon".

**Docs**

- `docs/tech-debt.md` — closed the cheap-signal entry; added the scale-ceiling (promote-to-C) entry.
