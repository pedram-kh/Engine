# Admin All-Creators filters — application status, KYC state, Connected (AH-079)

- **Status: Closed — approved.** Compact full loop — kickoff with locked decisions (D1–D4) → brief
  plan-pause → build → two-commit pair → pushed.
- **Verdict (Pedram, 2026-08-18).** **D1–D4 verified** as built against the kickoff's locked decisions.
  **The four-creature §5.34 set, with its set-equality complement, accepted as the model** other
  connection-style filters should follow: one shared fixture, each creature covering a distinct reason
  for its classification (wrong relation status, right status but blacklisted, no relation at all), and
  the complement asserted by `toEqualCanonicalizing` rather than inferred from a count. **The
  scope-reuse break-revert accepted as load-bearing** — it reddened exactly the two creatures the
  scope exists to gate and nothing else, proving the test suite would actually catch a re-spelled
  predicate drifting from `permitsMessaging()`. **The D4 no-URL-state refinement ratified**: AH-076
  checked and confirmed `apps/main`-only, so admin's chip filters correctly stayed local-`ref` state
  matching their two siblings rather than importing a feature that was never asked for here. **72
  strings verified flaky-10 clean** — the new `connected` leaves differ from English in all ten
  historically-under-translated locales, not just present.
- **Date:** 2026-08-18
- **Provenance:** built by Cursor directly against Pedram's kickoff (D1–D4 below, locked at kickoff
  time); no separate independent-review round in this loop — reviewed and approved by Pedram directly,
  2026-08-18.
- **Feature commit:** `b2bc310e` —
  `feat(admin): add application-status, KYC, and Connected filters to All Creators (AH-079)`.
- **Docs commit:** `docs(reviews): AH-079 review, log entry, and tech-debt for the All-Creators
filters` — carries this file, so its own hash cannot be cited from inside it; see `git log` at the
  tip for the exact hash.
- **Evidence base:**
  [`admin-filter-profile-modal-richtext-inventory.md`](admin-filter-profile-modal-richtext-inventory.md)
  §0.3/I3.1 — the controller/query survey, the chip-filter precedent, and the candidate-filter cost
  table this chunk builds from.
- **§5.40 risk: NONE**, held as declared at kickoff. One new read-only query param plus three admin FE
  chips; no migration, no schema change, no write path touched, and the two pre-existing filters
  (`?status=`, `?kyc_status=`) are byte-for-byte unchanged — this chunk is strictly additive behind a
  param nothing previously sent.

---

## 1. What shipped, against the kickoff's D1–D4

| Decision | Asked                                                                                                                      | Shipped                                                                                                                                                                                                                                                                                           |
| -------- | -------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **D1**   | Three chip groups on All Creators: status (`?status=`), KYC (`?kyc_status=`), Connected (new `?connected=true\|false`).    | Held. `AllCreatorsPage.vue` gained three independent `v-chip-group`s, cloned 1:1 from `CreatorListPage.vue` / `KycQueuePage.vue`'s widget, `watch`, and `onTableUpdate` wiring.                                                                                                                   |
| **D2**   | Connected = `permitsMessaging()` (roster + non-blacklisted) via any agency, `EXISTS`/`NOT EXISTS`, missing-index accepted. | Held. See [§2](#2-the-d2-predicate-and-why-the-three-cheaper-ones-were-rejected) and [§3](#3-the-534-disjoint-set) for the predicate and its proof; [§6](#6-the-tech-debt-entry) for the index.                                                                                                   |
| **D3**   | Unknown value → empty page; absent → today's list; the disjoint set incl. the complement count.                            | Held. `whereRaw('1 = 0')` on an unrecognised `connected=` value, mirroring the existing `status`/`kyc_status` posture verbatim; see [§3](#3-the-534-disjoint-set).                                                                                                                                |
| **D4**   | Chips AND-compose; FE preserves them via AH-076 if it reached admin, else match today's behaviour.                         | Held, refined: AH-076 never reached admin (confirmed by reading its own log entry — `apps/main` only). All Creators, like both sibling queues, has never round-tripped filter state through the URL, so no URL-state layer was added; see [§4](#4-chips-compose--no-url-state-the-d4-refinement). |

---

## 2. The D2 predicate, and why the three cheaper ones were rejected

"Connected" is implemented as a correlated subquery reusing
`AgencyCreatorRelation::scopePermitsMessaging()` byte-for-byte — the same reuse
`JobsBoardVisibility::visibleTo()` (`apps/api/app/Modules/Campaigns/Services/JobsBoardVisibility.php:101-106`)
already relies on for an identical cross-agency fact:

```php
$permitsMessagingSubquery = fn () => AgencyCreatorRelation::query()
    ->withoutGlobalScope(BelongsToAgencyScope::class)
    ->permitsMessaging()
    ->whereColumn('agency_creator_relations.creator_id', 'creators.id');

if ($connectedInput === 'true') {
    $query->whereExists($permitsMessagingSubquery());
} elseif ($connectedInput === 'false') {
    $query->whereNotExists($permitsMessagingSubquery());
} else {
    $query->whereRaw('1 = 0');
}
```

`Illuminate\Database\Query\Builder::whereExists()` accepts an Eloquent `Builder` directly (converts
via `->toBase()`), so the scope call needs no re-spelling inside a raw closure — the correlation is
the only thing added at the call site. `BelongsToAgencyScope` is dropped deliberately: `creators` is a
global entity with no agency tenancy on this admin list, and "does ANY agency have a qualifying
relation" is a genuine cross-agency fact, not a scoping bug.

**The inventory's table (§0.3, condensed), and why each cheaper option was rejected rather than
adopted for its lower cost:**

| Rejected predicate                            | Cheaper because                                            | Wrong because                                                                                                                                                                                                                                                                                                                |
| --------------------------------------------- | ---------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Any relation row exists, regardless of status | No `relationship_status`/`is_blacklisted` filtering at all | A `pending_request` or `declined` creator would read as connected — a materially false admin-facing fact, not a rounding error.                                                                                                                                                                                              |
| Roster status only, skip the blacklist leg    | One fewer `WHERE` leg                                      | Reopens exactly the gap AH-051 closed: a blacklisted-rostered creator has an active `roster` row but contact/messaging is closed. Filtering it as "connected" would contradict the drill-in's own contact gate.                                                                                                              |
| Denormalised `creators.is_connected` boolean  | O(1) filter, no subquery at all                            | Needs a write-path hook on every relation mutation (connect, disconnect, blacklist, un-blacklist, decline, re-request) across `AdminCreatorConnectionController` and every agency-side transition — ongoing maintenance for a low-traffic admin list, and a second source of truth that can drift from `permitsMessaging()`. |

The honest `EXISTS` was chosen specifically because it **cannot** drift from the one scope every other
messaging-gate consumer shares — the same reasoning `scopePermitsMessaging()`'s own docblock states
for its other two call sites.

---

## 3. The §5.34 disjoint set

`AdminCreatorIndexTest.php` gained six cases, built from one shared fixture
(`seedConnectedDisjointSet()`) so every case reasons about the same four creators:

| Creator               | Relation                          | `connected=true`? | Why it's in the set                                                                    |
| --------------------- | --------------------------------- | ----------------- | -------------------------------------------------------------------------------------- |
| `pendingRequestOnly`  | `pending_request`, one agency     | **No**            | Has a relation row, but it's not `roster` — must not leak into "connected."            |
| `rosteredBlacklisted` | `roster`, `is_blacklisted = true` | **No**            | The exact case AH-051 exists to gate — a real roster row that fails messaging.         |
| `rosteredClean`       | `roster`, non-blacklisted         | **Yes**           | The one genuinely qualifying case.                                                     |
| `noRelations`         | none                              | **No**            | A fourth, distinct reason for "not connected" — no row at all, not a failed predicate. |

Assertions built from this fixture:

- `connected=true` returns **exactly** `rosteredClean` (count 1, id match).
- `pendingRequestOnly` and `rosteredBlacklisted` are each individually asserted **absent** from the
  `connected=true` result set (two separate cases, not inferred from the count).
- `connected=false` returns **exactly the complement**: count 3, containing
  `{pendingRequestOnly, rosteredBlacklisted, noRelations}` — `toEqualCanonicalizing`, so this is a
  set-equality proof, not "at least these three."
- Unrecognised `connected=maybe` → `meta.total === 0`, matching the `status`/`kyc_status` posture.
- A combined case (`status=approved&kyc_status=verified&connected=true`) seeds one matching creator
  plus two near-miss negatives (right status+KYC but not connected; right status+connected but wrong
  KYC) and asserts only the true triple-match survives — proving the three legs genuinely AND-compose
  rather than one silently overriding another.

All 12 cases in the file (6 pre-existing + 6 new) pass: **12 passed, 38 assertions.**

---

## 4. Break-revert: the scope reuse is load-bearing, not decorative

Mutation: dropped `->permitsMessaging()` from the subquery, leaving a bare `EXISTS`/`NOT EXISTS`
against `agency_creator_relations` with no status/blacklist filtering at all.

**Reddened exactly the cases the scope exists to gate — nothing else:**

```
✕ a pending_request-only relation does NOT count as connected
  Expecting […] not to contain '01M09T8WT7YAH6ZF0XN4R687ER'.
✕ a rostered-but-blacklisted relation does NOT count as connected
  Expecting […] not to contain '01M09T8WW3RZ0TFDZJ033FY6TP'.
✕ connected=false returns exactly the complement of connected=true…
  Failed asserting that 3 is identical to 1.
```

4 of 12 failed, all three the tests that exist specifically to catch this regression class (the fourth
is the complement-count assertion inside the same test as the third). The other 8 — status, kyc_status,
pagination, auth gates, and the `rosteredClean`-matches-connected=true case — stayed green, because a
bare `EXISTS` still correctly finds the one creator with an actual relation row; it just also
over-includes the two it shouldn't. Reverted; all 12 green again.

---

## 5. Chips compose, no URL state (the D4 refinement)

**AND-compose, not exclusive-select across groups.** Each of the three `v-chip-group`s is
`mandatory` internally (matching the review-queue/KYC-queue precedent — one value always selected
within a group, defaulting to `all`) but independent of the other two groups. `AllCreatorsPage.spec.ts`
asserts the combined case directly: clicking status→`approved`, then kyc→`verified`, then
connected→`yes` results in a single `adminCreatorsApi.list` call carrying all three params at once,
not three separate mutually-overwriting calls.

**No URL-state layer, and this was checked rather than assumed.** AH-076 (commit `8a1f3ae4`,
`browse-state persistence for Roster and Discover`) put page/search/filter state into the URL for two
`apps/main` pages via a new `useListQueryState` composable — but its own log entry's **Touched** list
is `useListQueryState.ts`, `useBackToList.ts`, `core/router/index.ts`, `DiscoverPage.vue`,
`DiscoverProfilePage.vue`, `CreatorRosterPage.vue`, `CreatorDetailPage.vue` — all `apps/main`, none
`apps/admin`. Separately, `AllCreatorsPage.vue`, `CreatorListPage.vue`, and `KycQueuePage.vue` all
carry their filter/page state in local `ref`s today, with zero URL round-trip (not even `page`). Per
D4's instruction, the feature was not imported: all three chips are local component state, identical
in shape to the two pre-existing chip groups they sit beside.

---

## 6. The tech-debt entry

`agency_creator_relations` carries exactly two indexes
(`2026_05_14_100007_create_agency_creator_relations_table.php:113-119`):
`unique_agency_creator (agency_id, creator_id)` and `idx_agency_creator_blacklisted (agency_id,
is_blacklisted)` — both lead with `agency_id`. Neither serves the `whereColumn('agency_creator_relations.creator_id', 'creators.id')`
correlation this filter runs for every row `creators` considers, so `connected=` scans without an
index assist. No index ships with this chunk — recorded as open, AH-054/AH-056-precedent shape
(a query nobody has measured, run from a low-QPS admin-only surface), full entry in `tech-debt.md`
under "Admin's `?connected=` creator filter has no supporting index (AH-079)".

---

## 7. Gate board

| Gate                                 | Result                                                                                                                                              |
| ------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend (Pest), full suite           | **2528 passed, 1 skipped** · 9385 assertions                                                                                                        |
| `AdminCreatorIndexTest.php` targeted | **12 passed** · 38 assertions (incl. the 6 new §5.34 cases)                                                                                         |
| Break-revert (scope-reuse mutation)  | **4/12 reddened**, exactly the scope-reuse cases; restored, green                                                                                   |
| PHPStan (project config)             | **0 errors** (919 files)                                                                                                                            |
| Pint                                 | **passed**                                                                                                                                          |
| `apps/admin` (Vitest), full suite    | **458 passed / 54 files** (was 449/53 before this chunk)                                                                                            |
| `AllCreatorsPage.spec.ts` (new)      | **9 passed**                                                                                                                                        |
| `i18n-locale-parity.spec.ts` (admin) | **4 passed** — keyset, placeholder, and plural-form parity across all 24 locales, including the new `admin.creators.all.filters.connected.*` leaves |
| ESLint (touched files)               | **0 errors**                                                                                                                                        |
| `vue-tsc` (admin, project-wide)      | **clean**                                                                                                                                           |
| Playwright                           | **N/A** — no admin E2E surface touches this page, before or after                                                                                   |

---

## 8. i18n — what's new vs. reused

Only **Connected** is new copy. Status and KYC chip labels reuse `admin.creators.list.filters.*` and
`admin.creators.kyc.filters.*` verbatim (same words, already translated in all 24 locales) — no new
keys for those two axes. New leaves: `admin.creators.all.filters.connected.{all,yes,no}`, 3 × 24 = 72
strings, real per-locale copy including the flaky-10 (`bg, el, et, fi, ga, hu, lt, lv, mt, ro`) —
none inherited the English fallback. Sample spot values:

| Locale | `all` | `yes`        | `no`             |
| ------ | ----- | ------------ | ---------------- |
| en     | All   | Connected    | Not connected    |
| el     | Όλα   | Συνδεδεμένος | Μη συνδεδεμένος  |
| ga     | Uile  | Ceangailte   | Neamhcheangailte |
| ro     | Toate | Conectat     | Neconectat       |

---

## 9. What this chunk deliberately did not do

- **No URL-state persistence.** See [§4](#4-chips-compose--no-url-state-the-d4-refinement) — checked
  against AH-076, not assumed.
- **No new index.** Recorded as tech-debt, not built speculatively against a query nobody has measured.
- **No search (`?q=`).** Out of the inventory's asked filter set; All Creators still has no free-text
  search, matching its state before this chunk.
- **No E2E leg.** Confirmed rather than assumed: the admin Playwright suite has exactly two specs,
  neither touching the creators module.
