# AH-102b — admin observability for the creator app — change inventory

Read-only inventory. No code, config or migration was written or changed. **Plan-pause: no build plan and no
code until this document is reviewed and cleared.**

> **This is a snapshot of the tree at `b21b7b2f`, not a live claim** — the same convention the AH-101 / AH-102
> inventories follow. Line numbers are real at that SHA and will drift. Where something could not be measured it
> says so under **Not verified** rather than asserting it.

**Base SHA:** `b21b7b2f` — `HEAD` and `origin/main` agree (`git rev-list --left-right --count origin/main...HEAD`
returned `0 0`); pushed, **not deployed** (`docs/runbooks/deploy-log.md`: AH-099 … AH-102 are all `PENDING`).
**CI at that tip (§5.41):** run [38174364033](https://github.com/pedram-kh/Engine/actions/runs/38174364033),
conclusion **success, all four jobs green** (Frontend, Backend, E2E admin, E2E main), read at commit time. The admin
E2E job was green **on retry with two flaky specs** — see §4; the second one is new.
**Working tree:** clean at the start.
**Paths:** backend paths are relative to `apps/api/` unless they start with `apps/`, `packages/` or `docs/`;
`Modules/…` means `apps/api/app/Modules/…`. Admin SPA paths are relative to `apps/admin/src/`.
**Method note:** the CI flake tally (§4) was read from the job logs of 19 `main` runs via `gh`, not from chat or
from the tech-debt prose. The Sanctum behaviour in §1.2 was read from `vendor/laravel/sanctum` (v4.3.2, per
`composer.lock`). Nothing was run against production; production state is marked **not verified** wherever it
matters.

---

## 0. What the review should know first

1. **The seam is as clean as promised, with one gap.** `PushDeliveryOutcome` already carries everything a
   `push_deliveries` row needs **except the notification ULID** (it is in `PushMessage->data`, not in the outcome).
   Adding it is one optional DTO field plus one named argument at the job's call site — no logic change (§1.1).
2. **The recorder is not contained today.** `PushDeliveryRecorder::record()` has no `try/catch`
   (`Modules/Notifications/Push/PushDeliveryRecorder.php:27-56`), and it runs **after** the push has been sent
   (`SendPushNotificationJob.php:84-100`). An exception thrown from `record()` fails the job → retry → **the user
   gets the push twice.** Today that path is only a `UserPushToken::find` + an `update`; the moment 102b adds an
   insert it becomes a real risk. Containment is the central write-path requirement (§2.5).
3. **The scheduler may not exist in production.** The resumption template carries a standing **BLOCKER —
   "scheduler existence UNVERIFIED… assume NO scheduled command runs in production"**
   (`docs/reviews/RESUMPTION-TEMPLATE.md:1561-1570`). A retention policy that only runs on the scheduler might
   never run. §2.2 designs around that rather than assuming it away.
4. **Half B needs no new tracking writes — and the best "ever signed in on the app" source is not the PATs.**
   Sanctum already stamps `last_used_at` on every authenticated bearer request, but creator-app tokens are
   **deleted** on logout, password reset and lockout, so PATs alone forget everyone who signed out. The durable
   record is the **audit log**: `auth.login.succeeded` rows carry `metadata.guard = 'creator_app'` for the token
   door only (§1.2). Recommended: derive from PATs + push tokens + audit, add nothing to the login path (§3.1).
5. **The `admin-sign-in.spec.ts` "two flakes" (`:69`, `:106`) are one flake.** `:69` is where the test is declared;
   `:106` is the assertion inside it that fails. **17 of the 18 `main` CI runs that ran the admin E2E job since
   2026-10-06 flaked it, identically,** and `git diff 4d468406..HEAD -- apps/admin` is empty. It is far past any
   threshold (§4). **New at the tip:** the _other_ admin spec, `admin-mandatory-mfa-enrollment.spec.ts:82`, flaked
   for the first time in this window (§4).
6. **Production has none of this yet.** Nothing from AH-099 … AH-102 is deployed, so there are no `creator-app:*`
   tokens, no `user_push_tokens` table, and no push data in production. Half B will show "never installed" for
   everyone until the app ships; half A's table starts empty, and while `push_notifications_enabled` is OFF the
   recorder is never reached (`PushDispatcher.php:82-84`).

---

## 1. What exists already

### 1.1 The recording seam (half A)

| What                                          | Where                                                           | Fact                                                                                                                                                                                                                                                                                           |
| --------------------------------------------- | --------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| The one call site                             | `Modules/Notifications/Jobs/SendPushNotificationJob.php:89-100` | `$recorder->record(new PushDeliveryOutcome(...))`, called once per attempt, **after** `$sender->send()` (`:84`).                                                                                                                                                                               |
| The recorder                                  | `Modules/Notifications/Push/PushDeliveryRecorder.php:27-56`     | Order today: (1) on `TokenInvalid`, `find` the token row and call `PushTokenRegistry::invalidate()` (`:29-35`); (2) build a context array (`:37-47`); (3) one `Log::*` line per outcome (`:49-56`). **No try/catch anywhere in the method.** Docblock `:11-17` names this as the AH-102b seam. |
| The outcome DTO                               | `Modules/Notifications/Push/PushDeliveryOutcome.php:13-27`      | `tokenId`, `userId`, `platform`, `notificationType`, `outcome`, `attempt`, `code?`, `detail?`, `httpStatus?`, `final` (true only when a `Retryable` has run out of attempts). Docblock `:6-10`: deliberately carries **no device token and no message text**.                                  |
| The five outcomes                             | `Modules/Notifications/Push/PushOutcome.php`                    | `Delivered`, `TokenInvalid`, `Retryable`, `ConfigError`, `Rejected`.                                                                                                                                                                                                                           |
| `detail` is already scrubbed                  | `Modules/Notifications/Push/Fcm/FcmPushSender.php:248-255`      | The device token is replaced with `[token]` and the text is capped at `DETAIL_MAX_CHARS = 200` (`:42`). Safe to persist, but the recorder should still cap it itself.                                                                                                                          |
| `notification_ulid` is **not** in the outcome | `Modules/Notifications/Push/PushMessageFactory.php:63-66`       | It lives in `PushMessage->data['notification_ulid']`, present only when the in-app row was written. The job holds the message (`$this->message`), so passing it is a one-argument change.                                                                                                      |
| Retry shape                                   | `SendPushNotificationJob.php:54,56,102-104`                     | `$tries = 3`, backoff `[30, 120]`; a non-final `Retryable` is recorded **and** released, so one job can write up to **3 rows**.                                                                                                                                                                |

**What the job does _not_ record (silent today, and would stay silent):**

- **Token gone / invalidated / bearer revoked or expired / user soft-deleted between dispatch and run** —
  `findLive()` returns null and the job `return`s (`SendPushNotificationJob.php:78-82`). No outcome, no call.
- **Everything before the job is dispatched** — ineligible type, `$suppressPush`, flag OFF, push preference off, no
  live tokens, message throttle, factory returns null (`PushDispatcher.php:74-105`). These are early `return`s; no
  job exists to record anything.
- **An unexpected exception** — only `failed()` runs (`SendPushNotificationJob.php:111-119`), which logs and does
  not call the recorder.
- **`push:test`** — bypasses the job and the recorder on purpose (`app/Console/Commands/PushTest.php:43`), so
  operator probes will never appear in the table. That is the right behaviour.

So `push_deliveries` answers "what happened to each send attempt that reached FCM", not "how many pushes were
suppressed". The admin UI must not imply otherwise.

### 1.2 The sources for half B, with zero new writes on the hot path

**`user_push_tokens`** (`database/migrations/2026_10_10_100000_create_user_push_tokens_table.php:40-67`;
`Modules/Notifications/Models/UserPushToken.php`):

| Column                                           | Gives us                                           | Limit                                                                                                                                                                                                                               |
| ------------------------------------------------ | -------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `platform` (`ios`\|`android`)                    | Platform of a _push-registered_ device.            | Exists only after the app obtained an FCM token (OS permission granted).                                                                                                                                                            |
| `app_version` (≤ 32)                             | The **only** place the app version is stored.      | Sent only by push registration (`apps/main/src/core/platform/push/usePushRegistration.ts:21-24`; `RegisterPushTokenRequest.php:rules`). A creator who declined push has no version on record.                                       |
| `last_seen_at`                                   | **Last push registration**, not "last seen".       | Set only by `PushTokenRegistry::upsert` (`PushTokenRegistry.php:136-160`, `:156`), i.e. a register/upkeep call — at most once per 24 h (`pushRegistration.ts:32`, `UPKEEP_INTERVAL_MS`). Must be labelled "last push registration". |
| `invalidated_at` + `invalidation_reason` (≤ 64)  | Dead-token evidence: e.g. `provider_unregistered`. | Written by `PushTokenRegistry::invalidate()` (`:89-95`). Cleared on re-register (`:157-158`).                                                                                                                                       |
| `personal_access_token_id` (FK, cascade, unique) | Binds the push token to exactly one login.         | The row **vanishes** with its PAT (logout, password reset, lockout) — the FK cascade is the revocation mechanism.                                                                                                                   |
| `token`                                          | —                                                  | **Never display.** `$hidden` (`UserPushToken.php:67`).                                                                                                                                                                              |

**The `creator-app:{ios|android}` personal access tokens** (stock Sanctum table,
`database/migrations/2026_05_07_003823_create_personal_access_tokens_table.php:15-22`):

- **Minted** one per login at `Modules/Identity/Services/AuthService.php:236`
  (`createToken(self::TOKEN_NAME_PREFIX.$platform)`; prefix `'creator-app:'` at `:82`). `name` carries the platform;
  `created_at` is the sign-in time; `tokenable_*` is the user (no morph map is registered, so `tokenable_type` is the
  `User` FQCN — query via `$user->tokens()`, not by string).
- **`last_used_at` is a free "last seen"**: `CreatorTokenGuard.php:80-82` calls Sanctum's `updateLastUsedAt` on
  every authenticated bearer request, and Sanctum 4.3.2 writes it **unconditionally, per request**
  (`vendor/laravel/sanctum/src/Guard.php:162-173`; `config('sanctum.last_used_at', true)` is passed in at
  `Modules/Identity/IdentityServiceProvider.php:66`). The write already exists; half B reads it.
- **Expiry is not in the row.** `expires_at` is unset; expiry is `created_at + sanctum.expiration` (129 600 min =
  90 days, `config/sanctum.php:75`) evaluated at use time. Expired rows are **not pruned**
  (`docs/tech-debt.md:2801`: "No pruning of expired tokens… Resolution: schedule `sanctum:prune-expired`"). The
  liveness rule is already encoded once, in `PushTokenRegistry::liveQuery()` (`PushTokenRegistry.php:114-134`):
  token not invalidated, `expires_at` null-or-future, `created_at > now − expiration`, user not soft-deleted
  (`users.deleted_at`, `:121`). Half B must apply the **same** rule.
- **Deleted on:** token logout (`AuthService.php:456-462`, one token), password reset
  (`PasswordResetService.php:113`, `tokens()->delete()`), lockout/suspension (`AccountLockoutService.php:90`).
  Consequence: after any of those, PATs say "never installed" about someone who did install.
- **No device identity.** The token name is per-platform, not per-device; a phone that signs in twice holds two
  PATs, and a reinstall without sign-out leaves a ghost row until expiry (`docs/tech-debt.md:2802`, item 2; the
  AH-102 walk's item 15). The honest unit is a **sign-in**, not a device.

**What `users` has (and why it is not enough):** `last_login_at` / `last_login_ip` are stamped by **both** doors
(web `AuthService.php:182-184`, token `:239-241`), so they cannot say "app".

**The durable record of app sign-ins — the audit log.** `AuthService::issueToken()` dispatches `UserLoggedIn` with
`guard: 'creator_app'` (`AuthService.php:243-250`); `WriteAuthAuditLog::handleUserLoggedIn` writes
`auth.login.succeeded` with `metadata: {guard, mfa}`, `ip`, `user_agent`
(`Modules/Identity/Listeners/WriteAuthAuditLog.php:49-62`). Token logout writes `auth.logout` with
`metadata.guard = 'creator_app'` (`AuthService.php:464-469`, `WriteAuthAuditLog.php:64-72`). `audit_logs` is
immutable, has indexes on `(actor_type, actor_id)`, `action`, `created_at`
(`database/migrations/2026_05_07_020000_create_audit_logs_table.php:65-69`), and `metadata` is a `json` column
(`:56`) — filterable, not indexed. These rows exist from the first app login, survive logout/reset/lockout, and are
not pruned by anything. The existing viewer already filters on `action` and `actor_id`
(`Modules/Admin/Http/Controllers/AdminAuditLogController.php:50-58`) but not on `metadata.guard`.

**How far these alone get us (no new writes):**

| Admin question                                  | Answerable?             | From                                                                                                                            |
| ----------------------------------------------- | ----------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| Is this creator signed in on the app right now? | Yes                     | ≥ 1 live `creator-app:*` PAT (liveness rule above)                                                                              |
| Platform(s)                                     | Yes                     | PAT `name` suffix (every sign-in); `user_push_tokens.platform` (push-registered only)                                           |
| Last seen                                       | Yes, for live sign-ins  | `max(personal_access_tokens.last_used_at)`                                                                                      |
| Signed in at                                    | Yes                     | PAT `created_at`                                                                                                                |
| Ever signed in on the app, first/last time      | Yes                     | `audit_logs` `auth.login.succeeded` + `metadata.guard='creator_app'`                                                            |
| Signed out / reset / locked since               | Partly                  | absence of a live PAT + an audit login row; the _reason_ is not stored (an `auth.logout` row exists only for explicit sign-out) |
| App version                                     | Only if push-registered | `user_push_tokens.app_version`                                                                                                  |
| Installed but never signed in                   | **No**                  | nothing is written until a login succeeds                                                                                       |
| Installed on a reinstalled/ghost device         | **No**                  | no device id exists                                                                                                             |

### 1.3 Admin SPA list patterns to copy

All three live in `apps/admin/src/modules/`. The shared shape: a `ref` for filters, `tableOptions = { page: 1,
itemsPerPage: 25 }`, a `load()` that calls an `admin…Api.list()` with `page`/`per_page`, a `watch` that resets
`page = 1`, and Vuetify `v-data-table-server` with `@update:options`.

| Surface           | Component                                                                                                                                                                                                                                                                                         | Filters                                                                                                                              | Pagination (server side)                                                                                                                                                                                                                                                                    |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **All Creators**  | `creators/pages/AllCreatorsPage.vue` — chip filters `:134-186` (status, KYC, "connected"), `tableOptions` `:44`, `load()` `:88-110`, `watch([…])` `:112-115`, `v-data-table-server` `:199-246`. API: `creators/api/creators.api.ts:44-65` (`AdminCreatorListResponse`, `AdminCreatorListParams`). | Three independent AND-composing `v-chip-group`s (`mandatory`), i18n keys reused from sibling pages.                                  | Backend `Modules/Creators/Http/Controllers/Admin/AdminCreatorController.php:78-174`: **offset** `paginate()` (`:146`), `per_page` clamped 1–100 default 25 (`:82-83`), unknown filter value → `whereRaw('1 = 0')` (`:95`, `:109`, `:141`), response `meta.{total,page,per_page,last_page}`. |
| **Agencies list** | `agencies/pages/AgencyListPage.vue` — status chips, a debounced (300 ms) `v-text-field` search `:100-109`, `v-data-table-server` `:166-218`.                                                                                                                                                      | status chips + `search` (`like %needle%`).                                                                                           | `Modules/Admin/Http/Controllers/AdminAgencyController.php:59-98`: offset `paginate()` (`:88`), same clamp (`:71`), `meta` block, and a `?for=select` unpaginated projection (`:63`).                                                                                                        |
| **Audit log**     | `audit/pages/AuditLogPage.vue` — cursor stack `:32-73` (`nextCursor`, `prevCursor`, `cursorHistory`).                                                                                                                                                                                             | action, actor, agency, subject ulid, date range — "every filter targets an INDEXED column" (`AdminAuditLogController.php` docblock). | `AdminAuditLogController.php:40-95`: **`cursorPaginate()`** (`:80`) because OFFSET scans linearly on a high-volume append-only table; default 50, max 100 (`:44`); `next_cursor`/`prev_cursor` tokens.                                                                                      |

**Rule that falls out:** the creators-with-app list is a bounded, human-sized set → copy **All Creators** (offset).
The delivery log is an unbounded append-only stream → copy **Audit log** (cursor). Both use the same auth stack:
`Route::prefix('admin')->middleware(['auth:web_admin', EnsureMfaForAdmins::class])` (`Modules/Admin/Routes/api.php:50-53`)
plus a policy (`authorizeAny($request, 'viewAny')`, `AdminAgencyController.php:61`) or the inline platform-admin
check (`AdminAuditLogController.php:135-145`). Every new admin route is added to the cross-tenant allowlist in
`docs/security/tenancy.md` § 4 (rows at `:197-201` are the precedent; `Modules/Admin/Routes/api.php:44-46` says so).

**Nav:** `core/nav/navItems.ts` — `Creators` group (`:59-76`: approvals, KYC queue, All Creators) and `Operations`
group (System health, Horizon). Routes: `modules/creators/routes.ts:38-43` for `/creators/all`. Both admin
Playwright specs land on the dashboard inside `AdminLayout`, so a nav change is an **E2E-traversed surface**
(WORKING-PROCESS §4: full admin Playwright before push).

---

## 2. Half A — delivery tracking

### 2.1 `push_deliveries` — schema proposal

One **append-only** row per recorder call (per attempt). Append-only mirrors `notifications` / `audit_logs`
(`created_at` only, no `updated_at`).

| Column               | Type                                                      | Notes                                                                                                                                                                                                                                                                                                                                                                                                     |
| -------------------- | --------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`                 | `bigIncrements`                                           |                                                                                                                                                                                                                                                                                                                                                                                                           |
| `user_id`            | `unsignedBigInteger`, **FK → `users`, `cascadeOnDelete`** | Recipient. Cascade (not `restrict` like `notifications`): a delivery row holds no content and no secret, and AH-104's erasure then cleans it for free. `users` is soft-deleted, so the cascade fires only on a genuine hard delete. **Q4.**                                                                                                                                                               |
| `user_push_token_id` | `unsignedBigInteger` nullable, **no FK**                  | Soft reference. A real FK would need `ON DELETE SET NULL`, and every logout cascade-deletes a `user_push_tokens` row — that would trigger a scan/update on this fast-growing table on the logout path. The row is deleted with its PAT by the DB-level cascade (migration `:49-52`), so the id is only meaningful while the device is live; the denormalised `platform` below is what survives. No index. |
| `platform`           | `string(16)`                                              | `ios`\|`android`, denormalised from the outcome.                                                                                                                                                                                                                                                                                                                                                          |
| `notification_type`  | `string(64)`                                              | The `NotificationType` value (same width as `notifications.type`).                                                                                                                                                                                                                                                                                                                                        |
| `notification_ulid`  | `char(26)` nullable, **no FK**                            | Joins to `notifications.ulid` (unique) for the in-app cross-reference. Null when the in-app row was not written (user muted in-app but still gets push — `NotificationService.php` push leg is independent). No FK: that row may be absent by design.                                                                                                                                                     |
| `outcome`            | `string(16)`                                              | `delivered`\|`token_invalid`\|`retryable`\|`config_error`\|`rejected` (the `PushOutcome` value).                                                                                                                                                                                                                                                                                                          |
| `attempt`            | `unsignedSmallInteger`                                    | 1…3.                                                                                                                                                                                                                                                                                                                                                                                                      |
| `final`              | `boolean` default false                                   | Straight from the DTO: true only for an exhausted `Retryable`. "Terminal" = `outcome <> 'retryable' OR final`.                                                                                                                                                                                                                                                                                            |
| `code`               | `string(64)` nullable                                     | `UNREGISTERED`, `QUOTA_EXCEEDED`, `http_503`, …                                                                                                                                                                                                                                                                                                                                                           |
| `http_status`        | `unsignedSmallInteger` nullable                           |                                                                                                                                                                                                                                                                                                                                                                                                           |
| `detail`             | `string(200)` nullable                                    | **Non-`delivered` outcomes only**; already scrubbed + capped at the sender (§1.1), capped again in the recorder.                                                                                                                                                                                                                                                                                          |
| `created_at`         | `timestampTz` default `now()`                             | The attempt time (the recorder runs right after the send).                                                                                                                                                                                                                                                                                                                                                |

**Indexes (three, deliberately — every index is a hot-path write cost):**

- `(user_id, created_at)` — "recent deliveries for this creator".
- `(outcome, created_at)` — the failures feed and the outcome tiles.
- `(created_at)` — the prune and any window count.

No `token` column, no title/body, no `data` bag: the row cannot leak either, by construction (the DTO already has
neither). Postgres in production, SQLite in tests — nothing here is engine-specific.

**What the recorder writes, per outcome** (one row per call; the existing `invalidate` and `Log` behaviour stays):

| Outcome                  | Row                                                                          | Other effect (unchanged)                             |
| ------------------------ | ---------------------------------------------------------------------------- | ---------------------------------------------------- |
| `Delivered`              | `delivered`, `code` null, `http_status` 200 (accepted by FCM), `detail` null | `Log::info push.delivery`                            |
| `TokenInvalid`           | `token_invalid`, `code` `UNREGISTERED`/`INVALID_ARGUMENT`, `detail`          | token invalidated (`PushDeliveryRecorder.php:29-35`) |
| `Retryable`, attempt < 3 | `retryable`, `final=false`                                                   | job releases with backoff                            |
| `Retryable`, exhausted   | `retryable`, `final=true`                                                    | none further                                         |
| `ConfigError`            | `config_error` — **the channel-down alarm**, the row an admin most needs     | `Log::error`                                         |
| `Rejected`               | `rejected`                                                                   | `Log::error`                                         |

**Honest meaning of `delivered`:** FCM _accepted_ the message. It is not a device receipt; FCM v1 gives no
per-message delivery confirmation to the server (vendor behaviour, from memory — **not verified in this repo**). The
UI copy must say "accepted by FCM".

**One change outside the recorder (Q2):** add `?string $notificationUlid = null` to `PushDeliveryOutcome` and pass
`$this->message->data['notification_ulid'] ?? null` at `SendPushNotificationJob.php:89-100`. It is the only edit to
the job file and changes no logic. Without it the in-app cross-reference is impossible.

### 2.2 Retention — designed in, and robust to "no scheduler"

**Why it matters:** the table gains up to 3 rows per (live device × push). Sizing is unmeasured — production has no
push data — so as an _illustration only_: 1 000 active creators × 3 pushes/day × ~1.2 devices ≈ 3 600 rows/day ≈
330 k rows per 90 days (order of tens of MB with the three indexes). That is not alarming; the point is that without
a prune it grows forever, and the admin queries (all index-backed) degrade slowly rather than break.

**Proposal:**

- **Window:** 90 days (**Q5**) — long enough to answer "did she get the invite last quarter?", short enough to
  bound the table. The window is a command option, not a constant.
- **Command:** `push:prune-deliveries` — `--days=90`, `--dry-run` (counts, deletes nothing), `--limit=N` (chunked
  delete, oldest-first by `id`, ≤ 5 000 per statement, loop until the cutoff or the limit), idempotent, loud on a bad
  `--days`. This is the command pattern in WORKING-PROCESS §9 (`--dry-run`, `--limit`, oldest-first, count summary).
  It deletes only from `push_deliveries`. **Row-removing path → §5.34 over-reach negatives** even though the rows are
  telemetry: a row inside the window survives; a row exactly at the cutoff survives; nothing outside the table is
  touched; `--dry-run` deletes nothing; `--limit` is honoured.
- **Where it runs — and the blocker.** The scheduler is `bootstrap/app.php:34-49` (`->daily()` for the three existing
  commands). `docs/runbooks/production-queue-worker.md` §7 documents the `schedule:run` cron, **but production was
  deployed "with a different structure (colleague-managed)" and the cron is unconfirmed**
  (`RESUMPTION-TEMPLATE.md:1561-1570`; also `deploy-log.md:829, 979`). The same dependency already strands
  `sanctum:prune-expired` (`tech-debt.md:2801`).
  - **Plan:** register `push:prune-deliveries` on the schedule (`->daily()`) **and** make it manually runnable, and
    do **not** claim retention works until the scheduler is verified. 102b's deploy-log entry carries a named
    obligation: _"`crontab -l` / `supervisorctl status` read from prod; if no scheduler, run the command by hand and
    open the infra chunk."_
  - **Make a dead scheduler visible instead of silent:** the admin delivery page footer shows `total rows` and the
    `oldest row` date (one `count(*)` + one `min(created_at)`). "Oldest row: 140 days ago" is a self-evident alarm.
  - **Not** proposed: an opportunistic prune inside the push job. That is a delete on the hot path of a job whose
    failure duplicates pushes (§2.5).
- **Mode A trigger:** a scheduled job. This is a stop-gate item and goes through the full loop.

### 2.3 Per-channel honesty

| Channel    | What is knowable                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           | v1 admin treatment                                                                                                                                             |
| ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Push**   | Real per-attempt outcomes from the recorder (§2.1): accepted by FCM, token dead, transient failure and exhaustion, config alarm, rejected.                                                                                                                                                                                                                                                                                                                                                                 | The delivery log and tiles.                                                                                                                                    |
| **In-app** | The `notifications` row **is** the delivery: `created_at`, and `read_at` is the one post-insert mutation (`Modules/Notifications/Models/Notification.php:26-38`). Muting in-app writes **no** row (`NotificationService::notify()`), so "muted" is not a recorded event; it is derivable only from the _current_ preference. Index `(recipient_user_id, created_at)` serves a per-user feed; there is **no** index for a global per-type count (`2026_06_06_100000_create_notifications_table.php:86-91`). | Per-user only: the delivery row's `notification_ulid` shows "in-app: written / read" via a join to `notifications.ulid` (unique). No global in-app aggregates. |
| **Email**  | See below.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | "Not tracked" — stated on the page, not hidden.                                                                                                                |

**Email, precisely.**

- **Production transport: not established by anything in the repo.** `config/mail.php` defaults to `log`
  (`MAIL_MAILER`, `:19`); `.env.example:106-113` points at a local Mailpit (`smtp`, `127.0.0.1:1025`); the integration
  docs still say the provider is **open** ("lean SES + Postmark", `docs/services.md:157,189`;
  `docs/06-INTEGRATIONS.md:414-423`); the deploy log never records `MAIL_MAILER`. Whether prod is SES, SMTP relay,
  or even `log` is **Q7 for Pedram / the colleague who manages the box** (read the effective `MAIL_MAILER`).
- **What is knowable without provider webhooks:** Laravel fires `MessageSent` when the _transport accepted_ the
  message (SMTP 250 / provider API 200) — **not** inbox delivery, not a bounce, not a complaint. Failures of the
  mail job land in `failed_jobs`. That is all.
- **Why even that is not v1:** mail is sent from **12 files / 44 `Mail::` call sites** (`Modules/Notifications/Services/NotificationService.php`,
  `Messaging/Services/DebouncedMessageMailer.php`, `Campaigns/…`, `Creators/Services/IncompleteCreatorNudgeService.php`,
  `Agencies/Services/AgencyInvitationService.php`, …). A mailable carries no `NotificationType`, so a `MessageSent`
  listener could not attribute a send to (user, type) without touching every site. The only existing instrumentation
  is log lines (`DebouncedMessageMailer::logEmission()`, `DebouncedMessageMailer.php:161`;
  `CampaignApplicationNotifier`) — no table.
- **Bounce / complaint / delivery feedback needs a provider webhook** (SES→SNS, or Postmark webhooks): an inbound
  signed endpoint, a signature verifier, a suppression-list decision, and an `email_events` table. **That is scoped
  OUT of 102b.** If wanted, it is its own chunk (call it AH-102c) once the transport is known.

### 2.4 Admin surface — minimal v1

Placement (**Q8**): **Operations → "Push deliveries"** (beside System health; it is an operator surface, not a creator
record). `GET /api/v1/admin/push-deliveries`, platform-admin only, behind the existing admin stack.

- **Outcome tiles** for a window (24 h / 7 d / 30 d; default 7 d): delivered, token-invalid, retryable-final,
  config-error, rejected — one grouped query on `(created_at)`. A non-zero **config-error** tile is styled as an alarm
  (it means the channel is down; `PushOutcome.php` docblock). **No** new admin notification type is emitted (the
  alerts feed is an empty-by-default shell, `AdminAlertsController.php:37-40`; a new type is new scope — **Q9**).
- **A cursor-paginated table** (copy the Audit-log page): time, creator (name + email, linking to the creator
  detail), platform, notification type, outcome chip, code, `attempt/final`, an "in-app: read/unread/none" cell
  (join via `notification_ulid`). Filters: outcome (with a **"Failures"** preset = `token_invalid`, `config_error`,
  `rejected`, exhausted `retryable`), notification type, creator, date range. Each filter is an indexed predicate.
  **Failures are surfaced by default-filter preset, not buried.**
- **Per-user view** = the same page filtered by creator, linked from the app-usage page (§3.2). No new card on
  `CreatorDetailPage.vue` (a ~900-line file; avoid touching it in a chunk about observability).
- **Footer:** total rows + oldest row (§2.2).
- **Deferred:** per-type × outcome matrix (the type filter + tiles cover v1), CSV export, any chart.
- **Never shown:** the device token, message text, any `data` — none is stored.

### 2.5 Write-path risk and containment

The insert runs inside the push job, after the send. The danger is not data loss; it is **duplicate pushes**:

> `send()` succeeded → `record()` throws → `handle()` throws → the queue retries (`$tries = 3`) → the user is pushed
> again, up to three times.

**Containment (structural intent: telemetry must never be able to fail, retry, or re-send a push):**

1. **The whole of `record()` is contained**, not just the insert: each of the three steps — invalidate, insert, log —
   in its own `try/catch (Throwable)` so a failure in one cannot skip another. Today none is contained
   (`PushDeliveryRecorder.php:27-56`), including the existing `find` + `invalidate`; 102b closes that too.
2. **Order:** `invalidate` first (it changes behaviour and must not be skipped), then the insert, then the log line.
3. **A failed insert logs `push.delivery_record_failed`** (`token_id`, `user_id`, outcome, exception class — never the
   token, never the message) and returns. It does **not** rethrow and does **not** call `report()` unguarded (the
   nested `Log::error` is itself wrapped).
4. **No transaction poisoning:** on Postgres a failed statement inside an open transaction aborts the transaction.
   The job runs on the queue worker, outside any transaction in production; if the project ever runs the sync driver
   inside one, wrap the insert in a savepoint (`DB::transaction` when `transactionLevel() > 0`). Stated, not built
   speculatively.
5. **The failure that will actually happen is a missing GRANT.** A missing table/sequence grant makes _every_ insert
   fail — and with containment that failure is silent apart from the log line. This is not hypothetical: the
   `campaign_applications` incident (`deploy-log.md:985-995`) and AH-102's own named step 6
   (`deploy-log.md:107-110`, `has_table_privilege('engine_c_user', …)`). 102b's deploy entry needs the same check for
   `push_deliveries` **and `USAGE` on its id sequence**, and the empty-table-after-arming read is a smoke step.
6. **Break-revert (§5.35) pins:** (a) a recorder whose insert throws → the job completes, one push sent, no retry
   (mutate: remove the `catch` → the spec fails); (b) a recorder whose `invalidate` throws still writes the row and
   vice versa; (c) `Delivered` with a failing insert does not re-send (assert a single `send()`).
7. **Existing behaviour pinned unchanged:** `SendPushNotificationJobTest.php` and `PushTokenRegistryTest.php` keep
   passing untouched except for the one new DTO argument.

---

## 3. Half B — app usage

### 3.1 Data source — recommendation

**Recommended: derive, add no writes.** App status and last-seen come from `personal_access_tokens`
(`creator-app:*`), `user_push_tokens`, and `audit_logs` (`auth.login.succeeded` / `auth.logout`,
`metadata.guard = 'creator_app'`).

|                              | **(1) Derive (recommended)**                                                                                  | **(2) `users.last_app_login_at`**                                                                                         | **(2b) per-request `last_app_seen_at`** |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- | --------------------------------------- |
| New hot-path write           | none                                                                                                          | none extra (stamped in the existing `saveQuietly` at `AuthService.php:239-241`)                                           | **a new write on every API call**       |
| Touches the login path       | no                                                                                                            | **yes** — `AuthService::issueToken()`, security-sensitive (AH-099), full-loop                                             | yes                                     |
| Migration                    | none                                                                                                          | additive nullable column; no backfill needed _only if_ it ships before the first app login (true today: nothing deployed) | additive                                |
| "Ever signed in", first/last | audit log (durable, survives logout/reset)                                                                    | last only                                                                                                                 | last only                               |
| Last seen                    | PAT `last_used_at` — already written per request                                                              | does not give it                                                                                                          | duplicates what PAT already stores      |
| Filter/sort cost             | `EXISTS` subqueries over PATs + audit; `metadata->guard` is unindexed JSON                                    | plain indexed column                                                                                                      | plain column                            |
| Weakness                     | the "never signed in" filter is two correlated `NOT EXISTS` per creator row — fine now, a named ceiling later | one more column to keep honest; pre-existing logins (none today) would need a separate backfill command                   | rejected outright                       |

(2b is listed only to reject it: the PAT already carries the same fact.) **Trade-off accepted:** (1) pays a heavier
read query to keep the identity login path untouched and add zero schema. **Trigger to revisit:** the
creators-with-app list or its "never" filter becomes slow at real volume → add (2) then, as its own small chunk.
This respects the AH-102 liveness rules by construction (§3.2).

**Derived states (v1, three — chosen so they do not depend on whether tokens are ever pruned):**

- **Active** — ≥ 1 _live_ creator-app sign-in: PAT `name LIKE 'creator-app:%'`, `expires_at` null-or-future, `created_at >
now − expiration` (when `expiration > 0`), user not soft-deleted. The rule from `PushTokenRegistry::liveQuery()`
  (`:114-134`).
- **Signed out** — no live sign-in, but an audit row `auth.login.succeeded` with `guard = creator_app` exists
  (covers explicit logout, password reset, lockout, and 90-day lapse alike; the reason is not stored).
- **Never** — no creator-app PAT and no such audit row.

(A fourth state, "lapsed" — PAT present but expired by age — exists only while expired rows are unpruned; collapsing it
into "signed out" means turning on `sanctum:prune-expired` later changes nothing the admin sees.)

**One liveness definition, not two (Q11).** AH-102's principle was "one query, one place". Half B needs the PAT-side
predicate without the `user_push_tokens` join, so either **extract** the PAT-liveness predicate from `liveQuery()` into
a shared scope (touches shipped, reviewed code; its §5.34 negative set in `PushTokenRegistryTest.php` is the safety
net) or **duplicate** it plus a parity test. Recommendation: extract.

### 3.2 Admin section proposal

**Placement (Q8):** **Creators → "Creator app"** (`/creators/app`), beside All Creators. The creator app is
creator-only by construction (`AuthService.php:110`, allow-list `'creator_app' => [UserType::Creator]`), so "all
users" **is** "all creators". New dedicated endpoint + page rather than widening `GET /admin/creators`
(`AdminCreatorController.php:78-174`): the per-row cost is different (PAT aggregates + audit), and widening would
change a shipped resource shape and `AdminCreatorListItem` (a Mode A trigger) for no gain (**Q10**).

`GET /api/v1/admin/creator-app/users` — **offset-paginated** (copy All Creators: `page`, `per_page` clamp 1–100
default 25, `meta.{total,page,per_page,last_page}`, unknown filter value → empty page).

| Column                                 | Source                                                                                                                    |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| Creator (name → creator detail, email) | `creators` + `users` (soft-deleted excluded)                                                                              |
| App status chip                        | derived (§3.1)                                                                                                            |
| Platform(s)                            | PAT name suffixes of live sign-ins; "—" when none is live (the platform of a past sign-in is not stored anywhere durable) |
| Last seen                              | `max(last_used_at)` over live PATs                                                                                        |
| Last app sign-in                       | `max(audit.created_at)` for `auth.login.succeeded` + `guard=creator_app`                                                  |
| Sign-ins                               | count of live PATs                                                                                                        |
| Push                                   | "registered" / "none" / "dead (reason)" from `user_push_tokens`                                                           |

**Filters** (chip groups, AND-composing, All Creators style): app status (all / active / signed out / never);
platform (all / ios / android — over live sign-ins); push (all / registered / none). Plus a debounced email/name
search (Agencies pattern, `:100-109`).

**Per-user detail** (`GET /api/v1/admin/creator-app/users/{creator ulid}/sign-ins`, opened from a row, a drawer or a
sub-route): one row per `creator-app:*` PAT, left-joined to its push token:

| Field                  | Source                                                               |
| ---------------------- | -------------------------------------------------------------------- |
| Platform               | PAT name suffix                                                      |
| Signed in              | PAT `created_at`                                                     |
| Last seen              | PAT `last_used_at`                                                   |
| Session state          | live / expired (age) / expired (`expires_at`) — computed, not stored |
| App version            | `user_push_tokens.app_version` (push-registered only; else "—")      |
| Push token state       | none / live / invalidated + `invalidation_reason` + `invalidated_at` |
| Last push registration | `user_push_tokens.last_seen_at` (labelled as such)                   |
| "View deliveries"      | link → the §2.4 page filtered to this creator                        |

**Terminology (Q12):** the page says **"sign-ins"**, not "devices" — there is no device identity (§1.2), a ghost
session after a reinstall is expected, and `last seen` lets an admin tell a ghost from a live one.

### 3.3 Privacy note

- **Admins see:** platform, app version, sign-in time, last seen, session state, push-token state and invalidation
  reason, push-delivery outcomes and codes. Device metadata, not content.
- **Admins never see:** the FCM token (`UserPushToken::$hidden`, `UserPushToken.php:67`), the PAT secret/hash
  (`personal_access_tokens.token`, never selected), message text (not stored), **IP address and user-agent** (audit
  rows hold them, `WriteAuthAuditLog.php:58-60`; half B deliberately does not surface them — the data is not needed
  to answer "who has the app").
- **Structural, not by convention:** the endpoints use explicit allow-listed resources (never `model->toArray()`), and
  a test asserts no `token` key anywhere in any response, with a break-revert that adds one.
- **Reads are not audited** in this codebase (read operations are "Not audited (intentionally)… covered by application logs",
  `docs/05-SECURITY-COMPLIANCE.md` §3.1, `:154-156`); 102b does not change that.
- **AH-105 note:** `push_deliveries` is a new stored category (per-user delivery telemetry). The App Store
  privacy-label / Play Data Safety checklist (`push-notifications-plan.md:593`) should list it.

---

## 4. Riders — `admin-sign-in.spec.ts`

**Not two flakes — one.** `apps/admin/playwright/specs/admin-sign-in.spec.ts:69` is where the test
(`admin with 2FA enrolled signs in via the inline TOTP reveal`) is declared; `:106` is the assertion inside it that
fails: `await expect(page).toHaveURL(/127\.0\.0\.1:5174\/$/)`, received `"http://127.0.0.1:5174/sign-in"`, 5000 ms,
`8 × unexpected value` (`:106:24`). Every failing run shows exactly this, attempt 1 fails, a retry passes, the job is green.

**Tally** (admin E2E job log of each `main` run since the Creator Mobile App inventory on 2026-10-06; read with `gh`,
19 runs examined; the admin job ran in 18 — run `37715503817` skipped it because Frontend failed first):

|                                                        | Runs                                                                  |
| ------------------------------------------------------ | --------------------------------------------------------------------- |
| Admin E2E ran                                          | 18                                                                    |
| **Flaky: `admin-sign-in.spec.ts:69`, failing at :106** | **17**                                                                |
| Clean (`2 passed`)                                     | **1** — `38025689774` (2026-10-10, the AH-101 provenance docs commit) |
| Failed after retries                                   | 0                                                                     |
| Flaky: any _other_ spec                                | **1** — the tip run, below                                            |

Sign-in-flaky runs: `37393941985`, `37402539475`, `37403873142`, `37561535504`, `37563130364`, `37716595878`,
`37718247832`, `37957556300`, `37960856088`, `38013498051`, `38014604787`, `38024623651`, `38135748678`,
`38163464156`, `38164763227`, `38173240196`, `38174364033`. Earlier history (from the same logs and `docs/tech-debt.md:1364`): five flaky runs on 2026-08-27; clean on 09-01
(`33510200711`, `33511250955`, `33568213627`); flaky `33822655018` on 09-04; clean `34062590149` on 09-06. `docs/tech-debt.md:2830` (item 16)
already says "three CI runs in a row… diagnose it before it hides a real sign-in regression."

**New at the tip, and not the same flake:** run `38174364033` reported **`2 flaky`** — sign-in as above, **and**
`admin-mandatory-mfa-enrollment.spec.ts:82:3` (`D7 deep-link to /settings is preserved across the MFA enrollment
redirect`), failing at `:132:66`: `expect(locator('[data-test="recovery-codes-display"]')).toBeVisible()`, "element(s) not
found", 5000 ms; the retry passed. It is **absent from the other 18 logs** (none contains the spec name in a failure),
so one occurrence in this window — recorded as seen, not diagnosed, and **not folded into the sign-in rider's count.**
It is in the same family (an admin auth-flow spec, a 5 s `expect` after a navigation), which is a reason to look at
both in one bounded pass, not evidence that they share a cause. (`docs/tech-debt.md:1361` already names this exact spec and test
in "Residual Playwright-retry flakiness", so it is a known-historical flake, newly seen in this window — not a new
finding.)

**Threshold: hit, many times over.** The AH-102 inventory's convention was a three-session threshold with a
bounded-diagnosis rider (`push-notifications-inventory.md:529`); this has failed in at least seven sessions' pushes
(AH-099, 100, 100b, 100c, 100d, 101, 102). The stated-rule precedent is `creator-profile-everywhere-review.md` §11
("a third flake after this bump reopens it as a real finding").

**It is not caused by recent changes:** `git diff --stat 52af5b23 HEAD -- apps/admin` (from the last clean-era tip,
2026-09-06) is empty, and so is `git diff --stat 4d468406 HEAD -- apps/admin`. No admin code changed across the
whole flaky stretch.

**This chunk touches `apps/admin`** (new pages, a nav entry, i18n) **and the nav is on the spec's own path** (the
spec lands on the dashboard inside `AdminLayout`). So **102b carries the bounded-diagnosis rider**, ≤ 2 h like R2/C0.3,
as Stage 0, with these starting facts (not conclusions):

1. The mechanism already written down (`tech-debt.md:1364`): `SignInPage.vue:81-83` sets `user` and pushes `/`;
   `requireAuth` (`core/router/guards.ts`, `await store.bootstrap()`) re-fetches `GET /admin/me` and a failure nulls the
   user and bounces to `/sign-in`; `AdminLayout`'s `useNavBadges` can add a second 401 inside the window. **Hypothesis,
   not a finding.**
2. **R2's cause is not it, on the log evidence:** none of the 19 logs contains an `optimized dependencies changed` /
   `optimize deps` line, and `apps/admin/vite.config.ts:65-67` already pre-includes `vuetify` + the component list.
3. The earlier "deliberately unfixed, no timeout bump" ruling (`tech-debt.md:1364`) predates 17 flaky runs of 18;
   whether it stands is **Q14**.

---

## 5. Risk and open questions

### 5.1 Live-data risk statement

⚠️ **PROD-DATA RISK (contained, additive — no existing production row is read-modified, deleted, migrated or
backfilled):**

- **One new table, `push_deliveries`** (`CREATE TABLE` only; FK _to_ `users`; nothing about `users` changes).
  Migrations carry no data writes (WORKING-PROCESS §5). `down()` drops the table and says the delivery history is
  not restorable. Tagged `@migration-risk low`.
- **Half B: no migration at all** under recommendation (1). (Option (2) would add one nullable column on `users` —
  not recommended.)
- **A hot-path insert in the push job.** Blast radius of a bug: lost delivery rows — **and, if containment is wrong,
  duplicate pushes to real creators** (§2.5). While `push_notifications_enabled` is OFF the recorder is never reached
  (`PushDispatcher.php:82-84`), so nothing is written until Pedram arms the flag.
- **One new row-removing command**, `push:prune-deliveries`, which deletes **only** this table's own rows older than
  the window; `--dry-run` first, counts recorded in the deploy log; §5.34 over-reach negatives.
- **New admin read endpoints** (platform-admin + MFA stack) over `users`, `creators`, `personal_access_tokens`,
  `user_push_tokens`, `audit_logs`, `push_deliveries`: **read-only**, no writes to any of them.
- **Deploy-time dependency:** the `engine_c_user` GRANT on the new table + sequence (§2.5.5); a scheduler
  obligation (§2.2). This chunk rides the still-PENDING AH-099 … AH-102 deploy; its migration order after
  `2026_10_10_100000_create_user_push_tokens_table`.

### 5.2 Questions for Pedram

**Q1. Row granularity.** One row per attempt (≤ 3 per job; full history) or one per job (needs a job id the outcome
does not carry)? **Recommend per attempt** — no new identity, append-only, a retry storm is visible.

**Q2. Add `notification_ulid` to `PushDeliveryOutcome`** (one optional field + one argument at
`SendPushNotificationJob.php:89-100`, no logic)? **Recommend yes** — it is the only way to join a delivery to its
in-app row.

**Q3. Record the non-attempts?** (token-gone-at-run-time, suppressed-before-dispatch, `failed()`.) That needs new
outcome cases and new call sites, and widens the recorder's exhaustive `match`. **Recommend no for v1**; the UI
states what the table does and does not count. Revisit if "why didn't she get it?" becomes a real support question.

**Q4. FKs.** `user_id` → `users` **cascade**; `user_push_token_id` a plain nullable column with **no FK**. **Recommend
as written** (§2.1); the alternative is `restrict` like `notifications`.

**Q5. Retention window.** 90 days? **Recommend 90**, configurable via the command option.

**Q6. The scheduler.** Can you (or the colleague who manages the box) read `crontab -l` and `supervisorctl status`
from prod before the build, closing the standing blocker? **Recommend yes, before Stage 1** — it also unblocks
`messages:send-digest`, `boards:scan-overdue`, and the incomplete-creator nudge. If it stays unknown: ship the
command schedule-registered _and_ manually runnable, with the admin footer making staleness visible.

**Q7. Email.** (a) Confirm email delivery/bounce tracking is **scoped out** of 102b (needs provider webhooks;
"Not tracked" on the page). (b) What is the effective production `MAIL_MAILER`? **Recommend** out, and a future
AH-102c only after (b) is known.

**Q8. Placement.** Operations → "Push deliveries"; Creators → "Creator app". **Recommend as written.**

**Q9. Config-error alarm.** A highlighted tile only, no new admin notification type? **Recommend tile only.**

**Q10. New endpoint + page** vs widening `GET /admin/creators`? **Recommend new**, leaving the shipped list shape
untouched.

**Q11. One liveness predicate.** Extract from `PushTokenRegistry::liveQuery()` into a shared scope (recommended) or
duplicate with a parity test?

**Q12. "Sign-ins", not "devices".** Label the unit honestly given there is no device identity? **Recommend yes.**

**Q13. App version for everyone.** Today only push-registered devices carry a version. Sending it at login (a field
on `POST /auth/token`, an app change, a release) is out of scope. **Recommend: accept "—" for the rest.**

**Q14. Flake rider.** Carry the bounded-diagnosis rider (≤ 2 h, Stage 0) for `admin-sign-in.spec.ts:69/:106`, and
re-open the "deliberately unfixed" ruling for this one test? **Recommend yes to both** (§4). Also confirm the
tally counts `:69`/`:106` as one flake, not two, and that the single sighting of
`admin-mandatory-mfa-enrollment.spec.ts:82` rides along as an observation (look at it in the same pass) rather than
as a second rider.

**Q15. Deploy sequencing.** 102b folds into the existing PENDING AH-099 … AH-102 entry (one snapshot, one
migrate) rather than a separate deploy? **Recommend fold in**; the new table is empty and flag-gated.

**Cost note (not a question):** every new admin string is ×24 locales with real MT for the flaky ten
(WORKING-PROCESS §4); the admin SPA has all 24 locale folders (`core/i18n/locales/*`). Scope the copy tight.

---

## Not verified

- **Production mail transport**, `MAIL_MAILER`, and whether any bounce/complaint feed exists — nothing in the repo
  records it (§2.3).
- **Whether a scheduler runs in production** — standing blocker, unchanged (§2.2).
- **Production row counts** — none exist for push; PAT count unread. All sizing in §2.2 is an illustration, not a
  measurement.
- **FCM semantics** (no per-message device receipt; `UNREGISTERED` behaviour) — vendor behaviour from memory; the repo
  shows only what the sender maps.
- **The `metadata->guard` filter on Postgres `json`** (query shape, plan, cost at volume) — not run; the audit
  column is `json`, unindexed. The "never" filter's correlated subqueries were not measured.
- **Sanctum behaviour beyond `v4.3.2`** — read from `vendor/` at the pinned version only.
- **Playwright on a loaded machine** — the flake tally is from CI logs only; nothing was run locally.
- **That the AH-102 `PushTokenRegistryTest` negative set would survive extracting the shared predicate** — it exists
  (§3.1); the extraction was not tried.

---

**Plan-pause stands.** No build plan, code, config or migration will be produced until this inventory is reviewed
and cleared.
