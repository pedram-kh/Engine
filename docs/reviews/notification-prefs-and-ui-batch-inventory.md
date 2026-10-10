# AH-101 — notification preference matrix + completion notice + vocabulary catch-up + third eyes-on UI batch — change inventory

Read-only inventory. No code edits, no code. The kickoff and the plan-pause follow this document.

> **This is a close-time snapshot of the tree as it stood at `06a8d95`, not a live claim** — the same
> convention `ui-batch-post-arc-inventory.md` and the AH log's `Status:` line follow. Line numbers are
> real at that SHA and will drift. Where something could not be measured, the section says so under
> **Not verified** rather than asserting it.

**Base SHA:** `06a8d959a057da7b3d0468fcb12694753853d7af` — `HEAD` and `origin/main` are the same commit
(`git rev-parse HEAD origin/main` returns one SHA twice), and it is the AH-100d close-out commit:
`docs(reviews): AH-100d closed — pushed tip, CI run, flake disposition`.
**Working tree:** clean at the start of the inventory (`git status --short` returned nothing).
**Orientation through AH-100d:** `fbf84e33` (AH-100d approved by the third re-walk, conditional per AH-090) →
`06a8d959` (pushed tip, CI run, flake disposition). The native shell this batch touches is the AH-100 →
AH-100d work: `useMobileShell` (`apps/main/src/composables/useMobileShell.ts:18-25`), the `.catalyst-native`
class and `--native-*` tokens (`packages/design-tokens/native.css:17-82`), and the creator shell
(`apps/main/src/modules/creators/layouts/CreatorDashboardLayout.vue`).

**Paths:** backend module paths are relative to `apps/api/app/Modules/`; `app/Console/…`, `bootstrap/`, `config/`, `lang/`,
`database/`, `resources/` and `tests/` are relative to `apps/api/`; frontend paths are relative to `apps/main/src/modules/`
(or `apps/main/src/` where they start with `core/` or `composables/`); anything starting with `apps/`, `packages/` or `docs/` is
repo-root-relative.

---

## 0. The answers to the three shaping questions

1. **N1 — the no-guard send-site list (§N1.3).** Every in-app write funnels through one method that _does_ read
   preferences. **The unguarded surface is the mail legs: 29 mailables, 1 reads a preference** (the daily digest, on
   the `digest` channel). Of the 29: **14 pair with a `NotificationType` and go to a user who can be offered a choice**
   (candidates; #3, the digest, is already wired, and #13–#14, the creator approve/reject mails, are better treated as
   always-on — so **11 new guard sites plus 2 judgement calls**); **2 are always-on by an existing FE ruling**
   (relation connect/disconnect); **6 are structurally exempt** (no `NotificationType` exists for them, so there is no
   preference key to read); **7 are security/transactional or go to a recipient who is not yet a user**. The 11 live in
   **5 files** (`SendAssignmentNotifications`, `DebouncedMessageMailer`, `CampaignApplicationNotifier`,
   `JobPostedFanOutService`, `AdminCreatorController`), and **3 existing flag checkpoints** already sit in the right place.
2. **N3 — the inertness mechanism (§N3).** Sparse storage plus a default-ON `email` channel: _a missing row means
   ON_, so a guard that reads `email` returns `true` for every user who has no row — which is every user, because
   today's UI cannot write one. No migration, no backfill. The matrix reaches nobody's behavior until a user
   _themselves_ flips an email toggle. **Three honest caveats** (a crafted dormant `email` row, a per-send query cost,
   and an unverifiable production row count) are in §N3.4, with the one read-only check Pedram can run.
3. **U3 — the composer diagnosis (§U3).** **Not missing and not native-CSS-hidden. It is below the viewport,
   underneath the fixed bottom tab bar.** `.rel-thread` is sized `calc(100dvh - 140px)` — a _web-mobile_ chrome
   figure — but native chrome is ≈ 220 pt, so the thread overshoots by ≈ 90 pt and its last row, the composer, sits
   under the tab bar. Geometry-derived from a simulator screenshot, **not** an interactive scroll test (§U3.4).

---

# HALF A — the preference matrix (behavioral, LOW-MEDIUM)

## N1 — Storage and read path today

### N1.1 Storage shape

| Thing            | Fact                                                                                                                                                                                                                                                               | Cite                                                                                                                      |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------- |
| Table            | `user_notification_preferences`: `id`, `user_id` (FK → `users`, **cascade on delete**), `type` string(64), `channel` string(16), `is_enabled` boolean **default `true`**, timestamps. **Unique `(user_id, type, channel)`** named `unique_user_notification_pref`. | `apps/api/database/migrations/2026_06_06_100001_create_user_notification_preferences_table.php:41-61`                     |
| Tenancy          | User-global, like `users` — no `agency_id`, not `BelongsToAgency`; own-record-only.                                                                                                                                                                                | same file `:29-30`                                                                                                        |
| Channel enum     | `in_app`, `email`, `digest`.                                                                                                                                                                                                                                       | `Notifications/Enums/NotificationChannel.php:21-23`                                                                       |
| Channel defaults | `in_app` ON, `email` ON, `digest` OFF.                                                                                                                                                                                                                             | `NotificationChannel.php:28-34`                                                                                           |
| Type enum        | **26 cases.** 20 are live in the FE registry; 6 are never emitted (`assignment.declined`, `.countered`, `.accepted`, `.cancelled`, `.payment_funded`, `.payment_released`).                                                                                        | `Notifications/Enums/NotificationType.php:44-157`; emit scan in §N5.2                                                     |
| Sparse rule      | A preference equal to its default is **deleted**, not stored; only divergences hold a row.                                                                                                                                                                         | `Notifications/Services/NotificationService.php:79-100`                                                                   |
| Read             | `isChannelEnabled()` — missing row → `channel->defaultEnabled()`; else the row's `is_enabled`. One `SELECT … first()` per call.                                                                                                                                    | `NotificationService.php:108-121`                                                                                         |
| Read API         | `GET /me/notification-preferences` returns the user's sparse rows **and** a server-authoritative `defaults` block.                                                                                                                                                 | `Notifications/Http/Controllers/NotificationPreferenceController.php:37-40, 65-80, 88-97`                                 |
| Write API        | `PATCH` validates `notification_type` against the **full** `NotificationType` enum and `channel` against the **full** `NotificationChannel` enum — so `email` rows are _already writable_ by a crafted call.                                                       | `Notifications/Http/Requests/UpdateNotificationPreferencesRequest.php:39-45`; routes `Notifications/Routes/api.php:45-51` |
| Wire contract    | The api-client types already carry all three channels and the per-channel `defaults` map. The prior comment says the UI surfaces `in_app` only.                                                                                                                    | `packages/api-client/src/types/notification.ts:166-198`                                                                   |

### N1.2 Every call site of the preference read

There are **two** `isChannelEnabled()` consumers in the whole backend:

| Consumer                                                       | Channel read | Cite                                                                        |
| -------------------------------------------------------------- | ------------ | --------------------------------------------------------------------------- |
| `NotificationService::notify()` — the single in-app write path | `in_app`     | `NotificationService.php:48`                                                |
| `MessageDigestService` — the daily digest recipient filter     | `digest`     | `Messaging/Services/MessageDigestService.php:178` (class docblock `:34-40`) |

`Notification::query()->create` appears **once** in `app/` (`NotificationService.php:52`). The in-app surface
therefore has **no unguarded write**; the 17 `notify()` call sites all inherit the `in_app` guard:

`Campaigns/Listeners/SendAssignmentNotifications.php:158, 234, 297, 427, 527` · `Campaigns/Services/CampaignApplicationNotifier.php:110, 168, 227` ·
`Campaigns/Services/JobPostedFanOutService.php:251` · `Messaging/Services/SendMessageNotifications.php:80, 108` ·
`Messaging/Services/RelationshipMessageNotifications.php:71, 88` · `Creators/Http/Controllers/Admin/AdminCreatorController.php:510` ·
`Creators/Http/Controllers/Admin/AdminCreatorConnectionController.php:272, 304` · `Creators/Services/CreatorAgencyDirectConnectService.php:211`.

(`JobPostedFanOutService.php:282` writes `CampaignJobNotification`, a de-dupe ledger — not a `Notification`.)

### N1.3 The complete table — send-sites that do NOT consult preferences (the chunk's real surface)

All 29 mailables under `*/Mail/`, one row each. **Reads prefs?** is "no" for every row except #3.
**Gate** is the only thing standing between the send site and a recipient today.

Legend for **Class**: **C** = real candidate for an `email` toggle · **X** = structurally exempt (no `NotificationType`, so no
preference key exists to read) · **T** = security/transactional or non-user recipient — never preference-gated.

| #   | Mailable (`*/Mail/`)                          | Pairs with `NotificationType`                                                                                                                                            | Sends via (`path:line`)                                                                                                                   | Gate today                                                                                | Reads prefs?           | Class                             |
| --- | --------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- | ---------------------- | --------------------------------- |
| 1   | `Campaigns/InviteReceivedMail`                | `assignment.invited`                                                                                                                                                     | `Campaigns/Listeners/SendAssignmentNotifications.php:286` → `queueInviteMail` `:316-325`                                                  | Pennant `missing_creator_mails_enabled` `:318`                                            | no                     | C                                 |
| 2   | `Messaging/NewMessageMail`                    | `message.received_by_creator`, `message.relationship_received_by_creator` (mail goes to the **creator only**; the two agency types are in-app only)                      | `Messaging/Services/DebouncedMessageMailer.php:83` (callers `SendMessageNotifications.php:88`, `RelationshipMessageNotifications.php:96`) | Pennant `missing_creator_mails_enabled` `:65` + 30-min debounce per `(thread, recipient)` | no                     | C                                 |
| 3   | `Messaging/UnreadMessagesDigestMail`          | `message.received_by_*` (the `digest` channel)                                                                                                                           | `app/Console/Commands/SendMessageDigests.php:33`, scheduled `bootstrap/app.php:37`                                                        | scheduler; **recipient filter reads `digest`** `MessageDigestService.php:178`             | **YES — the only one** | C (already done)                  |
| 4   | `Campaigns/AssignmentCompletedOnApprovalMail` | `assignment.completed_on_approval`                                                                                                                                       | `SendAssignmentNotifications.php:150`                                                                                                     | none                                                                                      | no                     | C                                 |
| 5   | `Campaigns/PostManuallyVerifiedMail`          | `assignment.manually_verified`                                                                                                                                           | `SendAssignmentNotifications.php:224`                                                                                                     | none                                                                                      | no                     | C                                 |
| 6   | `Campaigns/DraftReviewedMail`                 | `assignment.draft_approved` / `.revision_requested` / `.draft_rejected` (3 types, one mailable)                                                                          | `SendAssignmentNotifications.php:407`; approval mail suppressed when it completes (`:111`)                                                | none                                                                                      | no                     | C                                 |
| 7   | `Campaigns/DraftSubmittedForReviewMail`       | `assignment.draft_submitted` — **mail goes to the inviter only; in-app fans out to all admins+managers**                                                                 | `SendAssignmentNotifications.php:340`                                                                                                     | none                                                                                      | no                     | C (see §N2.4)                     |
| 8   | `Campaigns/ContractAcceptedMail`              | `assignment.contracted` — **inviter only**, same asymmetry; contract-less advance sends nothing (`:465`)                                                                 | `SendAssignmentNotifications.php:480`                                                                                                     | none                                                                                      | no                     | C (see §N2.4)                     |
| 9   | `Campaigns/ApplicationSubmittedMail`          | `campaign_application.submitted` (to each agency admin/manager)                                                                                                          | `Campaigns/Services/CampaignApplicationNotifier.php:126` → `queue()` `:273-286`                                                           | Pennant `application_notifications_enabled` `:275`                                        | no                     | C                                 |
| 10  | `Campaigns/ApplicationAcceptedMail`           | `campaign_application.accepted`                                                                                                                                          | `CampaignApplicationNotifier.php:188`                                                                                                     | same flag                                                                                 | no                     | C                                 |
| 11  | `Campaigns/ApplicationRejectedMail`           | `campaign_application.rejected` (two causes, one type)                                                                                                                   | `CampaignApplicationNotifier.php:243`                                                                                                     | same flag                                                                                 | no                     | C                                 |
| 12  | `Campaigns/JobPostedMail`                     | `campaign.job_posted`                                                                                                                                                    | `Campaigns/Services/JobPostedFanOutService.php:267` (flag `:119`)                                                                         | Pennant `job_posted_notifications_enabled` + 50/run cap                                   | no                     | C                                 |
| 13  | `Creators/CreatorApprovedMail`                | `creator.approved`                                                                                                                                                       | `Creators/Http/Controllers/Admin/AdminCreatorController.php:290` → `dispatchCreatorMail` `:482-492`                                       | none                                                                                      | no                     | C (but see §N3.5 — likely exempt) |
| 14  | `Creators/CreatorRejectedMail`                | `creator.rejected`                                                                                                                                                       | `AdminCreatorController.php:361`                                                                                                          | none                                                                                      | no                     | C (but see §N3.5)                 |
| 15  | `Agencies/AdminConnectedMail`                 | `agency_creator_relation.admin_connected` — FE marks it **always-on** (`preference: null`)                                                                               | `Creators/Services/CreatorAgencyDirectConnectService.php:224`                                                                             | none                                                                                      | no                     | T (always-on)                     |
| 16  | `Agencies/RelationDisconnectedMail`           | `agency_creator_relation.disconnected` — FE **always-on**, both parties                                                                                                  | `Creators/Http/Controllers/Admin/AdminCreatorConnectionController.php:284, 316`                                                           | none                                                                                      | no                     | T (always-on)                     |
| 17  | `Campaigns/ResubmitRequestedMail`             | — (`assignment.resubmit_requested[_in_place]` exists in `AuditAction` `:306-307` but is **deliberately excluded** from `NotificationType`, `NotificationType.php:24-27`) | `Campaigns/Http/Controllers/CampaignAssignmentResolutionController.php:200`                                                               | none                                                                                      | no                     | X                                 |
| 18  | `Campaigns/ContractAttachedMail`              | — (no contract verb in `AuditAction`)                                                                                                                                    | `Campaigns/Http/Controllers/CampaignAssignmentContractController.php:279`                                                                 | none                                                                                      | no                     | X                                 |
| 19  | `Campaigns/PostVerificationFailedMail`        | — (no verb; goes to the **agency inviter**)                                                                                                                              | `Campaigns/Jobs/VerifyPostedContentJob.php:140`                                                                                           | Pennant `social_verification_enabled` `:63`                                               | no                     | X                                 |
| 20  | `Creators/IncompleteCreatorNudgeMail`         | — (system nudge, no verb)                                                                                                                                                | `Creators/Services/IncompleteCreatorNudgeService.php:123, 135`                                                                            | Pennant `incomplete_creator_nudge_enabled` `:94`                                          | no                     | X                                 |
| 21  | `Agencies/ConnectionRequestMail`              | — (`agency_creator_relation.admin_requested` exists `AuditAction.php:204`, **deliberately** typeless: it is an inbox request, `NotificationType.php:97-99`)              | `Agencies/Http/Controllers/AgencyConnectionRequestController.php:171`; `AdminCreatorConnectionController.php:397`                         | none                                                                                      | no                     | X                                 |
| 22  | `Agencies/CreatorBlacklistedMail`             | — (`creator.blacklisted` exists `AuditAction.php:217`, **deliberately email-only**, `NotificationType.php:33-35`)                                                        | `Agencies/Http/Controllers/CreatorBlacklistController.php:347`                                                                            | none                                                                                      | no                     | X                                 |
| 23  | `Creators/ProspectCreatorInviteMail`          | — (`creator.invited` `AuditAction.php:132`; recipient is **not yet a user**)                                                                                             | `Creators/Services/BulkInviteService.php:97`                                                                                              | none                                                                                      | n/a                    | T (non-user)                      |
| 24  | `Agencies/InviteAgencyUserMail`               | — (recipient not yet a user)                                                                                                                                             | `Agencies/Services/AgencyInvitationService.php:95`                                                                                        | none                                                                                      | n/a                    | T (non-user)                      |
| 25  | `Identity/VerifyEmailMail`                    | —                                                                                                                                                                        | `Identity/Services/SignUpService.php:279`                                                                                                 | none                                                                                      | no                     | T (security)                      |
| 26  | `Identity/ResetPasswordMail`                  | —                                                                                                                                                                        | `Identity/Services/PasswordResetService.php:78`                                                                                           | none                                                                                      | no                     | T (security)                      |
| 27  | `Identity/MfaResetByAdminMail`                | —                                                                                                                                                                        | `Admin/Http/Controllers/AdminAgencyMemberController.php:191` (`mailTo` `:358-368`)                                                        | none                                                                                      | no                     | T (security)                      |
| 28  | `Identity/MfaExemptionGrantedMail`            | —                                                                                                                                                                        | `AdminAgencyMemberController.php:248`                                                                                                     | none                                                                                      | no                     | T (security)                      |
| 29  | `Identity/MfaExemptionRemovedMail`            | —                                                                                                                                                                        | `AdminAgencyMemberController.php:295`                                                                                                     | none                                                                                      | no                     | T (security)                      |

**Reading the table.**

- **Tally (sums to 29):** **C** = rows 1–14 (14; #3 is already done and #13/#14 are probably better always-on, §N3.5 → **11 new guard
  sites + 2 judgement calls**); **always-on T** = 15–16 (2); **X** = 17–22 (6); **non-user / security T** = 23–29 (7).
  The per-row **Class** column is the authority.
- **The flag checkpoints that already sit in the right place** are the natural guard seams — and they are each commented as
  "the one place": `CampaignApplicationNotifier.php:273-286` (a second `Feature::active` "would quietly halve" the mail),
  `SendAssignmentNotifications.php:313-325`, `DebouncedMessageMailer.php:63-67`. A preference read belongs _beside_ each flag, not
  instead of it.
- **The unflagged sites** (rows 4–8, 13–16 and the controllers/jobs in 17–19, 21–22) have **no checkpoint at all** — a shared seam
  is the cheaper design there (§N6.1).
- **The tech-debt entry that already names this chunk:** `docs/tech-debt.md:2412-2452` ("The email channel is not wired through
  notification preferences") — its _Resolution_ (`:2430-2434`) is "read `notification_preferences` for the `email` channel inside the
  emission path (one shared guard…), expose the email toggles on the preferences page, and backfill defaults … most of it deciding
  the default and the transactional-mail exemption list", and its AH-083 widening (`:2444-2452`) names **AH-084 — this chunk — as
  the one that wires the channel**.

## N2 — The page, web and native today, and what a matrix needs

### N2.1 Today

`notifications/pages/NotificationPreferencesPage.vue` is **already a per-type × per-channel list**, not a flat one:

- It renders `preferenceGroups` (from `preferenceGroupsForRole`, `notifications/templates.ts:311-336`) → one `<h2>` per group → one
  block per type → **one `v-switch` per entry in that type's `channels` array** (`NotificationPreferencesPage.vue:179-207`).
- The per-type channel list is data: `preference: { group, channels }` on each `LIVE_TYPES` entry (`templates.ts:66-69, 88`).
  `IN_APP_ONLY = ['in_app']` is the common case (`:92`); the two messaging types carry `['in_app', 'digest']`
  (`templates.ts:168-176`). The file's own rule: _"A channel appears here ONLY when a consumer actually delivers it — never ship a
  toggle that gates nothing (dead control)"_ (`:59-65`).
- State is `enabled[type|channel]`, composed as `override ?? defaults[channel]` (`NotificationPreferencesPage.vue:96-108`); **Save sends the
  full visible set** and the server reconciles to sparse (`:122-145`, comment `:127`).
- Always-on types: `preference: null` is a _positive assertion_ (`templates.ts:76-88`) — used for the two relation types
  (`:200-209`) so they never get a toggle.
- Strings: `notifications.json:42-81` — `preferences.channels` has **only `in_app` and `digest`** labels (`:55-58`); the page description
  says "in-app notifications" (`:44`). 24 locales.

### N2.2 Web vs native today — the same page

The creator route `creator.notifications.preferences` (`creators/routes.ts:122-123`) renders the **same** page the agency reaches at
`notifications.preferences` (`auth/routes.ts:443-444`). The page has **no native-specific markup or CSS**: it is a `v-card max-width=640
pa-6` (`:151`). On native, `native.css` restyles it generically — flat bordered cards (`native.css:102-107`) and selection controls
(`:185-190`). The native "Settings" tab entry (AH-100 Q7) is a `v-list-item` in the More menu pointing straight at it
(`creators/layouts/CreatorDashboardLayout.vue:370-380`), with the subtitle "Notification preferences" (`core/i18n/locales/en/availability.json:23`; the row title is "Settings", `:19`).
The web reaches it from the avatar menu (`CreatorDashboardLayout.vue:280`, `AgencyLayout.vue:201`).

### N2.3 What a matrix needs — designed so AH-102's push column is a row-value addition

The registry is already the matrix's data model; the page is the only thing that has to change shape.

1. **Columns are derived, not authored.** Take the union of `channels` over the visible types for the role; header order is a single
   constant (`in_app`, `email`, `digest`, then `push` when AH-102 lands). A type that does not support a channel renders an **empty
   cell**, not a disabled switch (the dead-control rule).
2. **The email column is a row-value addition**: add `'email'` to a type's `channels` array in `LIVE_TYPES`. **Push is the identical
   edit** (`'push'`), plus one backend enum case (`NotificationChannel::Push`) and its `defaultEnabled()`. Nothing else is structural:
   `Rule::enum(NotificationChannel::class)` already picks the new case up (`UpdateNotificationPreferencesRequest.php:42`), the table
   column is `string(16)` (fits `push`), and the api-client type is a string union (`notification.ts:166`). **That is the AH-102
   contract this chunk must not break:** no per-channel branching in the page template — only a column-header constant and one i18n
   label.
3. **The honesty rule must carry over.** An `email` entry may land in a type's `channels` **only in the same change that makes that
   type's mail path read the guard** — otherwise it is exactly the dead control `templates.ts:225-230` already warns about. This
   couples the FE matrix to the BE guard per type, which is why §N3's deploy order matters.
4. **New strings:** `preferences.channels.email` (and later `push`) + the page description (today it promises "in-app notifications"
   only, `notifications.json:44`) — ×24 locales; `i18n-notifications-parity.spec.ts` pins the key sets.
5. **Native layout is the real design question.** A 390 pt screen cannot carry label + 3–4 switch columns inline at body size. The
   cheapest compatible shape is the _same_ data in a stacked card per type: label on top, a row of channel toggles beneath, each with an
   icon + short label (the current page is already a vertical stack of switches). The web gets the grid. One component, two
   arrangements, selected by the same `isMobile` shell flag — the plan-pause should pick this.
6. **Save model:** today one explicit Save for the whole form. A matrix of 18+ rows × up to 4 cells makes "forgot to save" likelier;
   autosave-per-cell is a plan-pause alternative (it would also make the page's `saveSuccess` banner `:166-176` obsolete).

### N2.4 Two honesty traps the matrix would walk into

- **Per-user toggle vs inviter-only mail.** `assignment.draft_submitted` and `assignment.contracted` fan out **in-app to every agency
  admin/manager** (`SendAssignmentNotifications.php:355, 493`) but send the **email to the single inviter**
  (`:339-349, 478-488`). An email toggle on those rows would be a **dead control for every non-inviter member**. Options for the plan:
  omit the email cell for those two; show it with an "only if you sent the invite" caption; or change who gets the mail (a behavior
  change — out of scope for a schema-only chunk).
- **The `email` channel vs the `digest` channel for messaging.** A creator can have `digest` ON and `email` OFF for
  `message.received_by_creator` — the digest mail (row 3) and the debounced `NewMessageMail` (row 2) are different mails on different
  channels. The page needs a one-line explanation, and the debounce stamp must not be written when the preference suppresses the send
  (otherwise a later re-enable is silently debounced out).

## N3 — Defaults and inertness

### N3.1 What rows exist in production (and what the UI can have written)

**Could not be measured here** — there is no production data access from this session. What the code _proves_ is the set of rows the
shipped UI is able to create:

- the page offers `in_app` for every non-always-on type and `digest` for two messaging types (`templates.ts:108-256`);
- a row is stored only when it diverges from the default (`NotificationService.php:86-99`) — so the only reachable rows are
  `(type, in_app, false)` and `(message.received_by_*, digest, true)`;
- **no `email` row can come from the UI**, because `email` is in no type's `channels`.

So the _expected_ production table holds `in_app = false` and `digest = true` divergences only. The one thing that breaks that
expectation is a hand-crafted `PATCH` (the request accepts any enum channel, `UpdateNotificationPreferencesRequest.php:42`) or a seeder.
`NotificationPreferenceFactory.php` is test-only.

### N3.2 What absence means, per channel

| Channel  | Missing row means                                        | Today's consumer                        | After this chunk                                            |
| -------- | -------------------------------------------------------- | --------------------------------------- | ----------------------------------------------------------- |
| `in_app` | **ON**                                                   | `notify()` `NotificationService.php:48` | unchanged                                                   |
| `email`  | **ON** (`defaultEnabled()` `NotificationChannel.php:31`) | **none**                                | the new guard — returns `true` for every user without a row |
| `digest` | **OFF** (`:32`)                                          | `MessageDigestService.php:178`          | unchanged                                                   |

### N3.3 The mechanism by which the matrix deploys with nobody's behavior changed

The schema-only rule stands: **no migration, no backfill, no seeded rows.** The mechanism is the sparse-storage contract that already
exists and was designed for exactly this (`NotificationChannel.php:11-14`: _"No per-user row is seeded, so the Ch2 retrofit can never
silently disable an existing email"_):

1. The new guard resolves `email` through `isChannelEnabled()`. A user with **no `email` row** gets `defaultEnabled()` = `true`.
   Every user today has no `email` row (§N3.1). Therefore **the guard returns `true` for everyone → every mail that sends today
   still sends.**
2. The only writer of an `email=false` row is the new matrix, and only when a user _chooses_ to flip it. Behavior changes for
   exactly the people who asked for it — which is the feature.
3. The existing outer layers are untouched: **Pennant flag OFF → no mail regardless of preference; flag ON → preference consulted.**
   The guard is _inside_ the flag, so a kill switch still kills.
4. **Deploy order:** API guard first (inert by absence), SPA matrix second (the first writer). If the SPA shipped first, a user
   could save an `email=false` row that nothing reads — a dead control, the exact failure mode the registry comments forbid. If the
   two ship in one release, order within it does not matter; the rollback story is "revert the SPA, the guard is still inert".
5. **Proof shape for the review** (to design, not write): a test that, with no `email` rows, every guarded site still queues
   exactly the mail it queued before; and one that, with an `email=false` row for a _different_ type, the site is unaffected.

### N3.4 Honest caveats to the "inert" claim

| Risk                                       | Why it is real                                                                                                                                                                                     | What closes it                                                                                                                                                                                                                                                                                                                             |
| ------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **A dormant crafted `email=false` row**    | The `PATCH` already accepts it today. Today it gates nothing; the day the guard ships it _would_ silence that user's mail — a behavior change nobody asked the plan about.                         | Pedram runs one read-only count of `user_notification_preferences` grouped by `channel` and `is_enabled` before deploy. **Expected: no `email` rows at all.** If any exist they are deliberate user intent (nobody else wrote them), so the plan should decide _honour_ vs _clear_.                                                        |
| **Per-send query cost**                    | `isChannelEnabled()` is one `SELECT` per call (`NotificationService.php:110-114`). The fan-outs are capped (job-posted is 50/run, flag `:119`) but the message and assignment sites are per-event. | A bulk "load all email rows for these recipients" variant at the fan-out sites (job-posted, application-submitted loops) — a plan decision, not a necessity at current volumes.                                                                                                                                                            |
| **Exemption-list drift**                   | If the guard keys on a type, a future mail added without a type silently bypasses it; if it keys on the mailable, a new mailable silently bypasses it.                                             | Make the **exemption structural**: the guard takes a `NotificationType`; mails with no type (rows 17–29) _cannot_ call it. Pin it with an architecture test that every `Mail::…->queue` site is either routed through the seam or on an explicit allowlist (the repo already uses this tripwire pattern, e.g. `NotificationTypeEnumTest`). |
| **Transaction-ordering hazard (existing)** | Mail is queued _inside_ the state machine's transaction today (`docs/tech-debt.md:2454-2470` entry). A preference read there is one more query inside that transaction.                            | Not new; note it so the review does not mistake it for a regression.                                                                                                                                                                                                                                                                       |

### N3.5 Default and exemption decisions the plan has to make

- **Default for `email` stays ON** (the only value compatible with "nobody's behavior changed"). Settled by the mechanism; listed so
  the plan does not reopen it.
- **`creator.approved` / `creator.rejected` mail** (rows 13–14): a creator who switches off the approval email can then miss the
  one email that tells them they may sign in to the full app. Recommendation: treat as **always-on** (`preference` has no `email`
  channel for these two), mirroring how the relation types are always-on. Pedram's call.
- **`agency_creator_relation.*` mail** (rows 15–16): already declared always-on in the FE registry for `in_app` (`templates.ts:196-209`);
  the mail follows the same ruling for consistency.
- **Messaging `email` vs `digest`:** keep both (they are different products) — see §N2.4.

## N4 — ⑦ the silent `live_verified` completion

### N4.1 What the transition emits today

`posted → live_verified` is `CampaignAssignmentStateMachine::verifyLive()` (`Campaigns/Services/CampaignAssignmentStateMachine.php:484-502`),
reached only from `VerifyPostedContentJob` on a `Verified` outcome (`Campaigns/Jobs/VerifyPostedContentJob.php:99-107`) — with a **null actor**
(`:102`). It commits through the single `commit()` (event dispatched at `:668`). The four `AssignmentTransitioned` listeners
(`Campaigns/CampaignsServiceProvider.php:52-75`; the relevant ones are listed — `CreateMessageThread` and `CreateBoardCard` are not) do
the following for `assignment.live_verified`:

| Listener                            | Behavior                                                                                        | Cite                                                                                                  |
| ----------------------------------- | ----------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| `DispatchPostedContentVerification` | n/a (acts on `posted`)                                                                          | `CampaignsServiceProvider.php:52`                                                                     |
| **`SendAssignmentNotifications`**   | **nothing** — the `match` has no `AssignmentLiveVerified` arm, so it falls to `default => null` | `SendAssignmentNotifications.php:105-120` (default `:119`)                                            |
| `WriteSystemMessage`                | writes the thread line "The live post was verified."                                            | `Campaigns/Listeners/WriteSystemMessage.php:36-50` (`:48`); string `apps/api/lang/en/messages.php:19` |
| `BoardAutomationListener`           | moves the board card to `Posted`                                                                | `Boards/Support/BoardDefaults.php:58, 87`                                                             |

**Net:** no mail, no in-app row. The sibling human override **does** notify: `assignment.manually_verified` has a type
(`NotificationType.php:60`), a mailable (`PostManuallyVerifiedMail`, row 5) and an arm (`SendAssignmentNotifications.php:117`). The
failure path notifies the agency by mail (`VerifyPostedContentJob.php:111, 125-149`). **The only silent branch of the
verification outcome is the success.** (Note `live_verified` is _not_ terminal — it is followed by `payment_held → payment_released`;
the "completion" is the creator's posted content passing verification.)

### N4.2 What the completion notice needs, on the AH-083 conventions

| Piece                   | Convention                                                                                                                                                                                    | Precedent / cite                                                                                                                                                                                  |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `NotificationType` case | add `assignment.live_verified`; **remove it from the "deliberately EXCLUDED" docblock**                                                                                                       | `NotificationType.php:24-27`; `AuditAction::AssignmentLiveVerified` already exists `AuditAction.php:286`, so the one-vocabulary rule is met with **no new verb**                                  |
| Tripwires               | `NotificationTypeEnumTest` (adding a case is a deliberate edit)                                                                                                                               | `apps/api/tests/Feature/Modules/Notifications/NotificationTypeEnumTest.php`                                                                                                                       |
| Listener arm            | add a `match` arm beside `ManuallyVerified`                                                                                                                                                   | `SendAssignmentNotifications.php:117`; sibling fn is `notifyCreatorOfManualVerification`                                                                                                          |
| Mailable                | queued `ShouldQueue`; **queue-time** `->locale($recipient->preferred_language ?: 'en')`; markdown view                                                                                        | model on `Campaigns/Mail/AssignmentCompletedOnApprovalMail.php` + `resources/views/mail/campaigns/assignment-completed-on-approval.blade.php`; locale idiom `SendAssignmentNotifications.php:151` |
| Strings                 | `lang/*/campaigns.php`, **24 locales**, en at the `completed_on_approval` block `apps/api/lang/en/campaigns.php:46-103` region; `LangParityTest` enforces file, keyset and placeholder parity | `apps/api/tests/Unit/Core/LangParityTest.php`                                                                                                                                                     |
| One flag checkpoint     | exactly one `Feature::active` per class — a second "would quietly halve" the mail                                                                                                             | `SendAssignmentNotifications.php:313-318`                                                                                                                                                         |
| `logEmission`           | the debounced mailer logs `flag_suppressed` etc.                                                                                                                                              | `DebouncedMessageMailer.php:65-67`                                                                                                                                                                |
| Dual-emit               | **mail flag-gated, in-app ungated** (the AH-083 ruling) — the in-app row is the always-works half                                                                                             | `docs/feature-flags.md:56`                                                                                                                                                                        |
| FE                      | `LIVE_TYPES` entry (renderer + prefs + role filter all derive from it), `notificationVisual.ts` icon/tone entry, `notifications.json` `types.*` ×24                                           | `notifications/templates.ts:98-257`; `notifications/notificationVisual.ts:26-56`; parity pins `templates.spec.ts`, `tests/unit/architecture/i18n-notifications-parity.spec.ts`                    |
| api-client              | the `NotificationType` union                                                                                                                                                                  | `packages/api-client/src/types/notification.ts`                                                                                                                                                   |

**The one-email rule (AH-069 Q3):** `NotificationType.php:53-58` records that for the completed-on-approval pair "only ONE of the two
sends an email". A `live_verified` notice should follow it: **one email**, an in-app row, no duplicate of the board/system-message line.

### N4.3 Decisions this needs (and a reachability fact that matters)

- **Recipient:** the creator only (mirrors `manually_verified`), or creator + agency fan-out? Agencies already hear about the _failure_; they hear nothing about success.
- **Preference group:** `assignment`, with an `email` channel added on the same rule as everything in §N2.3(3).
- **Reachability:** `verifyLive` throws `AssignmentTransitionGatedException` unless `social_verification_enabled` is ON
  (`CampaignAssignmentStateMachine.php:488-490`). That flag's default is **driver-based: ON iff `integrations.social.driver === 'mock'`**
  (`Creators/Features/SocialVerificationEnabled.php:66-69`), and the driver is `env('SOCIAL_PROVIDER', 'mock')`
  (`apps/api/config/integrations.php:70`). **If production leaves `SOCIAL_PROVIDER` unset, the flag is ON and the mock provider is what
  "verifies" posts** — a creator notice reading "your post was verified" would then be sent on a **mock** verification. **Open question
  for Pedram (§Q4): what is production's `SOCIAL_PROVIDER`, and is the flag overridden in the admin Feature-flags page?** I could not
  determine either from the repo (`SOCIAL_PROVIDER` appears in no `.env*` or deploy-log entry).

## N5 — ⑨ the vocabulary catch-up: the exact `NotificationType`-vs-mail-set drift list

"Drift" = a place where the mail set and the `NotificationType` set disagree. Six buckets, from the §N1.3 and the emit scan:

**5.1 — Mail with a paired type (aligned, no work):** rows 1, 2, 4–14 (13 mailables ↔ 16 type values) plus the digest.

**5.2 — Types that no site emits (the enum is wider than the product):** six —
`assignment.declined`, `assignment.countered`, `assignment.accepted`, `assignment.cancelled`, `assignment.payment_funded`,
`assignment.payment_released`. None is referenced as `NotificationType::X` anywhere outside the enum (scan of `app/`), and none is in the FE
`LIVE_TYPES` (20 live types). `notificationVisual.ts:35-47` carries icons for these six anyway. They are forward-declared by design
(`NotificationType.php:63-65` for the payment pair); **no drift to fix**, but the catch-up should state it so nobody mistakes the
26-vs-20 gap for a bug.

**5.3 — Mail whose `AuditAction` verb exists but whose type is deliberately absent (the ruling is already written down):**
`ResubmitRequestedMail` (`assignment.resubmit_requested[_in_place]`, `AuditAction.php:306-307`; excluded `NotificationType.php:26-27`),
`ConnectionRequestMail` (`admin_requested`, `AuditAction.php:204`; `NotificationType.php:97-99`), `CreatorBlacklistedMail`
(`creator.blacklisted`, `AuditAction.php:217`; email-only on purpose, `NotificationType.php:33-35`), `ProspectCreatorInviteMail`
(`creator.invited`, `AuditAction.php:132`; non-user). **These are the structural exemptions** — the catch-up should _keep_ them typeless.

**5.4 — Mail with no `AuditAction` verb at all (would need NEW vocabulary to ever be toggleable):**
`ContractAttachedMail`, `PostVerificationFailedMail`, `IncompleteCreatorNudgeMail`, `InviteAgencyUserMail`. Adding a type means adding a
verb first (the `NotificationTypeEnumTest` + `auditAction()` tie, `NotificationType.php:163-166`). **Recommendation: do not.** They are all
operational or non-user mails; the preference matrix has no honest row for them.

**5.5 — A live `AuditAction` transition with a creator-relevant outcome and no type: exactly one — `assignment.live_verified` (§N4).**
This is the _only_ drift item that is a missing capability rather than a deliberate omission.

**5.6 — Registry-side drift (FE/BE consistency):** `LIVE_TYPES` (20) = `NotificationType` (26) − the six of §5.2; both are pinned by
`templates.spec.ts` and `i18n-notifications-parity.spec.ts`. `preferences.channels` has no `email` label yet (`notifications.json:55-58`).

> So the "catch-up" is smaller than its name: **one addition (`assignment.live_verified`)**, **zero verbs to invent**, and a
> written-down exemption set (§5.3 + §5.4 + non-user/security) that the guard's structural-exemption design (§N3.4) makes enforceable.

## N6 — Ripple

### N6.1 The AH-083 / AH-090 notifier seams

| Seam                                        | Where                                                                                                                                        | What the matrix does to it                                                                                                                                                                                                                                 |
| ------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CampaignApplicationNotifier::queue()`      | `CampaignApplicationNotifier.php:273-286` (comment `:268-272`: single checkpoint, a second `Feature::active` "would quietly halve the mail") | add the preference read **in the same method**, after the flag. Covers rows 9–11 with one edit.                                                                                                                                                            |
| `queueInviteMail()`                         | `SendAssignmentNotifications.php:313-325` (flag `:318`)                                                                                      | the invite mail's checkpoint; one edit covers row 1.                                                                                                                                                                                                       |
| `DebouncedMessageMailer::maybeSend()`       | `DebouncedMessageMailer.php:63-126`                                                                                                          | same; must not stamp the debounce when the preference suppresses (§N2.4).                                                                                                                                                                                  |
| `JobPostedFanOutService`                    | `:117-151`, `:249-274` (flag `:119`)                                                                                                         | per-recipient; the bulk-read variant matters here (§N3.4).                                                                                                                                                                                                 |
| **Unflagged direct `Mail::to(...)->queue`** | rows 4–8 `SendAssignmentNotifications.php:150, 224, 340, 407, 480`; rows 13–14 `AdminCreatorController.php:482-492`; rows 15–16              | **no checkpoint exists**; a shared "send this mailable for this type to this user" seam is the cheaper design than 8 hand edits (it is what `docs/tech-debt.md:2430` calls "one shared guard").                                                            |
| **AH-090 automation fire path**             | `RunAutomationRuleJob` → `CreatorAgencyDirectConnectService` (`:204-229`, mail `:224`)                                                       | already notifies via the same `AdminConnectedMail` + `RelationAdminConnected`; both are **always-on**, so AH-090 is unaffected by the matrix. The automation flag (`platform_automations_enabled`, `docs/feature-flags.md:58`) is the only gate and stays. |

### N6.2 Mail render + `LangParity`

No mailable's _content_ changes for the guard itself. Render tests are `Mail::fake()`-based (dispatch + content + locale, not a real
inbox — e.g. the headers of `Agencies/Mail/*Mail.php`). The guard adds **zero new mail strings**. The **completion notice (§N4)** adds
one mailable + strings ×24 in `lang/*/campaigns.php`, which `LangParityTest` (`tests/Unit/Core/LangParityTest.php:11-90`) gates on
file / keyset / placeholder parity.

### N6.3 The native Settings page (AH-100 Q7 pays off here)

The More-tab "Settings" row already navigates to this page (`CreatorDashboardLayout.vue:370-380`). Today it opens a **web-styled
single-column list of switches** inside a flat bordered card, with the page description still saying "in-app". The matrix is therefore
**the first time "Settings" on native has content worth the label**. Consequences: (a) the native arrangement in §N2.3(5) is a design
deliverable, not an afterthought; (b) the page needs a native header treatment consistent with AH-100d's detail pages (it has none today —
no `.catalyst-native` rules, §N2.2); (c) the subtitle "Notification preferences" (`availability.json`) stays accurate.

### N6.4 i18n scope

| Add                                                                                                                    | Where                                          | ×locales |
| ---------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------- | -------- |
| `preferences.channels.email` (+ `push` later), reworded `preferences.description`                                      | `core/i18n/locales/*/notifications.json:42-58` | 24       |
| Completion-notice type label + body template (`types.assignment_live_verified`, `typeLabels.assignment_live_verified`) | same file                                      | 24       |
| Mail strings for the completion mailable                                                                               | `apps/api/lang/*/campaigns.php`                | 24       |
| Any inviter-only caption (§N2.4) and a messaging email-vs-digest hint                                                  | `notifications.json`                           | 24       |

### N6.5 Test and E2E surface

| Layer                | Existing                                                                                                                                                                                                         | Change                                                                                                                                              |
| -------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| API                  | `tests/Feature/Modules/Notifications/MeNotificationPreferencesTest.php`, `NotificationTypeEnumTest.php`, `tests/Feature/Modules/Identity/CreatorAppGuardSwapTest.php`                                            | per-site "no row → still sends" + "email=false → suppressed" for each guarded site; the structural-exemption architecture test (§N3.4)              |
| SPA unit             | `NotificationPreferencesPage.spec.ts`, `templates.spec.ts`, `tests/unit/architecture/i18n-notifications-parity.spec.ts`, `notificationVisual.spec.ts`, `CreatorDashboardLayout.spec.ts:483-505` (Settings entry) | matrix render per role; empty-cell rule; always-on exclusion; native stacked arrangement                                                            |
| **E2E (Playwright)** | **none** — no Playwright spec touches notification preferences (`apps/main/playwright`), so the matrix would be the first                                                                                        | one creator + one agency walk: toggle email off → save → reload → persisted; a mail-suppression assertion belongs in the API suite, not the browser |

**Not verified (Half A):** production preference-row counts (§N3.1), production `SOCIAL_PROVIDER` / the live flag state (§N4.3), and queue
behavior under the guard (no load test).

---

# HALF B — the third eyes-on UI batch (SPA-only, native-gated, risk NONE)

## U1 — The notification dropdown stays open after "View all notifications" navigates

**Close mechanism: there is none for that item.**

- `NotificationBell.vue:48-54` is a `v-menu` bound to `menuOpen` (`:35`) with **`:close-on-content-click="false"`** (`:51`). The only
  watcher on `menuOpen` _reloads_ the list when it opens (`:40-44`). Nothing sets it back to `false` on navigation.
- "View all notifications" is `<v-list-item :to="{ name: viewAllRoute }" … data-test="notification-view-all">`
  (`notifications/components/NotificationCenter.vue:318-327`) — **a router link with no `@click`** and no event to the parent. The router
  navigates; the menu is a sibling of the page, so it stays mounted and open.
- `close-on-content-click=false` is deliberate (taps _inside_ the list — mark-read, a row — must not dismiss it), which is why the default
  behavior does not save it.
- **Contrast with the siblings that work:** the More menu closes explicitly on every item — `@click="moreMenuOpen = false"`
  (`creators/layouts/CreatorDashboardLayout.vue:368` and `:379`); the avatar menu is built the same way as the bell — `:227-229`
  `close-on-content-click=false` — and closes per item (`@click="userMenuOpen = false"`, `:283`). The bell is mounted at `:224`
  (`view-all-route="creator.notifications"`).

**Options for the plan (no code):** (a) `NotificationCenter` emits a `close` event from that item and `NotificationBell` sets `menuOpen=false`;
(b) `NotificationBell` watches `route.fullPath` and closes — fixes _every_ current and future in-menu link; (c) flip to
`close-on-content-click=true` — rejected, it would dismiss on mark-read. **Recommendation: (b)**, with (a) as the explicit fallback. It is
SPA-only and applies on web and native alike (the bug is not native-specific; the native top bar just makes it more visible).
Pins to add: `NotificationBell` spec "navigating closes the menu".

## U2 — Sign-in and splash redesign from the new Figma frames

File `1Dd0NdZ55EXh58uNyvf9Bi` "Catalyst-Creator-App-– Restyled". Pulled via the Figma MCP (`get_design_context`, `get_variable_defs`) with
the `figma-design-to-code` guidance loaded first. **All three frames are 390 × 844** (iPhone portrait).

| Node      | Name                    | What it is                                                                                                                                                                                                                                                                                                                                                                                           |
| --------- | ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `201:445` | **01 Splash Screen**    | `bg/canvas` `#0b0b0c`; a large aurora-gradient glow washing from bottom-left (violet `#cd69ff`) to bottom-right (teal `#00fff2`) behind the mark, masked by an SVG shape (`Rectangle 154`, `201:529-532`); the **mark** `Frame 978` is **225.4 px (57.8 % of frame width)**, centred.                                                                                                                |
| `205:445` | **02 Sign in**          | Same canvas + glow (shifted up); mark **150 px** centred at y ≈ 200; **top bar** with a language pill at the right (`205:457-464`); a **bottom sheet** (`Sign in sheet`, `205:480`), full width, top at y = 304, 24 px top corners, `bg/surface`, 1 px `border/default`, `Shadow/Overlay`, with a 2 px aurora **brand hairline** across its top edge (`205:513`); an iOS home indicator (`205:514`). |
| `205:515` | **02b Sign in – error** | Same, with the **mark shrunk to 84 px** (`201:568`), the sheet pushed up to y = 220, and an **Error banner** (`205:554`) between the heading block and the email field; password border turns `status/danger`.                                                                                                                                                                                       |

### U2.1 Structure deltas against today

| #   | Figma                                                                                                                                                                                                        | Today                                                                                                                                                                                                        | Cite                                                                                    |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------- |
| S1  | **Full-bleed hero mark + bottom sheet.** The form lives in a rounded sheet anchored to the bottom, the mark floats above it.                                                                                 | A **web "Rebrand" landing in a card**: top header (logo lockup + locale `v-select`), hero panel headline (AH-100b D2 "hero headline only"), then a centred `v-card`.                                         | `auth/layouts/AuthLayout.vue:46-80`, native padding only `:236-238`                     |
| S2  | **No wordmark lockup, no hero headline.** The mark is the brand.                                                                                                                                             | Header carries `catalyst-logo.svg` (mark + wordmark, `:49`) and `AuthHeroPanel` renders the headline.                                                                                                        | `AuthLayout.vue:47-51, 70`                                                              |
| S3  | **Language pill** (globe + "English" + chevron, `radius/full`, `bg/surface`, `border/default`).                                                                                                              | An outlined `v-select` 160 px wide.                                                                                                                                                                          | `AuthLayout.vue:53-65, 210-212`                                                         |
| S4  | **Fields: label above, leading icon, trailing eye.** Email has a mail icon; Password has a lock icon and an eye toggle; **"Forgot password?" sits in the password label row**, right-aligned, accent colour. | Vuetify floating-label fields; "Forgot password" is a link **below** the form (`SignInPage.vue:315-317`). Label-above is an already-ratified deviation (AH-100b review; `native.css:114-116`).               | `auth/pages/SignInPage.vue:304-318`; `native.css:114-126`                               |
| S5  | **Two stacked buttons:** primary "Sign in" (44 px) + secondary **"Create an account"** (44 px, `bg/surface`, `border/strong`) under a "New to Catalyst?" rule.                                               | Primary button; sign-up is a **text pointer only** ("New to Catalyst? Create your account at {site}, then sign in here."). The router sign-up link is hidden on native.                                      | `SignInPage.vue:300-302, 308-310 (v-if !isNative), 320-326`; `locales/en/auth.json:193` |
| S6  | **Footnote under the secondary button:** "Accounts are created at **platform.catalyst-growth.com**. You can then sign in here."                                                                              | The same information, as a pointer paragraph.                                                                                                                                                                | `SignInPage.vue:325`; `CREATOR_SIGNUP_SITE`                                             |
| S7  | **Error banner above the fields** in `status/danger`, with an alert icon.                                                                                                                                    | An error message rendered inside the form (`errorKey` → text, `:289`); the session-expired banner is a tonal `v-alert` (`:239-247`). Rendering of the wrong-credentials state was not inspected beyond that. | `SignInPage.vue:64, 207-227, 239-247, 289`                                              |
| S8  | **Hero shrinks when content grows** (150 → 84 px, sheet 304 → 220). Implies a hero region sized by the space _above_ the sheet — and that the mark must resize with the keyboard too.                        | Static layout.                                                                                                                                                                                               | —                                                                                       |

> **Behavior trap, flag for the plan:** S5 introduces a **button** labelled "Create an account" on native. The AH-100 ruling (Q1) was a
> pointer, not an affordance (`docs/reviews/creator-app-figma-passes-review.md:47, 159`; `creator-app-client-shell-review.md:247`), and the
> footnote in the same frame says accounts are created on the website. The frame is ambiguous about what the button _does_. Either it opens
> the website (an external-link affordance on an iOS app — App Store review territory, **needs Pedram's ruling**) or it is decorative
> (a dead control). **Do not default; ask.**

### U2.2 Token deltas

Every Figma variable the three frames use, against `packages/design-tokens/native.css`:

| Figma variable                                          | Value                                                                           | `--native-*` today                                                                                                                      | Status                                                                                                            |
| ------------------------------------------------------- | ------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `bg/canvas`                                             | `#0b0b0c`                                                                       | `--native-bg-canvas` `:18`                                                                                                              | ✓ same                                                                                                            |
| `bg/surface`                                            | `#1b1b1d`                                                                       | `--native-bg-surface` `:21`                                                                                                             | ✓                                                                                                                 |
| `border/default` / `border/strong`                      | `#2e2e33` / `#3a3a40`                                                           | `:23-24`                                                                                                                                | ✓                                                                                                                 |
| `text/primary` / `secondary` / `tertiary` / `on-accent` | `#ededed` / `#a0a0a8` / `#6e6e76` / `#0b0b0c`                                   | `:25-28`                                                                                                                                | ✓                                                                                                                 |
| `accent/primary`                                        | `#3ecf9e`                                                                       | `:30`                                                                                                                                   | ✓                                                                                                                 |
| `status/danger`                                         | `#f0506e`                                                                       | `:33` (+ `-subtle` 16 % `:43`)                                                                                                          | ✓ — **see note**                                                                                                  |
| `radius/lg`, `radius/full`                              | 8, 999                                                                          | `:50, 53`                                                                                                                               | ✓                                                                                                                 |
| `space/1,2,3,5`                                         | 4, 8, 12, 20                                                                    | `--space-1…5` in `tokens.css:122-127`                                                                                                   | ✓ (web scale, shared)                                                                                             |
| `Shadow/Overlay`                                        | `0 12 32 #0000008C`                                                             | `--native-shadow-overlay` `:55`                                                                                                         | ✓                                                                                                                 |
| `Body/Small`, `Body/Default`, `Caption/*`, `Button/*`   | 13/19, 14/20, 12/16, 14/20                                                      | `:61-73`                                                                                                                                | ✓ all present                                                                                                     |
| **`Heading/H1`**                                        | Inter Medium **26/32**                                                          | nearest is `--native-type-display` 28/36 (`:58`)                                                                                        | **NEW token** (`--native-type-h1`)                                                                                |
| **Sheet top radius**                                    | **24 px**                                                                       | scale stops at `2xl` = 12 (`:48-53`)                                                                                                    | **NEW** (a radius step or a literal)                                                                              |
| **Brand hairline**                                      | 2 px, 342 px wide, `#cd69ff → #7fc3ff → #00fff2`                                | web has `--brand-aurora-gradient` (135°) `tokens.css:50` and `--auth-glow-gradient` (90°) `:71`                                         | **reuse `--auth-glow-gradient`** (the 90° one is the Figma direction)                                             |
| **Splash / hero glow**                                  | the same three stops, as a masked, blurred field behind the mark                | no native equivalent                                                                                                                    | **NEW asset or CSS** (see U2.4)                                                                                   |
| **Mark artwork** (`Frame 978`)                          | a **"ghost" C**: dark octagonal ring + a small glowing glass tile at its centre | the app/auth mark is `auth/assets/catalyst-logo.svg` (white ring + wordmark), cropped for icons by `scripts/app-icon-sources.mjs:35-41` | **NEW artwork** — same family, different treatment; must be **exported from Figma** (asset URLs expire in 7 days) |

**Two notes.** (1) The Figma **error banner is rendered with a tinted, translucent fill** (the screenshot shows ≈ 16 % danger over the
sheet), but the code export reports `bg/surface`-less solid `var(--status/danger)`. Treat the **screenshot** as authoritative and use the
existing `--native-status-danger-subtle` (`native.css:43`) — flagged because following the export literally would paint a solid red slab.
(2) **Field text size:** Figma draws 14 px; the app ships **16 px on purpose** (`native.css:64-67`, AH-100c D1: iOS auto-zooms under 16 px).
This is a _ratified_ deviation and should be carried, not re-litigated.

### U2.3 Splash — what the platform actually does today

| Platform                   | Mechanism today                                                                                                                                                                                                                                                                                                 | Cite                                                                                                                                                                               |
| -------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **iOS**                    | `LaunchScreen.storyboard` = one full-screen `UIImageView` of the `Splash` asset, `scaleAspectFill`, backed by `Splash.imageset` (1x/2x/3x, light + dark). The art is **a flat `#0b0b0c` square 2732 × 2732 with the cropped mark at 18 % width**, generated by `assets:generate`.                               | `apps/main/ios/App/App/Base.lproj/LaunchScreen.storyboard:15-19, 27`; `Assets.xcassets/Splash.imageset/Contents.json`; `scripts/app-icon-sources.mjs:23-24, 54`; `package.json:14` |
| **Android**                | `AppTheme.NoActionBarLaunch` extends `Theme.SplashScreen`; `windowSplashScreenBackground = @color/ic_launcher_background` (`#0B0B0C`); `android:background = @drawable/splash`. On **Android 12+ the OS draws launcher-icon-on-colour** and ignores the artwork; the full image shows only on older API levels. | `android/app/src/main/res/values/styles.xml` (launch theme), `values/ic_launcher_background.xml`, `res/drawable/splash.png`                                                        |
| **No programmatic splash** | `@capacitor/splash-screen` is **not** a dependency and `capacitor.config.ts` configures only `SystemBars`. So there is no hide/fade/animation hook: the launch image simply stays until the WebView paints.                                                                                                     | `apps/main/package.json:25-45`; `capacitor.config.ts:15-21`                                                                                                                        |

### U2.4 What the frames want, per platform, and the cost

- **iOS — full design is achievable.** A static launch image can carry the glow + the 225 px ghost mark. Costs:
  1. **New source artwork.** The generator currently _draws_ the splash from the logo SVG (`app-icon-sources.mjs`, "anyone can regenerate from the repo"). Figma's splash has a masked gradient and a different mark, so the source becomes a **committed raster/SVG exported from Figma**; the generator either wraps it or is bypassed. The asset spec pinning the canvas colour (`app-icon-sources.mjs:38`, "pinned equal to `nativePalette['bg-canvas']`") needs a corresponding pin on the new art's background.
  2. **Crop math.** `scaleAspectFill` of a _square_ on a _tall_ screen crops the sides: on a 402 × 874 pt screen only ≈ 46 % of the square's width is visible. A mark that is 57.8 % of the **frame** must be ≈ 26 % of the **square** so it survives. The existing 18 % constant (`:54`) is for a different mark.
  3. **Regenerate + review** `Splash.imageset` (6 PNGs) through `pnpm assets:generate` (`package.json:14`); this is a native-project diff, so it is a "needs a rebuild + simulator look" item, not a `pnpm dev` item.
- **Android — stays icon-on-colour.** Nothing in the frames changes that on 12+. **If** Pedram wants the glow/mark on Android, the only route is an **in-app boot screen** (a full-screen Vue view shown from first paint until auth bootstrap resolves). **This is a plan-pause proposal with cost, not a default:** it adds a _second_ branded moment after the OS splash on Android (and, if used on iOS too, a duplicate of the launch image); it must be gated on `isNativePlatform()`; it costs a new component, a bootstrap-state hook, a reduced-motion variant, and a test that it never blocks a cold start on a slow network. Flagged here for the plan; **not recommended as part of this chunk.**
- **Sign-in (both platforms) — SPA work, native-gated.** The structural deltas S1–S8 are a restyle of the native branch only. The web must not change (`native-css-scoping.spec.ts` already pins that every native rule is `.catalyst-native`-scoped; `AuthLayout.native.spec.ts` and `SignInPage.native.spec.ts` exist). The cheapest shape is a **native-only sign-in arrangement** (hero region + bottom sheet) selected on `isNative` inside `AuthLayout`/`SignInPage`, rather than re-skinning the web card with CSS alone; the plan-pause should choose.

**Not verified (U2):** the Figma file's _other_ frames (only the three provided were pulled); the exported SVG assets' contents (not downloaded —
they are temporary URLs); real-device keyboard behavior against S8.

## U3 — The Messages conversation view appears to have no reply composer

**Diagnosis: the composer is present in the DOM, not hidden by any native CSS, and sits _below the visible viewport, under the fixed bottom tab bar_.**
The thread's height is a web-mobile magic number that is ≈ 90 pt too tall inside the native shell.

### U3.1 What renders

The native Messages thread is `CreatorRelationshipThreadPage.vue:82` → `messaging/components/RelationshipThreadView.vue`. Its layout is a flex column
— header, a scrolling feed, the composer last (composer `RelationshipThreadView.vue:462-605`; attach menu `:544-561`). The root is `.rel-thread`
with an **explicit height**: `100dvh − 180px` with a 420px floor (`RelationshipThreadView.vue:610-618`), and on viewports ≤ 959px
`100dvh − 140px` with the floor released (`:623-628`). The `140px` is "top app bar + bottom nav + page padding" on the **web mobile** layout (the comment at `:620-622` says exactly that:
"fit between the top app-bar and bottom nav").

### U3.2 Why it is wrong inside the native shell

Native chrome is taller than the web's, by construction:

- App bar: `padding-top: var(--native-safe-top)` on top of its own height (`CreatorDashboardLayout.vue:432-437`).
- Tab bar: `height: calc(59px + var(--native-safe-bottom))` (`:444-451`) — 1 px rule + 58 px row + the home-indicator inset (~34 pt on the iPhone).
- `<v-main>` additionally pads by `--v-layout-top + --native-safe-top` and `--v-layout-bottom + --native-safe-bottom` (`:439-442`).

### U3.3 The numbers (geometry-derived from the simulator screenshot, 402 × 874 pt iPhone, Dynamic Island)

| Quantity                          | Value                                                                                                 | How                                                                                  |
| --------------------------------- | ----------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| Top chrome (status bar + app bar) | **≈ 126 pt**                                                                                          | bottom edge of the bar in the screenshot (`/tmp/ah101/sim0.png`, 1206 × 2622 px @3x) |
| Tab bar                           | **≈ 93 pt**                                                                                           | top of the bar ≈ 781 pt → 874 pt                                                     |
| Assumed by CSS                    | **140 pt**                                                                                            | `RelationshipThreadView.vue:625`                                                     |
| Actual chrome                     | **≈ 126 + 93 = ≈ 219 pt, plus ≈ 16 pt content padding ≈ 235 pt**                                      | sum                                                                                  |
| Overshoot                         | **≈ 90–95 pt**                                                                                        | 235 − 140                                                                            |
| Thread box                        | starts ≈ 135 pt, height `874 − 140 = 734 pt`, so it ends ≈ 869 pt — **the screen's bottom edge**      | arithmetic                                                                           |
| Composer                          | the last ≈ 60–80 pt of that box = **≈ 790–869 pt**, entirely inside the tab bar's **781–874 pt** band | arithmetic                                                                           |

So the composer is not missing: it is _covered_ by the fixed bar. The screenshot shows exactly that — a header, one message bubble, then
empty space down to the tab bar, with no field.

### U3.4 What I did not and could not measure

- **Not an interactive test.** I could not drive the Simulator window (AppleScript `Invalid index`), so I could not scroll the page to see
  whether the composer is _reachable_ by scrolling. The padding at `CreatorDashboardLayout.vue:439-442` suggests the page can scroll and
  would reveal it — but **nothing signals that**, which is why it reads as "no composer". Treat "reachable by scroll" as unverified.
- The pt figures are screenshot pixel geometry ÷ 3 (a ±2–3 pt measurement), not Web-Inspector computed styles.
- **No native-CSS hide:** there are **no `.rel-thread*` rules in any native stylesheet** (`creatorNative.css`, `native.css`) — confirmed by search.

### U3.5 Web comparison and options

On web mobile (≤ 959 px) the same rule is right: a 56–64 px app bar + 56 px bottom nav + padding ≈ 140. On desktop (> 959 px) the two-pane
shell uses `calc(100vh - 150px)` (`CreatorMessagesPage.vue:209-214`, `AgencyMessagesPage.vue:282`). **The defect is isolated to "native
chrome ≠ web mobile chrome".**

Fix directions for the plan (no code): (a) a native-scoped override that subtracts the live layout variables (`--v-layout-top`, `--v-layout-bottom`, the native safe-area
tokens) instead of a constant, so it tracks any future chrome change instead of adding another magic number; (b) make the thread a
flex child of `<v-main>` (height `100%`), removing the viewport arithmetic altogether (more invasive; touches the agency page too). **(a)
scoped to `.catalyst-native` is the SPA-only, web-safe, minimal choice.** Either way the pin is a native layout test that the composer's
bottom edge is above the tab bar's top edge. Note `ChatPanel` (campaign threads) has **no viewport-height rule** (`grep` finds none), so it
does not share this defect; it flows inside its host (`CreatorAssignmentDetailPage.vue:622`).

## U4 — The composer's "+" opens a one-item menu

### U4.1 Where the pattern appears

Two components own it, six host surfaces:

| Component                                                    | The "+"                                                                                      | Menu contents                                                | Cite                                                                                     |
| ------------------------------------------------------------ | -------------------------------------------------------------------------------------------- | ------------------------------------------------------------ | ---------------------------------------------------------------------------------------- |
| **`ChatPanel.vue`** — campaign/assignment threads            | `<v-btn icon="mdi-plus" … data-test="chat-attach-toggle" @click="attachOpen = !attachOpen">` | **one item:** paperclip (file). _This is the one-item menu._ | `messaging/components/ChatPanel.vue:324-333`; menu `:365-374`; state `:103`              |
| **`RelationshipThreadView.vue`** — the 1:1 agency↔creator DM | `mdi-plus`, `data-test="relationship-attach-toggle"`                                         | **two items:** paperclip (file) + link                       | `messaging/components/RelationshipThreadView.vue:504-511`; menu `:544-561`; state `:163` |

`ChatPanel` hosts: the **creator assignment detail** page (`creators/pages/CreatorAssignmentDetailPage.vue:622`), the **campaign messages
dialog** (`ChatDialog.vue:45`, opened by `CampaignMessagesPanel.vue:139`), and the **agency board-card drawer**
(`boards/components/BoardCardDrawer.vue:390`). `RelationshipThreadView` hosts: `CreatorRelationshipThreadPage.vue:82` and
`AgencyRelationshipThreadPage.vue:90`. Tests that pin it: `RelationshipThreadView.spec.ts` (`:205-206` reference the toggle) is the only spec that pins an attach id;
**none pins `ChatPanel`'s** `chat-attach-*` ids (they appear only in `ChatPanel.vue`).

### U4.2 The direct-paperclip replacement

- **`ChatPanel` (one item):** the "+" is a toggle for a single button, so the replacement is simply the paperclip **in the "+" slot**,
  removing `attachOpen` (`:103, 118`) and the extra row (`:365-374`). `data-test` ids `chat-attach-toggle` / `chat-attach-file` collapse
  to one — specs and any selector referencing either must be updated.
- **`RelationshipThreadView` (two items):** a direct paperclip **drops the link action**, which is a _feature removal_ unless link gets
  its own affordance. The repo already has the answer in the same codebase: **`CreatorAssignmentDetailPage.vue:720-746`** renders the
  **paperclip + link pair directly** (`d-flex ga-2`, both `variant="tonal" size="small"`), with the comment that it deliberately mirrors
  `RelationshipThreadView` (`:106-108`). So the options are (a) paperclip + link icon pair inline in the composer row (matches the
  assignment page; costs width on a 390 pt screen), or (b) paperclip only and drop link in DMs. **Plan-pause decision: (a) vs (b);
  recommendation (a)** — it removes the menu without removing a capability. Brief says "direct-paperclip replacement" — flagged because on
  one of the two components that is not a pure UI swap.
- **Scope:** the change is behavior-neutral on the server (same upload/attachment paths). It should apply on **web and native** (the
  pattern is not native-gated today); if the brief wants it native-only, say so — otherwise web users also lose the extra tap.

## U5 — The onboarding wizard's hamburger does nothing

### U5.1 What it was meant to open, web vs native

The button is `<v-app-bar-nav-icon data-test="onboarding-menu-btn" @click="menuDrawerOpen = !menuDrawerOpen">`
(`onboarding/layouts/OnboardingLayout.vue:267-271`), rendered in the **mobile branch** (`v-else` of `!isMobile`, `:255`), which on native is
**always** (`isMobile = isNative || smAndDown`, `useMobileShell.ts:23`). It toggles `menuDrawerOpen` (`:87`), which is the `v-model` of a
right-side temporary `v-navigation-drawer` (`:276-310`).

|                           | What the drawer holds                                                                                                                                          | Why it exists                                                                                                                     | Cite                                                                                             |
| ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| **Web mobile (≤ 959 px)** | the **locale switcher** + a block **"Save and exit"** button                                                                                                   | AH-007: collapse the cramped top bar's inline locale switcher and Save-and-exit into a hamburger so the bar is "just logo + menu" | `OnboardingLayout.vue:81-87`, `:283-308`; `docs/reviews/adhoc-changes-log.md:4443-4445` (AH-007) |
| **Native**                | the **locale switcher only** — "Save and exit" is moved **inline** beside the menu and removed from the drawer (`v-if="showSaveAndExit && !isNative"`, `:298`) | AH-100 / Figma frame 01: "Save and exit" inline in the app bar                                                                    | `:253-266`, `:298`; spec `onboarding/layouts/OnboardingLayout.native.spec.ts:160-184`            |

So on native the hamburger was **meant to open a drawer whose only content is a language dropdown.** The Figma frame 01 draws a menu icon
beside "Save and exit" (the code comment `:254` cites it) but **no frame shows what it opens**; I found no decision record for the native
drawer's contents (`creator-app-figma-passes-review.md` and `creator-app-client-shell-review.md` do not mention it).

### U5.2 Why it appears to do nothing — what is established and what is not

**Established by reading the code:**

- The wiring is complete: button → `menuDrawerOpen` → `v-model` on the drawer. No missing handler, no `disabled`, no overlay swallowing the tap
  that I could find, and **no native CSS touches the drawer, the nav-icon or the overlay** (`onboardingNative.css` and `native.css` contain no
  `drawer`/`scrim`/`v-overlay`/`nav-icon` rule; the layout's own native rules `:436-458` style only the bar and `<v-main>`).
- **The only spec that "covers" it cannot fail.** `OnboardingLayout.native.spec.ts:172-184` clicks the button and asserts that the locale
  switcher _exists_ — but Vuetify's `VNavigationDrawer` (3.12.5, the installed version) renders its `<aside>` and content whether open or closed,
  marking it `inert` and dropping the `--active` class when closed (`vuetify/lib/components/VNavigationDrawer/VNavigationDrawer.js:230-234`);
  only the scrim is conditional (`:266`). So the assertion holds with the drawer shut. Nothing pins that the drawer **opens**, and there is no Playwright coverage (`grep` finds the ids only in the
  layout and that spec).

**Not established:** _why_ it looks dead on the device. With the code as written the two most likely explanations are (1) the drawer **does**
open but contains only a language dropdown, which reads as "nothing happened" on a screen where the sign-in no longer shows a locale
control; or (2) a real open/z-order defect specific to the native top bar (the bar was given `padding-top: var(--native-safe-top)` in AH-100,
`:439`, and the drawer's layout offset is computed against the bar). **I could not drive the simulator to distinguish them** (§U3.4), and I
will not guess — the plan-pause should either include a one-minute device check or fold it into whichever option is chosen.

### U5.3 Wire-or-remove — for the plan

| Option                                    | What happens                                                                                                                                                 | Cost                                               | Risk                                                                                                                        |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| **A. Remove on native**                   | Native top bar = logo + inline "Save and exit"; no hamburger, no drawer on native. Locale stays reachable from Settings if a locale control is wanted there. | smallest; delete a branch + update the native spec | loses locale switching during onboarding **on native** (the app currently has no other locale entry in the wizard — verify) |
| **B. Wire it to something native-worthy** | The drawer carries things a creator needs mid-onboarding: language, **Sign out**, help/support link.                                                         | new content + strings ×24; a real device pass      | scope creep; needs Pedram to say what belongs                                                                               |
| **C. Keep and fix**                       | Diagnose the dead tap on device, fix, add the missing "opens" assertion.                                                                                     | depends on the cause                               | none, but retains a one-item menu                                                                                           |

**Recommendation: A**, unless Pedram wants locale in the wizard — in which case **B with language only is just option C**, and the honest
question is whether a one-item drawer is the right pattern (the _same_ complaint as U4's one-item menu). Keep the web behavior unchanged either
way.

---

# Open questions for Pedram

| #       | Question                                                                                                                                    | Why it blocks                                                                                               |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| **Q1**  | Production `user_notification_preferences`: is the count of `email` rows zero? (One read-only count grouped by `channel` and `is_enabled`.) | §N3.4 — decides "honour" vs "clear" for crafted rows before the guard ships.                                |
| **Q2**  | `creator.approved` / `creator.rejected` mail: **always-on** (recommended) or togglable?                                                     | §N3.5 — the only account-lifecycle emails.                                                                  |
| **Q3**  | Agency inviter-only mails (`draft_submitted`, `contracted`): omit the email cell, caption it, or change who is mailed?                      | §N2.4 — a dead control for non-inviters otherwise.                                                          |
| **Q4**  | Production `SOCIAL_PROVIDER` and the live state of `social_verification_enabled`?                                                           | §N4.3 — a completion notice on mock verification would tell creators a post was "verified" when it was not. |
| **Q5**  | `live_verified` notice: creator only, or creator + agency fan-out? Mail + in-app, or in-app only?                                           | §N4.3.                                                                                                      |
| **Q6**  | Matrix on native: stacked cards per type (recommended) vs a horizontal grid; one Save vs autosave per cell?                                 | §N2.3(5)–(6).                                                                                               |
| **Q7**  | U2: what does the secondary **"Create an account"** button do on native?                                                                    | §U2.1 — an external-link affordance is App-Store-sensitive; a dead button is a no-go.                       |
| **Q8**  | U2: iOS only gets the full splash; **do you want an in-app boot screen for Android** (not recommended in this chunk)?                       | §U2.4.                                                                                                      |
| **Q9**  | U4: `RelationshipThreadView` — paperclip + link pair inline (recommended) or paperclip only? Web too, or native only?                       | §U4.2.                                                                                                      |
| **Q10** | U5: remove the native hamburger (A, recommended), give it content (B), or diagnose on device (C)?                                           | §U5.3.                                                                                                      |

---

# Plan-pause seed

**Shape:** one chunk, two commit pairs (Half A behavioral, Half B SPA-only) following inventory → kickoff → plan-pause → build → two-commit pair.
Half A is LOW-MEDIUM; Half B is NONE and native-gated. One plan-pause covers both.

**Half A (proposed order, each independently revertable):**

1. **A1 — the guard seam (BE, inert).** One shared "may this user be mailed this type" read keyed on `NotificationType`; applied beside the existing flag
   checkpoints (rows 1, 2, 9–11, 12) and at the unflagged sites (rows 4–8; 13–14 per Q2). Mails with no type stay structurally outside it.
   Break-revert: with no rows every site still sends; with an `email=false` row only that site stops.
2. **A2 — the completion notice (BE + FE vocabulary).** `assignment.live_verified` type + mailable + listener arm + strings ×24 + FE registry/visual/i18n/api-client.
   Ungated in-app, flagged mail. Gated on Q4/Q5.
3. **A3 — the matrix (FE).** Column derivation from `channels`; `email` appended per type **only** as A1 lands that type's guard; empty cells; always-on exclusion; native stacked arrangement; `push` is a header-constant + enum-case addition (AH-102).
4. **A4 — ripple.** Reworded description + channel labels ×24; parity specs; the first Playwright walk for preferences; the architecture test that every mail site is routed or allowlisted.

**Half B (each is its own small, native-gated change):**

1. **U1** `NotificationBell` closes on route change (option b) + spec.
2. **U3** native-scoped `.rel-thread` height from the live layout vars + a layout pin; verify on device.
3. **U4** collapse the "+" menu (ChatPanel → paperclip; RelationshipThreadView per Q9) + spec updates.
4. **U5** per Q10.
5. **U2** the largest: native sign-in arrangement (S1–S8), `--native-type-h1` + sheet radius token, the splash artwork export + `assets:generate`, per Q7/Q8. Needs a device walk; gate behind the others.

**Risks to carry into the plan-pause:** the dormant-row caveat (Q1), the deploy order (API before SPA), the dead-control cases (Q3), the
mock-verification reachability (Q4), and that **U2's splash art lands as a native-project diff** and cannot be verified in a browser.

**Out of scope here, recorded so it is not re-opened:** the mail-inside-transaction hazard (`docs/tech-debt.md:2454-2470`), a real social
adapter, AH-102's push worker, and any change to _who_ receives a mail.
