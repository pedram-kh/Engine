# Creator email notification set — inventory (chunk #1, "the mail chunk")

**Status:** Read-only inventory. No code changed, no plan written, no decisions made.
**PROD-DATA RISK: NONE** — this document reads code and docs only.
**Eventual build (not this doc):** ⚠️ LOW-MEDIUM expected — new mail volume to ~279 live creators
(flag-gated), one new stamp/state table for the §I3 debounce, preference-read wiring on the live
notifier paths that currently send mail unconditionally.

Orientation read through [`AH-082`](adhoc-changes-log.md#L73) (insert-link button + cap raise — unrelated
to this chunk, confirms nothing about the notification stack changed since). Load-bearing precedents:
**AH-056** (jobs-board chunk 3 — the email-channel tech-debt entry + the `LIVE_TYPES`/preferences
machinery), **AH-058** (jobs-board chunk 4 — dual-emit-in-one-service + the "mail flag gates mail
only" ruling), **AH-048** (incomplete-creator nudge — the once-only-stamp + cap + `--dry-run`
furniture), the AH-058 mailable pattern (`CampaignApplicationNotifier`), `PROJECT-WORKFLOW.md` §5.3
(real-rendering mailable tests) and §5.40 (production-data safety / schema-only migrations).

---

## The three headline answers (read this first)

**1. I2's "absent-means-ON" reality.** It already holds, structurally, for free. `NotificationChannel::Email`
already exists and already defaults to `true`
(`apps/api/app/Modules/Notifications/Enums/NotificationChannel.php:22,28-34`), storage is already sparse
(a missing row resolves to the channel default — never a stored value —
`apps/api/app/Modules/Notifications/Services/NotificationService.php:108-121`), and **no `email`-channel
row has ever been written**: every `channels` array ever exposed to the prefs UI has been `['in_app']`
or `['in_app','digest']` — `email` has never appeared in `LIVE_TYPES`
(`apps/main/src/modules/notifications/templates.ts:92,103,108,…`) or in the rendered `channels` i18n block
(`apps/main/src/core/i18n/locales/en/notifications.json:56-59`, which has `in_app` + `digest` only, no
`email` key at all). So no creator has ever had the opportunity to set an email preference, sparse or
otherwise — the §5.40 "nobody's existing choice may silently change meaning" question resolves to
**vacuously safe**: there is no existing choice to reinterpret. The only wiring needed for I2's
mechanical half is (a) call `isChannelEnabled($user, $type, NotificationChannel::Email)` at each mail
call site instead of queuing unconditionally, and (b) add `NotificationChannel::Email` to the relevant
`LIVE_TYPES` `channels` arrays + the `preferences.channels.email` i18n leaf. Neither requires a
migration.

**2. I5's flag-collision.** Not a clean collision — a **three-way split**, which is the real finding.
Today's nine map onto three different, incompatible gating regimes: (a) ②③④ already ride
`job_posted_notifications_enabled` / `application_notifications_enabled`, each of which is documented
and tested as **"gates mail only, in-app unaffected"**
(`docs/feature-flags.md:52-54`, `apps/api/app/Modules/Creators/Features/JobPostedNotificationsEnabled.php`,
`.../ApplicationNotificationsEnabled.php`); (b) ⑤⑥⑦(partial)⑨ mail **already ships today, completely
unflagged and unconditional** — `DraftReviewedMail`, `AssignmentCompletedOnApprovalMail`,
`PostManuallyVerifiedMail` and `ContractAttachedMail` are queued straight off `SendAssignmentNotifications`
/ `CampaignAssignmentContractController` with no `Feature::active()` check anywhere in the call path
(see §I1/§I4); (c) ①⑧ and one of ⑦'s three completion paths **have no mail and no flag because they
have no emit site at all**. A single new `creator_email_notifications_enabled` flag "gating all nine"
would, for group (b), introduce a **new default-OFF gate on mail that currently sends unconditionally**
— i.e. it would silently _stop_ live draft-approved / revision-requested / contract-attached mail the
moment it ships, until an operator arms the new flag. That is a materially bigger behaviour change than
"wire preferences" implies, and is the honest version of the collision to carry into kickoff. See §I5
for the options (double-gate ②③④, retire the two old flags into the new one, or leave ②③④ on their own
flags and scope the new flag to preference-reads only) — this document does not choose between them.

**3. I1's ⑨ gap size.** Genuinely the biggest gap of the nine, and bigger than ①. ① (`assignment.invited`)
at least has a live `AuditAction` case and a `NotificationType` case already — it only lacks an emit
site. ⑨ ("contract/onboarding action required") has **no `AuditAction` verb, no `NotificationType` case,
and no in-app row at all** for the one concrete event that already sends mail today (the per-campaign
contract-attached notice) — see §I1/§I4. The broader "onboarding action required" half of ⑨ (stalled
KYC/payout/contract wizard steps) has no event-driven notification of any kind — the only existing
precedent is AH-048's incomplete-creator nudge, which is a **scheduled batch scan**, not an
event-triggered notification, and only covers creators who never finished the _initial_ wizard at all.
⑨ is therefore two distinct sub-problems wearing one bullet, and needs the most net-new vocabulary of
the nine.

---

## I1 — The notification-type map

| #   | Feature                             | Existing `NotificationType`(s)                                                                                                                                                                                                                                                                                                                                                                                                                  | In-app today?                                                                                                                                                                                                                                                           | Mail today?                                                                                                                                                                                                                                                                                                                                                                                                                                         | Emit site(s)                                                                                                                                                                                                              | Gap                                                                                                                                                                                                                                                 |
| --- | ----------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| ①   | Campaign invite received            | `AssignmentInvited` = `assignment.invited` exists in both `AuditAction` (`apps/api/app/Modules/Audit/Enums/AuditAction.php:242`) and `NotificationType` (`apps/api/app/Modules/Notifications/Enums/NotificationType.php:44`)                                                                                                                                                                                                                    | **No** — never in `LIVE_TYPES`; explicitly listed as `EMIT_LESS_TYPES`/`DEFERRED_WITHOUT_EMITTER` in both FE parity specs (`apps/main/tests/unit/architecture/i18n-notifications-parity.spec.ts:94-101`, `apps/main/src/modules/notifications/templates.spec.ts:36-43`) | **No**                                                                                                                                                                                                                                                                                                                                                                                                                                              | `CampaignInvitationService` logs the audit row only (`apps/api/app/Modules/Campaigns/Services/CampaignInvitationService.php:113,127`) — no `NotificationService::notify()` call, no `Mail::queue()` anywhere in that path | **Full gap** — zero notification of any channel today                                                                                                                                                                                               |
| ②   | Job posted                          | `CampaignJobPosted` = `campaign.job_posted` (`NotificationType.php:121`)                                                                                                                                                                                                                                                                                                                                                                        | Yes, toggleable (`templates.ts:220-224`, group `jobs_board`)                                                                                                                                                                                                            | **Yes** — `JobPostedMail` (`apps/api/app/Modules/Campaigns/Mail/JobPostedMail.php`), queued unconditionally in `JobPostedFanOutService::queueMail()` (`apps/api/app/Modules/Campaigns/Services/JobPostedFanOutService.php:265-274`)                                                                                                                                                                                                                 | `JobPostedFanOutService::send()` (`…JobPostedFanOutService.php:117-151`)                                                                                                                                                  | **Exists with mail.** Mail gated by `job_posted_notifications_enabled` only (`…JobPostedFanOutService.php:119`); no per-user email preference read anywhere in the class                                                                            |
| ③   | Application accepted                | `CampaignApplicationAccepted` (`NotificationType.php:156`)                                                                                                                                                                                                                                                                                                                                                                                      | Yes (`templates.ts:230-234`)                                                                                                                                                                                                                                            | **Yes** — `ApplicationAcceptedMail`, queued via `CampaignApplicationNotifier::queue()` (`apps/api/app/Modules/Campaigns/Services/CampaignApplicationNotifier.php:188-203,273-286`)                                                                                                                                                                                                                                                                  | `CampaignApplicationNotifier::accepted()`                                                                                                                                                                                 | **Exists with mail.** Gated by `application_notifications_enabled` only                                                                                                                                                                             |
| ④   | Application rejected                | `CampaignApplicationRejected` (`NotificationType.php:157`)                                                                                                                                                                                                                                                                                                                                                                                      | Yes (`templates.ts:241-245`)                                                                                                                                                                                                                                            | **Yes** — `ApplicationRejectedMail`, two causes (`agency_rejected`/`campaign_closed`) in `data.cause`, same notifier                                                                                                                                                                                                                                                                                                                                | `CampaignApplicationNotifier::rejected()` (`…CampaignApplicationNotifier.php:212-260`)                                                                                                                                    | **Exists with mail.** Same flag as ③                                                                                                                                                                                                                |
| ⑤   | Draft changes requested             | `AssignmentRevisionRequested` (`NotificationType.php:50`)                                                                                                                                                                                                                                                                                                                                                                                       | Yes (`templates.ts:111-115`)                                                                                                                                                                                                                                            | **Yes, already** — `DraftReviewedMail` with `outcome='revision_requested'` (`apps/api/app/Modules/Campaigns/Mail/DraftReviewedMail.php`), queued **unconditionally** — no flag, no preference check (`apps/api/app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php:277-338`)                                                                                                                                                            | `SendAssignmentNotifications::notifyCreatorOfReview()`                                                                                                                                                                    | **Exists with mail already, unflagged.** The "wiring" here is adding a preference gate to mail that has never had one, not creating a new mail leg                                                                                                  |
| ⑥   | Draft approved                      | `AssignmentDraftApproved` (`NotificationType.php:51`)                                                                                                                                                                                                                                                                                                                                                                                           | Yes (`templates.ts:116-120`)                                                                                                                                                                                                                                            | **Yes, already** — same `DraftReviewedMail`, `outcome='approved'`, same unconditional send, **except** suppressed when the approval also completes the assignment (`suppressEmail` param, AH-069 — `…SendAssignmentNotifications.php:86-91,304`) — see ⑦                                                                                                                                                                                            | same method                                                                                                                                                                                                               | Same as ⑤: existing, unflagged mail                                                                                                                                                                                                                 |
| ⑦   | Assignment complete                 | Fragmented across **three** transitions, only two notified: `AssignmentCompletedOnApproval` (`NotificationType.php:59`) and `AssignmentManuallyVerified` (`NotificationType.php:60`). The third — `assignment.live_verified` (ordinary auto-verification pass, almost certainly the **majority** completion path) — is deliberately **excluded** from `NotificationType` as "internal / non-notification" (`NotificationType.php:26`, docblock) | Two of three transitions notify (`templates.ts:131-140`); `live_verified` has none                                                                                                                                                                                      | Two of three mail: `AssignmentCompletedOnApprovalMail` (`…/Mail/AssignmentCompletedOnApprovalMail.php`, unconditional, `…SendAssignmentNotifications.php:114-147`) and `PostManuallyVerifiedMail` (`…/Mail/PostManuallyVerifiedMail.php`, unconditional, `…SendAssignmentNotifications.php:188-223`); **no mail for `live_verified`** at all (AH-047 gave it an in-app UI banner only, no mailable — `docs/reviews/adhoc-changes-log.md:1883-1908`) | same listener                                                                                                                                                                                                             | **Partial gap.** "Assignment complete" as one bullet hides a real fork: the most common completion path has zero notification of either channel today                                                                                               |
| ⑧   | New agency message (debounced)      | `MessageReceivedByCreator`/`MessageReceivedByAgency` (campaign thread) **and** `MessageRelationshipReceivedByCreator`/`…ByAgency` (1:1 roster DM) — **two separate models**, see §I3                                                                                                                                                                                                                                                            | Yes, immediate, both pairs (`templates.ts:157-181`)                                                                                                                                                                                                                     | **No immediate mail for either.** The only email path is `UnreadMessagesDigestMail`, a **daily batch digest**, opt-in/default-OFF (`apps/api/app/Modules/Messaging/Services/MessageDigestService.php`) — a different cadence entirely, not a debounced per-conversation email                                                                                                                                                                       | `SendMessageNotifications` (campaign thread), `RelationshipMessageNotifications` (1:1 DM)                                                                                                                                 | **Full gap for the debounced email itself** — plus an open question over _which_ thread model ⑧ means (see §I3)                                                                                                                                     |
| ⑨   | Contract/onboarding action required | **None.** `ContractAttachedMail` sends today but rides no `AuditAction` and no `NotificationType` at all (see §I4). Onboarding-wizard-stalled events (`CreatorWizardContractInitiated`, `…PayoutInitiated`, `…KycInitiated` — `apps/api/app/Modules/Audit/Enums/AuditAction.php:83-86`) are audit-only, never `NotificationType` cases                                                                                                          | **No**                                                                                                                                                                                                                                                                  | Mail exists for ONE sub-case only (contract attached)                                                                                                                                                                                                                                                                                                                                                                                               | `CampaignAssignmentContractController::notifyCreator()` (`apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAssignmentContractController.php:269-286`)                                                              | **Largest gap.** Needs new `AuditAction` + `NotificationType` + `LIVE_TYPES` entry just to catch up the existing contract-attached mail to the one-vocabulary tie, before any "onboarding action required" (wizard-stalled) sub-case is even scoped |

**Outcome table (the authoritative split the kickoff asked for):**

- **Exists-with-mail already:** ②③④ (flag-gated) and ⑤⑥⑦-partial⑨-partial (**unflagged, unconditional** — a materially different starting point than ②③④).
- **Exists-in-app-only:** none of the nine are purely in-app-only; every one with an in-app row also already has _some_ mail, except ①⑧ which have neither.
- **Doesn't exist at all:** ① (fully — audit verb and notification type exist, nothing emits), the `live_verified` third of ⑦, ⑧'s debounced email specifically (immediate in-app exists), and ⑨'s notification-vocabulary half (the mail exists, orphaned from the type system).

---

## I2 — The preference machinery's email half

**`LIVE_TYPES`' channel model today.** Every entry is `IN_APP_ONLY` (`apps/main/src/modules/notifications/templates.ts:92`) except the two messaging pairs, which additionally expose `digest`
(`templates.ts:160,165`). No entry has ever included `email` as a channel value, even though
`NotificationChannel::Email` has existed in the backend enum since the same chunk that added `Digest`
(`apps/api/app/Modules/Notifications/Enums/NotificationChannel.php:19-23`).

**What "wiring email through preferences" touches, concretely:**

- **Prefs page UI** (`apps/main/src/modules/notifications/pages/NotificationPreferencesPage.vue`) — no
  code change needed to the component itself; it already renders one `v-switch` per
  `(type, channel)` pair returned by `preferenceGroupsForRole()` (`NotificationPreferencesPage.vue:187-206`).
  Adding `email` to a type's `channels` array in `templates.ts` is what makes a new switch appear. The
  i18n bundle is missing the leaf, though: `notifications.preferences.channels` has `in_app` and
  `digest` only, no `email` key (`apps/main/src/core/i18n/locales/en/notifications.json:56-59`) — that's
  a new key × 24 locales, and the page's `description` copy ("Choose which **in-app** notifications…",
  `notifications.json:43`) is now inaccurate and would need a copy pass too.
- **The resource/endpoint** — `NotificationPreferenceController` (`apps/api/app/Modules/Notifications/Http/Controllers/NotificationPreferenceController.php`)
  is already channel-agnostic: `index()`/`update()`/`defaults()` iterate `NotificationChannel::cases()`
  (`…Controller.php:88-97`) and `NotificationPreferenceResource` just echoes whatever channel/type/bool
  it's given (`apps/api/app/Modules/Notifications/Http/Resources/NotificationPreferenceResource.php:27-37`).
  **No backend endpoint change is needed** — `email` already round-trips through this surface; only the
  request validation (`UpdateNotificationPreferencesRequest`) needs to permit `channel=email` if it
  doesn't already (its enum-backed validation should already accept any `NotificationChannel` case —
  worth confirming at kickoff, not re-verified line-by-line here).
- **The notifier read path** — the sibling check to AH-058's flag check is
  `NotificationService::isChannelEnabled($recipient, $type, NotificationChannel::Email)`
  (`apps/api/app/Modules/Notifications/Services/NotificationService.php:108-121`), the exact method
  `MessageDigestService::buildDigest()` already calls for the `Digest` channel
  (`apps/api/app/Modules/Messaging/Services/MessageDigestService.php:165`). That call is the pattern to
  replicate at each of the nine mail-queueing call sites — it does not exist today at any of them (see
  §I1/§I4: every current mail send is `Mail::to(...)->queue(...)` unconditionally, gated at most by a
  Pennant flag, never by a per-user preference read).

**Default-ON mechanics.** Confirmed structurally sound already: `NotificationChannel::Email->defaultEnabled()`
returns `true` (`NotificationChannel.php:31`), and `NotificationService::isChannelEnabled()` returns that
default the moment no row exists (`NotificationService.php:116-118`) — this is the exact "preserve-current"
contract the Ch1 design doc built for `in_app`/`email` from the start
(`NotificationPreference.php:17-25` docblock). **Storage shape supports absent-means-default**: the table
is sparse by construction (`setPreference()` **deletes** a row when the toggle returns to the channel
default rather than storing `is_enabled = true` — `NotificationService.php:79-100`), so there is no
"we forgot to seed everyone's email row" risk class to worry about — the mechanism was built to never
require seeding.

**Whether any existing creator has a stored preference that would be reinterpreted.** No — see the
headline answer above. `channel=email` has never been offered in any UI the product has shipped, so no
row with `channel='email'` should exist in `user_notification_preferences` today (worth a one-query
confirmation at kickoff — `SELECT count(*) FROM user_notification_preferences WHERE channel='email'` —
but nothing in the code path makes it reachable). The §5.40 concern the kickoff named — "nobody's
existing choice may silently change meaning" — has no existing choices to protect.

---

## I3 — The ⑧ debounce

**Two distinct conversation models exist, and the prompt's "new agency message" doesn't disambiguate
between them — this is worth raising at kickoff, not assumed:**

- **Campaign-assignment thread** (`MessageThread`, one per `CampaignAssignment` —
  `apps/api/app/Modules/Messaging/Models/MessageThread.php:20-30`). Agency→creator sends notify
  in-app immediately via `SendMessageNotifications::dispatch()` (`apps/api/app/Modules/Messaging/Services/SendMessageNotifications.php:63-76`,
  type `MessageReceivedByCreator`). This is the thread the existing daily digest already covers
  (`MessageDigestService`).
- **Relationship thread** (`RelationshipThread`, one per connected agency↔creator pair, independent of
  any campaign — `apps/api/app/Modules/Messaging/Models/RelationshipThread.php:19-33`). Agency→creator
  sends notify in-app immediately via `RelationshipMessageNotifications::dispatch()`
  (`apps/api/app/Modules/Messaging/Services/RelationshipMessageNotifications.php:73-85`, type
  `MessageRelationshipReceivedByCreator`). This thread's digest is **explicitly deferred**
  (`docs/tech-debt.md:505`, AH-010 D5) — there is no email path for it at all today, immediate or
  digested.

Both are plausible readings of "new agency message." If the intent is "wherever an agency messages a
creator," both surfaces need the debounce; if it's scoped to one, that halves the emit-site surface.

**The stamp shape needed is genuinely NEW, not a drop-in of the AH-048/AH-056 family.** Both existing
precedents are **once-only** stamps — write once, never touch again:
`campaign_job_notifications.notified_at` (`apps/api/database/migrations/2026_07_27_110001_create_campaign_job_notifications_table.php:63`,
unique on `(campaign_id, creator_id)`) and `creators.incomplete_nudge_sent_at` (AH-048, a single nullable
timestamp column). ⑧'s "re-armed 30 minutes after the last emailed notification for that thread" needs
a stamp that is **read and conditionally re-written on every candidate message**, keyed by
`(conversation, recipient)` — closer in shape to `MessageThread.last_message_at` /
`RelationshipThread.last_message_at` (both already exist as per-thread timestamps —
`MessageThread.php:38`, `RelationshipThread.php:44`) than to either once-only precedent, except it
needs to be **per-recipient**, not per-thread (an agency-side thread fans out to N notifiable members —
`Agency::notifiableMembers()` — each of whom needs their own 30-minute window). Neither existing thread
model carries a per-member read/email cursor today (`message_read_receipts` tracks _read_ state, not
_emailed_ state). This is new schema either way: a `last_emailed_at` column doesn't fit on the thread
row itself (per-recipient, not per-thread) and doesn't fit the once-only stamp tables' unique-pair,
insert-only semantics — it is closer to a new narrow table, `(thread_type/id, recipient_user_id,
last_emailed_at)`, updated in place rather than inserted once.

**Where the check would run.** Both `SendMessageNotifications::dispatch()` and
`RelationshipMessageNotifications::dispatch()` are synchronous calls from the message-send controllers
(not queued jobs themselves — the mail they'd newly queue would be, same as every other mailable here).
Neither currently checks any preference or flag; both would need the same per-call stamp-read /
compare-30-minutes / conditionally-queue-and-restamp logic, ideally factored once (the
`CampaignApplicationNotifier::queue()` "one checkpoint" pattern — `CampaignApplicationNotifier.php:262-286`
— is the precedent to copy, so neither of the two dispatch paths can drift the way AH-051's ungated
types did).

---

## I4 — Mailable inventory

**Which of the nine have mailables today, confirmed:**

| Mailable                                                                            | Covers                                                  | Gated by                                                               | AH origin                           |
| ----------------------------------------------------------------------------------- | ------------------------------------------------------- | ---------------------------------------------------------------------- | ----------------------------------- |
| `JobPostedMail`                                                                     | ②                                                       | `job_posted_notifications_enabled` (flag only, no pref read)           | AH-056                              |
| `ApplicationAcceptedMail`                                                           | ③                                                       | `application_notifications_enabled` (flag only)                        | AH-058                              |
| `ApplicationRejectedMail`                                                           | ④                                                       | `application_notifications_enabled` (flag only)                        | AH-058                              |
| `DraftReviewedMail` (`outcome` param: `approved`\|`revision_requested`\|`rejected`) | ⑤⑥ (and rejected, not one of the nine)                  | **Nothing — unconditional**                                            | Sprint 9 Ch2, copy refreshed AH-068 |
| `AssignmentCompletedOnApprovalMail`                                                 | ⑦ (1 of 3 paths)                                        | **Nothing — unconditional**                                            | AH-069                              |
| `PostManuallyVerifiedMail`                                                          | ⑦ (1 of 3 paths)                                        | **Nothing — unconditional**                                            | verification-resolution chunk       |
| `ContractAttachedMail`                                                              | ⑨ (contract sub-case only)                              | **Nothing — unconditional**; not even in the `NotificationType` system | per-campaign-contract chunk         |
| —                                                                                   | ①, ⑧, ⑦'s `live_verified` path, ⑨'s onboarding sub-case | none exist                                                             | —                                   |

**The c3/c4 mailable pattern (AH-056/AH-058), confirmed as the shape to reuse:** one service method
per emission, in-app + mail emitted together, ONE `Feature::active()` check per service
(`CampaignApplicationNotifier::queue()` — `apps/api/app/Modules/Campaigns/Services/CampaignApplicationNotifier.php:273-286`),
structured logging of the flag's decision so silence is legible (`…CampaignApplicationNotifier.php:311-327`,
built specifically because AH-059 burned an hour attributing an unarmed flag to a broken mail path —
`docs/reviews/adhoc-changes-log.md:1219-1234`).

**The post-AH-068 localized-mailable / render-test standard, current state:** `DraftReviewedMailTest.php`
is the reference (`apps/api/tests/Feature/Modules/Campaigns/DraftReviewedMailTest.php:1-59`) — §5.3
real-rendering tests (`docs/PROJECT-WORKFLOW.md:203-207`) that instantiate the mailable with scalars only,
force `App::setLocale()`, and call `->envelope()`/`->render()` directly across all 24 locales
(`…DraftReviewedMailTest.php:152`), catching a broken Blade conditional or missing locale key that
`Mail::assertQueued()` alone would miss. Every existing campaign mailable already has 24-locale copy
committed (`apps/api/lang/en/campaigns.php:9-88` — `reviewed`, `completed_on_approval`,
`manually_verified`, `resubmit_requested`, `contract_attached` blocks all present), so ⑤⑥⑦(2 of 3)⑨(1 of 2)
need **zero new mail copy** — only the render-test-per-locale standard applied if not already, plus the
preference-gate wiring. New copy is needed only for ①, ⑧, ⑦'s missing `live_verified` mail (if scoped
in), and ⑨'s onboarding-stalled sub-case (if scoped in).

**Template-family question — can ⑤⑥⑦ share a layout family with round/status params?** Partially already
proven, partially not taken. `DraftReviewedMail` already IS that shared family for ⑤⑥ — one mailable,
one Blade view (`mail.campaigns.draft-reviewed`), an `outcome` discriminator picking subject/body variant
(`DraftReviewedMail.php:33-65`), plus the `CarriesDraftRound` concern for the shared round-number clause
(`apps/api/app/Modules/Campaigns/Mail/Concerns/CarriesDraftRound.php`). ⑦, by contrast, is **two separate
mailable classes** (`AssignmentCompletedOnApprovalMail`, `PostManuallyVerifiedMail`) that were **not**
folded into that family despite being adjacent "your assignment reached a good end state" mails — each
has its own Blade view and its own subject line. This is evidence the shared-family shape is viable
(⑤⑆ prove it) but wasn't extended to ⑦ when ⑦ shipped; whether a `live_verified` addition (if scoped)
joins that family or starts a fourth class is an open kickoff question, not resolved here.

**lang/** surface estimate (×24).** Reasoning, not a guess dressed as one: zero new keys for ⑤⑥⑦(2/3)⑨(1/2)
mail copy (already shipped). New surface is bounded by (a) genuinely new mailables — ①, ⑧, and
whichever of ⑦'s `live_verified` gap / ⑨'s onboarding-stalled sub-case get scoped in, each costing
roughly the `contract_attached` block's shape (~5 keys: subject/greeting/body/cta[/feedback_label]) ×
24 ≈ 120 leaves per mailable; (b) new `NotificationType` in-app registrations for whichever of ①/⑦-gap/⑨
get a type (each costs 1 `types.*` leaf + 1 `preferences.typeLabels.*` leaf if toggleable) × 24 ≈ 48
leaves per type; (c) the one missing `preferences.channels.email` leaf × 24 = 24 leaves, plus the
`preferences.description` copy edit. At 3 new mailables + 2-3 new types this lands in the
**~500-650 leaf\*\* range — comparable to or larger than AH-058-c5's own cited "528 leaves (22×24)" for a
five-decision batch (`docs/reviews/adhoc-changes-log.md:1214`) — this chunk touches more distinct
surfaces (mail copy + in-app template + prefs labels) than that one did.

---

## I5 — Flag + operational furniture

**The two existing mail flags' shapes**, both in `apps/api/app/Modules/Creators/Features/`:

- `JobPostedNotificationsEnabled::NAME = 'job_posted_notifications_enabled'` — default-OFF closure
  (`JobPostedNotificationsEnabled.php:58-61`), checked inside `JobPostedFanOutService::send()`
  (`JobPostedFanOutService.php:119`), gates **both** the in-app row and the mail for `campaign.job_posted`
  (unlike the other flag — see docblock note, `JobPostedNotificationsEnabled.php:16-24` — this one is
  the arc's outbound kill switch, not a mail-only gate, because the in-app row and the mail are emitted
  from the same guarded `send()` call, not split like the applications notifier).
- `ApplicationNotificationsEnabled::NAME = 'application_notifications_enabled'` — default-OFF closure
  (`ApplicationNotificationsEnabled.php:83-86`), checked inside `CampaignApplicationNotifier::queue()`
  only (`CampaignApplicationNotifier.php:273-286`) — **mail-only**, in-app writes regardless
  (`ApplicationNotificationsEnabled.php:17-22`, the ruling the kickoff cites).

**Current armed state (evidence, not assumption):** per `docs/runbooks/deploy-log.md:293-295`, both
shipped OFF at the jobs-board arc's deploy. Per the AH-059/c5 investigation
(`docs/reviews/adhoc-changes-log.md:1219-1228`), as of that chunk `application_notifications_enabled`
had **never been armed** (zero `feature_flag.toggled` audit rows for it) while the audit log showed
**two** toggle rows for "the other flag" (`job_posted_notifications_enabled`) — i.e. its current ON/OFF
state is not derivable from the docs alone and should be read back live
(`php artisan tinker --execute="dump(Laravel\Pennant\Feature::active('job_posted_notifications_enabled'))"`,
the `docs/feature-flags.md:106-119` read-back pattern) before kickoff assumes either state.

**Whether this ships under one new flag or ②③④ migrate — the collision, reported honestly:** see the
headline answer above. Concretely, three options exist and none is free:

1. **New flag ADDS on top of the two existing ones** for ②③④ (both must be active to mail) — no
   regression risk, but now three flags gate two of nine mail legs and an operator has to know to arm
   two flags for one type.
2. **②③④ migrate onto the new flag, old flags retired** — clean end state, but is a live behavioural
   change for whatever their current armed state actually is (see above — unverified), and retiring a
   flag an audit trail already references is its own small archaeology problem.
3. **New flag scoped to the preference-READ only** (the mechanical wiring), existing flags stay as the
   mail kill-switches they already are for ②③④, and ⑤⑥⑦⑨'s currently-unconditional mail either stays
   unconditional (preference-gated only, no flag) or gets its own first-ever flag as part of this chunk
   — which is a **separate decision** from "does one new flag replace two old ones."

This document does not pick one; it names the collision the kickoff needs to resolve, per the prompt.

**The enable-ritual + read-back shape to reuse:** `docs/feature-flags.md:56-140`, the jobs-board arc's
combined first-enable ritual — dry-run where a preview command exists, arm from the admin Feature-flags
page with a mandatory reason (`AuditAction::FeatureFlagToggled`, `AuditAction.php:420`, `requiresReason()`
at `AuditAction.php:456-457`), **read the flag back** rather than trusting the click
(`docs/feature-flags.md:106-119` — written specifically because of the AH-059 hour-long false alarm),
watch the structured emission log + queue depth + `failed_jobs`. Any new flag also needs a row in
`AdminFeatureFlagController::FLAGS` (`apps/api/app/Modules/Admin/Http/Controllers/AdminFeatureFlagController.php:55-`)
to be operator-toggleable at all — that allowlist rejects unknown flag names by design.

**Caps — which of the nine can fan out:**

- ② already capped at 50/run, oldest-roster-first, with a `--dry-run` preview command
  (`JobPostedFanOutService::DEFAULT_LIMIT`, `JobPostedFanOutService.php:91`,
  `PreviewJobPostedNotifications` console command).
- ③④ have **no cap** — bounded by human action (one accept/reject at a time), per
  `ApplicationNotificationsEnabled.php:30-35`.
- ① is per-action (one invite, one creator) — no fan-out risk.
- ⑤⑥⑦⑨ are all per-action, single-recipient (one creator, one review/completion/contract event) — no
  fan-out risk.
- ⑧ is the debounced case, bounded by the 30-minute window itself rather than a count — but the
  agency-side direction (creator→agency) fans out to **every notifiable member** of the agency per
  message (`Agency::notifiableMembers()`, same pattern as ③'s `submitted` fan-out, which
  `docs/feature-flags.md:95-99` already flags as "N × (admins+managers)" volume-shaped). If ⑧'s email
  covers that direction too, it inherits the same volume shape ③'s `submitted` mail has, un-capped,
  windowed only by the 30-minute re-arm per (thread, recipient) — worth naming as the one genuinely
  volume-shaped leg among the new nine besides ②.

---

## I6 — Ripple

**Tripwires that fire on any of the nine:**

- `NotificationTypeEnumTest.php` — a hand-maintained hardcoded catalogue
  (`apps/api/tests/Feature/Modules/Notifications/NotificationTypeEnumTest.php:17-65`) that must gain
  every new `NotificationType` case by name, plus the one-vocabulary tie test
  (`…NotificationTypeEnumTest.php:75-81`) that fails if a new case's value isn't also a live
  `AuditAction`.
- **Both FE parity specs** — `apps/main/src/modules/notifications/templates.spec.ts` (backend-enum vs.
  `LIVE_TYPES` diff, `DEFERRED_WITHOUT_EMITTER` allowlist at lines 35-43) and
  `apps/main/tests/unit/architecture/i18n-notifications-parity.spec.ts` (en/24-locale key parity +
  "exactly N live templates + fallback" hardcoded count, currently 19 —
  `i18n-notifications-parity.spec.ts:146-173` — and the role-partition completeness/disjointness specs,
  `…i18n-notifications-parity.spec.ts:232-305`). Both need a hand-edit per new live type, by design (the
  AH-051 lesson these exist to prevent).
- `templates.spec.ts` itself: 18 `it`/`describe` blocks; `i18n-notifications-parity.spec.ts`: 12;
  `NotificationPreferencesPage.spec.ts`: 9 — all three grow by at least one assertion per new type/channel.

**Prefs-page specs + counts:** `NotificationPreferencesPage.spec.ts` already mocks a `defaults` envelope
that includes `email: true` (`apps/main/src/modules/notifications/pages/NotificationPreferencesPage.spec.ts:24`)
— the test scaffold anticipated this before the UI ever rendered it, so the FE test fixture needs no
shape change, only new assertions once `email` toggles actually render.

**§5.2 splits per new emission** (`docs/PROJECT-WORKFLOW.md:192-201`): every new mail-queueing call site
tied to a domain event needs the `Event::fake` split — one test asserting the event dispatches, a second
(without `Event::fake`) asserting the mail/audit consequence actually happened — for each of ①⑧⑨'s new
emit sites (⑤⑥⑦'s existing emit sites already have this; only the new preference-gate branch needs a
new pair: preference-ON sends, preference-OFF doesn't).

**i18n scale estimate:** see §I4 — **~500-650 new/changed leaves** across `lang/*/campaigns.php` (or a
new `lang/*/messaging.php` / `lang/*/contracts.php` block depending on where ①⑧⑨'s mailables land),
`locales/*/notifications.json` (`types` + `preferences.typeLabels` + the one `channels.email` key), each
×24 — the single biggest ripple line item, matching the scale of AH-058-c5's own comparable
"528 leaves (22×24)" citation for a smaller batch.

**E2E exposure:** none found — no Playwright spec under `apps/main/playwright/specs/` currently exercises
the notification-preferences page or a mail-triggering flow's email content directly (confirmed via
glob: zero `*notif*` spec files). The nine emit sites this chunk touches (invite, draft review ×2,
completion ×2-3, contract-attach, application ×2) already have Playwright coverage for their
**in-app**/UI consequence in the existing jobs-board and drafts specs; adding a preference-gated email
leg to an already-covered UI flow doesn't obviously need new E2E, but a debounce-window test (⑧) is not
something Playwright's real-time model covers well and would likely need a backend-only Feature test
with time travel (`Carbon::setTestNow()`), not a browser test.

**Deploy obligations:**

- **Worker restart** — obviously, per the existing convention every new mailable class + new `lang/**`
  copy requires (`docs/feature-flags.md:75-77`, the "un-restarted worker sends a missing-key body"
  lesson).
- **Migration, schema-only (§5.40):** the ⑧ debounce needs a new table (or a new column doesn't fit —
  see §I3) — additive-only, no backfill, no data write in the migration file itself
  (`docs/PROJECT-WORKFLOW.md:483-486`, the absolute schema-only rule). If ⑨ gets its own
  `AuditAction`/`NotificationType` pairing, that's enum-only — no migration. Preference storage
  (`user_notification_preferences`) needs **no schema change** — `channel` is already a
  `NotificationChannel`-cast column that accepts `email` today (`NotificationPreference.php:65-72`).
- Any new Pennant flag needs the `AdminFeatureFlagController::FLAGS` allowlist entry (§I5) — code, not
  schema.

---

## What this document does not do

Per the request: no decisions are made, no plan is written, and nothing in the codebase changed. The
three headline answers above are the load-bearing findings for kickoff; every other section is the
supporting inventory behind them.
