# Sprint 6 — Chunk 2b Review

**Status:** Closed.

**Author:** Cursor (build + self-review draft).

**Scope:** The whole **Saved talent pools** feature (`20-PHASE-1-SPEC.md:211`) — and the close of Sprint 6. Two net-new tables (`talent_pools`, `talent_pool_creators`); agency-scoped pool **CRUD** mirroring `BrandController` (soft-delete + restore); a net-new **pivot-write membership** surface (add/remove a creator, composing the relation-exists gate); a simple **pool detail page** with a paginated member roster; and the **"add to pool"** picker affordance on the 2a creator-detail page. Frontend: pool pages + `PoolForm` + nav + routes + the picker dialog + api-client types + i18n (en/pt/it).

**Out of scope (logged at close):** brand-scoped **eligibility** (D-2b-4 — `brand_id` is a label, not a gate; no constraint added); anything campaign-related (Sprint 8); the admin SPA (pools are agency-side).

**Reviewed against:** `02-CONVENTIONS.md` (modular monolith, ULID, tenancy), `docs/security/tenancy.md` (relation-exists scope, 404-not-403 cross-tenant), `05-SECURITY-COMPLIANCE.md` §3.3/§7 (audit), `07-TESTING.md` §5.17 (defense-in-depth) + §5.35 (break-revert), the locked decisions **D-2b-1…D-2b-10**, and the Chunk-2b read-pass inventory.

---

## Divergences from the kickoff (surfaced at plan-pause)

1. **Pool detail page IS in scope (the plan-pause Q answered "yes, simple").** The list stays **counts-only** (D-2b-7); the member roster lives on a dedicated detail page and is **paginated (25/page)** so the signed-avatar minting in `TalentPoolMemberResource` is bounded to one page per request — the explicit D-2b-7 list/detail boundary. The detail page also carries the per-member **remove** affordance (admin/manager), so a pool is actually _curatable_, not just viewable.
2. **The picker fetch is a creator-nested read, not a pool-nested one.** `GET agencies/{agency}/creators/{creator}/talent-pools` (creator-centric — "which pools is _this_ creator in?") rather than hanging it off `talent-pools`. It reads more naturally for the dialog and keeps the pivot-write endpoints (D-2b-8) clean of a read concern.
3. **Idempotency method = `firstOrCreate` (the honest-deviation flag).** Add composes `firstOrCreate` keyed by the `(talent_pool_id, creator_id)` unique constraint: a genuine add → `201` + one audit row; an already-member → `200` no-op, **no second row, no second audit row** (gated on `wasRecentlyCreated`). Remove is `detach()` — idempotent by nature (removing a non-member → `200` no-op, audited only when a row was actually deleted). `syncWithoutDetaching` was the alternative; `firstOrCreate` was chosen because it lets `added_by_user_id` be set only on a real insert and gives a clean `wasRecentlyCreated` signal for the audit gate.
4. **Picker `is_member` is one query, not N (the fetch-shape flag).** `CreatorTalentPoolController` computes membership via a single `withExists(['creators as creators_exists' => fn => where creator])` correlated subquery across all the agency's pools — not one membership probe per pool. The resource maps `creators_exists` → `is_member`.

No _eligibility_ enforcement was added for brand-scoped pools (D-2b-4) — that's a label; the temptation was resisted and is covered by a break-revert-style positive test (a creator with no brand link is added to a brand-scoped pool successfully).

---

## What was built

### Backend (new `TalentPools` module)

- **Two migrations (D-2b-1/2).** `talent_pools` — `id` PK, `ulid` unique, `agency_id` FK **`restrictOnDelete`**, `brand_id` nullable FK **`nullOnDelete`**, `name` varchar(160), `description` text null, `created_by_user_id` nullable FK **`nullOnDelete`**, `timestamps()`, **`softDeletes()`**, **`unique(agency_id, name)`** = `unique_talent_pools_agency_name`. `talent_pool_creators` — surrogate `id` PK, `talent_pool_id` FK **`cascadeOnDelete`**, `creator_id` FK **`cascadeOnDelete`**, `added_by_user_id` nullable FK **`nullOnDelete`**, `timestamps()`, **`unique(talent_pool_id, creator_id)`** = `unique_talent_pool_creator` (house style: surrogate id + named composite unique, not a composite PK).
- **Models.** `TalentPool` (`BelongsToAgency` + `HasUlid` + `SoftDeletes`; `creators()` `belongsToMany(...)->using(TalentPoolMembership::class)->withTimestamps()` mirroring `Agency::members()`; `brand()` / `createdBy()`). `TalentPoolMembership` — first-class pivot (carries `added_by`), like `AgencyMembership`. Both declare `newFactory()` (the modular-path factory-resolution fix).
- **Pool CRUD (D-2b-6).** `TalentPoolController` `index`/`store`/`show`/`update`/`destroy`/`restore`, verbatim against `BrandController`: each non-index method `assertBelongsToAgency($pool, $agency)` (404-not-403) → `Gate::authorize(...)` → `Audit::log(...)`. `index` uses `withCount('creators')` (D-2b-7).
- **Membership (D-2b-8 — the no-precedent half).** `TalentPoolMembershipController`: `index` (paginated members, `view` gate), `store` (add, `firstOrCreate`, `update` gate), `destroy` (remove, `detach`, `update` gate). Every method composes **both** `assertBelongsToAgency` **and** `requireRosterRelation` (the exact pattern from `AgencyCreatorAvailabilityController`). Add/remove `Audit::log` (`talent_pool.creator_added` / `creator_removed`).
- **Picker (D-2b-9).** `CreatorTalentPoolController::index` — the single-query `is_member` fetch (divergence 4), `viewAny` gate + `requireRosterRelation`.
- **Policy (D-2b-6).** `TalentPoolPolicy` mirrors `BrandPolicy`: view/viewAny = any accepted member; create/update/archive/restore = admin or manager; delete = admin only.
- **Requests + resources + audit.** `CreateTalentPoolRequest` / `UpdateTalentPoolRequest` (name unique-within-agency, brand-ownership check) / `AddPoolCreatorRequest`; `TalentPoolResource` (+ `creators_count`), `TalentPoolMemberResource` (slim creator + bounded signed avatar + `added_at` pivot ts), `TalentPoolPickerResource` (`is_member`). Six `AuditAction` cases (`talent_pool.created/updated/archived/restored/creator_added/creator_removed`). `TalentPoolsServiceProvider` registers the policy + routes; added to `bootstrap/providers.php`.

### Frontend (`apps/main`, new `pools` module + api-client + roster touch)

- **Pages + form (D-2b-10).** `PoolListPage` (counts, status filter, archive/restore dialogs — Brand-mirrored), `PoolCreatePage` / `PoolEditPage` wrapping `PoolForm` (name / description / optional brand), `PoolDetailPage` (paginated member roster + per-member remove, admin/manager).
- **Picker dialog (D-2b-9).** `AddToPoolDialog` — lists the agency pools with a per-pool `v-switch` reflecting `is_member`; toggling calls the add/remove endpoints. A header **"Add to pool"** button on `CreatorDetailPage` (net-new chrome on the 2a header), gated by the page's existing `canEdit`; reuses a snackbar success pattern.
- **Nav + routes + arch-tests (D-2b-10).** A `pools` nav item in `AgencyLayout` (`app.nav.pools`). Four routes (`pools.list/create/detail/edit`, `layout: 'agency'`, `guards: ['requireAuth','requireAgencyUser']`). The `requireAgencyUser` arch-test expected set grew by the four pool routes; the sibling **MFA arch-test** confirms the pool routes are **not** in the MFA-gated set (it pins that set at exactly the three admin-sensitive routes — pools, being non-admin, are excluded and the pin holds).
- **api-client + i18n.** `talentPools.api.ts` wrapper + `TalentPool*` types in `@catalyst/api-client`; `app.pools.*` / `app.picker.*` / nav + error strings in **en/pt/it**.

---

## Coverage (§5.17; break-revert §5.35)

**Backend — `tests/Feature/Modules/TalentPools/` (41 tests):**

- **CRUD** (`TalentPoolCrudTest`): agency-scoped list; cross-tenant `show` → **404** (break-revert: `assertBelongsToAgency`); 401 unauthenticated; the **policy matrix** (admin/manager create/update/archive; **staff 403**; admin-only delete-is-not-exposed); soft-delete archive **preserves membership rows** (D-2b-3) + restore (admin); restore of an active pool is an idempotent no-op (no audit); audit on create/update/archive/restore; **`unique(agency_id, name)`** rejects a dup in-agency (break-revert) but allows the same name across agencies; re-saving the same name is not a false 422; a **null-brand** and a **brand-scoped** pool both create; a `brand_id` from another agency is rejected; the integer `id` is never exposed.
- **Membership** (`TalentPoolMembershipTest`): admin/manager add + remove; **staff 403** on both; **add requires the relation** — a no-relation creator → **404** (break-revert: drop `requireRosterRelation`); **add is idempotent** — twice → one row, not a 500/dup (break-revert: `firstOrCreate`); **brand-scope adds no eligibility constraint** — a creator with no brand link is added to a brand-scoped pool (D-2b-4); add composes the **agency-owns-pool** check — another agency's pool → 404; add/remove **audit-logged**; paginated member list for any member; **picker** lists pools with the `is_member` flag (single query) + **404s** a no-relation creator.

**Frontend (jsdom, heavy Vuetify stubbed — 23 specs):**

- `PoolListPage.spec.ts` (6) — renders the **count, not member rows** (D-2b-7); create btn admin-only; view/edit/archive/restore visibility by role + status; restore round-trip + toast.
- `PoolForm.spec.ts` (4) — submit wiring; the optional brand field.
- `PoolDetailPage.spec.ts` (5) — name/members render; per-member remove (admin/manager) vs hidden (staff); remove round-trip.
- `AddToPoolDialog.spec.ts` (5) — fetch on open; the toggle reflects `is_member` (in/out rows); empty state; toggle calls add/remove.
- Arch: `agency-routes-agency-user-guard.spec.ts` set grown by the four pool routes (break-revert: drop a guard → fail); `agency-routes-mfa-guard.spec.ts` holds (pools NOT MFA-gated).

**E2E:** `playwright/specs/talent-pools.spec.ts` — empty state → create → list shows the **count**; and the **add-to-pool round-trip** on the detail page (header button → picker → toggle on → the creator lands in the pool's member roster). Runs in the `e2e-main` job against the live stack (not executed in this local pass).

---

## Gate results

- **Backend:** `tests/Feature/Modules/TalentPools` **41/41** green; `AuditActionEnumTest` green with the six new cases. `composer pint --test` **passed**; `composer stan` (Larastan) — **No errors**.
- **Frontend:** pools jsdom specs **23/23** + both arch-tests green; `vue-tsc --noEmit` clean; `eslint` clean on the pools module + touched files + the Playwright spec.

---

## Spot-check anchors

- The two migrations match D-2b-1/2 (FKs, on-delete, the named uniques `unique_talent_pools_agency_name` + `unique_talent_pool_creator`, soft-delete).
- Membership add composes **BOTH** tenancy checks — `requireRosterRelation` (break-revert: no-relation → 404) **AND** `assertBelongsToAgency` (break-revert: other agency's pool → 404).
- Add is idempotent (`firstOrCreate`; break-revert: twice → one row).
- `unique(agency_id, name)` enforced (break-revert).
- Brand-scope adds **no** eligibility gate (D-2b-4 — positive test: no-brand-link creator added to a brand-scoped pool).
- Policy matrix: admin/manager write, **staff 403**; admin-only delete not exposed.
- List shows **counts**, not previews (D-2b-7); the roster lives on the paginated detail page.
- The picker toggles call add/remove + reflect current membership (`is_member`, one query).
- Pool routes carry `requireAgencyUser` (arch-test grown) and are **not** MFA-gated (sibling pin holds).
- Sprint-6 `tech-debt.md` entry closed.

---

## Docs follow-up

- **`tech-debt.md`:** the broader **"Agency-side prospect/invited creators list"** Sprint-6 entry is **CLOSED** — its full-version scope (roster + filters + FTS → Chunk 1; per-creator detail → Chunk 2a; **saved talent pools → this chunk**) is delivered. Annotated what remains _separately_ open (handle search, a real availability filter, follower/engagement filters — each blocked on net-new infra, not this surface).
- **`services.md`:** no change.
- The `requireAgencyUser` arch-test set growth is code, not docs.

---

## Commit plan (two-commit pair, not committed until spot-check)

1. `feat(talent-pools): saved talent pools — CRUD + pivot-write membership + relation-exists gate` — the two migrations, `TalentPool` + `TalentPoolMembership` models + factories, `TalentPoolController` + `TalentPoolMembershipController` + `CreatorTalentPoolController`, `TalentPoolPolicy`, requests + resources, the six `AuditAction` cases, routes + service provider + `bootstrap/providers.php`, backend tests + catalogue update.
2. `feat(main): talent-pool pages + add-to-pool picker + nav/routes` — api-client types, `talentPools.api.ts`, `PoolForm` + the four pages, `AddToPoolDialog` + the `CreatorDetailPage` button, nav item + routes + arch-test growth, i18n (en/pt/it), FE specs + Playwright, tech-debt close.
