# Sprint 1 — Chunk 7 Group 2 Review (sub-chunk 7.4: admin SPA router + guards + mandatory-MFA enforcement flow)

**Status:** Closed. No change-requests; the work is mergeable as-is.

**Reviewer:** Claude (independent review) — incorporating Cursor's self-review draft.

**Reviewed against:** `PROJECT-WORKFLOW.md` § 5 (all standing team standards) + § 7 (spot-checks-before-greenlighting) + § 10 (session boundaries), `02-CONVENTIONS.md` § 1 + § 3 + § 4, `04-API-DESIGN.md` § 4 + § 7 + § 8 (error envelope shapes), `05-SECURITY-COMPLIANCE.md` § 6 (admin-specific mandatory 2FA + idle-timeout policy), `07-TESTING.md` § 4 + § 4.4, `20-PHASE-1-SPEC.md` § 5 + § 7, `security/tenancy.md`, `feature-flags.md`, `tech-debt.md` (one new entry added), all four chunk-6 review files (particularly `sprint-1-chunk-6-5-to-6-7-review.md` for the main SPA router + guards pattern that 7.4 mirrors), `sprint-1-chunk-7-1-review.md` (including post-merge addendum), `sprint-1-chunk-7-2-to-7-3-review.md` (Group 1's admin store + i18n that 7.4 consumes), and `reviews/sprint-1-chunk-6-plan-approved.md`.

This is the second review group of chunk 7. Group 2 builds the admin SPA's routing + guard + mandatory-MFA enforcement infrastructure — the genuine new design work in chunk 7. After 7.4 lands, the admin SPA has:

- A working Vue Router instance integrated with the Pinia store (Group 1), i18n (Group 1), and the api-client (chunks 6.2–6.4).
- Guards enforcing authentication, mandatory MFA enrollment, deep-linking with intended-destination preservation, 401-interceptor-driven redirects.
- The infrastructure ready for sub-chunk 7.5 (Group 3) to drop real auth pages into the existing route slots.

Sub-chunks 7.5 (admin auth pages + layouts), 7.6 (admin E2E specs), and 7.7 (admin module README + chunk-7 self-review) are Group 3's work.

---

## Scope

Cursor's draft enumerates the full scope in detail. The merged review preserves Cursor's draft as the durable record by reference rather than restating. Brief orientation:

- **Layer 1 (router + routes + guards):** `apps/admin/src/core/router/index.ts` (Vue Router v4 instance + `runGuards` dispatcher + `createRouter` factory) mirroring main's `apps/main/src/core/router/index.ts` with `useAdminAuthStore` substituted; `apps/admin/src/core/router/guards.ts` (`requireAuth`, `requireGuest`, `requireMfaEnrolled` + symbolic guards registry) mirroring main's pattern with the D7 intended-destination-preservation adaptation; `apps/admin/src/modules/auth/routes.ts` with seven admin routes (`/sign-in`, `/auth/2fa/{enable,verify,disable}`, `/`, `/settings`, `/error/auth-bootstrap`); `PlaceholderPage.vue` temporary shell so the router renders before sub-chunk 7.5 swaps in real pages; minimal `App.vue` rewrite to `<v-app><v-main><router-view/></v-main></v-app>` shell (layout switcher deferred to 7.5).

- **Layer 2 (401 interceptor wiring):** `apps/admin/src/core/api/index.ts` extended with `SESSION_EXPIRED_QUERY_REASON`, narrowed `UNAUTHORIZED_EXEMPT_PATHS` (admin-variant surface only — `/admin/me`, `/admin/auth/login`), `shouldHandleUnauthorized` decision function, `createUnauthorizedPolicy` factory, and the production `productionPolicy` with dynamic-import lazy loaders for store + router.

- **Layer 3 (unit tests):** 19 cases on the 401 policy, 15 cases on guards, 12 cases on the router dispatcher + `createRouter` integration (including the chained-flow test added during Claude's spot-check pass), 2 cases on the minimal App shell. 101 admin tests total; 100% coverage on `core/router/**`, `core/api/**`, `modules/auth/**`.

- **Layer 4 (architecture test):** `apps/admin/tests/unit/architecture/no-direct-router-imports.spec.ts` — path-(b) mirror of main's chunk-6.5 test pinning that stores never import the router.

- **Layer 5 (coverage gate):** `vitest.config.ts` extended to include `src/core/router/**` and `src/core/api/**`; added `routes.ts` to exclusion+guard pattern.

---

## Acceptance criteria — all met

(All Group 2 acceptance criteria from the kickoff — router exists and integrates correctly, guards enforce all specified concerns, 100% Vitest coverage on `apps/admin/src/core/router/**`, all existing tests remain green, lint/typecheck clean across all three apps + api package, one new tech-debt entry added for the idle-timeout carry-forward, no new Playwright work — all ✅. Reproduced verbatim in Cursor's draft; merged review preserves the same line-by-line verdicts. Verification gates summarized in §"Verification results" below.)

---

## Plan corrections / honest deviation flagging — nine items

**Eighth instance** in chunk 6 + 7.1 + 7.2-7.3 + 7.4 of Cursor flagging where the kickoff carried hidden assumptions that didn't hold. Precedents from prior groups; this round produced the highest count yet (9), distributed across three categories that are worth recording explicitly:

- **Paraphrase-vs-actual (5):** the kickoff embedded paraphrased descriptions of main's implementation that didn't match the actual code (D1: route table location, D2: bootstrapStatus enum values, D3: route name prefix, D4: recovery-codes router-level enforcement, D9: layout switcher in App.vue).
- **Structurally-correct admin adaptation (3):** admin diverges from main where the security model requires (D5: admin `/settings` stricter MFA, D7: admin preserves intended destination across MFA redirect, D8: admin's narrowed `UNAUTHORIZED_EXEMPT_PATHS`).
- **Tech-debt-flagged carry-forward (1):** admin matches main in NOT wiring an existing concern, both deferred to a future security sprint (D6: idle-timeout).

The new kickoff-writing discipline introduced in Group 1's merged review ("verify main's actual implementation before specifying admin's mirror") **worked as intended**. All 5 paraphrase-vs-actual deviations were caught at build time rather than ship time. **For Group 3's kickoff, the next discipline upgrade is "specify admin's mirror by referencing main's actual files inline with file:line citations"** — this should drop the paraphrase-vs-actual category to zero.

### Paraphrase-vs-actual deviations (D1, D2, D3, D4, D9)

**D1 — Route table location.** Kickoff said `core/router/routes.ts`; actual landed at `modules/auth/routes.ts` because main's main SPA puts auth routes inside `modules/auth/routes.ts` (auth is a feature module, not a core concern). Admin mirrors verbatim. Routes-not-in-auth-module would be a future structural change for both SPAs.

**D2 — `bootstrapStatus` enum values.** Kickoff said `'idle' | 'pending' | 'authenticated' | 'anonymous' | 'mfa-required' | 'error'`; actual is `'idle' | 'loading' | 'ready' | 'error'` (with the result type encoded in `user` presence + `mfaEnrollmentRequired` field separately). Group 1's store mirrored main's actual enum, not the kickoff's paraphrase; 7.4's guards consume main's actual enum.

**D3 — Route names without `admin.` prefix.** Kickoff implied admin routes might have a namespace prefix; main uses bare names (`auth.sign-in`, `auth.2fa.enable`, `app.dashboard`, `app.settings`) and admin mirrors verbatim. Cross-SPA route-name consistency is the same load-bearing concern as cross-SPA action-name consistency (Group 1's D3).

**D4 — No `/mandatory-recovery-codes` router-level enforcement.** Kickoff anticipated this might be a router-level concern; main handles recovery-codes display at the page level (`EnableTotpPage.vue` shows codes inline after confirm; user dismisses to proceed). Admin mirrors verbatim — no router-level mandatory-recovery-codes route. **Structurally-correct shape:** the codes are transient component-local state (per chunk-6.7 invariant verified across multiple groups); router-level enforcement would push them through navigation state, which would either persist them or require complex out-of-band passing. Page-level is cleaner.

**D9 — Minimal App.vue shell, layout switcher deferred.** Kickoff implied App.vue gets a layout switcher in 7.4; main's App.vue uses a layout switcher tied to route meta but the layout components themselves are in `modules/auth/layouts/`. Admin's 7.4 lands the minimal `<v-app><v-main><router-view/></v-main></v-app>` shell now; layout components + switcher land in 7.5 alongside the real auth pages. **Phase-deferred mirror of main** — not a divergence, just a sequencing choice within chunk 7's sub-chunks.

### Structurally-correct admin adaptations (D5, D7, D8)

**D5 — `app.settings` requires MFA enrollment on admin (stricter than main).** Admin route table mounts `requireMfaEnrolled` on `/settings`; main does NOT. Justification: admin settings include high-trust operations (e.g., changing admin user roles, viewing audit logs); access without 2FA enrolled is unacceptable per `05-SECURITY-COMPLIANCE.md` § 6.3. Admin being stricter than main on the admin-only surface is the correct security posture.

**D7 — `requireAuth` preserves intended destination across MFA-enrollment redirect (admin), main does NOT.** Admin's `requireAuth.mfaEnrollmentRequired` branch passes `query.redirect=<original-path>` to `/auth/2fa/enable`; main's same branch does not. Justification: admin users hitting a deep link to (e.g.) `/admin/users/123` while not yet enrolled in 2FA should land back on that specific user after enrolling, not at the dashboard. The UX benefit of preservation outweighs the marginal "MFA enrollment is a security-sensitive flow that should land at a known good destination" concern for the admin surface specifically. **End-to-end test covers this chain explicitly** (see spot-check 1 below for the chained-flow test added during the spot-check pass).

**D8 — `UNAUTHORIZED_EXEMPT_PATHS` narrowed to admin-variant surface only.** Main's `UNAUTHORIZED_EXEMPT_PATHS` includes both main and admin paths; admin's narrowed copy includes only `/admin/me` and `/admin/auth/login`. This avoids admin's 401-interceptor triggering on main-variant 401s (which would never reach admin in practice, but the narrower exemption list is cleaner and more truthful about admin's actual concerns).

### Tech-debt-flagged carry-forward (D6)

**D6 — Idle-timeout unwired on both admin AND main.** Main has `useIdleTimeout.ts` composable but never invokes it from `App.vue`. Admin's `App.vue` likewise doesn't invoke it. `05-SECURITY-COMPLIANCE.md` § 6.3 specifies stricter admin idle-timeout (30 min vs main's looser policy). **New tech-debt entry added:** "Idle-timeout enforcement is unwired on both SPAs despite the composable existing." Trigger: a future security sprint OR a CI test that asserts idle behavior. Resolution: wire `useIdleTimeout` from each SPA's `App.vue` with the configured timeout values; ensure admin's stricter policy is enforced.

### Process record on these nine deviations

The "verify main's actual implementation" discipline is the meta-pattern that produced these. **Five of the nine deviations would have shipped wrong if Cursor had implemented the kickoff verbatim** — three of those (D2 `bootstrapStatus`, D3 route names, D4 router-level recovery codes) are particularly important because the kickoff's wrong shape would have created cross-SPA inconsistency that would compound across subsequent sub-chunks.

**For Group 3's kickoff:** the next discipline upgrade is "specify admin's mirror by referencing main's actual files inline with file:line citations." Expected effect: paraphrase-vs-actual deviation count drops to ~zero; structurally-correct adaptations and tech-debt carry-forwards remain at their natural rate (~2-3 per group based on Group 2's evidence).

---

## Standout design choices (unprompted)

Cursor's draft enumerates several design choices. Three deserve highlighting as broadly applicable patterns:

- **Symbolic guards registry pattern.** Guards are exported by symbolic name (e.g., `'requireAuth'`, `'requireMfaEnrolled'`) and route meta references them by symbol; `runGuards` dispatcher resolves symbols to actual guard functions. This decouples route definitions from guard implementations and makes the guard chain inspectable at runtime. Mirrors main's exact pattern; worth recording as the canonical shape for any future SPA's router.

- **`runGuards` dispatcher as a separate concern from `createRouter`.** The router instance creation is a factory; the guard dispatcher is a separate function consumed by the factory. This makes both testable in isolation — `runGuards` can be unit-tested without instantiating Vue Router; `createRouter` can be integration-tested via `createMemoryHistory()`. Three-level coverage pattern (unit guard tests + dispatcher tests + `createRouter` integration tests) emerges naturally from this decomposition.

- **`createUnauthorizedPolicy` factory accepting injected dependencies.** Production policy injects store + router as lazy dynamic imports; test policies inject mocks. The factory shape means the 401 interceptor's logic is unit-testable without spinning up a Pinia + Vue Router stack — the test policy just records calls. Mirrors main's pattern; canonical for any cross-cutting interceptor that needs production wiring AND unit testability.

---

## Decisions documented for future chunks

- **Route tables live in feature modules (`modules/<feature>/routes.ts`), not in `core/router/`.** Established across chunks 6.5–6.7 (main) and Group 2 (admin). `core/router/` holds the router instance, the guards, and the dispatcher; route tables for each feature live with that feature.

- **Route names use bare prefixes per concern (`auth.*`, `app.*`), not SPA-prefixed (`admin.auth.*`, `main.auth.*`).** Cross-SPA route-name consistency reduces context-switching for future Cursor sessions touching both SPAs.

- **Defence-in-depth guards (like `requireMfaEnrolled`) intentionally do NOT preserve intended destination.** The primary enforcer (`requireAuth.mfaEnrollmentRequired` branch) handles the happy path with intended destination preservation; the defence-in-depth guard handles the edge state where data is inconsistent and the safe behavior is "force enrollment, land on dashboard" without trying to preserve a path that may not be valid.

- **`UNAUTHORIZED_EXEMPT_PATHS` is per-SPA, not shared.** Each SPA's 401 interceptor exempts only its own variant's paths. This avoids surprising behavior where main-variant 401s leak into admin's interceptor decisions.

- **Layout components live alongside the auth feature, not in `core/`.** Established for main and now confirmed for admin (D9 deferral to 7.5). Layouts that wrap auth-specific pages are part of the auth feature, not core infrastructure.

- **Architecture tests covering "no store-level navigation" use path-(b) mirroring (per-SPA test file) when each SPA's stores live in independent module trees.** Established by Group 1's recovery-codes architecture test; reinforced by Group 2's `no-direct-router-imports.spec.ts` mirror. Path-(a) extension would only work if there's a shared `packages/` location for stores; with per-SPA stores, per-SPA tests are cleaner.

---

## Tech-debt items

**One new entry added (D6):**

- **"Idle-timeout enforcement is unwired on both admin and main SPAs despite the `useIdleTimeout` composable existing."** `apps/main/src/core/auth/useIdleTimeout.ts` exists but is not invoked from main's `App.vue`; admin's `App.vue` likewise doesn't invoke it. `05-SECURITY-COMPLIANCE.md` § 6.3 specifies admin idle-timeout at 30 min (stricter than main's policy). Risk: an admin user leaves their session open indefinitely without re-authenticating; an attacker with physical access to the unlocked machine gains admin-level access without re-prompting. Mitigation today: session cookie has a 2-hour absolute lifetime; user must re-authenticate after that regardless. Trigger: a future security sprint OR a CI test asserting idle behavior. Resolution: wire `useIdleTimeout` from each SPA's `App.vue` with the configured timeout values; ensure admin's stricter policy is enforced. Owner: a future security-hardening sprint.

**Pre-existing items from chunks 6 + 7.1 + 7.2-7.3 remain open** (SQLite-vs-Postgres CI, TOTP issuance does not honor `Carbon::setTestNow()`, `auth.account_locked.temporary` `{minutes}` interpolation gap, Laravel exception handler JSON shape for unauthenticated `/api/v1/*`, test-clock × cookie expiry interaction, Vue 3 attribute fall-through). None are triggered by Group 2 work.

---

## Verification results

| Gate                                            | Result                                                                                                         |
| ----------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| `apps/api` Pint                                 | Pass                                                                                                           |
| `apps/api` PHPStan (max level via phpstan.neon) | Pass                                                                                                           |
| `apps/api` Pest                                 | 356 passed (1062 assertions)                                                                                   |
| `apps/main` typecheck / lint / Vitest           | Pass / Pass / 234 passed                                                                                       |
| `apps/admin` typecheck / lint                   | Pass / Pass                                                                                                    |
| `apps/admin` Vitest                             | 101 passed (43 store from Group 1 + 58 new across guards/router/api/App/architecture, incl. chained-flow test) |
| `apps/admin` Vitest coverage                    | 100% lines / branches / functions / statements on `core/router/**`, `core/api/**`, `modules/auth/stores/**`    |
| `packages/api-client` Vitest                    | 88 passed                                                                                                      |
| Repo-wide `pnpm -r lint` / `typecheck`          | Clean                                                                                                          |
| Architecture tests                              | All standing tests green; one new admin test (`no-direct-router-imports.spec.ts`)                              |
| Playwright `pnpm test:e2e`                      | Not exercised by Group 2; chunk-7.1 saga's specs #19 + #20 remain green from prior CI runs                     |

---

## Spot-checks performed

1. **D7 intended-destination preservation across MFA-enrollment redirect** (verification of structural divergence from main). The claim is that admin's `requireAuth.mfaEnrollmentRequired` branch passes `query.redirect=<original-path>` while main's same branch does not. Reviewed `guards.ts` source + the existing test cases in `guards.spec.ts` and `index.spec.ts`. **Mid-spot-check disciplined self-correction:** Cursor's response noticed that the chained-flow scenario (`/settings → /sign-in?redirect=/settings → /auth/2fa/enable?redirect=/settings → /settings`) was tested in three separate hops but NOT as a single end-to-end sequence. Cursor proactively added `createRouter > full chained D7 flow: ...` test in `index.spec.ts`, verified passing, updated the review file to record the addition. Test count went 100 → 101 (+1); 100% coverage maintained. **This is the strongest disciplined-self-correction example yet in the project** — Cursor caught the coverage gap during the spot-check response and resolved it without waiting for a change-request. **Verdict: correct + complete + chained-flow now tested end-to-end.**

2. **D5 + guard ordering for edge case** (verification that `requireMfaEnrolled` handles the edge state `mfaEnrollmentRequired: false` AND `isMfaEnrolled: false` cleanly). The edge state shouldn't occur in production (backend's `EnsureMfaForAdmins` middleware enforces the invariant) but should fail safely if it does. Reviewed routes.ts + guards.ts + the relevant test cases. Trace: `requireAuth` falls through (user non-null, `mfaEnrollmentRequired: false`, no error); `requireMfaEnrolled` catches the inconsistency (`!isMfaEnrolled`) and redirects to `auth.2fa.enable` with NO query. After enrollment, user lands on dashboard (not original `/settings`). **Asymmetry vs D7 normal path is intentional and matches main verbatim** — the defence-in-depth guard's job is to fail safely, not to support deep-linking on a path that shouldn't exist. Justification documented in spot-check response. **Verdict: correct.** The asymmetry between primary enforcer (preserves) and defence-in-depth guard (does not preserve) is the right shape.

3. **Diff stat for net change shape:** 5 modified files (+332/-57), 8 new files (+1,467), 13 files touched. Shape matches expectations for sub-chunk 7.4 (Layer 1 router/guards/routes + Layer 2 401 wiring + Layer 3 unit tests + Layer 4 architecture test + Layer 5 coverage gate + 1 review file). No backend changes; no E2E changes; no `pnpm-lock.yaml` churn.

---

## Cross-chunk note

None this round. Confirmed:

- Chunks 6.2–6.4 main store invariants intact; admin store from Group 1 is consumed by Group 2's guards without modification.

- Chunks 6.5–6.7 main router invariants intact; main's router unchanged by Group 2. Admin's router is a path-(b) mirror, not an extension.

- Chunks 6.8–6.9 + 7.1 backend invariants intact; no backend changes in Group 2.

- Group 1 (sub-chunks 7.2 + 7.3) deferred items remain open as recorded: `bootstrap()` caller wire (now landed in 7.4 via the `requireAuth` guard's first action), additional admin i18n strings for guard-driven redirect notices (none needed — existing bundle entries cover the guard's surface).

- The chunk-6.1 `App\TestHelpers` gating contract is unchanged; no new test-helper endpoints added in Group 2.

---

## Process record — compressed pattern (eighth instance)

The compressed pattern continues to work as intended. Group 2 was a single sub-chunk in one Cursor session (per the Option A grouping), and the result is what the grouping decision predicted: dedicated scrutiny on the design-work sub-chunk surfaced 9 deviations cleanly. Specific observations from this round:

- **The "verify main's actual implementation" discipline worked.** Five paraphrase-vs-actual deviations caught at build time rather than ship time. The discipline upgrade for Group 3 (specify mirrors with file:line citations) should drop this category further.

- **Mid-spot-check disciplined self-correction:** the strongest example yet. Cursor caught the chained-flow coverage gap during the spot-check response and resolved it (wrote the test, verified passing, updated the review file). Pattern is now confirmed across multiple groups (chunk 6.5, chunk 7.1's `AuthRateLimitTest` expansion, Group 2's chained-flow test). **Recorded as a baseline expectation, not just a happy accident.**

- **Single completion artifact at the end:** One chat summary, one draft review file. Cursor's draft was thorough enough that my merged review preserves most of it by reference.

- **Honest deviation flagging with category labels.** Cursor's draft introduced explicit category labels (paraphrase-vs-actual, structurally-correct admin adaptation, phase-deferred mirror, tech-debt-flagged carry-forward) that made the deviation distribution legible. **Worth carrying forward as a process pattern** — categorized deviations are easier to address structurally at kickoff time than uncategorized ones.

The compressed pattern + Option A grouping carries forward into Group 3 (sub-chunks 7.5 + 7.6 + 7.7) with the additional discipline upgrade noted above.

---

## What Group 2 closes for Sprint 1

- ✅ Admin SPA router with guards enforcing authentication, mandatory MFA enrollment, deep-link intended-destination preservation, and 401-interceptor-driven redirects.
- ✅ Foundation for Group 3 (sub-chunk 7.5) to drop real auth page components into the existing route slots.
- ✅ Mandatory-MFA enforcement is correct and exhaustively tested at three levels (guard unit tests, dispatcher integration tests, end-to-end chained-flow test).
- ✅ Architecture invariant pinned: stores never import the router.

**Group 3 (sub-chunks 7.5 + 7.6 + 7.7) is next.** Three sub-chunks in one session: admin auth pages + admin E2E specs + admin module README + chunk-7 self-review. Will kick off as a fresh Cursor session.

---

_Provenance: drafted by Cursor on Group 2 completion (compressed-pattern process per `PROJECT-WORKFLOW.md` § 3 step 6, modified). Independently reviewed by Claude with two targeted spot-checks (D7 intended-destination preservation chained-flow + D5 edge case for defensive guard). Nine honest deviations surfaced and categorized (5 paraphrase-vs-actual, 3 structurally-correct admin adaptations, 1 tech-debt carry-forward), all resolved with structurally-correct alternatives matching main's actual patterns. Mid-spot-check disciplined self-correction confirmed as a baseline expectation. The pattern of "every chunk-6 + 7.1 + 7.2-7.3 + 7.4 group catches at least one hidden assumption" is now eight-for-eight. Status: Closed. No change-requests; Group 2 lands as-is. Closes sub-chunk 7.4 and stages Group 3 (sub-chunks 7.5 + 7.6 + 7.7) as the chunk-7 closure work._
