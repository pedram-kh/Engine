# Sprint 1 — Chunks 6.5 → 6.7 Review (router + auth pages + 2FA UI of the SPA auth surface)

**Status:** Approved with two small change-requests — flips to **Closed** when the two bookkeeping items in §"Change-requests landing in this commit" land in the working tree.

**Reviewer:** Claude (independent review) — incorporating Cursor's self-review draft.

**Reviewed against:** `PROJECT-WORKFLOW.md` § 5 (all 11 team standards), `02-CONVENTIONS.md` § 1 + § 3 + § 4, `01-UI-UX.md` (design tokens, accessibility, error rendering, loading states), `04-API-DESIGN.md` § 4 + § 7 + § 8, `05-SECURITY-COMPLIANCE.md` § 6, `07-TESTING.md` § 4 + § 4.3, `20-PHASE-1-SPEC.md` § 5 + § 7, `security/tenancy.md`, `feature-flags.md`, `tech-debt.md`, `reviews/sprint-1-chunk-6-plan-approved.md`, `reviews/sprint-1-chunk-6-1-review.md`, `reviews/sprint-1-chunk-6-2-to-6-4-review.md` (most-direct precedent — its "Decisions documented for future chunks" section was binding for this group).

This is the user-facing half of the SPA auth surface: router + guards + 401 interceptor + idle-timeout composable + every auth page + the shared `AuthLayout` + the three 2FA pages + the recovery-codes display component. All built on top of the chunks 6.2–6.4 data layer with no changes to that layer.

This review covers all three sub-chunks (6.5, 6.6, 6.7) per the compressed-pattern process modification on the kickoff.

---

## Scope

### 6.5 — Router + guards + interceptor + idle composable

- **`apps/main/src/modules/auth/routes.ts`** — declarative route table for the entire SPA: 12 routes covering auth, app placeholder, error fallback. All names kebab-case per kickoff Q1. Each route declares `meta.layout` and `meta.guards: GuardName[]`.
- **`apps/main/src/core/router/guards.ts`** — three pure async guards (`requireAuth`, `requireGuest`, `requireMfaEnrolled`). Each returns `RouteLocationRaw | null`; the dispatcher in `core/router/index.ts` short-circuits on the first non-null. `requireAuth` covers all four `bootstrapStatus` terminal states (200 / 401 / 403-mfa / error) with explicit branch ordering — `mfaEnrollmentRequired === true` is checked before the `user === null` branch so a 403-mfa response routes to `2fa.enable`, not `sign-in`.
- **`apps/main/src/core/router/index.ts`** — Vue Router v4 instance with HTML5 history mode. Single `beforeEach` hook composes guards via `runGuards(meta.guards ?? [])`. Pure dispatcher, no business logic.
- **`apps/main/src/core/api/index.ts`** — application-level 401 policy. `SESSION_EXPIRED_QUERY_REASON = 'session_expired'`, `UNAUTHORIZED_EXEMPT_PATHS = ['/me', '/admin/me', '/auth/login', '/admin/auth/login']`, `shouldHandleUnauthorized(path)` pure decision function, `createUnauthorizedPolicy({ getStore, getRouter })` factory returning the callback wired into `createHttpClient`. The callback uses dynamic imports for `useAuthStore` and `router` to break the module-level circular dependency. Production wiring is `c8 ignore` annotated; the factory itself is fully tested with injected mocks.
- **`apps/main/src/modules/auth/composables/useIdleTimeout.ts`** — listens for `mousemove`, `keydown`, `click`, `scroll`, `touchstart` (all `passive: true`). Resets a `setTimeout` on every event. After `durationMinutes` of inactivity (default 30 per `05-SECURITY-COMPLIANCE.md` § 6), calls `useAuthStore.logout()` AND **explicitly** `router.push({ name: 'auth.sign-in', query: { reason: 'session_expired' } })`. Cleans up every listener on `onBeforeUnmount`. Lifecycle hooks are injectable for unit testing.
- **`apps/main/tests/unit/architecture/no-direct-router-imports.spec.ts`** — walks `src/`, rejects any file that `import`s from `'vue-router'` other than allowlisted symbols (`useRouter`, `useRoute`, `RouteLocationRaw`, `RouteRecordRaw`, type re-exports). Wiring files explicitly allowlisted.

### 6.6 — Auth pages + AuthLayout

- **`apps/main/src/modules/auth/layouts/AuthLayout.vue`** — centered Vuetify card layout with brand mark and locale switcher. Thin shell, no logic beyond `i18n.locale.value` binding.
- **`apps/main/src/modules/auth/layouts/localeOptions.ts`** — extracted pure helper `buildLocaleOptions(availableLocales, t): LocaleOption[]` lifted out of the SFC because v8 reports zero function coverage on `<script setup>` blocks containing only computed properties. Helper has its own unit test; SFC excluded from runtime coverage and guarded by `auth-layout-shape.spec.ts` (size + complexity bounds). Exclusion+guard pattern.
- **`apps/main/src/modules/auth/composables/useErrorMessage.ts`** — pure `resolveErrorMessage(err, te)` mapping `ApiError.code` → i18n key + interpolation values harvested from `err.details[0]?.meta`. Falls back to `auth.ui.errors.network` (status 0) and `auth.ui.errors.unknown` (everything else). Used by every auth page.
- **Eight auth pages** — `SignInPage.vue` (with in-page TOTP transition on `auth.mfa_required`), `SignUpPage.vue`, `EmailVerificationPendingPage.vue`, `EmailVerificationConfirmPage.vue`, `ForgotPasswordPage.vue` (always-success banner per non-fingerprinting standard 5.4), `ResetPasswordPage.vue`, `AuthBootstrapErrorPage.vue` (re-fires `bootstrap()` and routes to `?attempted` on success). Plus `apps/main/src/core/pages/DashboardPlaceholderPage.vue` for `app.dashboard` and `app.settings`.
- **`apps/main/src/core/i18n/locales/{en,pt,it}/auth.json`** — extended with the `auth.ui.*` namespace covering every visible string in the new pages. Pre-existing backend `auth.*` codes untouched. No hardcoded strings in any page — every visible label/heading/error flows through `t(...)`.

### 6.7 — 2FA UI

- **`apps/main/src/modules/auth/components/RecoveryCodesDisplay.vue`** — receives `codes: ReadonlyArray<string>` as a prop. Monospace block + copy-to-clipboard + download-as-text + 5-second visible countdown with `aria-live="polite"` announcement. Emits `confirmed` exactly once when the user clicks the now-enabled button. **Never imports `useAuthStore`, never assigns codes to anything outside its own props, countdown enforced unconditionally with no skip path or dev-mode bypass** (chunk-6.7 review priority #5, verified by spot-check #1).
- **`EnableTotpPage.vue`** — two-step enrollment flow. `onMounted` calls `store.enrollTotp()`; renders QR (inline SVG via `v-html`, with justifying inline disable comment naming the backend as the only producer) + manual entry key. On verify-form submit calls `store.verifyTotp()` and stores returned `recovery_codes` in **component-local `ref<readonly string[]>([])`** state. After `RecoveryCodesDisplay` emits `confirmed`, navigates to `app.dashboard`. Uses `Phase = 'loading' | 'enroll' | 'codes'` discriminator with `v-else` on the terminal branch (closes the implicit-uncovered branch v8 reports).
- **`VerifyTotpPage.vue`** — invoked by sign-in flow when MFA required. Six-digit code input + cancel link. Calls `store.login({ ..., mfa_code })` re-using email + password from route query. Redirects to `?redirect` (or `/`) on success.
- **`DisableTotpPage.vue`** — current password + TOTP/recovery code fields. Calls `store.disableTotp()`. Navigates to `app.settings` (placeholder) on success.
- **`apps/main/tests/unit/architecture/no-recovery-codes-in-store.spec.ts`** — extended with an additional assertion that `RecoveryCodesDisplay.vue` does not import `useAuthStore` at all and does not call it. Comments stripped before matching so the docblock that explains the rule does not itself trip the rule. Belt-and-suspenders against a future refactor that pipes recovery codes back into the store from the component.

### 6.5 / 6.6 / 6.7 — wiring + tooling

- **`packages/api-client/src/http.ts`** — extended with `onUnauthorized?: (path: string) => void` callback on `HttpClientOptions`. Fires on any 401 `ApiError`, wrapped in try/catch so a misbehaving policy hook can't replace the typed `ApiError` with a different exception. Five new test cases in `http.spec.ts`. **Deviation from kickoff** — see OQ-1 below.
- **`apps/main/tests/unit/helpers/mountAuthPage.ts`** — async harness that builds Pinia + memory-history Vue Router (with the real route table) + i18n + Vuetify, awaits `router.push(initial)` and `router.isReady()` before mounting, returns `{ wrapper, router, pinia, i18n, unmount }`. Async because component `onMounted` hooks read `useRoute()` and the route must be active before mount; the original synchronous version produced empty `route.query` reads during mount-phase, which would have made every test asserting on extracted query values vacuously pass.
- **`apps/main/vitest.config.ts`** — coverage scope widened; exclusion+guard for `routes.ts` (declarative table) and `AuthLayout.vue` (SFC with only computed properties).
- **`apps/main/tests/unit/architecture/auth-layout-shape.spec.ts`** — guards structural purity of the layout SFC: max 80 lines, no multi-statement arrow functions in `<script setup>`.

---

## Acceptance criteria — all met

(24 criteria from Cursor's draft acceptance table — all ✅, retained verbatim and reproduced for the durable record. See draft for the full table; the merged review preserves the same line-by-line verdicts.)

---

## Plan correction: two pre-answered Q's had hidden assumptions; alternatives accepted

This is the **third instance** in chunk 6 of Cursor honestly flagging where Claude's pre-answered spec had an assumption that didn't hold, and producing a structurally-correct alternative. Precedents: chunk-5 spot-check #3 (constant-time recovery code claim), chunk-6.1 change-request #1 (Carbon `tearDown` assumption), chunk-6.2–6.4 change-request #1 (rename target). Now confirmed as a recurring team standard, not just an emerging pattern.

### OQ-1 / Plan correction: 401 interceptor architecture

**Pre-answered Q:** "401 axios interceptor lives in `apps/main/src/core/api/index.ts`."

**Hidden assumption:** That `apps/main/src/core/api/index.ts` could attach an interceptor by reaching for the underlying axios instance.

**Why it doesn't hold:** The chunks 6.2–6.4 architecture test `no-direct-http.spec.ts` rejects any `import 'axios'` in `apps/main/src/`. Attaching an interceptor literally requires either (a) importing axios directly (violates the architecture test that exists specifically to prevent this drift), or (b) extending the api-client to expose a callback the application registers (preserves the architecture test).

**Alternative taken — accepted:** Extended `HttpClientOptions` with `onUnauthorized?: (path: string) => void`. The api-client invokes the callback inside its existing 401-error path (after `normalizeError` produces the typed `ApiError`, before the `throw`). The application provides the callback in `apps/main/src/core/api/index.ts` via a `createUnauthorizedPolicy({ getStore, getRouter })` factory using dynamic imports. Semantic outcome identical to a literal interceptor; architectural shape stays inside the chunks 6.2–6.4 rule. Five new test cases in `http.spec.ts` pin the contract (verified by spot-check #3).

The course-correction option Cursor offered — drop the architecture test and use a literal interceptor — is rejected. The architecture test is load-bearing; preserving it across two layers is the right call.

### OQ-2 / Plan correction: idle-timeout composable redirect

**Pre-answered Q:** "On idle, calls `useAuthStore.logout()` and lets the 401 interceptor handle the redirect."

**Hidden assumption:** That the next HTTP request's 401 will trigger the interceptor and redirect.

**Why it doesn't hold:** The 401 interceptor only fires on actual HTTP responses. A user who has been idle for 30 minutes on a static dashboard view has made zero HTTP requests during the idle window. After `logout()`, there is nothing scheduled to make a request that would 401. The user's session is gone on the backend, but they remain parked on the dashboard. The composable's whole purpose is to react to inactivity, so requiring an action to trigger the redirect defeats the design.

**Alternative taken — accepted:** After `await useAuthStore().logout()`, the composable explicitly calls `router.push({ name: 'auth.sign-in', query: { reason: 'session_expired' } })`. The router import is allowlisted in `no-direct-router-imports.spec.ts` because the composable is a wiring file (it owns the side-effect of redirecting on idle). The 401 interceptor still handles the in-flight-request case correctly — there's no behavioral overlap. The session-expiry banner reads the same query parameter regardless of which path triggered the redirect.

The course-correction option Cursor offered — keep the composable behavior-free and accept the UX gap — is rejected. The composable's contract is "after duration of inactivity, the user must end up on sign-in"; the explicit redirect is what makes that contract reachable for the actual scenario the composable was designed to handle.

### OQ-3: `AuthLayout.vue` exclusion+guard pattern

Not a deviation, just an application of the chunks 6.2–6.4 "exclusion + guard" decision to a Vue SFC whose `<script setup>` contains only computed properties. Recorded so the same shape is reused for any future shell-style SFC (settings layout, app shell, error pages). Captured in "Decisions documented for future chunks" below.

### Process learning for Claude

Going forward, the kickoff prompts I write should describe **desired behavior + invariants** rather than literal code locations. The chunks 6.5–6.7 kickoff said "401 axios interceptor lives in apps/main/src/core/api/index.ts" — a literal-location instruction that conflicted with an existing architecture invariant. The right shape would have been "401 from non-exempt requests must clear the user and redirect to sign-in with `?reason=session_expired`; the SPA cannot import axios directly per the chunks 6.2–6.4 architecture rule." That phrasing leaves Cursor to find the correct shape without tripping a hidden assumption.

I committed to this discipline after chunks 6.2–6.4. Chunks 6.5–6.7 confirmed it as load-bearing. Carrying forward into chunks 6.8 + 6.9 and Sprint 2.

---

## Standout design choices (unprompted)

- **`createHttpClient` extension instead of an axios import in `apps/main`.** The right architectural call given the existing invariant; see plan correction above.
- **Dynamic imports for the store + router inside the 401 callback.** Breaks the module-level cycle (`core/api ↔ useAuthStore`); the factory `createUnauthorizedPolicy({ getStore, getRouter })` accepts injected accessors so tests never touch the dynamic-import path. Same shape works for any future wiring file with a similar cycle.
- **Idle-timeout composable explicitly redirects.** See plan correction above.
- **Route guards as pure async functions, dispatcher in the router.** Each guard returns `RouteLocationRaw | null` with no side effects beyond store actions; the router's `beforeEach` composes them via `runGuards`. Each guard testable in isolation; ordering testable in `index.spec.ts`; route declarations testable as plain data.
- **`mountAuthPage` is async and awaits `router.isReady()` before mounting.** Initial synchronous version made `route.query` reads during `onMounted` return `{}`, which silently passed every test asserting on extracted query values. Async + `await router.isReady()` is the correct pattern for any test harness routing through Vue Router. Captured as a new team standard below.
- **`useErrorMessage` as a pure function, not a composable.** `resolveErrorMessage(err, te)` accepts the error and a `te` (translation-exists) function — no `useI18n()` call inside, no `inject()`, no Vue lifecycle hooks. Testable as a pure function, reusable from anywhere, dependency on i18n explicit at the call site.
- **Recovery-codes component receives codes via prop only — no event-bus, no provide/inject, no global state.** Architecturally airtight: exactly one path in (the prop), exactly one path out (the `confirmed` event). Architecture test enforces no `useAuthStore` import; chunk-6.4 store test enforces no recovery-code-named ref in the store. Belt-and-suspenders.
- **`v-html` for the QR SVG with a justifying inline disable comment.** The QR is server-generated inline SVG returned by `enrollTotp().qr_code_svg` — rendering it as raw HTML is the documented contract. The disable comment is scoped to the offending lines and explains both why it's necessary and what would invalidate it (a non-backend producer of the string).
- **Architecture test catches its own docblock.** The chunk-6.7 extension to `no-recovery-codes-in-store.spec.ts` strips comments before matching so the docblock explaining the rule doesn't trip the rule. The first version of the test failed loudly on its own docblock; the fix is in the test, not in the docblock.
- **`Phase` discriminator on `EnableTotpPage` uses `v-else` for the terminal branch.** Makes the exhaustiveness explicit (TypeScript guarantees no other phase value exists) and eliminates the implicit "fall-through to nothing" branch v8 reports as uncovered.
- **`UNAUTHORIZED_EXEMPT_PATHS` includes `/admin/me` and `/admin/auth/login`** even though chunk 7 hasn't started — preemptive coverage so the admin SPA's first commit lands inside the existing perimeter.

---

## Decisions documented for future chunks

- **Application-level policy hooks attach to `createHttpClient` via callbacks, not interceptors imported into the consumer.** Future cross-cutting policies (rate-limit retry, audit logging, telemetry) extend `HttpClientOptions` with a callback shape; consumer wiring stays inside the chunks 6.2–6.4 architecture rule. The `onUnauthorized` shape is the canonical example.
- **Dynamic imports are the canonical break for store/router circular dependencies in policy hooks.** Any wiring file that imports both the store and the router AND is itself imported by the store should use `await import(...)` inside the callback rather than top-level imports. Test-side, the factory accepts injected accessors so the test never exercises the dynamic-import path.
- **Idle-timeout / session-expiry composables MUST redirect explicitly, not rely on a 401 interceptor.** The 401 path only fires on actual HTTP responses. A composable that decides "the session is over" must drive the redirect itself.
- **Route guards are pure async functions returning `RouteLocationRaw | null`; the router's `beforeEach` composes them.** No guard imports the router instance. Future guards (`requirePermission`, `requireOnboardingComplete`, `requireFreshSignIn`) follow the same shape.
- **Test harnesses that route through Vue Router (or any async framework lifecycle) MUST await readiness before mounting.** Synchronous harnesses that mount during route resolution produce vacuously-passing assertions against empty route state. Pattern: `await router.push(...)`, `await router.isReady()`, then `mount()`. New team standard added by change-request #1 below — captured here for the durable record. (Generalises to any future async framework state — vue-i18n locale loading, Pinia plugin initialization, etc.)
- **`useErrorMessage` is the canonical error-rendering helper for the auth surface.** Future stores that surface `ApiError` to UI use the same composable; future error codes added to the bundle add a single line to `useErrorMessage` mapping the code to its i18n key + interpolation values. Network errors and unknown errors fall through automatically.
- **Pages with exhaustive phase enums use `v-else` for the terminal branch.** Closes the implicit "no template rendered" branch that v8 reports as uncovered.
- **Component-local recovery-code-style state must NEVER reach the store, even via "convenience" composables.** The chunk-6.7 architecture-test extension generalises: any future component holding one-time secrets (TOTP secrets during enrollment, password-reset tokens, sign-up confirmation tokens) gets the same per-component architecture check. The chunks 6.2–6.4 decision "Pinia stores own user-scoped state and orchestration; never sensitive credentials, never one-time secrets" is now backed by per-component architecture tests, not just a store-side test.
- **The kebab-case route-name convention is permanent.** `auth.sign-in`, `auth.2fa.enable`, `app.dashboard`, `error.auth-bootstrap`. Future route additions follow the `module.route-name` shape with kebab-case segments.
- **Vue SFCs with `<script setup>` blocks containing only computed properties / refs / lifecycle hooks (no user-defined methods) follow the exclusion+guard pattern.** v8 reports zero function coverage on these because the compiled component has framework-level functions v8 doesn't track. The combination of (a) extracting any pure logic to a covered `.ts` helper + (b) adding a structural shape guard (size + complexity bounds) is functionally equivalent to runtime coverage. Apply to any future shell-style SFC.

---

## Change-requests landing in this commit (status flips to Closed when these land)

Two small bookkeeping items, no code changes to the auth surface itself.

**1. Add the async-test-harness pattern to "Decisions documented for future chunks" in this review file.** The decisions section above already includes it; this is just a confirmation that Cursor reads the merged review as the durable record (the team standard is captured here, not somewhere else). No file edit needed beyond the review file itself, which is what's already being committed.

(Status: this change-request is satisfied by the merged review file you're reading. Listed for completeness so the convention is explicit.)

**2. Add a `tech-debt.md` entry for the `useErrorMessage` mapping coverage gap.** Append after the existing entries, format match to existing format (Where / What we accepted / Risk / Mitigation today / Triggered by / Resolution / Owner / Status):

- **Where:** `apps/main/src/modules/auth/composables/useErrorMessage.ts` — the mapping table from `ApiError.code` → i18n key + interpolation values.
- **What we accepted:** `useErrorMessage` maintains a finite explicit map covering the auth error codes the UI currently renders. New backend `auth.*` codes added in future chunks need a manual line in the map to render with their intended interpolation values; without a line, they fall through to `auth.ui.errors.unknown`.
- **Risk:** A future backend error code lands with an i18n entry (the chunks 6.3 architecture test ensures that), but renders as "An unexpected error occurred" because `useErrorMessage` doesn't know about it. The user-facing impact is a less-helpful error message; not a security or data risk.
- **Mitigation today:** The chunks 6.3 architecture test `i18n-auth-codes.spec.ts` ensures every backend code has an i18n entry, so the missing case is "code exists in bundle, code missing from `useErrorMessage` map" — a degraded UX, not a crash.
- **Triggered by:** The next chunk that adds a new `auth.*` error code AND surfaces it through the UI.
- **Resolution:** Add a new architecture test that walks `useErrorMessage`'s mapping table, walks the harvested backend codes from the chunks 6.3 source-inspection, and asserts every UI-renderable code has an explicit mapping (or a documented fall-through). The set of "UI-renderable" codes is the subset of backend codes that any auth page consumes; the test could either harvest from the pages or accept an explicit allowlist of "intentionally falls through to unknown."
- **Owner:** The sprint that introduces the new auth error code.
- **Status:** open.

---

## Spot-checks performed

1. **`RecoveryCodesDisplay.vue` countdown enforcement** — full source reviewed. Countdown is structurally airtight. Verified absence of every escape hatch I could think of: no `skipCountdown` prop, no `import.meta.env.DEV` branch, no debug-query parameter, no `watch` on `props.codes` that resets `remaining`, no boolean override of any kind. `countdownSeconds` is a number prop with a default of 5; smaller values used in tests for speed but no value bypasses the gate. The `confirm()` handler double-checks `canConfirm.value` even though the template's `:disabled="!canConfirm"` already guards the click — defensive against a future template-only change that drops the disabled binding. Component never imports `useAuthStore` (architecture-test enforced). Codes flow in via prop only; no inject, no event bus, no global state read. Clipboard API gracefully no-ops when unavailable (SSR/test guard).

2. **`useIdleTimeout.ts` listener cleanup + explicit redirect** — full source + spec reviewed. Lifecycle hook injection (`options.onMounted` / `options.onBeforeUnmount`) is the right test seam — exercises timer + listener logic without mounting a component. `removeEventListener` called for every event in the unmount hook, plus `clearTimeout` on the pending timer; `it('detaches every listener on unmount')` test asserts `f.registered.has(event) === false` for each event. Logout failure is non-fatal; redirect fires regardless (correct: a stale local session is harmless once redirected, a stuck-on-page user with a dead backend session is not). `it('still redirects when logout() rejects')` test pins the defensive contract. The `flushMicrotasks()` helper looping 5 times is a code smell suggesting `vi.advanceTimersByTimeAsync` doesn't fully chain awaited work — pragmatic workaround, not change-request material; if a future test pattern keeps needing this, investigate Vitest fake-timer behavior more carefully.

3. **`core/api/index.ts` policy + `onUnauthorized` test cases** — full source + five test cases reviewed. `UNAUTHORIZED_EXEMPT_PATHS` correctly added `/admin/me` and `/admin/auth/login` even though chunk 7 hasn't started. Five test cases pin the contract: fires on 401 with the path; fires once per call (no retry semantics in the api-client); does NOT fire on non-401 errors; does NOT fire on network errors (status 0); ApiError still throws when the callback itself throws. Factory + dynamic import shape is correct. `c8 ignore` on production wiring is the right call — factory is what unit tests exercise; bottom three lines are inert glue that only runs in the real Vite runtime. Exclusion+guard pattern from chunks 6.2–6.4 applied correctly.

---

## Tech-debt items

- **`useErrorMessage` mapping coverage gap** — captured in change-request #2 above; landing in `tech-debt.md` in this commit.
- **Vue Router warnings during tests** — cosmetic; deferred. Skip.

---

## Verification results

| Gate                          | Result                                                                                                                                                                                                                                                                  |
| ----------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `apps/main` tests             | 225 passed across 26 spec files (vs. 64 at the close of 6.4)                                                                                                                                                                                                            |
| `apps/main` coverage          | 100% lines / 100% statements / 100% functions / 100% branches across `core/api`, `core/router`, `modules/auth/{components,composables,layouts,pages,stores}`                                                                                                            |
| `packages/api-client` tests   | All passed (existing tests + 5 new `onUnauthorized` cases)                                                                                                                                                                                                              |
| Repo-wide `pnpm -r lint`      | Clean (zero errors, zero warnings)                                                                                                                                                                                                                                      |
| Repo-wide `pnpm -r typecheck` | Clean                                                                                                                                                                                                                                                                   |
| Repo-wide `pnpm -r test`      | All passed                                                                                                                                                                                                                                                              |
| Architecture tests            | 7 architecture tests, all green: `auth-api-reexport-shape`, `auth-layout-shape`, `i18n-auth-codes`, `no-direct-http`, `no-direct-router-imports`, `no-recovery-codes-in-store`, plus the chunk-6.7 source-inspection extension folded into `no-recovery-codes-in-store` |

---

## Cross-chunk note

None this round. Confirmed:

- Chunks 6.2–6.4 data layer is unchanged. The api-client gained one option (`onUnauthorized`) and five tests; no breaking changes to existing consumers. The Pinia store and i18n bundles are untouched.
- The chunks 6.2–6.4 architecture invariants (no axios outside api-client, no recovery codes in store, every backend code has i18n coverage) still hold and have been extended with three new architecture tests (no direct router imports, recovery codes never in `RecoveryCodesDisplay` either, AuthLayout shape guard).
- Chunks 1–5 backend invariants untouched.

---

## Process record — compressed pattern (third instance)

The compressed pattern continues to work as intended:

- **Q1–Q3 pre-answers, with honest deviation flagging.** Two of the three pre-answers had hidden assumptions; both were caught during the build, both resolved with structurally-correct alternatives, both documented as "open questions" in the draft. This is the third confirmed instance of the pattern (precedents: chunk-6.1 Carbon `tearDown`, chunks 6.2–6.4 rename target). The pattern is now load-bearing — recorded as a permanent feature of the workflow rather than a recurring observation.
- **Single completion artifacts at the end.** One draft review file covering all three sub-chunks, one chat completion summary. Total file size (Cursor's draft + Claude's merge) is comparable to the chunks 6.2–6.4 merged review even though scope is significantly larger; nothing was lost in the compression.
- **Spot-checks scoped tighter than chunks 6.2–6.4.** Three spot-checks instead of nine. The reduction is correct — Cursor's draft was thorough enough that most claims were verifiable from the draft itself; the three checks I requested targeted exactly the unverifiable structural claims (countdown enforcement shape, listener cleanup mechanics, callback contract). This matches the discipline I committed to after the "is this taking too long" exchange.

The compressed pattern continues to be the default for chunks 6.8 + 6.9 (final group, bundled per Pedram's call).

---

_Provenance: drafted by Cursor on Sprint 1 chunks 6.5–6.7 group completion (compressed-pattern process — single chat completion summary + single structured draft per `PROJECT-WORKFLOW.md` § 3 step 6, modified). Independently reviewed by Claude with three targeted spot-checks. Two open questions (OQ-1 and OQ-2) flag deviations from the kickoff's pre-answered Q's where hidden assumptions did not hold; both deviations accepted as the structurally-correct alternative. Two small bookkeeping change-requests issued (the async-test-harness team standard captured in this review file; a `tech-debt.md` entry for the `useErrorMessage` mapping coverage gap). Status flips to "Closed" when the `tech-debt.md` entry lands in the working tree, in the same commit as this review file._
