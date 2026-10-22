# 05 — Security & Compliance

> **Status: Always active reference. Defines the security baseline and GDPR commitments for Catalyst Engine. Cursor must apply these standards to every feature, every endpoint, every data flow.**

This document covers: GDPR specifics, audit log standards, encryption, secrets management, threat model, and security review checklists. The platform handles real money, real PII, and real client campaign data. Security is not a phase concern — it's a continuous discipline applied to every PR.

---

## 1. The security mindset

Three principles, in priority order:

1. **Don't get breached.** Defense in depth. Assume everything is hostile.
2. **Detect breaches fast.** Log everything that matters. Alert on anomalies.
3. **Recover correctly.** Audit logs survive incidents. Backups are tested. Disclosure is handled per GDPR Article 33.

When a security decision conflicts with a feature decision, the security decision wins. When it conflicts with a UX decision, document the trade-off explicitly and decide consciously.

---

## 2. GDPR — non-negotiable commitments

Catalyst Engine is built EU-first. GDPR isn't a layer added later; it's a baseline.

### 2.1 Lawful basis for processing

Every data processing operation has a documented lawful basis. The categories used:

| Basis                   | When used                                                                             |
| ----------------------- | ------------------------------------------------------------------------------------- |
| **Contract**            | Processing required to fulfill a contract (e.g., creator profile, payment processing) |
| **Legitimate interest** | Internal product improvement, fraud prevention, audit logs                            |
| **Consent**             | Marketing emails, optional analytics tracking, non-essential cookies                  |
| **Legal obligation**    | Tax records, payment compliance, financial reporting                                  |

The lawful basis for every data category is documented in `docs/compliance/lawful-basis-registry.md` (created during build).

### 2.2 Data minimization

- Collect only data that has a documented purpose.
- Retain data only as long as the purpose requires.
- Deletion or anonymization happens automatically when retention periods expire.

### 2.3 Data subject rights

GDPR grants individuals seven rights. The platform supports all seven from Phase 1.

| Right                            | Implementation                                                                                                                                                                                         |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Right to be informed**         | Privacy notice at signup. Linked from every relevant screen. Versioned and consent re-captured on material changes.                                                                                    |
| **Right of access**              | `/api/v1/me/data-export` initiates a self-serve export. Returns a downloadable archive of all user data within 30 days (typically minutes).                                                            |
| **Right to rectification**       | Profile edit flows for all editable data. Free-text correction request to support for non-editable data.                                                                                               |
| **Right to erasure**             | `/api/v1/me/data-erasure` initiates erasure request. Goes through an admin approval queue (not automatic — to handle legal exceptions like retention obligations). Erasure within 30 days of approval. |
| **Right to restrict processing** | User can suspend their account; processing pauses while account is suspended.                                                                                                                          |
| **Right to data portability**    | The export is in machine-readable JSON + supporting media files. Sufficient for porting elsewhere.                                                                                                     |
| **Right to object**              | Granular consent toggles for marketing, analytics, etc. in settings. Honored immediately.                                                                                                              |

All seven rights have endpoints, all are tested, and all are documented in the privacy policy.

### 2.4 Erasure mechanics

When a creator's erasure is approved:

- Personal data fields on `users` and `creators` are anonymized: `email = "deleted-{ulid}@deleted.local"`, `name = "Deleted User"`, etc.
- Sensitive PII fields (legal name, tax ID, address, KYC documents) are nulled.
- Files in S3 (avatars, portfolio media, KYC documents) are deleted.
- Connected social account tokens are revoked at the provider and the rows are deleted.
- `audit_logs` entries that reference this user are NOT deleted — they retain `actor_id` but the actor's identity is anonymized. This preserves audit integrity while removing PII.
- Payment records are NOT deleted (legal retention requirement); the creator's name on payment records becomes "Deleted User."
- The user record is hard-deleted only after 7 years (legal retention for financial transactions).

> **Status as built (AH-104a):** the engine anonymises in place rather than deleting: `users` and `creators` keep their rows (anchors) with `erased_at` set, the email becomes `erased-{ulid}@erased.invalid`, and encrypted NOT NULL columns hold `[erased]`. The audit scrub keeps the rows and removes the person from five columns. There is no 7-year hard delete. How the run works, what it keeps and for how long: [`runbooks/account-erasure.md`](runbooks/account-erasure.md).

The erasure process is implemented as a background job (`ExecuteDataErasureJob`) with full transaction integrity. If anything fails, the entire erasure rolls back and admin is notified.

### 2.5 Data residency

- All production data resides in `eu-central-1` (Frankfurt).
- DR snapshots replicated to `eu-west-1` (Ireland).
- No US regions for production data — including S3 buckets, RDS, ElastiCache, backups.
- CloudFront edges may be global (the cached content is non-personal: public profile photos, static assets).
- Third-party processors must be EU-based or have appropriate transfer mechanisms (Standard Contractual Clauses).

### 2.6 Data Processing Agreements (DPAs)

Required with every processor:

- Stripe (payment processor)
- KYC vendor (Persona / Veriff / Onfido — TBD)
- E-sign vendor (DocuSign / Dropbox Sign — TBD)
- Email provider
- Sentry (error tracking)
- AWS (infrastructure provider — DPA via AWS GDPR DPA)
- Product analytics tool

Each DPA reviewed by legal counsel before integration goes live. Tracked in `docs/compliance/dpa-registry.md`.

### 2.7 Cookie consent

A Consent Management Platform (CMP) is integrated from Phase 1. The CMP:

- Distinguishes essential cookies (no consent required) from non-essential (consent required).
- Captures consent before any non-essential cookie is set.
- Stores consent records server-side with versioning.
- Allows consent withdrawal at any time.

Vendor TBD (OneTrust, Cookiebot, or self-built minimal). The choice is recorded in `06-INTEGRATIONS.md`.

### 2.8 Privacy notices

- Versioned. Old versions retained.
- Material changes trigger re-consent, not silent updates.
- Linked from every signup, every privacy-relevant action.
- Available in English, Portuguese, Italian.
- DPO (Data Protection Officer) contact information published.

### 2.9 Breach notification

GDPR Article 33: breaches involving personal data must be reported to the supervisory authority within 72 hours of awareness. Article 34: affected individuals must be notified without undue delay if there's high risk.

The platform's incident response runbook (in `docs/runbooks/incident-response.md` — built during Phase 1) defines:

- Detection and triage
- Severity classification
- Notification timeline
- Communication templates (regulator, affected users, public)
- Post-incident review

A breach notification SLA of 48 hours from detection is the operational target.

---

## 3. Audit logging — full specification

The `audit_logs` table is the system's immune system. It must be complete, append-only, and trustworthy.

### 3.1 What gets audited

**Always audited:**

- Authentication events: login, logout, failed login, 2FA enable/disable, password change, password reset
- Authorization decisions: forbidden access attempts (logged at warn level)
- User mutations: create, update, suspend, delete, role change
- Agency / brand mutations: create, update, suspend, delete
- Creator mutations: profile updates, application status changes, blacklist changes, KYC status changes, tax/payout changes
- Campaign mutations: create, update, status change, publish, cancel
- Assignment mutations: invite, accept, decline, contract sign, draft submit, draft review, post verify, payment release, cancel
- Contract mutations: create, sign, decline, expire
- Payment mutations: fund, release, refund, dispute open/close
- Admin actions: every action in admin SPA, especially impersonation
- Permission changes: role assignments, policy overrides
- Data export and erasure requests (creation and execution). Account deletion (AH-104b-del): `account.deletion_requested` (metadata: `scheduled_for`, `revoked` counts) and `account.deletion_cancelled` (metadata: `was_scheduled_for`), both written by the user's own act; the system's cancellation of an erased creator's open assignments is `assignment.cancelled` with **no actor** and the reason code `creator_account_erased`
- Integration credential changes
- Feature flag changes

**Not audited (intentionally, to avoid noise):**

- Read operations (covered by application logs at lower retention)
- Idempotent operations that produce no change (already-deleted entity gets deletion request)
- System-generated metric syncs from social APIs (logged in `integration_events` instead)

### 3.2 Audit event structure

Every entry has:

```
{
  id, ulid,
  agency_id (if tenant-scoped),
  actor_type ('user', 'system', 'webhook'),
  actor_id, actor_role (snapshot at action time),
  action (verb in dot notation, e.g., 'campaign.published'),
  subject_type, subject_id, subject_ulid,
  reason (free text, MANDATORY for destructive/sensitive actions),
  metadata (jsonb, action-specific),
  before (jsonb, snapshot of relevant state before),
  after (jsonb, snapshot of relevant state after),
  ip, user_agent,
  created_at
}
```

### 3.3 Mandatory reason

The following actions REQUIRE a non-empty `reason` field:

- Any deletion (soft or hard)
- Any account erasure (`account.erased`, AH-104a: one row per run, counts only)
- Any blacklist (creator, brand, agency)
- Any account suspension
- Any payment refund or dispute resolution
- Any admin impersonation
- Any tenant-scoped data export by admin
- Any role elevation (granting admin role)
- Any feature flag change in production

The application enforces this at the service layer. Attempting these actions without a reason raises an exception. The HTTP layer enforces it via `X-Action-Reason` header (see `04-API-DESIGN.md` § 26).

### 3.3a Account deletion request (AH-104b-del, built; behind `account_deletion_enabled`, off)

How a creator asks for their account to be deleted, and what the platform does until an admin erases it (the erasure itself is AH-104a, runbook [`runbooks/account-erasure.md`](runbooks/account-erasure.md)).

- **Who and how.** Creators only (suspended accounts cannot ask; an account in onboarding can). `POST /api/v1/me/account-deletion` on both doors (cookie session and the `creator_app` bearer). Body:
  `password`, `mfa_code` (required when 2FA is confirmed; a recovery code is accepted), `acknowledged: true`. The typed word in the UI is a usability gate, **not** a security control: the control is the
  re-authentication. Re-authentication failures answer **403** (never 401, which the apps read as "session ended"): `auth.invalid_credentials`, `auth.mfa_required`, `auth.mfa.invalid_code`; 423 for the
  second-factor lock-outs. A refusal on engine guards (payment, vendor) is 422 `account_deletion.blocked`, before the password is asked for.
- **Throttles.** The login throttles (`auth-ip`, the second-factor soft 5 / hard 10) apply, plus `auth-account-deletion`, 3 per hour per user, 429. The deletion door **does not feed the failed-login
  tracker or the lockout**: a wrong password here changes nothing on the account.
- **What the request does, in one transaction.** Stamps `users.deletion_requested_at` and `deletion_scheduled_for` (= requested + 14 days, stored, so a later change of the grace never alters a request
  already made); revokes **every** session, API token and push token (`CredentialRevoker`, one list, tied by a spec to the erasure registry); writes `account.deletion_requested`. After commit: one
  confirmation mail (queued, in the person's language, no link; support address from `config('app.support_email')`). A repeat request is idempotent: 200 with the first date, no new clock, no new mail.
- **Until the erasure.** The account cannot sign in: right credentials (and second factor) answer **403 `auth.deletion_pending`** with `meta.scheduled_for` and `meta.mfa_via`, and open no session or token.
  Wrong credentials answer exactly as for any account (a spec compares the envelopes byte for byte, so the state does not leak before the credentials are proven). Signing in again with
  `cancel_deletion: true` restores the account (`account.deletion_cancelled`); this stays possible after `scheduled_for` has passed and until the account is erased, and does not depend on the flag.
  A recovery code spent on the pending step is burned; the app asks for another.
- **Sign-up with the same address** answers the one static "email already exists" message for every taken address, whatever its state (no leak); the message names the way back (sign in).
- **At erasure** the creator's open assignments are cancelled by the system (A7, runbook §4), and the agencies are told once.
- **Not closed in this chunk (tech-debt, "AH-104b-del"):** a cookie session created in the same instant as the request can survive it until the erasure.

**Part B: what the person and the agency see (built, local, not deployed).**

- **Where a creator asks.** The Security page (`/creator/security`, route `creator.security`) holds the "Delete account" entry and the dialog: a plain statement of what is lost and the 14-day period, the
  password, the second factor when it is set, a checkbox, and the typed word (a usability gate only; diacritics are ignored, and it is **not** a security control). 429 shows a client countdown. The entry is
  shown only when `features.account_deletion` on `/me` is true (creator-only, and the same value as the flag); with the flag off the route guard `requireAccountDeletionEnabled` sends the person to the
  dashboard and the endpoint answers 404.
- **Public page.** `/delete-account` (no sign-in, outside `/creator/`) explains the in-app way to ask, the support address for a person who cannot sign in, what is erased and what is kept, and the 14-day period. It
  is static (it does not read the flag), so it can be linked from the store listing before the flag is on.
- **The agency side.** A creator with `deletion_requested_at` set is **hidden** from every agency surface that lists or reaches out (`Creator::scopeVisibleToAgencies()`): discovery, the new-conversation list,
  invites, pool adds, the job-posted fan-out, the digest and the nudge. A **new** agency action on a pending or an erased creator (invite, connection request, pool add, accepted application, new thread) is refused
  with **422 `creator.unavailable`**, one answer for both states so the response never says which. Existing threads and assignments remain **writable but silent**: nothing is sent to the pending creator.
  `tests/Support/AgencyCreatorSurfaces.php` is a static manifest of every agency surface that reads a creator; a spec fails when a surface is not classified.
- **After erasure.** Agency payloads carry `is_erased: true` (trait `EmitsErasedState`) and no name or avatar; the apps render "Deleted creator" and a neutral avatar through one reader, `creatorLabel()`
  (an architecture spec forbids reading `display_name` or `avatar_url` in the agency modules). Each agency's admins and managers get **one** in-app notice per cancelled assignment
  (`assignment.cancelled`, reason `creator_account_erased`): always on, no preference, no mail, no push, and it **never names the creator**.
- **Not changed by design.** Assignment-lifecycle, contract and verification notices to a pending creator are not silenced (they concern work the creator already holds). Mail ignores notification preferences
  (the confirmation is a transactional message).
  **AH-104c C1: the claim, the narrowed writable rule and the delivered-work refusal (built, local, not deployed; no route reaches any of it yet).**

- **The claim.** An erasure begins by **claiming** the account: `users.erasure_started_at`, stamped only by `ErasureClaimer` (an architecture spec forbids any other writer), in one transaction with the
  user row locked, after it re-reads and passes every leg of the gate, in order: the account is an erasable creator; it is **not held** (`erasure_held_at`); in the normal mode a request stands **and its
  grace has ended** (no early erasure; the 14 days are a promise); no guard refuses (payment, delivered work, vendor). A refusal writes nothing, not even an audit row. The stamp writes
  `account.erasure_started` with a reason and the actor (the operator, or null for the system). `--force-not-requested` (the shell only) skips "not requested" and "not due", needs an operator and a reason, and
  **never passes a hold or a guard**.
- **From the claim the creator cannot cancel and cannot sign in.** Sign-in answers as for an unknown address, **before** the password check, with the audit reason `unknown_email` (the same branch as an erased
  account, so no response, status or reason separates them); `cancel()` returns false; a new request is "not available". Cancelling stays possible until the claim, not until `erased_at`: the run deletes the
  files before its database transaction, so a lock inside that transaction would come too late. A run that fails after its first irreversible step **stays claimed** and is retried; only a refusal or a moved
  digest before the run began releases the claim (`account.erasure_claim_released`, cause code only), and `release()` refuses once any ledger row exists for the account.
- **Nobody is signed in as an account that is leaving.** Impersonation `start()` and `claim()` refuse a target that is pending, claimed or erased (422 `admin.impersonation.target_unavailable`, no session row, no
  start audit row). The admin search still lists them.
- **"Assignments stay writable", narrowed.** The one new refusal on an existing assignment: an agency cannot run a **new live verification** for a pending (or erased) creator. The manual verify answers the neutral
  422 `creator.unavailable` and writes nothing; the queued `VerifyPostedContentJob` returns before the provider call and leaves the post `pending`; when the creator cancels, `AccountDeletionCancelled`
  (dispatched after commit, only by the call that cancelled) re-runs the held-back checks. Every other action on an existing thread or assignment is unchanged and silent. A manifest spec lists every caller
  of `verifyLive` and `manuallyVerify`, so a new one fails until it asks the same question.
- **Delivered work refuses erasure.** An assignment in a payment-eligible state (`live_verified`, `manually_verified`, `completed_on_approval`; read from `AssignmentStatus::isPaymentEligible()`, so a state
  added later refuses by default; soft-deleted rows count) is an **erasure refusal** like the payment states: in the claim, the dry-run, the request (422 `account_deletion.blocked`, reason `delivered_work`) and
  the rider, which no longer cancels such an assignment. The message names the table and the states, never a person. A total spec classifies every `AssignmentStatus`.
- **Audit.** Two new verbs, `account.erasure_started` (reason required) and `account.erasure_claim_released`. No spec attaches a retention class to a verb, so none was needed; both are in the pinned list.
- **Registry.** The three new `users` columns are `KEEP` (timestamps and an **admin** id, never the subject's data); the hold reason is in the audit row only and must carry no personal details.
- **Not built in C1:** the hold and release endpoints, the queue, the Erase button (C2 and C3). Until they exist the claim is reachable only from `erasure:run`.

### 3.4 Append-only enforcement

- **Target (not yet true in production):** the application's database role has INSERT and SELECT on `audit_logs`, but NO UPDATE or DELETE, and a separate "audit retention" role has DELETE permission, used only by the scheduled retention job.
- **Actual, as read from production on 2026-10-20 (AH-104a read-back C):** web, worker and migrations all connect as one role, `engine_c_user`, which holds `SELECT, INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER` on `audit_logs` (table and every column), with no triggers, rules or row-level security. **`audit_logs` is append-only by the model and by convention only, not by grant.** There is no audit-retention role. The least-privilege role split is a post-launch task for the infrastructure owner (see `tech-debt.md`, "audit_logs is not append-only in production").
- **What stands in for the grant today:** the `AuditLog` model throws on `update()`, `save()` of an existing row and `delete()`; and the only code that writes to the table after insert is the erasure audit scrub (`app/Modules/Erasure/Execution/DatabaseAuditScrub.php`), which can only set `ip`, `user_agent`, `metadata`, `before` and `after`, refuses any other column before a statement is sent, and is the only file under `app/` an architecture spec allows to update or delete the table. This narrows what the application's own code can do; it does not stop a person or a SQL injection using the same role.
- Periodic checksum verification (Phase 2+): a hash of each entry is computed and stored in a separate hash chain table. Tampering is detectable.

### 3.5 Storage and retention

- **Hot storage:** PostgreSQL `audit_logs` table for the most recent 12 months.
- **Cold storage:** Older entries archived to S3 Glacier in Parquet format, accessed for compliance queries only.
- **Compliance retention:** 7 years for financial-related actions; 3 years for general actions; legal hold overrides retention.
- Phase 2 introduces table partitioning by month for performance.
- **Status as built (2026-10-20, read-back D):** none of the above exists yet. There is no cold storage, no archive, no retention job and no audit export; `audit_logs` is one Postgres table (38 MB at the read-back) that is never trimmed. The only copies of an audit row are the table, the database snapshots (RDS automated, 7-day retention) and nothing else.

### 3.6 Admin actions on production

Admin SPA actions on production data are doubly logged:

1. To the standard `audit_logs` table (queryable from admin SPA).
2. To a dedicated S3 audit bucket with object-lock (compliance mode, immutable for retention period).

This double logging means even if the database `audit_logs` table is somehow compromised, the S3 record stands.

> **Status as built (2026-10-20, read-back D):** there is no S3 audit bucket and no object lock; admin actions are logged to `audit_logs` only, and the application role can update and delete that table (§3.4). Nothing in this section is true in production yet.

### 3.7 Querying audits

- Admin SPA includes a full audit log viewer with filtering by actor, action, subject, date range, agency.
- Users can request their own audit history (for actions they took or that affected them) via `/api/v1/me/audit-history`.
- Queries are paginated. Large queries run as background jobs.

---

## 4. Encryption

### 4.1 Encryption at rest

| Data              | Method                                                              |
| ----------------- | ------------------------------------------------------------------- |
| RDS PostgreSQL    | AWS-managed KMS key (Phase 1) → customer-managed KMS key (Phase 2+) |
| ElastiCache Redis | Encryption at rest enabled                                          |
| S3 buckets        | SSE-S3 (AES-256) default; SSE-KMS for sensitive buckets             |
| EBS volumes       | Encrypted with default KMS key                                      |
| RDS snapshots     | Encrypted (inherited from source)                                   |

### 4.2 Encryption in transit

- TLS 1.2+ everywhere. TLS 1.3 preferred where supported.
- HTTP automatically redirects to HTTPS.
- HSTS header with `max-age=31536000; includeSubDomains; preload`.
- HSTS preload submission target: production domains added to Chromium preload list.
- Internal AWS service-to-service traffic uses TLS where supported.
- Database connections from app to RDS use TLS with cert verification.
- Redis connections use TLS in production.

### 4.3 Application-layer encryption

Some fields require an extra layer of encryption (encrypted in the database, decrypted only when actively used). Implemented via Laravel's `encrypted` cast:

- `users.two_factor_secret`
- `users.two_factor_recovery_codes`
- `creator_social_accounts.oauth_access_token`
- `creator_social_accounts.oauth_refresh_token`
- `creator_tax_profiles.legal_name`
- `creator_tax_profiles.tax_id`
- `creator_tax_profiles.address`
- `creator_kyc_verifications.decision_data`
- `integration_credentials.credentials`

The Laravel `APP_KEY` is the encryption master. It is stored in AWS Secrets Manager. **Rotation procedure** is documented in `docs/runbooks/key-rotation.md`.

### 4.4 Key management

- All AWS KMS keys defined in Terraform.
- Key rotation: AWS-managed keys rotate annually automatically; customer-managed keys have explicit rotation policy.
- Application encryption key (`APP_KEY`): rotation requires a re-encryption migration. Documented procedure exists. Phase 1 doesn't pre-rotate; Phase 2 establishes a 2-year rotation cadence.

---

## 5. Secrets management

### 5.1 Where secrets live

- **Production:** AWS Secrets Manager. Loaded into Laravel at boot via a custom config loader.
- **Staging:** AWS Secrets Manager (separate secrets, separate access).
- **Local development:** `.env` file (gitignored). `.env.example` is committed with placeholders.

### 5.2 What counts as a secret

- Database credentials
- Redis password
- Application encryption key (`APP_KEY`)
- API keys and OAuth secrets for every third-party integration
- Webhook signing secrets
- JWT signing keys (if used)
- AWS access keys (use IAM roles preferred; keys only for legacy)
- Cookie encryption keys

### 5.3 What MUST NOT be in code

- Hard-coded secrets, even in tests
- Secrets in environment variable defaults in code
- Secrets in commit history (use BFG or git-filter-repo if a secret is leaked; rotate immediately regardless)
- Secrets in CI logs

### 5.4 Secret hygiene

- Secrets rotate on a schedule (annually minimum; immediately if compromised).
- Pre-commit hook (`gitleaks` or similar) scans for accidentally committed secrets.
- CI pipeline fails on detected secrets.
- Sentry, CloudWatch, etc. configured to redact known secret patterns.

---

## 6. Authentication security

### 6.1 Password requirements

- Minimum 12 characters.
- No maximum length (within reason — 128 char hash input limit).
- Hashed with Argon2id (Laravel default).
- Breach-checked at signup and password change via HaveIBeenPwned k-anonymity API. If a password is found in known breaches, registration/change is rejected with a specific error code.
- No password complexity rules (NIST guidance: length over complexity).
- No forced periodic rotation (NIST guidance: rotation on suspicion only).

### 6.2 Brute-force protection

- Rate limiting on `/api/v1/auth/login`: 10 attempts per minute per IP, 5 per minute per email.
- After 5 failed attempts on the same account: account is temporarily locked for 15 minutes.
- After 10 failed attempts within 24 hours: account is locked until password reset or admin intervention.
- All login attempts (success and failure) are audit-logged.

### 6.3 Two-factor authentication

- TOTP via authenticator apps (Google Authenticator, 1Password, Authy).
- Recovery codes generated at enrollment, one-time use, replaceable.
- Admin users: 2FA mandatory.
- Agency admin role: 2FA strongly encouraged via warning banner; mandatory in Phase 2.
- All other users: 2FA optional.
- 2FA enrollment, change, and disablement audit-logged.

### 6.4 Session management

- Session cookie attributes: `Secure`, `HttpOnly`, `SameSite=Lax`.
- Session lifetime: 14 days for main app, 8 hours absolute / 30 minutes idle for admin.
- Session invalidation on password change.
- "Sign out everywhere" feature available in user settings.
- Session storage in Redis with encryption.

### 6.5 Email verification

- Email verification required before access to most features.
- Verification link is single-use, valid for 24 hours.
- Verification link contains a signed token (HMAC), not predictable.

### 6.6 Account recovery

- Password reset via email link.
- Link contains a signed token (HMAC, 1-hour expiry).
- Successful reset invalidates all existing sessions.
- 2FA recovery via recovery codes; if codes are lost, manual identity verification process via support.

---

## 7. Authorization security

- Authorization checked on every endpoint. No "hidden" or "convenience" endpoints that skip authorization.
- Cross-tenant access is impossible via the API. Tenancy is enforced at the route resolver, query scope, and policy layers.
- Admin SPA routes require `platform_admin` user type, verified at every request.
- Privilege escalation prevention: a user cannot grant a role higher than their own. An agency_manager cannot make someone an agency_admin.
- Every policy has cross-tenant test coverage: a user from agency A trying to access agency B resources receives 404 (not 403, to avoid leaking existence).

---

## 8. Input validation & output encoding

### 8.1 Input validation

- Every endpoint uses a Form Request class.
- Validation is exhaustive: every field, every constraint.
- Custom validation rules go in `app/Core/Validation/Rules/`.
- File uploads validated for: MIME type, file size, content (magic bytes match claimed type), structural validity.
- URL fields validated for protocol (only `https`) and TLD existence where appropriate.
- JSON fields have schema validation; arbitrary JSON is not accepted.

### 8.2 Output encoding

- Vue templates auto-escape interpolations (`{{ }}`).
- `v-html` is **forbidden** with user-supplied content. The linter rejects it.
- API responses serialize via Resource classes; raw model arrays are not exposed.
- Markdown rendering (creator bios, contract bodies) uses an allow-list HTML sanitizer (e.g., `bleach` or DOMPurify). Strips scripts, iframes, event handlers.

### 8.3 SQL injection

- Eloquent ORM exclusively for queries. Raw queries only via parameterized bindings.
- `DB::raw()` allowed only with literal strings, never with user input.
- ORM relationships are typed (no string-based dynamic property access).

### 8.4 Mass assignment

- Every Eloquent model has explicit `$fillable`. `$guarded = []` is forbidden.
- Form Requests enforce that only allowed fields reach the service layer.

---

## 9. CSRF, CORS, headers

### 9.1 CSRF

- Sanctum SPA auth uses CSRF tokens via the `XSRF-TOKEN` cookie / `X-XSRF-TOKEN` header pattern.
- All state-changing endpoints require valid CSRF token on cookie auth.
- Token-based auth (mobile, public API) does not require CSRF (tokens are not auto-submitted).

### 9.2 CORS

- CORS allowed only for the SPA origins:
  - `https://app.catalyst-engine.com`
  - `https://admin.catalyst-engine.com`
  - Local dev origins (configured per environment)
- `Access-Control-Allow-Credentials: true` enabled (cookies cross-origin between API and SPA on same parent domain).
- Wildcard origins are forbidden in production.

### 9.3 Security headers

Every response includes:

```
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=()
Content-Security-Policy: ...   (strict CSP, see below)
```

### 9.4 Content Security Policy

```
default-src 'self';
script-src 'self' 'nonce-{request-nonce}';
style-src 'self' 'unsafe-inline';   (Vuetify needs inline styles; nonce-based later)
font-src 'self';
img-src 'self' data: https://*.cloudfront.net https://*.s3.eu-central-1.amazonaws.com;
connect-src 'self' https://api.catalyst-engine.com wss://...;
frame-ancestors 'none';
form-action 'self';
base-uri 'self';
```

CSP violations are reported to a `/api/v1/csp-report` endpoint (Phase 2) for monitoring.

---

## 10. File upload security

### 10.1 Validation

- File size limit per file type (avatars: 5MB; portfolio video: 500MB; etc.)
- MIME type validated server-side (claimed type vs. actual content via magic bytes).
- Filename sanitized (no path traversal characters; controlled charset).
- Files stored in user-scoped paths: `creators/{ulid}/portfolio/{file_ulid}.mp4`.

### 10.2 Storage

- Private buckets by default; signed URLs for access (1 hour expiry).
- Public bucket only for genuinely public content (public profile photos when explicitly opted in).
- No directory listing on any bucket.
- Bucket policies block public ACLs.

### 10.3 Virus scanning

- Phase 1: not implemented (acknowledged risk for an internal-tool MVP).
- Phase 2: ClamAV scanning in a dedicated worker on every upload. Files quarantined until clean.
- Phase 2+: integration with VirusTotal API for high-risk uploads.

### 10.4 Image processing

- Profile photos and other display images are re-encoded server-side via a trusted library (Intervention Image). This strips EXIF data and removes any embedded scripts/payloads.
- SVG uploads are not allowed (XSS risk via embedded scripts) — except by admins, with sanitization.

---

## 11. Third-party security

### 11.1 Dependencies

- Dependabot enabled, weekly security patches.
- Critical CVEs patched within 72 hours of disclosure.
- High CVEs patched within 1 week.
- `composer audit` and `pnpm audit` run in CI. PR fails on high+ vulnerabilities.

### 11.2 External APIs

- All outbound HTTP via a wrapped client that enforces:
  - TLS verification
  - Reasonable timeouts (10s default, 30s for known slow endpoints)
  - Retry with exponential backoff
  - Circuit breaking on repeated failures
- Webhook signatures verified on every inbound webhook.

### 11.3 Subresource integrity

- Frontend assets self-hosted; no external CDN scripts in production.
- If any third-party script is loaded (analytics), it has SRI hash.

---

## 12. Threat model (Phase 1)

The realistic threat actors and their goals:

### 12.1 External attackers

- **Scrapers** trying to harvest creator profiles.
  - Mitigation: rate limiting, public profile data minimized, scraping detection.
- **Credential stuffing** against agency accounts.
  - Mitigation: rate limiting, breached password check, 2FA for high-value accounts, anomaly detection.
- **Payment fraud** trying to get money out.
  - Mitigation: Stripe Connect verification, KYC for creators, escrow holds, dispute flow.
- **Phishing** users to take over accounts.
  - Mitigation: 2FA, anti-phishing email DMARC/DKIM/SPF, session anomaly detection.

### 12.2 Insider threats

- **Malicious agency staff** trying to scrape competitor data via cross-tenant access.
  - Mitigation: tenancy enforcement at every layer, audit logging of all access, anomaly detection on cross-tenant queries.
- **Compromised agency accounts** used to mass-message creators.
  - Mitigation: rate limiting on messaging, anomaly detection, 2FA on admin roles.
- **Catalyst Engine ops staff** with too-broad access.
  - Mitigation: scoped admin roles (Phase 2), all admin actions audited, impersonation logged with reason.

### 12.3 Third-party compromise

- **Stripe / KYC / e-sign vendor compromise** affecting integration.
  - Mitigation: webhook signature verification, idempotency, no long-lived secrets, vendor monitoring.
- **AWS account compromise.**
  - Mitigation: root account locked down, all access via IAM Identity Center, MFA required, CloudTrail enabled.

### 12.4 Supply chain attacks

- **Compromised npm/composer package** introducing backdoor.
  - Mitigation: dependency pinning, lockfile in repo, audit on every install, security scanning, minimal dependency surface.

---

## 13. Security review checklist (per PR)

Cursor includes this in every PR description. Each item answered yes/no/n-a:

```
Security review:
- [ ] Authentication required for all new endpoints (or N/A: explicitly public)
- [ ] Authorization policy in place and tested
- [ ] Input validated via Form Request
- [ ] Output via API Resource (no raw model serialization)
- [ ] User-supplied content properly escaped/sanitized
- [ ] No new secrets hardcoded; all configurable
- [ ] No new logging of sensitive data
- [ ] Rate limiting applied if new endpoint exposed
- [ ] Cross-tenant access tested if tenant-scoped
- [ ] Audit logging in place for state changes
- [ ] Reason field enforced for destructive actions
- [ ] File uploads validated if applicable
- [ ] CORS reviewed if new origin exposed
- [ ] CSP reviewed if new external resource added
```

---

## 14. Compliance roadmap

| Phase | Compliance milestone                                                                                                                         |
| ----- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| P1    | SOC 2 Type 1 readiness work begins. GDPR baseline implemented. DPAs in place with all processors. Privacy policy, terms, cookie policy live. |
| P2    | SOC 2 Type 1 audit and certification. Penetration testing (annual). EU-only data residency formalized. Subprocessor list public.             |
| P3    | SOC 2 Type 2 first audit period begins. ISO 27001 gap analysis. Bug bounty program (private).                                                |
| P4    | ISO 27001 certified. SOC 2 Type 2 ongoing. Bug bounty public. Regional certifications (LGPD if Brazil expansion).                            |

---

## 15. Vulnerability disclosure

Phase 1 has a `security.txt` file at `https://catalyst-engine.com/.well-known/security.txt` with:

- Contact email (`security@catalyst-engine.com`)
- Disclosure policy
- Acknowledgments page

Phase 3+ formalizes a bug bounty via HackerOne or Intigriti.

---

## 16. Logging hygiene (what NOT to log)

Application logs MUST NOT contain:

- Passwords (cleartext or hashed)
- Session tokens, API tokens, JWT tokens
- 2FA secrets or recovery codes
- Full credit card numbers
- Government ID numbers (full)
- Bank account numbers (full)
- KYC document content
- OAuth access/refresh tokens
- Email body contents (subject and addresses ok)

Laravel's logger and Sentry SDK are configured to redact known sensitive patterns. Cursor never adds `Log::info($creditCard)` or similar; reviews catch this.

When debugging requires logging sensitive data, use a temporary local debugger, not production logs.

---

## 17. Incident response (Phase 1 baseline)

A minimal incident response process exists from Phase 1:

1. **Detection** — alerting via Sentry, CloudWatch, or user report.
2. **Triage** — solo developer assesses severity (P0: data breach / total outage; P1: significant degradation; P2: minor).
3. **Containment** — feature flag off, rollback, IP block, account lock as appropriate.
4. **Eradication** — fix the root cause.
5. **Recovery** — restore normal operation.
6. **Post-incident review** — document what happened, what was missed, what changes follow.
7. **Disclosure** — if personal data involved: GDPR Article 33 (regulator within 72h), Article 34 (users if high risk).

Phase 2 expands to a formal IR runbook with on-call rotations.

---

## 18. Penetration testing

- **Phase 1:** internal security review by founder + automated scanning (Snyk, semgrep).
- **Phase 2:** annual external penetration test before SOC 2 audit. Report findings tracked to closure.
- **Phase 3+:** semi-annual pen tests + bug bounty.

---

**End of security & compliance. Apply this to every feature, every PR.**
