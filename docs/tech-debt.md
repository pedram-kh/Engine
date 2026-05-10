# Catalyst Engine — Tech Debt Register

A living list of conscious shortcuts and deferred decisions. Each entry names the
area, the cost we are accepting now, the trigger that escalates the work, and the
sprint by which it must be resolved.

The aim is to make every shortcut visible: nothing in this file should surprise
anyone reviewing it later.

---

## Test suite runs against SQLite in-memory by default

- **Where:** [`apps/api/phpunit.xml`](../apps/api/phpunit.xml) — `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`.
- **What we accepted in Sprint 1:** the Pest suite spins up an SQLite in-memory database for every test run. This is fast, hermetic, and requires no docker dependency from CI. Production runs PostgreSQL 16 (per [`docs/00-MASTER-ARCHITECTURE.md`](00-MASTER-ARCHITECTURE.md) §3).
- **Risk:** subtle SQL bugs invisible in SQLite — `jsonb` operators, full-text search, GIN indexes, window-function variants, `INSERT ... ON CONFLICT` quirks, partial indexes, `NULLS FIRST/LAST` ordering. Code that exercises these features can pass tests under SQLite and fail in production.
- **Mitigation today:** Sprint 1 migrations and models stick to driver-agnostic Laravel column types (`json()`, `ipAddress()`, `ulid()`, `char()`) and avoid raw Postgres SQL. The full migration set is also exercised against the live local Postgres on every chunk closure (see chunk-1 verification log).
- **Triggered by:** any sprint that adds Postgres-specific operators (`@>`, `?`, `?|`, `to_tsvector`, `similarity()`), index types (`gin`, `gist`, `brin`), partial indexes, or generated columns.
- **Resolution required by Sprint 8** at the latest. CI must add a Postgres-targeted test job that runs the same suite against a Postgres 16 service container; both jobs become required status checks. Until then, tests that rely on Postgres-specific behaviour must `markTestSkipped()` under SQLite and provide a Postgres-only counterpart. Sprint 8 Postgres-CI also upgrades `audit_logs.ip` `varchar(45)` → `inet` and any `json` → `jsonb` columns non-destructively (expand/migrate/contract per [`docs/08-DATABASE-EVOLUTION.md`](08-DATABASE-EVOLUTION.md)), since audit data will exist by then.
- **Owner:** Sprint that first introduces Postgres-specific SQL.
- **Status:** open.

---

## TOTP issuance does not honor `Carbon::setTestNow()`

- **Where:** [`apps/api/app/Modules/Identity/Services/TwoFactorService.php`](../apps/api/app/Modules/Identity/Services/TwoFactorService.php) (`currentCodeFor()`) and [`apps/api/app/TestHelpers/Http/Controllers/IssueTotpController.php`](../apps/api/app/TestHelpers/Http/Controllers/IssueTotpController.php).
- **What we accepted in Sprint 1 chunk 6.1:** the test-helper TOTP endpoint (`POST /api/v1/_test/totp`) calls `TwoFactorService::currentCodeFor()`, which delegates to `PragmaRX\Google2FA\Google2FA::getCurrentOtp()`. That library reads PHP's `time()` directly and does NOT honor `Carbon::setTestNow()`. The chunk-6 Playwright specs (#19 enrollment, #20 lockout) do not need to combine clock-skip with TOTP issuance, so a real-time-derived code is sufficient today.
- **Risk:** a future spec that combines `Carbon::setTestNow()` time-travel with TOTP issuance silently gets codes derived from real wall-clock time. Silent because the test passes by coincidence — TOTP windows are 30 seconds wide and Carbon-pinned tests usually run fast enough that the simulated and real clocks happen to share the same window. Drift across a 30-day fast-forward would no longer share a window and the test would flip to a confusing "TOTP rejected" failure with no obvious cause.
- **Mitigation today:** the limitation is documented as a `WARNING` in the docblock of `TwoFactorService::currentCodeFor()` and cross-referenced from the `IssueTotpController` docblock, so a future engineer who needs combined clock-skip + TOTP behaviour finds the limitation before they spend an afternoon debugging silent drift.
- **Triggered by:** any future spec that needs both — e.g., a spec that fast-forwards 30+ days via `POST /api/v1/_test/clock` and then enrolls a new TOTP factor, or any sprint that exercises long-window TOTP scenarios (replay attempts across a stale window, drift correction).
- **Resolution:** extend `TwoFactorService::currentCodeFor()` with an optional `?Carbon $at = null` parameter and route through `Google2FA::getCurrentOtpAt($timestamp)` when provided. The test-clock cache value (`config('test_helpers.clock_cache_key')`, default `test:clock:current`) is the canonical source for `$at` in the test-helper controller — `IssueTotpController` would read it via `TestClock::current()` and pass it through, so the same Redis-backed clock that drives `Carbon::setTestNow()` also drives `Google2FA`.
- **Owner:** the sprint that introduces a spec needing combined clock-skip + TOTP. Likely Sprint 9 (session-management UI) or Sprint 13+ (security pipeline) but not earlier.
- **Status:** open.

---

## `useErrorMessage` mapping table is not coverage-checked against the backend code registry

- **Where:** [`apps/main/src/modules/auth/composables/useErrorMessage.ts`](../apps/main/src/modules/auth/composables/useErrorMessage.ts) — the mapping table from `ApiError.code` → i18n key + interpolation values.
- **What we accepted in Sprint 1 chunks 6.5–6.7:** `useErrorMessage` maintains a finite explicit map covering the auth error codes the UI currently renders. New backend `auth.*` codes added in future chunks need a manual line in the map to render with their intended interpolation values; without a line, they fall through to `auth.ui.errors.unknown`.
- **Risk:** a future backend error code lands with an i18n entry (the chunks 6.3 architecture test ensures that), but renders as "An unexpected error occurred" because `useErrorMessage` doesn't know about it. The user-facing impact is a less-helpful error message; not a security or data risk.
- **Mitigation today:** the chunks 6.3 architecture test [`i18n-auth-codes.spec.ts`](../apps/main/tests/unit/architecture/i18n-auth-codes.spec.ts) ensures every backend code has an i18n entry, so the missing case is "code exists in bundle, code missing from `useErrorMessage` map" — a degraded UX, not a crash.
- **Triggered by:** the next chunk that adds a new `auth.*` error code AND surfaces it through the UI.
- **Resolution:** add a new architecture test that walks `useErrorMessage`'s mapping table, walks the harvested backend codes from the chunks 6.3 source-inspection, and asserts every UI-renderable code has an explicit mapping (or a documented fall-through). The set of "UI-renderable" codes is the subset of backend codes that any auth page consumes.
- **Owner:** the sprint that introduces the new auth error code.
- **Status:** open.

---

## `auth.account_locked.temporary` i18n bundle has no `{minutes}` interpolation

- **Where:** [`apps/main/src/core/i18n/locales/{en,pt,it}/auth.json`](../apps/main/src/core/i18n/locales/) — the `auth.account_locked.temporary` bundle entry.
- **What we accepted in Sprint 1 chunks 6.8–6.9:** The bundle entry is `"Too many failed sign-in attempts. Please try again in a few minutes."` — generic phrasing, no `{minutes}` placeholder. The backend response carries `meta.retry_after_minutes` on the `AuthErrorResource`, and `useErrorMessage` already forwards `details[0].meta` as the interpolation bag, so the data path is open — only the bundle entry needs a placeholder to consume the value.
- **Risk:** Users see "in a few minutes" instead of the actual minutes remaining. Materially less helpful when the lockout has 14 minutes remaining vs 30 seconds remaining; both render the same string.
- **Mitigation today:** None. The generic phrasing is correct, just imprecise.
- **Triggered by:** A UX-focused chunk that improves auth error messages, OR a user complaint about not knowing how long to wait.
- **Resolution:** Add `{minutes}` placeholder to all three locale bundle entries. Update spec `failed-login-lockout-and-reset.spec.ts`'s substring assertion to accommodate the new shape (still matches `'failed sign-in'` as a substring; no full-string assertion needed).
- **Owner:** The sprint that introduces the UX improvement.
- **Status:** open.

---

## Spec #19 (2FA enrollment) skipped pending in-flight TOTP enrollment helper

- **Where:** [`apps/main/playwright/specs/2fa-enrollment-and-sign-in.spec.ts`](../apps/main/playwright/specs/2fa-enrollment-and-sign-in.spec.ts) — `test.skip(...)` on the sole `full enrollment + re-sign-in flow` test, marked with the `TODO(spec-19-skip)` anchor.
- **What we accepted in Sprint 1 chunk 6 hotfix #3:** Spec #19 is muted in CI. The spec drives the SPA's two-step 2FA enrollment (`TwoFactorEnrollmentService::start()` → `confirm()`) and needs a TOTP code minted from the in-flight enrollment secret — the secret that lives in the cache (key prefix `identity:2fa:enroll:`) during enrollment, NOT the persisted `users.two_factor_secret` column (which is NULL until `confirm()` lands successfully). The chunk-6.8 `mintTotpCodeForEmail` fixture only services the post-enrollment case, so the spec 422s at the helper step. The chunk-6.8 spec design assumed a helper that didn't yet exist.
- **Risk:** The only end-to-end test for the 2FA enrollment flow is muted. Regressions in `EnableTotpPage`, `TwoFactorEnrollmentService::confirm()`, the recovery-codes UI countdown, or the `requireMfaEnrolled` router guard's enrollment-rebound behaviour can land without surfacing in CI until the spec is restored. Spec #20 (failed-login lockout) remains active and exercises a different slice of the auth surface.
- **Mitigation today:** Vitest unit specs + Pest feature specs cover the underlying behaviour at the component + service + controller level (`EnableTotpPage.spec.ts`, `RecoveryCodesDisplay.spec.ts`, `TwoFactorEnrollmentService` Pest tests, `IssueTotpController` Pest tests, `requireMfaEnrolled` guard architecture test). The 2FA path is exercised; what's missing is the cross-layer integration check that Playwright provides.
- **Triggered by:** the next chunk that touches `EnableTotpPage`, `TwoFactorEnrollmentService`, `IssueTotpController`, the recovery-codes UI, or the `requireMfaEnrolled` guard in a way that could break the cross-layer enrollment flow. Also triggered by the Sprint 2 kickoff if the spec is still skipped at that point — restoring it should be sequenced into Sprint 2 planning rather than carried indefinitely.
- **Resolution:** Follow-up review round designs an in-flight TOTP enrollment helper. The chunks 6.8–6.9 review's post-merge addendum #3 captures the discovery context. The same review round should also reconsider chunk-6.8 OQ-3 (whether `CACHE_STORE=array` was technically a correct choice given `php artisan serve`'s per-request PHP process model — separate technical question, related context). Once the helper lands, flip `test.skip` → `test` and remove the `TODO(spec-19-skip)` anchor.
- **Owner:** the sprint that introduces a chunk hitting one of the trigger conditions, OR an explicit "restore spec #19" sub-chunk in Sprint 2 if no triggering chunk has landed by then.
- **Status:** open.
