# AH-102 — push notifications for the creator app — PLAN (plan-pause)

- **Status:** **Cleared** at `d4edffb4` with the P-rulings in §11 (2026-10-10). Build starts at Stage 0, C0.1. Both pushes held; Checkpoint A stops for clearance.
- **Date:** 2026-10-10
- **Entries:** **AH-102** (everything through Android) and **AH-102-ios** (the Apple-account-gated final stage; §6).
- **Author:** Cursor, against the rulings Q1–Q19 given at kickoff.
- **HEAD:** `bc6614c6` (`docs(reviews): AH-102 inventory — push notifications`), local, **not pushed**;
  `origin/main` is `4d468406`, pushed, **not deployed**.
- **Inventory this plan is cut from:** [`push-notifications-inventory.md`](push-notifications-inventory.md). Its
  evidence is re-used by section reference (`inv §…`); anything this plan newly read is cited inline.
- **Orientation re-read at plan time:** `WORKING-PROCESS.md` (§2 Mode A, §4–§8), `docs/runbooks/deploy-log.md`
  (entry template, the open `PENDING` chain), `notification-prefs-and-ui-batch-plan.md` (format), and the code paths
  cited below.

---

## 0. The live-data risk line

> **⚠️ PROD-DATA RISK: LOW–MEDIUM** (unchanged from the inventory; reasons now specific to the ruled design).
>
> **What touches rows/behaviour that already exist, and how it is contained**
>
> 1. **`NotificationService::notify()` — the hot path of every notification in production** and, for assignment
>    events, called _inside_ the state machine's `DB::transaction`. It is restructured (the in-app early return
>    stops skipping the push leg). Containment: the push leg is a single guarded call wrapped in `try/catch`
>    (a push failure can never abort the surrounding transaction), the job is `$afterCommit = true`, the flag is
>    default OFF and checked before anything else that costs a query, and the existing
>    Notifications/Campaigns/Messaging suites must pass **unedited** except the list in §4.2.
> 2. **The preference contract changes — consciously, in one place.** `isChannelEnabled()`/`setPreference()` and the
>    `defaults` block grow a type-aware default (Q3). It is the only new mechanism in the chunk (§4). It applies to
>    exactly one pair — **(`campaign.job_posted`, `push`) → OFF** — and a pinned test asserts the override map
>    contains _nothing else_, so no existing `in_app`/`email`/`digest` default can move. No stored preference row is
>    read-for-mutation or rewritten; pre-deploy `SELECT count(*) … WHERE channel='push'` must be `0` (§7.2).
> 3. **One additive migration** (`user_push_tokens`, `CREATE TABLE` only, no data writes, FKs _to_ `users` and
>    `personal_access_tokens`, nothing altered on them). `down()` is a true structural inverse (`dropIfExists`) with a
>    lossy content inverse: it discards every registered token. That is recoverable — the app re-registers on next
>    launch — and is named in the migration's `down()` comment (AH-054/056 house style).
> 4. **New outbound exposure to real users.** Nothing is sent at deploy: `push_notifications_enabled` defaults OFF;
>    the token population is **zero until the first push-capable store build is installed**; the flag is armed
>    _before_ that build ships (Q12), so arming reaches only people who have installed it **and** granted the OS
>    permission. The one promotional type (`job_posted`) is opt-in per type (Q3), so nobody receives it by default.
> 5. **New table needs a production GRANT** for `engine_c_user` (the 2026-07-31 incident,
>    `deploy-log.md:745-756,811-813`): a named post-migrate step (Q15), §7.2.
>
> **Blast radius of a bug, worst first.**
> (1) an exception or logic error in the `notify()` push leg breaking an assignment transition → try/catch + a test
> that a throwing dispatcher still commits the transition, and a break-revert of the guard;
> (2) the per-type default implemented wrong so that `job_posted` push defaults ON → bounded by the flag, by tokens
> existing, and by the fan-out cap (`DEFAULT_LIMIT = 50` per run, `JobPostedFanOutService.php:91`), but it would be a
> promotional push without opt-in — hence the both-polarity tests and the break-reverts in §4.3 are the centre of
> this chunk;
> (3) message text or a rejection reason reaching the lock screen → allowlist + sentinel tests (§5.3);
> (4) a signed-out/expired/deleted login still receiving pushes → the liveness filter and its §5.34 negative set (§3.3);
> (5) a bad FCM credential → silent non-delivery, not data loss (the in-app list remains the source of truth).
>
> **PROD-DATA RISK for this plan document itself: NONE** (docs-only).

---

## 1. Rulings → where each is baked in

| Ruling | Decision (as given)                                                                                                                | Plan location                                                   |
| ------ | ---------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------- |
| Q1     | Option A: FCM v1 both platforms; `@capacitor-firebase/messaging`                                                                   | §6 (iOS), §5.5 (Android), §2 (one JS plugin both platforms)     |
| Q1b    | Thin custom FCM v1 client, **zero new composer deps**                                                                              | §5.1                                                            |
| Q19    | Apple account **not enrolled** → re-sequence; sender swappable                                                                     | §2 (cut line), §5.1 (one interface), §6 (iOS stage, fallback B) |
| Q2     | 14 eligible = inventory's 13 + `job_posted` opt-in; out: `live_verified`, `application.rejected`                                   | §3.1                                                            |
| Q3     | Push default ON, except `job_posted` OFF per-type; type-aware default                                                              | §4                                                              |
| Q4     | ~60 s per-(thread, recipient) throttle + collapse keys; never touches the email debounce                                           | §5.4                                                            |
| Q5     | One push when a draft approval also completes the assignment                                                                       | §5.4, C2.4                                                      |
| Q6     | Neutral copy: may name agency/campaign; never message text or rejection reasons                                                    | §5.3                                                            |
| Q7     | Fixed localized title per type; names injected from `data`; `lang/*/push.php` ×24; flaky-10                                        | §5.3                                                            |
| Q8     | Push column native-only; **corrects AH-101's "no page change"**                                                                    | §4.5, §8.1                                                      |
| Q9     | One Firebase project, production only; document the owning Google account                                                          | §7.1, C2.1 docs                                                 |
| Q10    | No hourly cap in v1                                                                                                                | §5.4                                                            |
| Q11    | `user_push_tokens`; FK cascade to the PAT; unique(PAT), unique(token); upsert-by-token; `creator.approved/rejected` push always-on | §3.2, §3.1                                                      |
| Q12    | Registration ungated; flag gates sending only; arm before the first push-capable build                                             | §7                                                              |
| Q13    | `queue:work`, default queue; production read-back is a named pre-deploy check                                                      | §7.2                                                            |
| Q14    | Commit `google-services.json`/`GoogleService-Info.plist`; `.gitignore` guard for the service-account JSON                          | C0.2, C4.2, §6                                                  |
| Q15    | `engine_c_user` GRANT check = named post-migrate step                                                                              | §7.2                                                            |
| Q16    | Pedram's; lands on AH-105's checklist                                                                                              | §9 (out of scope)                                               |
| Q17    | `Sprint3Chunk2InvariantsTest.php:17` deletion = its own test-only commit, **first commit of the build**                            | C0.1                                                            |
| Q18    | R2 bounded-diagnosis rider, ≤2 h                                                                                                   | C0.3                                                            |

---

## 2. Shape and the cut line

**Build order: backend → matrix/SPA → Android (proven on a real device) → iOS last.**

```
Stage 0  preflight + riders                      (C0.1 R1, C0.2 gitignore guard, C0.3 R2 rider)
Stage 1  backend: preferences + token model      (C1.1 – C1.4)
Stage 2  backend: sender, copy, job, dispatcher  (C2.1 – C2.5)
         ── Checkpoint A: gates → docs pair → deploy-log PENDING → HOLD for push clearance ──
Stage 3  matrix / SPA                            (C3.1 – C3.2)
Stage 4  app JS + Android, device-proven         (C4.1 – C4.2, evidence C4.3)
         ── Checkpoint B: eyes-on E1/E2/E3 → gates → docs pair → deploy-log → HOLD ──
         ══════ CUT LINE — AH-102 closes here ══════
Stage 5  iOS (AH-102-ios) — needs the Apple account  (§6)
```

**Nothing before the cut line depends on Stage 5.** How that is made true, concretely:

- **Single platform gate in the SPA.** One function, `pushChannelAvailable()` (§4.5) = native **and** (Android, or iOS once
  `IOS_PUSH_ENABLED` is flipped). It drives the matrix column, the soft-ask sheet and the registration wiring. Until
  Stage 5 the constant is `false`, so an iOS build made from this code shows no push column, no prompt, and never
  calls the plugin. Stage 5 flips one constant.
- **`cap sync` is Android-only until Stage 5.** Installing `@capacitor-firebase/messaging` and running
  `npx cap sync android` regenerates only the Android Gradle files (`android/app/capacitor.build.gradle`,
  `android/capacitor.settings.gradle` — both tracked). `ios/App/CapApp-SPM/Package.swift` is **deliberately not
  regenerated** and `experimental.ios.spm.packageOptions` is **not** added to `capacitor.config.ts` until Stage 5.
  Hazard: a full `cap sync` before Stage 5 would add the Firebase SDK to the iOS project with no
  `GoogleService-Info.plist`. Recorded as risk **R-7** and as a `tech-debt.md` row; no iOS build ships before Stage 5
  (no Apple account), so nothing is exposed.
- **One JS plugin, both platforms.** The Android proof exercises the same JS client (`getToken`, `tokenReceived`,
  `notificationActionPerformed`) iOS will use, so Stage 5 is native wiring + proof, not new app logic.
- **Server is platform-agnostic.** `platform` is a column on the token row and nothing in the job, eligibility,
  copy, recorder or registry branches on it; only the `PushSender` implementation does (§5.1).

**If the Apple account is not live when Stage 4 closes, AH-102 closes with Stage 5 explicitly open** and it completes
as **AH-102-ios**. The AH-102 review's close-out lists Stage 5's items as open, not as passing.

**Push points.** Two held pushes, each with its own deploy-log entry before it goes: **A** after Stage 2
(backend only, flag OFF, nothing client-visible) and **B** after Stage 4 (SPA + Android). Backend-first is therefore
structural, not just ordered: the API has accepted `push` in production before any client can send it (inventory §1.2
hazard: an SPA with a push column ahead of the API turns every save into a 422).

---

## 3. Eligibility and the token model (the settled shape)

### 3.1 The 14 eligible types, and who can toggle what

Source of truth is one backend class, `Notifications/Push/PushEligibility` (plain `NotificationType` lists, written
so the SPA parity spec can read them from source exactly as `templates.spec.ts:49-57` reads `NotificationType.php`).

| Group                           | Types                                                                                                                                                                                                                                                                                           | Toggle in matrix | Default                       |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------- | ----------------------------- |
| **Toggleable, default ON (9)**  | `assignment.invited`, `assignment.revision_requested`, `assignment.draft_approved`, `assignment.draft_rejected`, `assignment.completed_on_approval`, `assignment.manually_verified`, `message.received_by_creator`, `message.relationship_received_by_creator`, `campaign_application.accepted` | yes              | ON (missing row)              |
| **Toggleable, default OFF (1)** | `campaign.job_posted`                                                                                                                                                                                                                                                                           | yes              | **OFF** (opt-in; Apple 4.5.4) |
| **Always-on, no toggle (4)**    | `creator.approved`, `creator.rejected` (matrix caption "Always on", like email: `templates.ts` `alwaysOnChannels`); `agency_creator_relation.admin_connected`, `agency_creator_relation.disconnected` (`preference: null` → never in the matrix)                                                | no               | always                        |
| **Not eligible (rest)**         | `assignment.live_verified`, `campaign_application.rejected` (Q2), the 5 agency-only types, the 6 non-live cases                                                                                                                                                                                 | —                | —                             |

Always-on means the dispatcher **does not read a push preference for these types** (so a stray stored row cannot
suppress them); the toggleable ten read it. Eligibility is by type only — the push leg runs for whoever the emit site
named as recipient, and agencies have no tokens, so `disconnected`'s agency recipients are a zero-token no-op.

### 3.2 `user_push_tokens` (as proposed in the inventory, Q11)

`id` · `user_id` FK→`users` cascade · `personal_access_token_id` FK→`personal_access_tokens`
**cascadeOnDelete, NOT NULL, unique** · `platform` (`ios`|`android`) · `token` string(512) **unique** · `app_version`
nullable · `last_seen_at` · `invalidated_at` + `invalidation_reason` (nullable) · timestamps · index
`(user_id, invalidated_at)`.

- **One token per device login:** unique(PAT) turns an FCM rotation into a replace; multi-device = several PATs.
- **Upsert by token value:** the same token under a different account moves the row (shared phone).
- **Revocation is DB-level**, so it fires for model-event deletes _and_ mass deletes: `logoutToken`
  (`AuthService.php:458-461`), `PasswordResetService.php:113`, `AccountLockoutService.php:90`. A canary test proves
  the cascade is actually enforced under the test database (SQLite) before any other test relies on it.
- **Table name** is `user_push_tokens` per the kickoff; the earlier inventory's M5 erase-set called it `push_devices` —
  the docs pass reconciles the name.

### 3.3 The liveness filter (inventory §2.3) — one query, one place

`PushTokenRegistry::liveTokensFor(User)` returns only rows where **all** hold: `invalidated_at IS NULL`; the joined
PAT satisfies Sanctum's own use-time rule — `created_at > now() − sanctum.expiration` (when `expiration > 0`;
`config/sanctum.php:75`, 129 600 min) **and** `expires_at` is null-or-future; and the owning user is not
soft-deleted (`User` uses `SoftDeletes`, `User.php:69`, which does not trigger the FK cascade). §5.34 negative set:
each case eligible in every respect except one (invalidated; PAT expired by `created_at`; PAT `expires_at` past;
user soft-deleted; PAT deleted/cascaded; another user's token). **AH-104 seam:**
`PushTokenRegistry::revokeAllForUser(User): int` — named, built here because it is the registry's own delete path,
**not** wired into any account-deletion flow.

---

## 4. The per-type default mechanism — the one new piece of mechanism in the chunk

### 4.1 What exists and what is wrong with it for Q3

Today the default is a property of the **channel** only: `NotificationChannel::defaultEnabled()`
(`Notifications/Enums/NotificationChannel.php:28-34`). It is read at exactly three places, plus docblocks:

| Call site               | File:line                                 | Role                                                                        |
| ----------------------- | ----------------------------------------- | --------------------------------------------------------------------------- |
| `isChannelEnabled()`    | `NotificationService.php:138`             | missing row → default (the read side)                                       |
| `setPreference()`       | `NotificationService.php:86`              | row stored only when the value _diverges_ from the default (the write side) |
| controller `defaults()` | `NotificationPreferenceController.php:93` | the `defaults` block shipped to the SPA                                     |

The SPA composes `row?.is_enabled ?? defaults[channel]` (`NotificationPreferencesPage.vue:141`) and the API-client
type is `defaults: Record<NotificationChannel, boolean>` (`packages/api-client/src/types/notification.ts:192`).

The danger is the **write side**: if `setPreference()` kept using the channel default (`true` for push) while the read
side used the type default (`false` for `job_posted`), then a user turning `job_posted` push **ON** would be treated as
"back at the default" → **row deleted** → read as OFF. _Opt-in would silently never stick._ That is break-revert BR-2.

### 4.2 The mechanism

1. **One resolver:** `NotificationChannel::defaultEnabledFor(NotificationType $type): bool` — the channel baseline
   (`defaultEnabled()`, kept, and documented "baseline only — call sites that have a type MUST use
   `defaultEnabledFor`") with a single declarative override table in the same file:
   `TYPE_DEFAULT_OVERRIDES = [ 'campaign.job_posted' => [ 'push' => false ] ]`, exposed via
   `typeDefaultOverrides(): array`.
2. **All three call sites move to it** (`isChannelEnabled`, `setPreference`, controller). The sparse-storage contract
   is unchanged: _a row exists only when the value diverges from the (type-aware) default._
3. **Wire contract, additive:** `GET`/`PATCH /me/notification-preferences` keep `defaults` (per channel, now including
   `push: true`) and add `type_defaults` — **only the exceptions**, e.g.
   `{"campaign.job_posted": {"push": false}}`. Old clients ignore the new key; there is no old client that renders push.
4. **SPA composition:** `row?.is_enabled ?? typeDefaults[type]?.[channel] ?? defaults[channel]`
   (`applyState`, `NotificationPreferencesPage.vue:131-143`). The api-client type gains `type_defaults` and `'push'`.
5. **Architecture guard** (in the style of `Unit/…/EmailPreferenceGuardArchitectureTest.php`): a source scan asserting
   `->defaultEnabled()` is called nowhere outside `NotificationChannel` itself — so a fourth call site cannot
   reintroduce the channel-only default. Plus a pinned assertion that the override map equals exactly the one ruled
   pair, so no existing default can be flipped by a later edit without a test failing (the `setPreference` docblock,
   `:62-77`, is the standing warning about default flips overriding stored state).
6. **No (type, channel) combination validation** on PATCH — same as AH-101. The dispatcher, not validation, is
   authoritative: an ineligible type never reaches the push leg, and always-on types never read the row (§3.1). Flagged
   as plan question **P-9** only so it is a decision, not an omission.

### 4.3 Both-polarity tests (all at the backend, then mirrored in the SPA)

| #   | Case                                                      | Expected                                                                               |
| --- | --------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| T1  | `job_posted` push, no row                                 | read **OFF**                                                                           |
| T2  | `job_posted` push, `setPreference(true)`                  | row **stored**; read **ON**                                                            |
| T3  | `job_posted` push, `setPreference(false)` after T2        | row **deleted**; read **OFF**                                                          |
| T4  | control type (`assignment.invited`) push, no row          | read **ON**                                                                            |
| T5  | control, `setPreference(false)`                           | row **stored**; read **OFF**                                                           |
| T6  | control, `setPreference(true)` after T5                   | row **deleted**; read **ON**                                                           |
| T7  | `job_posted` `in_app`/`email` (same type, other channels) | unchanged channel defaults (ON/ON)                                                     |
| T8  | `GET` block                                               | `defaults.push === true`; `type_defaults` exactly `{campaign.job_posted:{push:false}}` |
| T9  | PATCH round-trip through HTTP                             | each of T2/T3/T5/T6 via the endpoint, response `type_defaults` intact                  |
| T10 | dispatcher                                                | `job_posted`, no row → no push; with opt-in row → push; control, no row → push         |

**Break-reverts:** **BR-1** resolver ignores the type → T1/T10 fail. **BR-2** `setPreference` back to the channel
default → T2 (and T10) fail. **BR-3** controller omits `type_defaults` → T8/T9 fail. Each mutated → right spec fails →
reverted → `git status`/`git diff` clean → re-green, output verbatim in the review.

### 4.4 Conscious edits to existing tests (the only ones allowed)

Everything else in Notifications/Campaigns/Messaging passes **unedited**; these are the contract changes, each
justified by "the contract consciously changed":

| Existing test                                                                 | Edit                                                           | Why                                                                                                                                               |
| ----------------------------------------------------------------------------- | -------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tests/Feature/Modules/Notifications/MeNotificationPreferencesTest.php:36-38` | add `defaults.push === true` and `type_defaults` assertion     | `defaults` is built from `NotificationChannel::cases()` (`NotificationPreferenceController.php:88-97`); a new case and a new key are the contract |
| `tests/Feature/Modules/Notifications/NotificationServiceTest.php:95`          | title/assertions extended for push (and the type-aware read)   | the "resolves preserve-current defaults" test enumerates the channels                                                                             |
| SPA `modules/notifications/pages/NotificationPreferencesPage.spec.ts:32`      | `DEFAULTS` literal gains `push`; fixtures gain `type_defaults` | the type now requires it                                                                                                                          |

`templates.spec.ts` (including `:340`, `preferenceColumns(messaging) → ['in_app','email','digest']`) is expected to pass
**unedited**: the registry gains push, but `preferenceGroupsForRole()` filters native-only channels by default (§4.5), so
web-shaped callers are byte-identical. If any existing spec turns out not to, that is a stop-and-flag, not a quiet edit.

### 4.5 The native-only column (Q8) — and the correction to AH-101

- `PREFERENCE_CHANNEL_ORDER` (`templates.ts:115-119`) gains `'push'`; the 14 types gain `push` in `channels`, and
  `creator.approved`/`creator.rejected` gain `alwaysOnChannels: ['email','push']`; a new constant sits beside
  `IN_APP_AND_EMAIL`.
- A new exported `NATIVE_ONLY_CHANNELS = ['push']`. `preferenceGroupsForRole(role, options?)` takes
  `{ includeNativeOnly?: boolean }`, **default `false`**, and strips those channels from `channels` and `alwaysOn`; the
  page passes `pushChannelAvailable()`. Because `allRows`, `columnsFor` and `onSave` all derive from the same groups,
  a web user can neither see push cells nor **send** push rows in a PATCH.
- **Reinterpretation (§5.32), recorded:** the ruling says "`isNativePlatform()` filter". The _intent_ is "never a dead
  control". On an iOS build before Stage 5 `isNativePlatform()` is true but push cannot work, so the predicate is
  `pushChannelAvailable()` (native ∧ Android-or-flag). Same intent, one extra term (**P-8**).
- **A push cell with no OS permission is a dead-feeling control**, so the page also shows (native only) a one-line state
  row — "Push is off on this device — Enable" — which is the second entry to the soft-ask (§5.5).
- **This corrects the AH-101 claim** (`PREFERENCE_CHANNEL_ORDER`'s comment, `templates.ts:109-114`: "adding a channel
  (AH-102's `push`) is one entry here plus the enum case and the label — no page change", and the AH-101 plan's "push is
  one column constant plus one enum case"). It is not true: hiding the column on web needs a `preferenceGroupsForRole`
  option and a page change. Corrections: the stale code comment is rewritten in C3.1, and a dated
  `## Post-close addendum` is added to `notification-prefs-and-ui-batch-review.md` (closed records stay verbatim — §8).

---

## 5. Stage plan

Conventions for every commit: conventional message; gates green on touched packages before it lands (Pest scoped,
Pint, PHPStan/Larastan, vue-tsc, ESLint, Vitest); the full board only at checkpoints (§7.1). Break-reverts are
executed **per commit that adds a gate** and their output pasted verbatim into the review.

### Stage 0 — preflight and riders

| Commit                                                                                                  | Content                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | Test / proof                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| ------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **C0.1** `test(creators): drop inert RuntimeException import (PHP 8.5)` — **first commit of the build** | delete `tests/Feature/Modules/Creators/Sprint3Chunk2InvariantsTest.php:17` (`use RuntimeException;`; it is used unqualified at `:166`/`:196` and resolves to the global class)                                                                                                                                                                                                                                                                                                                                                                                                                                         | `php -l` clean; the file's 7 tests green with no `DEPR`; **break-revert = re-add the line, watch the warning return, remove it, `git diff` clean.** Test-only diff. CI pins PHP 8.4 (`ci.yml:15`), so CI is unaffected either way (inv §6.1).                                                                                                                                                                                                                                                                            |
| **C0.2** `chore: guard service-account and APNs key files in .gitignore`                                | root `.gitignore`: patterns for service-account JSON (`*-firebase-adminsdk-*.json`, `**/service-account*.json`, `**/*serviceAccount*.json`) and APNs keys (`AuthKey_*.p8`, `*.p8`). **Not** ignored: `google-services.json` and `GoogleService-Info.plist` (Q14; the Android template line is already commented out at `apps/main/android/.gitignore:65`)                                                                                                                                                                                                                                                              | `git check-ignore -v` on a sample of each pattern (positive) and on both client config names (negative); no real file involved                                                                                                                                                                                                                                                                                                                                                                                           |
| **C0.3** _R2 rider_ (outcome decides the content)                                                       | **≤ 2 h wall-clock, hard stop.** Reproduce `apps/main/playwright/specs/creator-detail.spec.ts:64`: `--repeat-each=30 --retries=0` on the isolated E2E stack, count failures; trace the failing attempt; test the **hydration/re-render-race hypothesis** — the click at `:72` (`table.getByText('Ada Lovelace').click()`) lands after the table is visible but before the row handler is attached, or on a node a second render replaces (both fit "URL stayed `/roster` for the full 15 s", inv §6.2) — by comparing against a click gated on a stable row, and by reading `CreatorRosterPage.vue`'s row-click wiring | **Either** (a) a fix (spec-level stabilization or an app-level fix) **with a break-revert on the reproduction**, **or** (b) evidence + a parked disposition (`tech-debt.md` row with the observed repro rate and the CI run IDs `38024623651`, `38025689774`). The review records which, either way. **Not** a licence for an open-ended hunt; at 2 h it stops and records. Observed-but-out-of-scope in the same runs: `creator-wizard-happy-path.spec.ts:91` and `admin-sign-in.spec.ts:69` — recorded, not diagnosed. |

### Stage 1 — backend: preferences contract and the token model

| Commit                                                                                      | Content                                                                                                                                                                                                  | Tests (new unless stated)                                                                                                                                                                                         |
| ------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **C1.1** `refactor(notifications): type-aware preference default (behaviour-identical)`     | `defaultEnabledFor()`; the three call sites move; override table **empty**; architecture scan (§4.2-5)                                                                                                   | **Inertness proof:** Notifications/Campaigns/Messaging suites unedited and green; scan test; break-revert: re-add a direct `->defaultEnabled()` call → scan fails                                                 |
| **C1.2** `feat(notifications): push channel, eligibility policy, job_posted opt-in default` | `NotificationChannel::Push`; `PushEligibility` (§3.1 lists); override `{job_posted: {push: false}}`; `type_defaults` in the controller; api-client type (`'push'`, `type_defaults`)                      | T1–T10 (§4.3); the three edits in §4.4; override-map-equals-ruled-pair pin; eligibility lists pinned to the literal 14 / 10 / 4 sets; BR-1…BR-3                                                                   |
| **C1.3** `feat(notifications): user_push_tokens table, model, registry`                     | migration (§3.2), `UserPushToken`, factory, `PushTokenRegistry` (`register` upsert, `liveTokensFor`, `invalidate`, `revokeAllForUser`)                                                                   | cascade canary; §3.3 negative set; upsert-moves-ownership; unique(PAT) replace-on-rotation; `down()` honesty comment asserted by review; BR-4 (drop the PAT-expiry filter → the expiry case fails)                |
| **C1.4** `feat(notifications): POST/DELETE /me/push-tokens (creator_app bearer only)`       | controller + `RegisterPushTokenRequest` (`platform` in `ios,android`, `token` ≤512, `app_version` nullable); `auth:creator_app` **alone** + `tenancy.set` + `throttle:30,1`; own-token delete only; 204s | cookie session → 401; agency/admin bearer → refused by the guard; validation negatives; deleting someone else's token is a no-op; throttle; route pins (below); BR-5 (swap to `auth:web,creator_app` → pins fail) |

**Route pins.** There is **no bearer-only (`auth:creator_app` alone) route in the tree today** (all `creator_app` uses are
`auth:web,creator_app`, `Identity/Routes/api.php:61-135`, `Notifications/Routes/api.php:32,46`, etc.). C1.4 reads the three
route-inspection tests in `tests/Feature/Modules/Identity/CreatorAppGuardSwapTest.php` first (swapped-set test
`:88-104`, the no-both test, the over-reach test), adds a fourth assertion "exactly these routes carry bare
`auth:creator_app`", and confirms the existing three still hold. `docs/security/tenancy.md` §4 allowlist (heading `:113`;
the `/me/notification…` rows sit at `:189-193`) gets the new rows in the docs pair.

### Stage 2 — backend: sending

| Commit                                                                                 | Content                                                                                                                     | Tests                                                                                                                                                            |
| -------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **C2.1** `feat(notifications): PushSender contract and FCM v1 client`                  | see §5.1                                                                                                                    | `Http::fake` suite incl. real RS256 signature verification with a test key; error taxonomy table; BR-6                                                           |
| **C2.2** `feat(notifications): push copy (lang/*/push.php ×24) and PushMessageFactory` | see §5.3                                                                                                                    | per-locale real-render; sentinel privacy tests; locale parity; BR-7                                                                                              |
| **C2.3** `feat(notifications): SendPushNotificationJob and delivery recorder`          | see §5.2                                                                                                                    | job behaviours; `$afterCommit` rollback test; BR-8, BR-9                                                                                                         |
| **C2.4** `feat(notifications): push leg in notify() behind push_notifications_enabled` | see §5.4                                                                                                                    | the dispatcher matrix; BR-10…BR-13                                                                                                                               |
| **C2.5** `feat(notifications): push:test operator command` _(if P-6 is accepted)_      | `php artisan push:test --user=<id> [--validate-only] [--dry-run]`: one named user, one fixed neutral message, never fan-out | credential smoke test for the deploy (`validate_only`, no device) and the device proofs; guarded/idempotent per the data-command pattern (§9 of WORKING-PROCESS) |

**Checkpoint A (end of Stage 2):** full board (§7.1) → docs pair (§8) → deploy-log `PENDING` → **hold for clearance**.

### 5.1 The sender seam (so fallback B swaps one half)

```
PushSender (interface)            send(PushMessage, PushTarget): PushSendResult
 ├─ FcmPushSender                 the only class that knows FCM JSON, the OAuth exchange and FCM error codes
 └─ (future) ApnsPushSender       fallback B: iOS tokens only; implements the same interface
PushMessage   (neutral DTO)       title, body?, data{}, collapseKey?, channelId, ttl, priority — no vendor fields
PushTarget    (DTO)               platform, token  (+ `environment` only if fallback B ever needs it)
PushSendResult(neutral enum+DTO)  Delivered | TokenInvalid | Retryable | ConfigError | Rejected, plus code/detail for logs
```

- **FcmPushSender** (zero new composer deps — `composer.lock` has none of the Firebase packages, inv §0.1): `Http`
  facade (precedent for a small outbound client: `Identity/Services/PwnedPasswordsClient.php:40`, config in
  `config/services.php` `hibp`), RS256 service-account JWT via `openssl_sign`, OAuth access token cached in `Cache`
  (TTL = `expires_in` − 120 s; one forced refresh-and-retry on a 401 inside the same attempt), endpoint
  `POST https://fcm.googleapis.com/v1/projects/{id}/messages:send`.
- **Mapping lives only here:** neutral `collapseKey` → `android.collapse_key` + `android.notification.tag` +
  `apns.headers.apns-collapse-id` + `apns.payload.aps.thread-id`; `channelId` → `android.notification.channel_id`;
  `ttl` → `android.ttl` / `apns-expiration`.
- **Error taxonomy** (FCM v1 vocabulary — _from vendor docs, verify against the live API at C2.1_): `UNREGISTERED` →
  `TokenInvalid`; `INVALID_ARGUMENT` → `TokenInvalid` **only** when the message names the token, else `Rejected`;
  `SENDER_ID_MISMATCH`, `THIRD_PARTY_AUTH_ERROR`, 401/403 after refresh → `ConfigError` (alarm, never a token kill);
  `QUOTA_EXCEEDED`/`UNAVAILABLE`/`INTERNAL`/timeouts → `Retryable`.
- **Config** in `config/integrations.php` beside `payment.stripe` (`:108-115`; "secrets in AWS Secrets Manager,
  non-secret config here"), env `FCM_PROJECT_ID`, `FCM_SERVICE_ACCOUNT_JSON_B64` (the service-account JSON, base64-encoded, **decoded inside the FCM
  client**; **P-1 ruled** — no file, no path, no permission step), plus timeout. A missing, non-base64 or non-JSON value
  is a `ConfigError` outcome, never a crash at boot.
- **Containment proof (the fallback-B guarantee):** a scan test asserting nothing outside
  `Notifications/Push/Fcm/` imports an `Fcm*` class or references `fcm.googleapis.com`, and that the job, dispatcher,
  factory, recorder and registry never branch on `platform`. If the spike later fails, B = add `ApnsPushSender`, a
  per-platform binding in the provider, and an additive `environment` column — **no change** to eligibility, copy,
  job, recording or the token endpoints (the one JS-side counterpart, the plugin choice, is behind the same
  injectable `PushClient` interface, §5.5).

### 5.2 The job and recording (the AH-102b seam)

`SendPushNotificationJob(int $tokenId, array $message)` on the **default** queue (the only queue the production worker
consumes, `production-queue-worker.md:14,30,58`): `ShouldQueue`, **`public bool $afterCommit = true`**, `$tries = 3`,
`$backoff = [30, 120]`, `$timeout = 20`, HTTP timeout well under `retry_after` (300 s, `config/queue.php:75`), a
`failed()` hook — the house pattern is `Creators/Jobs/ProcessPortfolioImageJob.php:77,84,90,156`. On run: reload the
token (skip if gone/invalidated; re-check liveness) → `PushSender::send` → map the result:
`Delivered` → record; `TokenInvalid` → `PushTokenRegistry::invalidate(reason)`, **no retry**; `Retryable` → release with
backoff; `ConfigError`/`Rejected` → log at error, **no retry** (a retry cannot fix a config error).

**One recording call site:** `PushDeliveryRecorder::record(PushDeliveryOutcome)`. In AH-102 it does two things — the
`invalidated_at` mark and a structured `Log::info` (the `DebouncedMessageMailer::logEmission()` precedent). **AH-102b**
replaces its body with an insert into a future `push_deliveries` table; no schema for it is created now.

### 5.3 Copy and privacy (Q6, Q7)

- `lang/{locale}/push.php` ×24: per eligible type a fixed localized **`title`** and an optional **`body`** whose
  placeholders come only from an allowlist of `data` keys that are _names_: `campaign_name`, `agency_name`,
  `counterparty_name`, `sender_name`. Names are injected untranslated. If a type's required name is missing the body is
  dropped and the title alone is sent (no extra strings). Names are length-capped (~60 chars) and control-character
  stripped. `creator.approved`/`creator.rejected` carry no names.
- Rendered with an **explicit locale argument** — `$recipient->preferred_language ?: 'en'`
  (`Identity/Models/User.php:35,88`) — never `App::setLocale()`, because `notify()` can be mid-transaction in a request
  that must not have its locale mutated.
- **Structural privacy:** `PushMessageFactory` builds the payload from the allowlist; it never forwards the `data` bag.
  The review notifiers' `data` carries `feedback` and `rejection_reason` (rendered in-app at
  `NotificationCenter.vue:101-107`) and message events carry sender names — none of that can be reachable.
  **Sentinel tests:** a `data` bag containing `feedback`, `rejection_reason`, and a message-text-shaped key with unique
  sentinel strings → none appears anywhere in the FCM request body, for all 14 types × 24 locales (render matrix).
  **BR-7:** change the factory to forward `data` → the sentinel test fails.
- Locale discipline: real MT for all 23 non-English locales; the flaky ten (bg, el, et, fi, ga, hu, lt, lv, mt, ro) are
  never left as English fallback (WORKING-PROCESS §4); parity test green. ≈ 14 titles + ≤ 14 bodies × 24.
- Payload `data`: `{type, notification_ulid?, v:1}` — `notification_ulid` exists **only when the in-app row was
  written** (it is absent when the user has muted in-app but still gets push). No server badge counts. No data-only
  messages.

### 5.4 The dispatcher and the `notify()` hook (C2.4)

`NotificationService::notify()` (`:41-60`) today: `if (!isChannelEnabled(InApp)) return null;` then create
(`:48-50`). New shape, **same signature plus one optional `bool $suppressPush = false`**:

```
$notification = isChannelEnabled(..InApp..) ? create(...) : null;   // the early return is gone
$this->pushDispatcher->dispatch($recipient, $type, $data, $notification?->ulid, $suppressPush);
return $notification;
```

`PushDispatcher::dispatch` wraps its whole body in `try { … } catch (Throwable $e) { report; }`, and checks in order:
**(1)** `PushEligibility` (static; agency/ineligible types cost **zero** queries) → **(2)** `$suppressPush` →
**(3)** `Feature::active(PushNotificationsEnabled::NAME)` → **(4)** the push preference — `isChannelEnabled(.., Push)` with
the type-aware default (§4) — **skipped for always-on types** → **(5)** `liveTokensFor` (empty → return) → **(6)** the
message throttle (message types only) → **(7)** build once per recipient, dispatch one job per token.

- **Reinterpretation (§5.32), recorded:** the ruling says "push leg in try/catch _before_ notify()'s early return". The
  _intent_ is _push independent of the in-app preference, and unable to hurt the caller_. Placing the call literally
  above the early return would work but loses the `notification_ulid`; removing the early return and running the leg on
  both paths achieves the intent with the ulid available. If the review prefers the literal placement, the ulid is the
  only cost (**P-7**).
- **Flag:** `Creators/Features/PushNotificationsEnabled` (`NAME = 'push_notifications_enabled'`, `default()` → closure
  returning `false`; copy of `PostVerifiedMailEnabled.php:34-46`), registered at `CreatorsServiceProvider.php` beside
  `:263-266`, listed in `Admin/Http/Controllers/AdminFeatureFlagController.php` (entries from `:79`), asserted in
  `tests/Feature/Modules/Admin/AdminFeatureFlagTest.php:70`, documented in `docs/feature-flags.md` (the arm-only-after
  column: _credentials present and `push:test --validate-only` passed_). It gates **sending only** (Q12). The Notifications
  module importing a flag class from `Creators` follows the one-registry convention.
- **AH-069 mirror (Q5):** `SendAssignmentNotifications::notifyCreatorOfReview` already receives
  `suppressEmail` (`:448`, passed from the transition context at `:115`, applied at `:475`). The approval's `notify()` call
  passes **the same value** as `suppressPush`, so an approval that also completes the assignment sends **one** push
  (`completed_on_approval`), while both in-app rows are still written — exactly as email behaves.
- **Message throttle + collapse (Q4, Q10):** keyed per (recipient, thread) — `assignment_ulid` for
  `message.received_by_creator`, `thread_ulid` for the relationship type (these are the keys in each `data` bag,
  `SendMessageNotifications.php:62-66`, `RelationshipMessageNotifications.php:61-66`) — `Cache::add(key, 1, 60)`; a miss
  means a push already went in the last ~60 s → skip. The same key is the collapse key. **It never calls or reads
  `DebouncedMessageMailer`/`message_email_debounces`** (a scan test pins that). No hourly cap (Q10). The cache store is
  a deploy read-back (`CACHE_STORE`, §7.2): `Cache::add` must be atomic on it.
- **Transaction note:** a throttle slot claimed inside a transaction that then rolls back stays claimed for ≤60 s —
  accepted (messages are not emitted inside the state-machine transaction).

**C2.4 tests:** the dispatch matrix — each of 14 eligible types pushes; `live_verified`, `application.rejected`, the 5
agency types and the non-live cases never do; flag OFF → nothing; preference stored `false` suppresses a toggleable type
but **not** an always-on type; **in-app muted still pushes** (BR-10: re-introduce the early return → fails); a throwing
sender/registry does not stop `notify()` or the surrounding commit (BR-11: remove the `try/catch` → fails); a job
dispatched inside a rolled-back `DB::transaction` never runs (BR-8: drop `$afterCommit`); approval-with-completion yields
one push (BR-12: ignore `$suppressPush`); two messages in 60 s on one thread → one push, a second thread → its own
(BR-13: remove the throttle); no token → no job; a cross-recipient negative (a token of user B is never used for A).
Existing `SendAssignmentNotifications`/messaging tests that fake `Mail`/`Event` are expected unedited — the §5.2 Event::fake
split applies to new tests (event leg vs no-side-effect leg).

### Stage 3 — matrix / SPA

| Commit                                                                             | Content                                                                                                                                                                                                                                      | Tests                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| ---------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **C3.1** `feat(notifications): push column in the preference matrix (native only)` | `templates.ts` (§4.5), `NotificationPreferencesPage.vue` (`typeDefaults` composition, native state row), `pushChannelAvailable()` (+ `IOS_PUSH_ENABLED = false`), rewrite the stale comment at `templates.ts:109-114`, `channels.push` label | registry spec: the 10 toggleable + 4 always-on cells exactly; **web default unchanged** (existing specs unedited); `typeDefaults` both polarities (job_posted OFF no row / ON with row; control ON no row / OFF with row); PATCH body on native includes push rows, on web **never**; **parity spec reading `PushEligibility.php` from source** (the `templates.spec.ts:49-57` technique) so registry ⇔ backend cannot drift; BR-14 (drop the native filter → web specs fail), BR-15 (ignore `type_defaults` → polarity specs fail) |
| **C3.2** `feat(i18n): push label and state strings ×24`                            | `channels.push`, the state row, soft-ask + settings strings (§5.5), Android channel name                                                                                                                                                     | locale parity; flaky-ten value check (no English fallback)                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |

### Stage 4 — app JS + Android

| Commit                                                                              | Content                                                                                                                                                                                                                                                                                                                                       | Tests                                                                                                                                                                         |
| ----------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **C4.1** `feat(app): push registration, soft-ask, tap and foreground handling`      | `core/platform/push/` — injectable `PushClient` (dynamic `import('@capacitor-firebase/messaging')`, `c8 ignore` bridge, same shape as `appResume.ts`), `pushChannelAvailable()`, registration store, `PushSoftAskSheet`, preferences-page state row, wiring started after mount beside `startAppResumeRefresh` (`main.ts:40`), logout cleanup | see §5.5                                                                                                                                                                      |
| **C4.2** `feat(android): FCM wiring — plugin, google-services.json, manifest, icon` | `package.json` dep; `npx cap sync android` **only**; `capacitor.config.ts`; `android/app/google-services.json` (committed, Q14, supplied by Pedram); manifest meta-data; push icon asset                                                                                                                                                      | merged-manifest inspection; debug + release assemble; the plugin gradle block applies via the existing conditional `google-services` apply (`android/app/build.gradle:48-53`) |
| **C4.3** `docs(reviews): AH-102 Android device proof`                               | the evidence of §5.6 pasted into the review draft                                                                                                                                                                                                                                                                                             | Pedram's walk E3                                                                                                                                                              |

**Checkpoint B:** eyes-on E1/E2/E3 (§7.3) → full board → docs pair → deploy-log → **hold**.

### 5.5 The client (C4.1) — behaviour spec

- **Plugin choice:** `@capacitor-firebase/messaging` 8.5.2 on **both** platforms (it must not coexist with
  `@capacitor/push-notifications`; neither is installed today). The `firebase` npm peer is optional and **not** installed
  (web is never used); a build/bundle check confirms nothing web-side is pulled in. API surface used (from the tarball
  README, to be re-checked against the installed typings): `checkPermissions`, `requestPermissions`, `getToken`,
  `deleteToken`, `createChannel`, listeners `tokenReceived`, `notificationReceived`, `notificationActionPerformed`.
- **Auto-init disabled (privacy), unverified on device:** the plugin README says that when a token is generated the
  library uploads identifiers to Firebase and offers `firebase_messaging_auto_init_enabled=false` /
  `firebase_analytics_collection_enabled=false` (Android manifest) and `FirebaseMessagingAutoInitEnabled=NO` (iOS
  `Info.plist`). The plan sets them so **no token exists until the creator opts in via the soft-ask** (`getToken()` after
  consent). Whether explicit `getToken()` and later rotation behave correctly with auto-init off is a **device-proof
  item (§5.6)**; if it does not, the fallback is default auto-init, recorded for AH-105's privacy checklist (**R-8**).
- **Soft-ask** (our own sheet, native + `pushChannelAvailable()` only): shown on the creator's first dashboard landing —
  `CreatorDashboardPage.vue` pending banner (`:271`, native poll `:201-218`) **or** an approved creator who has never been
  asked — never at cold launch or sign-in. Copy: "Get notified the moment you're approved / invited / messaged". Accept →
  `requestPermissions()` → `getToken()` → `POST /me/push-tokens`. Decline → persisted `askedAt` (WebView `localStorage`,
  non-secret; cleared on uninstall), **not re-shown for 30 days**. OS-level deny → only the settings entry remains, which
  becomes "open system settings" (**P-2** for the trigger population and the 30-day figure).
- **Registration upkeep:** on boot/resume, if permission already granted, re-POST (daily at most) to refresh
  `last_seen_at`; `tokenReceived` → POST the new token (the server replaces by unique(PAT)).
- **Android channel:** `createChannel({ id: 'catalyst_default', … })` at registration, named from an i18n key; the server
  message sets the same `channel_id`; the manifest default-channel meta-data is the fallback.
- **Logout (R-4 mitigation):** `useAuthStore.nativeLogout()` (`useAuthStore.ts:369-380`) gains a best-effort
  `pushLogoutCleanup()` **before** the token is cleared — `DELETE /me/push-tokens` + `deleteToken()` with a short
  timeout, never blocking sign-out. `deleteToken()` also makes a leaked token `UNREGISTERED` at FCM, which the server
  then marks dead on the next send. The offline-revoke residue (the comment at `:363-368`) is unchanged and accepted.
- **Tap:** `notificationActionPerformed` → `router.push({ name: 'creator.notifications' })`, injected exactly like
  `onSessionLapsed` (only the wiring layer touches the router singleton). Foreground `notificationReceived` → the bell
  refresh the resume path already uses (`useNotificationPoll`; exact hook read at C4.1 start). **No** per-type
  destinations, links, or `(type,data,subject)→route` resolver — **AH-103** (rows still do not navigate,
  `NotificationCenter.vue:19-20`).
- **Tests (Vitest, c8-ignored bridge injected):** unsupported on web (no listener, no call); each permission state
  (`prompt`/`granted`/`denied`) → the right sheet/row; accept → register once; decline → cool-down honoured (both sides of
  30 d); rotation → one POST; logout cleanup order (cleanup _before_ token clear, sign-out proceeds when cleanup throws);
  tap → route; foreground → refresh; iOS with `IOS_PUSH_ENABLED=false` → nothing. **BR-16:** remove the platform gate →
  the iOS-off spec fails. **BR-17:** swap cleanup after the token clear → the order spec fails.

### 5.6 Android device proof (C4.3) — real device, the chunk's acceptance

Backend: local dev API + the **one** (production) Firebase project; `FCM_*` in the local `.env`; worker running
(`queue:work`); flag armed locally; device reaches the API via `adb reverse tcp:8000 tcp:8000` (AH-100's debug-only
cleartext path). Evidence to paste: (1) soft-ask → OS prompt → token POST → `user_push_tokens` row;
(2) `push:test --validate-only` OK, then a real push displayed on the lock screen with **neutral copy**, localized in a
non-English locale; (3) a real emit path (admin approves a creator → `creator.approved` push) arrives; (4) tap from
background **and** from killed → lands on `/creator/notifications`; (5) foreground arrival → bell updates, no banner;
(6) message burst → one push (throttle) + collapse; (7) muted in-app, push still arrives; push toggle off → none for that
type, always-on types still arrive; (8) `job_posted` push default OFF, opt-in → arrives; (9) logout → row gone (cascade)
→ send → nothing delivered; (10) uninstall/`deleteToken` → send → `UNREGISTERED` → `invalidated_at` set, no retry loop;
(11) deny path → matrix state row + settings entry behave; (12) auto-init-off behaviour incl. token rotation (R-8).

---

## 6. Stage 5 — iOS (**AH-102-ios**): everything that needs the Apple account

**Separate, last, and the only place any of this appears.** Entry condition: Apple Developer Program enrolled
(**Q19 is currently "no"**).

| Step                            | What                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | Gate                                              |
| ------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------- |
| **5.0 prerequisites (Pedram)**  | enrol; App ID `com.catalystgrowth.platform` + Push capability; APNs auth key (`.p8`, Key ID, Team ID); upload the key to Firebase; register the iOS app in Firebase (needs only the bundle id — may be done earlier) and download `GoogleService-Info.plist`                                                                                                                                                                                                                                                                                 | `.p8` never enters the repo (C0.2)                |
| **5.1 spike (time-boxed)**      | on a **real iPhone**: does `@capacitor-firebase/messaging` (SPM, Capacitor CLI 8.5.2 ≥ 8.4.0 as required by `experimental.ios.spm.packageOptions`) build, and does `getToken()` return an FCM token that FCM v1 accepts (`push:test`)? **Go / no-go recorded.**                                                                                                                                                                                                                                                                              | decides A vs B                                    |
| **5.2 wiring (go)**             | `npx cap sync ios`; `packageOptions` `{"@capacitor-firebase/messaging":{"symlink":true}}` in `capacitor.config.ts`; Push Notifications capability → `App.entitlements` (`aps-environment`) + `CODE_SIGN_ENTITLEMENTS`; the three AppDelegate methods (the plugin README block); `GoogleService-Info.plist` committed (Q14); `FirebaseMessagingAutoInitEnabled=NO`; flip `IOS_PUSH_ENABLED = true`; foreground `presentationOptions` set explicitly (plugin default is `["alert","badge","sound"]`; `[]` for Android parity — **P-5, ruled**) | iOS archive builds; no `UIBackgroundModes` needed |
| **5.3 device proof**            | the §5.6 list on iOS (TestFlight/production APNs environment _and_ a debug install, to catch the sandbox/production token split)                                                                                                                                                                                                                                                                                                                                                                                                             | evidence pasted                                   |
| **5.4 fallback B (no-go only)** | `ApnsPushSender` (HTTP/2, ES256 JWT, `.p8` + Key ID + Team ID + topic), per-platform binding, additive `environment` column + the official `@capacitor/push-notifications` behind the same `PushClient`. **Untouched:** `PushEligibility`, `PushMessageFactory`, the job, the recorder, the registry's liveness query, the token endpoints, the matrix. The containment scan (§5.1) is the proof.                                                                                                                                            | —                                                 |

If the account is not live when Stage 4 closes, **AH-102 closes with Stage 5 open** (§2).

---

## 7. Test, gate and deploy discipline

### 7.1 Gates

Per commit: scoped Pest/Vitest, Pint, PHPStan/Larastan, vue-tsc, ESLint on touched packages. **At each checkpoint**
(WORKING-PROCESS §4): full backend Pest **serial with `-d memory_limit=2G`**, full Vitest on touched SPAs and
api-client, vue-tsc, ESLint, `pint --test` over all, PHPStan with `--memory-limit`, 24-locale parity, and **full
Playwright** (the preference page is E2E-traversed since AH-101's walk, and the `defaults` contract changes) with the dev
stack down and the isolated E2E DB. **CI-green-at-tip (§5.41):** each push is not complete until the run URL at the
pushed tip is cited; red CI reopens the chunk. **Break-revert (§5.35)** BR-1…BR-17 are each: mutate → watch the _right_
spec fail → revert → `git status`/`git diff` clean → re-green, verbatim output in the review. §5.34 disjoint-and-complete
negatives on every gate (liveness §3.3, dispatcher §5.4, eligibility lists, privacy §5.3).
`Notifications/Campaigns/Messaging` suites unedited except §4.4 — the AH-101-style inertness proof is a recorded
diff of tests touched.

### 7.2 Named deploy steps (Checkpoint A, in order)

**Pre-deploy reads (Pedram; none blocks planning)** — all recorded in the deploy-log entry:

1. **Worker + queue connection (Q13):** `supervisorctl status` shows `queue:work` (not Horizon); the effective
   `QUEUE_CONNECTION` (`.env.example` says `redis`).
2. **`CACHE_STORE`** effective value (the throttle needs atomic `Cache::add`; the FCM OAuth token cache needs it too).
3. **`SELECT count(*) FROM user_notification_preferences WHERE channel='push'` → expected `0`.**
4. **FCM credentials (P-1):** `FCM_PROJECT_ID` is set, and `FCM_SERVICE_ACCOUNT_JSON_B64` **is set and decodes to valid
   JSON** carrying `client_email`, `private_key` and `project_id` — read-back without printing the value (the
   `push:test --validate-only` smoke performs exactly this decode and then the OAuth exchange).
5. **Snapshot** taken and confirmed `available` (migration in this deploy).

**Deploy:** snapshot → `migrate` (one: `user_push_tokens`) → code → **`php artisan queue:restart`** (named step: a new job
class; the runbook's rule) → PHP-FPM reload (opcache) → `/up`.
**Post-migrate, pre-arm (Q15):** `SELECT has_table_privilege('engine_c_user','user_push_tokens','SELECT,INSERT,UPDATE,DELETE')`
→ must be `true`, and `USAGE` on its id sequence — whether default privileges cover new tables is unknown
(`deploy-log.md:745-756` says the fix history is not reconstructable), so it is checked, not assumed.
**Smoke:** `php artisan push:test --validate-only` (if C2.5 is accepted) proves JWT + OAuth + project id without a device.
**Flags armed at A: none.** `push_notifications_enabled` stays OFF.

**Arming (before the first push-capable store build ships — Q12):** only after the Android device proof, the validate-only
smoke, and Pedram's go; recorded in the deploy-log "Flags armed" field and the first-enable ritual added to
`production-queue-worker.md` §7.x. Kill switch = the same flag. Checkpoint B ships the web SPA (column invisible on web)
and hands the Android build to Pedram's store process.

**Deploy-log disposition:** a new `AH-102 — push notifications — PENDING` entry is opened (template at
`deploy-log.md:36-55`), **stacking on AH-101 and everything under it, all `PENDING`** — so the deploy range includes the
undeployed AH-099…AH-101 chunks with their own recorded obligations; this plan does not re-derive them. The entry exists
**before** each push; both push points are held for clearance and for Pedram.

### 7.3 Eyes-on before push (the app-visible parts)

**E1** the matrix column on the Android build (native stacked cards, always-on caption, `job_posted` OFF by default) and
**unchanged on web**; **E2** the soft-ask sheet and the settings state row (accept / decline / deny paths);
**E3** the §5.6 Android device walk. Pedram's pass is acceptance, as in AH-101.

---

## 8. Docs that move with the build

- **Docs pair at each checkpoint** (feat + docs; push held): `adhoc-changes-log.md` AH-102 entry (Proposed → Landed with
  real SHAs; **AH-102-ios** as its own entry), `deploy-log.md` entry, `docs/feature-flags.md`, `docs/services.md` (FCM
  section: what is needed, Secrets Manager path, **which Google account owns the Firebase project — Pedram supplies**, Q9),
  `docs/security/tenancy.md` §4 rows, `production-queue-worker.md` (push job + first-enable ritual), `docs/tech-debt.md`
  (R-7 `cap sync` hazard; R2 outcome if parked; the `user_push_tokens`/expired-PAT pruning interaction with the existing
  "no pruning" item at `:2801`), and a post-close **addendum** on `notification-prefs-and-ui-batch-review.md` correcting
  the "no page change" claim (§4.5).
- `push-notifications-review.md` (completion package: per-decision evidence, break-revert outputs verbatim, gate table,
  the §4.4 test-edit list, R2 outcome, device-proof evidence, an explicit **Production posture** section).
- Session close (§5.39): `RESUMPTION-TEMPLATE.md` Part 2 refreshed in the closing docs commit.
- **Out of scope, named:** AH-103 (deep links), AH-104 (account deletion — only the registry seam), AH-105 (privacy
  checklist: App Store privacy labels, Play Data Safety, privacy policy, Google DPA — **Q16, Pedram's**), AH-102b
  (`push_deliveries`), agency push, silent/data-only pushes, badge counts, the mail-inside-transaction tech-debt item
  beyond what `$afterCommit` covers for the push job.

---

## 9. Risk register

Carried from the inventory (R-1…R-6) with their ruled treatment, plus the new ones.

| ID       | Risk                                                                                                                                  | Treatment in this plan                                                                                    | Residual                                                                         |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| **R-1**  | Lock-screen privacy                                                                                                                   | Allowlist factory; sentinel tests ×14 types ×24 locales; neutral copy (Q6)                                | Campaign/agency _names_ are agency-chosen text on a lock screen — accepted by Q6 |
| **R-2**  | FCM outage / bad credential → silent non-delivery                                                                                     | `ConfigError` is an alarm, not a retry loop; `validate_only` smoke; in-app list unaffected                | No delivery tracking until AH-102b                                               |
| **R-3**  | Default lock-in (a later flip silently overrides stored state)                                                                        | Q3 ruled: push ON, `job_posted` OFF; override-map pin test; architecture scan                             | Any future per-type override carries the same hazard — pinned by the same test   |
| **R-4**  | A signed-out device whose revoke never arrived keeps receiving pushes until the PAT lapses (≤ 90 d)                                   | `pushLogoutCleanup()` before token clear; `deleteToken()` makes the token `UNREGISTERED`                  | The offline-both-calls-fail case stays open (`useAuthStore.ts:363-368`)          |
| **R-5**  | Shared-device token reassignment: whoever holds a token can move it                                                                   | Upsert-by-token is the ruled behaviour; unique(token)                                                     | Worst case a victim loses pushes until re-registration; low                      |
| **R-6**  | iOS-via-Firebase under SPM unproven                                                                                                   | Deferred to Stage 5 spike; fallback B contained to the sender half (§5.1, §6)                             | Resolved only on hardware                                                        |
| **R-7**  | `cap sync` (full) before Stage 5 adds the Firebase SDK to iOS without a plist                                                         | Android-only sync; `Package.swift` not regenerated; `tech-debt.md` row; no iOS build ships before Stage 5 | A contributor running a full sync                                                |
| **R-8**  | FCM auto-init disabled (consent-first) may not behave across token rotation                                                           | Device-proof item (11/12); fallback to default auto-init recorded for AH-105                              | Unverified until C4.3                                                            |
| **R-9**  | The deploy range includes undeployed AH-099…AH-101                                                                                    | Deploy-log entry stacks and cites theirs; not re-derived here                                             | Deploy-day coupling is Pedram's timing call                                      |
| **R-10** | CI flake noise at pushes (`creator-detail.spec.ts:64`; also seen: `creator-wizard-happy-path.spec.ts:91`, `admin-sign-in.spec.ts:69`) | C0.3 bounded rider for the first                                                                          | The other two are recorded only                                                  |
| **R-11** | Hot-path change in `notify()`                                                                                                         | try/catch; `$afterCommit`; flag first; suites unedited; BR-10/11                                          | The one place a bug is highest-impact                                            |

---

## 10. Plan-review questions

Each has a recommendation; none blocks the stage work except where stated.

- **P-1** _(blocks Checkpoint A deploy, not the build)_ — How does a Secrets Manager value reach the box for a **file**
  credential (`06-INTEGRATIONS.md` §1.2 says the config layer reads secrets at boot; nothing documents file
  materialisation)? Plan assumes `FCM_SERVICE_ACCOUNT_PATH`; the alternative is a base64 JSON env var, which fits the
  existing env-based pattern and removes the worker-user file-permission step. **Recommend** whichever you already use for
  multi-line secrets — tell me which and I will adopt it in C2.1.
- **P-2** Soft-ask trigger and cool-down: pending creators **and** approved creators never asked, once per 30 days. OK?
- **P-3** `.gitignore` guard (C0.2) is planned as a Stage-0 commit rather than done now, because the kickoff said no code
  or config before plan review. It is one three-line change; say the word and it lands immediately, separately.
- **P-4** Android push notification icon: the plugin wants a white-on-transparent glyph. Do you have a monochrome mark, or
  should I derive one from the existing app-icon sources under `apps/main/assets`? Without it Android shows the default
  icon (a white square on many devices) and E3 will flag it.
- **P-5** iOS foreground presentation `[]` (in-app bell only, Android parity) vs the plugin default
  (`alert, badge, sound`) — iOS stage, decide then; recommended `[]`.
- **P-6** Include the `push:test` operator command (C2.5)? It adds an artisan surface but is what makes the credential
  smoke test, the device proofs and the post-deploy verification possible without arming the global flag. **Recommend yes.**
- **P-7** The §5.32 reinterpretation of "before notify()'s early return" (§5.4): run the leg on both paths with the ulid
  available vs literal placement. **Recommend as planned.**
- **P-8** The predicate `pushChannelAvailable()` (native ∧ Android-or-flag) in place of bare `isNativePlatform()` (§4.5).
- **P-9** No (type, channel) combination validation on PATCH (AH-101 precedent) — the dispatcher is authoritative.
- **P-10** Two held pushes (A after Stage 2, B after Stage 4) as in §2, rather than one at the end?

See §11 for the rulings.

---

## 11. Dispositions (plan review, 2026-10-10)

Plan `d4edffb4` reviewed and **cleared**. P-rulings:

| P    | Ruling                                                                                                                | Effect on the plan                                                                                                                                                                                                        |
| ---- | --------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P-1  | **Base64 env var** `FCM_SERVICE_ACCOUNT_JSON_B64`, decoded in the FCM client; drop the file-path and permission steps | §5.1 config rewritten; §7.2 read 4 is now "set and decodes to valid JSON"; the worker-user file-permission step and the `FCM_SERVICE_ACCOUNT_PATH` env name are gone from the plan body (§10 keeps the question as asked) |
| P-2  | Yes                                                                                                                   | Soft-ask: pending **and** approved-never-asked creators; 30-day cool-down (§5.5)                                                                                                                                          |
| P-3  | Stage 0                                                                                                               | C0.2 stays a Stage-0 commit                                                                                                                                                                                               |
| P-4  | Derive the monochrome mark from the AH-100b icon sources, **shown at E1**                                             | C4.2 derives a white-on-transparent glyph from `apps/main/assets` (`icon-foreground.png`, `icon-only.png`, `source/`); the glyph is part of the E1 eyes-on, not a surprise at E3                                          |
| P-5  | `[]` — decided now                                                                                                    | iOS foreground `presentationOptions: []` (in-app bell only; Android parity); no longer a question for Stage 5                                                                                                             |
| P-6  | Yes                                                                                                                   | C2.5 `push:test` is in                                                                                                                                                                                                    |
| P-7  | As planned                                                                                                            | `notify()` runs the push leg on both paths (§5.4)                                                                                                                                                                         |
| P-8  | Accepted                                                                                                              | `pushChannelAvailable()` (§4.5)                                                                                                                                                                                           |
| P-9  | Accepted                                                                                                              | No combination validation on PATCH (§4.2-6)                                                                                                                                                                               |
| P-10 | Yes                                                                                                                   | Two held pushes, A after Stage 2, B after Stage 4 (§2)                                                                                                                                                                    |

**Standing:** all disciplines as written; both pushes held; **Checkpoint A stops for clearance.**

---

## Not verified (carried and new)

- FCM v1 response codes, collapse/TTL/priority semantics, App Store guideline 4.5.4 wording — vendor docs from memory;
  re-checked against the live API at C2.1 / the Firebase console.
- `@capacitor-firebase/messaging` behaviour: only the 8.5.2 tarball (README, Android manifest, `Package.swift`) was read —
  notably it declares `POST_NOTIFICATIONS` itself, so the manual manifest line the inventory expected is likely
  unnecessary (confirm in the merged manifest at C4.2); runtime behaviour, auto-init-off and iOS-under-SPM are unproven.
- Production: effective `QUEUE_CONNECTION`, `CACHE_STORE`, default privileges for `engine_c_user`, how secrets become files.
- A whole-suite PHP 8.5 run (R1's "aborts collection" claim did not reproduce — inv §6.1).
- Pennant per-user scope resolution (not needed: the flag is global).
