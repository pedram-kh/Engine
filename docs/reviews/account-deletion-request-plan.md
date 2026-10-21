# AH-104b-del — The deletion request flow and Security page — PLAN (plan-pause)

- **Status:** **PLAN, awaiting clearance. No code, no migration.** Written 2026-10-21 after Pedram accepted the inventory
  ([`account-deletion-request-inventory.md`](account-deletion-request-inventory.md)), confirmed D1 to D5 and ruled Q1 to Q11 (table in §1).
- **Split (ruled):** **(A) API and the sign-in step** (8 sub-steps, A1 to A8) · **(B) pages, the agency-side pass and the i18n** (7 sub-steps, B1 to B7).
  Each sub-step ends green on its own (Pest, Pint, PHPStan with **no path argument**; on the web side Vitest, ESLint, typecheck, i18n parity). **A and B deploy together; A alone is never switched on.**
- **PROD-DATA RISK:** **A: MEDIUM** (one additive migration on `users`, a gate in the login path, a revoke at request time; nothing is erased; the flag is off). **A7 (the AH-104a rider) is HIGH
  once the engine runs**, because it cancels real assignments irreversibly at erasure; it cannot run before AH-104c, and it is dry-run-counted and break-reverted like the rest of the engine.
  **B: LOW to MEDIUM** (no schema; ~20 resources gain a field and ~25 queries gain a scope; a defect would hide a creator from an agency, not delete anything).
  **The flag flip is the real risk event** and is a named step on the build-3 checklist (§9).
- **Standards applied:** `WORKING-PROCESS` §2 Mode A, §5 (additive migration, no data write in a migration, flag default off, new mail flag-gated, snapshot before deploy), §5.34 over-reach negatives,
  §5.35 break-reverts with the exit code read, failing-by-default specs for any new surface. Global Pest helper names are prefixed (PHPStan collisions). Local test runs use the `SANCTUM_STATEFUL_DOMAINS` env.

---

## 1. Rulings recorded (2026-10-21) and what each becomes

| Ruling | As ruled                                                                                                                                                                                         | Becomes                                                                      |
| ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------- |
| D1-D5  | One gate; one revocation helper; one scope plus one resource flag, guarded by an arch spec; one flag and a non-separable mail; no reveal before password and 2FA                                 | §3, §4, §5                                                                   |
| Q1 (b) | Pending answer `auth.deletion_pending` after password and 2FA, **no session**; cancel only through `cancel_deletion: true` re-verifying the same credentials                                     | A4                                                                           |
| Q2     | Login throttles called explicitly; new `auth-account-deletion` limiter, 3 per hour, 429 only; typed "DELETE", localised x24                                                                      | A3, B2                                                                       |
| Q3     | Agency-side pass carried here: one scope, one resource trait, the arch spec; AH-104c reads them                                                                                                  | B1, B2                                                                       |
| Q4 (c) | Assignments untouched at request, **cancelled at erasure** (AH-104a rider: own plan line, system actor, fixed reason, in-app agency notice, push-ineligible); pending creators are not invitable | A7, B1                                                                       |
| Q5, Q6 | Hidden is **derived** from the stored date; failed passwords **throttle only**                                                                                                                   | B1 (scope reads `users.deletion_requested_at`), A3                           |
| Q7     | Flag off at deploy; Pedram flips it in the admin before build 3 goes to review; a named build-3 checklist step                                                                                   | §9                                                                           |
| Q8     | Confirmation mail on the same flag, no cancel link                                                                                                                                               | A6                                                                           |
| Q9     | `/delete-account`; support address `support@catalyst-growth.com`, **one place per runtime**; log and fix the `app.settings` bug here                                                             | B4, B5, tech-debt row closed                                                 |
| Q10    | The date is **stored** at request time                                                                                                                                                           | A1 (`deletion_scheduled_for`)                                                |
| Q11    | Creators only; suspended cannot request; mid-onboarding can                                                                                                                                      | A3                                                                           |
| Extra  | Sign-up during the grace says "this email belongs to an account scheduled for deletion; sign in to restore it" with **no state leak**                                                            | **Does leak as proposed. Safe variant in §6 (A5); needs your confirmation.** |

## 2. What the plan found that the inventory did not (each is a decision in §10 or is settled here)

**P1. A recovery code is burned by the first call.** Q1 (b) is two calls. The first call (no `cancel_deletion`) passes 2FA before it can answer `deletion_pending`, and a recovery code is
single-use (`TwoFactorChallengeService::consumeRecoveryCode`, atomic find-and-burn). So an enrolled creator who signs in with a recovery code needs a **second** code to cancel. A TOTP code is **not**
burned: `verifyTotp` has no replay protection (`TwoFactorService.php:98-114`, window only), so the same 6 digits work for the second call within the window. 8 users have 2FA today. Proposed: accept it,
and have the pending screen say so when a recovery code was used (the response carries `meta.mfa_via: "recovery"`, set only **after** the credentials pass). Alternative (a non-burning check) means a
new method on the security-critical challenge service; I do not recommend it for 8 users. **C1.**

**P2. "Throttle only" and "login throttles explicit" pull apart if taken literally.** `FailedLoginTracker` and `AccountLockoutService` are what escalate a failure streak to **suspension and token
deletion** (`AccountLockoutService.php:70-90`). Q6 forbids that at the deletion door. So the deletion door calls: `auth-ip` (10/min, the route middleware), the new `auth-account-deletion` (3 per hour per
user id), and `TwoFactorVerificationThrottle` for the 2FA code (soft 5 and hard 10 per 15 minutes; its hard threshold suspends **2FA enrolment**, not the account, which is the existing MFA protection and
is kept). It does **not** call `FailedLoginTracker` or the lockout, and a spec pins "ten wrong passwords at the deletion door never suspend and never delete a token". **C2.**

**P3. The request must refuse up front when the erasure will refuse.** The engine refuses a subject with an assignment in a payment state or a non-`mock` provider (`Guards/PaymentGuard.php`,
`VendorGuard.php`). Both are zero in production today. Without an up-front check, a creator would be signed out and hidden for 14 days for a request that can never complete. A3 calls both guards
(read-only, through a small public contract in the Erasure module so Identity does not import its internals) and answers `422 account_deletion.blocked` with the reason code before it touches anything.
**C3 (an addition to what you ruled).**

**P4. "Pending creators are not invitable" has more doors than invite.** The agency-side doors that would act on a pending creator: invite (`CampaignAssignmentController::store`), connection-request
accept (`AgencyConnectionRequestController`), talent-pool add (`TalentPoolMembershipController`), the creator's own pending connection requests (`CreatorConnectionRequestController`), applications
(`CampaignApplicationController`), and messaging. B1 covers every one by manifest (§5). Proposed behaviour: **new actions on a pending or erased creator answer 422 `creator.unavailable`** (a neutral code
and a neutral localised line, never "pending deletion"); **existing threads stay writable** (silent: the agency sees nothing different, mail and push to the creator are skipped); history and roster rows
are unchanged during the grace, so a cancelled request leaves no trace. **C4.**

**P5. Sign-up wording: the proposed text leaks.** §6.

**P6. The typed word is a UX gate, not a security control.** The security control is the re-auth. A server cannot cheaply validate a word that exists in 24 spellings, and should not: the client
compares (NFC, locale-uppercased, trimmed) and sends `acknowledged: true`; the server requires it. A spec asserts all 24 words exist, differ from the button label, and survive case and accent changes
that a phone keyboard introduces (Greek final sigma, Bulgarian Cyrillic). It is not enforced server-side, and the doc says so.

**P7. `cancelled_reason` is shown to agencies and is stored text.** The rider's fixed reason must not be an English sentence in a 24-locale product. It is stored as the code
`creator_account_erased`, and the SPA renders it through i18n when it equals that code (any other value is shown as free text, as today). Because `D-D` keeps the other party's decision records, the code
stays.

**P8. A session created in the same instant as the request can survive it.** The gate refuses new sign-ins once the columns are set, and the request deletes `sessions` and tokens in its own
transaction; a sign-in that passed the gate microseconds earlier can commit a session just after. The bearer side is closed by A4 (`CreatorTokenGuard` also refuses a pending user, defence in depth). The
cookie side is not closed in this chunk (no middleware on every creator route); the surviving session ends at the latest at erasure, which deletes `sessions`. Recorded in tech-debt, with the trigger
"any change that lengthens the grace or the session lifetime". **C5 (accept, or ask for the middleware).**

**P9. The rider cannot live in the engine's `Execution/` folder.** An arch spec (`ErasureEngineTest`, "no model import in Execution/") forbids models there, and the state machine is model code. The cancel
step is its own class (`Erasure/Cancellation/CancelOpenAssignments`), injected into `ErasureRun`, and runs **inside the engine's database transaction, in the existing `$inTransaction` hook, before the closing audit
pass**, so a failure rolls the whole erasure back and the audit rows it writes (actor null, subject the assignment, no personal data) are covered by the closing pass. The arch spec gains one explicit,
named exception.

**P10. The agency notice needs a frontend.** `NotificationType::AssignmentCancelled` exists and is push-ineligible (`PushEligibilityTest.php:71-79`), but nothing creates it and the SPA has no template for it
(`modules/notifications/templates.ts`, the live-types registry, `notification-route-keys.json`, and the notifications parity spec would all need it). The row's data carries **no creator name** (an `is_erased`
flag instead, so the 4d scrub has nothing to remove and the label is localised). Rendering lands in B6. A7 can be built and green without B6 because nothing erases until AH-104c; **AH-104c does not start
before B6**.

**P11. AH-104c reads three things from here:** `users.deletion_requested_at`, `users.deletion_scheduled_for`, and the `Creator::visibleToAgencies()` scope with the `is_erased` flag. Their names are fixed in A1
and B1 and written into the AH-104c plan.

**P12. The support address has two runtimes.** The web app and the API's mail template both need it. One place each, never a second literal: the API reads `config('app.support_email')` (env
`SUPPORT_EMAIL`, default `support@catalyst-growth.com`); the web app reads `VITE_SUPPORT_EMAIL` through one module `core/config/support.ts`. A failing arch spec on each side forbids the literal anywhere else
(including `lang/` and `locales/`: mail and page copy take it as a placeholder). Changing it later is one env value per runtime, not a code change. There is no existing support address anywhere in the repo.

## 3. The design in one page

**Data (A1).** `users.deletion_requested_at` (nullable timestamp) and `users.deletion_scheduled_for` (nullable timestamp, indexed; set to `requested_at + 14 days` at request time, Q10). Nothing on `creators`: hidden
is derived from `users` through a scope (Q5). Cancelling sets both back to null. **Erased accounts keep both** (they are the record that the erasure had a request; classified `KEEP` in the erasure registry
in the same commit, or the registry spec fails CI).

**Request (A3).** `POST /api/v1/me/account-deletion`, creator only (both doors: cookie and `creator_app` bearer), behind the flag (404 when off, the existing convention for flagged endpoints, confirmed in A1),
route middleware `auth-ip` and `auth-account-deletion`. Body: `password`, `mfa_code` (required when 2FA is confirmed), `acknowledged: true`. In order: flag, user type, not erased, **not already pending**
(idempotent: answers 200 with the existing date and does **not** reset the clock, C6), the P3 guards, password (`Hash::check`), MFA, then **one transaction**: set the two columns, revoke, audit
`account.deletion_requested`; **after commit**: queue the mail, log the current web session out. Response `200 { data: { scheduled_for } }`. The client then clears local state (a 401 on its next call is
expected).

**Revoke (A2).** `CredentialRevoker::revokeAll(User)` deletes `personal_access_tokens` (cascading push tokens), `sessions` by `user_id`, and calls `PushTokenRegistry::revokeAllForUser()`. It is **one list**
(`CredentialRevoker::TABLES`), and a spec asserts the erasure registry's `EraseRow` entries cover every table on it, so a surface added to "everything that signs a person in" cannot be revoked at request and
forgotten at erasure (D2). The engine's own generic delete step is unchanged; the two share the list, not the statement (a literal shared statement would need the engine to import a model-free copy of it,
which its own arch spec forbids).

**Sign-in (A4).** One new `LoginResultStatus::DeletionPending`; one new parameter `cancel_deletion` on `LoginRequest` and `TokenLoginRequest` (optional boolean); `evaluateGates()` gains a check **after 2FA passes**
and before `GatesPassed`: if the user is pending and `cancel_deletion` is not true, return `deletionPending` (no session, no token); if it is true, clear the columns, write `account.deletion_cancelled`, clear
the failure counters, and fall through to the normal success. The failure response is **403 `auth.deletion_pending`** with `meta.scheduled_for` and (P1) `meta.mfa_via`, a sibling of `auth.wrong_spa` (credentials
valid, no session); it dispatches `LoginFailed(..., 'deletion_pending')` for the audit row, the same precedent as `account_suspended`, and **does not feed the lockout counters** (the credentials were right).
`cancel_deletion` on a non-pending account is ignored. Wrong password, missing 2FA code and bad code answer exactly as today, **identically for a pending and a non-pending account** (a spec compares the
envelopes byte for byte).

**Hidden (B1, D3).** `Creator::scopeVisibleToAgencies()` = not erased and not pending (`NOT EXISTS` on `users.deletion_requested_at`). One resource trait `EmitsErasedState` adds `is_erased` (the SPA renders a localised
"Deleted creator" and drops the avatar and profile link). Agency-facing surfaces are listed in a **manifest** (`AgencyCreatorSurfaces`), each entry marked `scoped`, `via-relation` (with the reason) or
`admin-only`, and an arch spec fails on any file under `Agencies/`, `Campaigns/`, `Messaging/`, `TalentPools/`, `Boards/` that reads creators and is not in it (heuristic: it greps imports and relation access, and
says so). A **behavioural** spec seeds a pending and an erased creator and drives every manifest route, asserting absent, refused (422 `creator.unavailable`) or flagged. The behavioural spec is the strong one;
the static one catches the file nobody remembered.

**Rider (A7, Q4 c).** `CancelOpenAssignments` finds the subject's non-terminal assignments, calls `CampaignAssignmentStateMachine::cancel($assignment, 'creator_account_erased', null)` for each (system actor), and a new
branch in `SendAssignmentNotifications` writes **one in-app row per agency's admins and managers** (staff excluded, as for the other agency fan-outs) with no creator name and `is_erased: true`; no mail, no
push (pinned). It appears as a new disposition `Cancel` in the dry-run (`campaign_assignments.cancel`, with a count), is part of the digest, is recorded in the ledger counts, and the runbook gains a line.
Payment states are already a refusal, so it never sees one.

## 4. The sub-steps

Every sub-step: files, the specs it adds, the break-revert targets (named, run in the foreground, exit code read), and the gate. Target total: **about 70 mutations**, listed in the completion package.

### Part A — API and the sign-in step

**A1 — Schema, model, flag, audit verbs, config.** Migration `add_deletion_request_to_users` (two nullable timestamps, index on `deletion_scheduled_for`; `down()` drops them; **schema only**). `User` casts,
`isDeletionPending()`, `deletionScheduledFor()`. Erasure registry: both columns `KEEP`. `AuditAction::AccountDeletionRequested`, `AccountDeletionCancelled` (neither requires a reason; both documented in
`05-SECURITY-COMPLIANCE.md` with the actor and subject, and in `AuditActionEnumTest`). Pennant `AccountDeletionEnabled` (default `false`, registered like the Creators flags, in the Identity provider because the
feature is Identity's). `config('app.support_email')` (P12). Read-only finding closed here: the existing "flag off" response convention for flagged endpoints.
_Specs:_ migration up/down on sqlite; registry spec green with the new columns and **fails** without their classification; enum/doc spec; flag default off. _Break-reverts:_ column unclassified; flag default true; down() not
dropping.

**A2 — `CredentialRevoker` and its list spec.** The class, its single list, unit specs per table (rows of another user untouched: §5.34 over-reach negative), and the coverage spec against the erasure
registry. _Break-reverts:_ one table dropped from the list; a `WHERE` removed (touches a bystander); push registry call removed; a table in the list that the registry does not erase.

**A3 — The request endpoint.** `AccountDeletionController`, `RequestAccountDeletionRequest`, `AccountDeletionService`, `auth-account-deletion` limiter (and the E2E `RateLimiterNeutralizer::ALLOWED_NAMES`
entry), the Erasure-side public eligibility contract (P3), route, 404 when the flag is off, the mail dispatch seam (the mailable itself is A6). _Specs:_ happy path for cookie and bearer; flag off 404;
non-creator 403; erased 403; **already pending is idempotent and does not move the date**; blocked (payment, vendor) 422 before any write; wrong password 401 with nothing written and nothing revoked;
2FA enrolled with no code and a wrong code; a recovery code works and is consumed; **ten wrong passwords never suspend and never delete a token** (P2); 4th request in an hour is 429 with `Retry-After`;
`acknowledged` required; the revoke really runs (a second device's token is gone, the `sessions` row is gone, push rows are gone); audit row written with the right actor and subject; the response does not
echo any credential; the request that revokes the caller's own token still returns 200. _Break-reverts:_ the password check removed; the MFA check removed; the revoke removed; the transaction removed (a failing
audit write must roll the columns back); `acknowledged` ignored; the clock reset on a repeat; the guards skipped; the limiter removed; the mail dispatched before commit.

**A4 — The sign-in gate and the cancel.** `LoginResultStatus::DeletionPending`, `LoginResult::deletionPending()`, `evaluateGates()` parameter and check, both requests, both controllers, the responder arm (403,
`auth.deletion_pending`, `meta.scheduled_for`, `meta.mfa_via`), `CreatorTokenGuard` refuses pending users. _Specs (both doors each):_ pending + correct credentials → 403, no session cookie, no token row; pending + wrong
password → exactly today's 401; pending + 2FA missing → exactly today's `mfa_required`; **envelope equality for pending vs non-pending on every pre-credential failure**; `cancel_deletion` with right credentials clears the
columns, writes the audit row, signs in; with wrong credentials changes nothing; on a non-pending account is ignored; cancel with a TOTP re-submit works; a recovery code used on call 1 fails on call 2 (P1, pinned
as documented behaviour); pending bearer token (minted before the request, simulated) is refused by the guard; the pending response does not increment the lockout counters; the audit row is written. Update
`ErasedLoginBlockTest`-style precedent spec. _Break-reverts:_ gate placed **before** 2FA (leak: must fail the equality spec); gate placed before the password check; `cancel_deletion` honoured without 2FA; columns
not cleared; session issued on the pending answer; token minted on the pending answer; lockout fed; guard check removed; the flag ignored on the cancel path.

**A5 — Sign-up wording (safe variant, §6).** `auth.signup.email_taken` copy changed in 24 locales (static, identical for every taken address); the 422 shape is untouched. _Specs:_ pending, ordinary taken and
unknown-erased addresses produce byte-identical bodies (after erasure the address is freed, so that case is a success, asserted separately); the string exists in all 24 locales. _Break-reverts:_ a branch that
returns different copy for a pending address.

**A6 — The confirmation mail.** `AccountDeletionRequestedMail` (queued, 24 locales via `lang/*/auth.php` or a new `lang/*/account_deletion.php`, the existing localisation pipeline), content: the date, "if this was
not you, sign in before {date} to cancel or write to {support}", **no link that cancels** (Q8). Sent to the current address after commit. _Specs:_ queued after commit not before; 24-locale render; the support address
comes from config; the body contains no URL that mutates; not sent when the request is refused or idempotent. _Break-reverts:_ sent before commit; a cancel link added (a spec scans the rendered mail for
`cancel`-bearing URLs); support literal inlined (the P12 arch spec).

**A7 — The AH-104a rider: `CancelOpenAssignments`.** As in §3. Edits: new `Disposition::Cancel`, selection catalog row, dry-run line, digest, ledger counts, `ErasureRun` hook ordering, the one named arch-spec exception,
`SendAssignmentNotifications` branch, runbook §4 line, `ErasureGraph` gains an open assignment for each of the two symmetric creators. _Specs:_ an erased creator's non-terminal assignments become `cancelled` with the
code reason and a null actor and `cancelled_by_user_id` null; terminal ones untouched; **creator B's assignments untouched** (the symmetric graph); one in-app row per admin/manager of each affected agency, none
for staff; **the row's data holds no creator name**; no mail, no push (existing `PushEligibilityTest` pin extended); dry-run count equals the real count; digest changes when the count changes; a second run changes
nothing; failure rolls the whole erasure back (the closing-pass rollback precedent); the audit row carries no personal data. _Break-reverts:_ the step removed; a terminal state not excluded; the creator filter
removed; the actor set to a user; the row carries the name; staff notified; the step outside the transaction; the count left out of the digest.

**A8 — The sign-in step in the apps and the API client.** `packages/api-client` gains `cancel_deletion`, the `auth.deletion_pending` error code with typed `meta`, and the `requestAccountDeletion` function; the auth store
gains `pendingDeletion` and a `cancelDeletion` action for both doors; `SignInPage` shows the pending step (date, **Cancel the deletion** and **Stay signed out**, the P1 hint) in web and in the native sheet; the 2FA
inline step is reused. i18n for this step only (about 10 keys x 24) plus `auth.deletion_pending` in `i18n-auth-codes.spec.ts` (main and admin). _Specs:_ component specs for both layouts; the store's two doors; **"Stay
signed out" holds no token and no user**; the cancel resubmits the same credentials; the banner after a request; i18n parity; the native 16px field rule. _Break-reverts:_ the pending step skipped (a token stored); the
cancel sending different credentials; the date not shown; a code missing in one locale.

### Part B — Pages, the agency-side pass and the i18n

**B1 — The scope, the trait, the manifest, the refusals.** `Creator::scopeVisibleToAgencies()`; `CreatorUnavailable` exception (422 `creator.unavailable`); the scope and the refusals applied to every manifest entry (about 25
files: discovery, roster, detail, connection request and accept, invite, pool add, applications, review controller line 101, messaging contacts, digest, nudges, job-posted fan-out, board cards, the creator's own
connection-request controller). `AgencyCreatorSurfaces` manifest and the static arch spec. Mail and push to a pending creator are skipped in the four fan-outs. _Specs:_ the behavioural matrix (one pending creator
and one erased creator against every manifest route, plus the symmetric bystander who must still appear); the manifest spec fails on an unlisted file; **a pending creator's history is unchanged** (roster row,
assignment, thread visible exactly as before); a cancelled request makes the creator reappear everywhere. _Break-reverts:_ the scope removed from each of the headline queries (one mutation per surface family, ~12);
the refusal returning 404; the pending predicate flipped; the erased predicate dropped; a fan-out not skipping.

**B2 — The resource trait and `is_erased`.** `EmitsErasedState` on the ~8 creator-bearing resources that carry the heavy references (`AgencyCreatorDetailResource`, `CreatorPublicProfileResource`,
`CreatorDiscoveryResource`, `TalentPoolMemberResource`, `CampaignAssignmentResource`, `CampaignApplicationListItemResource`, `BoardCardResource`, `CampaignDraftListItemResource`) and null-safe `display_name` on the rest
(`CampaignAssignmentReviewController.php:101`). An erased creator emits `is_erased: true`, **no** name, no avatar, no profile identifiers. _Specs:_ per resource, erased vs normal; "no API response emits the
sentinel `[erased]` or the placeholder email" (the leak spec the registry plan promised); null-safety. _Break-reverts:_ the flag not emitted; the name emitted for an erased creator; one resource skipping the trait.

**B3 — The agency SPA label.** One helper `creatorLabel(creator, t)` and one `CreatorAvatar` guard; every agency component that renders `display_name` (about 22 `.vue` files across campaigns, boards, roster, messaging,
discover, pools) uses it; an arch spec forbids `.display_name` in agency modules outside the helper. `creator.erased` label x24. _Specs:_ the helper; each touched component with an erased fixture; the arch spec.
_Break-reverts:_ helper returning the name for an erased creator; a component bypassing it.

**B4 — The Security page and the menu entries.** Route `creator.security` (`/creator/security`, `requireAuth`), `SecurityPage.vue` with the **Delete account** section: what is erased, what is kept (the PII map's list in plain
words), the 14 days, how to cancel, **the button**; the confirmation dialog (`v-dialog` on web, `v-bottom-sheet` on native) with password, 2FA code when `two_factor_enabled`, the typed word, a danger button; success sends
the app through the existing sign-out path and to sign-in with a banner. Menu entries: the web avatar menu and the native More "Settings" row split so Security is its own item. Gated on the flag: `UserResource`
gains `features.account_deletion` (a **resource-shape change**, the first menu-level flag). `creator-routes-guard.spec.ts` updated. _Specs:_ page and dialog (web and native), the typed-word comparison table for 24 locales
(P6), 2FA field only when enrolled, errors mapped (wrong password, 429 with the countdown, blocked), the flag off hides the entry **and** the route redirects, the success path clears the store and the push registration
**without** a server call that 401s into an error, 16px native fields. _Break-reverts:_ the flag ignored; the typed word not required; the button enabled with an empty password; the success path leaving the user in the store.

**B5 — The public page and the support address.** `/delete-account` (auth layout, no guards, outside `/creator/*` so the AASA never captures it), explaining the in-app path and the support path (the address as a
placeholder from `core/config/support.ts`), what is kept, the 14 days. The P12 arch specs on both runtimes. The `DisableTotpPage` fix: it navigates to `creator.security` for creators and `settings` for agency users, the
spec at `:82` corrected, and the tech-debt row closed. _Specs:_ the route is public and renders signed out; **it is not under `/creator/`** (spec on the AASA file); the literal address appears only in the config module
and the env example; the disable-2FA redirect. _Break-reverts:_ the page behind `requireAuth`; the literal inlined; the old route name restored.

**B6 — Agency notice rendering.** Notification type `assignment.cancelled`: template and copy x24, the live-types registry, `notification-route-keys.json` (and its Pest contract test), the parity spec, the push
eligibility pin. The reason code `creator_account_erased` rendered through i18n (P7). _Specs:_ render, parity, contract test, the unknown-reason fallback. _Break-reverts:_ type missing from the registry; the code
shown raw.

**B7 — i18n completion, docs, the device-walk script.** The ~45 page keys and ~14 API keys x 24 locales finished and glossary terms added (`account deletion`, `grace period`); the review file; the runbook
`account-erasure.md` gains "the request side"; `05-SECURITY-COMPLIANCE.md` documents the flow; deploy-log lines; adhoc log; the **build-3 checklist** line (§9). _Gate:_ full web and API suites, Pint, PHPStan (no path).

## 5. Boundaries (what this chunk does not do)

It never calls the erasure engine, never sets `erased_at`, deletes no row except the credential rows listed in §3. No admin queue, no hold, no Erase button, no "Ready to erase" view (AH-104c). No audit-snapshot cleanup
(AH-104e). No change to suspension, lockout or password reset. No signed-in change-password route. No email-change flow. The AH-104a rider is the **only** edit to the engine, and it changes no existing step.

## 6. The sign-up wording, analysed (your request)

**As proposed it leaks, and more than the current message does.** Today `email.unique` answers "email taken" for every registered address (`SignUpRequest.php:49, 88`), which already confirms that an address has an
account (an existing, accepted enumeration oracle, rate-limited by `auth-ip`). The proposed text would answer a **different sentence only when the account is pending deletion**. Anyone who knows an email could then
learn, without a password, that its owner **asked to delete the account**: a new, more sensitive fact than "has an account", and it breaks D5.

**Safe variant (planned as A5):** keep **one** string for every taken address, and put the pointer in it as a general instruction, true for everyone and revealing nothing about any one account:

> "An account with this email already exists. Sign in to use it, or reset your password. If you asked for this account to be deleted, signing in lets you restore it."

It is a static sentence, byte-identical for ordinary, pending and (while it still exists) any other registered address; the pending person still finds their way back, and the pending **state** is shown only after
password and 2FA (A4). A spec compares the 422 body for the three cases. After an erasure the address is freed and sign-up simply succeeds. **C7: confirm the safe variant.**

## 7. Production posture

- **Schema:** two nullable columns and one index on `users` (2,763 rows). Additive; `down()` drops them. No backfill, no data write in a migration. The pre-deploy snapshot is mandatory (the entry already carries migrations).
- **Writes at runtime:** only when a creator presses the button with the flag on: two columns on their own row, the credential rows listed in §3, one audit row, one queued mail. Nothing else.
- **Blast radius of a defect:** A3 revoking the wrong rows (bounded by the over-reach spec: `WHERE user_id = ?` only); A4 locking a creator out (the pending answer is only ever returned for a user with the column set); B1 hiding a
  creator from agencies (derived: clearing the column un-hides; nothing is written to agency data); A7 cancelling the wrong assignments (dry-run count first; the symmetric-graph spec; the engine has never run in production).
- **Population:** 2,752 creators; 8 with 2FA; 4 tokens, 88 sessions, 4 push tokens at the read-back, so a request revokes a handful of rows. The flag keeps even that off until you flip it.

## 8. Deploy shape

Snapshot (confirmed `available`), then `migrate` (one additive migration), no env change beyond optional `SUPPORT_EMAIL` and `VITE_SUPPORT_EMAIL` (defaults are the ruled address), no scheduler change, `queue:restart`
**required** (new mailable and a changed listener; a worker holding the old classes would not send the mail or write the agency row). The flag stays **off**. The entry rides the pending combined deploy in
`deploy-log.md`, under its own lines. No one-shot command.

## 9. The flag flip, the build-3 checklist step and the device walk

**Named step on the build-3 checklist (Q7):** _"Before build 3 is submitted to App Review: Pedram turns `account_deletion_enabled` ON in the admin (reason required) after the walk below passes; the walk's W9 and the
support address are confirmed first."_ Apple needs the in-app path present in the reviewed build; the public page URL is also what the Play listing asks for, so it is checked live (W8) before that URL is entered anywhere.

**Device walk (Pedram's, on a named test creator; recorded in the review file as W1 to W10):**

| #   | Step                                                                                                            | Pass when                                                                                   |
| --- | --------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| W1  | Flag off, web and native: the Security entry is absent, `/creator/security` redirects, the endpoint answers 404 | no entry, no route                                                                          |
| W2  | Flag on, native: Security, Delete account, wrong password; wrong typed word; then correct values                | errors shown; button disabled until the word matches; success signs out with a banner       |
| W3  | Native sign-in during the grace (a 2FA creator first: code prompt, then the pending step)                       | the pending screen shows the date; "Stay signed out" leaves no session                      |
| W4  | Native: cancel the deletion                                                                                     | signed in normally; push registers again; nothing visible changed for the agency            |
| W5  | Web: W2 to W4                                                                                                   | the same                                                                                    |
| W6  | As an agency user (during a grace): discovery, invite, connection accept, pool add, message                     | creator absent from discovery; new actions refused with the neutral line; thread still open |
| W7  | The confirmation mail: English and one other locale; the support address                                        | arrives once; no cancel link; the address is the ruled one                                  |
| W8  | `https://platform.catalyst-growth.com/delete-account` signed out, on a phone with the app installed             | the page loads in the browser and **does not open the app**                                 |
| W9  | Sign-up form with the pending address, then with an ordinary taken address                                      | **the same message**, word for word                                                         |
| W10 | A recovery-code creator (if one exists): pending step, cancel                                                   | the P1 hint appears; the second code works                                                  |

**Part B specifics (added 2026-10-21, at the Part B review).** What the built pages change in the walk and the checklist:

- **W2 and W5** run on the Security page (`/creator/security`) and its dialog: the typed word ignores diacritics and case; a 429 shows a countdown; the entry is behind `features.account_deletion`.
- **W6** now has two halves: a **pending** creator (hidden; new actions refused with 422 `creator.unavailable`, thread still open and silent) and, from W11, an **erased** one.
- **W8** also confirms the page is the static one (it reads no flag) and that it lists the support address.
- **Proposed W11 (agency sees "Deleted creator"):** on a local or seeded database, mark one creator erased and open, as an agency, the roster, a thread, an application, an assignment and a board card; each
  shows "Deleted creator" with a neutral avatar, and none shows a name, email or photo. It cannot be walked against production (nothing is erased there).
- **Proposed W12 (the agency bell notice):** on the same seeded database, cancel an erased creator's open assignment through the rider and open the agency bell: one notice per assignment, no creator name, no
  mail, no push; tapping it opens the campaign's creators tab.
- **Checklist additions before the flag flips:** the human pass on the **en and es** strings (the machine draft is in 24 locales, C9); W1 to W10 pass; W11 and W12 pass if Pedram accepts them; the snapshot
  and `queue:restart` of Part A are done.

The walk needs the flag on in production for its duration (it is global, like every flag here). The window is short and the population that can reach the entry is the TestFlight set plus anyone on the web; if you
prefer a per-user flag scope for the walk, say so (**C8**: it costs a scoped flag class and the admin toggle does not support scoping today).

## 10. Questions (each with my recommendation; C1 to C8 are new; none blocks A1 or A2)

1. **C1 (P1):** accept that a recovery code is burned by the pending step, with an in-screen hint. _Recommend: accept._
2. **C2 (P2):** the deletion door calls `auth-ip`, `auth-account-deletion` and the 2FA throttle (whose hard threshold suspends **2FA enrolment**, never the account), and **not** `FailedLoginTracker`/lockout. _Recommend: confirm._
3. **C3 (P3):** the request refuses up front on the engine's payment and vendor guards. _Recommend: yes._
4. **C4 (P4):** new actions on a pending or erased creator answer 422 `creator.unavailable`; existing threads stay writable and silent; history unchanged during the grace; open **applications** and the creator's own pending
   connection requests are left as they are (the agency sees the "Deleted creator" label after erasure and can decline). _Recommend: confirm, in particular "leave applications"._
5. **C5 (P8):** accept the cookie-session race (ends at erasure at the latest), logged in tech-debt, or add a middleware on every creator route. _Recommend: accept._
6. **C6:** a repeat request while pending is idempotent and does not move the date. _Recommend: yes._
7. **C7 (§6):** the safe sign-up variant. _Recommend: yes._
8. **C8 (§9):** walk in production with the global flag on briefly, or a per-user flag scope. _Recommend: global, short window._
9. **C9 (translations):** the consequences copy and the public page are legal-adjacent text in 24 languages. Same pipeline as the earlier chunks (glossary plus machine draft), or a human pass on the public page and the
   consequences list for the languages you serve first? _Recommend: a human pass on those two texts for your first markets, before the flag flip; the pipeline for the rest._

## 11. Order and the stop

Clearance of this plan, then **A1, A2, A3, A4, A5, A6, A8, A7** (A7 last in A because it touches the engine and wants A1's names; it can move earlier if you prefer), then **B1 to B7**. Each sub-step is one `feat`
or `test` commit plus a docs commit at the end of each part (`WORKING-PROCESS` §2). **I stop after Part A for review** (the completion package: per-decision evidence, break-reverts verbatim, the gate table, a draft
review file), and again after Part B. No push until you trigger it.

## Not verified

- **Staging:** I know of no staging environment; the walk assumes production with the flag. If one exists, W1 to W10 should run there first.
- **The existing "flag off" response convention** for flagged endpoints (404 vs a code): read in A1 before A3 depends on it.
- **That the module boundary allows Identity to call an Erasure contract** (an arch spec may forbid it); A3's contract is the fallback shape, and the specs will say.
- **The 22-component count** for the agency label is a grep of `display_name` in `.vue` files; some will turn out to need nothing and some to need more than a label (an avatar or a profile link).
- **Whether the translation pipeline for legal copy** is what you want (C9).
- **Assignment counts by state** in production were not read; they matter only for the size of A7's first dry-run, which is AH-104c's concern.

## AH-104c notes (carried from the Part A and Part B reviews)

1. **`live_verified` and `manually_verified` refuse erasure (ruled 2026-10-21).** Same family as the payment states. Build both halves in AH-104c: an engine guard (so `erasure:run` and the Erase button
   refuse) and a request-time refusal through C3 (so a creator with such an assignment is told at the request, not left pending against a refusal). Until then `CancelOpenAssignments` still cancels them;
   nothing erases before AH-104c. Tech-debt: "AH-104b-del Part A", item 3.
2. **Re-check under a lock before erasing:** re-read `deletion_requested_at` with the row locked (the creator may cancel until `erased_at` is set, even after `deletion_scheduled_for`).
3. **Legal hold:** an account on hold is not offered for erasure; the engine does not know about holds.
4. **The rider's first real run:** read the dry-run `campaign_assignments.cancel` count first; the agency notice (`assignment.cancelled`) goes to admins and managers, once per assignment, without the creator's name.
