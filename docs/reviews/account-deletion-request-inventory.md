# AH-104b-del — The deletion request flow and the Security page — INVENTORY (read-only)

- **Status:** Inventory only. **No code, no plan, no migration.** Stops for Pedram's rulings on §9, then the plan-pause (`WORKING-PROCESS` §2, Mode A).
- **Date:** 2026-10-21. **Method:** two read-only passes (API; web, native and admin), then the load-bearing claims re-read by hand (marked **[checked]**).
- **PROD-DATA RISK: HIGH for the chunk as a whole** (it is the only door through which a creator starts an irreversible erasure; it revokes tokens, sessions and push rows, may
  cancel assignments and may notify real agency users). **MEDIUM for the build itself**: nothing in 104b-del erases anything (the engine is artisan-only and stays so until AH-104c),
  the new columns are additive and nullable, and the endpoint ships behind a Pennant flag, default off. The HIGH part is the revoke-at-request step: it is the one act in this chunk
  that cannot be undone by cancelling the request (a signed-out device stays signed out).
- **Scope as ruled (2026-10-20):** 14-day grace, cancellable by sign-in; lock and revoke tokens, sessions and push at request time; re-auth is password, plus 2FA when enrolled,
  rate-limited like login; Security page on web **and** native, under the creator account menu; a public web path for Google; no automatic erasure (that is AH-104c).
- **Inputs read:** [`account-deletion-inventory.md`](account-deletion-inventory.md) §4, §6, §7, §9; [`account-deletion-erasure-plan.md`](account-deletion-erasure-plan.md) §8.1 and §9;
  [`account-deletion-pii-map.md`](account-deletion-pii-map.md); [`../runbooks/account-erasure.md`](../runbooks/account-erasure.md).

---

## 1. What exists, what does not

| Piece                                                                 | State today                                                                                                                                                                                      |
| --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Erasure engine (`erasure:run`, `erasure:reapply`), `users.erased_at`  | **Built** (AH-104a). Artisan only. Revokes tokens, sessions and push rows **at erasure**, not at request.                                                                                        |
| A request, a grace clock, a "pending deletion" column or state        | **Not found.** No `deletion_requested_at` (or similar) anywhere in `apps/api`.                                                                                                                   |
| Re-auth endpoint for an already signed-in user                        | **Not found.** No `password.confirm`, no change-password route. The nearest is `POST /auth/2fa/disable` (password **and** TOTP): `DisableTwoFactorController.php:53-63`.                         |
| Security page, deletion entry, public deletion page                   | **Not found** on web, native or admin (§6).                                                                                                                                                      |
| `is_erased` on agency resources                                       | **Not found.** Only promised in a docblock (`Creator.php:77`) and the registry note (`ErasureRegistry.php:153`). No agency resource emits it and no agency query filters `erased_at`.            |
| Assignment cancel                                                     | State machine only: `CampaignAssignmentStateMachine::cancel()` (`:600-624`). **No HTTP route or caller in `app/`.** No cancel notification is wired (`SendAssignmentNotifications.php:109-120`). |
| `PushTokenRegistry::revokeAllForUser()`                               | Exists, documented as the account-deletion seam, **not wired** (`PushTokenRegistry.php:101-108`). **[checked]**                                                                                  |
| Pennant flags, `AuditAction` verbs, 24-locale mail, `auth-*` limiters | All exist and have a convention to follow (§3.3, §3.5, §3.6).                                                                                                                                    |

## 2. Findings that overturn or sharpen the premise

**F1. "Revoke everything at request, then let a sign-in cancel" has no mechanism today, and the obvious one is the weakest.** Revoking all tokens is easy (`$user->tokens()->delete()`,
as password reset and lockout already do: `PasswordResetService.php:113`, `AccountLockoutService.php:90`). But the cancel is "sign in again", and a normal sign-in issues a full session
or a full bearer token. The 2026-10-20 note in the first inventory said "a successful sign-in during the grace is the cancel". That makes **a stolen password, or one accidental
sign-in by the owner, silently cancel an irreversible request and hand over a full session** (the first inventory flagged the stolen-password half). There is no "restricted
token" machinery: `CreatorTokenGuard` checks only creator type and suspension (`CreatorTokenGuard.php:72-74`, **[checked]**: it does not even check `erased_at`). §9 Q1 proposes a
mechanism that needs none.

**F2. The single login gate is `AuthService::evaluateGates()` and it is the right hook.** Both doors (SPA cookie `login()` and native bearer `issueToken()`) run it
(`AuthService.php:302-466`). It already contains the exact precedent: an erased account is answered **identically to an unknown email, before the password is checked**
(`:328-337`, **[checked]**), and suspension is answered **after** the password is verified so it never confirms a state to someone without the password (`:357-364`, **[checked]**).
A pending-deletion answer belongs in the second category (after the password and the 2FA check), never the first.

**F3. Web sessions are not revoked by anything the app does today except sign-out and erasure.** `SESSION_DRIVER=database` (`.env.example:44`, `config/session.php:21`,
**[checked]**). Suspension and password reset delete **tokens** only; web `sessions` rows are left to Sanctum's `AuthenticateSession` narrative (`PasswordResetService.php:106-108`).
Revoking "sessions" at request time therefore needs a new, explicit `DELETE FROM sessions WHERE user_id = ?`. The erasure engine already deletes `sessions` rows by `user_id` through its generic delete step
(`Erasure/Execution/Steps.php:42`, registry `ErasureRegistry.php:121`); the request flow should not hand-write a second statement: one shared helper, called from both (§8 D2).

**F4. The agency-side pass is not built, and it is not a 104b-del-only problem.** The first inventory (§7, §10) named three chunks: engine, request flow, **agency-side deleted-creator pass**.
The 2026-10-20 split put the pass under "AH-104b-del/c" without saying which. What happens in each state:

- **Pending (request made, up to 14 days):** the creator is still `approved` and `is_discoverable`; they would still appear in discovery, the roster's active lists, pickers, fan-outs and
  message digests (the consumers are listed in §3.4) while being unable to sign in.
- **Erased (after AH-104c's Erase):** `display_name` is null; `CampaignAssignmentReviewController.php:101` reads `$assignment->creator->display_name` bare; the erased row is live and
  not soft-deleted, so nothing 500s, but agency pages render a blank name and no localised label, because **no resource emits `is_erased`** (first inventory §7: ~20 resources).

So the minimum that must exist **before the first real erasure** is the agency-side pass; the minimum that must exist **before the first real request** is the pending exclusion. Both
are resource-shape and policy changes (stop-gates). §9 Q3 asks how to split them.

**F5. Auto-cancelling open assignments at request time is irreversible even if the creator cancels the request.** The state machine has no "un-cancel": `cancel()` writes `cancelled_at`,
`cancelled_reason`, `cancelled_by_user_id` and ends the assignment (`CampaignAssignmentStateMachine.php:600-624`). The first inventory recommended auto-cancel "as part of the request" (§5.2
(b)). If the creator cancels the deletion on day 3, the agency has lost its assignment for a request that was withdrawn. Also: **no notification is wired for cancel** and push for it is
explicitly ineligible (`PushEligibilityTest.php:71-79`), so "tell the agency" is new notification work, to real users, which is a stop-gate in its own right. The assignment question was
**never ruled** (open question 4 of the first inventory is not in the 2026-10-20 rulings). §9 Q4.

**F6. The re-auth path has no throttle of its own.** Login has `auth-login-email` (5/min per email and ip) and `auth-ip` (10/min), and the MFA code check on login is guarded by
`TwoFactorVerificationThrottle` (soft 5, hard 10 per 15 minutes). `2fa/disable` and `2fa/recovery-codes` use **neither** (an invalid code is just a 401): `DisableTwoFactorController.php:53-63`,
`RegenerateRecoveryCodesController.php:46-55`. A deletion endpoint that "re-auths like login" must therefore call the login throttles and the TOTP throttle **explicitly**; it cannot inherit
them from the disable route. A signed-in session with a stolen cookie would otherwise get unlimited password guesses against the deletion door.

**F7. A found pre-existing bug on the page we would copy.** `DisableTotpPage.vue:36` and `:99` navigate to route name `app.settings`; no route has that name (the only `settings` route is the
agency's, named `settings`, `auth/routes.ts:418`; **[checked]**). After disabling 2FA the push throws or no-ops, and the back link is dead. Not caused by this work; **not fixed here**; it is
the closest existing re-auth UI, so the new page must not copy the target. Goes to tech-debt (§9, Q9).

**F8. No in-app Security page exists, and the native "Settings" row is notification preferences only.** The creator account menu (`CreatorDashboardLayout.vue:334-348`) has: email, a web-only
theme toggle, a locale select, Notification settings, Sign out. The native bottom "More" sheet has a "Settings" row that also goes to notification preferences (`:432-441`). A Security entry is a
new menu item on both, and the biometric section earlier ruled native-only has no UI yet (so "Security page" today would hold exactly one section).

**F9. The public Google page has no Netlify-side guarantee in the repo.** There is no `netlify.toml` or `_redirects` in the repo (`universal-links-inventory.md:33-34`); the SPA fallback is a
dashboard rule, which C1-C7 of AH-103b verifies for `.well-known` only. A new public SPA route (for example `/delete-account`) works like any other SPA path **only if** that dashboard
fallback is on (it is today, since `/sign-in` and `/creator/*` load). The AASA covers `/creator/*` only (`public/.well-known/apple-app-site-association:6-7`), so a public path outside it is
not captured by the app (good). Privacy and terms are external URLs (`footerLinks.ts:75`, not SPA routes).

**F10. Everything a reviewer or the first store review sees on the native app is the in-app path.** Apple 5.1.1(v) needs deletion **initiable in the app**; the in-app path exists only if
the Security page ships with the feature flag **on** for the store build. A flag default-off is right for the first deploy, and it must be on before TestFlight build 3 is submitted for
review. (§9 Q7.)

## 3. Backend inventory

### 3.1 Login, the one hook, and the response shape

- Doors: `POST /api/v1/auth/login` (`LoginController`, `Identity/Routes/api.php:81-83`) and `POST /api/v1/auth/token` (`TokenLoginController`, `:90-92`; guard `creator_app`, creator-only,
  `AuthService.php:79, 105-111`). Admin has its own login and is out of scope.
- Gate order in `evaluateGates()` (`AuthService.php:313-463`): temporary lock → unknown email → **erased** → password (with lockout escalation) → **suspended** → agency suspended → wrong SPA →
  2FA. A pending-deletion check slots **after 2FA succeeds** (so the cancel proof is password **and** TOTP when enrolled) and **before** the session or token is issued.
- Failure shape: `{ errors: [{ id, status, code, title, meta }], meta: { request_id } }` via `ErrorResponse::single()` (`ErrorResponse.php:12-21`); mapping lives in
  `LoginFailureResponder.php:40-108` with a precedent arm for each of `auth.mfa_required` (401) and `auth.account_locked.*` (423). A new code (for example `auth.deletion_pending`, carrying
  the scheduled date in `meta`) is **one new `LoginResultStatus`, one responder arm, one i18n code** and is pinned by the frontend `i18n-auth-codes.spec.ts` in `apps/main` and `apps/admin`.
- Email verification is not a login gate (route middleware only), so an unverified creator can request deletion. 336 of 2,763 users were never verified (read-back B); some are bulk-invite
  prospects with a random password and `name = email` (PII map §2.1), who cannot sign in at all and so can only be erased by an admin. How many of the 336 are that kind was **not read**.

### 3.2 Re-auth, limiters and what to reuse

- Password check: `Hash::check` through `Hasher` (used by `DisableTwoFactorController.php:53-63`). TOTP then recovery code: `TwoFactorChallengeService::verify()` (TOTP, then recovery codes,
  emits `TwoFactorRecoveryCodeConsumed`). `TwoFactorService` is the only class allowed to touch Google2FA (isolation tests).
- Limiters (`IdentityServiceProvider.php:105-137`): `auth-ip` 10/min, `auth-login-email` 5/min (`login:{email}:{ip}`), `auth-password` 5/min, `auth-resend-verification` 1/min. The 429 shape is
  `rate_limit.exceeded` with `meta.seconds` and `Retry-After`, pinned by `AuthRateLimitTest.php:29-30, 62-74`. A new limiter follows `RateLimiter::for(...)` in a provider, and **must be added to
  `RateLimiterNeutralizer::ALLOWED_NAMES`** (`:73-78`) or the E2E suite cannot drive it.
- Escalation: a failure streak at login suspends the account (`AccountLockoutService::escalate()`, `:70-90`, which also deletes all tokens). The first inventory asked whether a failed-password
  streak at the deletion door escalates or only throttles (question 9). Not ruled. §9 Q6.
- There is no signed-in change-password route and `AuditAction::AuthPasswordChanged` has no producer (enum only, `AuditAction.php:34`).

### 3.3 Revocation at request time

| Thing               | Today                                                                                                                                      | What the request needs                                                                                                                              |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| Bearer tokens       | `$user->tokens()->delete()` (inline, twice)                                                                                                | The same call; cascades to `user_push_tokens` by FK **only on a hard delete of the token row** (`2026_10_10_100000_..._push_tokens:51`).            |
| Web sessions        | Not revoked anywhere but sign-out and erasure (F3)                                                                                         | An explicit `DELETE FROM sessions WHERE user_id = ?`, one shared helper with the engine's `sessions` delete (`Steps.php:42`).                       |
| Push rows           | `PushTokenRegistry::revokeAllForUser()`, unwired                                                                                           | Call it (a plain delete by `user_id`), explicitly: a soft delete would not cascade, though the request does not soft delete either.                 |
| The device itself   | Native sign-out calls `pushLogoutCleanup()` (needs a live bearer) then the revoke, then clears the local token (`useAuthStore.ts:376-391`) | The app must run its own cleanup **before** calling the request endpoint **or** accept that the server-side delete did it (the first inventory §6). |
| The requesting call | The request's own session or bearer is among the revoked                                                                                   | The response must be sent, then the client clears local state; a 401 on the next call is expected, not an error.                                    |

### 3.4 Where a creator in the grace must stop appearing (the "hidden" half)

Consumers of an approved, discoverable creator today (none filter `erased_at`, none will know "pending"). The 2026-10-20 inventory §6 named the fan-outs; the discovery side is new here:

| Surface                                                | Entry                                                                                                                                                                                      | Needs                                                                         |
| ------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------- |
| Discovery search and show                              | `AgencyCreatorDiscoveryController::index/show` (`:34-42`: approved and discoverable)                                                                                                       | Exclude pending and erased (or set `is_discoverable` false at request, §9 Q5) |
| Roster list and detail                                 | `AgencyCreatorController::index` (`:28-33`), `AgencyCreatorDetailController::show`                                                                                                         | Keep the row; emit a flag; no new action                                      |
| Connection request, campaign invite, talent-pool add   | `AgencyConnectionRequestController`, `CampaignAssignmentController::store` (`:117-127`), `TalentPoolMembershipController`                                                                  | Refuse for a pending or erased creator (422, not 404)                         |
| Applications list, assignment review, board cards      | `CampaignApplicationController` (`:93`), `CampaignAssignmentReviewController.php:101`, `BoardCardResource`                                                                                 | Null-safe name, flag, localised label on the SPA                              |
| Messaging contacts, digest, nudges, job-posted fan-out | `MessageableContactsFinder.php:55-68`, `MessageDigestService.php:124`, `SendIncompleteCreatorNudges`, `JobPostedFanOutService.php:206`                                                     | Skip pending and erased (mail to an address that is about to disappear)       |
| Resources that show the creator                        | `AgencyCreatorDetailResource`, `CreatorPublicProfileResource`, `CreatorDiscoveryResource`, `TalentPoolMemberResource`, `CampaignAssignmentResource`, `CampaignApplicationListItemResource` | `is_erased` (and `is_deletion_pending`?) plus a locale-independent label      |
| Admin creator list                                     | `AdminCreatorController::index` (`Creators/Routes/api.php:352-353`)                                                                                                                        | Reserved for AH-104c's "Ready to erase" view                                  |

Cheapest correct shape (a proposal for the plan, not a decision): **one query scope and one resource trait**, so "hidden" is defined once. Without it, ~20 resources and ~10 queries each carry
their own predicate and the next new surface forgets one. A failing-by-default architecture spec that fails on any agency-facing creator query without the scope would be the guard, the same shape
as the payment-state spec.

### 3.5 Assignments, mail and notifications

- `cancel()` requires a non-empty `reason`, sets the three cancel columns, writes `AssignmentCancelled` and accepts a **null actor** (`CampaignAssignmentStateMachine.php:600-666`), so a
  system cancel is possible without a user.
- No `AssignmentCancelled` notification or mail branch exists (`SendAssignmentNotifications.php:109-120`); the `NotificationType::AssignmentCancelled` case exists (`NotificationType.php:69`) and is
  push-ineligible (`PushEligibilityTest.php:71-79`).
- Mail: identity mailables are queued and 24-locale (`ResetPasswordMail`, `VerifyEmailMail`; `lang/*/auth.php`; `MailLocalizationTest`). **Mail to real users ships flag-gated, default OFF**
  (`WORKING-PROCESS` §5; Pennant classes like `MissingCreatorMailsEnabled`). The **confirmation mail to the creator** is both a security control (the first inventory's mitigation for a
  stolen-password request) and new mail to a real user. If its flag is off, the mitigation is off; the two flags cannot be independent. §9 Q8.
- No mail to an old address after an email change exists as a pattern, and a creator cannot change their email in-app (`UpdateMeController.php:16-21` accepts `preferred_language` only).

### 3.6 Flags, audit, admin, API shape

- Flags: Laravel Pennant, `features` table, snake_case `NAME`, default `false` closure, registered in `CreatorsServiceProvider::registerFeatureFlags()` (`:257-270`); admin toggle with a reason
  (`Admin/Routes/api.php:119-121`). The frontend reads **creator** flags only through `CreatorResource` → `wizard.flags` (`CreatorResource.php:218-222`), and the only consumer is the onboarding wizard
  (`useFeatureFlags.ts:6-9`). **No flag-gated menu item exists anywhere in the web app**, so gating the Security entry is a new pattern.
- Audit: dot-notation verbs (`AuditAction.php:22-25`); `account.erased` already exists. `account.deletion_requested` and `account.deletion_cancelled` fit; both written by `AuditLogger::log()` with
  the subject set, not by the `Audited` trait. The request writes the actor's ip and user agent (S1 rows), which the eventual erasure scrubs (rule S1).
- Envelope and errors: `{ data }` and `{ errors[] }`, not problem+json; `packages/api-client` is hand-written TypeScript (OpenAPI generation is future), so a new endpoint is a typed function plus a
  spec, and an error code is a new i18n key checked by `i18n-auth-codes.spec.ts`.

### 3.7 Specs this feature touches (to extend, or that could break)

`LoginTest`, `TokenLoginTest`, `TwoFactorLoginTest`, `TwoFactorDisableTest`, `AuthRateLimitTest`, `RateLimiterNeutralizerTest`, `ErasedLoginBlockTest` (the precedent for a gate spec),
`CreatorAppGuardSwapTest`, `CreatorTokenGuardTest`, `TokenRevocationTest`, `AuditActionEnumTest`, `CampaignAssignmentStateMachineTest`, `PushEligibilityTest`, `CreatorFeatureFlagsTest`,
`AdminFeatureFlagTest`; on the frontends `i18n-auth-codes.spec.ts` (main and admin), `i18n-locale-parity.spec.ts`, `creator-routes-guard.spec.ts:93-106` (the full creator route name list),
`CreatorDashboardLayout.spec.ts`, `DisableTotpPage.spec.ts` (the template, with its F7 bug pinned: `:82`).

## 4. Frontend inventory

- **One SPA serves everything:** `apps/main` (`@catalyst/main`) is the agency, brand and creator web app and the Capacitor 8 shell for iOS and Android (`capacitor.config.ts:13-16`; creator
  routes `layout: 'creator'`, `apps/main/src/modules/creators/routes.ts:18-159`, guard `requireAuth` only). `isNativePlatform()` is the one platform switch (`core/platform/isNativePlatform.ts:10-27`).
- **Menu:** `CreatorDashboardLayout.vue:283-348` (avatar menu) and `:422-441` (native bottom More). New entry: Security, both places.
- **Auth store** (`useAuthStore.ts`): `login` (cookie) and `loginWithToken` (native bearer, secure storage), `bootstrap`, and the install marker gate. **No slot for a "scheduled for deletion"
  step at sign-in** (closest: the `session_expired` banner on `SignInPage.vue:80-88`). The sign-in page already handles an inline MFA step (`:63-68`, `:221-226`), which is the model for a
  second step ("your account is scheduled for deletion on {date}: cancel it or stay signed out").
- **Re-auth UI:** `DisableTotpPage.vue` (full page, password and TOTP in one form, Vuetify fields, `role="alert"`). There is **no shared dialog primitive** in `packages/ui` (it is thin: button with a
  `danger` variant, empty state, badges); dialogs are Vuetify `v-dialog` and `v-bottom-sheet` in the app.
- **Error to message:** `resolveErrorMessage` maps `ApiError.code` through `te(code)` (`useErrorMessage.ts:4-13`).
- **Public routes:** `meta: { layout: 'auth' }` with no guards, as `accept-invitation` does (`auth/routes.ts:459-463`); a public `/delete-account` would follow it. The native auth layout omits the footer
  (`AuthLayout.native.spec.ts:84-89`), irrelevant to a page the app never links to.
- **i18n:** `vue-i18n`, **24 locales**, `apps/main/src/core/i18n/locales/{locale}/*.json`, with `i18n-locale-parity.spec.ts` failing on any missing or extra key or changed placeholder. Roughly 30 to 45
  keys (page, menu, dialog, errors, public page, the sign-in cancel step, the agency label) times 24 locales, plus the API's mail templates and `auth.deletion_pending`, plus `docs/i18n-glossary.md`
  terms ("account deletion", "grace period"). This is the largest mechanical cost in the chunk.
- **Native:** a full-screen page must respect safe areas and `useShellBack` (`useShellBack.ts:1-28`); native fields must meet the 16px rule (`native-field-text-size.spec.ts`).
- **Admin app:** creators list and detail exist (`apps/admin/src/modules/creators/routes.ts:24-56`, `CreatorDetailPage.vue`); **no erase or pending-deletion UI** (AH-104c; out of scope here).

## 5. Data and schema the chunk implies (to confirm in the plan, not decided)

- `users.deletion_requested_at` (nullable, indexed) and, for the clock, either `users.deletion_scheduled_for` or a computed `requested_at + 14 days` (a stored date survives a later change of
  the grace length; §9 Q10). `creators` needs no new column if the scope reads through `users`, which means a join in every agency query; **a denormalised `creators.deletion_requested_at`** removes it at
  the cost of two writers. Additive and nullable either way; **schema only, no backfill** (`WORKING-PROCESS` §5). **Pre-deploy snapshot is mandatory** (the entry already carries migrations).
- A `legal hold` column belongs to AH-104c and must not be built here, but the "Ready to erase" predicate (`deletion_requested_at < now() - 14 days and not held`) must read the **same** column the
  request writes, so the two chunks must agree on its name before either is planned.
- The request is stored on `users`, so it is **covered by the erasure engine's registry spec**, which fails CI on any unclassified column. The new columns must be classified (`ANON`/`KEEP`) in the same commit.

## 6. Production posture (read-only facts)

- Read-back B (2026-10-20): 2,752 creators; **0 suspended**, **0 soft-deleted**; 8 users with 2FA; 336 never verified; 4 personal access tokens, 88 sessions, 4 push tokens. So at launch the
  population that can use this flow is small, and the revoke-at-request step touches a handful of tokens and sessions per request.
- 709 assignments, **0** with a payment state (the engine's payment guard cannot trigger today). How many are non-terminal was **not read**: if §9 Q4 is "cancel", that number sizes the effect.
- Nothing in this chunk runs against production data until a creator presses the button; the flag keeps even that off.

## 7. What 104b-del must not do (boundaries)

- It never calls the erasure engine, never sets `erased_at`, and never deletes a row it does not own. Erasure remains AH-104c's act, by an admin.
- It does not build the admin queue, the hold, the Erase button, or the audit-snapshot cleanup (AH-104e).
- It does not change how suspension, lockout or password reset behave (their token deletes stay inline; only the new code uses the shared helper).

## 8. Proposed kickoff decisions (for Pedram to accept or change; structural intent named)

- **D1 — One gate, one door.** The "pending deletion" answer to a sign-in and the cancel both pass through `evaluateGates()`, so rate limits, lockout, MFA and audit apply once. _Intent: no second
  code path that can authenticate a person._
- **D2 — Revocation is one helper** shared by the request and the erasure engine (tokens, sessions, push). _Intent: a surface added to "everything that signs a person in" is revoked in both._
- **D3 — "Hidden" is one scope plus one resource flag**, guarded by an architecture spec. _Intent: a new agency query cannot forget the pending or erased creator._
- **D4 — The endpoint and the Security entry ship behind one flag, default off**, and the confirmation mail is not separately switchable (F10, §3.5). _Intent: no state in which a request
  is accepted without the owner being told._
- **D5 — The response reveals nothing before the password and MFA have passed** (F2). _Intent: the pending state never leaks to a person who only knows an email._

## 9. Questions for Pedram (each with my recommendation)

1. **How does the sign-in that cancels work? (F1)** (a) Any successful sign-in cancels, as ruled; (b) **a successful sign-in during the grace returns `auth.deletion_pending` with the date and issues
   nothing; a second call to the same door with `cancel_deletion: true` re-verifies the same credentials (and 2FA) and then cancels and signs in.** I recommend **(b)**: no restricted token, no new guard,
   a stolen password still cannot do more than cancel (and the mail tells the owner), and an accidental sign-in cancels nothing. (b) is a refinement of the ruling, so it needs your word.
2. **Re-auth, concretely.** Password always, plus TOTP or a recovery code when enrolled (as ruled), checked with the **login throttles** called explicitly (F6) and a new `auth-account-deletion` limiter
   (recommend 3/hour per user, **429 only; no escalation to suspension**, because suspending a creator for mistyping a password while deleting turns a safety feature into a lockout. Your call on
   question 9 of the first inventory). A typed word ("DELETE") as the last confirmation? Recommend **yes, and localised**.
3. **Agency-side pass: which chunk?** The pending exclusion (hide, refuse new invites and mail) is needed **before the first real request** and belongs here. The `is_erased` resource flag and
   label on ~20 resources is needed **before the first real erasure**, which is AH-104c's act. Recommend: **104b-del carries the scope, the resource flag, the label and the specs** (one pass,
   one review), and AH-104c only reads them. The alternative (a third chunk, AH-104f) is smaller per commit and puts a hard dependency in front of 104c.
4. **Open assignments (F5, never ruled).** (a) Block the request while any is non-terminal (a creator can be stuck; Apple dislikes that); (b) auto-cancel at **request** (irreversible if the request
   is withdrawn); (c) **leave them untouched at request, and cancel at erasure** (a new step in the engine's database transaction, system actor, fixed reason, in-app notice to the agency); (d) leave
   them forever. Recommend **(c)**: the cancel happens only when the person is actually gone, the agency is told once, and cancelling the request costs the agency nothing. It changes AH-104a (a
   stop-gate: the state machine and a notification to real users) and needs its own plan line. Until it exists, **a pending creator should not be invitable** (Q3) so the number cannot grow.
5. **Hide how?** Set `is_discoverable = false` at request (reversible by cancel only if we remember the previous value: another column) or **derive hidden from `deletion_requested_at` at read time** (no
   write, nothing to restore). Recommend **derive**.
6. **Failed-password streak at the deletion door:** throttle only (recommended, Q2) or escalate to suspension like login?
7. **Flag timing.** Default off at deploy (the standing rule) and **on before TestFlight build 3 goes to App Review**, because Apple needs the in-app path (F10). Confirm that the build-3 checklist
   carries the flag flip, and who flips it (the colleague, or you, through the admin flag toggle with a reason).
8. **The confirmation mail:** new mail to a real user. Same flag as the endpoint (recommend, D4) with **no separate switch**, one 24-locale template sent to the **current** address, no link that
   cancels (a link in a mail is a phishing and replay surface; cancelling is by signing in). Confirm.
9. **Public page content and URL.** A public SPA route outside `/creator/*` (recommend `/delete-account`) that says how to delete in the app and how to ask support if the person cannot sign in (the
   random-password prospect users and creators who lost their credentials). What is the support channel (an address, or a form)? A public page needs a real contact, and **it is the URL Google Play
   will ask for**, so it must exist before the Play listing. Also: may I add the F7 `app.settings` bug to tech-debt now, and fix it in this chunk since the new page sits next to it?
10. **The clock.** 14 days, stored as a date at request time (recommended) so a later policy change does not alter a request already made.
11. **Who may request.** Creators only, signed in on web or native (the 2026-10-20 scope; Apple binds the app, which is creator-only). A creator who is currently **suspended** cannot sign in, so cannot
    request; the admin-initiated path covers them (AH-104c). A creator mid-**onboarding** (not yet approved) can sign in and can request. Confirm the three cases.

## 10. Size, order and deploy shape

- **Honest size:** two sub-chunks if Q3 is "carry the pass": **(A)** request, grace, gate, cancel, revocation, audit, mail, flag, endpoints (API) and the sign-in cancel step; **(B)** the Security page,
  the menu entries, the public page, the agency-side scope and resource flag, 24-locale keys. Each ends green on its own. Roughly 2 to 4 API files per concern and ~20 resource edits for (B).
- **Hard stop-gates in this chunk:** a schema change, a new route set, a validation and security rule (re-auth), a gate in the login path, a policy change, new i18n x24, new mail, and (if Q4 is not
  "leave") a state-machine edge and notifications to real users.
- **Deploy shape:** pre-deploy snapshot, additive migration, **no backfill, no data write in a migration**, flag default off, no scheduler change (the clock is a column, not a job). The entry rides the
  pending combined deploy in `deploy-log.md`. First real use is on a named test creator, with the revoke and the cancel both walked on a device.
- **Walk required (device):** request on native, observe sign-out, sign in during the grace (see the pending screen), cancel, sign in normally; the same on web. Pedram's walk, as with AH-103d.

## Not verified

- **Assignment counts by state** in production (§6): not read. Needed only if Q4 is "cancel".
- **Whether the Netlify SPA fallback covers `/delete-account`** (F9): it covers every path today by observation of `/sign-in`, not by configuration in the repo.
- **How Google Play and Apple word the data-deletion requirement today** (from memory, as in the first inventory).
- **Sentry:** production read-back D says there is none. Not re-checked in code.
- **Native device behaviour** of the revoke-then-401 sequence (§3.3): read from code, not run.
