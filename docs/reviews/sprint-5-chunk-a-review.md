# Sprint 5 — Chunk A Review

**Status:** Closed.

**Author:** Cursor (build + self-review draft).

**Scope:** **Backend-only.** The availability backend: manual availability-block CRUD under `creators/me/availability` (creator-self-owned), backing **enums + casts** for `block_type`/`kind`, a **weekly-recurrence expansion engine** (server-side, on a PHP RRULE library), a standalone **hard-block conflict-detection** service, and the **agency-side availability read-view** (`reason` excluded). The creator calendar UI (Luxon + custom component) is **Chunk B** (separate kickoff). Auto-blocks on assignment acceptance + the conflict-warning modal trigger + external calendar sync are **deferred** (see "Out of scope").

**Reviewed against:** `03-DATA-MODEL.md` (`creator_availability_blocks §272–297`, `kind`/`block_type`/`reason` semantics `:284–286`), `20-PHASE-1-SPEC.md` Sprint 5 (`:193–204`, incl. the recurring-in-scope `:199` ↔ P2-columns contradiction and the auto-block acceptance criterion `:204`), `02-CONVENTIONS.md` (modular monolith, ULID, tenancy), `docs/security/tenancy.md` (§3 mandatory rule, §4 allowlist), `07-TESTING.md` §5.17 (defense-in-depth) + §5.35 (break-revert), the locked decisions **D-a1…D-a6**, and the Sprint 5 read-only inventory (B2–B7).

---

## Plan-pause decisions (resolved + confirmed)

- **RRULE library = `rlanvin/php-rrule` `^2.6`.** Picked over `simshaun/recurr` because it pulls **zero runtime dependencies** (pure PHP — clears the heavy-transitive-deps honest-deviation trigger; `simshaun/recurr` drags in `doctrine/collections`), it has **no Postgres/SQLite relevance** (it never touches the DB — it expands in-memory `DateTime`s), and `getOccurrencesBetween($begin, $end)` is exactly the window-expansion API D-a3/D-a4 need.
  - **PHP 8.4 confirmation (honest-deviation trigger):** "PHP 8.4 flagged" resolved to **compatible**, not a concern. Stable `v2.6.0` declares `php: >=5.6` (no upper bound, installs cleanly on our `^8.4`); it installed on PHP **8.4.13** and expanded a `FREQ=WEEKLY;INTERVAL=2;BYDAY=MO` rule correctly, and the full pint/phpstan/pest gate is green on 8.4 with no deprecation noise. (Explicit 8.4 support lands in the unreleased 3.0.0; we are not blocked waiting for it.)
- **Q1 — recurrence ceiling allows `INTERVAL` (every N weeks).** Locked rule: `FREQ=WEEKLY` + optional `INTERVAL` + optional `BYDAY` + optional `UNTIL`. Biweekly ("every other week") is a real pattern; hard-locking `INTERVAL=1` would push those creators back to manual blocks. Still rejected: daily/monthly/yearly, `BYMONTHDAY`, numeric-prefixed `BYDAY` (`2MO`), `COUNT`, embedded `DTSTART`, anything else.
- **Q2 — agency read-view built standalone now (B6), scoped to any relation.** Endpoint `GET /agencies/{agency}/creators/{creator}/availability` ships now (Sprint 6's creator-detail page will consume it). Scope mirrors the Chunk-5 roster exactly: an `AgencyCreatorRelation` (any `relationship_status` — roster/prospect/external) must exist between the agency and the creator, enforced by an **explicit relation-exists check**; no relation → 404.

## Divergences from the kickoff

No scope divergences. The build followed D-a1…D-a6 as written. Reportable implementation notes (none change scope):

- **`kind` required-ness forced no churn (the flagged trigger came up clean).** The table is unpopulated (no existing rows to backfill/default), and the factory already emitted valid `kind`/`block_type` strings — switching to enum casts kept the same stored values, so no factory rewrite or data migration was needed.
- **No schema change.** The enums/casts map onto the existing `varchar` columns; the two indexes (`idx_availability_creator_dates`, `_kind`) already serve the date-range + kind queries. Nothing forced a column tweak.
- **Weekly-only validation is enforced on the RAW submitted parts**, not post-expansion (the flagged library awkwardness). `WeeklyRecurrenceRule` allowlists the literal `KEY=VALUE` parts before persistence and _then_ hands the rule to the library to confirm parseability — so a `FREQ=DAILY` can never reach storage or the expansion engine.

---

## What was built

### Enums + casts (D-a2)

- `BlockType` (`hard`/`soft`) and `Kind` (`vacation`/`personal`/`exclusive_contract`/`assignment_auto`/`other`, matching the data-model `:284`), mirroring the module's string-backed enum pattern.
- `CreatorAvailabilityBlock` now casts `kind` → `Kind` and `block_type` → `BlockType` (model PHPDoc updated). The factory uses enum-backed values + adds `hard()`/`soft()`/`weeklyRecurring()` states.
- `Kind::creatorSettable()` returns the four creator-settable kinds (**excludes `assignment_auto`** — reserved for the Sprint 8 auto-block flow); used by the FormRequest `in:`-style rule so a creator-submitted `assignment_auto` is rejected (D-a2).

### Recurrence engine (D-a3 / D-a4)

- `WeeklyRecurrenceRule` (custom `ValidationRule`) — the weekly ceiling guard (FREQ=WEEKLY + INTERVAL + BYDAY + UNTIL; everything else rejected).
- `AvailabilityExpansionService::expand(Creator, from, to)` → `list<AvailabilityOccurrence>` — **the single source of expanded occurrences** (D-a4). One-offs are fetched via the date index; recurring blocks are expanded with `rlanvin`'s `getOccurrencesBetween`, each occurrence inheriting the source block's duration. The query window is widened backward by the block duration so an occurrence that _starts_ before the window but overlaps into it is not missed.
- `AvailabilityOccurrence` (readonly DTO) carries the source block + the concrete `startsAt`/`endsAt`, with an `overlaps()` helper.

### Conflict-detection (D-a5) — detection only

- `AvailabilityConflictService::detect(Creator, from, to)` → `AvailabilityConflictResult{hasConflict, conflicts}`. It **consumes the expansion service** (never re-expands) and filters to `BlockType::Hard` — soft blocks are not conflicts. No modal, no invite-flow wiring (that surface is Sprint 8).

### Creator CRUD (D-a1) — `creators/me/availability`

`CreatorAvailabilityController` in the existing `creators/me` group (`auth:web` + `tenancy.set` + `verified`):

- **Structural ownership:** every row resolves through `$request->user()->creator->availabilityBlocks()` (never a path id), mirroring `CreatorWizardController::requireCreator()`. A non-owned `{block}` is not in the relation → `firstOrFail()` → 404. Cross-creator write is impossible by construction.
- `GET` lists **expanded occurrences** for a `?from`/`?to` window (default today→+90d, span clamped to 366d to bound expansion), each carrying the source block's `recurrence_rule`/`is_recurring` for editing (D-a4).
- `POST`/`PATCH`/`DELETE` create/update/delete. Validation (`StoreAvailabilityBlockRequest`, `UpdateAvailabilityBlockRequest` = full-replace): tz-aware `starts_at` < `ends_at`, `block_type` ∈ enum, `kind` ∈ enum minus `assignment_auto`, optional `reason`, optional weekly `recurrence_rule` (required iff `is_recurring`).
- Creator-facing `AvailabilityOccurrenceResource` **includes `reason`** (creator's own view).

### Agency read-view (D-a6) — `agencies/{agency}/creators/{creator}/availability`

`AgencyCreatorAvailabilityController::show` in the Agencies module's `auth:web → tenancy.agency → tenancy` group:

- Gated by `AgencyCreatorRelationPolicy::viewAny` (any agency member), then an **explicit relation-exists check** (`agency_id` + `creator_id`, on top of the `BelongsToAgency` scope) — no relation → 404 (the Q2 anchor).
- Reads the **same** `AvailabilityExpansionService` output (D-a4) and renders it through a **dedicated** `AgencyAvailabilityResource` that **omits `reason`** (creator-only, B4). A dedicated resource — not a reuse of the creator-facing one — so `reason` cannot leak through a shared shape.
- Tenancy: path-scoped inside the tenancy stack, so it needs **no §4 allowlist entry** (only the `creators/me/availability` routes, which sit outside the tenancy alias, were allowlisted).

---

## Coverage (§5.17 defense-in-depth; §5.35 break-revert, git-verified)

**`AvailabilityCrudTest` (13):** create; 401 unauth; reject bad range (ends ≤ starts); reject unknown `block_type` + unknown `kind`; **reject creator-set `assignment_auto`** (+ nothing persists); `Kind::creatorSettable()` excludes `assignment_auto`; update own block; **404 updating another creator's block** (owner-only, break-revert) + foreign block untouched; delete own block; **404 deleting another creator's block** (owner-only) + foreign block survives; list returns only the caller's blocks for the window.

**`AvailabilityRecurrenceTest` (9):** weekly-Monday rule → exactly the 5 expected Mondays (break-revert on count); **`INTERVAL=2` → the 4 expected biweekly dates** (Q1 anchor); `UNTIL` bound stops generation; one-off unaffected by recurrence; **accept `FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE`**; **reject `FREQ=DAILY`** (break-revert on the ceiling); reject `FREQ=MONTHLY;BYMONTHDAY=1`; reject numeric-prefixed `BYDAY` (`2MO`); require a rule when `is_recurring`.

**`AvailabilityConflictTest` (5):** one-off HARD overlapping → detected; expanded-recurring HARD overlapping → detected; **SOFT → not a conflict** (break-revert on the hard/soft distinction); no overlap → clear; **single-source agreement** — conflict-detection's hard set equals the expansion service's hard set (same instants, same order).

**`AgencyCreatorAvailabilityTest` (8, one parameterized ×3):** 401 unauth; 404 non-member (tenancy invisibility); **404 when no relation exists** (no-relation boundary, break-revert); reads across roster/prospect/external; **expanded availability OMITS `reason`** (asserted on keys **and** raw body — break-revert: add `reason` to the resource); **single-source agreement** — the agency endpoint's occurrence starts equal the expansion service's.

**Results:** the four suites = **34 passed**. Full API suite **1018 passed (3262 assertions)**. PHPStan (470 files): **No errors**. Pint: clean.

---

## Spot-check anchors

1. **Owner-only CRUD** — `AvailabilityCrudTest` "404 when updating/deleting another creator block". Break-revert: resolve the block globally by ULID instead of through `$creator->availabilityBlocks()`.
2. **Weekly-only enforced; non-weekly rejected** — `AvailabilityRecurrenceTest` "rejects a FREQ=DAILY rule" / "monthly BYMONTHDAY" / "2MO". Break-revert: drop the `WeeklyRecurrenceRule` guard.
3. **Weekly + INTERVAL accepted (Q1)** — "accepts a weekly+INTERVAL+BYDAY rule" + "honours INTERVAL (every other week)".
4. **Expansion correctness for a window** — "expands a weekly Monday rule to the correct occurrences" (exact 5-date list).
5. **Conflict hard-vs-soft** — "does NOT treat a SOFT block as a conflict". Break-revert: flip the `BlockType::Hard` filter.
6. **Single expansion source agreement** — conflict-detection test "reads the SAME expansion output" + agency test "reads the SAME expansion output".
7. **Agency view omits `reason` + is agency-scoped** — "OMITS reason" + "404 when the creator has NO relation with the agency". Break-revert: add `reason` to `AgencyAvailabilityResource`; drop the relation check.
8. **`assignment_auto` not creator-settable** — "forbids a creator from setting kind=assignment_auto".
9. **The deferred `:204` auto-block criterion is named, not dropped** — `tech-debt.md` "Auto-blocks on assignment acceptance".

---

## Out of scope (logged at close)

- **Auto-blocks on assignment acceptance** (spec `:197`/`:204`) → **Sprint 8** (`campaign_assignments` is `NOT FOUND` — B3 — no acceptance event to fire from). `Kind::AssignmentAuto` reserved system-side now. Logged to `tech-debt.md` with the `:204` criterion named.
- **Conflict-warning modal trigger** (spec `:202`) → **Sprint 8** (the agency invite-to-assignment surface doesn't exist — B7). Detection logic ships now; logged to `tech-debt.md`.
- **External calendar sync** (`external_calendar_id`/`external_event_id`) → **P2**, untouched. Logged to `tech-debt.md`.
- **Daily/monthly/custom recurrence** — the weekly ceiling. Resolution of the spec `:199` ↔ P2-columns contradiction logged to `tech-debt.md`.
- **The creator calendar UI** → Chunk B.

## Note for Chunk B (recorded at close — not a Chunk-A issue)

**`UNTIL` is an instant, not a date — the calendar must map "ends on date X" carefully.** RRULE `UNTIL` is a precise datetime bound, so a rule whose occurrences start at (say) 09:00 with `UNTIL=…T00:00:00Z` on the end date will **exclude** that day's occurrence (09:00 > midnight). This is standard RFC 5545 semantics and is correct in the engine (a Chunk-A test was adjusted to reflect it, not the engine). When Chunk B's calendar lets a creator pick an "ends on" date, it must emit an `UNTIL` instant that is **at/after the occurrence's clock-time on the chosen end date** (e.g. end-of-day, or the occurrence start time) so the creator's intuitive "ends on date X" includes date X — otherwise creators hit the same midnight-boundary surprise. Flagged here so it's on the record for the Chunk-B design.

## Docs updated

- `docs/tech-debt.md` — four entries: auto-blocks-on-acceptance deferral (Sprint 8, `:204` named), conflict-modal-trigger deferral (Sprint 8), the recurrence spec-vs-data-model contradiction (resolved → keep weekly, ceiling = weekly), and external-calendar-sync (still P2).
- `docs/security/tenancy.md` §4 — the four new `creators/me/availability` routes added to the cross-tenant allowlist (the agency endpoint needs no entry — it is inside the tenancy stack).
- `docs/services.md` — no change (per the kickoff).

---

## Commit pair (proposed — not committed until spot-check)

1. **feat(creators): availability backend — CRUD + weekly recurrence + conflict-detection + agency read-view** — `rlanvin/php-rrule` dep; `BlockType`/`Kind` enums + model casts/factory; `WeeklyRecurrenceRule`; `AvailabilityExpansionService`/`AvailabilityConflictService` (+ DTOs); `CreatorAvailabilityController` + Store/Update/List requests + `AvailabilityOccurrenceResource` + routes; `AgencyCreatorAvailabilityController` + `AgencyAvailabilityResource` + route; the four test suites.
2. **docs(tech-debt,tenancy): log Sprint 5 Chunk A deferrals + availability route allowlist** — `tech-debt.md` (four entries) + `tenancy.md` §4 + this review.
