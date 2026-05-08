# Sprint 1 — Chunk 4 Review

**Status:** Closed
**Reviewer:** Claude (independent review) — incorporating implementation details from Cursor's self-review
**Reviewed against:** `02-CONVENTIONS.md`, `04-API-DESIGN.md`, `05-SECURITY-COMPLIANCE.md` § 6.5, `07-TESTING.md`, `20-PHASE-1-SPEC.md`

## Scope

Self-serve sign-up + email verification:

- `POST /api/v1/auth/sign-up` — creates a single creator-typed `users` row with `email_verified_at = null`. No session is issued.
- `POST /api/v1/auth/verify-email` — single-use HMAC-signed token; 24h lifetime per `05 § 6.5`. Re-clicks return 409 `auth.email.already_verified`. Expired tokens return 410 `auth.email.verification_expired`.
- `POST /api/v1/auth/resend-verification` — silent 204 (user-enumeration defense). 1/min/email rate limit.
- New events: `UserSignedUp`, `EmailVerificationSent`, `EmailVerified`.
- New audit verbs: `auth.signup`, `auth.email.verification_sent`, `auth.email.verified` (replaced `auth.email.verification_requested` since no chunk had shipped against it yet).
- New mailable `VerifyEmailMail` with `mail.identity.verify-email` Markdown template; localized to en/pt/it.
- Reused chunk 3's `StrongPassword` + `PasswordIsNotBreached` rules and Argon2id hashing config.

## Acceptance criteria — all met

- ✅ Sign-up writes exactly one `users` row and zero rows in any other domain table (asserted by counting `users`, `admin_profiles`, `agency_users`, `agencies` before/after)
- ✅ Sign-up uses Argon2id (asserted: stored hash starts with `$argon2id$`) and HIBP (stub client returning `breachCount = 17` rejects with `password` validation error)
- ✅ Verification token is HMAC-SHA-256 signed, 24h lifetime, single-use via `users.email_verified_at`
- ✅ Re-click on verified user returns 409 `auth.email.already_verified`
- ✅ Expired token returns 410 `auth.email.verification_expired`
- ✅ Resend rate-limited 1/min/email; second request within 60s returns 429 with standard `rate_limit.exceeded` envelope and `Retry-After` header
- ✅ Localized mailables: rendered subject AND body strings actually differ across en/pt/it
- ✅ Audit events fire correctly with matching `actor_id` / `subject_id`; re-click on verified user does NOT write a fresh audit row
- ✅ No session created on sign-up: `assertGuest('web')` and `assertGuest('web_admin')` pass; follow-up authenticated request returns 401
- ✅ Identity module at 100% line coverage (except 1 defensive line in `IdentityServiceProvider::registerRoutes()`)

## Standout design choices (unprompted)

- **Tagged enum `EmailVerificationResult`** for verification outcomes (`Verified`, `AlreadyVerified`, `Expired`, `Invalid`). Type-safe, exhaustive, and the API contract is legible from the enum.
- **Single-use enforced via `users.email_verified_at`** rather than a separate spent-tokens table. Simpler, no cleanup needed, can't fail open under any concurrent state.
- **Replay-after-email-change defense** via `email_hash = sha1(strtolower(trim(email)))` in the payload — token minted against the user's old email is invalid after they change it.
- **All four "invalid" cases collapse to one error code** `auth.email.verification_invalid` (bad signature, malformed payload, unknown user, email-changed-since-mint). Caller can't fingerprint token internals via differential error responses.
- **`base64:`-prefixed APP_KEY decoded for HMAC** — naive code would HMAC against the literal `base64:...` string, using fewer entropy bits. Cursor handled the encoding prefix correctly. Unit-tested with both prefixed and plain APP_KEY values.
- **`hash_equals()` for constant-time signature comparison** — defends against timing attacks on the HMAC. Signature verified BEFORE attempting JSON decode.
- **Email normalization in `prepareForValidation()`** lowercases email so the `unique` validator and DB unique index see the same value. Test specifically posts `PEDRO@example.com` against an existing `pedro@example.com` and asserts a friendly 422, not a 500 from a unique-index race.
- **Sign-up and resend share the verification mail path** — `SignUpService::sendVerificationMail()` is reused by `EmailVerificationService::resend()`, producing identical event/audit sequences.
- **Resend silent 204 on already-verified or unknown email** — consistent user-enumeration defense across the auth surface (matches forgot-password from chunk 3).
- **Event-listener split for audit assertions in tests** — when `Event::fake([...])` is used, the `WriteAuthAuditLog` listener never runs so no audit row is written. Tests are split into one "event" assertion (with `Event::fake`) and one "audit row" assertion (without), so neither side accidentally tests through a swallowed listener. **This is now the team standard** for any test asserting both a domain event and its audit consequence.
- **Caught and fixed a latent chunk-3 bug:** `ResetPasswordMail` used `view:` with `@component('mail::message')` which fails outside `Mail::fake()` because Laravel's `mail::` hint path isn't registered without going through the markdown pipeline. Switched both mailables to `markdown:`. Tests didn't catch it earlier because `Mail::fake()` short-circuits rendering. (See "Lessons" below.)

## Lessons captured for future chunks

**`Mail::fake()` skips actual rendering.** Tests relying solely on `Mail::fake()` won't catch broken mailable templates. Future chunks introducing new mailables include at least one real-rendering test per mailable (e.g., `MailLocalizationTest` pattern from chunk 4 that switches `App::setLocale()` and renders `envelope()` directly). **Team standard.**

**Event listener tests must split assertions.** When a test uses `Event::fake([...])`, downstream listeners (including `WriteAuthAuditLog`) don't run. To assert both an event was dispatched AND its audit row was written, split into two tests: one with `Event::fake` (asserting dispatch), one without (asserting the audit row). **Team standard.**

## Decisions documented for future chunks

- **`auth.email.verification_invalid`** is the single error code for all decode failures. No future chunk should add fingerprinting error codes that differentiate signature-invalid from payload-malformed from unknown-user.
- **HMAC token format** `base64url(payload).base64url(signature)` is the established pattern for self-contained signed tokens. Future chunks needing similar tokens (one-time access links, magic-link auth) follow this format.
- **Mail rendering uses `markdown:`, not `view:` + `@component('mail::message')`.** All future mailables in this codebase use the markdown pipeline.
- **Re-click on already-verified user is a clean no-op.** No audit event, no log line. (See observability follow-up below.)
- **`auth.email.verification_sent`** is shared by sign-up and resend. A future "verification mail bounced" event will use a distinct verb (`auth.email.verification_bounced`, not yet in the catalog).
- **HMAC tokens use the same `APP_KEY`** Laravel uses for cookies and signed URLs. No separate key material in chunk 4. If verification abuse becomes a real attack surface, a future sprint can introduce a dedicated `IDENTITY_TOKEN_KEY` and rotate it independently.
- **Sign-up always creates a creator-typed user in Phase 1.** Agency invitation flow (Sprint 2) writes its own `agency_users` row and sets `users.type = 'agency_user'` against an existing user.
- **`Rule::unique('users', 'email')` + DB unique index** is the canonical uniqueness pattern. Future user-creating endpoints replicate it.

## Follow-up items

### For Sprint 8 (Postgres-CI work)

The case-insensitive email uniqueness check added in `prepareForValidation()` will be backed by a `LOWER(email)` unique index in Postgres. Currently we rely on application-layer normalization; a malicious actor crafting raw SQL could bypass it. Add this to the existing SQLite-in-tests entry in `docs/tech-debt.md`:

> "Sprint 8 Postgres-CI also adds a `LOWER(email)` functional unique index on `users.email` to back the application-layer normalization in `SignUpRequest::prepareForValidation()`."

### For Sprint 2 observability pass (or Sentry full-wiring sprint, whichever comes first)

The `EmailVerificationResult::AlreadyVerified` branch is a clean no-op. This is correct for chunk 4 because re-clicks on stale email links are overwhelmingly benign (mobile mail prefetch, user retry).

However, a sustained pattern of `AlreadyVerified` results across many distinct users could indicate token-theft probing — an attacker testing harvested tokens.

**Trigger:** Sprint 2 observability pass, or the sprint that fully wires Sentry-Laravel (whichever first).
**Action when triggered:** Increment a metric / Sentry breadcrumb (not an audit row — keep audit clean) on `EmailVerificationResult::AlreadyVerified`. Add an alert rule when the rate exceeds a threshold (e.g., 50/hour or 10/hour per IP). Same recommendation applies to chunk 3's HIBP `Log::warning` calls.

### For Sprint 8 observability pass (verification-mail bounce handling)

SES / SMTP bounce webhooks are not handled in chunk 4. When email-deliverability monitoring lands (Sprint 8 or later), introduce `auth.email.verification_bounced` audit verb and a flow that surfaces persistently-bouncing accounts to admins.

## What was deferred (with triggers)

- Observability for replay attempts on email verification — Sprint 2 / Sentry-wiring sprint
- Verification-mail bounce handling (SES / SMTP webhooks) — Sprint 8 observability pass
- `LOWER(email)` functional unique index — Sprint 8 (Postgres-CI work)
- Magic-link signup for agency creator-roster invitations — Sprint 3 (creator wizard)
- Brand self-serve signup (no agency) — Phase 3 (`docs/22-PHASE-3-SPEC.md`)
- Test extension `assertDatabaseCount('creators', 0)` — Sprint 3 (one-line addition to existing `SignUpTest`)

## Verification results

| Gate                                       | Result                                                                                                                      |
| ------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------- |
| Backend tests                              | 208 passed (614 assertions)                                                                                                 |
| Frontend smoke tests                       | 2 passed (apps/admin + apps/main Vitest)                                                                                    |
| `php vendor/bin/pint`                      | clean                                                                                                                       |
| `php vendor/bin/phpstan analyse` (level 8) | no errors                                                                                                                   |
| Identity module coverage                   | 100% (except 1 defensive line in `IdentityServiceProvider::registerRoutes()`)                                               |
| Critical chunk-4 tests                     | `SignUpTest`, `VerifyEmailTest`, `ResendVerificationTest`, `MailLocalizationTest`, `EmailVerificationTokenTest` — all green |

## Spot-checks performed

1. ✅ HMAC-SHA-256 signing with `hash_equals()` constant-time comparison; `base64:` APP_KEY prefix correctly decoded; signature verified BEFORE JSON decode
2. ✅ Resend rate limiter keyed on `verify:<lowercased email>` at 1/min, independent from `auth-ip` and `auth-password`; documented mailbombing-defense rationale
3. ✅ `AlreadyVerified` branch is a clean no-op — no audit event, no log; correct for chunk 4, observability deferred to Sprint 2

## Cross-chunk note (retroactive)

**Chunk 3 retroactive update:** A latent bug in `ResetPasswordMail` (using `view:` + `@component('mail::message')` instead of `markdown:`) was discovered and fixed during chunk 4. The chunk 3 review's verification was passing because the test suite used `Mail::fake()` exclusively, which short-circuits actual rendering. The new chunk 4 `MailLocalizationTest` real-rendering pattern would have caught it earlier; future chunks adopt this pattern as a team standard.

## Note on review file authorship

For chunk 4, Cursor produced a self-review draft anticipating the established pattern. This file merges the independent review (structural backbone, spot-check verdicts, observability follow-ups) with Cursor's self-review (specific implementation details, exact HTTP codes, the `Event::fake` test split insight). Going forward, `docs/reviews/` files are written by the reviewer (Claude). Cursor's chunk completion summaries should remain in chat / commit messages, not as separate files in `docs/reviews/`.
