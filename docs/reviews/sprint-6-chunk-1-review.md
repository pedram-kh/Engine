# Sprint 6 — Chunk 1 Review: Agency roster search (FTS) + filter affordances

**Status:** Closed. Spot-check passed (no PMC) — the D-3 untestable seam was verified handled honestly (correct + fully-tested ILIKE fallback, dormant Postgres `markTestSkipped` counterpart + a real live-Postgres 16 run, divergence documented in three places + the Playwright spec searches a full token), both confirmed divergences landed correctly, the guard ordering + the MFA-arch-test order-tuple ripple were surfaced not hidden, and the not-run-locally Playwright call + bounded `_test` seeder were flagged honestly.

**Reviewer:** drafted by Cursor (build pass); spot-checked + closed.

**Reviewed against:** the Chunk-1 kickoff (locked decisions D-1…D-7, the honest-deviation triggers, the spot-check anchors) + `PROJECT-WORKFLOW.md` § 5 standards (5.17 defense-in-depth, 5.35 break-revert claim verification, 5.36 asymmetric-coverage acknowledgement, source-inspection arch-tests) + the extended Sprint 4 Chunk 5 roster + the verified Chunk-1 read-pass inventory.

This chunk extends the Sprint 4 Chunk 5 roster with the one real spine the inventory left standing — **name/bio full-text search** — plus two **inert filter affordances** (availability + metrics), the **`requireAgencyUser`** guard close, and a **Playwright roster spec** that resolves the jsdom heavy-component tech-debt. Everything else the spec floated (handle search, a real availability filter, real metrics filters) is deferred and logged.

---

## What shipped

### D-1 / D-3 — FTS name/bio search (the spine)

- **Migration** `apps/api/database/migrations/2026_06_03_100001_add_search_vector_to_creators_table.php` — a **pgsql-guarded** STORED generated column `search_vector = to_tsvector('simple', coalesce(display_name,'') || ' ' || coalesce(bio,''))` + `idx_creators_search_gin` GIN index. The whole `ALTER`/index sits behind `getDriverName() === 'pgsql'`, mirroring the existing `idx_creators_categories_gin` block — so **the column never exists on SQLite** and the test schema is untouched (honest-deviation trigger #1: resolved by guard, not fought).
- **Driver-aware helper** `AgencyCreatorController::applySearchFilter` — the one filter that needs an explicit `if (pgsql)` branch (FTS has no portable grammar degrade like `whereJsonContains`):
  - **Postgres:** `whereRaw("search_vector @@ plainto_tsquery('simple', ?)", [$q])` — served by the GIN index.
  - **SQLite (CI + local dev):** `LOWER(display_name) LIKE ? ESCAPE '\'` OR `LOWER(bio) LIKE ?` — substring match over the raw columns (no `search_vector` reference). Wildcards (`%`/`_`/`\`) are escaped with a declared `ESCAPE` char (the `MembershipController` precedent omits the ESCAPE clause, so its `\%` escaping is inert — this chunk's is correct).
- **`?q=` threaded** controller → `roster.api.ts` (`RosterListParams.q` on `@catalyst/api-client`) → the page's debounced search box. Trimmed; blank/whitespace is a no-op.

### D-4 — disabled affordances (the KYC-button idiom)

`CreatorRosterPage.vue` renders three present-but-inert controls — **followers**, **engagement rate** (metrics), and **availability** — each a `disabled` `v-select` wrapped in a `<span v-bind="tooltipProps">` inside a `v-tooltip` (a disabled control emits no hover, so the tooltip attaches to the wrapping span — the exact Chunk-3 KYC precedent, copied into `apps/main` since it's not importable cross-SPA). They have **no `v-model` and no watcher**, so they **cannot issue a query** (a 0-results from an empty-data query would read as broken). Driven by static FE constants `METRICS_FILTERS_CONNECTED` / `AVAILABILITY_FILTER_CONNECTED` (`false` this chunk) — there's no backend signal to drive them yet (no `kyc_vendor_available` equivalent), which is the accepted state per D-4; flip + wire the real control behind the `v-else` when the blocking infra lands.

### D-7 — `requireAgencyUser` guard close

`requireAgencyUser` added to `guards.ts` (+ `GuardName` union + registry), redirecting `user_type === 'creator'` → `onboarding.welcome-back` and falling through for every other type. Wired **second** (`requireAuth → requireAgencyUser → [requireMfaEnrolled → requireAgencyAdmin]`) so a creator is bounced before the MFA/admin checks. Applied to every **agency-shell** route (`layout: 'agency'`). The route-walking arch-test `agency-routes-agency-user-guard.spec.ts` pins the invariant.

### D-6 — Playwright roster spec + jsdom tech-debt close

`playwright/specs/roster-search-and-affordances.spec.ts` drives the **real** `v-data-table-server` against seeded rows: it asserts the table renders both creators, the search narrows it (and since CI's API runs Postgres, this is a **live** exercise of the FTS path), and the disabled affordances render disabled + deliver a hover tooltip via the span-wrap idiom + don't filter. A new bounded `_test/agencies/{agency}/roster-creators` helper seeds approved creators + accepted relations. With the table DOM now covered in Playwright, `CreatorRosterPage.spec.ts` stubs the heavy components freely (`VSelect`, `VDataTableServer`, `VTextField`).

### i18n

`app.roster.search.*` (label/placeholder) + `app.roster.affordances.*` (followers/engagement/availability labels + the two tooltips) added to **en / pt / it**.

---

## Locked-decision divergences (surfaced at plan-pause, both confirmed)

1. **D-7 scope — "every `appRoutes` entry" → every agency-shell route.** `appRoutes` includes `accept-invitation`, a public pre-auth landing (`layout: 'auth'`, **no `requireAuth`**) where a guard that assumes a resolved user cannot run. The original tech-debt entry enumerated only the agency-shell routes and omitted `accept-invitation`, so this matches the _intent_. The guard is applied to all 9 `layout: 'agency'` entries; the arch-test asserts (1) the full agency-shell set verbatim (blocks silent narrowing/broadening), (2) every such route carries the guard after `requireAuth`, (3) `accept-invitation` does **not** carry it. The exception is documented inline in `routes.ts`. **Confirmed: agency-layout scope.**
2. **FTS config — `'simple'` (no stemming).** Chosen to minimize the FTS-vs-ILIKE divergence. **Confirmed.**

## Honest deviations & notes

- **FTS vs ILIKE result semantics differ — documented, not papered over (D-3, trigger #2).** Postgres matches whole-word **lexemes**; the SQLite fallback matches **substrings**. e.g. `q=lov` matches "Lovelace" under SQLite but not Postgres; `q=lovelace` matches under both. `'simple'` (no stemming) keeps them as close as practical. The divergence is called out in `applySearchFilter`'s docblock + the migration docblock + here. The Playwright spec deliberately searches a **full token** (`lovelace`) so it passes under both drivers.
- **The untestable seam, handled honestly (D-3 — the spot-check anchor).** The SQLite `ILIKE` fallback is the path CI + local dev actually run, and it's fully tested (name, bio, unmatched→0 break-revert, blank no-op, wildcard escaping). The Postgres FTS branch is **un-unit-testable under the SQLite `:memory:` suite**, so it ships with a dormant `markTestSkipped()` counterpart (`it('[postgres-only] matches name/bio via to_tsvector @@ plainto_tsquery')`) that goes live the day a Postgres CI job lands (~Sprint 8). **Manual local-Postgres verification done:** the full roster suite was run against the live local Postgres 16 (`catalyst-postgres`, port 5435) on a throwaway `catalyst_test` DB — **all 24 tests green, including the otherwise-skipped FTS assertion**; the pgsql-guarded generated-column migration applied cleanly (valid syntax on the pinned Postgres) and `search_vector @@ plainto_tsquery` returned the expected single row. The test DB was dropped after. This is the one place "green CI" is not full proof, and the chunk says so.
- **New `_test` roster-seed helper.** D-6 needs real rows for the Playwright table; no production path provisions an agency roster in one call. `CreateRosterCreatorsController` follows the `CreateAgencyWithAdminController` pattern verbatim (token-gated, 404 when closed, validated, no production wiring) and is bounded to 1..50 creators. This is net-new test infra, flagged here.
- **Pre-existing MFA arch-test updated.** Inserting `requireAgencyUser` second changed the exact-order tuple the MFA arch-test pins for `agency-users.list` / `creator-invitations.bulk`; the assertion + its docblock were updated to `requireAuth → requireAgencyUser → requireMfaEnrolled → requireAgencyAdmin`. The selective-MFA-gating pin is unaffected.
- **Playwright not run locally.** `global-setup.ts` runs `migrate:fresh --force` against the API's configured DB (the dev `catalyst` Postgres), so running the E2E suite locally would wipe dev data. The spec is compile-verified + discovered (`playwright test --list`) and mirrors the established harness exactly; it runs in the `e2e-main` CI job against that job's ephemeral Postgres (§5.36 asymmetric-coverage acknowledgement).
- **Deferred, per the kickoff (no temptation indulged):** handle search (D-2) and a real availability filter (D-5) are logged as new tech-debt entries (D-5 with both cheap-signal options named); real metrics filters wait on social adapters.

---

## Coverage (§5.17; break-revert §5.35)

| Area                           | Coverage                                                                                                                                                                                                                                                                                 |
| ------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| FTS `ILIKE` fallback (CI path) | narrows by **name** + by **bio**; **unmatched→0 break-revert** (drop the `q` branch → returns all → the `toBe(0)` fails); blank/whitespace no-op; literal-`%` wildcard-escape. (`AgencyCreatorRosterTest`, SQLite)                                                                       |
| FTS Postgres branch            | dormant `markTestSkipped()` counterpart asserting `to_tsvector @@ plainto_tsquery` (live under Postgres CI; verified manually against local PG — green)                                                                                                                                  |
| Disabled affordances           | component spec: all three render **disabled**, span-wrap activators exist, **only 1 API call** (the mount load) → they issue no query (break-revert: wiring one to a query bumps the count). Playwright: disabled class + hover tooltip + table unchanged                                |
| Debounced search               | component spec: no query at 299ms, exactly one query with **trimmed** `q` at 300ms                                                                                                                                                                                                       |
| `requireAgencyUser`            | guard unit tests (creator→welcome-back; agency_user/platform_admin/brand_user fall-through; defensive no-user; registry) + arch-test (full agency-shell set; guard-after-auth on each; accept-invitation excluded). Break-revert: drop the guard from one agency route → arch-test fails |
| Playwright roster              | real table renders both rows; search narrows (live FTS in CI); affordances disabled + tooltip + don't filter                                                                                                                                                                             |

---

## Verification results

| Gate                                                                      | Result                                                                              |
| ------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| `apps/main` Vitest                                                        | **695 / 695** (76 files) — incl. the new affordance + debounce + guard + arch tests |
| `apps/api` Pest — `AgencyCreatorRosterTest` (SQLite)                      | **23 passed, 1 skipped** (the Postgres-only FTS test)                               |
| `apps/api` Pest — same suite vs **live local Postgres** (`catalyst_test`) | **24 passed** (the FTS test runs + passes) — manual D-3 verification                |
| PHPStan (changed files, level per `phpstan.neon`)                         | 0 errors                                                                            |
| Pint                                                                      | clean (auto-fixed import ordering in the roster test)                               |
| `vue-tsc --noEmit` (apps/main)                                            | 0 errors                                                                            |
| ESLint (changed FE files)                                                 | 0 errors                                                                            |
| `playwright test --list` (new spec)                                       | compiles + discovered (1 test)                                                      |
| Playwright run                                                            | CI `e2e-main` job (not run locally — `migrate:fresh` would wipe dev DB)             |

---

## Files touched

**Backend (`apps/api`):**

- `database/migrations/2026_06_03_100001_add_search_vector_to_creators_table.php` — **new**: pgsql-guarded `tsvector` generated column + GIN.
- `app/Modules/Agencies/Http/Controllers/AgencyCreatorController.php` — `?q=` read + driver-aware `applySearchFilter`; docblock filter-list updated.
- `app/TestHelpers/Http/Controllers/CreateRosterCreatorsController.php` — **new**: roster-seed helper for the Playwright spec.
- `app/TestHelpers/Routes/api.php` — register the roster-seed route.
- `tests/Feature/Modules/Agencies/AgencyCreatorRosterTest.php` — FTS tests (ILIKE fallback + break-revert + dormant Postgres counterpart).

**Shared:**

- `packages/api-client/src/types/agency.ts` — `RosterListParams.q`.

**Frontend (`apps/main`):**

- `src/modules/roster/api/roster.api.ts` — serialize `q`.
- `src/modules/roster/pages/CreatorRosterPage.vue` — debounced search box + the three disabled affordances + docblock.
- `src/modules/roster/pages/CreatorRosterPage.spec.ts` — stub heavy components freely; add search-debounce + affordance tests.
- `src/core/router/guards.ts` — `requireAgencyUser` + registry.
- `src/modules/auth/routes.ts` — `GuardName` union + guard on the 9 agency-shell routes + accept-invitation exception note.
- `tests/unit/core/router/guards.spec.ts` — `requireAgencyUser` unit tests.
- `tests/unit/architecture/agency-routes-agency-user-guard.spec.ts` — **new** arch-test.
- `tests/unit/architecture/agency-routes-mfa-guard.spec.ts` — exact-order assertion updated for the inserted guard.
- `playwright/specs/roster-search-and-affordances.spec.ts` — **new** E2E spec.
- `playwright/fixtures/test-helpers.ts` — `seedRosterCreators`.
- `playwright/helpers/selectors.ts` — roster `data-test` ids.
- `src/core/i18n/locales/{en,pt,it}/app.json` — `app.roster.search.*` + `app.roster.affordances.*`.

**Docs:**

- `tech-debt.md` — **closed** FTS / jsdom-heavy-component / `requireAgencyUser` entries; **added** deferred real-availability-filter (two options named) + deferred handle-search entries.
- `reviews/sprint-6-chunk-1-review.md` — this file.

`services.md` — no change (no new service / cross-module wiring).

---

## Proposed commit shape (two-commit pair — not yet committed; awaiting spot-check)

1. `feat(roster): name/bio FTS search + disabled filter affordances + requireAgencyUser guard (Sprint 6 Chunk 1)` — the migration, controller, api-client type, page, guard, routes, i18n, and all tests (backend + component + arch + Playwright + the `_test` seeder).
2. `docs(tech-debt,reviews): close FTS/jsdom/requireAgencyUser debt + log deferred availability/handle search + Chunk 1 review` — `tech-debt.md` + this review.

(Or split backend/frontend if the reviewer prefers — the surfaces are cohesive either way.)

---

## Spot-check anchors

- **ILIKE fallback correct + tested (CI path):** `AgencyCreatorRosterTest` name/bio + unmatched-→0 break-revert, all green on SQLite.
- **Postgres FTS branch:** dormant `markTestSkipped` counterpart present **and** manually verified against live local Postgres (24/24 incl. the FTS test) — recorded above.
- **Affordances disabled + issue no query:** component spec (disabled + only-1-call) + Playwright (disabled class + tooltip + table unchanged).
- **`requireAgencyUser` redirects creators + arch-test covers every agency route:** guard unit tests + `agency-routes-agency-user-guard.spec.ts`.
- **Playwright roster spec exercises real table DOM:** `roster-search-and-affordances.spec.ts` (real `v-data-table-server`).
- **Deferred entries present:** real-availability-filter (cheap-signal design problem, options (a)/(b) named) + handle-search, both in `tech-debt.md`.

---

_Provenance: drafted by Cursor (Sprint 6 Chunk 1 build pass, 2026-06-03); spot-check passed (no PMC) and chunk closed. Shipped as the two-commit pair per `PROJECT-WORKFLOW.md` § 3._
