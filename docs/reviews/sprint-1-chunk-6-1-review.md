# Sprint 1 — Chunk 6.1 Review

**Status:** Closed.

**Reviewer:** Claude (independent review) — incorporating Cursor's self-review draft and the resolution round on six change-requests.

**Reviewed against:** `02-CONVENTIONS.md` § 1–§ 2.2, `04-API-DESIGN.md` § 2 + § 4 + § 7 + § 8, `05-SECURITY-COMPLIANCE.md` § 6.1 + § 6.5, `07-TESTING.md` § 2 + § 3, `20-PHASE-1-SPEC.md` § 5 / § 7 (E2E priorities #19, #20), `security/tenancy.md` § 3, `local-dev.md` § 2 (cookie-isolation contract), `PROJECT-WORKFLOW.md` § 5.1 + § 5.10 + § 7 (source-inspection regression test pattern; review-files workflow; spot-checks-before-greenlighting).

## Scope

Chunk 6.1 is the backend half of the chunk-6 SPA auth surface: the read endpoint the SPA consumes on cold load, plus the test-helper module that lets Playwright drive the SPA without depending on Mailhog or wall-clock time.

- **`GET /api/v1/me` and `GET /api/v1/admin/me`** — single `MeController` mounted on both the `web` and `web_admin` guards. Both routes layer `tenancy.set` after the guard so agency_user requests leave the `TenancyContext` populated for Sprint 2+ work; for creators / platform admins the populator is a documented no-op (see `security/tenancy.md`). The admin variant additionally mounts `EnsureMfaForAdmins` per the chunk-5 priority #7 contract — the SPA reads the resulting 403 `auth.mfa.enrollment_required` envelope as the "go enroll" signal.
- **`App\TestHelpers` module** — new top-level module at `apps/api/app/TestHelpers/`. Ships:
  - `TestHelpersServiceProvider` — registers nothing unless `APP_ENV` is `local` or `testing` AND `config('test_helpers.token')` is non-empty. Exposes a single `gateOpen()` predicate that every layer reads.
  - `Services/TestClock` — Redis-backed (cache facade, key `test:clock:current`). Persists / reads / resets a simulated `Carbon::now()` value.
  - `Http/Middleware/VerifyTestHelperToken` — header check via `hash_equals`. Returns a bare 404 (no body, no envelope) when the gate is closed.
  - `Http/Middleware/ApplyTestClock` — globally prepended at boot when the gate is open. Tracker-aware: pins `Carbon::setTestNow()` from the cache when set, releases the pin on the first request after a reset (only if the middleware itself was the source of the pin). See change-request #1 resolution below.
  - Four controllers: `MintVerificationTokenController` (`GET /_test/verification-token?email=...`), `IssueTotpController` (`POST /_test/totp`), `SetClockController` (`POST /_test/clock`), `ResetClockController` (`POST /_test/clock/reset`).
  - `Routes/api.php`, `README.md` — full operator runbook including local-dev vs CI token-rotation guidance.
- **New method on `TwoFactorService`**: `currentCodeFor(string $secret): string`. Pure delegation to `Google2FA::getCurrentOtp()`. Required so `IssueTotpController` can issue a TOTP code without any caller reaching past `TwoFactorService` (chunk-5 priority #1 isolation invariant). The `TwoFactorIsolationTest` source-inspection test still passes — `app/` outside `TwoFactorService.php` contains zero references to `PragmaRX\Google2FA\` or `BaconQrCode\` (verified by spot-check #8).
- **Wiring**:
  - `bootstrap/providers.php` — `TestHelpersServiceProvider::class` listed unconditionally; the provider's own `gateOpen()` check enforces production safety.
  - `apps/api/config/test_helpers.php` — new config file. `token` (sourced from `TEST_HELPERS_TOKEN` env), `clock_cache_key` default.
  - `.env.example` — new `TEST_HELPERS_TOKEN` block with explicit local-dev vs CI rotation guidance and an `openssl rand -hex 32` snippet.
  - `phpunit.xml` — `TEST_HELPERS_TOKEN=phpunit-test-helpers-token` with an inline comment explaining the gate-closed test pattern.
  - `Tests\TestCase::tearDown()` — added `Carbon::setTestNow()` + `ApplyTestClock::resetPinningTracker()` to make the Pest suite hermetic across tests (resolution to change-request #1; see below).

## Acceptance criteria — all met

| #   | Priority                                                                      | Status                                                                                                                                                                                                             |
| --- | ----------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | `GET /api/v1/me` mounted on the `web` guard with `tenancy.set`                | ✅ `Route::get('me', MeController::class)->middleware(['auth:web', 'tenancy.set'])`                                                                                                                                |
| 2   | Same controller reused on the admin SPA                                       | ✅ `Route::get('admin/me', MeController::class)->middleware(['auth:web_admin', EnsureMfaForAdmins::class, 'tenancy.set'])`                                                                                         |
| 3   | Same `UserResource` shape as `/auth/login`                                    | ✅ Single `UserResource::make($user)->response()`; tested by `MeControllerTest::it('returns the authenticated user resource on the main guard')`                                                                   |
| 4   | `tenancy.set` populates context for agency_user, no-op for creator / admin    | ✅ Three dedicated tests in `MeControllerTest` assert each branch                                                                                                                                                  |
| 5   | Cross-guard isolation: a `web` session cannot read `/admin/me` and vice versa | ✅ Two dedicated 401 tests in `MeControllerTest`                                                                                                                                                                   |
| 6   | `/me` is side-effect free (no `last_login_*` stamping, no audit, no event)    | ✅ `MeControllerTest::it('does not stamp last_login_at on /me')`                                                                                                                                                   |
| 7   | Test-helper module gated three ways (env + provider + per-request)            | ✅ `GatingTest` covers each layer: `gateOpen()` returns false in production/staging or empty token; routes 404 on missing/wrong header; provider boot under closed gate registers zero new routes (snapshot)       |
| 8   | Token comparison is constant-time                                             | ✅ `hash_equals` used at the only comparison call site; pinned by `GatingTest::it('uses hash_equals for token comparison (source-inspection regression)')`                                                         |
| 9   | Bare 404 (no envelope) on gate-closed responses                               | ✅ `VerifyTestHelperToken::bareNotFound()` returns `new Response('', 404)`; tested by `GatingTest::it('returns 404 with no body when the X-Test-Helper-Token header is missing')`                                  |
| 10  | Redis-backed test clock; key `test:clock:current`; reset endpoint clears      | ✅ `TestClock` reads `config('test_helpers.clock_cache_key')` (default `test:clock:current`); `ClockTest` covers set / replay / reset / corrupted-value safety / **leak-fix regression (new in resolution round)** |
| 11  | TOTP helper goes through `TwoFactorService` (chunk-5 isolation preserved)     | ✅ `IssueTotpController` calls `TwoFactorService::currentCodeFor()`; isolation grep is clean (spot-check #8); pinned by `IssueTotpTest::it('routes the TOTP call through TwoFactorService …')` source-inspection   |
| 12  | `TEST_HELPERS_TOKEN` rotation guidance documented for CI vs local             | ✅ `.env.example` block + `app/TestHelpers/README.md` § "Token rotation" with explicit CI snippet (`openssl rand -hex 32`)                                                                                         |
| 13  | All chunk-3 / chunk-4 / chunk-5 team standards still apply                    | ✅ No new `Event::fake` mismatches (no events in chunk 6.1); `TwoFactorIsolationTest` and `TwoFactorEdgeCasesTest` still green; bcrypt cost-4 phpunit override untouched                                           |

## Plan correction (two-route shape)

The approved chunk-6 plan (Q1 answer in `sprint-1-chunk-6-plan-approved.md`) said `GET /api/v1/me` should be "mounted on both `auth:web` AND `auth:web_admin` guards". Cursor implemented one controller with two routes (`/api/v1/me` for web, `/api/v1/admin/me` for web_admin) instead of a single route with stacked guards.

**This is the correct call, and the plan as written was technically infeasible.** `local-dev.md` § 2 documents that `UseAdminSessionCookie` is path-aware: it only swaps `config('session.cookie')` to `catalyst_admin_session` on requests under `api/v1/admin/*`. A single `/api/v1/me` route stacked on both guards would silently fail for the admin SPA on cold reload because the admin session cookie's scope wouldn't match the request path — Laravel's `StartSession` would resolve to the main cookie name and find nothing, and the SPA would see a spurious 401.

The two-route, one-controller shape preserves three things at once: cookie isolation (each route under a path the corresponding session cookie covers), controller reuse (no duplication), and the natural seam for `EnsureMfaForAdmins` per chunk-5 priority #7 (only the admin route mounts it). Inline comments on both route declarations document the rationale durably.

**Process learning for future plan-review:** when reviewing future plans that reference multi-guard routes, pre-check against the cookie-isolation contract in `local-dev.md` § 2 before approving. Captured here so a future Cursor session reading this review absorbs the constraint.

## Standout design choices (unprompted)

- **Single controller, two routes — not one route with `auth:web,web_admin`.** Laravel's `Authenticate` middleware accepts a comma-separated guard list, but using it on `/me` would leave the admin SPA's session unreadable for the reason explained in §"Plan correction" above. Two routes pointing at the same `MeController` keeps the cookie-isolation contract intact while still reusing the controller, the resource, and the tests. The decision is documented inline in the routes file.
- **`gateOpen()` as the single source of truth.** Three layers (provider, route middleware, clock middleware) all read the same predicate. Without it the layers would drift — one tweak, three files to remember to update. The Pest suite tests it directly as a static method (5 cases) so the contract is visible without firing an HTTP request.
- **Belt-and-suspenders gating for the global clock middleware.** The provider only pushes `ApplyTestClock` onto the global stack when the gate is open at boot, AND the middleware re-checks `gateOpen()` per request. The latter is what lets `GatingTest` flip `config('test_helpers.token')` to `''` mid-test and assert that the surface immediately closes — without it, "gate-closed" tests would require a full container reboot, which Pest can't do.
- **Bare-404 response on gate failure (no error envelope).** Returning the standard JSON envelope on a 404 would leak that the route exists; an unrecognised route in Laravel returns no body. The middleware mirrors that exactly. Pinned by `GatingTest::it('returns 404 with no body …')`. **Source-inspection regression test** asserts the comparison uses `hash_equals` and not `===` (would reintroduce a timing oracle). New test pattern that fits the team standard from chunk 5 (§ 5.1).
- **`TestClock` is intentionally side-effect-free w.r.t. Carbon; the middleware applies, the service only persists.** Separation of concerns lets the service be unit-tested in isolation (`tests/Unit/TestHelpers/TestClockTest.php`, 5 tests, no HTTP) and keeps the "what does the request see?" property to one file (`ApplyTestClock`).
- **`currentCodeFor()` lives on `TwoFactorService` even though it is test-only.** Adding it under `App\TestHelpers\` directly would have required reaching into `Google2FA`, which would break the chunk-5 isolation invariant test. The method is documented with a "test-helper-only path" warning in the docblock so a future engineer can't accidentally call it from production code on the assumption that it's a generic utility.
- **`MintVerificationTokenController` reuses `EmailVerificationToken::mint()` directly rather than going through `SignUpService::sendVerificationMail()`.** The Playwright spec needs a valid signed token, not an actual email. Skipping the mail dispatch keeps the helper deterministic and side-effect-free; mail tests still go through the production path.
- **Tests cover both the gate-closed and gate-open paths for every controller** (12 tests across three Feature/TestHelpers files), so the production-safety perimeter is part of the regression suite, not an afterthought.
- **Tracker-based Carbon pinning in `ApplyTestClock`** (added during the resolution round). The middleware tracks whether _it itself_ set Carbon's test state, and only releases the pin on a cache-empty request when the tracker is true. Pattern-A tests (cache-driven, via test-helpers endpoints) and Pattern-B tests (direct `Carbon::setTestNow($t)` in test bodies) both work cleanly. See change-request #1 resolution below for the full reasoning. This is a new pattern worth recognising — it's the right shape for any test-helper that wants to interact with global process state without clobbering tests that manipulate that state directly.

## Decisions documented for future chunks

- **`/me` and `/admin/me` are side-effect-free reads.** Future expansion (e.g., `PATCH /api/v1/me` for preferences in chunk 8) MUST go through a separate controller / route. Mutating `/me` would break the SPA's "fire on every cold load" pattern.
- **`tenancy.set` is the canonical middleware for any new authenticated read endpoint** that may eventually carry agency-scoped data. Even when a route is currently consumed only by creators / admins, mounting `tenancy.set` early avoids a multi-route retrofit when an agency_user code path lands.
- **Why `/me` mounts `tenancy.set` but NOT the fail-closed `tenancy` alias.** The standard tenant route stack from `security/tenancy.md` § 3 is `[auth, SetTenancyContext, tenancy]`. `/me` deliberately omits `tenancy` because creator and platform-admin users have no agency context to set; the fail-closed alias would 500 every cold-load `/me` request for those user types. The pattern: apply the standard three-middleware stack only when a route both (a) reads or writes a tenant-scoped model AND (b) is reachable only by users who have an agency context. Otherwise drop `tenancy` and let the model-level `BelongsToAgency` trait's failsafes carry the boundary. Documented inline at the `/me` route declaration.
- **`/admin/<resource>` parallel-mount convention.** Any global-resource endpoint that must be reachable by both the main and admin SPAs needs a parallel `/admin/...` mount because of the cookie-isolation contract — a single multi-guard route cannot work. Documented in this chunk; should be added to `04-API-DESIGN.md` § 2 in chunk 7 (deferred, see Follow-up).
- **`app/TestHelpers/` is a top-level non-domain folder.** Documented in `02-CONVENTIONS.md` § 2.1 (added during the resolution round) alongside `Modules/`, `Core/`, `Http/`, `Console/`, `Exceptions/`, `Providers/`. Test fixtures and infrastructure that's gated to never run in production live here, not under `Core/` (which is for production cross-cutting code) or `Modules/` (which is for domain modules). Future debug surfaces follow the same pattern.
- **Test-helper endpoints live under `/api/v1/_test/` with leading underscore prefix.** Visually distinguishes them from product routes in route lists. The `App\TestHelpers` namespace mirrors the path and stays at the top level (not under `App\Modules\`) so the directory listing makes the non-domain nature obvious.
- **Gate-open predicate must be `app->environment(['local', 'testing']) AND non-empty token`.** Both checks; either alone is insufficient. Future debug surfaces follow the same pattern.
- **Per-request gate must use `hash_equals`** for token comparison. Direct `===` is forbidden by the source-inspection regression test in `GatingTest`. Future debug-token comparisons follow the same rule.
- **Gate-closed responses must be bare 404 (`new Response('', 404)`)** — no error envelope, no body. Mirrors how an unknown route would render. Pinned by test.
- **Persistent debug state (e.g., the test clock) lives in the application cache under a `test:` key prefix** so an operator browsing production Redis would immediately recognise it as a debug knob. The `test_helpers.clock_cache_key` default of `test:clock:current` is the pattern.
- **CI must generate `TEST_HELPERS_TOKEN` per run** and inject the same value into the API container and the Playwright runner. Local dev's hard-coded `.env.example` value is fine because the host is the developer's laptop; staging / production are unreachable to the test-helper code via the env gate. Documented in `app/TestHelpers/README.md` with a copy-paste `openssl rand -hex 32` snippet.
- **TOTP test-helper route MUST go through `TwoFactorService`.** Direct `Google2FA` calls in `App\TestHelpers\` would break `TwoFactorIsolationTest`. Future credential-issuing helpers (e.g., a recovery-code helper if Sprint 9 needs one) follow the same rule.
- **Test-helper middleware that touches global process state MUST track whether it owns the state, and only reset what it owns.** New team standard, established by `ApplyTestClock`'s tracker pattern. The blunt alternative — "always overwrite global state per request" — clobbers tests that manipulate that state directly. The right shape is: track ownership with a private static flag, expose a `resetOwnershipTracker()` static method, wire the tracker reset into `Tests\TestCase::tearDown()` for hermeticity. This is the canonical pattern for any future test-helper that interacts with `Carbon::setTestNow`, `App::setLocale` overrides, the cache fake, or similar global state.

## Change-requests landed in this commit

All six implemented; status flips from "Approved with change-requests" to "Closed".

**1. `ApplyTestClock` Carbon-state-leak fix — landed with shape deviation, accepted.**

- **What was asked:** call `Carbon::setTestNow($this->clock->current())` unconditionally inside the gate-open branch, so a null cache value resets Carbon to real time.
- **What landed:** a private static `$pinnedByModule` tracker on `ApplyTestClock` that flips true when the middleware sets Carbon from cache. On a subsequent request, an empty cache + tracker-true triggers `Carbon::setTestNow()` (with no arg, releasing the pin); empty cache + tracker-false leaves Carbon untouched. A static `ApplyTestClock::resetPinningTracker()` method is exposed for `Tests\TestCase::tearDown()`. The `tearDown()` method now also calls `Carbon::setTestNow()` to make the Pest suite hermetic.
- **Why the deviation is correct:** the literal change-request had a hidden assumption — that `Tests\TestCase::tearDown()` already resets Carbon between Pest tests. It didn't. With the literal fix, every request through the gate-open middleware would call `Carbon::setTestNow($cache->get())` with `$cache->get()` returning null, clobbering tests that pin Carbon directly mid-body (`LoginTest`'s 24-hour-lockout, `AccountLockoutServiceTest`, `FailedLoginTrackerTest`). The tracker-based shape preserves the leak fix for the actual leak case (Playwright spec set→reset across the request boundary in `artisan serve`) without breaking pattern-B tests. Adding the Carbon reset to `tearDown()` makes my original assumption true at the framework level going forward.
- **Process record:** this is the second instance in Sprint 1 of Cursor honestly flagging where Claude's spec didn't match reality and producing a better-shaped fix (precedent: chunk-5 spot-check #3, the constant-time recovery-code claim). PROJECT-WORKFLOW.md § 7 cites that pattern as the spot-checks-before-greenlighting workflow doing what it's meant to do. Worth recognising explicitly.
- **Regression test:** `ClockTest::it('clears the pinned Carbon clock on the next gate-open request after a reset')` — sets a far-past cache, runs middleware (pins Carbon), resets cache, runs middleware again, asserts Carbon is back to real wall-clock time within ~5 seconds tolerance. Test starts with an explicit `resetPinningTracker()` call as belt-and-suspenders against in-process pollution.

**2. `ApplyTestClock` docblock aligned with corrected logic. ✅** Removed the false "kernel terminate hook" claim. New docblock describes the three branches (cache-set-and-pin, cache-empty-and-tracker-true-and-release, cache-empty-and-tracker-false-and-leave) and includes the rationale for tracking module-local pinning state.

**3. TOTP `Carbon::setTestNow()` non-propagation warning. ✅** Added to `TwoFactorService::currentCodeFor()` docblock with explicit `?Carbon $at = null` + `Google2FA::getCurrentOtpAt(...)` resolution pointer. One-liner cross-reference added to `IssueTotpController::__invoke()` docblock.

**4. `/me` route comment extended with fail-closed `tenancy` alias rationale. ✅** Added to the comment block in `apps/api/app/Modules/Identity/Routes/api.php`.

**5. `02-CONVENTIONS.md` § 2.1 documents `apps/api/app/TestHelpers/`. ✅** Now framed explicitly as "test-only, gated", with the three-way gating contract enumerated and a link to `apps/api/app/TestHelpers/README.md`. Directory tree at § 1 also updated to include `TestHelpers/`.

**6. `tech-debt.md` entry for the TOTP-clock limitation. ✅** Appended after the SQLite-CI section, titled "TOTP issuance does not honor `Carbon::setTestNow()`", matching the SQLite-CI format (Where / What we accepted / Risk / Mitigation today / Triggered by / Resolution / Owner / Status).

## Follow-up items

### For Sprint 1 chunks 6.5–6.7 (router + pages + 2FA UI)

- The `useAuthStore.fetchMe()` action will hit `GET /api/v1/me` on cold load. The store is the only consumer; chunk 6.5 should add a `requireAuth` router guard that fires `fetchMe()` and waits on it before the SPA renders any authenticated route. Empty / loading / error states for the bootstrap call (per chunk 6 priority #8) are owned by the route guard, not by individual pages.

### For Sprint 1 chunk 6.8 (Playwright E2E)

- Spec #19 will use `POST /api/v1/_test/totp { user_id }` to mint codes during the 2FA enrollment flow. The user_id can be looked up via `GET /api/v1/me` after the SPA's sign-in step, so the spec doesn't need direct DB access.
- Spec #20 will use `POST /api/v1/_test/clock { at: "..." }` to fast-forward across the 24-hour failed-login window. The spec must call `POST /api/v1/_test/clock/reset` in `afterEach` so a stray clock from a failed test does not bleed into the next. **The set→reset transition is now correctness-tested at the middleware level (`ClockTest` regression)**, so a misbehaving spec can't paper over a middleware regression.
- The Playwright runner config must read `TEST_HELPERS_TOKEN` from env and forward it as the `X-Test-Helper-Token` header on every helper request. CI must generate a fresh token per run; chunk 6.8 should add the CI workflow snippet alongside the spec.

### For chunk 7 (admin SPA auth UI)

- `/admin/me` already returns 403 `auth.mfa.enrollment_required` for an unenrolled admin. Chunk 7's auth store should treat that 403 differently from a 401: a 401 means "not signed in, redirect to /sign-in"; a 403 with `auth.mfa.enrollment_required` means "signed in but blocked, redirect to /auth/2fa/enable" (the session cookie is still valid).
- The admin SPA reuses `MeController`. No backend work needed in chunk 7 unless an admin-only field needs to land on `UserResource`.
- **`04-API-DESIGN.md` § 2 needs `/api/v1/admin/me` enumerated** under the admin endpoints list, alongside the convention statement: "Any global-resource endpoint reachable by the admin SPA gets a parallel `/admin/<resource>` mount due to the cookie-isolation contract documented in `local-dev.md` § 2." Deferred to chunk 7 because chunk 7 is when the admin SPA actually starts consuming `/admin/me`; landing the doc edit then keeps the doc and the consumer in lock-step.

### For Sprint 8 (Postgres-CI / Redis-hardening pass)

- The `test:clock:current` cache key uses the Laravel cache facade; in tests it lands on the `array` driver, in real environments on Redis. No schema work needed. Append to the existing `docs/tech-debt.md` Sprint 8 entry as a note that test-helpers are array-driver-only in CI by virtue of phpunit's `CACHE_DRIVER=array` override.

## What was deferred (with triggers)

- **Carbon-aware TOTP issuance.** `Google2FA::getCurrentOtp()` calls PHP's `time()` directly, which `Carbon::setTestNow()` does not influence. Spec #19 doesn't require time-travelled TOTP, so this is fine for chunk 6. Trigger: any future spec that combines clock-skip with TOTP issuance. Resolution: extend `TwoFactorService::currentCodeFor()` with an optional `?Carbon $at = null` parameter and route through `Google2FA::getCurrentOtpAt(...)`. **Recorded as an active entry in `docs/tech-debt.md`** (per change-request #6) — the review file captures the rationale; tech-debt.md is the active register that gets read at sprint kickoff.
- **`PATCH /api/v1/me` for preferences.** Chunk 8.
- **Admin-side `EnsureMfaForAdmins` 403 → SPA redirect to `/auth/2fa/enable`.** Chunk 7.
- **Source-inspection test asserting that `App\TestHelpers\` is the only namespace allowed under the `_test` route prefix.** Currently informal; can be formalised when a second debug surface lands. Sprint 8+.
- **CI workflow snippet for `TEST_HELPERS_TOKEN` rotation.** Currently documented narratively in `app/TestHelpers/README.md`. The actual CI config lands with chunk 6.8 when Playwright is wired.
- **`04-API-DESIGN.md` § 2 update for `/admin/me` and the parallel-mount convention.** Chunk 7.

## Verification results

| Gate                                       | Result                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend tests                              | 323 passed (964 assertions) — 47 new tests across `MeControllerTest` (12), `GatingTest` (12), `MintVerificationTokenTest` (6), `ClockTest` (8 — original 7 + new Carbon-reset regression), `IssueTotpTest` (6), `TestClockTest` (5)                                                                                                                                                                                                                     |
| `php vendor/bin/pint --test`               | passed                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| `php vendor/bin/phpstan analyse` (level 8) | no errors                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| Coverage on new code                       | `MeController` 100%; `App\TestHelpers\Http\Controllers\*` 100% on every controller; `App\TestHelpers\Http\Middleware\*` 100% on both middleware (including the tracker branches); `App\TestHelpers\Services\TestClock` 100%; `App\TestHelpers\Routes\api` 100%; `TestHelpersServiceProvider` 95.0% — single uncovered line is the `routesAreCached()` defensive return, identical to `IdentityServiceProvider`'s pre-existing 98.6% branch from chunk 1 |
| Critical chunk-6.1 tests                   | All green; chunk-5 `TwoFactorIsolationTest` (2) still green; LoginTest's 24-hour-lockout, `AccountLockoutServiceTest`, `FailedLoginTrackerTest` still green (these were the tests at risk under the literal change-request #1 — the tracker shape preserves them)                                                                                                                                                                                       |

## Spot-checks performed

1. **`MeController.php`** — confirmed minimal, side-effect-free, single shared controller with no admin/non-admin branching. Larastan-friendly `@var` annotation present. Detailed docblock documents the two-route layering and the side-effect-free contract (no `last_login_*` stamping, no events, no audit).

2. **Both route registrations (`api.php` lines 40–60, 110–135)** — confirmed middleware sequence on each: `/api/v1/me` mounts `[auth:web, tenancy.set]`; `/api/v1/admin/me` mounts `[auth:web_admin, EnsureMfaForAdmins, tenancy.set]`. Inline comments on both declarations explain the cookie-flip rationale and the `EnsureMfaForAdmins` layering. Middleware order on the admin route is correct (auth → mfa-check → tenancy populator). The `/me` comment was extended in the resolution round per change-request #4 to document the fail-closed `tenancy` alias omission.

3. **`TestHelpersServiceProvider.php`** — confirmed `gateOpen()` is a public static predicate on the provider class, reads both `app.env` (must be `local` or `testing`) and `test_helpers.token` (must be non-empty), and is the single source of truth for all three gating layers. Boot logic conditionally registers routes and prepends the clock middleware only when the gate is open. `routesAreCached()` defensive return present (mirrors `IdentityServiceProvider`).

4. **`VerifyTestHelperToken.php`** — confirmed `hash_equals` for token comparison; bare 404 with empty body and no envelope on any failure (gate closed, missing header, wrong header); per-request `gateOpen()` re-check belt-and-suspenders; empty-string short-circuit before `hash_equals` (would otherwise compare `''` to `''` and return true).

5. **`ApplyTestClock.php`** — initial spot-check found the Carbon-state-leak bug; resolution round implemented the tracker-based fix (deviation from the literal change-request, accepted as the correct shape). Final state: gate-open requests pin Carbon when cache is set (tracker→true); cache-empty + tracker-true releases the pin via `Carbon::setTestNow()` with no arg; cache-empty + tracker-false leaves Carbon untouched (so direct `Carbon::setTestNow($t)` calls in test bodies survive middleware passes). Docblock rewritten to describe the three branches accurately (change-request #2). New `ClockTest` regression case (lines 115–151) verifies the set→reset transition end-to-end.

6. **`TestClock.php`** — confirmed `forever`-write semantics on `set()`, `forget` on `reset()`, defensive `Carbon::parse` try/catch on `current()` (corrupted values return null silently rather than crashing every request). Cache key sourced from config with `test:clock:current` default. ISO 8601 round-trip via `toIso8601String()` — second-precision is sufficient for the lockout-window time-travel use case.

7. **`TwoFactorService.php` diff** — confirmed only the new `currentCodeFor()` method was added; existing `verifyTotp()` and recovery-code methods untouched. Method delegates to `$this->google2fa->getCurrentOtp($secret)` (no new instantiation). `#[SensitiveParameter]` attribute applied for stack-trace redaction. Docblock now includes the Carbon non-propagation warning (change-request #3) with explicit `?Carbon $at = null` + `Google2FA::getCurrentOtpAt(...)` resolution pointer. `IssueTotpController::__invoke()` carries a one-liner cross-reference.

8. **Isolation grep** — `grep -RnE 'Google2FA|PragmaRX' app/ --include='*.php' | grep -v 'Modules/Identity/Services/TwoFactorService.php'` returns "isolation invariant: clean". Chunk-5 standard 5.1 (source-inspection regression test pattern) preserved.

9. **`.env.example` and `phpunit.xml` diffs** — confirmed distinct token values (local-dev hard-coded, phpunit `phpunit-test-helpers-token`); rotation guidance with `openssl rand -hex 32` snippet present in `.env.example`; explicit "Never check a CI value into source control or ship a known token to any deployed environment" warning. `phpunit.xml` token has an inline comment explaining the runtime config-flip pattern that gate-closed tests use.

## Cross-chunk note

None this round. Confirmed:

- The chunk-5 isolation invariant test (`TwoFactorIsolationTest`) still passes against the new `currentCodeFor()` method (spot-check #8: grep is clean).
- The chunk-3 `tenancy.set` middleware semantics carry forward correctly (spot-checks #1, #2: route stack matches `security/tenancy.md` § 3 modulo the documented `tenancy` alias omission, which is the correct call for `/me` and is now codified in the "Decisions for future chunks" section).
- The chunk-3 lockout escalation (`LoginTest`'s 24-hour-lockout, `AccountLockoutServiceTest`, `FailedLoginTrackerTest`) still passes after the resolution round — the tracker-based shape of the `ApplyTestClock` fix specifically preserves these pattern-B tests that pin Carbon directly in their bodies.
- `Tests\TestCase::tearDown()` now resets Carbon and the `ApplyTestClock` pinning tracker between Pest tests — incremental hermeticity improvement that closes the assumption gap surfaced during the resolution round.
- No latent bugs from earlier chunks discovered.

---

_Provenance: drafted by Cursor on Sprint 1 chunk 6.1 completion (chat completion summary + structured draft per `PROJECT-WORKFLOW.md` § 3 step 6). Independently reviewed by Claude with nine spot-checks and two findings beyond Cursor's draft (the `ApplyTestClock` Carbon-reset bug and its aspirational docblock). Six change-requests issued; resolution round completed by Cursor with one principled deviation on #1 (tracker-based shape instead of literal unconditional `setTestNow`), accepted by Claude as the correct shape — recorded as a process precedent (second instance after chunk-5 spot-check #3 of the spot-checks-before-greenlighting workflow catching a hidden assumption in Claude's spec). Merged final by Claude per `PROJECT-WORKFLOW.md` § 3 step 8. Status: Closed._
