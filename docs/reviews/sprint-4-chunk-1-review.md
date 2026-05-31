# Sprint 4 — Chunk 1 Review (Agency dashboard + harness gaps)

**Status:** **Closed.** Build complete, all gates green, spot-check passed, three pre-merge corrections (PMC #1–#3) landed — see "Pre-merge corrections" below.

**Reviewer:** drafted by Cursor (implementation); spot-checked + closed by the reviewer after PMC #1–#3.

**Reviewed against:** the Chunk 1 kickoff + the plan-approval message (Q1 `is_blacklisted` both-KPIs boolean-only; Q2 commit grouping + the 1c-anchors condition; the three refinements — route repoint-in-place, feed metadata whitelist, signal-curated allowlist); `01-UI-UX.md` §11 (workspace home) + §2.3 (aurora); `02-CONVENTIONS.md` §1 (shared-package extraction); `PROJECT-WORKFLOW.md` §5 (5.1 source-inspection, 5.8 reasoned removal, 5.15 deliberate-allowlist, 5.17 defense-in-depth, 5.35 break-revert-with-`git`-restore, 5.36 asymmetric-coverage-acknowledgement), §6 (sub-chunk planning), §8 (tech-debt append-don't-delete).

The chunk has two goals: **replace the `/` placeholder with a real agency dashboard**, and **close two test-harness tech-debt entries**. Split into three sub-steps: **1a** harness infra, **1b** dashboard core + summary endpoint, **1c** activity feed. Sectioned distinctly below; 1c carries its own spot-check anchors.

---

## 1a — `packages/ui` theme-aware test harness (closes BOTH harness tech-debt entries)

**What shipped:**

- **`packages/ui` now has its own Vitest harness** (was a `echo 'no tests yet'` no-op):
  - `package.json` — `test` → `vitest run`; devDeps added at `apps/main`'s pins (`vitest ^2.1.8`, `@vue/test-utils ^2.4.6`, `jsdom ^25.0.1`, `@vitejs/plugin-vue ^5.2.1`). The Vue plugin is the one addition the kickoff list implied but didn't name — `packages/ui` has no Vite build of its own, so Vitest needs it to compile SFCs (design-tokens didn't, having no `.vue`).
  - `vitest.config.ts` — jsdom + `@vitejs/plugin-vue` + `vuetify` inlined via `server.deps.inline` (mirrors `apps/main`). No coverage gate (mirrors `packages/design-tokens`).
  - `tests/setup.ts` — the Vuetify jsdom polyfills (ResizeObserver / IntersectionObserver / matchMedia / CSS.supports / visualViewport), mirrored from `apps/main/tests/unit/setup.ts`.
- **`tests/helpers/mountThemed.ts`** — the theme-aware mount helper: builds Vuetify with the **real Catalyst `light`/`dark` themes** (from `@catalyst/design-tokens/vuetify`), **dark-default**, mode-parameterized. Deliberately **no `defaults` block** (preserves the `CButton` "no inline border-radius" D-fork-a assertion).
- **Migrated** the two co-located SPA specs (`CButton`, `CEmptyState`) into `packages/ui/tests/components/`, rewired to `mountThemed`; deleted them from `apps/main/tests/unit/components/`; removed `CEmptyState.vue`'s "tests live in the consuming SPA" docblock + the tech-debt citation.
- **First systematic dark-vs-light rendering assertion:** `CButton` mounted dark carries `v-theme--dark` (and `v-theme--light` in light) — the assertion the stock-theme harness never had.
- `tsconfig.json` `include` extended to `tests/**/*` so the harness is typechecked (parity with design-tokens, whose specs live in `src`).

**Import-form flag (kickoff trigger):** specs import the component-under-test **relatively** (`../../src/components/<C>.vue`) — unambiguous under the Vite/Vitest resolver; a `@catalyst/ui` self-reference resolves via the `exports` field in principle but adds a barrel round-trip. The cross-package `@catalyst/design-tokens/vuetify` import is a genuine workspace dep and resolves normally.

**Both tech-debt entries flipped** (`docs/tech-debt.md`):

1. _"packages/ui has no test harness"_ → **CLOSED**.
2. _"Component-test harness renders under Vuetify's stock theme"_ → **CLOSED (narrowed)**, with two honest corrections:
   - **Accurate-residual correction:** the original "nothing renders against the dark theme" framing was already partly stale — `CButton.spec.ts` mounted a dark Vuetify inline. The true gap was the **shared `mountAuthPage` helper** (+ admin equivalent) building Vuetify with no `theme` option.
   - **Narrowing:** closed for the two NEW theme-aware harnesses (1a's `mountThemed` + 1b's dashboard helper). **Deliberately NOT closed for `mountAuthPage`** — re-theming the established auth/creator/onboarding specs is out of scope and carries a real destabilization risk (D-c1-11). Those helpers intentionally remain stock-theme; the `color-system-parity` value-locks + `no-hard-coded-colors`/`no-inline-color-styles` guards + eyes-on sweep remain their mitigation.

---

## 1b — Dashboard core: shell + welcome bar + KPI strip + summary endpoint

**Backend — `GET /api/v1/agencies/{agency}/dashboard/summary`** (Agencies module, `DashboardSummaryController`):

- One payload (D-c1-6): `{ creators_in_roster, pending_creator_applications, active_campaigns, payments_due }`. Two real counts; two stable `null` placeholders.
- **`creators_in_roster`** = `roster` relations to this agency, `is_blacklisted = false`, creator not soft-deleted (`whereHas('creator')` applies Creator's SoftDeletes scope to the EXISTS subquery). Soft-delete exclusion pinned by `it('excludes soft-deleted creators from the roster count')` (break-revert: PMC #1).
- **`pending_creator_applications`** (the D-c1-7 denominator) = distinct creators with a relation to this agency (ANY relationship_status), `application_status = pending`, not soft-deleted (Creator's SoftDeletes global scope; pinned by `it('excludes soft-deleted creators from the pending count')`, break-revert PMC #1), `is_blacklisted = false`. Self-signup creators with no relation to this agency are correctly excluded.
- **Null-placeholder contract pinned (PMC #2):** `it('pins the four-key null-placeholder contract …')` asserts all four keys are PRESENT and `active_campaigns`/`payments_due` are explicitly `null` (via `array_key_exists`, which distinguishes present-null from absent) — so a future refactor that drops the keys instead of nulling them can't silently break the muted-`—` placeholder cards.
- **Authz/tenancy:** the `auth:web → tenancy.agency → tenancy` group enforces membership (non-member → 404 invisibility, matching `/members`). No MFA gate (matches `/`). Both counts filter `agency_id = {agency}` explicitly (belt-and-suspenders over the `BelongsToAgency` global scope).
- **Module ownership:** Agencies owns it even though `pending` reads `Creator.application_status` — the route group, `tenancy.agency`, and `AgencyCreatorRelation` all live here; a read-only cross-module join beats splitting the endpoint.

**Frontend — `apps/main/src/modules/dashboard/`:**

- **Route repointed in place** (refinement 1): `/` (`app.dashboard`) now lazy-imports `modules/dashboard/pages/DashboardPage.vue`. `DashboardPlaceholderPage.vue` deleted; the dead `app.dashboardPlaceholder` i18n string removed from all three locales; two comment-only references in `SignInPage.{vue,spec}` de-stale'd. The central-route-table oddity (app routes living in `modules/auth/routes.ts`) is logged as a one-line low-priority tech-debt note, not chased here — keeps `agency-routes-mfa-guard.spec.ts` green.
- **`DashboardPage.vue`** — single-column layout (D-c1-3): welcome bar → KPI strip → activity region. No FAB (D-c1-2). Component-local refs + the thin `dashboard.api.ts`, `onMounted` + `watch(currentAgencyId)` (A8 house pattern, mirrors `BrandListPage`); no data store.
- **`WelcomeBar.vue`** — name (from `useAuthStore`) + locale-aware date (`Intl.DateTimeFormat` keyed to the active i18n locale). Aurora 2px bottom-edge rule via `var(--brand-aurora-gradient)` (D-c1-9), added to `aurora-surfacing.spec.ts`'s asserted-surface list.
- **`KpiStrip.vue`** — four `CKpiCard`s in locked order (D-c1-4): Active campaigns [placeholder] → Creators in roster [real] → Pending applications [real] → Payments due [placeholder]. Labels localized here (the shared card is i18n-free).
- **`CKpiCard` (new `@catalyst/ui` component, D-c1-10)** — caption label + heading-2 value (via `--catalyst-typography-*`, no rem literals). `null` value → muted `—` placeholder (no "coming soon" copy); `loading` → skeleton. Proven on the 1a harness in **both** themes.
- **i18n:** new `dashboard.*` namespace (en/pt/it) merged into the i18n bundle + `MessageSchema`. UI-only — no backend error codes map under it, so (unlike auth/creator) no i18n-codes architecture test.

---

## 1c — Activity feed (spot-check anchors delineated per the Q2 condition)

**Backend — `GET /api/v1/agencies/{agency}/dashboard/activity`** (`DashboardActivityController` + `Support/DashboardActivityFeed`):

- Agency-stamped `audit_logs` rows whose action ∈ a curated `ACTION_ALLOWLIST`, newest-first (`created_at desc`, `id desc` tiebreak), capped at `FEED_LIMIT = 15`. **PMC #3 confirmed:** `audit_logs.id` is `bigIncrements('id')` — a monotonic auto-increment PK (the model's `ulid` is a _separate_ `char(26)` column, exposed as the row `id` in the response). So the `id desc` tiebreak is creation-order-correct for same-`created_at` rows; it is not a random ULID sort. (The table also carries a covering `(agency_id, created_at)` index, `idx_audit_agency_created`.)
- Each row exposes ONLY render-needed fields: `id`, `action`, `actor_label` (the actor's name, **eager-loaded** `with('actor:id,name')`), `created_at`, and a **per-action whitelisted** metadata subset.

**Frontend — `ActivityFeed.vue`** (self-contained widget; the page just drops it in): self-fetches via `dashboard.api.ts` + `useAgencyStore`; maps each `action` → a localized template (`dashboard.activity.actions.*`) with actor + whitelisted-metadata interpolation; handles empty (`CEmptyState`) / loading / error. **Never renders a raw action string** (unknown actions hit a generic fallback template).

### Curation rationale — signal over churn (refinement 3)

The allowlist's **exclusions are as deliberate as its inclusions** (pinned + documented in `DashboardActivityFeed`):

| Included (lifecycle)                        | Excluded (churn / noise) — and why                                                                                             |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| `creator.invited`                           | `agency_creator_relation.updated` — field churn (e.g. a rating tweak floods the feed)                                          |
| `bulk_invite.completed`                     | `agency_creator_relation.deleted` — removal, low signal for v1                                                                 |
| `agency_creator_relation.created`           | `brand.updated` — field churn                                                                                                  |
| `brand.created` / `.archived` / `.restored` | `bulk_invite.started` / `.failed` — progress / error-channel noise (the single `completed` carries the signal)                 |
| `invitation.created` / `.accepted`          | `invitation.expired_on_attempt` — edge event                                                                                   |
| `agency_settings.updated`                   | creator wizard `creator.wizard.*` — stamp `agency_id = null`, excluded by the stamping mechanism (deferred enrichment, D-c1-8) |

### 1c spot-check anchors (independently verifiable)

1. **Allowlist test break-revert** — `DashboardActivityAllowlistTest` pins the exact set + a churn-exclusion guard. Verified: adding `brand.updated` to `ACTION_ALLOWLIST` → 3 tests failed (pin + endpoint-exclusion + churn-guard) → reverted.
2. **Agency-stamped-only isolation** — `it('never returns agency_id-null rows or other agencies\' rows')`: a stamped row, an `agency_id = null` row (same allowlisted action), and another agency's row → only the stamped one returns. Break-revert: removing `->where('agency_id', …)` → that test failed → reverted.
3. **Metadata safety** — `bulk_invite.completed` row carrying `failures`/`secret` alongside the counts → response exposes only `invited`/`already_invited`/`failed`; `creator.invited` carrying `email` → empty metadata; a cross-row guard asserts no forbidden key reaches the response. Break-revert: `safeMetadata` returning the raw blob → 4 tests failed → reverted. (Whitelist, not blacklist-strip, per refinement 2.)
4. **No N+1** — `actor_label` resolves from an eager-loaded `with('actor:id,name')`, not a per-row lazy load; the feed query is a single `select` + one actor `select`.

(The feed did **not** balloon — it shares Pair B as planned.)

---

## Pre-merge corrections (PMC #1–#3, landed before commit)

Per the spot-check, two test-only assertions (landed in the **work** commit, not the docs follow-up) + one confirmation:

- **PMC #1 — soft-delete break-revert (both KPIs).** The `whereHas('creator')` (roster) + Creator SoftDeletes scope (pending) exclusions are now break-revert verified, and the covering tests cited by name (`it('excludes soft-deleted creators from the roster count')`, `it('excludes soft-deleted creators from the pending count')`). Break: dropped `whereHas('creator')` AND added `->withTrashed()` to the pending query → **both** soft-delete tests failed (red) → reverted → both green; controller restored byte-identically (new/untracked file — restoration confirmed by red→green, not a `git diff` against a committed baseline).
- **PMC #2 — null-placeholder contract assertion.** Added `it('pins the four-key null-placeholder contract …')` — all four keys present; `active_campaigns`/`payments_due` present-and-null via `array_key_exists` (distinguishes present-null from absent). Green.
- **PMC #3 — `audit_logs.id` column type.** Confirmed `bigIncrements('id')` (auto-increment PK); `id desc` tiebreak is creation-order-correct. Noted inline in the 1c backend bullet above. No code change.

---

## Honest deviations & notes

- **D-c1-7 / Q1 — `is_blacklisted` on BOTH KPIs (deviation from the kickoff's "no blacklist filter" prose).** Per the plan-approval, both counts apply `is_blacklisted = false` — **boolean only**, no `blacklist_scope`-aware logic. A blacklisted roster member should not inflate either KPI; the asymmetry of excluding it from only `pending` would be worse. Scope-aware counting is deferred to Sprint 7 and logged as tech-debt ("dashboard KPI counts exclude `is_blacklisted` via the boolean only; scope-aware counting deferred to Sprint 7").
- **Feed enrichment deferred (D-c1-8), logged as tech-debt.** v1 establishes subject-relevance via the _mechanism_ (agency-stamping + curated allowlist + metadata whitelist). Tenant-less creator events (`agency_id = null`) are excluded by construction; subject deep-links / name resolution / churn-control are deferred.
- **`@vitejs/plugin-vue` added beyond the named 1a deps** — required to compile SFCs under the new harness (design-tokens didn't need it). Pinned to `apps/main`'s version.
- **Central-route-table oddity logged, not chased** (refinement 1) — one-line low-priority tech-debt note; route repointed in place to keep the selective-gating MFA test green.
- **`apps/admin` re-run as a shared-package consumer** — adding `CKpiCard.vue` to `packages/ui/src` extends the byte-identical `typography-consumption` scan in admin; admin's 305 stay green.

---

## Verification results

| Gate                                            | Result                                                                                                                      |
| ----------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `@catalyst/ui` Vitest                           | **30 / 30** (3 files) — NEW harness (was a no-op); runs under the existing `./packages/*` frontend-job glob (no new CI job) |
| `apps/main` Vitest                              | **617 / 617** (67 files)                                                                                                    |
| `apps/admin` Vitest                             | **305 / 305** (32 files) — shared-package consumer, green                                                                   |
| `apps/api` Pest                                 | **901 / 901** (after PMC #2's added test; 2857→ +assertions)                                                                |
| `@catalyst/ui` typecheck                        | 0 errors (incl. `tests/`)                                                                                                   |
| `apps/main` typecheck                           | 0 errors                                                                                                                    |
| `apps/main` eslint                              | 0 errors (2 pre-existing `v-html` warnings, unrelated)                                                                      |
| `apps/api` PHPStan                              | **0 errors** (423 files) — fixed a `nullsafe.neverNull` on `created_at`                                                     |
| `apps/api` Pint                                 | clean (1 cosmetic auto-fix in a new test)                                                                                   |
| **Break-revert — summary blacklist**            | drop `is_blacklisted` on `pending` → "excludes blacklisted from pending" failed → reverted (`git diff` clean)               |
| **Break-revert — summary soft-delete (PMC #1)** | drop `whereHas('creator')` + add `->withTrashed()` → BOTH soft-delete tests failed → reverted → green                       |
| **Break-revert — feed allowlist**               | add `brand.updated` → 3 allowlist tests failed → reverted                                                                   |
| **Break-revert — feed stamping**                | drop `agency_id` filter → isolation test failed → reverted                                                                  |
| **Break-revert — feed metadata**                | `safeMetadata` returns raw blob → 4 metadata-safety tests failed → reverted                                                 |

---

## Files touched

**`packages/ui` (1a + 1b CKpiCard):**

- `package.json` — `test` → `vitest run`; harness devDeps.
- `vitest.config.ts`, `tests/setup.ts`, `tests/helpers/mountThemed.ts` — **new** harness.
- `tests/components/CButton.spec.ts`, `tests/components/CEmptyState.spec.ts` — **migrated** + dark/light assertions.
- `tests/components/CKpiCard.spec.ts` — **new**.
- `src/components/CKpiCard.vue` — **new**; `src/index.ts` — export; `src/components/CEmptyState.vue` — docblock de-stale'd; `tsconfig.json` — include `tests/`.

**`apps/api` (1b + 1c):**

- `app/Modules/Agencies/Http/Controllers/DashboardSummaryController.php` — **new** (1b).
- `app/Modules/Agencies/Http/Controllers/DashboardActivityController.php` — **new** (1c).
- `app/Modules/Agencies/Support/DashboardActivityFeed.php` — **new** (allowlist + metadata whitelist + curation rationale).
- `app/Modules/Agencies/Routes/api.php` — `dashboard/summary` + `dashboard/activity` routes.
- `tests/Feature/Modules/Agencies/DashboardSummaryTest.php`, `DashboardActivityTest.php`, `DashboardActivityAllowlistTest.php` — **new**.

**`apps/main` (1b + 1c):**

- `src/modules/dashboard/{api/dashboard.api.ts, pages/DashboardPage.vue, components/{WelcomeBar,KpiStrip,ActivityFeed}.vue}` — **new** module (+ specs for DashboardPage / WelcomeBar / ActivityFeed).
- `tests/unit/helpers/mountDashboardPage.ts` — **new** theme-aware helper.
- `src/modules/auth/routes.ts` — repoint `/`; `src/core/pages/DashboardPlaceholderPage.vue` — **deleted**.
- `src/core/i18n/index.ts` + `locales/{en,pt,it}/dashboard.json` — **new** namespace; `locales/{en,pt,it}/app.json` — dead `dashboardPlaceholder` removed.
- `tests/unit/architecture/aurora-surfacing.spec.ts` — WelcomeBar added; `src/modules/auth/pages/SignInPage.{vue,spec.ts}` — comment de-stale.

**Docs:**

- `tech-debt.md` — 2 harness entries flipped (1 closed, 1 closed-narrowed); 3 new entries (route-table low-pri; blacklist-boolean Sprint-7; feed-enrichment D-c1-8).
- `reviews/sprint-4-chunk-1-review.md` — this file.

---

## Proposed commit shape (for the merge step — not yet committed)

Per Q2 (approved): two pairs, each a work commit + plan-approved docs follow-up.

**Pair A — 1a infra:**

1. `test(ui): stand up theme-aware Vitest harness for @catalyst/ui + migrate shared specs (Sprint 4 Chunk 1 1a)` — `packages/ui/**` (config/setup/helper/specs/tsconfig/CEmptyState docblock) + the deleted `apps/main` co-located specs.
2. `docs(tech-debt): close both harness entries (narrowed for mountAuthPage) + log route-table note (1a)` — the two flipped entries + the route-table note.

**Pair B — 1b + 1c product** (grouped per Q2; the feed did not balloon):

3. `feat(dashboard): real agency workspace home — summary endpoint + welcome bar + KPI strip + activity feed (Sprint 4 Chunk 1 1b+1c)` — `apps/api/**` (both controllers + feed support + routes + Pest) + `apps/main/src/modules/dashboard/**` + route repoint / placeholder deletion / i18n + CKpiCard + the FE specs/helper + aurora-surfacing.
4. `docs(tech-debt,reviews): log blacklist-boolean + feed-enrichment deferrals + chunk-1 review (1b+1c)` — the two new tech-debt entries + this review.

---

_Provenance: drafted by Cursor (Sprint 4 Chunk 1 build pass, 2026-05-31); spot-check passed; PMC #1 (soft-delete break-revert) + PMC #2 (null-placeholder contract assertion) landed in the work commit, PMC #3 (id auto-increment) confirmed. **Closed** per `PROJECT-WORKFLOW.md` §3._
