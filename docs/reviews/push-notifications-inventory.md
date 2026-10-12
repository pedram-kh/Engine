# AH-102 — push notifications for the creator app — change inventory

Read-only inventory. No code, config or migration was written or changed. **Plan-pause: no build plan and no
code until this document is reviewed and cleared.**

> **This is a snapshot of the tree at `4d468406`, not a live claim** — the same convention the AH-101
> inventory follows. Line numbers are real at that SHA and will drift. Where something could not be
> measured it says so under **Not verified** rather than asserting it.

**Base SHA:** `4d468406` — `HEAD` and `origin/main` agree (`git rev-list --left-right --count origin/main...HEAD`
returned `0 0`); pushed, **not deployed** (`docs/runbooks/deploy-log.md`). CI on that tip was green (run
`38025689774`, all four jobs `success`).
**Working tree:** clean at the start.
**Paths:** backend module paths are relative to `apps/api/app/Modules/` unless they start with `config/`,
`database/`, `lang/`, `tests/` (relative to `apps/api/`); SPA paths are relative to `apps/main/src/` unless they
start with `apps/`, `packages/` or `docs/` (repo-root-relative).
**Method note:** the two npm plugins this chunk would add (`@capacitor/push-notifications` 8.1.3,
`@capacitor-firebase/messaging` 8.5.2) are **not installed in the repo**. Claims about them come from their
published tarballs (README + `Package.swift` + Android manifest), read on this machine — not from running them.
Claims about FCM/APNs wire behaviour are from memory of the vendor docs and are marked **(vendor docs — verify at
plan time)**; nothing about delivery was exercised.

---

## 0. What the review should know first

1. **The expected Laravel packages are not in the lock.** `composer.lock` has no `kreait/firebase-php`, no
   `laravel-notification-channels/fcm`, no `google/auth`, no `firebase/php-jwt`. Present: `guzzlehttp/guzzle`,
   `laravel/horizon`, `predis/predis`, `laravel/sanctum`, `laravel/pennant`. `laravel-notification-channels/fcm`
   could not work anyway: it rides Laravel's `Notification`/`Notifiable` system, and `Notifiable` was
   **deliberately removed** from `User` (`Identity/Models/User.php:71-77`). Recommendation in §3.2: a thin custom
   FCM v1 client.
2. **"FCM fronting APNs for both platforms" is not free on the client.** The official
   `@capacitor/push-notifications` returns the **raw APNs token on iOS** ("On iOS it contains the APNS token. On
   Android it contains the FCM token" — plugin README) and its iOS `Package.swift` has no Firebase dependency. FCM
   v1 cannot address an APNs hex token. To send iOS through FCM the app needs a different plugin
   (`@capacitor-firebase/messaging`, which README says must not coexist with another push plugin). This is the
   largest fork in the chunk — **Q1**.
3. **Push cannot be "one line in `notify()`".** Three traps are in the existing code: `notify()` returns early
   when the in-app preference is off (`Notifications/Services/NotificationService.php:48-50`), so a push leg placed
   after that line would silently die for anyone who muted in-app; `notify()` is called **inside** the
   assignment state machine's DB transaction (§1.1); and the draft-approval/completion pair double-fires (§1.5).
4. **Push needs server-side localized copy.** In-app text is rendered client-side from the SPA's 24 locale files
   (`modules/notifications/components/NotificationCenter.vue:68-74`); the API cannot reuse them. A push title/body
   has to be built on the backend: new `lang/*/push.php` ×24. This is the largest hidden cost — **Q7**.
5. **The AH-101 reservation mostly holds, with one correction.** Adding the channel really is a one-entry change in
   `PREFERENCE_CHANNEL_ORDER`, but "no page change" does not hold if push is to be hidden on web (§1.3), and the
   `defaults` block / PATCH validation / api-client type union all change the moment the enum gains a case.
6. **A push token needs a _live-login_ eligibility filter, not just existence.** Sanctum's `expires_at` column is
   unset for these tokens; expiry is computed from `created_at` + `SANCTUM_TOKEN_EXPIRATION_MINUTES` at use time,
   and expired rows are never deleted (§2.3). A token row that merely "exists" would keep pushing to a device
   whose login lapsed.
7. **R2 — yes, `creator-detail.spec.ts:64` flaked in both runs (retry-then-pass).** It is at/over the threshold
   (§6.2).
8. **R1 — the warning is real and the one-line fix is known; the claimed suite-collection abort did not
   reproduce** (§6.1).

---

## 1. Backend pipeline — the seam push plugs into

### 1.1 How `notify()` dispatches today

`NotificationService::notify()` (`Notifications/Services/NotificationService.php:41-60`) is the single in-app emit
seam. Its entire behaviour:

- `:48-50` — if `isChannelEnabled($recipient, $type, NotificationChannel::InApp)` is false, **return `null`**
  (nothing written);
- otherwise create one `Notification` row (ulid id, `notification_type`, `data`, polymorphic subject).

There is no fan-out, no queue, no event. Email is **not** dispatched by `notify()`: every mail site calls a
guard itself — `isEmailEnabled()` (`:118-121`) which wraps `isChannelEnabled()` (`:129-143`) — and queues its own
mailable (e.g. the `queueMail` helper `Campaigns/Listeners/SendAssignmentNotifications.php:387-396`, flag read `:370`). AH-101 pinned
that with an architecture test. So "the pipeline" is: the emit sites in §1.4 → `notify()` (in-app) + per-site mail guard.

**Where the emit sites run (this decides the job design):**

| Fact                                                                                                         | Evidence                                                                                                    |
| ------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------- |
| `SendAssignmentNotifications` is a _synchronous_ listener on the state-machine event                         | `Campaigns/CampaignsServiceProvider.php:53`                                                                 |
| …which is dispatched **inside** `DB::transaction`                                                            | `Campaigns/Services/CampaignAssignmentStateMachine.php:653` (`DB::transaction`), event dispatched at `:668` |
| The repo already names this as a hazard, trigger = "the next chunk touching the state-machine emission path" | `docs/tech-debt.md:2465-2494` (its own `:612-637` line refs are stale)                                      |
| Queue connections all have `after_commit => false`                                                           | `config/queue.php:48,57,68,77`                                                                              |
| Tests run queues synchronously                                                                               | `phpunit.xml:63` (`QUEUE_CONNECTION=sync`)                                                                  |

Consequence: a push job dispatched from inside that transaction could run before commit and push a state that
then rolls back. The job class should set `public bool $afterCommit = true` (per-job; no config change), and the
push leg must be wrapped in `try/catch` so a push failure can never abort the transaction that `notify()` sits in.
Test design note: with the `sync` driver under transaction-wrapped tests, `afterCommit` jobs need `Queue::fake()`
or equivalent — a plan-time detail, flagged here so it is not discovered mid-build.

### 1.2 Where `NotificationChannel` lives and what a `Push` case touches

`Notifications/Enums/NotificationChannel.php:21-23` — three cases: `InApp='in_app'`, `Email='email'`,
`Digest='digest'`; `defaultEnabled()` (`:28-34`) is `true` for InApp/Email, `false` for Digest. It is **per
channel**, not per type.

Adding `case Push = 'push'` has these automatic consequences (all verified by reading the consumers):

| Consumer                | Effect of the new case                                                                                                                           | Evidence                                                                                                            |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------- |
| PATCH validation        | `Rule::enum(NotificationChannel::class)` — `push` **starts being accepted** the instant the case exists; today it 422s                           | `Notifications/Http/Requests/UpdateNotificationPreferencesRequest.php:42`                                           |
| `defaults` block in GET | loops `NotificationChannel::cases()` → `push` appears in `defaults`                                                                              | `Notifications/Http/Controllers/NotificationPreferenceController.php:88-97`                                         |
| `setPreference()`       | deletes the row when the value equals `defaultEnabled()` (sparse storage)                                                                        | `NotificationService.php:79-100`                                                                                    |
| DB column               | `channel` is `string(16)`, unique `(user_id,type,channel)`, **no CHECK** — `'push'` fits, no migration needed for preferences                    | `Notifications` preferences migration (AH-101 inventory §N3)                                                        |
| api-client type union   | `'in_app' \| 'email' \| 'digest'` → add `'push'`; `defaults: Record<NotificationChannel, boolean>` then _requires_ `push`                        | `packages/api-client/src/types/notification.ts:167,192`                                                             |
| SPA test fixtures       | `DEFAULTS = {in_app, email, digest}` literal; `preferenceColumns(...)` equality for messaging                                                    | `modules/notifications/pages/NotificationPreferencesPage.spec.ts:32`; `modules/notifications/templates.spec.ts:340` |
| Backend test pins       | defaults block asserts three keys                                                                                                                | `tests/Feature/Modules/Notifications/MeNotificationPreferencesTest.php:36-38`; `NotificationServiceTest.php:95`     |
| Column label            | `channels.push` key needed in the **24** locale `notifications.json` files (`channels` block at `core/i18n/locales/en/notifications.json:58-61`) | —                                                                                                                   |

**Deploy-order hazard (new, worth stating).** `NotificationPreferencesPage.vue` PATCHes the _whole visible set_
(`:167` `is_enabled: enabled[stateKey(type, channel)] ?? true`). If an SPA that renders a push column ships before
the API accepts `push`, **every save 422s — for all channels**, not just push. Backend first, SPA second, in that
order. The reverse skew (new backend, old app/SPA) is harmless: old clients never send push rows and ignore the
extra `defaults.push` key.

**No stored `push` rows can pre-exist.** Validation rejects `push` today (`UpdateNotificationPreferencesRequest.php:42`),
so no UI/API path could have written one. Belt-and-braces read before the build deploys:
`SELECT count(*) FROM user_notification_preferences WHERE channel='push'` — expected `0`.

### 1.3 How `PREFERENCE_CHANNEL_ORDER` and `LIVE_TYPES` are consumed (the AH-101 reservation)

- `modules/notifications/templates.ts:115-119` `PREFERENCE_CHANNEL_ORDER` — the render order of matrix columns.
  `:125` onward `LIVE_TYPES` — the registry; each entry declares `channels` (what the type _may_ send), a
  `preference` block, and `recipient`. `:332` `inColumnOrder()` sorts a type's channels by the order array;
  `:366` `preferenceGroupsForRole()`; `:403` `preferenceColumns()` derives a group's columns from its types.
  `alwaysOnChannels` produces the "always on" caption; types with `preference: null` never appear in the matrix.
- `modules/notifications/pages/NotificationPreferencesPage.vue`: `:76` `columnsFor(group)` (called per group),
  `:83` one `minmax(88px, auto)` grid column per channel (inside the 640px card), `:141`
  `enabled[key] = overrides.get(key) ?? defaults[channel]` — the display state is "stored row, else the backend's
  default for that channel".

**What a new column needs:** (a) enum case; (b) `'push'` in `PREFERENCE_CHANNEL_ORDER`; (c) `'push'` added to each
_eligible_ type's `channels` array in `LIVE_TYPES` (so only those types grow a push cell — the column derivation
is automatic); (d) `channels.push` label ×24; (e) api-client union; (f) tests above.

**The correction to AH-101's claim.** The matrix will render a push column for creator groups on **web** too, where
there is no push surface (a "dead control" — `docs/reviews/creator-app-inventory.md` M7.3). Hiding it on web is not
a registry change; it needs a page/`preferenceColumns` change (native-only filter via `isNativePlatform()`),
which is **not** "no page change". Options and recommendation in **Q8**. The agency preferences UI is unaffected
(agency-only types never get a push channel).

**Missing-row = ON, does it apply to push?** Mechanically yes — `isChannelEnabled()` returns
`$channel->defaultEnabled()` when there is no row (`NotificationService.php:138`). Recommendation: `Push` default
**ON**, because the consent gate for push is _"the user installed the app, granted the OS permission, and a live
device token exists"_; a second default-OFF gate would mean a user who just tapped "Allow" still gets nothing.
**This is a lock-in decision:** `setPreference()`'s docblock (`:62-77`) warns that flipping a default later
silently overrides every stored/absent state. Decide it once, before the build — **Q3**.

### 1.4 Push eligibility per live type (yes/no with reasons)

27 `NotificationType` cases; 21 are in `LIVE_TYPES`; 6 are not live (declined, countered, accepted, cancelled,
payment_funded, payment_released — no emit site). Of the 21 live: **16 reach a creator** (15 creator-recipient +
`agency_creator_relation.disconnected`, recipient `both`) and **5 are agency-only**. Registry lines are in
`modules/notifications/templates.ts`; emit sites in the right column.

**Creator-reaching types (16):**

| Type (`templates.ts` line)                                               | Push?                      | Reason                                                                                                                                                                                                                                                                                                                              | Emit site                                                                                                       |
| ------------------------------------------------------------------------ | -------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| `assignment.invited` (132)                                               | **YES**                    | The core "an agency wants you" moment; low volume, human-initiated                                                                                                                                                                                                                                                                  | `SendAssignmentNotifications.php:333-374` (mail leg behind `MissingCreatorMailsEnabled`)                        |
| `assignment.revision_requested` (149)                                    | **YES**                    | Creator is blocked on it                                                                                                                                                                                                                                                                                                            | `SendAssignmentNotifications.php:448-475` `notifyCreatorOfReview` (type chosen `:515`)                          |
| `assignment.draft_approved` (154)                                        | **YES, with suppression**  | Mirror AH-069: when the approval also completes the assignment, send **one** push, not two                                                                                                                                                                                                                                          | `SendAssignmentNotifications.php:448-475` (`suppressEmail` param `:448`, passed at `:115`, applied `:475`)      |
| `assignment.draft_rejected` (159)                                        | **YES**                    | Same as revision; creator is blocked                                                                                                                                                                                                                                                                                                | `SendAssignmentNotifications.php:448-475`                                                                       |
| `assignment.completed_on_approval` (169)                                 | **YES**                    | Completion/payment-relevant                                                                                                                                                                                                                                                                                                         | `SendAssignmentNotifications.php:155-163`                                                                       |
| `assignment.manually_verified` (174)                                     | **YES**                    | A human verified the post                                                                                                                                                                                                                                                                                                           | `SendAssignmentNotifications.php:227-237`                                                                       |
| `assignment.live_verified` (184)                                         | **NO (v1)**                | Machine verification; the email leg is itself gated behind `PostVerifiedMailEnabled` (`:276`) because of mock-verification risk; reversible later                                                                                                                                                                                   | `SendAssignmentNotifications.php:277-286`                                                                       |
| `creator.approved` (189)                                                 | **YES — highest value**    | The one moment the dashboard currently _polls_ for (`modules/creators/pages/CreatorDashboardPage.vue` pending-review poll)                                                                                                                                                                                                          | `Creators/Http/Controllers/Admin/AdminCreatorController.php:279-290` (approve), `notify` helper `:510`          |
| `creator.rejected` (194)                                                 | **YES, neutral copy**      | Must reach the creator, but lock-screen text must not state the outcome's reason                                                                                                                                                                                                                                                    | same file, reject path (helper `:510`)                                                                          |
| `message.received_by_creator` (207)                                      | **YES, own throttle**      | Real-time channel; see §1.5                                                                                                                                                                                                                                                                                                         | `Messaging/Services/SendMessageNotifications.php:80`                                                            |
| `message.relationship_received_by_creator` (224)                         | **YES, own throttle**      | Same; **payload caveat:** `data` carries `thread_ulid`, not `agency_ulid` (blocks AH-103 deep link, not AH-102)                                                                                                                                                                                                                     | `Messaging/Services/RelationshipMessageNotifications.php:88`                                                    |
| `agency_creator_relation.admin_connected` (241, `preference: null`)      | **YES**                    | Consequential relationship change; always-on, no toggle (no matrix row to add)                                                                                                                                                                                                                                                      | `Creators/Http/Controllers/Admin/AdminCreatorConnectionController.php:272`                                      |
| `agency_creator_relation.disconnected` (246, `both`, `preference: null`) | **YES — creator leg only** | Same; agencies have no tokens so only the creator leg ever pushes                                                                                                                                                                                                                                                                   | `Admin/AdminCreatorConnectionController.php:304`; `Creators/Services/CreatorAgencyDirectConnectService.php:211` |
| `campaign.job_posted` (271)                                              | **NO**                     | Promotional bulk fan-out (`DEFAULT_LIMIT = 50` per run — `Campaigns/Services/JobPostedFanOutService.php:91`), in-app emission itself flag-gated (`:119`). Promotional push generally needs explicit opt-in (Apple App Store guideline 4.5.4 — **from memory, verify**). Default-ON-per-channel cannot be overridden per type (§1.2) | `JobPostedFanOutService.php:251`                                                                                |
| `campaign_application.accepted` (281)                                    | **YES**                    | Good news the creator is waiting for                                                                                                                                                                                                                                                                                                | `Campaigns/Services/CampaignApplicationNotifier.php:110/168/227`                                                |
| `campaign_application.rejected` (292)                                    | **NO (v1)**                | Auto-reject bursts (`AutoRejectPendingApplicationsJob`) and low urgency. **Reversible — confirm with Pedram (Q2).** The earlier inventory had it in its "nine"                                                                                                                                                                      | same notifier                                                                                                   |

Totals: **YES 13 · NO 3** (live_verified, job_posted, application.rejected).

**Agency-only types (5) — all NO**, with one reason: there is no agency app and therefore no agency device token
anywhere in the model: `assignment.draft_submitted` (138), `assignment.contracted` (143),
`message.received_by_agency` (212), `message.relationship_received_by_agency` (229),
`campaign_application.submitted` (276). (Agency matrix groups get **no** push column.)

**Not a type at all:** the contract-attached email (`ContractAttachedMail`) has no `NotificationType`
(`docs/reviews/creator-app-inventory.md`, confirmed there), so it has no push path and none is proposed.

**Parity guard to build with it:** an FE-registry ⇔ backend `pushEligible()` test, analogous to AH-101's
`EmailPreferenceGuardTest` / architecture test, so a type cannot declare `push` in the SPA without the backend
agreeing (and vice versa).

### 1.5 Debounce — does push share AH-083's 30-minute email debounce?

**No. Push needs its own mechanism; it must not call into the email debounce.**

- The email debounce is `Messaging/Services/DebouncedMessageMailer.php`: `DEBOUNCE_MINUTES = 30` (`:59`), one
  atomic upsert per (thread, recipient) into `message_email_debounces`
  (`database/migrations/2026_08_19_100000_create_message_email_debounces_table.php:47`), `shouldEmail()` at
  `:126`, **wholly behind `missing_creator_mails_enabled`**.
- Reusing it would (1) consume the email window as a side effect, (2) give a real-time channel a 30-minute
  blackout, and (3) hang push on the **email** kill switch.
- Proposal: a short per-(thread, recipient) cache throttle (e.g. `Cache::add` with a ~60 s TTL) **plus** a collapse
  key per thread (`apns-collapse-id`/`thread-id` on iOS, `android.collapse_key`/`tag` on Android) so repeats
  replace rather than stack. Window length is **Q4**.
- Everything else is non-bursty (single recipient, human- or admin-gated). Two exceptions to design for:
  `draft_approved` + `completed_on_approval` firing as a pair (suppress one — **Q5**), and a creator invited to
  several campaigns in a row (accept; optionally a per-user hourly cap as a backstop — **Q10**).

### 1.6 Pennant flag proposal

- **`push_notifications_enabled`, default OFF.** Class `Creators/Features/PushNotificationsEnabled.php` (`NAME`
  const + `default()` returning a Closure that returns `false`), copying `PostVerifiedMailEnabled.php`.
- Registration: `Creators/CreatorsServiceProvider.php` next to `:263-266` (`Feature::define(...)`); admin list
  `Admin/Http/Controllers/AdminFeatureFlagController.php` (one map entry per flag keyed by `NAME`, e.g. `:79`); registry doc `docs/feature-flags.md`
  (rows carry a vendor/arming column — cf. `post_verified_mail_enabled`, `:59`; this flag's entry would read "arm
  only after FCM credentials are present and a `validate_only` smoke test passes");
  `tests/Feature/Modules/Admin/AdminFeatureFlagTest.php:70` asserts flag names and needs the new one.
- **What it gates:** _sending only._ Token registration is not gated (otherwise the app would 409 during the
  window between app release and arming).
- **Arming order and the real containment.** The structural blast radius is the **token population**, which is
  zero until the first push-capable store build is installed. Recommendation: arm the flag _before_ that build
  reaches users; the app then never needs to read the flag, and first-enable is a no-op for everyone who has no
  token. (Alternatively the app needs a "push is live" read before it shows the permission prompt — more surface;
  **Q12**.)
- Flag is global (existing flags are checked as `Feature::active(NAME)` — e.g. `SendAssignmentNotifications.php:370`);
  I did not verify Pennant scope resolution for per-user canarying — not needed for this proposal.

### 1.7 Where the push leg hooks in

Inside `NotificationService::notify()`, **before** the in-app early return (`:48`), as a call that is independent
of whether the in-app row is written. Order of checks, cheapest first so agency types cost zero queries:
(1) static `pushEligible($type)` → (2) `Feature::active(PushNotificationsEnabled::NAME)` → (3) push preference
(`isChannelEnabled(..., Push)`) → (4) live tokens for the recipient → dispatch one job per token.
`notify()` lacks the `completes_on_approval` context (§1.5 / Q5), so the suppression is either an explicit
argument or site-level handling in `SendAssignmentNotifications`. This is the hot path for **every** notification
in production — see §7 risk.

### 1.8 Push copy and i18n (the hidden cost)

In-app rows store only `notification_type` + `data` (`Notifications/Http/Resources/NotificationResource.php`);
the SPA turns them into text from its locale JSON. The API has no equivalent, and a push _must_ carry rendered
text. Proposal: `apps/api/lang/{locale}/push.php` ×24, keyed by type, locale from `$user->preferred_language ?: 'en'`
(`Identity/Models/User.php:35,88`). Volume: 13 types × (title + body) ≈ 26 strings ×24, or ≈ 13 ×24 if the title
is a fixed app name (**Q7**). The repo's real-MT discipline applies, including the "flaky-10" locale handling
(bg, el, et, fi, ga, hu, lt, lv, mt, ro). Privacy default: **no message text previews** and no rejection reasons on
the lock screen (**Q6**). No server-side badge counts (they get stuck). Payload: a `notification` block plus
data `{notification_ulid, type}`; no data-only messages (they are not delivered to JS when the app is killed on
Android and do not exist on iOS — plugin README).

---

## 2. Device token model

### 2.1 PAT row vs its own table

`personal_access_tokens` (Sanctum, vendor-owned): `id`, morphs `tokenable`, `name`, `token(64)` unique,
`abilities`, `last_used_at`, `expires_at`, timestamps. The creator-app token name is `creator-app:{ios|android}`
(`Identity/Services/AuthService.php:82`, issued `:220-252`). **Recommendation: its own table**, referencing the PAT.
Reasons, each grounded:

- the table is vendor-owned and has no column for a vendor token or rotation;
- an FCM token rotates independently of the Sanctum token (re-registration is routine);
- AH-102b's delivery tracking needs an entity to hang outcomes off (§3.5);
- Sanctum rows accumulate per login and expired ones are never pruned (§2.3).

**Proposed shape (`user_push_tokens`; the earlier inventory called it `push_devices` — name to be reconciled):**

| Column                                  | Notes                                                                                   |
| --------------------------------------- | --------------------------------------------------------------------------------------- |
| `id`, `user_id`                         | FK `users`, cascade                                                                     |
| `personal_access_token_id`              | FK `personal_access_tokens`, **cascadeOnDelete, NOT NULL, unique**                      |
| `platform`                              | `ios`/`android` — same value the login already receives (`TokenLoginRequest.php:35,59`) |
| `token`                                 | string ≤512, **unique**                                                                 |
| `app_version`                           | nullable                                                                                |
| `last_seen_at`                          | refreshed on registration                                                               |
| `invalidated_at`, `invalidation_reason` | the "dead token" mark (§3.5)                                                            |

`unique(personal_access_token_id)` means **one push token per device login**: an FCM rotation is a _replace_, with
no `previous_token` bookkeeping; multi-device is simply multiple PATs. `unique(token)` + upsert-by-token moves
ownership when a phone is shared between accounts (token possession is the proof; worst case an attacker who knew
a victim's token could un-assign it — low, noted in §7).

### 2.2 Registration endpoint (bearer-guard only)

`POST /api/v1/me/push-tokens` (upsert) and `DELETE /api/v1/me/push-tokens`, middleware **`auth:creator_app`
alone** (not `auth:web,creator_app`, which is today's route pattern — e.g. `Notifications/Routes/api.php:31-51`)
so cookie sessions get 401, plus `tenancy.set`, plus a throttle (cf. `Identity/Routes/api.php:79-99`). Consequences:

- `docs/security/tenancy.md:113` allowlist (the `/me/notification…` rows sit at `:189-193`) needs a new row;
- `tests/Feature/Modules/Identity/CreatorAppGuardSwapTest.php` route-inspection pins (regex list; intended-set test `:88-104`) must be extended so the new URI is pinned as bearer-only, not accidentally swapped;
- AH-099's wrong-user-type protection (guard refuses non-creators at use time) applies unchanged.

### 2.3 Lifecycle — rotation, revocation, expiry (the filter)

| Event                          | Mechanism                                                                                                                                                                         | Evidence                                                       |
| ------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------- |
| FCM token rotates              | client POSTs new token; `unique(personal_access_token_id)` replaces the old                                                                                                       | §2.1                                                           |
| Logout (token door)            | `logoutToken` deletes the current PAT → **DB cascade** removes the push row                                                                                                       | `AuthService.php:458-461`                                      |
| Password reset / lockout       | `$user->tokens()->delete()` (mass delete, no model events) → cascade is a **DB-level** FK, so it still fires                                                                      | `PasswordResetService.php:113`; `AccountLockoutService.php:90` |
| 90-day expiry                  | `expires_at` is **null**; expiry = `created_at + sanctum.expiration` (`config/sanctum.php:75`, 129 600 min), computed in `TokenLoginController.php:42-45`; **row is not deleted** | `docs/tech-debt.md:2801` ("No pruning of expired tokens")      |
| Account deletion (AH-104 seam) | `User` uses `SoftDeletes` (`User.php:69`): soft-delete does **not** fire the FK cascade                                                                                           | —                                                              |

**Therefore the send-time eligibility query must filter on all three:** `invalidated_at IS NULL`; the joined PAT
`created_at > now() - sanctum.expiration` (mirror the use-time rule rather than the unset `expires_at`); and the
owning user not soft-deleted. Without the middle filter a signed-out-by-expiry device keeps receiving lock-screen
pushes.

**Residual gap, stated plainly.** In the app, device sign-out is authoritative and best-effort: if the revoke
request never arrives, the server token "lapse[s] at its `expires_at`"
(`modules/auth/stores/useAuthStore.ts:365-380`, comment `:362-367`). A signed-out device whose revoke failed
offline would keep receiving pushes until the PAT expires (up to 90 days) or another account registers the same
token. Mitigation in the build: `nativeLogout()` calls `DELETE /me/push-tokens` and `PushNotifications.unregister()`
_before_ clearing the token (best-effort). The offline-failure residue remains — **§7 risk R-4**.

**AH-104 (account deletion) seam — named, not built:** `PushTokenRegistry::revokeAllForUser(User $user): int`
(single method AH-104's erasure orchestrator calls), plus the `user_id` FK cascade for hard deletes. The
earlier inventory's erase-set already lists the table (`docs/reviews/creator-app-inventory.md` M5, as
`push_devices`).

**Optional hygiene:** cap ≈10 live tokens per user (oldest dropped); `last_seen_at` refreshed from the client on
launch/resume at most daily (`core/platform/appResume.ts` throttle precedent, 5 s there).

---

## 3. Delivery mechanics

### 3.1 FCM HTTP v1 fronting APNs vs native APNs + FCM

|                         | **A. FCM v1 for both**                                                                                                                                                                                                                                                          | **B. APNs direct (iOS) + FCM v1 (Android)**                                                                                                                                                 |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| iOS client plugin       | `@capacitor-firebase/messaging` 8.5.2 (SPM-capable; firebase-ios-sdk `from 12.7.0`; needs Capacitor CLI ≥ 8.4.0 — repo has 8.5.2 — and `experimental.ios.spm.packageOptions` with `"@capacitor-firebase/messaging": {"symlink": true}`; README: no other push plugin installed) | official `@capacitor/push-notifications` 8.1.3 as-is (no Firebase in `Package.swift`)                                                                                                       |
| iOS AppDelegate         | 3 methods (the 2 register callbacks + `didReceiveRemoteNotification`)                                                                                                                                                                                                           | 2 methods posting `.capacitorDidRegisterForRemoteNotifications` / `…FailToRegister…`                                                                                                        |
| iOS binary              | adds Firebase SDK (Google)                                                                                                                                                                                                                                                      | no third-party SDK                                                                                                                                                                          |
| Server senders          | **one** (FCM v1)                                                                                                                                                                                                                                                                | **two** (FCM v1 + APNs HTTP/2 with ES256 JWT)                                                                                                                                               |
| Token semantic          | one FCM registration token, both platforms                                                                                                                                                                                                                                      | iOS = APNs hex token, Android = FCM token                                                                                                                                                   |
| APNs sandbox/production | handled by the Firebase SDK (**vendor docs — verify**)                                                                                                                                                                                                                          | **ours**: debug/Xcode-installed builds issue _sandbox_ tokens, TestFlight/App Store issue _production_ tokens; sending to the wrong host is `BadDeviceToken`. Needs an `environment` column |
| Credentials             | Firebase service account (server) + APNs key uploaded to Firebase                                                                                                                                                                                                               | service account (Android) + `.p8` + Key ID + Team ID + bundle topic (server)                                                                                                                |
| Error taxonomy          | one                                                                                                                                                                                                                                                                             | two                                                                                                                                                                                         |

**Recommendation: A** — one sender, one credential, one error vocabulary, and it removes the classic
"works from Xcode, silent in TestFlight" failure. **Cost and caveat:** everything about Firebase-on-iOS-under-SPM
is **unverified in this repo** (`apps/main/ios/App/CapApp-SPM/Package.swift` currently lists only
`AparajitaCapacitorSecureStorage` and `CapacitorApp`). So the plan should open with a **time-boxed spike**
that proves `getToken()` returns a usable FCM token on a real iPhone before anything else is built on it. If the
spike fails, B is the fallback and the table gains an `environment` column. Decision: **Q1**.

Android is unaffected by the choice: it needs only `google-services.json` and (official plugin) no Firebase SDK in
the app.

### 3.2 Laravel packages: lock vs thin custom channel

Evidence: `composer.lock`/`composer.json` contain none of the Firebase candidates (§0.1). Options:

1. **Thin custom FCM v1 client (recommended).** `Http` facade (testable with `Http::fake`; precedent for a small
   outbound client: `Identity/Services/PwnedPasswordsClient.php:40`), an RS256 service-account JWT via
   `openssl_sign` (PHP `openssl` and `sodium` are present), and the OAuth access token cached through `Cache`.
   Surface: one endpoint (`POST https://fcm.googleapis.com/v1/projects/{id}/messages:send`) plus one token
   exchange. Zero new composer dependencies — relevant because the deploy log shows `composer install` on the live
   box is a sensitive step. Cost: we own token refresh, error mapping and a JWT unit test (~150–200 lines).
2. `kreait/firebase-php` (or `kreait/laravel-firebase`): maintained SDK, but new dependency chain (google/auth,
   php-jwt, …) and more surface than we use.
3. `laravel-notification-channels/fcm`: **rejected** — depends on the `Notification`/`Notifiable` system removed
   from `User` (`User.php:71-77`).

Decision: **Q1b**.

### 3.3 Queue behaviour

- Production worker: `php artisan queue:work --memory=768 --tries=3 --max-time=3600`, **default queue only**, no
  `onQueue` anywhere (`docs/runbooks/production-queue-worker.md:14,30,58`). A new queue name would never be
  consumed — **use `default`**. (`.env.example` has `QUEUE_CONNECTION=redis`; `laravel/horizon` is installed but
  the runbook uses `queue:work` — confirm in **Q13**.)
- One job per device token (`SendPushNotificationJob`), `ShouldQueue`, `$afterCommit = true` (§1.1), explicit
  `$tries`/`$backoff`/`$timeout` and a `failed()` hook — house pattern:
  `Creators/Jobs/ProcessPortfolioImageJob.php:77,84,90,156`. Short HTTP timeout (a hung FCM call must not hold a
  worker for the `retry_after` of 300 s — `config/queue.php:75`).
- **Deploy obligation:** a new job class requires a worker restart (the runbook's rule), i.e. `queue:restart`.
- The email path is the contrast: mail is queued by its mailables (`CreatorApprovedMail` etc. implement
  `ShouldQueue`); push must follow the same "never block the request" rule, and never call FCM inline.

### 3.4 Failure handling — invalid/expired token (vendor docs — verify at plan time)

| FCM v1 response                               | Action                                                                                                          |
| --------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| `UNREGISTERED` (404)                          | token dead → set `invalidated_at` + reason; **no retry**                                                        |
| `INVALID_ARGUMENT` (400)                      | ambiguous token-vs-payload → invalidate only if the message names the token; otherwise log + fail (payload bug) |
| `SENDER_ID_MISMATCH` (403)                    | config alarm (wrong project), **not** a token kill                                                              |
| `THIRD_PARTY_AUTH_ERROR` (401)                | config alarm (APNs key in Firebase)                                                                             |
| `QUOTA_EXCEEDED` / `UNAVAILABLE` / `INTERNAL` | retry with backoff, then `failed()`                                                                             |

A dead token is excluded from the next send by the §2.3 filter, so nothing retries forever.

### 3.5 Where delivery outcomes would be recorded (the AH-102b seam)

A **single call site** inside the job: `PushDeliveryRecorder::record(PushDeliveryOutcome $o)`. In AH-102 it does
exactly two things — set `invalidated_at` on a dead token, and emit a structured `Log::info` (precedent:
`DebouncedMessageMailer::logEmission()`). AH-102b swaps its body for an insert into a future `push_deliveries`
table (token id, notification ulid, outcome, FCM error code, attempted_at). No schema for 102b is created now.

---

## 4. App side (Capacitor)

**State today:** `apps/main/capacitor.config.ts` appId `com.catalystgrowth.platform`; Capacitor 8.5.2 with SPM on
iOS; no push plugin installed.

### 4.1 Plugin behaviour (from the published tarballs)

`@capacitor/push-notifications` 8.1.3 — listeners `registration`, `registrationError`,
`pushNotificationReceived`, `pushNotificationActionPerformed`; `checkPermissions()`/`requestPermissions()` (required
on Android 13+); `register()`. No iOS silent push. iOS default `presentationOptions` is `['badge']` (no foreground
banner unless configured). If option A, the equivalent surface comes from `@capacitor-firebase/messaging`
(`getToken()`, `notificationActionPerformed`, `FirebaseMessagingAutoInitEnabled` can be disabled).

### 4.2 Native changes the build chunk will need

| Platform       | Change                                                                                                                  | Evidence of current state                                                                                                                                                                                     |
| -------------- | ----------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| iOS            | Add Push Notifications capability → creates `App.entitlements` with `aps-environment` and sets `CODE_SIGN_ENTITLEMENTS` | none today: no entitlements file, no `CODE_SIGN_ENTITLEMENTS` (`apps/main/ios/App/App.xcodeproj/project.pbxproj`: `CODE_SIGN_STYLE = Automatic` at `:302,324`, no `DEVELOPMENT_TEAM`, deployment target 15.0) |
| iOS            | AppDelegate push callbacks                                                                                              | `apps/main/ios/App/App/AppDelegate.swift` has none                                                                                                                                                            |
| iOS (option A) | `GoogleService-Info.plist` in the App target; SPM `packageOptions` symlink in `capacitor.config.ts`                     | absent                                                                                                                                                                                                        |
| iOS            | `UIBackgroundModes: remote-notification` is **not** needed (no silent push)                                             | `Info.plist` has none                                                                                                                                                                                         |
| Android        | `google-services.json` in `android/app/` — the plugin is already wired _conditionally_ on that file                     | `android/build.gradle:11` (`google-services:4.4.4`); `android/app/build.gradle:48-53`; file absent                                                                                                            |
| Android        | declare `POST_NOTIFICATIONS` (targetSdk 36 → runtime permission)                                                        | `AndroidManifest.xml:40` has only `INTERNET`; plugin manifest declares only `MessagingService` (verify the merged manifest at build)                                                                          |
| Android        | push small icon (white-on-transparent) + default-icon meta-data                                                         | none today                                                                                                                                                                                                    |
| Both           | gitignores do **not** exclude `google-services.json`/`GoogleService-Info.plist` (**Q14**)                               | `apps/main/{ios,android}/.gitignore`                                                                                                                                                                          |

Any native change ships through a store/TestFlight release, not a web deploy — and the backend must be live first
(§1.2 deploy order, §5).

### 4.3 Permission-prompt timing

Not at cold launch, not on the sign-in screen. Recommendation: a **soft-ask sheet** (our own UI, native only) the
first time a creator lands on the dashboard in the `pending` state — the moment push has unique value ("we'll tell
you when you're approved"; the dashboard currently polls instead: `CreatorDashboardPage.vue` pending banner
`:271`, native-only poll `:201-218`). Only if they accept the soft-ask do we trigger the OS prompt. Second entry
point: a push row on the notification-preferences page (also the only path after an OS-level deny, which becomes
a "open Settings" action). The iOS OS prompt is one-shot; Android 13+ stops showing after repeated denials, so a
declined soft-ask is not re-shown for a cooling-off period (value tbd at plan time).

### 4.4 Minimal tap → navigation, and the AH-103 boundary

**AH-102 does exactly this:** `pushNotificationActionPerformed` (registered at boot next to
`startAppResumeRefresh`, `apps/main/src/main.ts:34-40`, injectable-bridge pattern like `core/platform/appResume.ts`)
→ `router.push({ name: 'creator.notifications' })` (route exists, `modules/creators/routes.ts:109-123`). A
foreground `pushNotificationReceived` triggers a bell-count refresh. Signed-out/locked taps fall through the
existing auth guard.

**AH-102 does _not_ do (all AH-103):** a `(type, data, subject) → route` resolver; universal/app links; per-type
destinations; marking-as-read on tap beyond what the list already does; biometric-lock interplay. Evidence that the
resolver does not exist: `NotificationCenter.vue:19-20` ("D-7 deferred"), `onRowClick` `:138` — rows do not
navigate today. The push payload carries `notification_ulid` + `type` so AH-103 needs **no** backend payload
migration; unknown fields are ignored by old app builds.

> **Correction, 2026-10-12 (AH-103).** The "needs **no** backend payload migration" statement above was true for the four always-on
> types only and is **reversed**: AH-103 chose Option B (plan Q1) and added additive, ULID-only **route keys** to the push payload on a
> per-type allowlist in `PushMessageFactory`, plus `campaign_ulid` on the `campaign.job_posted` in-app bag. `v` stays `1`; old builds
> still ignore unknown fields. The "resolver does not exist" and "rows do not navigate today" statements are also superseded: both now
> go through `resolveNotificationDestination`. See [`deep-links-review.md`](deep-links-review.md) and
> [`deep-links-plan.md`](deep-links-plan.md) §3.2. The original text is left unedited above for the record.

---

## 5. Prerequisites Pedram must do by hand

**Blocking the build chunk (cannot be done by me or by CI):**

1. **Apple Developer Program** membership active; App ID `com.catalystgrowth.platform` with the **Push
   Notifications** capability; an **APNs auth key** (`.p8`) with its **Key ID** and **Team ID**. (None of
   `DEVELOPMENT_TEAM`/entitlements is set in the repo — it has never been signed for push.)
2. **Firebase project**; add an **Android** app and an **iOS** app (same bundle id); download
   `google-services.json` and `GoogleService-Info.plist`; confirm the **FCM HTTP v1** API is enabled; upload the
   APNs key in Firebase → Cloud Messaging → Apple app configuration.
3. **Service account** (FCM send role, least privilege) → JSON key kept **outside the repo and web root**,
   `chmod 600`, owned by the PHP/worker user. Proposed env var _names_ (to be confirmed at plan time, none exist
   today in `config/services.php`): `FCM_PROJECT_ID`, `FCM_SERVICE_ACCOUNT_PATH`, read via `config/services.php`
   (precedent: `STRIPE_SECRET_KEY`). Nothing secret is committed; a `.gitignore` guard for service-account JSON is
   cheap insurance.
4. **A real iPhone and a real Android device** (push does not work on the iOS simulator in the relevant paths and
   the spike in §3.1 needs hardware).
5. **Production DB grants.** New tables created by the migrating role have previously been unreadable by the app
   role: `docs/runbooks/deploy-log.md:745-756,811-813` (the `engine_c_user` GRANT incident). `user_push_tokens`
   (and its id sequence) needs the same check — `has_table_privilege('engine_c_user','user_push_tokens',
'SELECT,INSERT,UPDATE,DELETE')` — as a **named post-migrate, pre-arm read**. Whether default privileges now cover
   new tables is **unknown** (the log says the fix history is not reconstructable) — **Q15**.

**Not blocking, but must be decided/owned:** App Store privacy labels and Play Data Safety (push token = device
identifier shared with Google); the privacy policy (`https://www.catalyst-growth.com/legal/privacy`); Google/Firebase
DPA and cross-border transfer position. **Honest limit: this is engineering review, not legal review** — **Q16**.
Also: one vs two Firebase projects (**Q9**), who owns the Google account that owns the project (bus factor), and the
release sequencing: **backend deploy (flag OFF) → arm flag → store build** (§1.6).

---

## 6. Riders

### 6.1 R1 — PHP 8.5 compile warning in `Sprint3Chunk2InvariantsTest.php`

- **Cause:** `apps/api/tests/Feature/Modules/Creators/Sprint3Chunk2InvariantsTest.php:17` is `use RuntimeException;`
  in a file with **no `namespace`**. PHP 8.5 reports: `The use statement with non-compound name 'RuntimeException'
has no effect`. `RuntimeException` is used at `:166` and `:196` and resolves to the global class regardless. It
  is the **only** such statement in `tests/` and `app/` (repo-wide grep).
- **One-line fix:** delete line 17. (Equivalent: leave it and write `\RuntimeException` at `:166`/`:196` — two
  edits, so the delete is the one-liner.) Last touched by `e665e9ce`.
- **What I reproduced:** `php -l` and a single-file Pest run emit the warning once; the file's 7 tests are
  reported as **DEPR** but the run **exits 0**; with `--fail-on-deprecation` the single file exits 1. A whole-suite
  `--list-tests` completes with exit 0 (warning appears once). `phpunit.xml` has no fail-on-warning/deprecation
  settings.
- **What I could NOT reproduce: "aborts full-suite collection".** A full serial suite run was not done.
- **Why CI never sees it:** CI pins `PHP_VERSION: '8.4'` (`.github/workflows/ci.yml:15`); the local toolchain is
  PHP 8.5.9. The warning is a 8.5-only local artefact.
- **Gating:** the fix is test-only (deleting an inert import). It lands in AH-102's build as its own docs/test-only
  commit (confirm — **Q17**).

### 6.2 R2 — did `creator-detail.spec.ts:64` flake in runs `38024623651` and `38025689774`?

**Yes — in both, retry-then-pass.** From the job logs (`gh api repos/pedram-kh/Engine/actions/jobs/<id>/logs`):

| Run           | Tip        | main SPA job   | Result line                                                                                      |
| ------------- | ---------- | -------------- | ------------------------------------------------------------------------------------------------ |
| `38024623651` | `b7a3342f` | `114134381893` | "2 flaky: `creator-detail.spec.ts:64`, `creator-wizard-happy-path.spec.ts:91`; 32 passed (7.1m)" |
| `38025689774` | `4d468406` | `114137575326` | the same two flaky; 32 passed (7.0m)                                                             |

- **Failure mode (first attempt):** `expect(page).toHaveURL(/\/roster\/<ulid>$/)` at spec `:73` failed — the URL
  stayed `http://127.0.0.1:5173/roster` for the full 15 000 ms (18 polls). The click on "Ada Lovelace" (`:72`)
  **never navigated**; it is not a slow route.
- Run 1's admin SPA also flaked `admin-sign-in.spec.ts:69` (1 flaky); run 2's admin job had no flake. Run 1's log
  carries an unrelated-looking Vite `[WebServer] … node_modules/.vite/deps/chunk-PGKQMF4Q.js … optimize deps
directory` warning ~5 minutes before the failure — **a lead only, no correlation shown**.
- **History:** `docs/reviews/creator-profile-everywhere-review.md` §11 (`:330-331`): two flakes led to `368970d4`
  (timeout 5 s→15 s), with the stated rule "a third flake after this bump reopens it as a real finding";
  `docs/tech-debt.md` item 11 (`:2937`) records it again; `creator-app-eyes-on-fixes-review.md:36` and
  `creator-app-fidelity-audit-review.md:37` (AH-100d) record further occurrences.
  **The AH-101 review does not record these two retries — a disposition gap.**
- **Disposition:** the 15 s timeout already failed _with the URL never changing_, so the bump did not address the
  cause. On the evidence this is at/over the three-session threshold, so **AH-102 carries the bounded-diagnosis
  rider** (suspect: a click dispatched before the roster row handler is attached, i.e. a hydration race — a
  hypothesis, not a finding). How AH-101's two runs count as "sessions" is Pedram's convention — **Q18**.

---

## 7. Risk and open questions

### Live-data risk

**PROD-DATA RISK: NONE** for this inventory commit — read-only, docs-only, zero runtime diff.

**PROD-DATA RISK (the build chunk it describes): LOW–MEDIUM.** Reasons, by what the build would touch that
already works:

- `NotificationService::notify()` — the hot path of **every** notification, called inside the state-machine
  transaction (§1.1). Mitigations: flag OFF by default; push leg in `try/catch`; `$afterCommit` job; the existing
  Notifications/Campaigns/Messaging suites must pass **unedited** (the AH-101 inertness proof).
- The preferences contract: `defaults` gains `push`; PATCH starts accepting `push` (§1.2). Backend-first deploy
  order; `push`-rows `count(*)=0` read.
- One **additive** migration (new table; no writes to existing tables; `down()` is lossy but recoverable — the app
  re-registers on next launch). GRANT check (§5.5).
- The queue worker (new job class → restart).
- Token population is zero until a push-capable build is installed, so first arming reaches nobody who has not
  opted in at the OS level.

Residual risks to carry: **R-1** lock-screen privacy (Q6); **R-2** FCM outage/credential error → silent loss, not
data loss (in-app list remains the source of truth); **R-3** a mis-set default-ON that cannot be walked back
without overriding stored state (Q3); **R-4** signed-out device whose revoke never arrived keeps receiving
pushes until PAT expiry (§2.3); **R-5** shared-device token reassignment lets whoever holds a token move it
(low); **R-6** iOS-via-Firebase under SPM unproven (spike, Q1).

**Deploy-log disposition for this commit:** none required — docs-only commit with no runtime diff. `deploy-log.md`
and the adhoc log are not touched. Not pushed.

### Open questions for Pedram

**Q1.** Transport/plugin: A (FCM v1 for both, `@capacitor-firebase/messaging` on iOS) vs B (APNs direct + FCM)?
Recommended A, gated by a hardware spike as the first build step. **Q1b.** Server client: thin custom (recommended)
vs `kreait/firebase-php`?
**Q2.** Confirm the v1 type set: YES = 13 (§1.4); NO = `live_verified`, `job_posted`, `application.rejected`. Any
flips?
**Q3.** Push default **ON** and missing-row = ON (recommended) — this is the lock-in decision.
**Q4.** Message cadence: every message collapsed, a ~60 s per-thread throttle (recommended), or other?
**Q5.** Mirror AH-069 — send one push when a draft approval also completes the assignment?
**Q6.** Lock-screen privacy: neutral copy (no message text, no rejection reasons) — agreed? May the body name the
campaign/agency?
**Q7.** Copy volume: distinct title+body per type (~26 ×24) or fixed app-name title (~13 ×24)?
**Q8.** Matrix push column: native-only (recommended; needs a small page change, contradicting AH-101's "no page
change"), always visible, or only when the user has a device (needs an API field)?
**Q9.** One Firebase project or two (staging/prod)? Who owns the Google account?
**Q10.** Per-user hourly push cap as a backstop (yes/no)?
**Q11.** Bind tokens to the PAT with FK cascade (recommended) vs standalone table with app-level revocation?
Also: are `creator.approved`/`creator.rejected` push cells toggleable (email's are always-on)?
**Q12.** Registration ungated by the flag, with arming _before_ the first push-capable store build, so the app never
reads the flag — OK?
**Q13.** Read-back: is the production worker `queue:work` (runbook) rather than Horizon, and what is
`QUEUE_CONNECTION` on the box?
**Q14.** Commit or gitignore `google-services.json` / `GoogleService-Info.plist`? (Neither is secret by Google's
guidance; repo precedent keeps environment-specific native config out of git.)
**Q15.** Do default privileges cover new tables for `engine_c_user`, or must the GRANT be a named deploy step?
**Q16.** Who signs off on privacy policy, App Store privacy labels, Play Data Safety and the Google DPA?
**Q17.** R1: OK to land the one-line deletion as a separate test-only commit in AH-102's build?
**Q18.** R2: confirm AH-102 carries the bounded-diagnosis rider, and how AH-101's two retried runs count toward the
session counter.
**Q19.** Is the Apple Developer account enrolled and the App ID created (blocks everything iOS)?

**Plan-pause stands.** No build plan, code, config or migration will be produced until this inventory is reviewed
and cleared.

---

## Not verified

- Whole-suite PHP run on 8.5 (R1 abort claim); PHP 8.4 behaviour of the warning (inferred from CI config only).
- Any FCM/APNs wire behaviour, error codes, collapse semantics and App Store guideline text — from vendor docs
  recalled from memory; marked **verify**.
- Firebase iOS SDK under SPM in this repo; `@capacitor-firebase/messaging` runtime behaviour (tarball read only).
- Pennant per-user scope resolution; production `QUEUE_CONNECTION`; production default DB privileges.
- Merged Android manifest (to confirm `POST_NOTIFICATIONS` is truly absent).
