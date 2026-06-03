# Sprint 5 — Chunk B Review

**Status:** Closed.

**Author:** Cursor (build + self-review draft). Spot-check passed (no PMC) — closed 2026-06-03.

**Scope:** **Frontend-only.** The creator availability calendar **month view** + block **create/edit/delete** + the **creator topbar nav**. Builds directly on the Chunk-A backend (`creators/me/availability`). The **week view is deferred** to a focused follow-on chunk (its timed-lane/overlap-packing math is the disproportionately-heavy increment — D-b1). External calendar sync (P2), auto-blocks + conflict-modal trigger (Sprint 8), and per-occurrence exceptions (series-level only) remain out of scope.

**Reviewed against:** the Chunk-B kickoff (locked decisions **D-b1…D-b13**), the Chunk-A backend contract ([`AvailabilityOccurrenceResource`](../../apps/api/app/Modules/Creators/Http/Controllers/CreatorAvailabilityController.php), [`WeeklyRecurrenceRule`](../../apps/api/app/Modules/Creators/Rules/WeeklyRecurrenceRule.php), [`Kind`](../../apps/api/app/Modules/Creators/Enums/Kind.php)/[`BlockType`](../../apps/api/app/Modules/Creators/Enums/BlockType.php)), the [Chunk-A review's Chunk-B note](sprint-5-chunk-a-review.md) (the `UNTIL`-instant carry-forward), the established main-SPA patterns (`*.api.ts` + `http`, `InviteUserModal`/`BrandForm` dialog + `extractFieldErrors`, the i18n namespace model, `CEmptyState`), and the architecture suite (`form-error-pattern`, `no-direct-http`, `typography-consumption`, `no-inline-color-styles`).

---

## Plan-pause confirmations (resolved)

Three calls were confirmed at the plan-pause before building:

- **Q1 — month-grid home = SPLIT.** A reusable, Luxon-free **`CMonthGrid` layout primitive in `@catalyst/ui`** (the 6×7 matrix, padding, today marker, prev/next, a slotted day cell) + **all occurrence/tz logic in the availability module**. Honors D-b2 (custom grid, zinc/aurora + dark-mode from day one, no headless dep) while keeping Luxon out of the shared package and the grid testable under `@catalyst/ui`'s existing theme harness.
- **Q2 — `UNTIL` emit = END-OF-DAY in the creator tz → UTC.** The safest inclusion for "ends on date X" (D-b12): end-of-day (23:59:59) sits at/after any same-day occurrence's clock-time, so date X is never silently dropped.
- **Q3 — proceed with the build.**

**Luxon confirmed + added:** `luxon@3.7.2` (matches D-b3 exactly; zero runtime deps) + `@types/luxon@3.7.1`, the first date dep in `apps/main`.

## Divergences from the kickoff

No scope divergences — the build followed D-b1…D-b13 as written. Three reportable refinements (none change scope), surfaced at plan-pause and confirmed:

1. **Month-grid split (refines D-b2/D-b10).** D-b2 names `@catalyst/ui` for the grid and the build line softened to "ui or module". Resolution: a **pure layout primitive `CMonthGrid` in `@catalyst/ui`** (genuinely reusable, dependency-free, theme-tokened) + the availability-specific occurrence/tz rendering in the module. The **date/time picker stays module-local** per D-b10 (no second consumer yet). `VDatePicker` ships in the already-installed Vuetify `^3.7.6` — **no new install** needed.
2. **Multi-day blocks paint each covered day (day-level, NOT overlap math).** A block spanning several days renders a bar in every covered cell, end-exclusive at midnight (a `00:00→00:00` all-day block paints exactly one day). This is day-level rendering — explicitly **not** the deferred week-view intra-day lane geometry. The honest-deviation trigger ("if all-day-vs-timed pulls in overlap math, stop") was held: nothing intra-day leaked in.
3. **`availability` is a UI-only i18n namespace** (like `dashboard.*`). No backend error codes map under it (codes stay `validation.`/`creator.`), so — like dashboard — it needs no `i18n-*-codes` architecture test.

---

## What was built

### Wire types — `@catalyst/api-client`

- [`types/availability.ts`](../../packages/api-client/src/types/availability.ts) — mirrors the backend verbatim: `AvailabilityOccurrenceResource` (`id`/`type`/`attributes`), `AvailabilityBlockType`, `AvailabilityKind`, and `CreatorSettableKind = Exclude<AvailabilityKind, 'assignment_auto'>` (so a client cannot even _construct_ an `assignment_auto` create payload — D-b9 at the type level). `AvailabilityListResponse` carries `meta.window` (D-b6); create/update return `SingleAvailabilityResponse`; `UpdateAvailabilityBlockPayload = Create…` (full-replace, matching the backend). Re-exported via `types/index.ts`.

### API wrapper — `availability.api.ts`

- [`availability.api.ts`](../../apps/main/src/modules/creators/availability/availability.api.ts) — `list({from,to})` / `create` / `update(ulid)` / `delete(ulid)` against `/creators/me/availability` via the house `http` client (creator-self-scoped — no agency path). Docblocks the full-replace + series-level semantics.

### Shared layout primitive — `CMonthGrid` (`@catalyst/ui`)

- [`CMonthGrid.vue`](../../packages/ui/src/components/CMonthGrid.vue) — pure 6×7 matrix: leading/trailing-month padding, weekday header, localized month label + prev/next, today marker. **Date math is pure UTC calendar arithmetic** (no tz drift), and the grid emits/slots plain `'YYYY-MM-DD'` keys via a single `#day="{ cell }"` scope object (avoids the template slot-prop camelCase gotcha). i18n-free + Luxon-free; styled entirely with `rgb(var(--v-theme-*))` + the `--catalyst-typography-*` scale, so it re-themes light/dark automatically. Exported from `packages/ui/src/index.ts`.

### Availability module (`apps/main/src/modules/creators/availability/`)

- [`datetime.ts`](../../apps/main/src/modules/creators/availability/datetime.ts) — pure Luxon helpers: `resolveTimezone` (null → browser tz, D-b7), `utcIsoToZoned`/`zonedToUtcIso` (the UTC ↔ resolved-tz round-trip), `eachDayKey` (day-level coverage, end-exclusive at midnight), `monthQueryWindow`, `monthLabel`/`weekdayLabels`, `todayKey`, `addDays`.
- [`recurrence.ts`](../../apps/main/src/modules/creators/availability/recurrence.ts) — `buildWeeklyRule` (emits ONLY `FREQ=WEEKLY` + optional `INTERVAL`/`BYDAY`/`UNTIL` — D-b11), `untilInstant` (**end-of-day in tz → UTC** RRULE instant — D-b12), `parseWeeklyRule`/`untilToDate` (round-trip for editing).
- [`useResolvedTimezone.ts`](../../apps/main/src/modules/creators/availability/useResolvedTimezone.ts) — thin composable over `resolveTimezone` reading `useAuthStore().user?.attributes.timezone`.
- [`options.ts`](../../apps/main/src/modules/creators/availability/options.ts) — `BLOCK_TYPE_VALUES` + `KIND_VALUES` (the four creator-settable kinds; **never `assignment_auto`**).
- [`DateTimeField.vue`](../../apps/main/src/modules/creators/availability/components/DateTimeField.vue) — module-local date (`VDatePicker` in a menu) + time (`type="time"`) control (D-b10); tz-agnostic (parent owns conversion).
- [`AvailabilityBlockDialog.vue`](../../apps/main/src/modules/creators/availability/components/AvailabilityBlockDialog.vue) — the `v-dialog`+`v-card`+`<form novalidate @submit.prevent>` create/edit/delete dialog mirroring `InviteUserModal`/`BrandForm`: `extractFieldErrors` → per-field `:error-messages` + a generic banner; fields per D-b9 (all-day, start/end date+time, block_type hard/soft, kind, reason ≤255, recurring + the weekly builder). **Series-edit notice (D-b8)** when editing/deleting a recurring block; **two-step delete** confirm. All UTC ↔ tz conversion happens here.
- [`AvailabilityCalendar.vue`](../../apps/main/src/modules/creators/availability/components/AvailabilityCalendar.vue) — wraps `CMonthGrid`; fetches the visible month, **reads `meta.window`** (D-b6), **buckets occurrences by day keyed `id + starts_at`** (D-b5 — no recurring collision), renders day-level bars (color by hard/soft), month nav, click-a-day→create, click-a-bar→edit, `v-skeleton-loader` initial load + `CEmptyState` for no-blocks.
- [`pages/AvailabilityPage.vue`](../../apps/main/src/modules/creators/availability/pages/AvailabilityPage.vue) — thin shell (localized header + aurora accent divider, matching the creator dashboard) hosting the calendar.

### Route + creator topbar nav (D-b13)

- [`routes.ts`](../../apps/main/src/modules/creators/routes.ts) — `/creator/availability`, `name: creator.availability`, `meta.layout: 'creator'`, `guards: ['requireAuth']`.
- [`CreatorDashboardLayout.vue`](../../apps/main/src/modules/creators/layouts/CreatorDashboardLayout.vue) — extended the existing topbar with two router-linked nav items (**Dashboard, Availability**); active-state is driven by vue-router link matching (no manual `route.name` checks), responsive, dark-mode, tri-locale. Not a sidebar (the creator surface is thin — D-b13).

### i18n — `availability` namespace

- [`en`](../../apps/main/src/core/i18n/locales/en/availability.json)/[`pt`](../../apps/main/src/core/i18n/locales/pt/availability.json)/[`it`](../../apps/main/src/core/i18n/locales/it/availability.json) `availability.json` — UI strings, the **block-type/kind labels** (the API sends raw enum values, no localized labels — Divergence #4), the **series-edit warning copy**, and the creator-nav labels. Registered in [`core/i18n/index.ts`](../../apps/main/src/core/i18n/index.ts) `MessageSchema` + the `messages` spread.

---

## Coverage (jsdom-safe; heavy Vuetify stubbed per the Chunk-5 memory)

**New: 62 tests** (8 files). Heavy components (`VDatePicker`/`VSelect`/`VSwitch`/`VDialog`) are stubbed per the roster `CreatorRosterPage.spec` pattern; the real, lightweight `CMonthGrid` renders in its own spec.

- **`CMonthGrid.spec.ts` (10, `@catalyst/ui` harness):** 42 cells; weekday order; leading/trailing padding `inMonth` flags; Sunday-first variant; today marker on/off; prev/next + day-click emits; the `#day` scope object.
- **`datetime.spec.ts` (15):** tz null-fallback (D-b7); `utcIsoToZoned` clock + **day-shift** placement; `zonedToUtcIso` round-trip (epoch-compared, format-agnostic); `eachDayKey` single/all-day/multi-day + **zone-local (not UTC) day** bucketing; `monthQueryWindow` bounds; label/`addDays` helpers.
- **`recurrence.spec.ts` (12):** builder emits only allowed weekly parts; never a forbidden part; **`UNTIL` ≥ a 09:00 same-day occurrence** + the regression showing a naive midnight `UNTIL` _would_ drop it (D-b12); parse/`untilToDate` round-trip.
- **`options.spec.ts` (2):** `KIND_VALUES` excludes `assignment_auto` (D-b9).
- **`availability.api.spec.ts` (4):** list query, create/update/delete verbs + paths (http mocked).
- **`AvailabilityCalendar.spec.ts` (7):** occurrence in the correct **tz day cell**; a recurring block's two instances **each render, no key collision** (D-b5); **reads `meta.window`** (D-b6); null-tz fallback renders without crash; empty state; click-a-day opens create seeded with the date; list-error alert.
- **`AvailabilityBlockDialog.spec.ts` (7):** create submits tz-correct UTC instants (no `recurrence_rule` on a one-off); **`assignment_auto` not in the kind options** (D-b9); **series-edit notice** present for recurring / absent for one-off (D-b8); 422 maps to the field via `extractFieldErrors`; recurring submit emits `FREQ=WEEKLY` (D-b11); two-step delete then API call.
- **`CreatorDashboardLayout.spec.ts` (5):** Dashboard + Availability render; active-state on each route; **tri-locale** label (en/pt/it); dark-theme render (D-b13).

**Results:** `apps/main` Vitest **678 passed** (75 files); `@catalyst/ui` **40 passed** (CMonthGrid +10); `apps/admin` **315 passed** (the shared `typography-consumption` mirror over `packages/ui` stays green). `pnpm typecheck:frontend` clean (5 workspaces); `pnpm lint:frontend` 0 errors (2 pre-existing `v-html` warnings, unrelated); `pnpm --filter @catalyst/main build` clean.

---

## Bundle note (Luxon honest-deviation trigger — D-b3)

The whole availability surface is a **lazy route chunk**: `AvailabilityPage` builds to **118 KB raw / 36.72 KB gzip**, which bundles Luxon + `VDatePicker` + the dialog. Luxon's share (~24 KB gzip, per the inventory) is **isolated to this route chunk** — it does not touch the main `index` bundle. This matches D-b3's accepted ~24 KB estimate; no materially-worse-than-expected impact. Trigger clears.

---

## Spot-check anchors

1. **Composite key — no recurring collision (D-b5)** — `AvailabilityCalendar.spec` "renders EACH occurrence of a recurring block without key collision" (same ULID, two weekly instances → two distinct `id|starts_at` bars in their own cells). Break-revert: key the `v-for` on `occurrence.id` alone → the second bar vanishes.
2. **tz round-trip + null fallback (D-b7)** — `datetime.spec` (round-trip epoch-equal; day-shift placement; `resolveTimezone(null)` → browser tz) + `AvailabilityCalendar.spec` (correct tz day cell; null-tz renders).
3. **`assignment_auto` not offered (D-b9)** — `options.spec` (`KIND_VALUES`) + `AvailabilityBlockDialog.spec` (the kind select's `items` exclude it).
4. **Weekly rule + `UNTIL`-instant (D-b11/D-b12)** — `recurrence.spec` (builder parts; `UNTIL` ≥ a 09:00 same-day occurrence + the midnight-trap regression) + the dialog recurring-submit test.
5. **Series-edit "all occurrences" messaging (D-b8)** — `AvailabilityBlockDialog.spec` (notice present for recurring, absent for one-off) + the two-step delete test.
6. **Reads `meta.window` (D-b6)** — `AvailabilityCalendar.spec` asserts the component's `loadedWindow` equals the response `meta.window`.
7. **Creator topbar nav, dark + tri-locale + active-state (D-b13)** — `CreatorDashboardLayout.spec`.
8. **Week view cleanly deferred (no overlap math leaked)** — `eachDayKey` is day-level only (end-exclusive midnight); no intra-day lane code exists; `tech-debt.md` records the deferral with the B2 effort rationale.

---

## Out of scope (logged at close)

- **Week view** → follow-on chunk (the timed-lane/overlap-packing weight, D-b1). Logged to `tech-debt.md` with the B2 effort rationale.
- **External calendar sync** (P2), **auto-blocks + conflict-modal trigger** (Sprint 8), **per-occurrence exceptions** (series-level only, D-b8) — unchanged from Chunk A.
- **Block-not-found 404 envelope** — `firstOrFail()` emits Laravel's default `{message}`, not the canonical `{errors:[…]}` envelope; an edge case (delete-in-another-tab). Logged to `tech-debt.md` as an **API-wide `ModelNotFoundException` renderer** concern — not fixed here (not a calendar matter).

## Docs updated

- `docs/tech-debt.md` — two new entries: the week-view deferral (B2 rationale) + the block-not-found-404 envelope note.
- `docs/services.md` — no change (per the kickoff).

---

## Commit pair (proposed — not committed until spot-check)

1. **feat(creators): availability calendar month view — grid, dialog, recurrence, nav** — `luxon` dep; `@catalyst/api-client` availability types; `@catalyst/ui` `CMonthGrid`; the availability module (api, `datetime`/`recurrence`/`options`/tz composable, `DateTimeField`, `AvailabilityBlockDialog`, `AvailabilityCalendar`, `AvailabilityPage`); route + creator topbar nav; the `availability` i18n namespace; the 8 test files + the `form-error-pattern` allowlist entry.
2. **docs(tech-debt,reviews): log Sprint 5 Chunk B — week-view deferral + 404-envelope note + chunk review** — `tech-debt.md` (two entries) + this review.
