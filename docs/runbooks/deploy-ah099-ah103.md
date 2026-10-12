# Deploy runbook: AH-099 → AH-103, one sitting

**Status: DRAFT for Pedram's review. Nothing in this file has been run.** Written 2026-10-12 from the repo at `f932eda4` and the
production read-backs Pedram supplied the same day. It merges the six `PENDING` entries in [`deploy-log.md`](deploy-log.md)
(AH-099, the AH-100 family, AH-101, AH-102, AH-102b, AH-103) with [`production-queue-worker.md`](production-queue-worker.md) §8
(the checklist), §4 (the worker restart), §7 (the scheduler) and §7.5 (arming push).

**How to use it.** Run the steps in order. Every step has its exact command, what a healthy result looks like, and a blank
`Result:` line. Each step says where it runs: **HOST** (`~/catalyst-engine`) or **APP** (inside `docker compose exec app sh`, at
`/app`). Anything the repo does not document is marked **CONFIRM**: check it on the box before relying on it. The repo's
`docker-compose.yml` is the **local-dev** stack (Postgres, Redis, Mailhog, MinIO); there is **no production compose, nginx or
php-fpm file in the repo**, so the production layout is not written down anywhere I could read.

---

## 0. What this deploy is

| Item                                     | Value                                                                                                                                 |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| Last deployed tip (from `deploy-log.md`) | `9b7d6deb` (AH-098, 2026-09-04). **CONFIRM** with `git rev-parse HEAD` on the host (step P1)                                          |
| Deploy tip                               | `origin/main` when you pull. At the time of writing `f932eda4`, plus the docs-only commit that adds this runbook once you clear it    |
| Migrations                               | **2**, both `CREATE TABLE` only (§1)                                                                                                  |
| PHP dependencies                         | **No change** (`apps/api/composer.json` and `composer.lock` are identical to `9b7d6deb`)                                              |
| JS dependencies                          | **Changed** (`pnpm-lock.yaml` +1,515 lines; `apps/main` gains Capacitor and Firebase packages)                                        |
| SPA rebuilds                             | `apps/main` and `apps/admin` (the admin gains two pages)                                                                              |
| Env changes                              | `FRONTEND_EXTRA_ORIGINS` (append), `FCM_PROJECT_ID`, `FCM_SERVICE_ACCOUNT_JSON_B64`; optional `SANCTUM_TOKEN_EXPIRATION_MINUTES` (§2) |
| Worker restart                           | **Required** (`SendPushNotificationJob`, `PushDeliveryRecorder`, `PostVerifiedMail`, `PushMessageFactory` all changed)                |
| PHP-FPM reload                           | **Required** (new guard, config, providers, controllers)                                                                              |
| Scheduler                                | One new entry, `push:prune-deliveries` (`->daily()`). **No scheduler restart needed** (§3)                                            |
| Flags armed in this sitting              | `push_notifications_enabled` only (step 16). `post_verified_mail_enabled` stays OFF                                                   |
| Not in this sitting                      | The native iOS and Android builds (a store process), the TestFlight checks, `.env.native-production` (§5)                             |

### Production facts read on 2026-10-12 (Pedram, inside `docker compose exec app sh` at `/app`)

| Read                                               | Result                                                                                                                                                                              |
| -------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `supervisorctl status`                             | `laravel-queue` RUNNING (`queue:work`, not Horizon); `laravel-schedule` RUNNING (`schedule:work`, up 41 days); cron, nginx, php-fpm, syslog RUNNING; postfix, sshd, dnsmasq STOPPED |
| `queue.default` / `cache.default` / `mail.default` | `database` / `redis` / `smtp` → `smtp.postmarkapp.com`                                                                                                                              |
| `user_notification_preferences`, `channel='push'`  | **0** (the AH-102 expectation)                                                                                                                                                      |
| Preference rows                                    | `digest/true` 19; `in_app/false` 15; `email/false` **none**                                                                                                                         |
| `integrations.social.driver`                       | `mock`                                                                                                                                                                              |
| `features`: `social_verification_enabled`          | stored `"true"`, created 2026-07-14 07:58:21                                                                                                                                        |
| `migrate:status`                                   | last run `2026_08_27_140000_create_campaign_attachments_table` (batch 8). Nothing from AH-099 onward has run                                                                        |

Three consequences of those reads are already folded into the steps below:

1. **The queue is `database`, not Redis.** `queue:restart` still works (it signals through the cache, which is Redis).
2. **The scheduler is `schedule:work` under supervisor, not cron.** The AH-102b entry's step 6 says "`crontab -l` shows `schedule:run`"; that
   is not how production runs it. Step 14 reads the supervisor program and `schedule:list` instead.
3. **`email/false` has no rows**, so AH-101's new opt-out guard blocks nothing on deploy.

---

## 1. The migrations pending after `2026_08_27_140000`, by name

Checked two ways: file names sorted after `…140000`, and `git diff --name-status 9b7d6deb..HEAD -- apps/api/database/migrations`
(which would also catch a late-added file with an early timestamp). **Exactly two, both added files:**

| #   | Migration                                         | Entry   | What it does                                                                                                                                                                              |
| --- | ------------------------------------------------- | ------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `2026_10_10_100000_create_user_push_tokens_table` | AH-102  | `CREATE TABLE user_push_tokens` (FKs to `users` and `personal_access_tokens`; `id()` so `user_push_tokens_id_seq`). No data writes. `down()` drops it (tokens re-register on next launch) |
| 2   | `2026_10_12_100000_create_push_deliveries_table`  | AH-102b | `CREATE TABLE push_deliveries` (one FK to `users`, three indexes; `id()` so `push_deliveries_id_seq`). No data writes. `down()` drops it (a log of attempts)                              |

AH-099's `personal_access_tokens` table is the **stock** migration `2026_05_07_003823_create_personal_access_tokens_table`, older than
`…140000`, so it should already be `Ran`. Step P4 reads it rather than assuming.

---

## 2. Environment changes (all four, in one place)

| Variable                           | Change                                                          | Notes                                                                                                                                                                                                                              |
| ---------------------------------- | --------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `FRONTEND_EXTRA_ORIGINS`           | **Append** `capacitor://localhost,https://localhost`            | iOS and Android origins for CORS (AH-099). Comma-separated; keep any existing value                                                                                                                                                |
| `SANCTUM_STATEFUL_DOMAINS`         | **No change. Read it and confirm there is NO bare `localhost`** | The Android-only trap: Sanctum strips only `http(s)://`, so a bare `localhost` makes `https://localhost` a stateful origin and every bearer POST 419s. The repo's `.env.example` default contains `localhost`; production must not |
| `FCM_PROJECT_ID`                   | Set to `catalyst-production-c28e9`                              | Non-secret                                                                                                                                                                                                                         |
| `FCM_SERVICE_ACCOUNT_JSON_B64`     | Set (base64 of the service-account JSON, **one line**)          | **SECRET.** From Secrets Manager (`catalyst/<env>/api/fcm` is a convention to **CONFIRM**). Never paste it into chat, the terminal log or a ticket                                                                                 |
| `SANCTUM_TOKEN_EXPIRATION_MINUTES` | Optional. Unset defaults to `129600` (90 days)                  | A fixed ceiling from token creation. Leave unset unless you want a different one                                                                                                                                                   |
| `AUTH_GUARD`                       | Read: must be unset or `web`                                    | AH-099 pre-deploy read                                                                                                                                                                                                             |

**CONFIRM where production reads its env from** (`/app/.env`, a compose `env_file`, or injected from Secrets Manager). The repo
does not say. The commands below assume `/app/.env`; adjust the file path, not the procedure.

---

## 3. Two risks to read before you start

### 3.1 The APP_KEY incident: current state and the safe route

**The risk (2026-08-11, recorded in `deploy-log.md` and AH-067).** `composer install --no-dev` ran a `post-install-cmd` hook that called
`php artisan key:generate`, which **rotated the live production `APP_KEY`**. Every TOTP secret and session became undecryptable
(MFA down platform-wide). Business data and password hashes were unaffected. It was recovered only because the old key was still
in the running queue worker's memory and could be extracted from the host.

**What `composer.json` says now (checked at this tip, `apps/api/composer.json:52-66`).** `key:generate` appears **once**, at line 64,
under `post-create-project-cmd`, which Composer runs only when scaffolding a new project, never on `composer install` or
`update`. There is **no `post-install-cmd` at all**. `post-autoload-dump` runs `package:discover`; `post-update-cmd` runs
`vendor:publish --tag=laravel-assets`; `post-root-package-install` only copies `.env.example` if `.env` is missing. The fix is
AH-067 (`b13ee718`) and it is pinned by `apps/api/tests/Feature/Core/ComposerInstallHooksTest.php:51` and `:78`, which run in CI
(green at the pushed tip). So **the hook is gone and a re-add fails CI.**

**The safest route for this deploy: do not run `composer install` at all.** `composer.json` and `composer.lock` are byte-identical to
`9b7d6deb`, so the PHP `vendor/` tree needs nothing. Step 3 proves that with a diff on the box rather than asserting it.

**If step 3's diff is NOT empty** (for example the box's tip is older than `9b7d6deb`), use the safe form, which runs no hooks:

```sh
composer install --no-dev --optimize-autoloader --no-scripts   # APP
php artisan package:discover --ansi                            # APP: the one hook that matters, run by hand
```

**Either way, fingerprint the key before and after every step that can touch it.** A hash of the `APP_KEY` line, never the key
itself (steps P2, 2, 3, 7, 8 and the end). If it ever changes: **stop, restart nothing** (the workers hold the old key in memory,
which is what made the last recovery possible), and restore the value from the recoverable copy in step P2.

### 3.2 The scheduler needs no restart for the new prune entry

Production runs `php artisan schedule:work` under supervisor. Laravel's `ScheduleWorkCommand::handle()` starts a **fresh
`schedule:run` process every minute** (`vendor/laravel/framework/src/Illuminate/Console/Scheduling/ScheduleWorkCommand.php`), so
`bootstrap/app.php`'s new `push:prune-deliveries` entry is read by the next minute's run. Step 14 proves it with `schedule:list`.

---

## 4. The decision before the flag steps (Task 1): mock social verification

Production runs the **mock** social driver with `social_verification_enabled` ON since 2026-07-14. Full evidence is in D1 below;
the short version and the recommendation:

> **Recommendation: confirm it is harmless and make no flag change in this deploy.** AH-101's new in-app row (`assignment.live_verified`)
> **will** start firing for every mock verification, but the transition it announces already happens in production today and
> already writes the same claim into the thread ("The live post was verified.") and moves the board card. The deploy adds one
> creator-only bell row, with no push and no email. **Switching the flag OFF is worse, not safer:** a post submitted while it is OFF
> is stuck at `posted` / `pending` with no way for anyone to advance it.

**Run the three read-only queries in step P6 first.** They confirm there is nothing in flight that changes this. If they show real
creator posts stuck `pending` right now, stop and re-decide (§4 D1.4).

Step 15 applies the decision. Under the recommendation it is a no-op plus read-backs.

### D1. Evidence

**D1.1 What the mock does when the flag is ON, and the trigger.** It marks posts `live_verified`, and the trigger is a **request that
queues a job**, not a schedule:

1. A creator submits a post URL: `POST /api/v1/creators/me/assignments/{a}/posted-content`
   (`apps/api/app/Modules/Creators/Routes/api.php:236` → `CreatorAssignmentDraftController::submitPostedContent`), which drives
   `approved → posted` and fires `AssignmentTransitioned` with action `assignment.posted_by_creator`.
2. `DispatchPostedContentVerification` (`apps/api/app/Modules/Campaigns/Listeners/DispatchPostedContentVerification.php:34-47`) checks the
   flag (`:38`) and dispatches `VerifyPostedContentJob` (`:47`) onto the `database` queue, which the supervisor `queue:work` drains.
3. `VerifyPostedContentJob::handle` (`apps/api/app/Modules/Campaigns/Jobs/VerifyPostedContentJob.php`) re-checks the flag (`:63`), skips a
   post that is not `pending` (`:78`), calls `verifyPostUrl` (`:89`), writes the outcome (`:92-99`) and, on `verified`, calls
   `CampaignAssignmentStateMachine::verifyLive` (`:101-107`), which moves `posted → live_verified` (`CampaignAssignmentStateMachine.php:484-501`).
4. The mock's rule (`apps/api/app/Modules/Creators/Integrations/Mock/MockSocialProvider.php:79-93`): **verified if the URL merely
   contains the creator's connected handle**, checked _before_ it looks like a social URL at all; `mismatch` if it looks like a
   social URL without the handle (`:86-90`); otherwise `not_found` (`:93`). A creator who types any URL containing their handle is
   "verified". An empty handle never verifies.
5. A creator's in-place PATCH (`Routes/api.php:246`) re-arms verification, but **only for a failed post**
   (`CreatorAssignmentDraftController.php:513-519`).

No money moves: `holdPayment` and `releasePayment` always throw (`CampaignAssignmentStateMachine.php:580-596`, `escrowUnavailable`).
`live_verified` is a dead end until Sprint 10.

**D1.2 Would AH-101's in-app notice fire for mock verifications after this deploy? Yes.** `SendAssignmentNotifications::handle` maps
`AssignmentLiveVerified` to `notifyCreatorOfLiveVerification` (`apps/api/app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php:122`),
which writes the in-app row **ungated** (`:282-293`; the recipient's own `in_app` preference still applies). Copy: "Your post for
{campaign_name} was verified." (`apps/main/src/core/i18n/locales/en/notifications.json:26`). What it does **not** do:

- **No push:** `PushEligibility` excludes the type by ruling (`apps/api/app/Modules/Notifications/Push/PushEligibility.php:21`).
- **No email:** the mail leg is behind `post_verified_mail_enabled` (`SendAssignmentNotifications.php:276`), OFF.
- **No backfill:** it is written on the transition event; no command creates rows for past verifications.
- **Not new in kind:** the same transition already writes the system message "The live post was verified."
  (`WriteSystemMessage.php:48`, `apps/api/lang/en/messages.php:19`) and moves the board card (`BoardDefaults.php:58`). The agency-side
  UI labels it "Simulated verification (mock provider)" (`apps/main/src/core/i18n/locales/en/app.json:809`, `:833`, `:863`); the
  creator side has no such label.

**D1.3 What switching `social_verification_enabled` OFF would change, especially in flight.**

- New posts: the dispatch listener returns (`DispatchPostedContentVerification.php:38`) and the job would return (`VerifyPostedContentJob.php:63`),
  so a post stays `verification_status = pending` and the assignment stays `posted`. `verifyLive` throws the vendor-gated exception
  (`CampaignAssignmentStateMachine.php:488-489`).
- **Nobody can then advance it.** The agency's manual path exists only for a _failed_ post: the API refuses with "no failed
  verification to resolve" unless the latest post is `not_found` or `mismatch`
  (`apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAssignmentResolutionController.php:160-180`), and the UI hides the action
  likewise (`apps/main/src/modules/campaigns/pages/CampaignDetailPage.vue:200-206`). The creator's in-place PATCH is failed-only too
  (§D1.1 item 5). A `pending` post is neither.
- In flight: a job already queued when the flag flips is a no-op (`VerifyPostedContentJob.php:63`), and flipping the flag back ON
  does **not** re-dispatch it. Those posts would stay `pending` for good.
- Already-verified assignments (`live_verified`, `manually_verified`) are unchanged.

**D1.4 Why "confirm harmless" and not the other two.**

- **Gate the in-app notice** is a code change (a new conditional on the driver), so a new chunk, CI and push before this deploy.
  And it only hides one of three places the same claim already appears (the thread line and the board move stay), so it is
  inconsistent rather than safer.
- **Switch the flag OFF** trades one bell row for a dead end on every new post, and the only thing it protects against (a
  spoofable string check producing `live_verified`) already has no financial consequence while escrow is gated.
- **Condition:** the three queries in step P6. If they show posts stuck `pending` now (a failed job or a queue lag), OFF would make
  that permanent, which strengthens the case for leaving it ON and fixing the job. If they show **zero** posts ever, the whole
  question is theoretical today and the recommendation costs nothing.

**D1.5 A trap to record regardless of the decision (a tech-debt row, not a deploy blocker).** The flag's default is driver-based
(`SocialVerificationEnabled.php:56-69`: ON under `mock`, OFF under a real adapter), but Pennant **stores the resolved default on
first check** (`apps/api/vendor/laravel/pennant/src/Drivers/DatabaseDriver.php:206-231`) and a stored row always wins. The
production row (`"true"`, 2026-07-14 07:58:21) is therefore almost certainly the mock-driver default frozen in place, not a
deliberate arming. **When a real adapter is wired, the flag will stay ON** unless that stored row is deleted or set OFF first, which
defeats the "no silent vendor calls" guarantee `docs/feature-flags.md:151` claims. This is also why `post_verified_mail_enabled` must
stay OFF: its own docs say not to arm it over the mock driver.

---

## 5. The checklist

### Before you start (nothing here changes production)

**P1. Where is the box now? [HOST]**

```sh
cd ~/catalyst-engine && git status --short | head && git rev-parse HEAD && git fetch origin && git log --oneline HEAD..origin/main | wc -l
```

Healthy: a clean tree, `HEAD` is `9b7d6deb` or a docs-only descendant, and a large positive commit count behind `origin/main`.
**CONFIRM** the on-box tip: step 3's diff uses it, so write it down. Is `/app` a bind mount of `~/catalyst-engine/apps/api`, or a
separate copy built into the image? That decides whether the host `git pull` is also what the container serves. **CONFIRM.**
Result: ****\*\*****\_\_****\*\*****

**P2. APP_KEY fingerprint and a recoverable copy [APP]**

```sh
grep '^APP_KEY=' .env | sha256sum | cut -c1-12
```

Healthy: prints 12 hex characters. Write it down; it is compared at steps 2, 3, 7, 8 and 18. Also confirm, **without printing the
key**, that a copy of the production `APP_KEY` exists outside the box (`docs/05-SECURITY-COMPLIANCE.md` says it is in AWS Secrets
Manager): **CONFIRM** it is current. If `.env` is not where the key lives, fingerprint wherever it does.
Fingerprint: \***\*\_\_\_\_\*\*** Recoverable copy confirmed: yes / no

**P3. Preview the migrations [APP]**

```sh
php artisan migrate:status | tail -5
```

Healthy: the last line is `2026_08_27_140000_create_campaign_attachments_table … Ran` and nothing is `Pending`. (Matches your read: batch 8, nothing from AH-099 onward.)
Result: ****\*\*****\_\_****\*\*****

**P4. AH-099's pre-deploy reads [APP]** (not yet read)

```sh
php artisan migrate:status | grep personal_access_tokens
php artisan tinker --execute="echo DB::table('personal_access_tokens')->count(), PHP_EOL;"
php artisan tinker --execute="echo json_encode([config('sanctum.stateful'), config('auth.defaults.guard')]), PHP_EOL;"
```

Healthy: the stock `…create_personal_access_tokens_table` shows `Ran`; the count is `0`; the stateful list has **no bare `localhost`**
(only real production hostnames) and the guard is `web`. `config('sanctum.stateful')` reflects cached config if it is cached; if you
suspect a stale cache, read the `SANCTUM_STATEFUL_DOMAINS` line in `.env` too.
Result: ****\*\*****\_\_****\*\*****

**P5. FCM credential is present and valid, without printing it [APP]** (not yet read)

The credential is a Step 7 change, so this read happens _after_ step 8. Here, only confirm the secret **exists** in Secrets Manager
(**CONFIRM** path `catalyst/<env>/api/fcm`) and that you can produce it in step 7.
Result: ****\*\*****\_\_****\*\*****

**P6. The Task 1 reads (read-only) [APP]**

```sh
php artisan tinker --execute="echo json_encode(DB::select('select status, count(*) as n from campaign_assignments group by status order by status')), PHP_EOL;"
php artisan tinker --execute="echo json_encode(DB::select('select verification_status, count(*) as n, min(created_at) as first, max(created_at) as last from campaign_posted_content group by verification_status')), PHP_EOL;"
php artisan tinker --execute="echo json_encode(DB::select(\"select count(*) as n, min(created_at) as first, max(created_at) as last from audit_logs where action = 'assignment.live_verified'\")), PHP_EOL;"
php artisan tinker --execute="echo json_encode(DB::select(\"select type, count(*) as n from user_notification_preferences where channel='in_app' and is_enabled = false group by type\")), PHP_EOL;"
```

Healthy for the recommendation: **no row with `verification_status = pending` that is old** (a `pending` row that is older than a few
minutes means the job did not run). `live_verified` rows and audit rows show how long mock verification has really been live and
for whom. Record the numbers; they decide §4.
Result: ****\*\*****\_\_****\*\*****

### Step 1. Snapshot [wait for `available`]

Take the database snapshot in the AWS console or CLI. **CONFIRM** the identifier and the DB instance name (the repo has neither).
Then wait until it reports `available`. A snapshot still `creating` is not a safety net (§8 step 1).

```sh
# example form, CONFIRM the instance/snapshot names:
aws rds describe-db-snapshots --db-snapshot-identifier <SNAPSHOT_ID> --query 'DBSnapshots[0].Status' --output text
```

Healthy: `available`. **Record the ID in the deploy-log entries before step 5.** Both new tables are `CREATE TABLE`; the snapshot is the
restore point for both migrations.
Snapshot ID: ****\*\*****\_\_****\*\***** Status read: ****\*\*****\_\_****\*\*****

### Step 2. Pull the code [HOST]

```sh
cd ~/catalyst-engine
OLD=$(git rev-parse HEAD); echo "OLD=$OLD"
git pull --ff-only origin main        # CONFIRM: the repo documents no pull command; the deploy log says "pulled origin/main"
git rev-parse HEAD
```

Healthy: a fast-forward, no conflict, and the new `HEAD` is the tip you intend. Then re-fingerprint the key **[APP]**:

```sh
grep '^APP_KEY=' .env | sha256sum | cut -c1-12
```

Healthy: **identical to P2.**
New HEAD: \***\*\_\_\_\_\*\*** Fingerprint after: \***\*\_\_\_\_\*\*** Same as P2: yes / no

### Step 3. PHP dependencies: normally none [HOST, then APP]

```sh
git diff --stat $OLD..HEAD -- apps/api/composer.json apps/api/composer.lock        # HOST; expected: empty
```

Healthy: **empty output = skip `composer install` entirely** (§3.1). If it is not empty, use the `--no-scripts` form in §3.1, then
`php artisan package:discover --ansi`. Never plain `composer install`.
Then confirm the guard still holds on the box **[APP]**:

```sh
grep -n 'key:generate' composer.json                       # expected: one line, under post-create-project-cmd (≈ line 64)
grep -n 'post-install-cmd' composer.json                   # expected: no output
grep '^APP_KEY=' .env | sha256sum | cut -c1-12             # expected: identical to P2
```

diff empty: yes / no Fingerprint: \***\*\_\_\_\_\*\*** Same as P2: yes / no

### Step 4. JS dependencies and the two SPA builds [CONFIRM where this runs]

The deploy log says only "`pnpm install` on the build host" and "SPA rebuilt"; **how the bundles are built and published is not in
the repo. CONFIRM** which machine builds them, where `dist/` is served from (nginx root) and how it is swapped in.
The repo's commands (`package.json`, `apps/*/package.json`):

```sh
pnpm install --frozen-lockfile                 # the lockfile changed (+1,515 lines; apps/main gains Capacitor + Firebase packages)
pnpm --filter @catalyst/main build             # = vue-tsc --noEmit && vite build (web mode; NOT build:native)
pnpm --filter @catalyst/admin build            # the admin gains Push deliveries and Creator app pages
```

`packages/ui`, `packages/design-tokens` and `packages/api-client` are consumed by those builds. **Do not** run `build:native`; it is a
device build and needs the uncommitted `.env.native-production`.
Healthy: both builds exit 0 (the type-check runs first, so a TypeScript error fails the build), and the new `dist/` is the one nginx
serves. Do this **before** the migration only if publishing is atomic; otherwise build now and **publish after step 9** so
browsers never see a bundle that calls an API the box is not yet running. **CONFIRM** which.
Result: ****\*\*****\_\_****\*\*****

### Step 5. Migrate [APP]

```sh
php artisan migrate --pretend --force      # reads only: prints the SQL. Expect two CREATE TABLEs and their indexes/FKs, nothing else
php artisan migrate --force                # --force is required with APP_ENV=production
```

Healthy: **exactly** these two lines, `DONE`, and nothing else:
`2026_10_10_100000_create_user_push_tokens_table` and `2026_10_12_100000_create_push_deliveries_table`. Any third migration, or any
`ALTER`/`DROP` in the pretend output, is a stop (§8 step 2: "read the output").

```sh
php artisan migrate:status | tail -4
```

Healthy: both `Ran`, batch 9.
Output: ****\*\*****\_\_****\*\*****

### Step 6. GRANT read-backs for the app DB role [APP]

Postgres does not extend `CREATE TABLE` access to the application role (the 2026-07-31 `campaign_applications` incident,
`deploy-log.md`). Check **before arming**. The earlier entries used `has_table_privilege('engine_c_user', 't', 'SELECT,INSERT,UPDATE,DELETE')`.
**That form returns true if ANY one of the listed privileges is held** (PostgreSQL's documented behaviour for a comma list), so it
passes with `SELECT` alone. Check each privilege separately. This runs as the connection the app uses (`current_user`), so it reads
what the app will actually have:

```sh
php artisan tinker --execute="foreach (['user_push_tokens','push_deliveries'] as \$t) { foreach (['SELECT','INSERT','UPDATE','DELETE'] as \$p) { echo \$t,' ',\$p,' ',var_export(DB::selectOne('select has_table_privilege(current_user, ?, ?) as ok', ['public.'.\$t, \$p])->ok, true), PHP_EOL; } foreach (['USAGE','SELECT'] as \$p) { echo \$t,'_id_seq ',\$p,' ',var_export(DB::selectOne('select has_sequence_privilege(current_user, ?, ?) as ok', ['public.'.\$t.'_id_seq', \$p])->ok, true), PHP_EOL; } } echo DB::selectOne('select current_user as u')->u, PHP_EOL;"
```

Healthy: **12 lines, every one `true`**, and the last line is the app role (expected `engine_c_user`). (The command was run against the
local dev database to check its syntax; it prints the same 12 lines plus the role name.)

If anything is `false`, grant it **as the table owner** (**CONFIRM** how you get an owner/admin SQL session on RDS; the app role
cannot grant itself), then re-run the read above:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON user_push_tokens TO engine_c_user;
GRANT USAGE, SELECT ON SEQUENCE user_push_tokens_id_seq TO engine_c_user;
GRANT SELECT, INSERT, UPDATE, DELETE ON push_deliveries TO engine_c_user;
GRANT USAGE, SELECT ON SEQUENCE push_deliveries_id_seq TO engine_c_user;
```

Also **CONFIRM** which role runs `migrate`. If it is the same as the app role, this step is moot but the read still proves it.
Role: \***\*\_\_\_\_\*\*** Output: ****\*\*****\_\_****\*\*****

### Step 7. Environment [APP; CONFIRM the env file]

Edit the production env (see §2). Do not print secrets. After editing, **fingerprint the key** and read back **names, not values**:

```sh
grep '^APP_KEY=' .env | sha256sum | cut -c1-12                                   # identical to P2
grep -c '^FCM_PROJECT_ID=catalyst-production-c28e9$' .env                        # expect 1
grep -c '^FCM_SERVICE_ACCOUNT_JSON_B64=.\+' .env                                 # expect 1 (non-empty; do not print the line)
grep '^FRONTEND_EXTRA_ORIGINS=' .env                                             # must contain capacitor://localhost and https://localhost
grep '^SANCTUM_STATEFUL_DOMAINS=' .env                                           # must contain NO bare "localhost"
```

Healthy: as annotated. `FRONTEND_EXTRA_ORIGINS` keeps any value it already had. These are **not live until step 8**.
Fingerprint: \***\*\_\_\_\_\*\*** FRONTEND_EXTRA_ORIGINS read: ****\*\*****\_\_****\*\*****

### Step 8. Cache config [APP]

```sh
php artisan config:cache
grep '^APP_KEY=' .env | sha256sum | cut -c1-12                                   # identical to P2
php artisan tinker --execute="echo json_encode(['origins'=>config('cors.allowed_origins'),'fcm_project'=>config('integrations.push.fcm.project_id'),'fcm_secret_set'=>config('integrations.push.fcm.service_account_json_b64')!==null]), PHP_EOL;"
```

Healthy: `config:cache` succeeds; the origins list includes `capacitor://localhost` and `https://localhost`; `fcm_project` is
`catalyst-production-c28e9`; `fcm_secret_set` is `true`. If your CORS config uses a different key, read `config('cors')` whole
(**CONFIRM** the key; `config/cors.php` reads `FRONTEND_EXTRA_ORIGINS`).
Result: ****\*\*****\_\_****\*\*****

### Step 9. PHP-FPM reload [CONFIRM the exact command]

```sh
supervisorctl status | grep -i php                                # find the program name
supervisorctl signal USR2 <php-fpm-program-name>                  # CONFIRM: USR2 = graceful reload for php-fpm; or use the box's usual command
```

Why: opcache serves stale bytecode otherwise (the AH-097 and 2026-08-11 entries in `deploy-log.md` record the reload as a required step). The repo does not say how the box reloads FPM.
Healthy: FPM stays RUNNING and `/up` still answers. **Publish the SPA bundles now** if step 4 deferred it.
Result: ****\*\*****\_\_****\*\*****

### Step 10. Restart the worker [APP]

```sh
php artisan queue:restart
sleep 10
supervisorctl status laravel-queue
```

Healthy: `RUNNING` with an uptime of seconds (supervisor restarted it onto the new code). Without this the worker runs the old job and
mail classes (§4 of the queue runbook). `laravel-schedule` is **not** restarted (§3.2).
Result: ****\*\*****\_\_****\*\*****

### Step 11. `/up` and one authenticated request

```sh
curl -s -o /dev/null -w '%{http_code}\n' https://<APP_HOST>/up         # CONFIRM host; expect 200
```

Then log in on the web app (creator or admin) and load the dashboard. Healthy: `200` and the authenticated page renders. If either
fails, stop and assess (§8 step 5).
Result: ****\*\*****\_\_****\*\*****

### Step 12. FCM credential [APP]

```sh
php artisan push:test --validate-only
```

Healthy: **`Credential OK: the provider accepted authentication…`**. Anything else is a `config_error`, `retryable` or `rejected`
line: read the code, **do not arm**. This decodes `FCM_SERVICE_ACCOUNT_JSON_B64`, does the OAuth exchange and validates a request
for a placeholder token; it is the credential read-back.
Result: ****\*\*****\_\_****\*\*****

### Step 13. Prune dry-run [APP]

```sh
php artisan push:prune-deliveries --dry-run
```

Healthy: **`would delete 0`** (the table is empty). Nothing is deleted.
Result: ****\*\*****\_\_****\*\*****

### Step 14. Scheduler [APP]

```sh
supervisorctl status laravel-schedule
php artisan schedule:list
```

Healthy: `laravel-schedule` RUNNING; the list includes `push:prune-deliveries` alongside `messages:send-digest`, `boards:scan-overdue` and
`creators:send-incomplete-nudges`. This is `schedule:work`, **not** cron, so the AH-102b entry's `crontab -l` read does not apply
(production's `cron` service is running, but the scheduler is the supervisor program). **CONFIRM** that no _second_ `schedule:run`
cron line exists (`crontab -l`), or every daily command runs twice.
Result: ****\*\*****\_\_****\*\*****

### Step 15. Apply the Task 1 decision

**Recommended (§4): no flag change.** Run the read-backs only:

```sh
php artisan tinker --execute="var_dump(Laravel\Pennant\Feature::active('social_verification_enabled'), Laravel\Pennant\Feature::active('post_verified_mail_enabled'), config('integrations.social.driver'));"
```

Healthy: `bool(true)` for social verification (unchanged, as it has been since 2026-07-14), **`bool(false)` for
`post_verified_mail_enabled` (must stay OFF)**, `"mock"`.

**If you override and switch it OFF:** admin → Feature flags → `social_verification_enabled` OFF, then re-run the line above and read
`bool(false)`. Know what that does first (§4 D1.3): new and in-flight posts are stuck `posted` / `pending` with no resolution path.
Decision taken: recommended / OFF / other: ****\*\*****\_\_****\*\***** Read-back: ****\*\*****\_\_****\*\*****

### Step 16. Arm `push_notifications_enabled` [admin Feature-flags page]

Preconditions (`feature-flags.md:60`, `production-queue-worker.md` §7.5): the FCM variables are set and decode (step 12 passed); the
worker restarted after this deploy (step 10); the Android device proof passed (local walk, 2026-10-11, E3 11 of 12). **Arm before the first
push-capable store build reaches users**, or their first sessions register tokens that never receive anything. No tokens exist
yet, so arming now sends nothing.

1. Admin → **Feature flags** → `push_notifications_enabled` **ON**.
2. Read it back:

```sh
php artisan tinker --execute="var_dump(Laravel\Pennant\Feature::active('push_notifications_enabled'));"
php artisan tinker --execute="echo json_encode(DB::select(\"select name, scope, value, created_at, updated_at from features where name = 'push_notifications_enabled'\")), PHP_EOL;"
```

Healthy: `bool(true)`, and one stored row with `value` `true`, `updated_at` just now.

`production-queue-worker.md` §7.5 step 2 (one real push to a named test account) needs a **production-pointed** build with a
registered token; none exists yet. **Do it before you announce the app**, not in this sitting.
Result: ****\*\*****\_\_****\*\*****

### Step 17. `push_deliveries` after arming [APP]

```sh
php artisan tinker --execute="echo DB::table('push_deliveries')->count(), PHP_EOL;"
```

Healthy: **`0`.** Read this honestly: with no registered tokens there is nothing to send, so an empty table now proves only that
nothing broke, **not** that the recorder can write. Two things prove the write path: step 6's 12 `true` reads (the missing-`GRANT`
failure mode), and the **first real push**, after which `count` must be `> 0`. If a real push is delivered and the table is still
empty, the cause is a `GRANT` or a recorder failure; look for `push.delivery_record_failed` in the log (`push:test` bypasses the
recorder by design and will never write a row).

```sh
grep -c 'push.config_error\|push.delivery_rejected' storage/logs/laravel*.log        # CONFIRM log path; expect 0
```

Result: ****\*\*****\_\_****\*\*****

### Step 18. Post-deploy smoke, by entry

First, the key fingerprint, last time: `grep '^APP_KEY=' .env | sha256sum | cut -c1-12` → **identical to P2**. \***\*\_\_\_\_\*\***

**AH-099 (token door).**

1. `/up` → 200 (step 11).
2. A creator **cookie** session still works on `GET /api/v1/me` (the web path did not move).
3. A platform-admin session on `GET /api/v1/me` of the **main** SPA host is refused, as before.
4. Unknown email on the token door (**do not use a real creator's email**: a correct password mints a live token with no client to hold it,
   a wrong one bumps that creator's lockout counter):

```sh
curl -s -w '\n%{http_code}\n' -X POST https://<API_HOST>/api/v1/auth/token \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"deploy-smoke-nobody@example.invalid","password":"x","platform":"android"}'
php artisan tinker --execute="echo DB::table('personal_access_tokens')->count(), PHP_EOL;"       # APP: still 0
```

Healthy: `401` with `auth.invalid_credentials`, and the count is still `0`.
Result: ****\*\*****\_\_****\*\*****

**AH-100 / 100b / 100c / 100d (web render only).** Web creator sign-in, dashboard, availability page and Edit profile look and behave
as before (square buttons; no polling on the under-review page); a cookie session is in use and **no `Authorization` header** is
sent; `personal_access_tokens` is still `0` after web traffic. AH-100d also repaired the base `assignments` nav label in ten locales
(bg el et fi ga hu lt lv mt ro): switch one (for example `ro`) and read the top nav.
Result: ****\*\*****\_\_****\*\*****

**AH-101.** The creator notification-preferences page shows the grid with an **Email** column; the admin Feature-flags list shows
`post_verified_mail_enabled` **OFF**. No `email/false` rows exist (your read), so the opt-out guard blocks nothing on deploy. The in-app
`assignment.live_verified` row is live and ungated (§4 D1.2); there is no kill switch for it.
Result: ****\*\*****\_\_****\*\*****

**AH-102 (backend + app, web side).** The admin Feature-flags page lists `push_notifications_enabled` (now ON, step 16); the creator
web preferences page has **no Push column** (it is invisible on web); the creator dashboard shows no push soft-ask; `push:test
--validate-only` passed (step 12). Optional, needs a creator session: `PATCH /api/v1/me/notification-preferences` with `channel: push`
is accepted. **CONFIRM** if you want it tested here.
Result: ****\*\*****\_\_****\*\*****

**AH-102b (admin).** The admin sidebar shows **Operations → Push deliveries** and **Creators → Creator app**; Push deliveries loads
empty, with the footer "No push attempts stored yet."; Creator app loads. (Admin SPA rebuilt in step 4.)
Result: ****\*\*****\_\_****\*\*****

**AH-103 (deep links).** Backend: nothing visible without a native build. Web: from the agency bell, a campaign-application row opens the
campaign on the **Applications** tab (`?tab=applications`) if such a row exists; skip honestly if none does. The route keys on push
payloads are proved by the first real push (§6).
Result: ****\*\*****\_\_****\*\*****

### Step 19. Record it [deploy-log.md]

Fill each of the six `PENDING` entries (blanks: snapshot ID, step results, operator, "anything unexpected"), then flip their status to
`DEPLOYED` with today's date. Record deploy state there only (`production-queue-worker.md` §8 step 6). Mark the **pre-deploy
read-backs** already written into each entry's _Pre-deploy reads_ field.
Done: yes / no

---

## 6. Not in this sitting (named, so nothing is assumed done)

- **The native builds** (iOS TestFlight, Android store). Never run a bare `npx cap sync` (it adds the Firebase SDK to the iOS project without
  a `GoogleService-Info.plist`); Android needs JDK 21; a production build needs the uncommitted `.env.native-production`.
- **On the first TestFlight build after this deploy:** the **production-APNs proof** (the sandbox/production token split) **and** the AH-103
  finding-2 iPhone check (sign in, confirm a new `user_push_tokens` row exists, confirm a push is delivered). Both are listed in
  `push-notifications-review.md` (named open items, rows 1 and 4).
- **§7.5 step 2:** one real push to a named test account with a registered token, before the app is announced.
- **The first real push:** after it, `push_deliveries` must be `> 0` (step 17) and the AH-103 route keys can be checked by tapping it.
- **Tech-debt row (D1.5):** the stored `social_verification_enabled` row must be removed or set OFF before a real social adapter is wired.

---

## 7. If something goes wrong

**First rule: stop, record the state, change nothing else.** Then:

| Symptom                                                               | Do                                                                                                                                                                                                                                                                                                                                                   |
| --------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **`APP_KEY` fingerprint differs** (any step)                          | **Restart nothing.** The workers still hold the old key in memory (that is what made the 2026-08-11 recovery possible). Restore the value in `.env` from the recoverable copy (P2), `php artisan config:cache`, reload FPM. Do not run `composer install` again                                                                                      |
| A migration fails midway                                              | Do not re-run blindly. Read the error. Both migrations are single `CREATE TABLE`s, so a failure leaves nothing half-applied except possibly one table: `migrate:status`, then fix forward                                                                                                                                                            |
| Code problem after migrating (API or SPA)                             | **Roll the code back, leave the tables.** Both tables are additive and the old code never touches them. `git checkout <OLD>` on the host, rebuild/republish the SPAs (**CONFIRM** how), reload FPM, `queue:restart`                                                                                                                                  |
| Roll the schema back                                                  | `php artisan migrate:rollback --step=2 --force` drops both tables. Safe while they are empty (they are at deploy). After tokens exist it loses registered tokens (they re-register on next launch) and the delivery log                                                                                                                              |
| **Data problem** (not just schema)                                    | **Restore from the step-1 snapshot.** An RDS restore creates a **new instance**; it does not roll the live one back in place. **CONFIRM** how the app is re-pointed to it. **The restore has never been rehearsed** (`production-queue-worker.md` §8.2), and everything written after the snapshot is lost. Prefer fix-forward for anything additive |
| Pushes misbehave after arming (`push.config_error` burst, wrong copy) | Flip `push_notifications_enabled` **OFF** (admin → Feature flags). The dispatcher returns before any token read; jobs already queued re-check token liveness first; nothing already sent is recalled; tokens still register and in-app rows still write                                                                                              |
| Web login or token door broken (CORS, 419)                            | Re-read `FRONTEND_EXTRA_ORIGINS` and `SANCTUM_STATEFUL_DOMAINS` (step 7). The token door has **no flag**; the only revert is the env change or a code rollback                                                                                                                                                                                       |

**Kill switches in this deploy:**

| Flag                          | Flip OFF to…                                                                              | Caveat                                                                                                                       |
| ----------------------------- | ----------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `push_notifications_enabled`  | Stop **all push sending** (and, with it, the delivery recorder). The one real kill switch | Token registration and in-app rows are unaffected, by design                                                                 |
| `post_verified_mail_enabled`  | Stop the post-verified **email** (it is OFF and must stay OFF over the mock driver)       | Gates mail only; the in-app row is written regardless                                                                        |
| `social_verification_enabled` | Stop mock verification                                                                    | **Strands every new and in-flight post at `posted` / `pending` with no way to advance it** (§4 D1.3). Not a safe kill switch |

**No kill switch exists for:** AH-101's in-app `assignment.live_verified` row (ungated by design), AH-101's email opt-out guard
(subtract-only), AH-099's token door (no flag; nothing calls it yet), device-token registration (`POST /me/push-tokens` is deliberately
outside the flag), and the AH-103 payload route keys (additive; old and new builds both tolerate them, plan §0.4).

---

_Provenance: drafted by Cursor on 2026-10-12 from `docs/runbooks/deploy-log.md`, `production-queue-worker.md` §4, §7, §7.5, §8,
`feature-flags.md`, the repo at `f932eda4`, and the production read-backs Pedram supplied. The read-only `artisan tinker` commands in
P4, P6 and steps 6, 8, 15, 16, 17, the `push:prune-deliveries --dry-run` and the `APP_KEY` fingerprint were run against the **local
dev database** to check their syntax and output shape; **no command has been run against production**. Every item marked CONFIRM is
something the repo does not document._
