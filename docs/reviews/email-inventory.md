# Production email inventory — every email the platform can send

**Read-only artifact.** Compiled by an exhaustive sweep of `apps/api/app/Modules/**/Mail/*.php` (26
classes, all extending `Illuminate\Mail\Mailable`), cross-checked against every `lang/en/*.php` mail
key (`campaigns.php`, `creators.php`, `messages.php`, `auth.php`, `invitations.php`) and every
`resources/views/mail/**/*.blade.php` template, so nothing with copy-but-no-class or class-but-no-copy
was missed. Compiled 2026-08-24. No Laravel `Notification` classes exist in this codebase — every
email is a `Mailable`, queued and sent via `Mail::to(...)->queue(...)`.

**How to read "Status."** Three values are used:

- **live-and-sending** — unflagged, unconditional (or conditional only on a state that is currently
  true in production).
- **live-behind-a-flag, OFF** — the code path is correct and tested, but the Pennant feature flag that
  gates its mail leg is **OFF** in production today, so it is currently silent.
- **built-but-effectively-unreachable** — no feature flag blocks it, but an infrastructure precondition
  it depends on is not confirmed to exist in production (the standing scheduler blocker, below).

**The standing scheduler blocker**, load-bearing for two entries below (`IncompleteCreatorNudgeMail`,
`UnreadMessagesDigestMail`): `docs/reviews/RESUMPTION-TEMPLATE.md` records that the existence of a
production cron/scheduler (`schedule:run`) has **never been confirmed** (`supervisorctl status` /
`crontab -l` have not been read on the production box). Until that read happens, **every scheduled
command — `messages:send-digest`, `creators:send-incomplete-nudges`, `boards:scan-overdue` — is
assumed NOT to be firing.**

**Flag state, read from `docs/runbooks/deploy-log.md`'s latest entry (2026-08-19, AH-085) and
`docs/feature-flags.md`.** Four Pennant flags gate a mail leg; **all four are OFF and not armed in
production today**:

| Flag                                | Gates                                                                            | State   |
| ----------------------------------- | -------------------------------------------------------------------------------- | ------- |
| `job_posted_notifications_enabled`  | `JobPostedMail`                                                                  | **OFF** |
| `application_notifications_enabled` | `ApplicationSubmittedMail`, `ApplicationAcceptedMail`, `ApplicationRejectedMail` | **OFF** |
| `missing_creator_mails_enabled`     | `InviteReceivedMail`, `NewMessageMail`                                           | **OFF** |
| `incomplete_creator_nudge_enabled`  | `IncompleteCreatorNudgeMail`                                                     | **OFF** |

A fifth flag, `social_verification_enabled`, is **driver-based** (default ON while
`integrations.social.driver = mock`, which is this codebase's configured default — no real
Meta/TikTok/YouTube adapter is wired). It doesn't gate a mailable directly, but it gates whether
`VerifyPostedContentJob` ever runs at all, which is the only way `PostVerificationFailedMail` or
`ResubmitRequestedMail` can fire. `per_campaign_contract_enabled` is **default ON** (a documented
exception — gates no vendor) and gates whether the per-campaign contract endpoints (and therefore
`ContractAttachedMail` / `ContractAcceptedMail`) are reachable at all — they are live.

**On preferences.** A per-user notification-preference system exists (`notification_preferences`
table, `in_app` / `email` / `digest` channels, `GET`/`PATCH /me/notification-preferences`), but — with
one exception — **no mail leg reads it today.** The `email` channel is defined in the enum
(`NotificationChannel::Email`, default ON) but is **never consumed** by `NotificationService` or any
mailable dispatch site; every mailable below sends regardless of the recipient's `email` preference
row. The one exception is the messaging **digest** channel (below), which the daily-digest path does
read. This is a recorded gap — see `docs/tech-debt.md` — deliberately deferred to AH-084, not
implemented in this or any prior chunk. Every "Conditions" row below states this plainly rather than
implying an opt-out that isn't wired.

---

## Part 1 — Creator-facing emails (recipient: the creator)

### 1. `ProspectCreatorInviteMail`

- **Class / module:** `App\Modules\Creators\Mail\ProspectCreatorInviteMail` (Creators module).
- **Rides:** `creator.invited` (audit only — not a `NotificationType`; the recipient has no account
  yet to carry an in-app row).
- **Trigger:** An agency bulk-invites a prospective creator to its roster (the CSV bulk-invite flow,
  `BulkInviteService`). One email per invitee email address; a re-invite to an email that already has
  a live `prospect` relation is a no-op (the existing token is not re-issued).
- **Recipient:** The invited prospect (not yet a platform user in the full sense — a bootstrap `User` +
  `Creator` row is pre-created).
- **Conditions:** No feature flag. No debounce. No preference read (no account exists yet to hold
  one). Localized to the invitee's `preferred_language` at queue time.
- **Content (EN):**
  - Subject: `You've been invited to :agency on Catalyst` (`:agency` = the inviting agency's name)
  - Greeting: `Hi,`
  - Body: `:agency has invited you to join their roster on Catalyst. Click the button below to set up your creator profile.`
  - CTA button: **Get started** → `{frontend_main_url}/auth/accept-invite?token={unhashed_token}`
  - Footer line: `This invitation expires on :date.`
  - Footer line: `If you did not expect this invitation, you can safely ignore this email.`
- **Status:** live-and-sending.

### 2. `CreatorApprovedMail`

- **Class / module:** `App\Modules\Creators\Mail\CreatorApprovedMail` (Creators module).
- **Rides:** `creator.approved` — both an `AuditAction` and a `NotificationType` (dual-emit: this mail
  rides alongside an in-app row).
- **Trigger:** A platform admin approves a creator's onboarding application (`AdminCreatorController::approve()`).
- **Recipient:** The creator.
- **Conditions:** No feature flag. No debounce. No preference read. Localized to `preferred_language`
  at queue time. Carries the admin's optional free-text `welcome_message` when one was stamped on
  approval.
- **Content (EN):**
  - Subject: `Your Catalyst application has been approved`
  - Greeting: `Hi :name,`
  - Body: `Good news — your creator application has been approved. You now have full access to your Catalyst dashboard.`
  - Optional block (only when an admin left a welcome note): the note, rendered as a quoted line.
  - CTA button: **Go to your dashboard** → `{frontend_main_url}/creator/dashboard`
- **Status:** live-and-sending.

### 3. `CreatorRejectedMail`

- **Class / module:** `App\Modules\Creators\Mail\CreatorRejectedMail` (Creators module).
- **Rides:** `creator.rejected` — both an `AuditAction` and a `NotificationType` (dual-emit).
- **Trigger:** A platform admin rejects a creator's onboarding application (`AdminCreatorController::reject()`),
  supplying a mandatory free-text rejection reason.
- **Recipient:** The creator.
- **Conditions:** No feature flag. No debounce. No preference read. Localized to `preferred_language`
  at queue time. The rejection reason is quoted verbatim in the body (the same text also renders in
  the in-app banner).
- **Content (EN):**
  - Subject: `An update on your Catalyst application`
  - Greeting: `Hi :name,`
  - Body: `Thank you for applying to Catalyst. After review, we're unable to approve your application at this time.`
  - **Reason** (bold label) followed by a quoted block: `> {the admin's free-text reason}`
  - Line: `You can update your application and resubmit it for another review from your dashboard.`
  - CTA button: **Review your application** → `{frontend_main_url}/creator/dashboard`
- **Status:** live-and-sending.

### 4. `ConnectionRequestMail`

- **Class / module:** `App\Modules\Agencies\Mail\ConnectionRequestMail` (Agencies module).
- **Rides:** `agency_creator_relation.admin_requested` (Door 1, admin-initiated — audit only, no
  `NotificationType`, since the creator sees it via their ordinary connection-requests inbox) **or**
  the discovery-connect path's own relation-write (agency-initiated, same mail, no distinct verb
  documented at that call site).
- **Trigger:** Two call sites, same mail: (a) an agency sends (or re-sends) a discovery connection
  request to a creator (`AgencyConnectionRequestController`); (b) a platform admin sends a connection
  request on an agency's behalf — "Door 1" (`AdminCreatorConnectionController::sendRequest()`).
- **Recipient:** The creator.
- **Conditions:** No feature flag. No debounce. No preference read. Localized to `preferred_language`
  at queue time. This is the **only** email in the connection-request lifecycle — the agency is not
  notified when the creator accepts or declines (a deliberately deferred gap, recorded in
  `docs/tech-debt.md`).
- **Content (EN):**
  - Subject: `:agency wants to connect with you on Catalyst`
  - Greeting: `Hi :name,`
  - Body: `:agency would like to add you to their roster on Catalyst. Open your dashboard to accept or decline the request.`
  - CTA button: **View the request** → `{frontend_main_url}/creator/dashboard`
  - Footer line: `If you don't recognise this agency, you can simply decline — nothing changes until you accept.`
- **Status:** live-and-sending.

### 5. `AdminConnectedMail`

- **Class / module:** `App\Modules\Agencies\Mail\AdminConnectedMail` (Agencies module).
- **Rides:** `agency_creator_relation.admin_connected` — both an `AuditAction` (mandatory reason) and a
  `NotificationType` (`RelationAdminConnected`, dual-emit).
- **Trigger:** A platform admin **directly** connects an agency to a creator — "Door 2" — recording an
  offline agreement (`AdminCreatorConnectionController`).
- **Recipient:** The creator.
- **Conditions:** No feature flag. No debounce. No preference read. Localized to `preferred_language`
  at queue time. Fires immediately (no confirmation step) since it records an already-made agreement.
- **Content (EN):**
  - Subject: `You are now connected with :agency on Catalyst`
  - Greeting: `Hi :name,`
  - Body: `A Catalyst administrator has connected you with :agency on the platform.`
  - CTA button: **Go to your dashboard** → `{frontend_main_url}/creator/dashboard`
  - Footer line: `If this connection is unexpected, please contact Catalyst support.`
- **Status:** live-and-sending.

### 6. `CreatorBlacklistedMail`

- **Class / module:** `App\Modules\Agencies\Mail\CreatorBlacklistedMail` (Agencies module).
- **Rides:** `creator.blacklisted` — audit only, **deliberately excluded** from `NotificationType`
  ("an unsolicited in-app notice of one's own blacklisting is counterproductive" — email-only by
  design).
- **Trigger:** An agency (or brand, for a brand-scoped blacklist) blacklists a creator
  (`CreatorBlacklistController`).
- **Recipient:** The creator.
- **Conditions:** **Not a Pennant flag** — gated by a **per-agency setting**,
  `agency.settings.blacklist_notification_policy`, default **OFF** for every agency. Each agency
  chooses independently whether its blacklist actions notify the creator. No debounce. No preference
  read. Localized to `preferred_language` at queue time. Deliberately generic: carries **no reason, no
  scope, no hard/soft type** — the free-text reason is GDPR-sensitive and never leaves the agency
  boundary.
- **Content (EN):**
  - Subject: `:agency has updated your collaboration status on Catalyst`
  - Greeting: `Hi :name,`
  - Body: `:agency has updated your collaboration status on Catalyst. If you have any questions, please reach out to them directly.`
  - Closing line: `Thank you for being part of Catalyst.`
  - (No CTA button in this template.)
- **Status:** live, but **silent for every agency by default** — an agency must explicitly opt its
  settings into `blacklist_notification_policy = true` before this ever sends.

### 7. `RelationDisconnectedMail`

_(Both-parties email — also listed in Part 2.)_ See **Part 2, entry 1**.

### 8. `IncompleteCreatorNudgeMail`

- **Class / module:** `App\Modules\Creators\Mail\IncompleteCreatorNudgeMail` (Creators module).
- **Rides:** No dedicated `AuditAction`/`NotificationType` — a scheduled-command side effect, stamped
  via `creators.incomplete_nudge_sent_at` (once-only).
- **Trigger:** The daily scheduled command `creators:send-incomplete-nudges`, which finds self-serve
  creators sitting `application_status = incomplete` for 48+ hours who have never been nudged.
- **Recipient:** The creator. Two copy variants selected by `users.email_verified_at`:
  - **`verify`** — email not yet verified.
  - **`finish`** — email verified, but onboarding not finished.
- **Conditions:** Gated by Pennant flag `incomplete_creator_nudge_enabled`, default **OFF** — currently
  **OFF in production**. Once-only (the `incomplete_nudge_sent_at` stamp prevents a second send to the
  same creator). No preference read. Localized to `preferred_language` at queue time. **Additionally**
  depends on the production scheduler actually running `creators:send-incomplete-nudges` daily, which
  is unconfirmed (the standing scheduler blocker) — so even if the flag were armed, nothing would send
  until that is verified.
- **Content (EN) — `verify` variant:**
  - Subject: `Finish setting up your Catalyst account`
  - Greeting: `Hi :name,`
  - Body: `You started creating your Catalyst creator account but haven't confirmed your email address yet. Confirm it now to pick up where you left off and finish your registration.`
  - CTA button: **Confirm your email** → `{frontend_main_url}/auth/verify-email?token=…`
  - Footer line: `This link expires in :hours hours. If it lapses, you can request a new one from the sign-in page.`
  - Footer line: `If you didn't start this registration, you can safely ignore this email.`
- **Content (EN) — `finish` variant:**
  - Subject: `Finish setting up your Catalyst creator profile`
  - Greeting: `Hi :name,`
  - Body: `You started setting up your Catalyst creator profile but haven't finished yet. Pick up where you left off to complete your registration.`
  - CTA button: **Finish your profile** → `{frontend_main_url}/onboarding`
  - Footer line: `If you've already finished your profile, you can safely ignore this email.`
- **Status:** **live-behind-a-flag, OFF** — and additionally blocked by the unverified scheduler even
  if armed.

### 9. `JobPostedMail`

- **Class / module:** `App\Modules\Campaigns\Mail\JobPostedMail` (Campaigns module).
- **Rides:** `campaign.job_posted` — both an `AuditAction` and a `NotificationType`.
- **Trigger:** An agency flips a campaign's `listed_on_jobs_board` from `false` to `true`
  (`JobPostedFanOutService::send()`), fanning out to that agency's reachable roster.
- **Recipient:** Every creator on the agency's roster who is approved, rostered, and not
  brand-hard-blacklisted from the listing brand — capped at **50 per run, oldest-roster-first**, stamped
  once per `(campaign, creator)` so a re-list never re-notifies the same creator.
- **Conditions:** Gated by Pennant flag `job_posted_notifications_enabled`, default **OFF** — currently
  **OFF in production**. No debounce beyond the once-per-`(campaign, creator)` stamp. No preference
  read. Localized to `preferred_language` at queue time. The email carries **no brand name** — only
  the agency name, the campaign name, and a deep link — because the brand is board content behind a
  visibility predicate an email is not behind.
- **Content (EN):**
  - Subject: `:agency posted a new job`
  - Greeting: `Hi :name,`
  - Body: `:agency has listed a new job on your board: ":campaign". Open it to see the details and apply.`
  - CTA button: **View the job** → `{frontend_main_url}/creator/jobs` (the job listing)
  - Footer line: `You are receiving this because you are on the :agency roster.`
- **Status:** **live-behind-a-flag, OFF.**

### 10. `ApplicationAcceptedMail`

- **Class / module:** `App\Modules\Campaigns\Mail\ApplicationAcceptedMail` (Campaigns module).
- **Rides:** `campaign_application.accepted` — both an `AuditAction` and a `NotificationType`.
- **Trigger:** An agency accepts a creator's jobs-board application (`CampaignApplicationNotifier::accepted()`),
  which also creates a new `invited` assignment.
- **Recipient:** The creator.
- **Conditions:** Mail leg gated by Pennant flag `application_notifications_enabled`, default **OFF** —
  currently **OFF in production** (the in-app row still writes regardless — flag gates mail only). No
  debounce. No preference read. Localized to `preferred_language` at queue time. Deliberately worded
  as an **invitation**, not a commitment — "review the offer," never "you have the job" — since the
  accept only creates a standard invited assignment the creator must still accept.
- **Content (EN):**
  - Subject: `Your application for :campaign was accepted`
  - Greeting: `Hi :name,`
  - Body: `:agency accepted your application for ":campaign" and sent you an offer. Open the assignment to review the terms, then accept or decline.`
  - CTA button: **View the offer** → `{frontend_main_url}/creator/assignments/{assignment_ulid}`
- **Status:** **live-behind-a-flag, OFF.**

### 11. `ApplicationRejectedMail`

- **Class / module:** `App\Modules\Campaigns\Mail\ApplicationRejectedMail` (Campaigns module).
- **Rides:** `campaign_application.rejected` — both an `AuditAction` and a `NotificationType`, with two
  causes sharing one verb.
- **Trigger:** Two call sites, one mail, two body variants chosen by `ApplicationRejectionCause`:
  - **`agency_rejected`** — the agency reviews the application and answers no.
  - **`campaign_closed`** — the campaign goes `completed`/`cancelled` while the application is still
    pending, auto-rejecting it (`CampaignApplicationNotifier::rejected()`).
- **Recipient:** The creator.
- **Conditions:** Mail leg gated by `application_notifications_enabled`, default **OFF** — currently
  **OFF in production** (in-app row still writes). No debounce. No preference read. Localized to
  `preferred_language` at queue time. **No agency-supplied reason is ever collected or shown** — by
  design (D4) — the copy is a generic "not selected this time."
- **Content (EN) — shared subject:** `An update on your application for :campaign`
  - Greeting: `Hi :name,`
  - **`agency_rejected` body:** `Thank you for applying to ":campaign". You were not selected for this job. New jobs are posted to your board regularly.`
  - **`campaign_closed` body:** `Thank you for applying to ":campaign". The campaign has closed, so your application will not be taken forward. New jobs are posted to your board regularly.`
  - CTA button (both variants): **View your jobs board** → `{frontend_main_url}/creator/jobs`
- **Status:** **live-behind-a-flag, OFF.**

### 12. `InviteReceivedMail` — AH-083 ①

- **Class / module:** `App\Modules\Campaigns\Mail\InviteReceivedMail` (Campaigns module).
- **Rides:** `assignment.invited` (a `NotificationType`) for the fresh path; `assignment.re_invited`
  (audit-only, no `NotificationType` — the in-app row always writes as `AssignmentInvited` regardless
  of which of the two fires) for both re-offer paths.
- **Trigger:** Fires identically on **all three** paths that land an assignment on `invited`
  (`SendAssignmentNotifications::notifyCreatorOfInvite()`):
  1. A fresh invite (`CampaignInvitationService::invite()`).
  2. The AH-035 re-offer after the creator declined.
  3. The agency's re-offer answering the creator's own counter-offer.
- **Recipient:** The creator. Copy discriminator `outcome`: **`fresh`** for path 1; **`re_offer`** for
  both paths 2 and 3 (the creator's experience is identical either way).
- **Conditions:** Mail leg gated by Pennant flag `missing_creator_mails_enabled`, default **OFF** —
  currently **OFF in production**. The in-app row is **not** gated by this flag (dual-emit, ungated).
  No debounce. No preference read. Localized to `preferred_language` at queue time.
- **Content (EN) — `fresh` variant:**
  - Subject: `You have a new offer for :campaign`
  - Greeting: `Hi :name,`
  - Body: `You've been invited to work on ":campaign". Open the assignment to review the offer and respond.`
  - CTA button: **View the offer** → `{frontend_main_url}/creator/assignments/{assignment_ulid}`
- **Content (EN) — `re_offer` variant:**
  - Subject: `An updated offer for :campaign`
  - Greeting: `Hi :name,`
  - Body: `An updated offer is waiting for you on ":campaign". Open the assignment to review it and respond.`
  - CTA button: **View the offer** → `{frontend_main_url}/creator/assignments/{assignment_ulid}`
- **Status:** **live-behind-a-flag, OFF.**

### 13. `ContractAttachedMail`

- **Class / module:** `App\Modules\Campaigns\Mail\ContractAttachedMail` (Campaigns module).
- **Rides:** **Nothing — orphaned from the type system.** `Contract` does not use the `Audited` trait,
  no `AuditAction` case exists for a contract attach, and there is no `NotificationType`. It is a
  direct side effect of the attach endpoint with no accompanying audit row or in-app notification.
- **Trigger:** An agency attaches a per-campaign contract for a creator's assignment to review
  (`CampaignAssignmentContractController::attach()`).
- **Recipient:** The creator.
- **Conditions:** Reachable only while `per_campaign_contract_enabled` is ON (**default ON in
  production** — live). No debounce. No preference read. Localized to `preferred_language` at queue
  time.
- **Content (EN):**
  - Subject: `Contract ready for :campaign`
  - Greeting: `Hi :name,`
  - Body: `A contract for ":campaign" is ready for your review. Open the assignment to read the terms and accept.`
  - CTA button: **Review the contract** → `{frontend_main_url}/creator/assignments/{assignment_ulid}`
- **Status:** live-and-sending.

### 14. `DraftReviewedMail`

- **Class / module:** `App\Modules\Campaigns\Mail\DraftReviewedMail` (Campaigns module).
- **Rides:** `assignment.draft_approved`, `assignment.revision_requested`, `assignment.draft_rejected`
  — all three are `AuditAction`s and `NotificationType`s.
- **Trigger:** The agency reviews a creator's submitted draft (`SendAssignmentNotifications::notifyCreatorOfReview()`),
  landing on one of three outcomes. **Exception:** when the approval also completes the assignment
  (a campaign where creators don't post the deliverable), this email is deliberately **suppressed** —
  the in-app row still writes, but only `AssignmentCompletedOnApprovalMail` (below) sends, to avoid two
  emails seconds apart for one click.
- **Recipient:** The creator.
- **Conditions:** No feature flag — unconditional except for the completes-on-approval suppression
  above. No debounce. No preference read. Localized to `preferred_language` at queue time. Carries the
  reviewer's free-text feedback verbatim when present (revision/reject only; approvals carry none).
  Includes the draft round number (`Draft :n`) when the transition context carries one.
- **Content (EN) — `approved` variant:**
  - Subject: `Your draft for :campaign was approved`
  - Greeting: `Hi :name,`
  - Body: `Good news — your draft for ":campaign" was approved. You can now post it and submit the live link.`
  - CTA button: **View the assignment** → `{frontend_main_url}/creator/assignments/{assignment_ulid}`
- **Content (EN) — `revision_requested` variant:**
  - Subject: `Changes requested on your :campaign draft`
  - Greeting: `Hi :name,`
  - Body: `The agency has requested changes to your draft for ":campaign". Review the feedback below and resubmit.`
  - Feedback block: **Feedback:** followed by `> {the reviewer's free-text feedback}` when present.
  - CTA button: **View the assignment** → `{frontend_main_url}/creator/assignments/{assignment_ulid}`
- **Content (EN) — `rejected` variant:**
  - Subject: `An update on your :campaign draft`
  - Greeting: `Hi :name,`
  - Body: `After review, your draft for ":campaign" was not accepted and the assignment has been closed.`
  - Feedback block: **Feedback:** followed by `> {the reviewer's free-text feedback}` when present.
  - CTA button: **View the assignment** → `{frontend_main_url}/creator/assignments/{assignment_ulid}`
- **Status:** live-and-sending.

### 15. `ResubmitRequestedMail`

- **Class / module:** `App\Modules\Campaigns\Mail\ResubmitRequestedMail` (Campaigns module).
- **Rides:** `assignment.resubmit_requested` / `assignment.resubmit_requested_in_place` —
  `AuditAction`s that are **deliberately excluded** from `NotificationType` (mail-only, no in-app row).
- **Trigger:** The agency resolves a failed auto-verification of a creator's posted content
  (`CampaignAssignmentResolutionController`), in one of two modes:
  - **`fresh`** (ACT2) — sent back to `approved`; the creator submits a brand-new post link.
  - **`in_place`** (ACT3) — stays `posted`; the creator edits the existing post link in place.
- **Recipient:** The creator.
- **Conditions:** No feature flag on this mail directly, but only reachable when auto-verification ran
  in the first place, i.e. `social_verification_enabled` is active (**default ON** while the social
  driver is `mock`, which is this codebase's configured default — reachable in this deployment). No
  debounce. No preference read. Localized to `preferred_language` at queue time. Carries the agency's
  free-text note verbatim when present.
- **Content (EN) — `fresh` mode:**
  - Subject: `Action needed on your :campaign post`
  - Greeting: `Hi :name,`
  - Body: `The agency could not verify your post for ":campaign" and has asked you to submit a new post link. Open the assignment to resubmit.`
  - Note block: **Note from the agency:** followed by `> {the agency's free-text note}` when present.
  - CTA button: **Open the assignment** → `{frontend_main_url}/creator/assignments/{assignment_ulid}`
- **Content (EN) — `in_place` mode:**
  - Subject: `Action needed on your :campaign post`
  - Greeting: `Hi :name,`
  - Body: `The agency could not verify your post for ":campaign" and has asked you to fix the submitted link. Open the assignment to update it.`
  - Note block: **Note from the agency:** followed by `> {the agency's free-text note}` when present.
  - CTA button: **Open the assignment** → `{frontend_main_url}/creator/assignments/{assignment_ulid}`
- **Status:** live-and-sending (conditional on the auto-verification job having run at all).

### 16. `AssignmentCompletedOnApprovalMail`

- **Class / module:** `App\Modules\Campaigns\Mail\AssignmentCompletedOnApprovalMail` (Campaigns module).
- **Rides:** `assignment.completed_on_approval` — both an `AuditAction` and a `NotificationType`.
- **Trigger:** A draft approval that also **completes** the assignment, on a campaign where the agency
  publishes the deliverable itself (`creator_posts_content = false`) — there is no post for the
  creator to make. This is the ONE email sent for that click (see `DraftReviewedMail`'s suppression
  note above).
- **Recipient:** The creator.
- **Conditions:** No feature flag. No debounce. No preference read. Localized to `preferred_language`
  at queue time. The copy is constrained to never say "posted" or "verified" — nothing was published
  through the platform.
- **Content (EN):**
  - Subject: `Your work for :campaign is complete`
  - Greeting: `Hi :name,`
  - Body: `Your draft for ":campaign" has been approved. On this campaign the agency publishes the content, so your assignment is now complete — there is nothing further for you to do.`
  - CTA button: **View the assignment** → `{frontend_main_url}/creator/assignments/{assignment_ulid}`
- **Status:** live-and-sending.

### 17. `PostManuallyVerifiedMail`

- **Class / module:** `App\Modules\Campaigns\Mail\PostManuallyVerifiedMail` (Campaigns module).
- **Rides:** `assignment.manually_verified` — both an `AuditAction` (mandatory reason) and a
  `NotificationType`.
- **Trigger:** The agency manually overrides a failed auto-verification, accepting the creator's post
  despite the automated check's failure (`SendAssignmentNotifications::notifyCreatorOfManualVerification()`).
- **Recipient:** The creator.
- **Conditions:** No feature flag. No debounce. No preference read. Localized to `preferred_language`
  at queue time. The agency's internal override reason is **not** surfaced to the creator (audit-trail
  only).
- **Content (EN):**
  - Subject: `Your post for :campaign was accepted`
  - Greeting: `Hi :name,`
  - Body: `Good news — the agency has reviewed and accepted your post for ":campaign". No further action is needed.`
  - CTA button: **View the assignment** → `{frontend_main_url}/creator/assignments/{assignment_ulid}`
- **Status:** live-and-sending.

### 18. `NewMessageMail` — AH-083 ⑧

- **Class / module:** `App\Modules\Messaging\Mail\NewMessageMail` (Messaging module).
- **Rides:** No dedicated audit/notification verb of its own — it rides alongside the pre-existing,
  unconditional in-app types `message.received_by_creator` / `message.relationship_received_by_creator`,
  which fire regardless of this mail.
- **Trigger:** An agency member sends a message to a creator, on either of two thread models, via the
  ONE checkpoint `DebouncedMessageMailer::maybeSend()`:
  - **`campaign`** context — a campaign-assignment thread (`SendMessageNotifications`'s agency→creator tail).
  - **`relationship`** context — a 1:1 connected agency↔creator DM (`RelationshipMessageNotifications`'s
    agency→creator tail).
    Creator→agency messages never reach this mailer (D4 — this chunk emails creators only).
- **Recipient:** The creator.
- **Conditions:** Gated by Pennant flag `missing_creator_mails_enabled`, default **OFF** — currently
  **OFF in production**; when the flag is OFF, the debounce table is never even touched (a total
  no-op). **Debounce rule** (when the flag is ON): email on the first unread message in a thread;
  re-armed once 30 minutes have passed since that thread's last **emailed** notification to that
  recipient; never more than one email per `(thread, recipient)` per 30 minutes — enforced by an
  atomic upsert on `message_email_debounces`. No preference read. Localized to `preferred_language` at
  queue time.
- **Content (EN) — `campaign` context:**
  - Subject: `New message about :counterparty` (`:counterparty` = the campaign name)
  - Greeting: `Hi :name,`
  - Body: `:sender sent you a new message about ":counterparty".`
  - CTA button: **Open the conversation** → `{frontend_main_url}/creator/assignments/{assignment_ulid}`
- **Content (EN) — `relationship` context:**
  - Subject: `New message from :counterparty` (`:counterparty` = the agency name)
  - Greeting: `Hi :name,`
  - Body: `:sender at :counterparty sent you a new message.`
  - CTA button: **Open the conversation** → `{frontend_main_url}/creator/messages/{agency_ulid}` (keyed
    by the agency's ulid, not the thread's own — the creator-side route parameter)
- **Status:** **live-behind-a-flag, OFF.**

### 19. `UnreadMessagesDigestMail`

_(Both-parties email — also listed in Part 2.)_ See **Part 2, entry 2**.

---

## Part 2 — Both-parties emails (recipient: creator AND agency, same mailable)

### 1. `RelationDisconnectedMail`

- **Class / module:** `App\Modules\Agencies\Mail\RelationDisconnectedMail` (Agencies module).
- **Rides:** `agency_creator_relation.disconnected` — an `AuditAction` (mandatory reason) and a
  `NotificationType` (`RelationDisconnected`, one type for both directions, dual-emit).
- **Trigger:** A platform admin severs a roster relationship between an agency and a creator
  (`AdminCreatorConnectionController::notifyDisconnect()`) — the platform's first termination path.
- **Recipient:** **Both parties**, one email each: the creator, and **every active member** of the
  agency (not just the inviting one). One mailable, direction-agnostic — the recipient's own name is
  the greeting, the other party is the `:counterparty` (the agency name for the creator; the creator's
  display name for each agency member).
- **Conditions:** No feature flag. No debounce. No preference read. Localized to each recipient's own
  `preferred_language` at queue time. Deliberately informational — no CTA button, and the reason is
  never surfaced (audit-only).
- **Content (EN):**
  - Subject: `Your connection with :counterparty on Catalyst has ended`
  - Greeting: `Hi :name,`
  - Body: `A Catalyst administrator has ended your working connection with :counterparty. You are no longer connected on the platform.`
  - Footer line: `If this is unexpected, please contact Catalyst support.`
  - (No CTA button in this template.)
- **Status:** live-and-sending.

### 2. `UnreadMessagesDigestMail`

- **Class / module:** `App\Modules\Messaging\Mail\UnreadMessagesDigestMail` (Messaging module).
- **Rides:** No `AuditAction` of its own — an aggregation read against the pre-existing
  `message.received_by_creator` / `message.received_by_agency` `NotificationType`s (campaign-assignment
  threads only; 1:1 relationship-thread messages are **not** included in the digest — deferred, AH-010
  D5).
- **Trigger:** The daily scheduled command `messages:send-digest`, which asks `MessageDigestService`
  for every user with at least one unread human message (system messages excluded) on a
  campaign-assignment thread they can see.
- **Recipient:** **Both parties** — any agency's notifiable member (admin/manager) with unread threads
  in their own agency, and any creator with unread threads on their own assignments. One aggregated
  email per opted-in recipient, listing every unread thread.
- **Conditions:** **No Pennant flag.** Gated entirely by the recipient's own **`digest`** channel
  preference (`NotificationChannel::Digest`) for the `message.received_by_*` type — the **one** mail
  leg in the whole platform that actually reads the preferences system. Default is **OFF** (opt-in);
  a toggle for it is exposed on the Notification Preferences page for messaging types specifically. No
  per-message debounce (it's a daily aggregate, not itself debounced) — and the docblock records that
  it is **deliberately not cross-suppressed** against `NewMessageMail` (AH-083 kickoff Q6): a creator
  opted into both can see the same unread thread reported twice in short order — an accepted, named
  duplication, not a bug. Renders in **English only** for every recipient — no per-recipient locale is
  applied at the send site (a recorded tech-debt item, "Digest + agency-invite emails are
  English-only"). **Additionally** depends on the production scheduler actually running
  `messages:send-digest` daily, which is unconfirmed (the standing scheduler blocker) — so even an
  opted-in user receives nothing today.
- **Content (EN):**
  - Subject: `You have unread messages`
  - Greeting: `Hi :name,`
  - Intro: `You have :count unread message(s) across :threads conversation(s).`
  - One line per thread: `:campaign with :counterparty — :count unread`
  - CTA button: **Open your messages** → `{frontend_main_url}/notifications`
- **Status:** **built-but-effectively-unreachable** — unflagged and opt-in-toggleable in the UI, but
  (a) default OFF and not exposed as an obvious toggle outside the messaging preference group, and (b)
  the daily command that would send it is assumed not running in production (unverified scheduler).

---

## Part 3 — Agency-facing emails (recipient: an agency member)

### 1. `ApplicationSubmittedMail`

- **Class / module:** `App\Modules\Campaigns\Mail\ApplicationSubmittedMail` (Campaigns module).
- **Rides:** `campaign_application.submitted` — an `AuditAction` since the jobs-board apply chunk, a
  `NotificationType` since this chunk.
- **Trigger:** A creator applies to a campaign listed on the jobs board
  (`CampaignApplicationNotifier::submitted()`).
- **Recipient:** **Every admin and manager** of the agency (`Agency::notifiableMembers()` — staff
  excluded by that method's load-bearing exclusion, a recorded pre-existing asymmetry). One email per
  member — a popular listing can mean N applications × (admins+managers) mails; a per-campaign digest
  is the named future move if that becomes an inbox pattern.
- **Conditions:** Mail leg gated by Pennant flag `application_notifications_enabled`, default **OFF** —
  currently **OFF in production** (in-app row still writes to every member regardless). No debounce.
  No preference read. Localized to each member's `preferred_language` at queue time. The creator's
  free-text application note is deliberately **not** included in the email body (it can be forwarded
  outside the platform; the notice links to the tab where the note is read under proper
  authorization).
- **Content (EN):**
  - Subject: `New application for :campaign`
  - Greeting: `Hi :name,`
  - Body: `:creator applied to ":campaign". Open the campaign to review the application and send an offer.`
  - CTA button: **Review the application** → `{frontend_main_url}/campaigns/{campaign_ulid}`
- **Status:** **live-behind-a-flag, OFF.**

### 2. `DraftSubmittedForReviewMail`

- **Class / module:** `App\Modules\Campaigns\Mail\DraftSubmittedForReviewMail` (Campaigns module).
- **Rides:** `assignment.draft_submitted` — both an `AuditAction` and a `NotificationType`.
- **Trigger:** A creator submits a draft for review on their assignment
  (`SendAssignmentNotifications::notifyAgencyOfSubmission()`).
- **Recipient:** The assignment's **single inviting agency member** (`invited_by_user_id`) by email —
  a deliberate asymmetry from the in-app leg, which fans out to every admin+manager (no agency-wide
  shared inbox exists yet — recorded in `docs/tech-debt.md`).
- **Conditions:** No feature flag. No debounce. No preference read. Localized to the inviter's
  `preferred_language` at queue time. Includes the draft round number (`Draft :n`) when known.
- **Content (EN):**
  - Subject: `:creator submitted a draft for review`
  - Greeting: `Hi :name,`
  - Body: `:creator has submitted a draft for ":campaign". Open the campaign to approve it, request changes, or reject it.`
  - Optional round line: `**Draft :n**`
  - CTA button: **Review the draft** → `{frontend_main_url}/campaigns/{campaign_ulid}`
- **Status:** live-and-sending.

### 3. `PostVerificationFailedMail`

- **Class / module:** `App\Modules\Campaigns\Mail\PostVerificationFailedMail` (Campaigns module).
- **Rides:** **Nothing — orphaned from the type system.** A failed auto-verification does not drive a
  state transition (the assignment stays `posted`), so no `AuditAction`/`NotificationType` fires for
  it at all; this mail is the only record a recipient sees of the event happening.
- **Trigger:** The automatic post-verification job cannot confirm a creator's submitted post link
  (`VerifyPostedContentJob`), for one of two reasons: `not_found` or `mismatch`.
- **Recipient:** The assignment's single inviting agency member.
- **Conditions:** Only reachable when `social_verification_enabled` is active (**default ON** while
  the social driver is `mock` — reachable in this deployment; would flip to OFF the moment a real
  Meta/TikTok/YouTube adapter is wired). No debounce. No preference read. Localized to the recipient's
  `preferred_language` at queue time.
- **Content (EN):**
  - Subject: `Could not verify the post for :campaign`
  - Greeting: `Hi :name,`
  - Body: `We could not automatically verify :creator's post for ":campaign". Please review the submitted link.`
  - **What happened:** followed by one of:
    - `not_found` reason: `The post could not be found at the submitted link.`
    - `mismatch` reason: `The post at the submitted link does not appear to belong to the creator's connected account.`
  - CTA button: **Review the assignment** → `{frontend_main_url}/campaigns/{campaign_ulid}`
- **Status:** live-and-sending (conditional on the auto-verification job having run at all).

### 4. `ContractAcceptedMail`

- **Class / module:** `App\Modules\Campaigns\Mail\ContractAcceptedMail` (Campaigns module).
- **Rides:** `assignment.contracted` — both an `AuditAction` and a `NotificationType`.
- **Trigger:** A creator accepts the per-campaign contract that was attached to their assignment
  (`SendAssignmentNotifications::notifyAgencyOfContractAcceptance()`). **Guarded**: never fires on a
  contract-less advance (`requires=false` auto-advance or the agency's "proceed without a contract") —
  a corrected false-fire from before the contract-flow decouple chunk.
- **Recipient:** The assignment's single inviting agency member.
- **Conditions:** Reachable only while `per_campaign_contract_enabled` is ON (**default ON in
  production** — live). No debounce. No preference read. Localized to the recipient's
  `preferred_language` at queue time.
- **Content (EN):**
  - Subject: `:creator accepted the contract`
  - Greeting: `Hi :name,`
  - Body: `:creator has accepted the contract for ":campaign". They can now begin work on their draft.`
  - CTA button: **View the campaign** → `{frontend_main_url}/campaigns/{campaign_ulid}`
- **Status:** live-and-sending.

### 5. `InviteAgencyUserMail`

- **Class / module:** `App\Modules\Agencies\Mail\InviteAgencyUserMail` (Agencies module).
- **Rides:** `invitation.created` (`AuditAction`; not a `NotificationType` — the invitee has no account
  yet).
- **Trigger:** An existing agency admin/manager invites a new member to join the agency
  (`AgencyInvitationService`).
- **Recipient:** The invitee (a prospective agency member — creator-side recipients never receive this
  one; grouped here as "agency-facing" since the invitee is joining an agency).
- **Conditions:** No feature flag. No debounce. No preference read (no account may yet exist).
  **Renders in English only** — no per-recipient locale is applied at this send site (the same
  recorded tech-debt item as the digest). The magic-link token is unhashed only in this email and in
  the create-response; only its SHA-256 hash is persisted.
- **Content (EN):**
  - Subject: `You've been invited to join :agency on :app`
  - Greeting: `Hi :name,`
  - Body: `:inviter has invited you to join :agency as :role. This invitation expires in :days days.`
  - CTA button: **Accept Invitation** → `{frontend_main_url}/accept-invitation?token={unhashed_token}`
  - Footer line: `If you did not expect this invitation, you can safely ignore this email.`
  - (`:role` renders as one of: **Admin**, **Manager**, **Staff**.)
- **Status:** live-and-sending.

---

## Part 4 — Account / system emails (outside the notification system)

These are transactional identity emails, not routed through `NotificationType`/`AuditAction`
notification machinery at all (beyond an auth audit row for the triggering action itself). No MFA
enrollment/backup-code email exists — the platform's 2FA is entirely app-based (TOTP + recovery
codes), never emailed.

### A. `VerifyEmailMail`

- **Class / module:** `App\Modules\Identity\Mail\VerifyEmailMail` (Identity module).
- **Rides:** `auth.email.verification_sent` (audit row for the send; `auth.email.verified` for the
  completion).
- **Trigger:** A user signs up (`SignUpService::sendVerificationMail()`), or requests the verification
  email be resent.
- **Recipient:** Any newly-registered user (creator or agency).
- **Conditions:** No feature flag. Signed, time-limited token (`:hours` hours). No preference read.
  Localized to `preferred_language` at queue time.
- **Content (EN):**
  - Subject: `Verify your :app email address`
  - Greeting: `Welcome to :app, :name!`
  - Body: `Please confirm your email address to finish setting up your :app account. The link below is valid for :hours hours.`
  - CTA button: **Verify email address** → the SPA's verify-email page (signed token in the query string; POSTs to `/api/v1/auth/verify-email`)
  - Footer line: `If you did not create a :app account, you can safely ignore this email.`
- **Status:** live-and-sending.

### B. `ResetPasswordMail`

- **Class / module:** `App\Modules\Identity\Mail\ResetPasswordMail` (Identity module).
- **Rides:** `auth.password.reset_requested` (audit row for the request; `auth.password.reset_completed`
  for the completion).
- **Trigger:** A user requests a password reset (`PasswordResetService`).
- **Recipient:** Any user (creator or agency) with a matching account.
- **Conditions:** No feature flag. Time-limited token (`:minutes` minutes). No preference read.
  Localized to `preferred_language` at queue time. Completing a reset signs out every other active
  session.
- **Content (EN):**
  - Subject: `Reset your :app password`
  - Greeting: `Hello :name,`
  - Body: `We received a request to reset the password on your :app account. The link below is valid for :minutes minutes.`
  - CTA button: **Reset password** → the SPA's reset page (token + email in the query string; POSTs to `/api/v1/auth/reset-password`)
  - Footer line: `If you did not request this, you can safely ignore this email — your password will not change.`
- **Status:** live-and-sending.

---

## Summary table

| #   | Email                               | Trigger                                                  | Flag / condition                                                   | Sends today?                         |
| --- | ----------------------------------- | -------------------------------------------------------- | ------------------------------------------------------------------ | ------------------------------------ |
| 1   | `ProspectCreatorInviteMail`         | Agency bulk-invites a prospective creator                | none                                                               | **Y**                                |
| 2   | `CreatorApprovedMail`               | Admin approves a creator's application                   | none                                                               | **Y**                                |
| 3   | `CreatorRejectedMail`               | Admin rejects a creator's application                    | none                                                               | **Y**                                |
| 4   | `ConnectionRequestMail`             | Agency (or admin Door 1) sends a connection request      | none                                                               | **Y**                                |
| 5   | `AdminConnectedMail`                | Admin Door 2 direct-connects an agency + creator         | none                                                               | **Y**                                |
| 6   | `CreatorBlacklistedMail`            | Agency blacklists a creator                              | per-agency `blacklist_notification_policy`, default OFF            | **N** (per agency, until it opts in) |
| 7   | `RelationDisconnectedMail`          | Admin severs an agency↔creator relationship              | none                                                               | **Y** (both parties)                 |
| 8   | `IncompleteCreatorNudgeMail`        | Daily nudge for stuck self-serve creators                | flag `incomplete_creator_nudge_enabled` OFF + scheduler unverified | **N**                                |
| 9   | `JobPostedMail`                     | Agency lists a campaign on the jobs board                | flag `job_posted_notifications_enabled` OFF                        | **N**                                |
| 10  | `ApplicationAcceptedMail`           | Agency accepts a jobs-board application                  | flag `application_notifications_enabled` OFF                       | **N**                                |
| 11  | `ApplicationRejectedMail`           | Agency rejects / campaign auto-rejects an application    | flag `application_notifications_enabled` OFF                       | **N**                                |
| 12  | `InviteReceivedMail` (①)            | Assignment lands on `invited` (fresh or either re-offer) | flag `missing_creator_mails_enabled` OFF                           | **N**                                |
| 13  | `ContractAttachedMail`              | Agency attaches a per-campaign contract                  | `per_campaign_contract_enabled` ON (default)                       | **Y**                                |
| 14  | `DraftReviewedMail`                 | Agency approves / requests changes / rejects a draft     | none (suppressed only on completes-on-approval)                    | **Y**                                |
| 15  | `ResubmitRequestedMail`             | Agency resolves a failed auto-verification               | `social_verification_enabled` ON (driver=mock default)             | **Y**                                |
| 16  | `AssignmentCompletedOnApprovalMail` | Draft approval completes a no-post campaign              | none                                                               | **Y**                                |
| 17  | `PostManuallyVerifiedMail`          | Agency manually overrides a failed verification          | none                                                               | **Y**                                |
| 18  | `NewMessageMail` (⑧)                | Agency→creator message, debounced                        | flag `missing_creator_mails_enabled` OFF                           | **N**                                |
| 19  | `UnreadMessagesDigestMail`          | Daily unread-message aggregate                           | opt-in `digest` pref, default OFF + scheduler unverified           | **N**                                |
| 20  | `ApplicationSubmittedMail`          | Creator applies to a jobs-board campaign                 | flag `application_notifications_enabled` OFF                       | **N**                                |
| 21  | `DraftSubmittedForReviewMail`       | Creator submits a draft for review                       | none                                                               | **Y**                                |
| 22  | `PostVerificationFailedMail`        | Auto-verification fails (not found / mismatch)           | `social_verification_enabled` ON (driver=mock default)             | **Y**                                |
| 23  | `ContractAcceptedMail`              | Creator accepts a per-campaign contract                  | `per_campaign_contract_enabled` ON (default)                       | **Y**                                |
| 24  | `InviteAgencyUserMail`              | Agency member invites a new agency member                | none                                                               | **Y**                                |
| 25  | `VerifyEmailMail` (account)         | Sign-up / resend verification                            | none                                                               | **Y**                                |
| 26  | `ResetPasswordMail` (account)       | Forgot-password request                                  | none                                                               | **Y**                                |

**Sending today: 17 of 26. Silent today: 9 of 26** — broken down as:

- **7 silenced by the three OFF Pennant flags:** `job_posted_notifications_enabled` silences 1
  (`JobPostedMail`); `application_notifications_enabled` silences 3 (`ApplicationSubmittedMail`,
  `ApplicationAcceptedMail`, `ApplicationRejectedMail`); `missing_creator_mails_enabled` silences 2
  (`InviteReceivedMail`, `NewMessageMail`); `incomplete_creator_nudge_enabled` silences 1
  (`IncompleteCreatorNudgeMail`, doubly silenced — the scheduler that would run it is also unverified).
- **2 silenced by a non-flag condition:** `CreatorBlacklistedMail` (per-agency setting, default OFF
  for every agency today) and `UnreadMessagesDigestMail` (opt-in user preference, default OFF, and the
  daily command that would send it is assumed not running — the unverified scheduler).
