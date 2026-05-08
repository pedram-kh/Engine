# Sprint 1 — Chunk 4 Review

**Status:** Ready for review
**Reviewer:** Claude
**Reviewed against:** `02-CONVENTIONS.md`, `04-API-DESIGN.md`, `05-SECURITY-COMPLIANCE.md`, `07-TESTING.md`, `20-PHASE-1-SPEC.md`

## Scope

Self-serve sign-up + email verification:

- `POST /api/v1/auth/sign-up` — creates a single creator-typed `users` row
  with `email_verified_at = null`. No session is issued.
- `POST /api/v1/auth/verify-email` — single-use HMAC-signed token; 24h
  lifetime per `05 §6.5`. Re-clicks return 409 `auth.email.already_verified`.
- `POST /api/v1/auth/resend-verification` — silent 204 (user-enumeration
  defence). 1/min/email rate limit.
- New events `UserSignedUp`, `EmailVerificationSent`, `EmailVerified` +
  matching audit verbs `auth.signup`, `auth.email.verification_sent`,
  `auth.email.verified` (replaced `auth.email.verification_requested`
  since no chunk shipped against it yet).
- New mailable `VerifyEmailMail` with `mail.identity.verify-email`
  Markdown template; localized to en/pt/it.
- Reuses chunk 3's `StrongPassword` + `PasswordIsNotBreached` rules and
  the Argon2id hashing config. No password logic was reimplemented.

## Acceptance criteria — all met

- ✅ `POST /auth/sign-up` writes exactly one `users` row and zero rows
  in any other domain table (asserted by counting `users`,
  `admin_profiles`, `agency_users`, `agencies` before/after).
- ✅ Sign-up uses Argon2id (asserted: stored password starts with
  `$argon2id$`) and HIBP (asserted: a stub client returning `breachCount = 17`
  rejects the request with `password` validation error).
- ✅ Email-verification token is signed (HMAC-SHA-256 over base64url
  payload) and single-use via `users.email_verified_at` flag.
  Re-clicking a verified user returns 409 with `auth.email.already_verified`.
  Expired tokens (>24h) return 410 with `auth.email.verification_expired`.
- ✅ `POST /auth/resend-verification` rate-limited to 1/min/email; second
  request within 60s returns 429 with the standard `rate_limit.exceeded`
  envelope and a `Retry-After` header.
- ✅ Localized mailables: `VerifyEmailMail` and `ResetPasswordMail` (chunk 3,
  retroactively switched from `view:` to `markdown:` to fix a latent
  `No hint path defined for [mail]` rendering bug). Tests assert the
  rendered subject and body strings actually differ across en/pt/it.
- ✅ Audit events fire correctly: `auth.signup` and
  `auth.email.verification_sent` on sign-up; `auth.email.verification_sent`
  on resend; `auth.email.verified` on first verify; no fresh audit on a
  re-click.
- ✅ No session created on sign-up: `assertGuest('web')` and
  `assertGuest('web_admin')` after the request. A follow-up authenticated
  request (logout) returns 401 — the user must verify and then sign in
  separately.

## Standout design choices (unprompted)

- **Mail content type switched to `markdown:`** in both `VerifyEmailMail`
  AND `ResetPasswordMail` (chunk 3). The chunk-3 mailable used `view:`
  with `@component('mail::message')` which works only when the vendor
  mail views are published. Tests didn't catch it because `Mail::fake()`
  short-circuits rendering. Switching to `markdown:` registers Laravel's
  built-in `mail::` hint path and makes the test that calls `->render()`
  pass without publishing vendor views.
- **Email normalisation in `prepareForValidation()`** so the `unique`
  validator AND the database insert see the same lower-cased value. A
  second test specifically posts `PEDRO@example.com` against an existing
  `pedro@example.com` and asserts a friendly 422, not a 500 from the
  unique-index race.
- **HMAC token carries an `email_hash`** (`sha1(strtolower(trim(email)))`)
  so a token minted against the user's old email is invalid after they
  change it. Test covers this path explicitly.
- **All four "invalid" cases collapse to one error code**
  (`auth.email.verification_invalid`): bad signature, malformed payload,
  unknown user, and email-changed-since-mint. Caller can't distinguish —
  prevents enumeration and fingerprinting of the token internals.
- **`SignUpService::sendVerificationMail()` is reused by**
  `EmailVerificationService::resend()` — sign-up and resend take the
  same code path so they emit identical audit / event sequences.
- **Resend on an already-verified user returns 204 silently** — same
  user-enumeration shape as forgot-password, no leak about verification
  state.
- **Event-listener split for audit assertions** — when a test uses
  `Event::fake([...])`, the `WriteAuthAuditLog` listener never runs so
  no audit row is written. Tests are split into one "event" assertion
  (with `Event::fake`) and one "audit row" assertion (without), so
  neither side accidentally tests through a swallowed listener.
- **APP_KEY resolution handles both `base64:` and plain values** —
  matches Laravel's own behaviour and is unit-tested with both cases.

## Decisions documented for future chunks

- The `auth.email.verification_sent` verb is shared by sign-up and
  resend. Future "verification mail bounced" handling (Sentry / SES
  webhook) emits a different verb (`auth.email.verification_bounced`,
  not yet in the catalog).
- HMAC tokens use the same `APP_KEY` Laravel uses for cookies and
  signed URLs; no separate key material in chunk 4. If verification
  abuse becomes a real attack surface, the next sprint to touch this
  can introduce a dedicated `IDENTITY_TOKEN_KEY` and rotate it
  independently.
- Sign-up always creates a creator-typed user (Phase 1). Agency
  invitation flow lands in Sprint 2 and writes its own `agency_users`
  row + sets `users.type = 'agency_user'` against an existing user.
- `Rule::unique('users', 'email')` + DB unique index is the canonical
  uniqueness pattern. Future user-creating endpoints replicate it.

## Follow-up items

### For Sprint 8 (Postgres-CI) — already documented in `docs/tech-debt.md`

- The case-insensitive email uniqueness check we added in
  `prepareForValidation()` will be backed by a `LOWER(email)` unique
  index in Postgres. Currently we rely on application-layer
  normalisation; a malicious actor crafting raw SQL could bypass it.

### For Sprint 5 (TOTP 2FA, the next chunk)

- The `auth.mfa_required` branch in `AuthService::login()` is real
  today (chunk 3) but no users have 2FA configured yet in chunk 4. The
  sign-up flow does NOT enrol the user in 2FA — that's a post-login
  flow. When chunk 5 lands, sign-up doesn't change.

## What was deferred (with triggers)

- Verification-mail bounce handling (SES / SMTP webhooks) — Sprint 8
  observability pass.
- Magic-link signup for agency creator-roster invitations — Sprint 3
  (creator wizard).
- Brand self-serve signup (no agency) — Phase 3 (`docs/22-PHASE-3-SPEC.md`).

## Verification results

| Gate                                       | Result                                                                                                          |
| ------------------------------------------ | --------------------------------------------------------------------------------------------------------------- |
| Backend tests                              | 208 passed (614 assertions)                                                                                     |
| Frontend smoke tests                       | 2 passed                                                                                                        |
| `php vendor/bin/pint`                      | clean                                                                                                           |
| `php vendor/bin/phpstan analyse` (level 8) | no errors                                                                                                       |
| Identity module coverage                   | 100% (one defensive line remains in `IdentityServiceProvider::registerRoutes()`)                                |
| Critical-path tests                        | `SignUpTest`, `VerifyEmailTest`, `ResendVerificationTest`, `MailLocalizationTest`, `EmailVerificationTokenTest` |

## Spot-checks performed

1. ✅ `SignUpTest` strict-row test counts users, admin_profiles, agency_users, agencies.
2. ✅ `SignUpService` uses `Hash::make()`-equivalent (the `password` cast is `'hashed'`) — Argon2id default.
3. ✅ `EmailVerificationToken::decode()` checks signature BEFORE attempting JSON decode (constant-time on bad signature).
4. ✅ `auth-resend-verification` rate limiter keys on `verify:<email>` (lower-cased), independent from `auth-ip` and `auth-password`.
5. ✅ Resend on verified or unknown email returns 204 with no mail queued and no audit row.
