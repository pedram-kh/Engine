# Sprint 1 — Sub-chunk 7.1 Review (close the chunk-6 hotfix saga: throttle neutraliser, in-flight TOTP helper, resolver fix, specs #19 + #20 active)

**Status:** Closed. No change-requests; the work is mergeable as-is.

**Reviewer:** Claude (independent review) — incorporating Cursor's self-review draft.

**Reviewed against:** `PROJECT-WORKFLOW.md` § 5 (all 11 team standards) + § 7 (spot-checks-before-greenlighting) + § 10 (session boundaries), `02-CONVENTIONS.md` § 1 + § 3 + § 4 (esp. § 4.3 coverage thresholds), `04-API-DESIGN.md` § 4 + § 7 + § 8 (error envelope shapes), `05-SECURITY-COMPLIANCE.md` § 6 (throttle + lockout layer design intent), `07-TESTING.md` § 4 + § 4.4 (Playwright + hermetic-test discipline), `20-PHASE-1-SPEC.md` § 5 + § 7 (critical-path E2E priorities #19 + #20), `security/tenancy.md`, `feature-flags.md`, `tech-debt.md` (four entries closed here), all four chunk-6 review files (with particular attention to `sprint-1-chunk-6-8-to-6-9-review.md` post-merge addendum #3 which named the deferred work this sub-chunk closes), and `reviews/sprint-1-chunk-6-plan-approved.md`.

This is the closing artifact for the chunk-6 hotfix saga. After 7.1 lands, both Playwright specs #19 (2FA enrollment) and #20 (failed-login lockout + reset) are active in CI (no `test.skip` on auth flows), the SPA's `useErrorMessage` resolver renders rate-limit errors with a localised message and `{seconds}` value (closing the production UX bug), and four `tech-debt.md` entries are marked closed.

---

## Scope

Cursor's draft enumerates the full scope in three layers + tech-debt closures + a comprehensive file index. The merged review preserves Cursor's draft as the durable record by reference rather than restating. Brief orientation:

- **Layer 1 (backend test-helper extensions):** `RateLimiterNeutralizer` service + `NeutralizeRateLimiterController` (POST/DELETE `/api/v1/_test/rate-limiter/{name}`); `IssueTotpFromSecretController` (POST `/api/v1/_test/totp/secret`); provider-side reapply in `TestHelpersServiceProvider::boot()` for cross-process persistence; shared `$rateLimitResponse` closure in `IdentityServiceProvider::registerRateLimits()` consolidating four near-identical inline builders and adding `meta: { seconds }` per error entry (deviation #2 fix).

- **Layer 2 (frontend resolver + bundles):** `useErrorMessage.isLikelyBundledCode()` accepts `auth.* | validation.* | rate_limit.*`; new `rate_limit.exceeded` entries in en/pt/it bundles; `i18n-auth-codes.spec.ts` architecture test extended to harvest `rate_limit.*` literals from backend PHP source.

- **Layer 3 (Playwright fixtures + specs):** new typed fixtures (`mintTotpFromSecret`, `neutralizeThrottle`, `restoreThrottle`); both specs unskipped; spec #19 redesigned to read manual key from DOM; spec #20 redesigned via option (i) — neutralise `auth-login-email` in `beforeEach`, restore in `afterEach`.

- **Tech-debt closures:** four entries marked closed (spec #19 skip, spec #20 skip, resolver-taxonomy UX bug, chunks 6.5–6.7 mapping coverage gap).

---

## Acceptance criteria — all met

(7 criteria from Cursor's draft acceptance table — all ✅. Reproduced verbatim in Cursor's draft; merged review preserves the same line-by-line verdicts. Verification gates summarized in §"Verification results" below.)

---

## Plan corrections / honest deviation flagging — two items

**Sixth instance** in chunk 6 + 7.1 of Cursor flagging where the kickoff carried a hidden assumption that didn't hold. Precedents: chunk 6.1 (Carbon `tearDown`), chunks 6.2–6.4 (rename target), chunks 6.5–6.7 (401 interceptor architecture + idle redirect path), chunks 6.8–6.9 (App.vue routing + IssueTotpController identifier + cache TTL hermeticity + dashboard URL + i18n interpolation), now sub-chunk 7.1 (in-flight TOTP secret access + missing `meta.seconds` emission).

**Six for six.** The pattern is permanent. Recorded as a standing expectation in PROJECT-WORKFLOW.md.

### Deviation #1 — In-flight TOTP helper takes `secret`, not `email`

**Kickoff said:** `/api/v1/_test/totp/secret` mints a TOTP secret for a user identified by email AND returns the secret AND the current code.

**Hidden assumption:** That the user's pending 2FA secret could be retrieved by email lookup during in-flight enrollment.

**Why it didn't hold:** During in-flight enrollment, the secret lives in cache under `identity:2fa:enroll:{user_id}:{provisional_token}` (per `TwoFactorEnrollmentService::start()` lines 71–75); `users.two_factor_secret` is `NULL` until `confirm()` lands. Retrieval by email would have required (a) driver-specific cache-walking, (b) production-code modification for testability (team-standard violation), or (c) helper-driven enrollment producing a parallel secret that desyncs from the SPA's.

**Alternative taken — accepted:** Helper accepts the secret as input. The SPA already exposes the secret via the existing `enable-totp-manual-key` `data-test` element (rendered as plain text inside a `<code>` block in `EnableTotpPage.vue` so the user can type it into an authenticator app). The redesigned spec reads the same DOM text and forwards it to the helper. Sidesteps cache-walking, doesn't touch production code, preserves the chunk-5 Google2FA isolation invariant.

**Why this is the structurally-correct shape:** "The user's pending secret" is what the spec actually needs, and the SPA is already broadcasting that secret in the DOM. Using the SPA's own state as the source of truth is more honest than building a parallel retrieval path; it also exercises the SPA's display contract as a side effect (if the manual key stops rendering, the spec catches it). Captured as a team standard below.

### Deviation #2 — Backend `meta: { seconds }` was NOT being emitted

**Kickoff said:** The backend's `IdentityServiceProvider::registerRateLimits()` already emits `meta: { seconds: ... }`, so the resolver's meta-forwarding path picks it up unchanged.

**Hidden assumption:** That the existing rate-limit response shape already carried the `meta` field the SPA resolver consumes.

**Why it didn't hold:** The four rate-limiter response callbacks interpolated `seconds` into the `title` field only — there was no `meta` field on the error entry. The SPA's resolver pulls interpolation values exclusively from `details[0].meta`, so without a backend change the bundled string would render with the literal `{seconds}` placeholder unfilled.

**Alternative taken — accepted:** Updated all four limiter response callbacks to include `meta: ['seconds' => ...]` per error entry. Factored the response shape into a single `$rateLimitResponse` closure to enforce the contract uniformly across the four callsites and dedupe previously-near-identical inline builders. Pinned with one Pest case per named limiter in `AuthRateLimitTest.php` — proactively expanded from one to four during spot-check #3 (see process record below).

**Why this is the structurally-correct shape:** The kickoff's stated contract ("the resolver's meta-forwarding path picks it up unchanged") is now actually true. The resolver code didn't need to change; the gap was on the backend, not the frontend. The shared-closure consolidation is a bonus — four near-identical inline response builders becomes one source of truth, with structural enforcement at source level plus behavioral enforcement per-limiter at test level.

### Process record on these two deviations

Both are interpretation-of-the-kickoff issues, not "ambiguous behaviour the kickoff did not cover" issues. My kickoff-writing discipline (desired behavior + invariants over literal code locations) caught some hidden assumptions but not all — the resolver-meta-forwarding contract was embedded as an assumption rather than as an invariant the implementation should satisfy. Note for the next kickoff: explicitly distinguish "this is the contract" from "this is the current implementation we're assuming."

---

## Standout design choices (unprompted)

Cursor's draft enumerates 11 design choices in detail. Three deserve highlighting as broadly applicable patterns:

- **`RateLimiterNeutralizer` service + cache list + provider-side reapply.** The neutralisation surface is a list, not a flag. A single composite cache key holds `list<string>` of currently-neutralised limiter names; `TestHelpersServiceProvider::boot()` reads the list and re-registers each entry with `Limit::none()` via `RateLimiter::for()`. This is the canonical pattern for any future test-helper that overrides Laravel framework primitives — the list shape supports composition (multiple overrides at once) and the cache-backed persistence carries the override across PHP processes. Provider ordering matters: `IdentityServiceProvider` is registered before `TestHelpersServiceProvider` in `bootstrap/providers.php`, so the test-helper's apply-loop overwrites the production callbacks rather than being overwritten by them. Captured as a team standard below.

- **`assertRateLimitMetaSecondsShape` as a per-callsite Pest helper for one shared closure.** The shared `$rateLimitResponse` closure structurally enforces the contract across four callsites; the per-callsite Pest assertions enforce it behaviorally. Both layers are load-bearing — a future refactor that re-inlines responses and forgets `meta.seconds` for any one limiter cannot land without tripping CI. (The unprompted expansion from 1-limiter coverage to 4-limiter coverage during spot-check #3 is the moment this pattern crystallised — see process record below.)

- **Specs read from existing production `data-test` selectors when they need ephemeral provisional state.** Spec #19's redesign reads the manual key from the same DOM element the SPA renders for the user. This is the canonical pattern when a test needs ephemeral state the production UI already surfaces — read the same source the user does, don't build a parallel retrieval path. Future specs that need provisional state (signup confirmation tokens visible in a "check your email" page, password reset tokens visible in a debug-mode banner) follow the same shape. Captured as a team standard below.

---

## Decisions documented for future chunks

- **Test-helper endpoints that override Laravel framework primitives are paired with a provider-side reapply hook.** The cache list carries override state across processes; the provider re-registers from the list at boot. Future overrides (rate limiters, gates, validation rules, mail drivers, queue connections) follow this two-half shape. Provider order in `bootstrap/providers.php` must place the test-helper provider AFTER the provider it overrides.

- **Test-helper endpoints that mutate global state require an explicit `afterEach` restore convention documented in the controller class docblock AND the Playwright fixture docblock AND the consuming spec docblock.** Three places. Belt-and-suspenders against a future spec author who reads only one. Established for `RateLimiterNeutralizer`; precedent for any future stateful test-helper.

- **Prefix-allowlist resolvers (like `useErrorMessage.isLikelyBundledCode`) use dot-suffixed prefixes, never bare-prefix `startsWith`.** `'auth.'` is the right shape; `'auth'` would happily match `authentication_failure`. The negative test case for bare-prefix codes is the regression guard. Future resolvers follow the same shape.

- **Architecture tests for backend-code-to-frontend-resource coverage harvest from the production emit-site, not a hand-maintained list.** The chunks 6.3 i18n-auth-codes test established this; chunk 7.1 extends it with `rate_limit.*`. Any future prefix added to the resolver allowlist requires extending the architecture-test harvest at the same time. (This is the contract that closes the chunks 6.5–6.7 "mapping table coverage" tech-debt entry — see closure note below.)

- **Specs read from existing production `data-test` selectors when they need ephemeral provisional state.** The SPA is the source of truth; parallel retrieval paths drift. Established by spec #19's manual-key read.

- **Opportunistic tech-debt closures require the same scrutiny as substantive change-requests.** The closure must explicitly state how the new mechanism satisfies what the original concern asked for, not just name the closure. Established by 7.1's fourth closure (chunks 6.5–6.7 mapping coverage); the closure note pins the new contract ("any future prefix added to the resolver must extend the architecture-test harvest at the same time") rather than asserting equivalence by hand-wave.

- **Source-inspection regression tests on test-helper controllers stay clean by avoiding library names in docblocks.** Chunk 7.1's `IssueTotpFromSecretController` docblock refers to "the underlying TOTP library" rather than `PragmaRX\Google2FA\` to keep the chunk-5 isolation invariant test green. This is the canonical pattern for any future controller adjacent to chunk-5 isolated components.

- **Disciplined self-correction at spot-check time is now an explicit expected behavior.** Beyond honest deviation flagging at kickoff-interpretation time, Cursor is also catching incomplete coverage at spot-check time and proactively expanding without waiting for a change-request. Two paths to the right answer (reviewer catches it → change-request; implementer catches it → proactive expansion); both work, but the second is faster and now confirmed across multiple instances.

---

## Tech-debt items

**Four entries closed:**

1. **Spec #19 (2FA enrollment) skipped pending in-flight TOTP enrollment helper** → closed via new `IssueTotpFromSecretController` + `mintTotpFromSecret` fixture + in-flight redesign reading manual key from DOM.

2. **Spec #20 (failed-login lockout + reset) skipped pending throttle-vs-lockout-vs-resolver follow-up** → closed via option (i): `RateLimiterNeutralizer` + `neutralizeThrottle` / `restoreThrottle` fixtures + spec narrative preserved (application-level lockout exercised in isolation, same pattern as Pest's `LoginTest::beforeEach`). Pest composition test in `RateLimiterNeutralizerTest` pins that with the throttle neutralised, the 5th wrong-password attempt returns 423 + `auth.account_locked.temporary` — closing the composition-coverage hole that option (ii) was designed to address.

3. **SPA renders generic fallback for rate-limit errors on auth endpoints** → closed via resolver prefix widening (`rate_limit.` added to `isLikelyBundledCode`) + i18n bundle entries in all three locales + the deviation #2 backend `meta.seconds` fix. End-to-end render assertion pins the chain in `SignInPage.spec.ts`.

4. **`useErrorMessage` mapping table is not coverage-checked** (chunks 6.5–6.7 entry) → closed opportunistically. The original concern presumed an explicit mapping table; chunks 6.5–6.7 actually shipped a prefix-allowlist resolver. The chunk 7.1 architecture-test extension covers both `auth.*` and `rate_limit.*` prefixes from the backend source. The closure pins the new contract: any future top-level prefix added to the resolver must extend the architecture-test harvest at the same time. **Reviewer endorsement:** the opportunistic closure satisfies the original concern (drift detection between backend codes and frontend rendering capability) on the merits, not by hand-wave. The new mechanism is structurally equivalent to what an explicit-mapping-table architecture test would have provided, with the additional advantage of not requiring hand-maintained map updates.

**No new tech debt added by sub-chunk 7.1.**

**Pre-existing items from prior chunks remain open** (SQLite-vs-Postgres CI, TOTP issuance does not honor `Carbon::setTestNow()`, `auth.account_locked.temporary` `{minutes}` interpolation gap). None are triggered by 7.1 work.

---

## Verification results

| Gate                                            | Result                                                                             |
| ----------------------------------------------- | ---------------------------------------------------------------------------------- |
| `apps/api` Pint                                 | Pass                                                                               |
| `apps/api` PHPStan (max level via phpstan.neon) | Pass — 215 files, 0 errors                                                         |
| `apps/api` Pest                                 | 352 passed (1052 assertions); +28 net new tests vs. chunks 6.8–6.9 close           |
| `apps/main` typecheck                           | Pass                                                                               |
| `apps/main` lint                                | Pass                                                                               |
| `apps/main` Vitest                              | 234 passed across 26 spec files (+6 from chunks 6.5–6.7 close)                     |
| `apps/admin` typecheck / lint / Vitest          | Pass / Pass / 2 passed                                                             |
| Repo-wide `pnpm -r lint` / `typecheck`          | Clean                                                                              |
| Architecture tests                              | All 7 green; `i18n-auth-codes.spec.ts` extended for `rate_limit.*` harvest         |
| Playwright `pnpm test:e2e`                      | User-runs-locally per chunk-6.8 contract; CI's `e2e-main` job is the durable proof |

---

## Spot-checks performed

1. **Throttle-neutraliser controller + routes** (verification of gating shape symmetry with chunk-6.1 helpers). Reviewed full `NeutralizeRateLimiterController.php` source. Mounts under the existing `_test` route group inside `TestHelpers/Routes/api.php` (lines 63–66); inherits `VerifyTestHelperToken` middleware unchanged; provider-level `gateOpen()` check at registration time; allowlist validation via `ALLOWED_NAMES` at request time. No per-route additional gating — symmetric with the rest of the chunk-6.1 surface. Class docblock spells out the mandatory `afterEach` restore convention with the cross-process / in-process distinction explicitly. The in-process `RateLimiter::for(...)` override on POST is correct for Pest tests; the absence of a re-register on DELETE is also correct (next process boot picks up the production callback fresh; the apply-loop sees the empty list). **Naming note:** Controller landed as `NeutralizeRateLimiterController.php` (verb-first), not my kickoff's `RateLimiterNeutralizerController.php` (noun-first). Verb-first matches the existing chunk-6.1 helpers; my kickoff name was inconsistent with the established pattern. Cursor's call is correct.

2. **`useErrorMessage` resolver + negative-widening Vitest cases** (verification that the prefix allowlist didn't accidentally widen to non-bundled-shaped codes). Reviewed full `useErrorMessage.ts` source. Predicate is `code.startsWith('auth.') || code.startsWith('validation.') || code.startsWith('rate_limit.')` — three dot-suffixed prefixes, no bare-prefix matches. Three negative cases in the spec pin exactly what the kickoff named: `error.500` falls back to `UNKNOWN_ERROR_KEY`; `http.foo` falls back to `UNKNOWN_ERROR_KEY`; `authentication_failure` (bare prefix without a dot) falls back to `UNKNOWN_ERROR_KEY`. Two positive cases pin the new `rate_limit.*` widening (with meta forwarding `seconds`; without meta returns empty values). Docblock enumerates each accepted prefix with its emit-site rationale and explicitly names the conservative posture.

3. **Per-limiter `meta.seconds` Pest coverage** (verification of the shared `$rateLimitResponse` closure across all four named limiters). **Unprompted scope expansion at spot-check time:** Cursor recognised mid-spot-check that the original deviation-#2 Pest case only exercised one of four limiters and proactively extended to all four with a shared `assertRateLimitMetaSecondsShape` helper. Each test triggers its respective limiter from its actual production endpoint and asserts the shared envelope contract. The `auth-ip` test correctly varies email across attempts so the per-email `auth-login-email` limiter doesn't trip first — non-obvious correctness detail handled without prompting. Test counts went 349 → 352 (+3), assertions 1029 → 1052 (+23). Both layers (structural enforcement at source level via shared closure; behavioral enforcement at test level per limiter) are now load-bearing — a future refactor cannot drop `meta.seconds` from any one limiter without tripping CI.

---

## Cross-chunk note

None this round. Confirmed:

- Chunks 6.2–6.4 data-layer invariants intact. The api-client surface is unchanged. The Pinia store gained no fields. The auth.json bundles gained a top-level `rate_limit` namespace as a sibling of `auth` (the file naming becomes a slight misnomer; documented in Cursor's draft as a deferral with the named trigger: when a non-auth limiter adopts `rate_limit.*`, split into `errors.json`).

- Chunks 6.5–6.7 UI invariants intact. `useAuthStore` unchanged; `useErrorMessage` gained one prefix (well-tested). No new architecture tests added; the existing `i18n-auth-codes.spec.ts` was extended (same drift-detection mechanism, wider harvest).

- Chunks 6.8–6.9 deferred work is now resolved. The chunks 6.8–6.9 review file gets a final post-merge addendum (verified in spot-check #5 of Cursor's own self-review) pointing here for closure detail.

- Chunks 1–5 backend invariants intact. The `IdentityServiceProvider::registerRateLimits()` refactor (four inline builders → one shared closure with added `meta.seconds`) preserves the existing behavior on the existing fields and adds the new field. The chunk-5 `TwoFactorService` isolation invariant is preserved — `IssueTotpFromSecretController` routes exclusively through `TwoFactorService::currentCodeFor()`, no new path into `Google2FA`. Source-inspection assertion at the bottom of the test file pins the docblock language ("the underlying TOTP library", not `PragmaRX\Google2FA\`).

- The chunk-6.1 `App\TestHelpers` gating contract is preserved across the two new endpoints. `VerifyTestHelperToken` middleware applies uniformly via the existing `_test` route group; provider-level `gateOpen()` check at registration; bare-404 response on closed gate. Symmetric with `IssueTotpController` / `MintVerificationTokenController` / `SetClockController`.

---

## Process record — compressed pattern (sixth instance)

The compressed pattern continues to work as intended. Six chunk-6 + 7.1 groups, six instances of honest deviation flagging, six clean closures. Specific observations from this round:

- **Q1–Q3 pre-answers with honest deviation flagging:** Two deviations surfaced (`secret` vs `email` input on the TOTP helper; backend `meta.seconds` emission), both interpretation-of-the-kickoff issues, both flagged and resolved in the structurally-correct shape.

- **Single completion artifact at the end:** One chat completion summary, one draft review file. Cursor's draft was thorough enough that my merged review preserves most of it by reference rather than restating.

- **Mid-spot-check disciplined self-correction:** Spot-check #3 response noticed that the original deviation-#2 Pest case only exercised one of four limiters and proactively extended to all four with a shared assertion helper. Beyond honest deviation flagging at kickoff-interpretation time, Cursor is now also catching incomplete coverage at spot-check time and expanding without waiting for a change-request. **New team standard:** disciplined self-correction at spot-check time is an explicit expected behavior, not just a happy accident.

- **Tech-debt closure discipline:** Three explicit closures + one opportunistic. The opportunistic closure (chunks 6.5–6.7 mapping coverage) was scrutinised at merge time: does the new mechanism satisfy what the original concern asked for? Yes. Endorsement on the merits, with the new contract explicitly pinned. New team standard: opportunistic closures get the same scrutiny as substantive change-requests.

- **Verbatim outputs over summaries.** Cursor's spot-check response showed both the failed `cat` command (with the corrected re-run) AND verbatim source/grep outputs. This is the discipline that caught the chunk-6 hotfix scope creep two rounds ago; it's now Cursor's default response shape on spot-check requests. Trust dividend earned.

The compressed pattern carries forward unchanged into sub-chunks 7.2 onward.

---

## What chunk 7.1 closes for Sprint 1

- ✅ All 20 critical-path E2E tests passing for the auth surface (specs #19 + #20 now active in CI, no `test.skip` on auth flows).
- ✅ Sprint 1 acceptance criterion #9 ("All 20 critical-path E2E tests passing") is now met for the auth surface. The remaining 18 specs cover features that don't exist yet (creator onboarding, brand CRUD, campaigns, board, payments) — they're Sprint 2+ work per `20-PHASE-1-SPEC.md` § 7's explicit framing.
- ✅ The production UX bug (any user hitting auth rate-limit sees "Something went wrong") is closed.
- ✅ The chunk-6 hotfix saga is closed: 4 hotfixes + this followup, all documented in the durable record across the chunks 6.8–6.9 and 7.1 review files.

---

_Provenance: drafted by Cursor on sub-chunk 7.1 completion (compressed-pattern process — single chat completion summary + single structured draft per `PROJECT-WORKFLOW.md` § 3 step 6, modified). Independently reviewed by Claude with three targeted spot-checks. Two honest-deviation flags surfaced (in-flight TOTP secret access; backend `meta.seconds` emission), both interpretation-of-the-kickoff issues, both resolved with structurally-correct alternatives. The pattern of "every chunk-6 + 7.1 group catches at least one hidden assumption" is now six-for-six and recorded as a permanent feature of the workflow. Mid-spot-check disciplined self-correction (proactive expansion from 1-limiter to 4-limiter `meta.seconds` Pest coverage) confirmed as a new team standard. Status: Closed. No change-requests; sub-chunk 7.1 lands as-is. Closes the chunk-6 hotfix saga and meets Sprint 1's E2E acceptance criterion for the auth surface._

Post-merge addendum — chunk-7.1 hotfix saga closure
Appended after the merged review's main body. This addendum is the durable record of what surfaced AFTER chunk 7.1's work commit landed on main, written once CI went green on the final hotfix in the series. The chunks 6.8–6.9 review file's addendum #3 covered the first half of the saga (the four hotfixes that closed before sub-chunk 7.1 work started); this addendum covers the second half (the nine hotfixes during and after sub-chunk 7.1's work commit, ending in CI green on commit b715cfa).
Final commit lineage on main
The complete chunk-7.1 post-merge hotfix series, in order, all under [post-chunk-6 hotfix] body anchor:
b715cfa fix(spa-auth): neutralize auth-ip in specs #19/#20 vs retry-cascade saturation
e206352 fix(spa-auth): expand spec #20 step-6 to 5 attempts to actually escalate
ffd7c6f fix(spa-auth): expand spec #20 step-6 to 4 attempts to actually trigger escalation
85e7f30 fix(spa-auth): drop +1m offset from spec #20 step-6 clock to keep T0 failures
7cb73aa fix(spa-auth): shift spec #20 T0 to wall-clock+30d to keep cookies valid
c8d4d27 revert "warm CSRF cookies in spec #20"
1afb13e fix(spa-auth): warm CSRF cookies in spec #20 to avoid cold-context 419
dccab6b fix(spa-auth): make playwright fixtures self-identify as JSON API calls
[earlier post-chunk-6 hotfixes — see chunks 6.8–6.9 addendum #3]
The middle of the series (1afb13e → c8d4d27) is a revert pair preserved deliberately, not squashed. The first commit landed on a wrong hypothesis (cold-cookie state); the trace artifact then surfaced the real root cause (Carbon × Symfony cookie-expiry interaction). The revert + replacement preserves the audit trail of what was thought at the time, consistent with the project's "no force-push, no amend" convention. CI green on commit b715cfa (run 25689460184).
Three independent failure modes compounded into one CI red
The saga's defining shape: what looked like a single failure was three independent bugs stacking, each one masked by the prior. Cursor's diagnosis-then-fix discipline peeled them in order:
Fix layer 1 — signOutViaApi returns HTTP 500 (commit dccab6b). Playwright's APIRequestContext doesn't set Accept: application/json or X-Requested-With: XMLHttpRequest by default. Sanctum's stateful middleware doesn't recognise the request as SPA-shaped, doesn't touch the session cookie, request hits auth:web as unauthenticated, Laravel's exception handler tries to redirect to a named login route, no named login route exists in this API-only app, RouteNotFoundException produces a 500. Fix: shared defaultHeaders constant applied to every API-calling fixture. Tech-debt entry added for the Laravel exception handler gap — still open.
Fix layer 2 — Carbon × Symfony cookie-expiry interaction (commit 7cb73aa, after the revert pair). This is the marquee finding. Carbon::setTestNow(T0) made Laravel compute cookie expires = T0 + session.lifetime. Symfony serialises the Max-Age header by subtracting time() (real wall-clock) from expires and clamping at zero. Once the wall-clock drifted past T0 + 2 hours (session.lifetime default), Max-Age clamped to 0 and the cookie arrived pre-expired. The 419 surfaced in the SPA as the auth.ui.errors.unknown fallback because Laravel's CSRF-mismatch response isn't envelope-shaped — useErrorMessage has nothing to resolve and falls back. Fix: shift T0 to Date.now() + 30 days, guaranteeing Carbon-computed expiries stay future-positive under any reasonable wall-clock drift. Tech-debt entry added — open, with three structural-fix proposals captured for Sprint 2+.
Fix layer 3 — Spec #20 step-6 narrative was unreachable as written (commits 85e7f30, ffd7c6f, e206352). Two compounding effects, both invisible in the spec text:

Step 4's successful sign-in calls failedLogins->clear($email), wiping the steps 2/3 ledger.
The temp-lock precheck in AuthService::login() short-circuits BEFORE recordFailureAndMaybeLock, so each "6-attempt block" only contributes 5 recorded failures (the 6th gets a 423 with no record).
The 24h window therefore enters step 6 with 5 failures, not 12. Spec rewritten to do 5 attempts at step 6 to land on LONG_WINDOW_THRESHOLD exactly. Three commits because the off-by-one was peeled progressively from the trace.

Fix layer 4 — Auth-ip cross-spec saturation (commit b715cfa). The originally-planned commit 2b. Each spec #19 attempt makes ~7 auth-ip hits; three attempts can saturate the 10/min bucket; spec #20 inherits a still-burning bucket. Fix: both specs neutralise auth-ip in beforeEach, restore in afterEach. Symmetric with the chunk-5 LoginTest Pest pattern.
What this saga validates
Artifact-grounded diagnosis beats source-only guessing for runtime bugs. Every correct diagnosis in this saga used a primary source: page snapshots from Playwright traces, response bodies from CI logs, Set-Cookie headers from network artifacts. Every wrong diagnosis was a hypothesis from source reading alone (my "stale Carbon pin" guess on the recovery-codes-display failure; Cursor's "cold cookie state" guess on the 419). The pattern is consistent enough to be a standing process expectation: when CI surfaces a runtime bug, the diagnosis isn't complete until it's grounded in an artifact.
RefreshDatabase-wrapped Pest tests hide pre-migration-state bugs. Hotfix #5 (the RateLimiterNeutralizer::list() cache-backend defence) only surfaced in CI because the composer post-install hook runs php artisan key:generate before migrations. Pest's RefreshDatabase runs against a migrated test database; the path that fails in CI was never exercised by any unit test. The Mockery-based defensive tests added in that hotfix close the gap going forward, but the structural lesson is general: provider boot hooks that touch the database must be tested under "database not yet migrated" conditions explicitly, not just under RefreshDatabase.
No-bundling discipline is load-bearing across multi-layer bug peeling. The chunk-6 saga's earlier rounds (the throttle-vs-lockout-vs-resolver three-layer fix) established this; the chunk-7.1 saga validated it across nine hotfixes. Each commit isolated one concern, which meant each CI cycle gave a clean falsifiability signal: "this layer is fixed, this layer is not." Bundling would have produced "at least one fix is wrong" signals with no way to bisect. The discipline cost extra CI cycles but saved orders of magnitude more debugging time than it cost.
Two-commit discipline (work + bookkeeping) preserves CI falsifiability. Established earlier; held across the saga. Every fix commit's CI result was meaningful and interpretable; every bookkeeping commit on plan-approved.md was inert.
Honest deviation flagging is now a baseline expectation, not a happy accident. The chunk-6 saga produced six instances across six review groups. The chunk-7.1 hotfix series added several more: hotfix #5's Mockery-vs-anonymous-class trade-off named explicitly; hotfix #6's incorrect cold-cookie hypothesis acknowledged in the revert commit; hotfix #7's three-fix off-by-one walked back layer by layer with the test-clock × cookie-expiry root cause named once it surfaced. The pattern carries forward unchanged.
When debugging mechanics become the bottleneck, hand the loop to Cursor and reconnect at closure or scope-decision points. Recorded as a new process pattern. The chunk-7.1 saga's earlier rounds were Claude-job territory (scope creep caught, discipline reinforced). The later rounds were Cursor-job territory (CSRF/cookie/Symfony debugging where fast iteration matters more than outside review). The reviewer (Claude) added value early; the implementer (Cursor) added value late. Reading-and-thinking is the slow loop; running-and-iterating is the fast loop; routing decisions to the right loop is part of the workflow discipline. Future sagas of similar shape should hand off explicitly rather than letting the reviewer's default rigor compound past its useful range.
Open tech-debt items added by the saga
Three new entries, all open, all captured with structural-fix proposals:

"Laravel exception handler returns HTML/redirect for unauthenticated /api/v1/_ requests without Accept: application/json" (added in dccab6b). Resolution sketch: configure the exception handler to always return JSON 401 with the standard envelope shape for /api/v1/_ routes regardless of Accept.
"Test-clock pinning interacts with Laravel cookie expiry to invalidate session/XSRF cookies when wall-clock time moves past T0 + session.lifetime" (added in 7cb73aa). Resolution sketch: three options proposed — (a) setClock-side guard that throws on dangerous baselines, (b) architecture test banning hard-coded Date('YYYY-MM-DD…') literals in setClock calls, (c) shared safeFutureClockBaseline() helper. Pick one in Sprint 2+.
"Vue 3 attribute fall-through can silently override child component root-element data-test attributes" (added in 92064a3, commit 1 of the spec-#19 fix pair). Resolution sketch: architecture test that scans .vue files for parent component invocations with data-test against children whose root template has its own data-test. Worth doing but non-trivial (.vue template parsing); defer to a future architecture-test sprint or chunk 7.2 prep work.

The chunk-6.5 "useErrorMessage mapping table coverage" entry stays closed (per the merged review's tech-debt section). All other prior tech-debt entries (SQLite-vs-Postgres CI, TOTP issuance does not honor Carbon::setTestNow(), auth.account_locked.temporary {minutes} interpolation gap) remain open and untouched by the saga.
Final state for Sprint 1 acceptance

✅ All 20 critical-path E2E tests passing for the auth surface. Specs #19 and #20 active in CI on b715cfa, run 25689460184 green across all four jobs.
✅ Sprint 1 acceptance criterion #9 met for the auth surface. Remaining 18 specs cover features not yet built; Sprint 2+ work per 20-PHASE-1-SPEC.md § 7's explicit framing.
✅ Production UX bug (rate-limit errors rendering as generic fallback) closed in commit b715cfa's predecessor work (the chunk 7.1 work commit).
✅ The chunk-6 hotfix saga is closed. Total: 4 hotfixes pre-chunk-7.1 + sub-chunk 7.1 work + 9 hotfixes during and after sub-chunk 7.1 work = the full saga's durable record across the chunks 6.8–6.9 review's addendum #3 + this addendum.

Status of the chunk-7.1 review itself: closed, post-merge addendum complete, no further follow-ups expected against this review file. Sub-chunks 7.2 onward proceed under the standing compressed-pattern conventions, with the standing-default refinements added during this saga (Cursor silently trims commit headers when Claude's draft exceeds 100 chars; debugging-mechanics loops are Cursor-job territory and don't require Claude in the loop).
