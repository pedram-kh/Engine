# Pool-side "Add creators" — Review

**Status:** Closed.

**Reviewer:** drafted by Cursor (implementation); reviewed + spot-checked (independent pass, 2026-06-04) — passed, no PMC.

**Reviewed against:** the pool-add kickoff (single chunk, frontend-only) and its locked decisions D-1..D-5 + the D-7 promote-options; the existing pool surfaces ([`PoolDetailPage.vue`](../../apps/main/src/modules/pools/pages/PoolDetailPage.vue), [`AddToPoolDialog.vue`](../../apps/main/src/modules/pools/components/AddToPoolDialog.vue)); the reused backend write ([`TalentPoolMembershipController::store`](../../apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolMembershipController.php)).

This chunk closes the discoverability gap found in the eyes-on pass: pool membership was creator-centric only — the pool page listed + removed members but had **no add entry point**. It adds a pool-side "Add creators" button + a roster-sourced picker dialog, reusing the existing idempotent, relation-gated `store` with **zero backend changes**.

---

## Plan confirmation — all five locks held

- **D-1 — Pure frontend, reuse the existing write.** Confirmed against the controller: `store` is idempotent (`firstOrCreate` keyed on `(talent_pool_id, creator_id)`), gated by `Gate::authorize('update', $talentPool)` (admin/manager — `TalentPoolPolicy::update`) and `requireRosterRelation()`. No new endpoint/route/resource/migration; the add path is `talentPoolsApi.addCreator` (already existed).
- **D-2 — Picker sourced from the ROSTER** (`rosterApi.list`), not discovery. Correct-by-construction: every roster row carries the `creator_id` ULID `store` consumes, and every roster creator has an `AgencyCreatorRelation`, so `requireRosterRelation()` can never reject a roster-sourced add.
- **D-3 — Exclude current members client-side.** The dialog fetches `talentPoolsApi.members` on open + subtracts member ULIDs from the roster in the FE. Page-local/partial on a large pool — harmless because the idempotent `store` makes a missed exclusion a no-op (re-add does nothing).
- **D-4 — Multi-add loops the single `store`.** Per selected creator, one `addCreator` call.
- **D-5 — Picker search is client-side.** A search box filters the fetched roster page locally by name.

---

## Honest deviations & notes

- **Roster rows carry no `avatar_url`.** The kickoff F2 said "avatar + display_name + country — lift the pool member row rendering." But `RosterCreatorListItem` exposes `display_name` + `country_code` + `creator_id` only; **only the member resource carries `avatar_url`**. The picker therefore renders an **initials-only avatar** (the same fallback the member list uses when `avatar_url` is null). Cosmetic, FE-only, no backend reach for a signed URL.
- **`creator_id` is nullable on roster rows.** The picker filters out any row with a null/empty `creator_id` (not addable) alongside the member exclusion, so only addable creators are ever offered.
- **Roster fetched at `per_page: 100` (single wide page).** To widen the client-side search + exclusion coverage without a server round-trip, the dialog requests one large page rather than the default 25. Still single-page and zero-backend; the server `?q=` FTS stays deferred (D-7 #3).
- **Multi-add UX is multi-select + one "Add" button** (the kickoff offered "multi-select or per-row add"). Checkboxes per row + an "Add {n}" action that loops `store`, then closes the dialog and reloads.
- **Reload uses a fresh fetch, not the `store` response.** Although `store` returns the refreshed `TalentPoolResource` (with `creators_count`), a multi-add loop + client-side exclusion is simplest to reconcile by reloading both the pool (for the count) and the member list (for the roster) on `added` — mirroring the existing remove flow's `loadMembers()`.

No locked decision was diverged from; no backend change was tempted. The diff is **FE + i18n + tests only** (`git status` confirms: pools components/page, the three locale `app.json`s, the pools specs, the Playwright spec, and docs).

---

## The build

**New — picker dialog:** [`AddCreatorsToPoolDialog.vue`](../../apps/main/src/modules/pools/components/AddCreatorsToPoolDialog.vue)

- On open: `Promise.all([rosterApi.list(agencyId, { per_page: 100 }), talentPoolsApi.members(agencyId, poolId, { per_page: 25 })])`.
- `available` = roster rows with a non-null `creator_id` minus the fetched member ULIDs (D-3); `filtered` = `available` narrowed by the client-side name search (D-5).
- Empty/edge states: no-roster, all-already-in-pool, no-search-match; loading skeleton; error alert (mirrors `AddToPoolDialog`).
- "Add {n}" loops `addCreator` per selected ULID (D-4), emits `added` (count message) + closes; the parent reloads.

**Wired — pool detail page:** [`PoolDetailPage.vue`](../../apps/main/src/modules/pools/pages/PoolDetailPage.vue)

- "Add creators" button in the page header beside the gated Edit button, **gated by the existing `canWrite`** (`isAdmin || currentRole === 'agency_manager'`) — the same gate the inline remove uses, so add mirrors remove and agrees with the backend `TalentPoolPolicy::update`.
- `onCreatorsAdded` shows the success snackbar + reloads pool (count) and members (roster).

**i18n:** new `app.pools.addCreators.*` keys (button label, title, search label, no-roster / all-in-pool / no-search-match empty states, cancel, add, success toast, load/add errors) in **en / pt / it**.

---

## Coverage

**Component — [`AddCreatorsToPoolDialog.spec.ts`](../../apps/main/src/modules/pools/components/AddCreatorsToPoolDialog.spec.ts)** (mocks `rosterApi.list` + `talentPoolsApi.members`/`addCreator`):

- fetches roster + members on open (D-2/D-3);
- excludes current members client-side (an in-pool roster creator isn't offered);
- all-already-in-pool empty state; no-roster empty state;
- selecting creators + Add loops `store` per creator + emits `added` + closes;
- client-side search filters the list (D-5);
- **idempotency safety (D-3):** adding a creator the partial exclusion still showed is a harmless no-op (one call, no error, `added` emitted).

**Component — [`PoolDetailPage.spec.ts`](../../apps/main/src/modules/pools/pages/PoolDetailPage.spec.ts)** (additions): the "Add creators" button is gated `canWrite` (manager sees it; staff doesn't); the `added` event reloads pool + members and sets the snackbar.

**Playwright — [`talent-pools.spec.ts`](../../apps/main/playwright/specs/talent-pools.spec.ts)** (new test, reuses the existing seeds/selectors): open an empty pool → "Add creators" → tick a rostered creator → submit → the creator lands in the member roster + the detail count bumps to 1. (The existing E2E does the creator-page add; this adds the pool-page entry point.)

---

## Verification results

| Gate                                     | Result                                                                                                 |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| `apps/main` Vitest — `src/modules/pools` | **30 / 30** (5 files) — was 21 at entry (+9: AddCreatorsToPoolDialog 7, PoolDetailPage +2... see note) |
| `pnpm --filter @catalyst/main typecheck` | 0 errors                                                                                               |
| `pnpm --filter @catalyst/main lint`      | 0 errors (2 pre-existing `v-html` warnings in unrelated onboarding files)                              |
| `git status` diff scope                  | FE + i18n + tests + docs only — **zero backend** (no route/controller/resource/migration)              |

Test-count note: pools suite 21 → 30 (+9 net = AddCreatorsToPoolDialog 7 new + PoolDetailPage 3 new − 1; PoolDetailPage went 5 → 8). Playwright not run in this pass (requires the API server + seeds); the spec mirrors the established `talent-pools.spec.ts` patterns.

---

## Files touched

**Frontend (`apps/main`):**

- `src/modules/pools/components/AddCreatorsToPoolDialog.vue` — **new** roster-sourced picker.
- `src/modules/pools/components/AddCreatorsToPoolDialog.spec.ts` — **new** component spec.
- `src/modules/pools/pages/PoolDetailPage.vue` — "Add creators" button (canWrite-gated) + dialog wiring + `onCreatorsAdded` reload.
- `src/modules/pools/pages/PoolDetailPage.spec.ts` — button-gating + added-reload tests; mock `addCreator` + stub `rosterApi`.
- `src/core/i18n/locales/{en,pt,it}/app.json` — `app.pools.addCreators.*` keys.
- `playwright/specs/talent-pools.spec.ts` — pool-page add round-trip test.

**Docs:**

- `docs/tech-debt.md` — one entry logging the three D-7 promote-options (server-side exclusion annotation, batch add endpoint, server-side picker search) with their triggers.
- `docs/reviews/pool-add-creators-review.md` — this file.

`services.md` / `tenancy.md` / data-model — **no change** (FE-only, no new routes/tables).

---

## Out of scope (logged in `tech-debt.md`, D-7, with promote-triggers)

1. **Per-pool `is_member` roster annotation** (server-side exact exclusion) — trigger: client-side partial exclusion on large pools becomes visibly confusing.
2. **A batch add endpoint** — trigger: agencies routinely add many creators at once and the per-creator loop is slow/janky.
3. **Server-side picker search** (`?q=` FTS) — trigger: rosters grow large enough that client-local search misses creators beyond the fetched page.

---

## Spot-check anchors

- **Gate:** the "Add creators" button uses the same `canWrite` (`isAdmin || agency_manager`) as the inline remove — admin/manager see it, staff don't; backend `TalentPoolPolicy::update` agrees.
- **Roster-sourced (D-2):** every offered creator comes from `rosterApi.list` and is addable (has a relation → `requireRosterRelation()` can't reject).
- **Client exclusion + idempotent safety (D-3):** current members are filtered out in the FE; a partial-exclusion re-add is a harmless `store` no-op (covered by a dedicated test).
- **Multi-add loop + reload (D-4):** N selections → N `store` calls → member list + count reload.
- **All-already-in-pool empty state** renders when the filtered roster is empty.
- **Zero backend changes:** confirm the diff is FE + i18n + tests + docs only.

---

_Provenance: drafted by Cursor (pool-add build pass, 2026-06-04). Spot-checked + closed 2026-06-04 (no PMC); committed as a single FE-only changeset._
