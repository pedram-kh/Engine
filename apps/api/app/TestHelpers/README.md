# App\TestHelpers — E2E / Playwright support

This module exposes a small set of debug-only HTTP endpoints that the
Playwright suite uses to drive the SPA without depending on real
mailboxes or wall-clock waits. It is heavily gated and **must never be
reachable in any deployed environment**.

The endpoints address two specific E2E pain points:

1. **Verifying user-controlled artefacts that the SPA cannot read.**
   The signup → email-verify flow needs a valid signed token. Reading
   it from Mailhog from a Playwright spec is brittle and slow; minting
   a fresh token via this module is deterministic.

2. **Time-travel without burning real time.** The 24-hour failed-login
   lockout-escalation spec (chunk 6 priority #20) cannot wait 24 hours
   in CI. The clock helpers below pin Carbon::now() to a simulated
   instant via Redis, so a fast-forward is one HTTP request away.

3. **TOTP generation without an authenticator app.** The 2FA
   enrollment spec (chunk 6 priority #19) needs a real 6-digit code
   that matches the user's stored secret. The `/totp` helper goes
   through `TwoFactorService::currentCodeFor()`, which preserves the
   chunk-5 isolation invariant: only `TwoFactorService` ever calls
   `PragmaRX\Google2FA\` directly.

## Routes

All routes live under `/api/v1/_test/*` and require the
`X-Test-Helper-Token` header.

| Method | Path                               | Body / Query                                                  | Returns                                 |
| ------ | ---------------------------------- | ------------------------------------------------------------- | --------------------------------------- |
| GET    | `/api/v1/_test/verification-token` | `?email=jane@example.com`                                     | `{ data: { token, verification_url } }` |
| POST   | `/api/v1/_test/totp`               | `{ "user_id": 123 }` **or** `{ "email": "jane@example.com" }` | `{ data: { code: "123456" } }`          |
| POST   | `/api/v1/_test/clock`              | `{ "at": "2026-05-09T00:00:00Z" }`                            | `{ data: { at: "..." } }`               |
| POST   | `/api/v1/_test/clock/reset`        | (none)                                                        | `{ data: { reset: true } }`             |

The Playwright spec for 2FA enrollment (chunk 6 priority #19) calls
`/_test/totp` with `email` because the SPA never sees the user's
numeric primary key — only the public `ulid`. The `user_id` branch is
preserved for direct-database tests + manual debugging.

A request that fails the gate receives a bare `404` — indistinguishable
from any other unknown route. This is deliberate: the surface should
look like it does not exist when the gate is closed.

## Gating contract (production safety)

The module enforces three layers of defence in depth.

### Layer 1 — Service-provider gate

`App\TestHelpers\TestHelpersServiceProvider::boot()` is a no-op unless
**both**:

- `APP_ENV` is `local` or `testing` (any other value, including
  `staging` and `production`, fails closed), **and**
- `config('test_helpers.token')` (sourced from `TEST_HELPERS_TOKEN`) is
  non-empty.

When either check fails, the module registers no routes and pushes no
middleware. Production runs through this code path on every cold boot
and pays only the autoload cost of the provider class.

### Layer 2 — Per-request route gate

Every test-helpers route is wrapped in
`App\TestHelpers\Http\Middleware\VerifyTestHelperToken`. The middleware:

- Re-checks `TestHelpersServiceProvider::gateOpen()` so a runtime
  config flip closes the surface without a redeploy. (Pest tests rely
  on this: they flip `config('test_helpers.token')` to `''` to assert
  gate-closed behaviour.)
- Compares the inbound `X-Test-Helper-Token` header against the
  configured token using `hash_equals` (constant-time).
- Returns a bare `404` (no body, no error envelope) on any failure,
  so the gate cannot be probed by status codes or response shapes.

### Layer 3 — Global clock middleware gate

`App\TestHelpers\Http\Middleware\ApplyTestClock` is appended to the
global middleware stack at boot time, and only when the same gate is
open. The handler re-checks `gateOpen()` per request, so the same
runtime-flip story applies.

## Token rotation

The token is the only secret the gating relies on. Rotate it with
intent:

- **Local dev.** The default in `.env.example`
  (`local-dev-test-helpers-token-replace-me`) is fine. The host is your
  laptop, and the gate is `APP_ENV=local`. Override per-developer if
  you prefer.

- **Pest suite.** `phpunit.xml` sets a fixed value
  (`phpunit-test-helpers-token`). The Pest suite and the SPA share the
  same database and binary, so a fixed value keeps the suite
  deterministic.

- **CI E2E (Playwright against a real API).** Generate a fresh value
  per run **and inject it into both processes**:

  ```bash
  export TEST_HELPERS_TOKEN=$(openssl rand -hex 32)
  # API container — same env block that boots `php artisan serve`
  docker compose run -e TEST_HELPERS_TOKEN api php artisan serve --host=0.0.0.0
  # Playwright runner — must send the same value as the X-Test-Helper-Token header
  TEST_HELPERS_TOKEN=$TEST_HELPERS_TOKEN pnpm --filter main test:e2e
  ```

  **Never** persist a CI token into source control or hand it to a
  staging/production environment. The Phase-1 expectation is that
  staging and production are unreachable to the test-helpers code at
  all (Layer 1), so a leaked token is harmless there — but the
  discipline of "fresh token per run" is also a habit that compounds
  in later phases when more debug surfaces land.

## Adding a new helper endpoint

1. Add the controller under `app/TestHelpers/Http/Controllers/`.
2. Wire a route in `app/TestHelpers/Routes/api.php` inside the existing
   `VerifyTestHelperToken` group.
3. If the helper needs to reach into a domain module (Identity, Audit,
   etc.), call into that module's published service classes — never
   reach past them. The module-isolation invariants (chunk 5 priority
   #1 for `Google2FA`, the audit trait for `AuditLog` writes, etc.)
   apply here exactly as they do everywhere else.
4. Cover the happy path and the gate-closed path in
   `tests/Feature/TestHelpers/`.
