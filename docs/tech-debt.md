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
