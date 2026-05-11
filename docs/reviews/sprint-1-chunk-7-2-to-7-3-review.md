# Sprint 1 — Chunk 7 Group 1 Review (sub-chunks 7.2 + 7.3: admin SPA Pinia store + admin SPA i18n bundle)

**Status:** Closed. No change-requests; the work is mergeable as-is.

**Reviewer:** Claude (independent review) — incorporating Cursor's self-review draft.

**Reviewed against:** `PROJECT-WORKFLOW.md` § 5 (all standing team standards) + § 7 (spot-checks-before-greenlighting) + § 10 (session boundaries), `02-CONVENTIONS.md` § 1 + § 3 + § 4 (esp. § 4.3 coverage thresholds), `04-API-DESIGN.md` § 4 + § 7 + § 8 (error envelope shapes), `05-SECURITY-COMPLIANCE.md` § 6 (admin auth security + mandatory 2FA enforcement), `07-TESTING.md` § 4 + § 4.4, `20-PHASE-1-SPEC.md` § 5 + § 7, `security/tenancy.md` (admin auth is pre-tenant), `feature-flags.md`, `tech-debt.md` (no entries triggered by this group), all four chunk-6 review files (with particular attention to chunks 6.2–6.4 for the store mirror and chunk 6.3 for the i18n architecture test), `sprint-1-chunk-7-1-review.md` (including post-merge addendum), and `reviews/sprint-1-chunk-6-plan-approved.md`.

This is the first review group of chunk 7. After Group 1 lands, the admin SPA has a complete, tested Pinia auth store mirroring main's chunks 6.2–6.4 contract, three-locale i18n bundles for the full admin auth surface (including pre-staged mandatory-MFA strings for sub-chunk 7.5 to consume), and architecture tests covering the recovery-codes-transience invariant and the i18n-drift-detection contract. No admin UI is built; that's sub-chunks 7.5–7.6. The router + mandatory-MFA enforcement that consumes the store's `isMfaEnrolled` getter and `mfaEnrollmentRequired` field is sub-chunk 7.4.

---

## Scope

Cursor's draft enumerates the full scope in detail. The merged review preserves Cursor's draft as the durable record by reference rather than restating. Brief orientation:

- **Sub-chunk 7.2 (admin SPA Pinia store):** `useAdminAuthStore` mirroring main's `useAuthStore` (chunks 6.2–6.4) with admin-specific narrowing. State: `user`, `bootstrapStatus`, `mfaEnrollmentRequired`, six per-action loading flags. Getters: `isAuthenticated`, `userType`, `isMfaEnrolled`. Actions: `setUser`, `clearUser`, `bootstrap`, `login`, `logout`, `enrollTotp`, `verifyTotp`, `disableTotp`, `regenerateRecoveryCodes`. Plus `admin-auth.api.ts` (module-local re-export of the singleton, with a dedicated architecture test pinning the re-export shape), full Vitest suite (43 tests covering every state field, getter, action, and the optimistic-update / dedupe / per-action loading-flag contracts), and the recovery-codes architecture test.

- **Sub-chunk 7.3 (admin SPA i18n bundle):** `apps/admin/src/core/i18n/index.ts` bootstrap mirroring main's pattern; three locale bundles (en, pt, it) under `apps/admin/src/core/i18n/locales/` covering the full Identity backend code set (`auth.*` + `rate_limit.exceeded`) plus admin-specific copy on UI strings and a new mandatory-MFA banner/heading/description triple pre-staged for 7.5 consumption; path-(b) mirror of `i18n-auth-codes.spec.ts` harvesting from the backend Identity tree and resolving against admin's bundles.

---

## Acceptance criteria — all met

(All Group 1 acceptance criteria from the kickoff — store contract, 100% Vitest coverage, api-client admin functions, isMfaEnrolled correctness, recovery-codes architecture invariant, three-locale i18n bundles, admin i18n architecture test, all existing tests remain green, lint/typecheck clean across all three apps + api package, no new tech-debt added, no Playwright work — all ✅. Reproduced verbatim in Cursor's draft; merged review preserves the same line-by-line verdicts. Verification gates summarized in §"Verification results" below.)

---

## Plan corrections / honest deviation flagging — six items

**Seventh instance** in chunk 6 + 7.1 + 7.2-7.3 of Cursor flagging where the kickoff carried hidden assumptions that didn't hold. Precedents: chunk 6.1 (Carbon `tearDown`), chunks 6.2–6.4 (rename target), chunks 6.5–6.7 (401 interceptor architecture + idle redirect path), chunks 6.8–6.9 (App.vue routing + IssueTotpController identifier + cache TTL hermeticity + dashboard URL + i18n interpolation), sub-chunk 7.1 (in-flight TOTP secret access + missing `meta.seconds` emission), and now Group 1 (six deviations).

**Seven for seven.** The pattern is permanent. This round had the highest count of deviations in a single group (six), and they're all the same fundamental shape: **my kickoff embedded assumptions about main SPA's implementation that don't match the actual main code**.

This is worth naming explicitly. When sub-chunk 7.4 kicks off, the kickoff-writing discipline should default to "verify main's actual implementation before specifying admin's mirror" rather than "specify admin's mirror from the structural intent and let Cursor flag where main diverges."

### Deviation #1 — No `packages/api-client/src/auth/admin/` subdirectory

**Kickoff said:** "api-client admin functions: `packages/api-client/src/auth/admin/` (or alongside main's — pick based on existing architecture)."

**Why the existing pattern is sufficient:** The api-client uses a `variant: 'admin' | 'main'` parameter on existing auth functions rather than separate files. Admin auth endpoints (`/api/v1/admin/auth/*`) are addressed by passing `variant: 'admin'` to the same function signatures main uses. Creating a separate subdirectory would duplicate the function bodies; the variant pattern routes the call cleanly without duplication.

**Why this satisfies the invariant:** The structurally-correct shape is whatever the existing api-client architecture prefers. The variant pattern was already established for handling the admin/main split; consuming it from the new admin store keeps the api-client surface uniform across SPAs.

### Deviation #2 — No sessionStorage persistence; bootstrap-based rehydration

**Kickoff said:** "Persisted state: `user` and `isAuthenticated` to sessionStorage; nothing else."

**Why it didn't hold:** Main's chunks 6.2–6.4 implementation doesn't use sessionStorage at all. It uses a `bootstrap()` action that calls `GET /api/v1/me` on app mount (via the router guard from chunks 6.5–6.7) and rehydrates the store from the session-cookie-authenticated response. The kickoff embedded an assumption from earlier conversation that didn't match the actual implementation.

**Alternative taken — accepted:** Admin mirrors main exactly. The `bootstrap()` action is fully implemented in the store (with `bootstrapStatus: 'idle' | 'pending' | 'authenticated' | 'anonymous' | 'mfa-required' | 'error'` plus the dedupe cache and 401-anonymous + 403-MFA-required + error branches all identical to main). The caller wire (`router.beforeEach` triggering `bootstrap()`) lands in sub-chunk 7.4. This is deferred-not-missing: the store's contract is ready; the consumer is what 7.4 builds.

**Why this is the structurally-correct shape:** Session-cookie-based authentication doesn't need client-side persisted state because the cookie IS the persistence layer. Rehydration on mount via `/me` is the canonical pattern; sessionStorage would duplicate the cookie's role and create a desync risk if the cookie expires but sessionStorage doesn't.

### Deviation #3 — Action names mirror main's exact identifiers

**Kickoff said:** Actions: `signIn`, `signOut`, `refresh`, `clearState`.

**Why it didn't hold:** Main uses `login`, `logout`, `bootstrap`, `clearUser`, `setUser` as the actual action names. The kickoff paraphrased; Cursor preserved exact identifiers for cross-SPA consistency.

**Alternative taken — accepted:** Admin actions: `setUser`, `clearUser`, `bootstrap`, `login`, `logout`, plus the 2FA actions (see Deviation #5). Exact match to main's action surface.

**Why this is the structurally-correct shape:** Cross-SPA consistency is more important than the kickoff's paraphrased names. If the action names diverge between SPAs, future Cursor sessions consuming the stores need to context-switch by SPA; uniform names reduce cognitive load.

### Deviation #4 — State shape mirrors main's exact fields (avoiding `recoveryCodes` in state)

**Kickoff said:** State: `user`, `isAuthenticated`, `isLoading`, `lastError`, `recoveryCodes` (transient only; not persisted).

**Why it didn't hold:** Two structural differences from main:

- Main has `bootstrapStatus` (the dedupe + status surface) rather than `isLoading + lastError`. The status enum encodes the loading state, the bootstrap result type, and the error state in one field.
- Main has NO `recoveryCodes` field. The chunk-6.7 invariant ("recovery codes never persist beyond the page lifetime") is enforced by NOT having a store field for them. Codes flow through action return values to component-local state, never landing in the store. Including `recoveryCodes` in state — even marked "transient" — would have been a direct violation of `PROJECT-WORKFLOW.md` § 5 standard 5.1.

**Alternative taken — accepted:** State fields: `user`, `bootstrapStatus`, `mfaEnrollmentRequired`, plus six per-action loading flags (`isLoggingIn`, `isLoggingOut`, `isEnrollingTotp`, `isVerifyingTotp`, `isDisablingTotp`, `isRegeneratingRecoveryCodes`). No recovery codes field.

**Why this is the structurally-correct shape:** This is one of the most consequential deviations. The kickoff's "recoveryCodes in state, transient only" would have created a footgun: a future contributor could easily add sessionStorage persistence to "transient" state, silently violating the chunk-6.7 invariant. The architecture test enforces "no recovery-codes refs in store source"; that enforcement is only meaningful if the kickoff doesn't request the very field the test bans.

**Recorded for future kickoffs:** When the kickoff and a standing invariant conflict, the invariant wins. Cursor's call to flag and resolve was correct.

### Deviation #5 — 2FA actions kept on the admin store (enrollTotp/verifyTotp/disableTotp/regenerateRecoveryCodes)

**Kickoff said:** "Admin SPA forgot-password / reset / 2FA enrollment flows — out of Sprint 1 scope per `20-PHASE-1-SPEC.md` § 5."

**Why it needed sharpening:** The "out of scope" framing was about the user-facing FLOW (sign-up → reset → enrollment pages and the wiring between them). The store-level ACTIONS for 2FA management are different: they're needed in Sprint 1 for the mandatory-MFA enforcement flow (sub-chunk 7.4) and the admin-side 2FA management UI on the admin profile page (sub-chunk 7.5). Mandatory-MFA enforcement requires that an admin user who signs in without 2FA enrolled is forced into an enrollment flow before accessing any admin route — which means the store needs `enrollTotp` and `verifyTotp` actions to drive that flow.

**Alternative taken — accepted:** Four 2FA actions land on the admin store with full Vitest coverage. The actions wrap the existing `authApi.*` functions; no production code in the api-client surface changes. The actions all preserve the chunk-6.7 invariant (recovery codes never enter store state — verified five-ways in spot-check 2; see below).

**Why this is the structurally-correct shape:** Mandatory-MFA is the differentiator that makes admin auth structurally different from main auth. The store's job is to expose the state and actions that the router (7.4) and pages (7.5) need to enforce it. Building the actions in Group 1 keeps the store complete; building UI without these actions in 7.4–7.5 would require either a store rebuild or out-of-store API calls.

### Deviation #6 — Admin i18n bundle covers the full Identity backend code set

**Kickoff said:** Admin i18n bundles cover "every backend `auth.*` and `rate_limit.*` code that admin auth endpoints can emit, plus admin-specific copy where it differs from main."

**Why it needed sharpening:** The architecture test (`i18n-auth-codes.spec.ts`) harvests every `auth.*` and `rate_limit.*` literal from the entire Identity backend tree, not just admin-specific emit-sites. A narrowed admin bundle that only covered admin-specific codes would fail the architecture test — the test cares about "no code missing", not "no extra code".

**Alternative taken — accepted:** Admin bundle covers the same full Identity backend code set main does, with admin-specific copy on UI strings (and the new mandatory-MFA strings). This means admin and main share most of their code coverage but diverge on UI string copy.

**Why this is the structurally-correct shape:** "No code missing" is the load-bearing invariant for the architecture test; "no extra code" would be a separate concern (and a desirable one, but not in scope here). Path-(b) mirror under `apps/admin/tests/unit/architecture/i18n-auth-codes.spec.ts` is the right call given admin's bundle has admin-specific UI string overrides; extending the main test to also check admin's bundle would couple them awkwardly.

### Process record on these six deviations

Six deviations in a single group is notable. The pattern is consistent: my kickoff-writing discipline still embeds assumptions about main's implementation that don't match reality, particularly when paraphrasing API names or state shapes. **For sub-chunk 7.4's kickoff (Group 2), I will read main's actual router + guard implementation before specifying admin's mirror.** This should reduce the deviation count without reducing implementation quality.

---

## Standout design choices (unprompted)

Cursor's draft enumerates several design choices. Three deserve highlighting as broadly applicable patterns:

- **Action shape preserving the chunk-6.7 invariant via `return await` patterns.** The 2FA actions that return recovery codes (`enrollTotp`, `regenerateRecoveryCodes`) use `return await authApi.*()` — the recovery codes flow through the action's return value to the caller without ever landing in a local `ref` or being written to store state. `verifyTotp`'s `result` is a function-scoped const that dies on return. Verified five-ways in spot-check 2; documented in store source. **This is the canonical pattern for any future action that returns transient data** that must not persist beyond the call. Worth recording in the team standards.

- **Six per-action loading flags rather than a single `isLoading`.** Main's pattern is one boolean per action, allowing independent flag state for concurrent actions. The admin store mirrors this: `isLoggingIn`, `isLoggingOut`, `isEnrollingTotp`, `isVerifyingTotp`, `isDisablingTotp`, `isRegeneratingRecoveryCodes`. The kickoff's simpler "isLoading" would have collapsed all of these into one bit, losing the ability for UI to render correctly when (e.g.) a logout is in flight while a regenerate-codes is also pending. Per-action flags are the structurally-correct shape for any store with multiple concurrent action surfaces.

- **`bootstrapStatus` as a status enum rather than `isLoading + lastError + userPresent`.** The status enum encodes the loading state, the bootstrap result type (`'authenticated' | 'anonymous' | 'mfa-required' | 'error'`), and the timing (`'idle' | 'pending'`) in one field. This means UI consumers can `switch (status)` cleanly rather than reading three booleans and inferring state. The kickoff's "isLoading + lastError" pattern would have lost this clarity. Worth recording as the canonical pattern for any future store with multi-state lifecycle.

---

## Decisions documented for future chunks

- **When the kickoff and a standing invariant conflict, the invariant wins.** Established by deviation #4 (recoveryCodes in state would have violated `PROJECT-WORKFLOW.md` § 5 standard 5.1). Cursor's call to flag and resolve was correct. Future kickoffs that accidentally request state shapes that violate invariants should be deviated-against; Claude's kickoff-writing discipline should default to "verify main's actual implementation before specifying admin's mirror" rather than "specify the mirror from structural intent."

- **Action names on mirror stores match exact identifiers from the original, not the kickoff's paraphrase.** Established by deviation #3. Cross-SPA action-name consistency reduces context-switching for future Cursor sessions consuming both stores.

- **Mirror stores use the same persistence strategy as the original.** Established by deviation #2 (bootstrap-based rehydration, not sessionStorage). The persistence layer is determined by the auth model (session cookie vs. JWT vs. local state); both admin and main use session cookies, so both use bootstrap-based rehydration.

- **Architecture tests that allowlist specific identifiers should document the allowlisted strings inline in the test source.** The `no-recovery-codes-in-store.spec.ts` test allows `isRegeneratingRecoveryCodes` (the loading flag boolean). If a future architecture test adds a second allowlist exception, future Cursor sessions reading the test should see the precedent inline rather than having to dig through commit history. Worth recording as a process pattern; not a code change for Group 1.

- **`return await` is the canonical pattern for actions returning transient data.** Established by the 2FA actions in this group. The pattern guarantees the transient data flows through the function's return value without ever being captured in a local variable that could leak. Future actions in any store that return data that must not persist (recovery codes, one-time tokens, password reset confirmations, etc.) should use the same shape.

- **Per-action loading flags rather than a single `isLoading` field on stores with multiple action surfaces.** Established by main's chunks 6.2–6.4 and confirmed by Group 1. Concurrent actions need independent flag state for correct UI rendering.

- **Status enums for multi-state lifecycle, not boolean tuples.** Established by `bootstrapStatus`. Future stores with similar lifecycles (e.g., file upload progress, multi-step form submission, async data fetching) should use enum-typed status fields rather than `isLoading + lastError + result` triples.

---

## Tech-debt items

**No new tech debt added by Group 1.**

**Pre-existing items from chunk 6 + 7.1 remain open** (SQLite-vs-Postgres CI, TOTP issuance does not honor `Carbon::setTestNow()`, `auth.account_locked.temporary` `{minutes}` interpolation gap, Laravel exception handler JSON shape for unauthenticated `/api/v1/*`, test-clock × cookie expiry interaction, Vue 3 attribute fall-through). None are triggered by Group 1 work.

---

## Verification results

| Gate                                            | Result                                                                                                                        |
| ----------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `apps/api` Pint                                 | Pass                                                                                                                          |
| `apps/api` PHPStan (max level via phpstan.neon) | Pass                                                                                                                          |
| `apps/api` Pest                                 | 356 passed (1062 assertions)                                                                                                  |
| `apps/main` typecheck / lint / Vitest           | Pass / Pass / 234 passed (100% coverage maintained)                                                                           |
| `apps/admin` typecheck / lint / build           | Pass / Pass / Pass                                                                                                            |
| `apps/admin` Vitest                             | 53 passed (10 from prior groups + 43 new); 100% coverage on `src/modules/auth/**`                                             |
| `packages/api-client` Vitest                    | 88 passed                                                                                                                     |
| Repo-wide `pnpm -r lint` / `typecheck`          | Clean                                                                                                                         |
| Architecture tests                              | All standing tests green; three new admin architecture tests added (recovery-codes, auth-api-reexport-shape, i18n-auth-codes) |
| Playwright `pnpm test:e2e`                      | Not exercised by Group 1; chunk-7.1 saga's specs #19 + #20 remain green from prior CI run                                     |

---

## Spot-checks performed

1. **Bootstrap rehydration mechanism** (verification that admin store's `bootstrap()` action is structurally correct and the deferred caller wire is correctly identified as deferred-not-missing). Reviewed `useAdminAuthStore.ts` source — `bootstrap()` action is fully implemented with state field (`bootstrapStatus`), dedupe cache, and 401-anonymous + 403-MFA-required + error branches identical to main's. Reviewed `apps/admin/src/main.ts` and `apps/admin/src/App.vue` — admin app shell is still Sprint-0 scaffolding (renders `t('app.title')` + `t('app.subtitle')` + `t('app.sprint0Notice')`); no router exists yet to call `bootstrap()`. **Verdict: deferred-not-missing.** The store's contract is ready for sub-chunk 7.4's router to consume via `router.beforeEach`, mirroring main's chunk-6.5 pattern at `apps/main/src/core/router/guards.ts`. Follow-up table in Cursor's draft correctly identifies this as 7.4's wire.

2. **2FA actions + recovery codes transience** (verification that the chunk-6.7 invariant is preserved across all four 2FA actions). Reviewed `useAdminAuthStore.ts` lines 234-339 plus `no-recovery-codes-in-store.spec.ts`. Five-way trace verified:
   - `enrollTotp`: `return await authApi.enrollTotp()` — provisional-token payload returned to caller; payload doesn't contain codes (they only appear after `verifyTotp` confirms).
   - `verifyTotp`: `result` is a function-scoped const that dies on return; store mutates `user.value.attributes.two_factor_enabled = true` (optimistic update) but never writes `result.recovery_codes` to any ref.
   - `disableTotp`: no recovery codes in scope at all.
   - `regenerateRecoveryCodes`: `return await authApi.regenerateRecoveryCodes(payload)` — codes pass through return value without local capture.
   - Exposed surface: store's return block has no `recoveryCodes` field, no setter for one.

   Architecture test scans every `.ts` under `apps/admin/src/modules/**/stores/` for `const X = ref(...)` declarations matching `/recovery_?codes?/i`; only allowlisted match is `isRegeneratingRecoveryCodes` (the loading flag boolean). Runtime tests verify `JSON.stringify(store.$state)` doesn't contain the actual code values after action calls. **Verdict: correct.** The chunk-6.7 invariant is preserved at source level (architecture test), at type level (no `recoveryCodes` field in exposed surface), and at runtime (state assertion tests).

3. **Diff stat** (verification of net change shape): 4 files modified (+65/-6), 10 new files (+1,982). Modified files are config + i18n bootstrap + package + lockfile entry — all expected. New files are 1 store + 1 store spec + 1 api re-export + 3 i18n bundles + 3 architecture tests + 1 review file. Shape matches expectations for a mirror-work group with no scope expansion.

---

## Cross-chunk note

None this round. Confirmed:

- Chunks 6.2–6.4 main store invariants intact. `useAuthStore` unchanged; the new `useAdminAuthStore` is a sibling, not a replacement.

- Chunks 6.5–6.7 UI invariants intact. `useErrorMessage` is shared (admin consumes main's composable directly); no widening of `isLikelyBundledCode` prefix allowlist needed for Group 1.

- Chunks 6.8–6.9 + 7.1 backend invariants intact. The admin SPA consumes the existing api-client `variant: 'admin'` pattern; no new backend endpoints landed in Group 1, no changes to existing backend surfaces.

- Sub-chunk 7.1 deferred items remain open as recorded in the post-merge addendum. None are triggered or addressed by Group 1.

- The chunk-6.1 `App\TestHelpers` gating contract is unchanged; no new test-helper endpoints added in Group 1.

---

## Process record — compressed pattern (seventh instance)

The compressed pattern continues to work as intended. Group 1 is the first compressed-pattern application across **two sub-chunks in one Cursor session**, and the result is what the Option A grouping decision predicted: cleaner closure, fewer round-trips, no loss of review quality. Specific observations from this round:

- **Two sub-chunks in one session:** the cognitive load on Cursor was manageable. Plan-then-build worked across both layers without confusion; the single completion artifact (one review file covering both sub-chunks) didn't obscure either layer. **Confirms the Option A grouping decision for the rest of chunk 7.**

- **Honest deviation flagging:** Six deviations in a single group is the highest count yet. All six are interpretation-of-the-kickoff issues where my kickoff embedded assumptions about main's implementation that don't match reality. **New kickoff-writing discipline for Group 2: verify main's actual implementation source before specifying admin's mirror.** This applies particularly to the router + guards pattern (chunks 6.5–6.7) that sub-chunk 7.4 will mirror, since router code tends to be intricate.

- **Disciplined self-correction at spot-check time:** Less applicable this round — the spot-checks confirmed Cursor's existing work rather than surfacing coverage gaps. Pattern continues to be available when needed.

- **Verbatim outputs:** Cursor's spot-check responses showed both source files AND grep outputs AND architecture test contents. Trust dividend earned across chunk 6 + 7.1 continues to pay off; verifying Cursor's verification adds value only when there's a specific concern.

- **Backend assumption check:** The kickoff flagged the admin backend endpoints as a potential scope-boundary decision. Cursor's read confirmed they exist (via the existing `variant: 'admin'` api-client pattern), so no scope expansion was needed. The process pattern worked as designed — flag the concern at kickoff time, resolve at read time, no surprise scope expansion at commit time.

The compressed pattern + Option A grouping carries forward into Group 2 (sub-chunk 7.4 alone) unchanged.

---

## What Group 1 closes for Sprint 1

- ✅ Admin SPA Pinia auth store complete; mirror of main's chunks 6.2–6.4 contract.
- ✅ Admin SPA i18n bundles in all three locales (en, pt, it) covering the full Identity backend code set plus mandatory-MFA strings pre-staged for 7.5.
- ✅ Architecture tests covering recovery-codes-transience invariant and i18n-drift-detection contract for the admin SPA.
- ✅ Foundation in place for sub-chunk 7.4 (router + guards + mandatory-MFA enforcement) to consume `isMfaEnrolled` and `mfaEnrollmentRequired`.

**Group 2 (sub-chunk 7.4) is next.** The router + mandatory-MFA enforcement flow is the genuine design work in chunk 7 — dedicated review group, no bundling. Will kick off as a fresh Cursor session.

---

_Provenance: drafted by Cursor on Group 1 completion (compressed-pattern process across two sub-chunks per `PROJECT-WORKFLOW.md` § 3 step 6, modified). Independently reviewed by Claude with two targeted spot-checks (bootstrap rehydration mechanism + recovery codes transience). Six honest deviations surfaced (no api-client subdirectory; bootstrap-based rehydration vs sessionStorage; exact-match action names; state shape avoiding recoveryCodes field; 2FA actions kept on store; full Identity code set in admin bundle), all resolved with structurally-correct alternatives. The pattern of "every chunk-6 + 7.1 + 7.2-7.3 group catches at least one hidden assumption" is now seven-for-seven. Status: Closed. No change-requests; Group 1 lands as-is. Closes sub-chunks 7.2 + 7.3 and stages sub-chunk 7.4 for Group 2._
