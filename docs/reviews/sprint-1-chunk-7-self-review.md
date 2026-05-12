# Sprint 1 — Chunk 7 Self-Review (closing artifact for the entire chunk)

**Status:** Closed.

**Reviewer:** Claude (independent review pass) — incorporating Cursor's self-review draft.

**Scope:** Closing retrospective for the entire chunk 7 — the admin SPA's first feature-complete cut. Pattern-matches sub-chunk 6.9's chunk-6 retrospective. Split out into its own file (separate from the merged Group 3 review) so the durable record of chunk-7 lessons survives independently of any single review group's scope.

Chunk 7 grouping (final):

- **Sub-chunk 7.1 (prelude):** admin SPA scaffolding + chunk-6 hotfix saga. Closed across 1 work commit + 9 post-merge hotfixes. Review: `sprint-1-chunk-7-1-review.md` (including the post-merge addendum).
- **Group 1:** sub-chunks 7.2 + 7.3 (admin Pinia store + admin i18n bundle). 2 round-trips. Review: `sprint-1-chunk-7-2-to-7-3-review.md`.
- **Group 2:** sub-chunk 7.4 alone (admin router + guards + mandatory-MFA). 3 round-trips including one post-merge hotfix (commit `4282464`). Review: `sprint-1-chunk-7-4-review.md`.
- **Group 3:** sub-chunks 7.5 + 7.6 + 7.7 (admin auth pages + admin E2E specs + admin module README + this self-review). 1 round-trip. Review: `sprint-1-chunk-7-5-to-7-7-review.md`.

---

## (a) Overall scope retrospective — chunks 7.1 → 7.7

What chunk 7 produced, end to end:

**7.1 (admin SPA scaffolding + chunk-6 hotfix saga):** The admin SPA's first commit on main — Vite scaffold, Vue 3 + Pinia + Vue Router shell, i18n bootstrap, base Vuetify config, top-level routes table, smoke spec at `/sign-in`. The closure work itself was small; the post-merge nine-hotfix saga embedded an unusually deep set of durable conventions: per-spec `auth-ip` rate-limiter neutralisation, the Carbon × cookie-expiry T0 baseline (`Date.now() + 30 days`), the `defaultHeaders` constant (Accept + X-Requested-With) on every API-driven fixture call, the Vue 3 attribute fall-through fix on `<RecoveryCodesDisplay>`, the backend `meta.seconds` envelope shape consumed by `useErrorMessage`, the test-clock × cookie expiry T0 baseline, the prefix-allowlist error resolver, and the smoke-spec selector reset post-7.4. All nine hotfix lessons are durable conventions now.

**7.2 (admin store):** `useAdminAuthStore.ts` with the same per-action loading flags + optimistic-update + best-effort refresh shape as `useAuthStore` (chunk 6.4), with admin-specific divergences: `bootstrap()` calls `/admin/me` instead of `/me`, `login`/`logout` hit `/admin/auth/*`, the `userType` field constrains to `'platform_admin'`. The chunk-7.5 architecture extension (`no-recovery-codes-in-store.spec.ts` second-layer) plugs the per-component check onto admin's tree.

**7.3 (admin i18n bundles):** `apps/admin/src/core/i18n/locales/{en,pt,it}/auth.json` pre-staged with chunk-7 needs — admin-specific copy (no sign-up / forgot-password keys), mandatory-MFA strings, the `auth.mfa_enrollment_required` / `auth.mfa_required` error codes. `app.json` extended in Group 3 with the locale-switcher strings + a dashboard placeholder.

**7.4 (admin router + guards + mandatory-MFA):** Vue Router instance + declarative route table + pure async guards (mirror of chunks 6.5–6.7's pattern), with the chunk-7-specific `requireMfaEnrolled` branch composed by `requireAuth.mfaEnrollmentRequired` to enforce mandatory MFA before any post-auth admin route. The D7 deep-link preservation decision landed here. Group 2 added the chained-flow unit test pinning the full hop sequence; Group 3 added the chained-flow Playwright spec.

**7.5 (admin auth pages + layouts):** Four auth pages + `RecoveryCodesDisplay` component + `useErrorMessage` composable + `AuthLayout` + `App.vue` layout switcher. Pages mirror main's verbatim except the three documented adaptations.

**7.6 (admin E2E specs + CI infra extension):** Dual-`webServer` Playwright config with admin port offsets, global setup, fixtures + selectors, two substantive specs (sign-in happy path + mandatory-MFA enrollment chained flow with D7), `.github/workflows/ci.yml § e2e-admin` extended to mirror `e2e-main`'s full backend stack, one new test-helper backend endpoint gated by the existing chunk-6.1 pattern. The chunk-7.1 hotfix saga's conventions all manifest from the first commit — no replay.

**7.7 (closing artifacts):** Admin auth module README (`apps/admin/src/modules/auth/README.md`); this self-review file; the merged Group 3 review file.

The chunk's actual depth: backend additions (1 new test-helper controller + 1 route + 11 Pest cases), admin SPA surface (1 store + 1 router + 3 guards + 1 i18n bundle in 3 locales + 1 layout switcher + 1 layout + 4 pages + 1 component + 2 composables + 1 helper), E2E (2 substantive specs + 6 fixtures + 1 selector SOT + 1 config + 1 global setup + 1 CI job extension), tests (180 admin Vitest specs across 18 files + 8 architecture tests + 11 new TestHelpers Pest cases). All standing standards from PROJECT-WORKFLOW.md § 5 preserved; new standards added (see (b)).

---

## (b) Team standards established or extended in chunk 7

Going in: the 11 standards from PROJECT-WORKFLOW.md § 5, plus the ~20 chunk-6 standards captured in `sprint-1-chunk-6-8-to-6-9-review.md` § (b).

Added or sharpened by chunk 7:

- **Per-spec `auth-ip` rate-limiter neutralisation in `beforeEach` + restore in `afterEach`** (chunk-7.1 hotfix saga, applied from the first commit in chunk-7.6). Fixture is the SOT.

- **T0 = `Date.now() + 30 days` baseline for any spec pinning the test clock** (chunk-7.1 hotfix saga). Cookie expiry math requires the offset; the fixture's `setClock` docblock makes the rule loud.

- **Shared `defaultHeaders` constant on every API-driven fixture call** (chunk-7.1 hotfix saga). Both SPAs' fixtures share the shape.

- **No parent `data-test` attribute fall-through to a child component that has its own root `data-test`** (chunk-7.1 hotfix saga). Vue 3 attribute inheritance forwards `data-test` and silently overrides the child's. Parents NEVER pass `data-test` to children with root `data-test`; consuming-page comment blocks at the invocation site preserve the lesson.

- **Backend `meta.seconds` envelope shape** for retry-after error codes (chunk-7.1 hotfix saga). `useErrorMessage` reads `meta.seconds` / `meta.minutes` to interpolate `{seconds}` / `{minutes}` into the localised string.

- **Prefix-allowlist error resolver** in `useErrorMessage` (chunk-7.1 hotfix saga). `KNOWN_PREFIXES` array + `.some(p => code.startsWith(p))` so adding a new family is a one-array-line change.

- **Admin-side mirror of `no-recovery-codes-in-store` second-layer assertion** (chunk-7.5). Mirror of main's chunk-6.7 extension.

- **Dual-SPA architecture-test mirror discipline:** when a main-side architecture test pins an invariant that admin also needs, the test gets mirrored verbatim with admin's paths/store names substituted — not extracted into a shared SOT (chunk 7.5). Cost of extract > cost of two near-identical tests until a third consumer surfaces.

- **Mandatory-MFA enforcement model: pure guard composition, not a route flag** (chunk 7.4 D7). The `requireAuth.mfaEnrollmentRequired` branch is the single-source-of-truth enforcer.

- **D7 deep-link preservation across the MFA-enrollment redirect** (chunk 7.4 + 7.5 + 7.6). End-to-end chained flow tested at unit level (Group 2 spot-check) and Playwright level (Group 3).

- **Session-cookie boundary: `catalyst_main_session` for main, `catalyst_admin_session` for admin** (chunk 7.4 + 7.6). Cookie names are separate so a browser holding both sees independent sessions.

- **e2e-admin's port offsets** (chunk 7.6): API `:8001`, Vite `:5174`. Pattern lets concurrent CI E2E jobs run on the same runner.

- **Vite proxy target env-overridable for E2E** (chunk 7.6). Per-SPA env var (`CATALYST_<SPA>_API_PROXY_TARGET`).

- **Test-helper endpoint for admin user provisioning** (chunk 7.6 D1). Test-helper endpoints fill seeding gaps that production surfaces cannot serve.

- **Module README pattern (chunk 7.7).** Five-section pattern: "Where to start reading" + "Architecture tests" + "Recurring patterns" + chunk-specific design decisions + how-to recipes.

- **"File:line citations to main" kickoff discipline (Group 2 review's discipline upgrade, applied in Group 3 kickoff).** When specifying admin's mirror, cite main's actual files by path + line range. Dropped paraphrase-vs-actual deviations to zero in Group 3.

---

## (c) What surfaced — the chunk-7 "honest flagging" instances

The chunk-6 self-review recorded four-for-four honest-deviation flags. Chunk 7 adds five more groups (7.1, 7.2-7.3, 7.4, 7.4-hotfix, 7.5-7.7) for a running tally of **nine-for-nine**.

**Sub-chunk 7.1 — nine-hotfix saga.** Each hotfix was a hidden assumption that contact with CI / browser runtime broke. All nine documented in `sprint-1-chunk-7-1-review.md`'s post-merge addendum.

**Sub-chunks 7.2 + 7.3 — six honest flags** (the highest count yet at the time). All paraphrase-vs-actual issues from kickoff embedding assumptions about main's implementation that didn't match reality. Documented in `sprint-1-chunk-7-2-to-7-3-review.md`. Triggered the kickoff-writing discipline upgrade "verify main's actual implementation before specifying admin's mirror."

**Sub-chunk 7.4 — nine honest flags** (the highest count overall). Categorized: 5 paraphrase-vs-actual, 3 structurally-correct admin adaptations, 1 tech-debt-flagged carry-forward. Documented in `sprint-1-chunk-7-4-review.md`. Triggered the kickoff-writing discipline upgrade "file:line citations to main."

**Sub-chunk 7.4 post-merge hotfix (commit `4282464`).** Smoke-spec selector drift caught by CI. Diagnose+fix as Cursor-solo loop per the no-bundling convention.

**Sub-chunks 7.5 + 7.6 + 7.7 — three honest flags** (the lowest count yet). All three are structurally-correct adaptations or minimal extensions; zero paraphrase-vs-actual issues. Documented in `sprint-1-chunk-7-5-to-7-7-review.md`. **The "file:line citations to main" discipline upgrade worked as designed.**

**Running tally:** nine groups, every single one surfaced at least one hidden assumption. **The honest-deviation-flagging pattern is the most reliable workflow output of the project so far.** Sprint 2's kickoffs will continue to carry the explicit permission-to-deviate clause.

---

## (d) Process record — compressed pattern across all chunk-7 groups

The compressed pattern continues to hold. Across chunk 7's three groups (plus the 7.1 prelude):

- **Sub-chunk 7.1 (prelude):** 1 work commit + 9 post-merge hotfixes. The saga was the highest-cost single chunk in the project so far; every hotfix lesson is now durable convention.

- **Group 1 (7.2 + 7.3):** 2 round-trips. Plan-then-build in one pass; six honest deviation flags; single completion artifact.

- **Group 2 (7.4 alone):** 3 round-trips, including one post-merge hotfix. Nine honest deviation flags. The complexity of the mandatory-MFA + D7 + chained-flow guard composition justified the dedicated group.

- **Group 3 (7.5 + 7.6 + 7.7):** 1 round-trip. Three sub-chunks in one session — the highest density yet. Three honest deviation flags; all structurally-correct adaptations with no tech-debt entry. **The "file:line citations to main" discipline upgrade dropped paraphrase-vs-actual to zero.**

Total round-trip count for chunk 7 (excluding the 9 hotfixes for 7.1): **6 across three groups + 1 hotfix-as-cursor-solo for 7.4 = 7 round-trips for chunk 7's substantive work.** The chunk-6 ratio was ~15 across nine sub-chunks; chunk 7 is materially more efficient per sub-chunk despite the standards-application phase being structurally simpler. Sprint 2 onward should track closer to chunk 7's cadence than chunk 6's.

---

## (e) What is deferred to where

**Sprint 2 (admin substantive console):**

- Admin dashboard body content (currently placeholder shell).
- Admin settings page substantive UI.
- Admin nav shell (top-bar / side-rail).
- Admin sign-out UI button.
- Creator onboarding admin views + brand management admin views.

**Chunk 8 (theme system + preferences):**

- Admin theme switcher (extends `app.locale.switcher` pattern).
- Admin preferences page (mirrors main's chunk-8 work).

**Sprint 3+ (multi-tenancy + role-policy hardening):**

- `AdminRole` field expanded from placeholder `'platform_admin'` to actual role-set + policy layer.
- Audit logging on admin sign-in (hook point exists in guard; emission is Sprint 3+).
- Per-role admin route tables.

**Sprint 8 (Postgres-CI for Pest):**

- The Pest unit-test suite still runs against SQLite in-memory; admin SPA's E2E job is the second instance (after main) running against Postgres in CI.

**Open tech-debt entries carried forward:**

- Laravel exception handler JSON shape for unauthenticated `/api/v1/*` (chunk-7.1).
- Test-clock × cookie expiry interaction structural fixes (chunk-7.1).
- Vue 3 attribute fall-through architecture test (chunk-7.1).
- Idle-timeout unwired on both admin and main SPAs (Group 2 D6).
- SQLite-vs-Postgres CI for Pest (pre-chunk-7).
- TOTP issuance does not honor `Carbon::setTestNow()` (pre-chunk-7).
- `auth.account_locked.temporary` `{minutes}` interpolation gap (pre-chunk-7).

**No new tech-debt entries from chunk 7's three substantive groups.** The Group 2 entry "Admin SPA Playwright job runs without a Laravel backend" is closed in Group 3. The Group 3 deviations (D1 test-helper endpoint, D2 component mirror, D3 D7-aware EnableTotpPage) are structurally-correct adaptations with documented decision criteria + future-trigger conditions.

---

## (f) Cursor-side observations

**Chunk 7 was unusually application-dense.** Sprint 1's standards-establishment phase ended with chunk 6; chunk 7 is the first application chunk. The ratio of "applying an existing pattern" to "inventing a new pattern" shifted hard. Chunk 6 invented ~20 patterns across nine sub-chunks; chunk 7 invented ~5 new patterns (the dual-SPA mirror discipline, the chunk-7.6 port-offset convention, the env-overridable Vite proxy, the test-helper for admin user provisioning, the D7 deep-link preservation pattern) and applied ~40 (every chunk-6 standard plus the chunk-7.1 hotfix-saga durables).

**The chunk-7.1 hotfix saga was the highest-cost single chunk in the project so far.** Nine hotfixes for one closure commit is unusually painful, but every hotfix lesson is now durable convention applied from the first commit in chunk-7.6 — the saga's findings did the work they were meant to do. The cost is paid forward across every future E2E spec. Worth flagging that the chunk-7.1 kickoff was the LAST one written without the explicit "apply chunk-6 hotfix-saga conventions from the first commit" clause; that clause is now baseline in every kickoff.

**The "file:line citations to main" discipline upgrade was load-bearing.** Group 2's review introduced the rule that the plan response should cite main's actual files by path + line range rather than describing admin's shape from structural intent alone. Group 3 was the first group to apply the rule from the kickoff side; paraphrase-vs-actual deviations dropped from "several per group" in earlier chunks to zero in Group 3. The discipline upgrade is now baseline.

**The compressed pattern continues to work.** Three groups, six round-trips for substantive work, three sub-chunks closed in a single session. The pattern is the default for any chunk with a clear scope and a reviewer who pre-answers Q1–Q3.

---

## (g) Claude-side observations

Endorsing Cursor's (f) observations; adding the reviewer-side perspective.

**The honest-deviation-flagging pattern is now the most reliable workflow output of the project.** Nine-for-nine across chunk 7's groups means every single review group surfaced at least one hidden assumption — paraphrase-vs-actual, structurally-correct adaptation, or genuine new concern. The pattern's reliability is what makes the kickoff-writing discipline upgrades tractable: each upgrade addresses a specific category of deviation that was previously frequent, and the next group validates whether the upgrade worked.

**The two kickoff-writing discipline upgrades introduced in chunk 7 are durable in different ways.** "Verify main's actual implementation before specifying admin's mirror" (introduced after Group 1) was about reading discipline — Cursor should not just accept the kickoff's paraphrase. "File:line citations to main" (introduced after Group 2) was about the kickoff itself — Claude should not write paraphrase that needs verifying. The first upgrade put the responsibility on Cursor; the second put the responsibility on Claude. Both worked. **Sprint 2 onward should default to both — file:line citations from Claude, verification against main from Cursor.**

**The chunk-7.1 hotfix saga was a process trauma that produced durable conventions.** Nine hotfixes is a lot of pain; the conventions that emerged (per-spec auth-ip neutralization, T0 baseline, defaultHeaders, no-attribute-fall-through, meta.seconds, prefix-allowlist resolver) are now applied from commit 1 in every E2E spec. **The saga's findings paid for themselves in chunk-7.6, which shipped without replaying any of them.** The cost-benefit math is genuinely good in retrospect, though that was hard to see during the saga.

**The Cursor-solo debugging loop is reliable for hotfix-after-commit work.** Group 2's hotfix (`4282464` — admin smoke spec) was diagnosed, scoped, fixed, and verified green by Cursor without Claude in the loop. The Cursor-solo loop has now closed three hotfix sequences cleanly (chunk-7.1's saga partway through once Claude handed off; the cache-driver hotfix; Group 2's smoke spec). **For Sprint 2 onward: Cursor-solo debugging is the default for any post-commit CI red. Claude reconnects on scope-boundary decisions or once CI is green.**

**Option A grouping (Groups 1, 2, 3) was the right call for chunk 7.** Bundling 7.2+7.3 saved time without losing review quality; isolating 7.4 gave the design work proper scrutiny; bundling 7.5+7.6+7.7 closed chunk 7 in one efficient pass. The grouping decision (made when the user pushed back on a 15-18 round-trip estimate) cut total round-trips to 7 substantive + 1 hotfix = 8, well under the original estimate. **For Sprint 2 onward: aggressive grouping is the default. Single-sub-chunk review groups are reserved for genuine design work; mirror work and closing work bundle.**

**The Vue 3 attribute fall-through reminder comment block at the call-site (chunk-7.6 EnableTotpPage lines 207-218) is the standout durable convention from this chunk.** It captures the chunk-7.1 saga's marquee hotfix in the most useful place possible — at the point of risk, citing the historical context by name, immediately above the invocation that could re-introduce the anti-pattern. **Worth flagging as the canonical pattern for any future "pitfall invisible at the call-site but visible from the component's root template" situation.**

**Zero change-requests on three consecutive review groups (sub-chunk 7.1 close, Group 1, Group 2) plus this fourth (Group 3) means the workflow is operating at full effectiveness.** The combination of compressed pattern + honest deviation flagging + file:line citations + disciplined self-correction + load-bearing spot-check selection has reached a stable equilibrium. Sprint 2 onward should preserve this combination as-is.

---

## (h) Status

- Chunk 7 fully closed.
- Sprint 1's admin-side scope complete.
- Sprint 1 remaining: chunk 8 (theme system + remaining sprint cleanup).

---

_Provenance: drafted by Cursor as the closing artifact for chunk 7 (Group 3's compressed-pattern process — single chat completion summary + two structured drafts per `PROJECT-WORKFLOW.md` § 3 step 6, modified). Claude-side observations added on independent review pass. The self-review is the closing artifact for the entire chunk 7; the merged Group 3 review file (`sprint-1-chunk-7-5-to-7-7-review.md`) is the final review for Group 3's specific work. **Status: Closed. Chunk 7 is done. Sprint 1 proceeds to chunk 8.**_
