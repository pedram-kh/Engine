# Sprint 1 — Chunk 5 Review

**Status:** Closed
**Reviewer:** Claude (independent review) — incorporating implementation details from Cursor's self-review draft
**Reviewed against:** `02-CONVENTIONS.md`, `04-API-DESIGN.md`, `05-SECURITY-COMPLIANCE.md` § 4.3 + § 6.3, `07-TESTING.md`, `20-PHASE-1-SPEC.md`

## Scope

End-to-end TOTP 2FA on top of the chunk 3 / 4 auth stack:

- New library dependencies: `pragmarx/google2fa ^9.0`, `bacon/bacon-qr-code ^3.1`. Both reachable only through `TwoFactorService`.
- Schema evolution: `users.two_factor_enrollment_suspended_at` (nullable timestamp) added in a non-destructive migration. `users.two_factor_recovery_codes` model cast changed from `encrypted` → `encrypted:array` so the column round-trips an array of bcrypt hashes (still encrypted at rest as defense-in-depth).
- Two-step enrollment flow:
  - `POST /api/v1/auth/2fa/enable` — provisional secret + OTPAuth URL + inline SVG QR + 10-minute provisional token. **No user-row mutation.**
  - `POST /api/v1/auth/2fa/confirm` — verifies first TOTP code against the cached secret, persists secret + 10 hashed recovery codes + `two_factor_confirmed_at` in a single DB transaction, returns the plaintext recovery codes ONCE.
  - `POST /api/v1/auth/2fa/disable` — requires current password AND working 2FA code; wipes secret + recovery codes + confirmed_at + suspended_at in one transaction.
  - `POST /api/v1/auth/2fa/recovery-codes` — requires a working 2FA code; rotates the batch.
  - All four routes mirrored under `admin/auth/2fa/*` on the `web_admin` guard. Disable/regenerate are gated behind `EnsureMfaForAdmins`; enable/confirm are not (chicken-and-egg).
- Login flow accepts an optional `mfa_code` field. Behaviour:
  - User without 2FA: behaves exactly as chunk 3.
  - User with 2FA, no `mfa_code`: 401 `auth.mfa_required`.
  - User with 2FA, valid TOTP `mfa_code`: success; `LoginSucceeded` audit row carries `mfa: true`. **No per-attempt TOTP audit row** (would flood the log).
  - User with 2FA, valid recovery `mfa_code`: success + atomic consumption + `mfa.recovery_code_consumed` audit row carrying `remaining_count`.
  - User with 2FA, invalid `mfa_code`: 401 `auth.mfa.invalid_code`. Throttle counter increments.
  - 6+ invalid attempts in 15 min: 423 `auth.mfa.rate_limited` with `Retry-After`.
  - 10+ invalid attempts in 15 min: enrollment frozen via `users.two_factor_enrollment_suspended_at`; subsequent attempts return 423 `auth.mfa.enrollment_suspended` until admin clears.
- New middleware: `EnsureMfaForAdmins` — runs AFTER `auth:web_admin` and BEFORE controller; returns 403 `auth.mfa.enrollment_required` for unenrolled admins. Honours `auth.admin_mfa_enforced` config flag, but only allows opting-out in the local environment.
- New events + listeners: `TwoFactorEnabled`, `TwoFactorConfirmed`, `TwoFactorDisabled`, `TwoFactorRecoveryCodesRegenerated`, `TwoFactorRecoveryCodeConsumed`, `TwoFactorEnrollmentSuspended`. The last writes its audit row transactionally (mirrors `AccountLocked` / `AccountLockoutService`); the others go through the listener.
- `AuditAction` enum: replaced placeholder `auth.two_factor.*` verbs with the chunk-5-spec `mfa.*` namespace (`mfa.enabled`, `mfa.confirmed`, `mfa.disabled`, `mfa.recovery_codes_regenerated`, `mfa.recovery_code_consumed`, `mfa.enrollment_suspended`). Removed `auth.two_factor.challenge_succeeded` / `auth.two_factor.challenge_failed` (no chunk had shipped against them; per-attempt audit explicitly NOT desired). Added `AuditAction::isSensitiveCredentialAction()` helper.
- i18n: `mfa.*` keys added to `lang/en|pt|it/auth.php` for invalid_code, rate_limited, enrollment_suspended, enrollment_required, already_enabled, not_enabled, provisional_expired.
- Test infrastructure: `BCRYPT_ROUNDS=4` added to `phpunit.xml` to keep the test suite under 12s with the new bcrypt-per-recovery-code work; production untouched. **Inline comment added** to `phpunit.xml` warning that cost=4 is the lowest bcrypt accepts and is for test performance only — production uses `config('hashing.bcrypt.rounds')` which defaults to 12.

## Acceptance criteria — all 12 priorities met

| #   | Priority                                                                            | Status                                                                                                                                                                                                                  |
| --- | ----------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | TwoFactorService is the only path to google2fa internals                            | ✅ Enforced by `TwoFactorIsolationTest` walking `app/` and asserting `PragmaRX\Google2FA\` and `BaconQrCode\` only appear inside `TwoFactorService.php`                                                                 |
| 2   | TOTP rate limit tighter than password (5/15min/user; 10/15min suspends enrollment)  | ✅ `TwoFactorVerificationThrottle` constants `SOFT_THRESHOLD = 5`, `HARD_THRESHOLD = 10`, `WINDOW_MINUTES = 15`; tests exercise both thresholds                                                                         |
| 3   | Recovery codes stored hashed (bcrypt) — column still encrypted at rest              | ✅ `TwoFactorService::hashRecoveryCode()` uses `HashManager::driver('bcrypt')` explicitly; `users.two_factor_recovery_codes` cast is `encrypted:array`                                                                  |
| 4   | Recovery code consumption is atomic (single tx with row lock)                       | ✅ `TwoFactorChallengeService::consumeRecoveryCode()` runs inside `DB::transaction` with `lockForUpdate()`; race test asserts exactly-one-success                                                                       |
| 5   | TOTP secret encrypted at rest AND wiped on disable, no partial state                | ✅ `users.two_factor_secret` keeps `encrypted` cast; `TwoFactorEnrollmentService::disable()` nulls secret + recovery_codes + confirmed_at + suspended_at in one transaction; test asserts all four                      |
| 6   | Recovery codes never logged in plaintext                                            | ✅ `TwoFactorAuditTest` replays the entire 2FA flow and asserts no audit row contains any plaintext code, secret, or matching substring                                                                                 |
| 7   | EnsureMfaForAdmins middleware order matters                                         | ✅ Mounted as `['auth:web_admin', EnsureMfaForAdmins::class]` so user resolves before MFA check; passes through cleanly when `$request->user()` is null                                                                 |
| 8   | Two-step enrollment flow (enable returns provisional → confirm with code → persist) | ✅ Provisional secret lives in cache (10-min TTL) keyed on `identity:2fa:enroll:<user_id>:<token>`; never persisted before `confirm()`; abandoned-enrollment test asserts user row stays clean after cache flush        |
| 9   | Login accepts TOTP OR recovery code in the same `mfa_code` field                    | ✅ `TwoFactorService::looksLikeTotpCode()` flags 6-digit numerics; `TwoFactorChallengeService::verify()` tries TOTP first, falls through to recovery-code consumption                                                   |
| 10  | Disable requires current password AND current 2FA code                              | ✅ `DisableTwoFactorController` checks `Hasher::check()` AND `TwoFactorChallengeService::verify()`; both paths return single error code `auth.mfa.invalid_code` (no factor fingerprinting)                              |
| 11  | Local development override flag, default true                                       | ✅ `config('auth.admin_mfa_enforced')` defaults `true`; `EnsureMfaForAdmins::shouldEnforce()` only honours `false` when `app.env === 'local'`                                                                           |
| 12  | All endpoints emit appropriate audit events                                         | ✅ All five `mfa.*` verbs (`enabled`, `confirmed`, `disabled`, `recovery_codes_regenerated`, `recovery_code_consumed`) plus the throttle's `enrollment_suspended` are tested for actor / subject / metadata correctness |

## Standout design choices (unprompted)

- **`TwoFactorIsolationTest` as the executable form of priority #1.** Walks every `.php` under `app/`, asserts only `TwoFactorService.php` references `PragmaRX\Google2FA\` or `BaconQrCode\`. Adding a new entry point requires either extending `TwoFactorService` or actively breaking this test, which makes the violation impossible to land silently. **This source-inspection test pattern is now a team standard** for enforcing structural invariants at test time.
- **Tagged enum `TwoFactorConfirmationResult` + `TwoFactorChallengeResult`** for branch outcomes. The controller's `match` is exhaustive against `TwoFactorConfirmationStatus` (Confirmed / InvalidCode / ProvisionalNotFound / AlreadyConfirmed) and PHPStan flags any new variant that isn't handled. Same for `LoginResultStatus` extension with `MfaInvalidCode` / `MfaRateLimited` / `MfaEnrollmentSuspended` branches.
- **Throttle counter increments on the soft-locked path too.** Without this, an attacker who knows the soft cap (5/15min) could pace themselves forever and never trigger the hard suspension. The increment-then-block-then-suspend pattern means even rate-limited attempts contribute to the hard threshold. **This bug was caught by the `TwoFactorLoginTest::it('after 10 invalid attempts the user 2FA enrollment is suspended...')` test mid-development**, with the patch including an explanatory comment so the design intent is preserved.
- **Constant-verification-count recovery-code lookup.** `TwoFactorChallengeService::consumeRecoveryCode()` calls `checkRecoveryCode()` on every stored hash slot regardless of whether a match has already been found, so the bcrypt-verify count is constant in the slot count and response time does not leak which slot matched via timing. Cost: ~N × bcrypt-verify on every recovery-code login (~100ms in production with cost 10). Acceptable because recovery-code logins are rare and already rate-limited. **Pinned by the regression test `TwoFactorEdgeCasesTest::it('runs checkRecoveryCode on every slot — no ! $matched short-circuit')`** which uses source-inspection to fail if a future engineer reintroduces the short-circuit.
- **Transactional audit on hard-suspension.** `TwoFactorVerificationThrottle::suspendEnrollment()` writes the `mfa.enrollment_suspended` audit row inside the same DB transaction that stamps `users.two_factor_enrollment_suspended_at`, mirroring the chunk-3 `AccountLockoutService::escalate()` pattern. The `TwoFactorEnrollmentSuspended` event listener is intentionally NOT registered (would double-audit); the event remains a fan-out signal only. Both decisions documented inline.
- **Idempotency guard at the top of `suspendEnrollment()`** short-circuits a second call. Combined with the in-transaction stamp, this means a re-suspend on an already-suspended user is a no-op (no overwritten timestamp, no duplicate audit row under serial conditions).
- **Single error code for all disable / regenerate failures** (`auth.mfa.invalid_code`). An attacker can't fingerprint whether the password or the 2FA code was wrong. Also matches the chunk-3 login-failure non-fingerprinting contract.
- **`TwoFactorService::checkRecoveryCode()` swallows hasher exceptions.** Bcrypt's verifyAlgorithm flag throws on non-bcrypt input. Defensive try/catch returns `false` so a corrupted/migrated row never 500s a login flow — the user gets an invalid-code response and operations fix the data out-of-band. Unit-tested against a `'not-a-bcrypt-hash'` literal.
- **`encrypted:array` cast on `two_factor_recovery_codes`.** Stores a JSON array of bcrypt hashes, encrypted at rest. The cast handles serialization round-trip cleanly so callers always see `array<int, string>`. `withTwoFactor()` factory state updated to use a real array of bcrypt-shaped strings; old test fixture (`json_encode(...)`) would have failed the cast.
- **Bcrypt cost 4 in tests via `phpunit.xml` env var, with documentation comment.** Lowest cost the library accepts, cuts the suite from 60s+ to ~10s. Production stays on the config default. Inline XML comment added to prevent cargo-culting the value elsewhere.
- **Bcrypt — not Argon2id — for recovery codes.** Documented in `TwoFactorService::hashRecoveryCode()` docblock: recovery codes are short, fixed-format, high entropy; Argon2's memory cost gives no security benefit on a 64-bit input but would dominate the verify path. Bcrypt cost 10 verifies in ~10ms.
- **Provisional state lives in cache only.** Cache key is `identity:2fa:enroll:<user_id>:<token>`. The provisional token is the cache-key suffix, so an attacker who steals an active session cannot guess another user's provisional token even though they share the cache. Abandoned enrollment evaporates via TTL; no DB cleanup needed.
- **`hasTwoFactorEnrollmentSuspended()` helper on the User model.** Mirrors `hasTwoFactorEnabled()` so AuthService's branch reads symmetrically; the suspended check happens before the code check so a suspended user with a valid TOTP still cannot bypass the freeze.
- **`AuditAction::isSensitiveCredentialAction()`** helper added preemptively. Currently only used in the `AuditActionEnumTest`, but downstream chunks that need to flag credential mutations for SIEM forwarding (Sprint 13+ security pipeline) get a single source of truth.
- **Removed unreachable hard-suspend branch.** A `LoginResult::mfaEnrollmentSuspended` early-return after `recordFailure` was unreachable under current thresholds (soft=5 < hard=10) — the user would always have been soft-locked before the challenge path could push them past hard. Replaced with an explanatory comment so a future threshold tweak can decide intentionally whether to restore it. The discipline to remove dead code with reasoning, rather than leaving it "just in case," is correct.

## Decisions documented for future chunks

- **`mfa.*` is the audit namespace** for 2FA actions (not `auth.two_factor.*`). Previous placeholder verbs were removed because no chunk had shipped against them yet.
- **TOTP per-attempt verifications are NOT audited.** The login-succeeded row carries `mfa: true` as the only signal that 2FA was used; the throttle counter records the suspicious-volume signal. Future chunks needing per-attempt visibility wire it to a metric, not an audit row.
- **Recovery code count, never code value, in audit metadata.** `mfa.recovery_codes_regenerated` records `code_count`; `mfa.recovery_code_consumed` records `remaining_count`. Future audit verbs handling secret-shaped material follow the same shape (record metadata about the secret, not the secret itself).
- **`TwoFactorService` is the only entry point to Google2FA + BaconQrCode.** Future modules needing TOTP / QR add a method to `TwoFactorService`, not direct vendor calls. Enforced by `TwoFactorIsolationTest`.
- **`auth.admin_mfa_enforced=false` is local-only.** Even if a misconfigured staging/prod env sets the flag, `EnsureMfaForAdmins` refuses to honour it outside `app.env === 'local'`. Future per-environment auth tweaks should follow the same belt-and-suspenders pattern.
- **`EnsureMfaForAdmins` mounts AFTER `auth:web_admin`.** Any new admin route added in later sprints MUST mount the middleware in this order; the chicken-and-egg enrollment endpoints (`/admin/auth/2fa/enable`, `/admin/auth/2fa/confirm`) are the only documented exceptions.
- **Single `mfa_code` field at login.** TOTP and recovery codes share the field; the verifier dispatches based on format (`looksLikeTotpCode()`). Future SMS / WebAuthn factors slot in as additional formats inside `TwoFactorChallengeService::verify()`.
- **`two_factor_enrollment_suspended_at` requires admin clearance.** Sprint 2 should surface "stuck" users in the admin SPA (single query: `WHERE two_factor_enrollment_suspended_at IS NOT NULL`); until then it's a manual UPDATE. The audit verb to use when an admin clears it is `mfa.enabled` again — re-enrollment from scratch — not a dedicated `mfa.enrollment_unfrozen` (avoids verb sprawl).
- **Disable requires both factors.** Future sensitive-mutation endpoints (e.g. delete account in Sprint 9) should reuse the password+mfa pattern.
- **`User::auditableAllowlist()` excludes all four 2FA columns** (`two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`, `two_factor_enrollment_suspended_at`). Tests verify by reading the trait output. New 2FA-related columns added in later chunks must be evaluated for inclusion explicitly.
- **Source-inspection regression tests are a team standard.** `TwoFactorIsolationTest` (library isolation) and `TwoFactorEdgeCasesTest::it('runs checkRecoveryCode on every slot')` (timing-attack defense) both use this pattern: the test reads source code with regex and asserts structural invariants the type system can't enforce. Future chunks needing similar invariants follow the same pattern.

## Follow-up items

### For Sprint 2 (admin SPA work)

- **Surface `two_factor_enrollment_suspended_at` in the admin SPA.** A simple list view + "clear suspension" button. Today an operator must run a manual UPDATE, which is acceptable for chunk 5 only because no real users are suspended yet.
- **Observability metric on `mfa.enrollment_suspended` rate.** A spike across distinct users is a likely credential-stuffing indicator. Same recommendation applies to chunk 4's `EmailVerificationResult::AlreadyVerified` and chunk 3's HIBP `Log::warning` calls — all three should land together when the observability sprint runs.

### For Sprint 8 (Postgres-CI work)

Append to the existing entry in `docs/tech-debt.md`:

- The new `users.two_factor_enrollment_suspended_at` column is `timestamp` and works identically across SQLite/Postgres.
- The existing `users.two_factor_secret` and `users.two_factor_recovery_codes` columns will benefit from `bytea` storage in the Postgres rehome (encrypted blobs are slightly more compact). Non-destructive migration via expand/migrate/contract.
- **Throttle counter race condition under true write concurrency.** The current `TwoFactorVerificationThrottle::recordFailure()` uses a read-then-put pattern (read counter, increment, put back), which is racy under simultaneous failed attempts on the same user. The in-transaction idempotency guard in `suspendEnrollment()` prevents corrupted state on the column itself, but two concurrent attempts at the hard-threshold boundary could each pass the guard before either commits, producing duplicate `mfa.enrollment_suspended` audit rows. The column re-stamp is idempotent (harmless), so the only observable artifact is a possible duplicate audit row at the suspension boundary. **Sprint 8 Redis-hardening should switch to `Redis::incr()` atomic semantics** to close the race cleanly.

### For Sprint 13+ (security pipeline)

- `AuditAction::isSensitiveCredentialAction()` is the existing helper for SIEM forwarding. The downstream pipeline can fan-out only the flagged verbs to a hot security-events stream rather than the full audit_logs table.

## What was deferred (with triggers)

- **WebAuthn / passkey support** — Phase 3 once user demand justifies the SPA complexity. Wire-compatible since `mfa_code` is a single field and `TwoFactorChallengeService::verify()` already dispatches by format.
- **SMS as a backup factor** — Phase 3+; would require SMS provider integration and is materially less secure than TOTP, so not a near-term priority.
- **Per-device trust ("don't ask for 2FA on this device for 30 days")** — Sprint 9+ as part of the broader session-management UI.
- **2FA challenge response logging for forensics** — deferred per the explicit "no per-attempt TOTP audit" decision; reconsider only if a security incident demands it and a privacy review approves.
- **Admin UI to clear `two_factor_enrollment_suspended_at`** — Sprint 2 (already flagged above).
- **Hardware key (Yubikey) enrollment** — Phase 3+; same factor-format-dispatch path.
- **Atomic throttle counter via `Redis::incr()`** — Sprint 8 Redis-hardening pass (flagged above).

## Verification results

| Gate                                       | Result                                                                                                                                                                                                                                                                                                                                                                                                                    |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend tests                              | 274 passed (873 assertions) — includes the constant-time regression test                                                                                                                                                                                                                                                                                                                                                  |
| Frontend smoke tests                       | 2 passed (apps/admin + apps/main Vitest)                                                                                                                                                                                                                                                                                                                                                                                  |
| `php vendor/bin/pint`                      | clean                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `php vendor/bin/phpstan analyse` (level 8) | no errors                                                                                                                                                                                                                                                                                                                                                                                                                 |
| Identity module coverage                   | 100% on every chunk-5 file (`TwoFactorService`, `TwoFactorEnrollmentService`, `TwoFactorChallengeService`, `TwoFactorVerificationThrottle`, all 5 controllers, the middleware, all 6 events, all 3 form requests, the throttle's audit-writing path, `AuthService` mfa branches). The 1.4% project-wide gap is the pre-existing `routesAreCached()` short-circuit in `IdentityServiceProvider` (chunk-1 code, unchanged). |
| Critical chunk-5 tests                     | `TwoFactorServiceTest` (10), `TwoFactorIsolationTest` (2), `TwoFactorEnrollmentTest` (11), `TwoFactorLoginTest` (10), `TwoFactorDisableTest` (12), `TwoFactorAuditTest` (2), `EnsureMfaForAdminsMiddlewareTest` (7), `TwoFactorEdgeCasesTest` (10 — includes constant-time regression test) — all green                                                                                                                   |

## Spot-checks performed

1. ✅ **Library isolation:** `TwoFactorIsolationTest` confirms `PragmaRX\Google2FA\` and `BaconQrCode\` appear in exactly one file under `app/` (`TwoFactorService.php`).
2. ✅ **Atomic recovery-code consumption:** `TwoFactorLoginTest::it('atomic recovery-code consumption: parallel-style attempts succeed exactly once')` calls `consumeRecoveryCode()` twice with the same plaintext code; asserts `[true, false]` and that exactly 9 hashes remain.
3. ✅ **Disable atomicity:** all four 2FA columns null after request; `disable()` runs inside `DB::transaction`.
4. ✅ **Audit hygiene:** `TwoFactorAuditTest` walks every audit row and asserts no plaintext code, secret, or matching substring appears.
5. ✅ **Two-step enrollment:** abandoned enrollment leaves user row clean; provisional state evicts from cache.
6. ✅ **Local-dev override constraint:** middleware refuses to honour `auth.admin_mfa_enforced=false` outside `app.env === 'local'`; staging/prod always enforces.
7. ✅ **Hard-suspension is triggered inline within `recordFailure`** (verified via spot-check after Cursor's draft): no separate post-recordFailure check, no race between increment and suspension within a single attempt; `suspendEnrollment` opens a transaction, stamps `two_factor_enrollment_suspended_at`, writes the audit row in the same tx, dispatches the event after commit. Idempotency guard at the top short-circuits a second call.
8. ✅ **Suspension precedence in login flow** (verified via spot-check): `hasTwoFactorEnrollmentSuspended()` is the FIRST gate inside the 2FA block, before the missing-code check, before the soft-lock check, well before any verifier call. A suspended user gets 423 immediately and `recordFailure` / verifier never run.
9. ✅ **Constant-verification-count recovery-code lookup** (Option B applied per Cursor's correction): `consumeRecoveryCode()` walks every slot calling `checkRecoveryCode()` regardless of match, so bcrypt-verify count is constant in slot count. Pinned by `TwoFactorEdgeCasesTest::it('runs checkRecoveryCode on every slot — no ! $matched short-circuit')` source-inspection test that fails if a future engineer reintroduces the short-circuit.

## Cross-chunk note

No latent bugs surfaced from earlier chunks during chunk 5. The `withTwoFactor()` factory state was the only legacy fixture that needed updating (it stored a JSON-encoded string under the old `encrypted` cast; switched to a real array of bcrypt-shaped strings under the new `encrypted:array` cast). One previously-passing test (`IdentityModelsTest::it('User factory withTwoFactor state populates the 2FA columns')`) was updated to match.

---

_Provenance: drafted by Cursor as the chunk-5 implementer's self-review against the established `docs/reviews/` template; merged by Claude with independent review pass, spot-check verifications (including the constant-verification-count correction that flipped from Option A defer to Option B fix-now), Sprint 8 atomic-incr follow-up, and final closure._
