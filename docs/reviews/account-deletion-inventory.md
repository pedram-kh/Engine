# AH-104 — Account deletion: inventory

- **Status:** **Inventory ACCEPTED (2026-10-20).** Rulings on its open questions, the AH-104a/b-del/c/d/e split and the approved PII map are in
  [`account-deletion-pii-map.md`](account-deletion-pii-map.md) and the AH-104 entry of `adhoc-changes-log.md`; where this file and the map differ
  (§5.1 tax/KYC, §5.4 audit), **the map wins**. Written read-only; no code. Mode A (schema, API shape, policy, state-machine edge, scheduled job, security).
- **Written at:** code `d1ad22d8` plus the docs commit `9adc60e0`. Repo claims below are cited `path:line`; store-policy claims are **from
  memory and unverified** (marked), to be checked against Apple's and Google's current text at plan time or in AH-105.
- **Builds on, and re-verifies:** [`creator-app-inventory.md`](creator-app-inventory.md) M5 (2026-10-05, "The account-deletion gap"),
  `05-SECURITY-COMPLIANCE.md` §2.3-2.5 and §3.4-3.5, [`push-notifications-inventory.md`](push-notifications-inventory.md) (the push seam).
  §2 lists what has changed since M5.
- **Neighbours (so nothing collides):** **AH-103d** (reinstall marker) is app-only and separate. The **Security page** (ruled web AND native, under the creator account menu) is built
  _inside_ AH-104 (as AH-104b-del) ([`biometrics-inventory.md`](biometrics-inventory.md) §10). **AH-104d** (in-app sign-up, formerly AH-104b) is gated on AH-104. **AH-105**
  (privacy checklist) consumes this inventory's retention table. **Biometrics** (AH-103c) are deferred and never stand in for the re-auth
  in §4.

## 1. What exists today (verified)

- **No self-deletion in any form.** No delete or erase route on `me` (`Identity/Routes/api.php` has `GET me` at :60 and `PATCH me` at :69 and
  no delete), no erasure job, no SPA string or route. `docs/05-SECURITY-COMPLIANCE.md:53` specs `/api/v1/me/data-erasure` and
  `ExecuteDataErasureJob`; neither exists.
- **An admin shell exists and is empty.** `AdminComplianceController::erasures()` returns `data: []` with `meta.shell: true`
  (`apps/api/app/Modules/Admin/Http/Controllers/AdminComplianceController.php`; routes `Admin/Routes/api.php:168-175`). Its docblock says the
  tables `data_export_requests` / `data_erasure_requests` and the actioning verbs were meant for "Sprint 14". Neither table exists (no
  migration among the 82).
- **Two seams are ready.** `PushTokenRegistry::revokeAllForUser(User): int` (`Notifications/Push/PushTokenRegistry.php:105`, commented
  "AH-104 SEAM", wired to nothing). Audit vocabulary `user.deleted` and `creator.deleted` (`Audit/Enums/AuditAction.php:54, 88`), with a
  mandatory reason for `user.deleted` (`:509`).
- **Account creation is web-only today.** The native app has no create-account button; it points to the website (AH-100 Q8, see
  `creator-app-client-shell-review.md` ~:142). In-app sign-up is AH-104d (formerly AH-104b; the quoted review keeps the old name), "gated on AH-104's in-app deletion".
- **The payments ledger does not exist.** `app/Modules/Payments/` holds only `.gitkeep` files and a provider class; the assignment
  states `payment_held` and `payment_released` exist in the state machine but both transitions refuse (vendor-gated, Sprint 10,
  `CampaignAssignmentStateMachine.php` ~:577-590). `payment_processing_enabled`, `creator_payout_method_enabled`,
  `kyc_verification_enabled` and `contract_signing_enabled` default off (`docs/feature-flags.md`). **Whether any is on in production is not
  known to me.** So "payments" is a _forward-looking constraint_ for AH-104, not today's data.

## 2. What changed since M5 (2026-10-05)

- New tables with a user edge: `user_push_tokens` (FK to `users`, cascade) and `push_deliveries` (FK to `users`, cascade;
  90-day prune, `PrunePushDeliveries` daily, `bootstrap/app.php:56`). Both cascade only on a _hard_ delete, and `User` is soft-deleted
  (`User.php:69`), so neither fires on a soft-delete.
- `campaign_applications.creator_id` is **restrict** (M5.2 already lists it). `agency_files.uploaded_by_user_id` and
  `campaign_attachments.attached_by_user_id` are _agency-side_ edges, not creator data.
- **Denormalised copies of the creator's name were not in M5** (§5.4): `notifications.data` and the audit `before`/`after` JSON.
- The mobile app now exists, so a deleted creator also has a **device-side** state to clear (§6).

## 3. Store policy (from memory; verify before relying on it)

**Apple, App Store Review Guideline 5.1.1(v).** _(From memory.)_ An app that supports account creation must let the user initiate deletion
**within the app**. Deactivation or "contact support" alone is not enough (narrow exceptions for highly regulated industries do not fit
us). Apple allows confirmation steps and re-authentication, and allows retaining data that law requires if the user is told. Apple does not
require instant erasure; a disclosed timeline is the part I am least sure of.

**Google Play, account deletion (User Data policy).** _(From memory.)_ An app that lets users create an account **in the app** must offer an
in-app path to request deletion **and** a **web link** (declared in the Play Console Data safety form) where a user can request deletion
without installing the app. The page must say which data is deleted and which is kept and for how long. Temporary deactivation does not count.

**How it applies to us.**

- Strictly both rules key on _in-app account creation_, and the app has none until AH-104d. The team already ruled (AH-100 Q8) to couple
  in-app sign-up to deletion, and a reviewer can still ask where deletion is for a sign-in-only app that links to web sign-up. **Treat AH-104
  as a precondition for the first store build**, not only for AH-104d.
- **The web path is a hard requirement for Google and for GDPR art. 17** (`05-SECURITY-COMPLIANCE.md:53`). Web creators use the same SPA, so the
  same flow serves both; a public, unauthenticated page describing the process is the URL Google wants. This **conflicts with the "native-only
  Security page"** ruling if that page is deletion's only home (see open question 1).
- Android has no release keystore and no Play listing yet, so Google's rule is a _later_ gate; Apple's is the near one.

## 4. Re-authentication (password + 2FA)

**The pattern exists.** `DisableTwoFactorController` (`Identity/Http/Controllers/DisableTwoFactorController.php`) requires the current
password **and** a working 2FA code, checks both without short-circuiting (`:53-54`), and returns one undifferentiated 401
`auth.mfa.invalid_code`. On the main origin it is mounted `auth:web,creator_app` (`Identity/Routes/api.php:130-132`), so a creator's bearer token reaches it
(the `:208-214` set is the admin guard). `sprint-1-chunk-5-review.md:78` already says future sensitive mutations, "e.g. delete account", should reuse it.

**What does not carry over as is.**

- Disable-2FA **requires 2FA to be enabled** (409 otherwise). Deletion must also work for a creator **without** 2FA: **password always, a
  TOTP or recovery code additionally when `hasTwoFactorEnabled()`** (`User.php:225`). A creator can enrol (the AH-099 Q5 swap) but is not
  forced to, so most creators will be password-only.
- **No lockout on the password check.** The disable endpoint's password comparison has no failure counter (only the 2FA challenge has the
  soft rate limit and enrolment suspension). `AccountLockoutService` is **email-keyed for sign-in** (`:47-61`) and `escalate()` suspends the
  user (`:70`). An endpoint that deletes an account on a stolen bearer token is a password oracle unless it carries its own throttle
  (the existing named limiters are `auth-ip`, `auth-login-email`, `auth-password`, `IdentityServiceProvider.php:105-123`) and counts failures.
  **A new limiter and a decision about escalation are part of the plan.**
- **Typed confirmation** ("type DELETE", or the creator's email) is a UI step on top, not a substitute.
- **Biometrics must never replace these** (restated from the AH-103c boundary). A live bearer token proves "this device", not "this person".
- **Impersonation must be refused.** An impersonating admin has no password (AH-099 note), so the password step blocks it; add an explicit
  negative anyway (§5.34 over-reach set).
- **Mail notice:** a "your deletion was requested / completed" email to the **pre-anonymisation** address is the user's only out-of-band
  tripwire against a hijacked session. It must be queued before the address is rewritten (a new mailable, ×24 locales, flag-gated per the
  standing "new emails ship OFF" rule).

## 5. What gets erased, anonymised or retained

The base is M5.2's table, re-verified above. Rows marked **(new)** are additions. Legend: **E** erase, **A** anonymise in place,
**R** retain (with the reason to disclose), **T** tension needing a ruling.

### 5.1 Identity and the creator's own profile

| Data                                                                                                                                                    | Proposal                                                                                                                                       |
| ------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| `users` row (email, name, last_name, password, 2FA secret and recovery codes, last_login_ip, locale, etc.)                                              | **A**: email → `deleted-{ulid}@deleted.local`, name → a fixed marker, secrets nulled, login blocked (`05-SECURITY:64`). Row stays.             |
| `creators` plaintext PII (display name, bio, phone, WhatsApp, address, date of birth, content companions)                                               | **A/E**: null or replace in place (`Creator.php:41-45, 116-122`).                                                                              |
| `creator_tax_profiles` (legal name, tax ID, address, encrypted: `CreatorTaxProfile.php:121-122`), `creator_payout_methods`, `creator_kyc_verifications` | **E** the row; **T** where tax retention applies (`05-SECURITY:65`). Vendor side (Stripe Connect account, KYC vendor) is a separate act, §5.6. |
| `creator_social_accounts`                                                                                                                               | **E** + revoke provider tokens (`05-SECURITY:66`). Social drivers are `mock` by default; production driver unknown.                            |
| `creator_portfolio_items`, avatar, availability blocks                                                                                                  | **E** rows + S3 objects (§5.5).                                                                                                                |
| `notifications` the creator received; `user_notification_preferences`; `message_read_receipts` (both kinds); `message_email_debounces`                  | **E** (the creator's own feed and settings).                                                                                                   |
| `sessions`, `personal_access_tokens`, `password_reset_tokens`, `user_push_tokens`, `push_deliveries` **(new)**                                          | **E** (§6). `push_deliveries` would age out in 90 days anyway; delete by `user_id` so no row outlives the request.                             |

### 5.2 Assignments and applications (the agency's commercial record)

- `campaign_assignments.creator_id` and `campaign_applications.creator_id` are **restrict**; the assignment is "the commercial anchor"
  and the future payment hangs on it (M5.2). **R**, with the creator shown as deleted (§7). Fees and terms are the agency's record.
- **Non-terminal assignments are the live problem.** The states are `invited … producing … draft_submitted … posted … live_verified …
payment_held`, terminals `declined`, `rejected`, `payment_released`, `cancelled` (`AssignmentStatus.php:73-89`; the machine comment says
  "any non-terminal → cancelled"). A creator who deletes mid-campaign leaves an agency waiting on a person who no longer exists. Options:
  **(a)** block deletion until none is open (a creator can be stuck, and Apple will not like a deletion that cannot be completed);
  **(b)** **auto-cancel open assignments as part of the request**, through the existing `cancelled` edge, with `cancelled_by_user_id` and the
  mandatory reason, and tell the agency; **(c)** let them sit.
  **Recommend (b)**, with the agency told in-app, flag-gated mail per the standing rule. Stop-gate items: it uses a **state-machine edge**
  and sends **notifications to real users**.
- **Payments, when Sprint 10 lands:** an assignment in `payment_held` has money in escrow, so (b) is no longer safe for it and the rule
  must become "block, or refund through the ledger first". **AH-104 should ship an invariant or a spec that fails when a payment state is
  introduced without that decision**, so Sprint 10 cannot quietly make deletion unsafe.
- `campaign_drafts` (`submitted_by_creator_id` set null) and its media (JSON + S3 under `creators/{ulid}/drafts/`): the agency approved
  it and may hold usage rights. **T**: keep the record, decide about the media (open question 6).
- `contracts` (`signed_by_creator_id` set null; signed PDFs; `signed_signature_data` encrypted, carrying IP and user agent): **R**, a signed
  agreement and a legal record. **T** on the encrypted signing metadata.

### 5.3 Messages

- `relationship_messages` and `messages` have `sender_user_id` **restrict**, `SoftDeletes` present but unwritten (`RelationshipMessage.php:29`,
  `Message.php:31`). They are the **agency's conversation record** as much as the creator's words.
- Options: **(a)** keep bodies and attachments, sender shown as deleted; **(b)** keep text, **erase attachments** (the creator's uploaded media
  under `messages/{thread_ulid}/…` and `relationship-messages/{thread_ulid}/…`, shared prefixes so per-object deletes, M5.2); **(c)** blank
  the creator's own bodies ("message removed"). GDPR art. 17(3) exceptions and the counterparty's interest argue for (a) or (b);
  (c) is the most protective of the creator and the most destructive of the agency's record. **Recommend (b)**, disclosed. A ruling, not a
  default. The data model survives all three.

### 5.4 Audit logs and denormalised copies (the part M5 under-stated)

- `audit_logs` is append-only: the model throws on update and delete (`Audit/Models/AuditLog.php:117, 127, 138`), the migration has no
  `updated_at` or `deleted_at`, and `05-SECURITY:198` specs a DB role with INSERT and SELECT only.
- **The spec's claim that audit "retains `actor_id` but the actor's identity is anonymized" (`05-SECURITY:68`) is only true for the _join_.**
  The rows themselves carry personal data that anonymising `users` does not reach:
  - `ip` and `user_agent` on every row (`…create_audit_logs_table.php:60-61`).
  - `before` / `after` JSON snapshots whose allowlist includes the user's **`email`, `name`, `last_name` and `last_login_ip`**
    (`User.php:148-158`) and the creator's `display_name` (`Creator.php`, allowlist).
- `notifications.data` holds **frozen copies of names**: `creator_name` (`RelationshipMessageNotifications.php:63`,
  `CampaignApplicationNotifier.php:117`) and `sender_name` (`SendMessageNotifications.php:64`, `RelationshipMessageNotifications.php:64`).
  These sit on the **agency's** notification rows, which §5.1 does not erase. `NotificationResource.php:60` also reads `actor->name` live.
- **Retention is stated, not implemented.** `05-SECURITY:204-206` says 12 months hot, then Glacier, 3 years for general actions and 7 for
  financial ones. I found **no audit prune or retention job** (no `PrunableAudit`, no scheduled command; the schedule I read lists four commands,
  `bootstrap/app.php:35-56`). So today the audit copies of a deleted creator's email, name and IP **never expire**.
- This is a real conflict between two documented rules (append-only and erasure). Options: **(a)** treat audit rows as **retained under a
  legitimate-interest or legal-claims basis**, say so in the privacy notice, and bound it by implementing the 3-year retention;
  **(b)** a privileged, audited **scrub command** that nulls `ip`, `user_agent` and the PII keys in `before`/`after` for the subject, under
  a separate DB role (the same "audit retention role" the spec already imagines); **(c)** stop writing PII into new audit snapshots (drop
  `email`, `name`, `last_name`, `last_login_ip` from the allowlists) and leave history. **Recommend (a) + (c) now, (b) only if counsel
  asks.** Engineering review is not legal review (`WORKING-PROCESS` §7); this needs a human decision.
- `notifications.data` copies are ordinary mutable rows: **A** by scrubbing the name keys in the agency's rows that reference the deleted user,
  or **R** with a disclosed reason. The scrub is a guarded, dry-runnable command (`WORKING-PROCESS` §5).

### 5.5 Files (S3)

Avatar `creators/{ulid}/avatar/…`, portfolio `creators/{ulid}/portfolio/…`, draft media `creators/{ulid}/drafts/…`, message attachments
(per object, shared prefixes), contract PDFs (`contracts.body_pdf_path`), KYC documents if a real vendor stores any. All through the
app's disk abstraction, **not** by prefix where the prefix is shared. **Backups:** RDS snapshots and PITR hold the pre-deletion state for
their retention period. The standing "backup restore is unrehearsed" item makes this sharper: a restore resurrects an erased creator unless an
**erasure ledger** (the request id, user id, completion time, nothing else) can be re-applied. Plan for that ledger, and state the snapshot
retention in the privacy notice.

### 5.6 Third parties

| Party                                   | Today                                                                                                                                                                      |
| --------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Stripe Connect (payout account)         | Real adapter exists (`Creators/Integrations/Stripe/StripePaymentProvider.php`); flag default off. Closing or detaching a connected account is a vendor act.                |
| KYC vendor                              | `KYC_PROVIDER=mock` default; flag off. If a real vendor is ever on, its stored documents need a delete call.                                                               |
| Social providers                        | Driver default `mock`; tokens revoked on delete if real.                                                                                                                   |
| Firebase Cloud Messaging                | We hold the FCM token (deleted with the row). Firebase also holds an installation id; the AH-105 note already says to list it. On-device `unregister` is best-effort (§6). |
| Mail provider (`MAIL_MAILER=smtp`)      | Sent mail and bounce logs live at the provider; outside our delete.                                                                                                        |
| Error tracking and the Meta Pixel (web) | The auth store references Sentry; I did not trace what identity it sends. No pixel on native. **Not verified.**                                                            |

## 6. Push tokens and device state

- **Server:** call `revokeAllForUser` (`PushTokenRegistry.php:105`; a plain delete by `user_id`) inside the erasure, and delete the user's
  `personal_access_tokens` (the FK cascade from the PAT removes push rows too, per the AH-102 inventory table at `push-notifications-inventory.md`
  :296-301) and `sessions` rows. A soft-delete does **not** fire the FK cascade (`User` is `SoftDeletes`, `User.php:69`), so the deletes must be
  **explicit**. Order: revoke tokens first, anonymise last (§8), all in one transaction.
- **Device:** after a successful request the app runs the existing sign-out path (`nativeLogout()`, `useAuthStore.ts:376-391`), which first
  calls `pushLogoutCleanup()` (needs a live bearer, so it must run **before** the server revokes the token, or accept that the server removal
  already did it), then the revoke call (now a 401, tolerated: `useAuthStore.ts:381-383`), then the local clear. The AH-103d marker work is the
  same path; they share, they do not conflict.
- **Pending work after the request:** queued jobs and scheduled mail that target the user must not reach an anonymised address. The creator
  iteration points to audit are `JobPostedFanOutService.php:206`, `MessageDigestService.php:124`, `SendIncompleteCreatorNudges`,
  `MessageableContactsFinder.php:55-68`, plus any queued mailable already holding the old address. Each needs an "erased or in grace" exclusion.

## 7. The agency-side view of a deleted creator

- The agency sees the creator in ~20 resources (the largest: `AgencyCreatorDetailResource` 41 references, `CreatorPublicProfileResource` 32,
  `CreatorDiscoveryResource` 16, `TalentPoolMemberResource` 15, `CampaignAssignmentResource` 9, `CampaignApplicationListItemResource` 8,
  `BoardCardResource` 7). Roster rows (`agency_creator_relations`, cascade on hard delete only), blacklists (an agency's ban is its record),
  threads (`relationship_threads.creator_id` restrict) and assignments all keep pointing at the creator.
- **The soft-delete model breaks them.** `Creator` and `User` are `SoftDeletes`; soft-deleting hides the row from relation queries, so
  `assignment->creator` becomes `null`. The code is _partly_ defensive (`AgencyCreatorDetailResource.php:128-132` and `CreatorResource.php:243-247`
  use `?->`; both message resources guard a null sender, `MessageResource.php:40-42`, `RelationshipMessageResource.php:43-45`) and partly not:
  `CampaignAssignmentReviewController.php:101` reads `$assignment->creator->display_name` bare. Only 21 `withTrashed` mentions exist in `app/` (comments included); the two on
  creators are admin-side (`AdminPushDeliveryController.php:189, 232`), none in an agency-facing resource. **A soft-delete would put a 500 in
  an agency page.** Anonymise in place (the row stays live) avoids the whole class.
- **What the agency should see:** a localised "Deleted creator" label, no avatar, no profile link, the assignment and message history intact
  and read-only, no new invite or message possible, and the creator absent from discovery, pickers and the roster's active lists. **The label must
  not be an English string from the API** (the 24-locale gate): the resource emits a flag (a **resource-shape change**, Mode A) and the SPA
  renders the translated label. Every agency resource and listing above needs a spec for the deleted case (§5.34 negatives), and the creator
  must be excluded from discovery, `MessageableContactsFinder` and every fan-out.
- **Agency notice:** whether an agency is _told_ when a rostered creator deletes (an in-app note on the roster, or only the changed label) is a
  product call (open question 8). The auto-cancel in §5.2 already notifies for open assignments.

## 8. The model: anonymise in place vs soft-delete

M5.4 raised it; the evidence above settles most of it.

- **Anonymise in place, row stays live, login blocked** keeps every FK edge valid (the RESTRICT edges are "anonymize, don't delete"), keeps agency
  pages working, and frees the email (`users.email` is unique). It needs an explicit marker (an `erased_at` or `anonymised_at` column, **an
  additive migration, schema only**; any backfill is a separate command, `WORKING-PROCESS` §5), an explicit login block, and the exclusions in §6.
- **Soft-delete the user as well** gives free login blocking (the default scope drops them) but the §7 blast radius. **Not recommended on its own.**
- **Hard delete after 7 years** (`05-SECURITY:70`) is a later retention job and needs the financial-records decision; it is not an AH-104 concern.
- Recommendation: **anonymise in place with an explicit marker**, `deleted_at` set as well only if a later reason appears. A kickoff decision.

## 9. The grace period question

**Apple and Google** _(from memory)_: neither forbids a waiting period if the user can initiate in-app and is told what happens and when;
neither accepts "deactivate" as deletion. **GDPR art. 12 and 17:** "without undue delay", within **one month** (extendable in complex cases); the
repo's own spec says erasure "within 30 days of approval" behind an admin approval queue (`05-SECURITY:53`).

| Model                                                           | Pros                                                                                                      | Cons                                                                                                                                                              |
| --------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Immediate, irreversible**                                     | Simple; matches what users expect from "delete".                                                          | No recovery from a hijacked session or a mistake; the §4 re-auth is the only brake.                                                                               |
| **Grace period, cancellable** (e.g. 14 days), then executed     | Recovery from mistakes and hijacks; time to cancel open assignments cleanly; fits the one-month GDPR cap. | A "pending deletion" state to build and to exclude everywhere (§6 and §7 apply from the _request_); how the user cancels, since login is blocked.                 |
| **Admin approval queue as the only path** (the spec as written) | Handles legal exceptions by a human.                                                                      | Slow and manual; a store reviewer who tests it sees "request received" and nothing; the 30 days start at _approval_, so the user's wait is unbounded by the spec. |

**Recommendation (a call, not a default):** a **14-day cancellable grace**. At request time (immediately): tokens, sessions and push rows
revoked, login blocked, the creator hidden from agencies' active views, open assignments cancelled (§5.2), the confirmation mail sent. During
the grace the user cancels by **signing in again** (the one allowed login, which shows "your account is scheduled for deletion, cancel?"), and
a successful sign-in during the grace is the cancel. After 14 days a **scheduled, guarded, idempotent, `--dry-run`-able command** (the
`WORKING-PROCESS` §5 shape, `--limit`, oldest first, loud on failure) performs the erasure and writes the ledger entry. The admin queue stops
being an _approval gate_ and becomes a **view** (pending, executed, held) plus a **legal-hold** action that stops one request.
The conflict to surface: **revoking tokens at request time vs allowing a re-login to cancel** (a stolen password could cancel a deletion; the
mail in §4 is the mitigation).

## 10. Scope, size and risk

- **PROD-DATA RISK for the chunk: HIGH.** It irreversibly rewrites and deletes production rows (users, creators, S3 objects, tokens,
  notification data), on the platform's most important asset (`WORKING-PROCESS` §0). Hard stop-gates: schema, API field and resource shape
  (a deleted flag on ~20 resources), a validation and security rule (re-auth), a policy, a route-table change (the Security page, a public
  deletion page, a request endpoint), a state-machine edge (auto-cancel), a scheduled job, i18n ×24, new mail.
- **Honest size:** M5.4 said "its own chunk, plausibly two". The evidence says **two or three**: **(1)** the erasure engine (the model, the
  marker, the guarded command, the S3 and vendor work, the ledger, the audit decision); **(2)** the request flow (re-auth, the grace state, the
  in-app Security page, the public web page, mail, the admin view); **(3)** the agency-side deleted-creator pass (the resource flag, ~20
  resources, discovery and fan-out exclusions). Engine first and dry-run-only, so it is proven on a snapshot before any user can trigger it.
- **Deploy shape:** a pre-deploy snapshot is mandatory (carries a migration), the migration is schema only, the command is run `--dry-run`
  first with the counts recorded in `deploy-log.md`, and the first real run is on a named test creator. The request endpoint ships behind a
  **flag, default off**, like every other first-use surface.
- **Dependency on restore rehearsal:** the erasure ledger (§5.5) is meaningful only once a restore can be rehearsed, which is still Pedram's
  open standing item.

## 11. Open questions

1. **Where does deletion live on the web?** The Security page is ruled native-only; Google and GDPR need a web path. Options: the Security page
   is not native-only (only its biometric section is); or deletion also sits on the web creator profile area; plus a **public** page for the
   Google URL. Which?
2. **Scope of "account":** creators only in AH-104, with agency users and admins handled by the admin queue or a support request? Apple's rule
   binds the app, which is creator-only (`creator_app => [Creator]`, AH-099). Confirm.
3. **Grace period:** immediate, **14 days** (recommended), or 30? Cancel by signing in again? May a stolen-password sign-in cancel it?
4. **Open assignments:** block, auto-cancel (recommended) or leave? And who is notified?
5. **Messages:** keep (a), keep text and erase attachments (b, recommended), or blank (c)?
6. **Draft media the agency approved:** keep, or erase with the creator? Do contracts keep the signing IP and user agent?
7. **Audit logs:** the append-only rule conflicts with erasure. Accept retention with a disclosed basis, and stop writing PII into new snapshots
   (recommended)? Is a scrub role acceptable, and does this need legal input before build? Is the 3-year audit retention job in or out of scope?
8. **Agency visibility:** only a "Deleted creator" label, or also a notice on the roster? Should rosters and blacklists keep the deleted creator at all?
9. **Re-auth:** password always plus 2FA when enrolled, a typed confirmation, a new rate limiter, and the confirmation mail to the old address.
   Does a failed-password streak escalate (suspend) or only throttle?
10. **Admin queue:** approval gate (the spec) or a view plus legal hold (recommended)? Who acts on a hold?
11. **Production vendor state:** are KYC, Stripe payout or social tokens live for any real creator? That decides whether vendor deletion is in
    scope at all. (I could not read production.)
12. **Order against the other work:** AH-104 before AH-105 (the privacy checklist consumes §5) and before AH-104d. Does it land before the first
    store build? §3 says it should.
13. **Sentry and any other identity-carrying processor:** I did not trace them; add to the AH-105 list or to this chunk?

## Not verified

- Every claim about Apple's and Google's policy text (§3, §9).
- Production flag and vendor state; whether any real creator has tax, payout, KYC or social data.
- What identity the web error tracker sends; the mail provider's retention.
- The exact count of agency-side creator dereferences without a null guard: I sampled (§7) and found one bare read; the plan must audit all of them.
- Whether the production DB role for `audit_logs` is INSERT+SELECT only (the spec says so; the grants were not read).
- Nothing was run.
