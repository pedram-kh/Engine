# Sprint 1 — Chunk 3 Review

**Status:** Closed
**Reviewer:** Claude
**Reviewed against:** `02-CONVENTIONS.md`, `04-API-DESIGN.md`, `05-SECURITY-COMPLIANCE.md`, `07-TESTING.md`, `00-MASTER-ARCHITECTURE.md` § 7

## Scope

Sanctum SPA wiring + login/logout + password reset + HIBP + lockout + rate limits:

- Sanctum stateful domains and CORS for local + staging/prod
- Two separate guards (`web`, `web_admin`) with admin-specific session lifetimes
- `UseAdminSessionCookie` middleware: cookie name flips for `api/v1/admin/*` paths
- `POST /api/v1/auth/login` with Argon2id rehash, MFA gate, lockout
- `POST /api/v1/auth/logout` (session invalidation)
- `POST /api/v1/auth/forgot-password` (silent 204 on unknown email)
- `POST /api/v1/auth/reset-password` (signed token, invalidates all sessions, clears failed-login counter)
- `App\Core\Security\PwnedPasswordsClient` + `NotBreachedPassword` rule (HIBP k-anonymity)
- `LoginThrottleService` + Redis-backed counters (per-IP, per-email, per-account escalation to suspension)
- `App\Core\Tenancy\SetTenancyContext` middleware populating context for agency-scoped routes
- Standardized error envelope via `App\Core\Errors\ErrorResponse`
- All auth events emit audit entries through chunk 2's `AuditLogger`

## Acceptance criteria — all met

- ✅ Argon2id with `Hash::needsRehash()` on login (parameters from `05 § 6.1`)
- ✅ Two-SPA cookie isolation in local dev (`catalyst_main_session` / `catalyst_admin_session`)
- ✅ `auth.mfa_required` is a real check on `users.two_factor_confirmed_at IS NOT NULL`
- ✅ 24-hour lockout escalation: 11 failures within 24h flip `is_suspended=true`, set `suspended_reason`, emit `auth.account_locked` audit event
- ✅ `SetTenancyContext` middleware on `auth:web` / `auth:web_admin` route groups (paired with `EnsureTenancyContext` from chunk 1)
- ✅ HIBP k-anonymity: only 5-char SHA-1 prefix sent; never the full hash, never the password
- ✅ Rate limiters: `auth-ip` (10/min/IP), `auth-login-email` (5/min/email+IP), `auth-password` (5/min/IP)
- ✅ Forgot-password returns 204 silently for unknown emails (user-enumeration defense)
- ✅ Password reset invalidates all sessions and clears failed-login counter on completion
- ✅ Localized reset mail (en/pt/it)
- ✅ Standardized error envelope via `ErrorResponse`
- ✅ Identity module at 100% line coverage except one defensive line in `IdentityServiceProvider::registerRoutes()` (`routesAreCached()` short-circuit)

## Standout design choices (unprompted)

- **`needsRehash()` test bumps Argon memory mid-test** to prove the rehash path actually fires — not just the naive "stored hash is Argon2id" assertion that would pass either way.
- **Cookie middleware prepended _before_ `StartSession`** — correct ordering; cookie name has to be set before the session reads from it.
- **Forgot-password silent 204 for unknown emails** — OWASP-recommended user-enumeration defense; not in the priority list but the right call.
- **`AccountLockoutService::escalate()` idempotency test** — re-running on an already-suspended user is a no-op (no duplicate audit, no overwritten `suspended_at`). Catches retry/replay bugs.
- **HIBP `Add-Padding: true` header** — HIBP-specific best practice to defeat traffic analysis. Cursor read the HIBP docs.
- **HIBP `User-Agent` header includes contact** — HIBP best practice; Troy Hunt's API has been known to deprioritize unidentified clients.
- **Bcrypt config block retained with explanatory comment** — Sanctum's hashed-token paths use bcrypt internally; removing it would have broken things in unexpected places. Cursor explicitly retained and labeled it.
- **`SetTenancyContext` includes Phase 2 forward-pointer** — comment notes that when multi-agency support is added in P2, the middleware will read the active workspace from a session attribute, but the contract for callers stays the same.
- **`SetTenancyContext` paired with `EnsureTenancyContext`** explicitly documented in the class docblock so the security model is legible in code, not just in `docs/security/tenancy.md`.

## Decisions documented for future chunks

- Login flow returns `LoginResult` value object (success / mfa-required / locked / invalid). Future chunks add MFA verification (chunk 5) without restructuring this.
- The `tenancy.set` middleware alias is the standard wire-up for tenant-scoped routes. Future agency-scoped controllers go behind `['auth:web', 'tenancy.set', 'tenancy']`.
- `ErrorResponse` is the canonical error envelope. All future controllers return errors through it, not via raw `response()->json()`.
- Failed-login counter resets on successful login OR successful password reset.
- HIBP fail-open policy: third-party outages don't block signups. Documented in `05-SECURITY-COMPLIANCE.md § 6.1` and replicated in the client's docblock.

## Follow-up items

### For Sprint 1 chunk 8 (or whichever sprint wires Sentry fully)

The HIBP `Log::warning('hibp.upstream_failure', ...)` and `Log::warning('hibp.upstream_exception', ...)` calls are correctly logged but currently rely on the standard Laravel logger. When Sentry-Laravel is fully wired (Sentry SDK is in Sprint 0, full wiring is later), verify these `warning`-level logs propagate to Sentry as breadcrumbs or issues so HIBP outages are observable to operators. Without monitoring, a multi-day HIBP outage could pass through 100+ breached passwords silently.

**Trigger:** Sprint that fully wires Sentry-Laravel (likely chunk 8 i18n cleanup or a Sprint 2+ observability pass).
**Action when triggered:** Verify `Log::warning('hibp.*', ...)` calls reach Sentry. Add an alert rule for `hibp.upstream_failure` rate > 5/min.

## What was deferred (with triggers)

- Full MFA verification flow (sending codes, validating TOTP) — chunk 5
- Admin SPA-specific login UI — chunk 7
- HIBP outage monitoring (Sentry alert rule) — Sentry full-wiring sprint
- Multi-agency user support (`SetTenancyContext` reading active workspace from session) — Phase 2

## Verification results

| Gate                                       | Result                                                                                                                                                                                                                                     |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Backend tests                              | 156 passed                                                                                                                                                                                                                                 |
| Frontend smoke tests                       | 2 passed (apps/admin + apps/main Vitest)                                                                                                                                                                                                   |
| `php vendor/bin/pint`                      | clean                                                                                                                                                                                                                                      |
| `php vendor/bin/phpstan analyse` (level 8) | no errors                                                                                                                                                                                                                                  |
| Identity module coverage                   | 100% (except 1 defensive line in `IdentityServiceProvider::registerRoutes()` `routesAreCached()` short-circuit)                                                                                                                            |
| Critical-path tests                        | `LoginTest` (Argon rehash, MFA gate, 24h escalation, post-suspension reject), `AuthRateLimitTest`, `TwoSpaCookieIsolationTest`, `SetTenancyContextTest`, `PwnedPasswordsClientTest` (5-char prefix only, never plaintext, never full hash) |

## Spot-checks performed

1. ✅ `config/hashing.php` driver Argon2id with parameters; `.env.example` removed BCRYPT*ROUNDS, added HASH_DRIVER + HASH_ARGON*\*
2. ✅ `AuthService::login()` real check on `$user->hasTwoFactorEnabled()`; `LoginFailed` event includes `mfa_required` reason for audit trail
3. ✅ `SetTenancyContext` correct behavior matrix; lowest-id membership fallback documented; Phase 2 forward-pointer in comments
4. ✅ HIBP local SHA-1, 5-char prefix, suffix matched locally; `Add-Padding: true` header; identifying `User-Agent`
5. ✅ Fail-open with `Log::warning()` for both 5xx and exception paths; trade-off documented inline and references security spec
