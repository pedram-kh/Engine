# Deploy runbook: AH-099 → AH-103, one sitting

**Status: USED 2026-10-12, but not as written.** Pedram's colleague deployed the same night with his usual procedure plus this release's extras; the
results, and the long list of reads that were **not** taken (P0, P6, P7, the GRANT read-backs and others), are in the AH-103 entry of
[`deploy-log.md`](deploy-log.md). Kept as the reference for the P0 discovery reads (mount shape, env file, FPM program); **the checklist for the next deploy is [`deploy-ah104-ah113.md`](deploy-ah104-ah113.md).** Original status line: _drafted for Pedram's review, nothing run._ Written 2026-10-12 from the repo at `f932eda4` and the
production read-backs Pedram supplied the same day. It merges the six `PENDING` entries in [`deploy-log.md`](deploy-log.md)
(AH-099, the AH-100 family, AH-101, AH-102, AH-102b, AH-103) with [`production-queue-worker.md`](production-queue-worker.md) §8
(the checklist), §4 (the worker restart), §7 (the scheduler) and §7.5 (arming push).

**How to use it.** Run the steps in order. Every step has its exact command, what a healthy result looks like, and a blank
`Result:` line. Each step says where it runs: **HOST** (`~/catalyst-engine`) or **APP** (inside `docker compose exec app sh`, at
`/app`). Anything the repo does not document is marked **CONFIRM**: check it on the box before relying on it. The repo's
`docker-compose.yml` is the **local-dev** stack (Postgres, Redis, Mailhog, MinIO); there is **no production compose, nginx or
php-fpm file in the repo**, so the production layout is not written down anywhere I could read. **P0 reads it off the box.**

> **Observed 2026-10-13, after the first deploy: the SPAs are not on the box.** `platform.catalyst-growth.com` and `admin.catalyst-growth.com` are Netlify
> sites; the API is `https://platform-api.catalyst-growth.com`. So P0.6's "nginx `root` serves the SPAs" and step 4's "publish `dist/` to nginx" do not
> describe production; what replaces them (how a Netlify deploy is triggered, and whether it follows `main` automatically) is **not read** and must be
> read from the Netlify site settings before the next deploy. Details in the AH-103 entry of [`deploy-log.md`](deploy-log.md).
> **Closed 2026-10-17:** the deploy source is a **Bitbucket mirror** of GitHub, deployed by the colleague for the API and both SPAs; a push to GitHub never deploys. See [`production-queue-worker.md` §8.0](production-queue-worker.md). The Netlify-settings read is no longer a prerequisite.

> **If P0.2 says `/app` is baked into the image (not a bind mount of `~/catalyst-engine/apps/api`), the deploy steps change.** Code then
> reaches the container by **rebuilding and recreating it** (`docker compose build` / `up -d`), not by `git pull` on the host; steps 2,
> 3, 4, 7, 8, 9 and 10, the §7 code rollback, and where the env file lives would all need rewriting, and a recreate restarts FPM and
> the workers by itself. **Stop after P0 and revise this runbook before deploy day.** Nothing below is written for that shape.

---

## 0. What this deploy is

| Item                                     | Value                                                                                                                                 |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| Last deployed tip (from `deploy-log.md`) | `9b7d6deb` (AH-098, 2026-09-04). read on the box in P0.1                                                                              |
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

| Variable                           | Change                                                          | Notes                                                                                                                                                                                                                                                        |
| ---------------------------------- | --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `FRONTEND_EXTRA_ORIGINS`           | **Append** `capacitor://localhost,https://localhost`            | iOS and Android origins for CORS (AH-099). Comma-separated; keep any existing value                                                                                                                                                                          |
| `SANCTUM_STATEFUL_DOMAINS`         | **No change. Read it and confirm there is NO bare `localhost`** | The Android-only trap: Sanctum strips only `http(s)://`, so a bare `localhost` makes `https://localhost` a stateful origin and every bearer POST 419s. The repo's `.env.example` default contains `localhost`; production must not                           |
| `FCM_PROJECT_ID`                   | Set to `catalyst-production-c28e9`                              | Non-secret                                                                                                                                                                                                                                                   |
| `FCM_SERVICE_ACCOUNT_JSON_B64`     | Set (base64 of the service-account JSON, **one line**)          | **SECRET.** The source is the service-account file **on Pedram's laptop**: `~/secrets/catalyst-production-c28e9-firebase-adminsdk-*.json` (not Secrets Manager). Step 7 ships it in one pipe. Never echo it to the terminal, history, logs, chat or a ticket |
| `SANCTUM_TOKEN_EXPIRATION_MINUTES` | Optional. Unset defaults to `129600` (90 days)                  | A fixed ceiling from token creation. Leave unset unless you want a different one                                                                                                                                                                             |
| `AUTH_GUARD`                       | Read: must be unset or `web`                                    | AH-099 pre-deploy read                                                                                                                                                                                                                                       |

**Where production reads its env from is read in P0.3** (`/app/.env`, a compose `env_file`, or variables injected into the container).
The commands below write `.env` for `/app/.env`; if P0.3 says otherwise, change the file path, not the procedure. **One trap P0.3 checks:**
Laravel's env loader never overrides a variable that is already in the process environment, so a name set by compose beats the same
line in `.env`.

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

**Proved, not just read** (dev database, a rolled-back transaction, the real Pennant stack, 2026-10-12): no row + driver `meta` → `false`; no row +
driver `mock` → `true` (row inserted); **row stored, driver switched to `meta` → still `true`.** Recorded as its own row in
[`docs/tech-debt.md`](../tech-debt.md), "`social_verification_enabled` is frozen ON by Pennant's stored default", with the trigger (before any real
adapter is bound).

---

## 5. The checklist

### Before you start (P0 to P7 are read-only: nothing here changes production)

Do not start step 1 until P0 to P7 are all filled in and healthy.

**P0. Discovery reads: answer every CONFIRM item below.** Each command only reads. Where a command could print a secret it is written to
print names, counts or paths only; **never run a bare `docker compose config`** (it prints the resolved `environment:` values) and never
`cat` an env file. What stays CONFIRM for Pedram because only the AWS console answers it: the **RDS snapshot name and DB instance**, and **how
to get an RDS owner SQL session** (step 1 and step 6).

**P0.1 On-box git tip [HOST]**

```sh
cd ~/catalyst-engine && git rev-parse HEAD && git branch --show-current && git status --short | head && git remote -v
git merge-base --is-ancestor 9b7d6deb HEAD && echo "9b7d6deb is an ancestor of HEAD"
git diff --stat 9b7d6deb HEAD -- apps packages pnpm-lock.yaml | tail -3
```

Healthy: branch `main`, a clean tree, `9b7d6deb` is an ancestor, and the diff stat is **empty** (the checkout's runtime code is the last deployed
tip; anything else means the box is not where `deploy-log.md` says, so stop and reconcile).
Result: `______________`

**P0.2 Is `/app` a bind mount of the host checkout, or baked into the image? [HOST]**

```sh
docker compose ps                                   # the service names, state and image
docker compose ls                                   # which compose file(s) this project uses
CID=$(docker compose ps -q app); echo "$CID"
docker inspect "$CID" --format '{{.Config.Image}} | created {{.Created}} | image {{.Image}}'
docker inspect "$CID" --format '{{range .Mounts}}{{.Type}} {{.Source}} -> {{.Destination}} (rw={{.RW}}){{"\n"}}{{end}}'
docker compose exec app sh -c 'grep " /app" /proc/self/mountinfo; ls -ld /app /app/vendor /app/storage'
stat -c '%i  %n' ~/catalyst-engine/apps/api/composer.json                  # HOST (GNU stat: CONFIRM the host is Linux)
docker compose exec app stat -c '%i  %n' /app/composer.json                # APP
docker history --no-trunc "$(docker inspect "$CID" --format '{{.Image}}')" | grep -E ' (COPY|ADD) ' | cut -c1-180
```

Healthy for this runbook (**bind mount**): the mounts list shows `bind <host path>/apps/api -> /app`, and the two inode numbers are **equal**.
**Baked**: no mount whose destination is `/app` (a named volume only for `storage`, say), the inodes differ, and the history shows a
`COPY … /app` layer. **Mixed** (for example only `storage` or `.env` mounted): write down exactly which paths are mounted. Either of the last two
means: **stop, revise the runbook** (top note).
Mount shape: bind / baked / mixed: `______________` Source path: `______________`
Result: `______________`

**P0.3 Env file location, and what is already in the process environment [HOST, then APP]**

```sh
docker compose config --format json | jq '.services.app | {image, build, env_file, volumes}'   # HOST. Paths only; no environment values
docker compose exec app sh -c 'ls -l /app/.env; php artisan tinker --execute="echo app()->environmentFilePath(), PHP_EOL;"'
docker compose exec app sh -c 'env | cut -d= -f1 | sort | grep -E "^(APP_KEY|FRONTEND_EXTRA_ORIGINS|SANCTUM_|FCM_|AUTH_GUARD|DB_|QUEUE_|CACHE_)"'
docker compose exec app sh -c 'grep -c "^FCM_" /app/.env; id -un'
```

(No `jq`? `docker compose config --format json | python3 -c "import json,sys; s=json.load(sys.stdin)['services']['app']; print(json.dumps({k: s.get(k) for k in ('image','build','env_file','volumes')}, indent=1))"`.)

Healthy: `environmentFilePath()` is the file you will edit in step 7 (expected `/app/.env`), it exists, and `grep -c "^FCM_"` prints **0** (the FCM
variables are not set yet). The `env | cut -d= -f1` line prints **names only**: any of `FRONTEND_EXTRA_ORIGINS`, `SANCTUM_*`, `FCM_*` listed there
is set by compose, **beats `.env`**, and must be changed where compose sets it (the `env_file:` from the first command), not in `.env`. (PHP-FPM
may scrub the environment with `clear_env`; the CLI and the queue worker do not, so a name here can make web and worker disagree.)
Env file for step 7: `______________` Names set by compose: `______________`
Result: `______________`

**P0.4 The PHP-FPM supervisor program, to derive the reload command [APP]**

```sh
docker compose exec app sh -c 'supervisorctl status; echo; grep -rhE "^\[program:|^command=" /etc/supervisor* /etc/supervisord* 2>/dev/null'
docker compose exec app sh -c 'supervisorctl pid <PHP_FPM_PROGRAM>; ps -o pid,ppid,args | grep "[p]hp-fpm"'
```

Healthy: one program runs `php-fpm` (name it from the first command), and the PID that `supervisorctl pid` prints **is the `php-fpm: master process`
line's PID** in the `ps` output (its PPID is supervisord). Then the reload (step 9) is `supervisorctl signal USR2 <PHP_FPM_PROGRAM>`; PHP-FPM
treats `USR2` as a graceful reload of the master. If the program wraps FPM in a shell script, the PID differs: signal the **master** PID from `ps`
instead. If `ps` is missing in the image, `supervisorctl pid` plus `cat /proc/<pid>/cmdline | tr '\0' ' '` shows the same.
Program name: `______________` Reload command: `______________`
Result: `______________`

**P0.5 Where the application logs go [APP, HOST]**

```sh
docker compose exec app php artisan tinker --execute="echo config('logging.default'), ' | ', json_encode(config('logging.channels.stack.channels')), ' | ', storage_path('logs'), PHP_EOL;"
docker compose exec app sh -c 'ls -la storage/logs | tail -5'
docker compose logs --since 1h app | tail -3
```

Healthy: the default is `stack` (or `single` / `daily`) and `laravel*.log` files exist, so the log path is `/app/storage/logs/laravel*.log` and step 17's
`grep` works as written. If the default is `stderr` or `errorlog`, there are no files: step 17 reads `docker compose logs --since 24h app | grep -c …`
instead.
Log path or channel: `______________`
Result: `______________`

**P0.6 The `/up` host, and where nginx serves the SPAs from [APP]**

```sh
docker compose exec app sh -c 'grep -rhnE "^[[:space:]]*(server_name|root|listen)[[:space:]]" /etc/nginx/conf.d /etc/nginx/sites-enabled /etc/nginx/http.d 2>/dev/null'
docker compose exec app php artisan tinker --execute="echo config('app.url'), PHP_EOL;"
docker compose exec app sh -c 'curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1/up'    # no curl in the image? use: wget -S -O /dev/null http://127.0.0.1/up
```

Healthy: `server_name` lines name the production host(s) (the `<APP_HOST>` / `<API_HOST>` of steps 11 and 18); `config('app.url')` agrees; `/up` on
`127.0.0.1` returns `200`. The `root` lines say **where each SPA's `dist/` is served from** (nginx runs inside this container, so that path is
either a bind mount from the host or baked in): `ls -ld --time-style=long-iso <root>` for each, and compare with the P0.2 mounts. This is the answer to
step 4's "where is `dist/` published".
APP_HOST: `______________` API_HOST: `______________` `root` of main SPA: `______________` of admin SPA: `______________`
Result: `______________`

**P0.7 How earlier deploys built and published the SPAs [repo, then HOST]**

I read the repo for this one; **the repo does not answer it.** `git ls-files` tracks no `Dockerfile`, nginx, supervisor or deploy-script file (only the
dev `docker-compose.yml`). `deploy-log.md` says only "`pnpm install` on the build host" (AH-100c) and "SPA (apps/main) rebuilt + API rebuilt"
(AH-097): no command, no path, no host. Re-run in a checkout to see it yourself, then read the box:

```sh
git ls-files | grep -iE 'dockerfile|compose|nginx|supervisor|deploy'                           # CHECKOUT: expect only docker-compose.yml (dev), docs and useDeployEnv*.ts (a false match); no Dockerfile
grep -nE -i 'build host|rebuilt|rebuild|pnpm install|dist/' docs/runbooks/deploy-log.md | head -40   # CHECKOUT
ls -la ~/catalyst-engine ~/catalyst-engine/apps/api | head -40                                  # HOST
find ~/catalyst-engine -maxdepth 3 \( -iname 'Dockerfile*' -o -iname 'docker-compose*.y*ml' -o -iname 'deploy*.sh' \) -not -path '*/node_modules/*' -not -path '*/vendor/*' 2>/dev/null
git -C ~/catalyst-engine status --short --ignored | head -20                                    # untracked or ignored production files (a compose override, a script)
command -v pnpm node; node -v; pnpm -v                                                           # is the host also the build host?
stat -c '%y  %n' ~/catalyst-engine/apps/main/dist ~/catalyst-engine/apps/admin/dist 2>&1        # when the bundles were last built
```

Healthy: you can say, in one line each, **which machine builds**, **where the build output goes**, and **how it reaches the nginx `root` from P0.6**
(already there as a bind mount, copied in, or baked by an image rebuild). The `dist/` timestamps should be near the last deploy (AH-098, 2026-09-04). Your
own shell history on the box is the likeliest record of the exact commands: read it yourself, do not paste it anywhere (it may hold a secret typed inline).
Build host: `______________` Publish path: `______________`
Result: `______________`

**P1. Where is the box now? [HOST]**

```sh
cd ~/catalyst-engine && git status --short | head && git rev-parse HEAD && git fetch origin && git log --oneline HEAD..origin/main | wc -l
```

Healthy: a clean tree, `HEAD` is `9b7d6deb` or a docs-only descendant, and a large positive commit count behind `origin/main`.
Write the on-box tip down: step 3's diff uses it. Whether `/app` is the same tree as the host checkout is P0.2.
Result: `______________`

**P2. APP_KEY fingerprint and a recoverable copy [APP]**

```sh
grep '^APP_KEY=' .env | sha256sum | cut -c1-12
```

Healthy: prints 12 hex characters. Write it down; it is compared at steps 2, 3, 7, 8 and 18. Also confirm, **without printing the
key**, that a copy of the production `APP_KEY` exists outside the box (`docs/05-SECURITY-COMPLIANCE.md` says it is in AWS Secrets
Manager): **CONFIRM** it is current. If `.env` is not where the key lives, fingerprint wherever it does.
Fingerprint: `______________` Recoverable copy confirmed: yes / no

**P3. Preview the migrations [APP]**

```sh
php artisan migrate:status | tail -5
```

Healthy: the last line is `2026_08_27_140000_create_campaign_attachments_table … Ran` and nothing is `Pending`. (Matches your read: batch 8, nothing from AH-099 onward.)
Result: `______________`

**P4. AH-099's pre-deploy reads [APP]** (not yet read)

```sh
php artisan migrate:status | grep personal_access_tokens
php artisan tinker --execute="echo DB::table('personal_access_tokens')->count(), PHP_EOL;"
php artisan tinker --execute="echo json_encode([config('sanctum.stateful'), config('auth.defaults.guard')]), PHP_EOL;"
```

Healthy: the stock `…create_personal_access_tokens_table` shows `Ran`; the count is `0`; the stateful list has **no bare `localhost`**
(only real production hostnames) and the guard is `web`. `config('sanctum.stateful')` reflects cached config if it is cached; if you
suspect a stale cache, read the `SANCTUM_STATEFUL_DOMAINS` line in `.env` too.
Result: `______________`

**P5. The FCM credential file on your laptop: present and the right shape, without printing it [LAPTOP]** (not yet read)

The credential is the service-account JSON on Pedram's laptop, **not** in Secrets Manager. The production variable does not exist yet (step 7
creates it); here you only prove the source file is the right one.

```sh
ls -l ~/secrets/catalyst-production-c28e9-firebase-adminsdk-*.json
jq -e '.type=="service_account" and .project_id=="catalyst-production-c28e9" and (.client_email|length>0) and (.private_key|startswith("-----BEGIN PRIVATE KEY-----"))' ~/secrets/catalyst-production-c28e9-firebase-adminsdk-*.json >/dev/null && echo shape-ok
```

(No `jq`? `python3 -c "import json,glob,os; d=json.load(open(glob.glob(os.path.expanduser('~/secrets/catalyst-production-c28e9-firebase-adminsdk-*.json'))[0])); print(d.get('type')=='service_account' and d.get('project_id')=='catalyst-production-c28e9' and bool(d.get('client_email')) and str(d.get('private_key','')).startswith('-----BEGIN PRIVATE KEY-----'))"` prints `True`.)

Healthy: **exactly one** file is listed (step 7's command refuses to run on zero or several), and the second command prints `shape-ok`. Neither prints
the contents.
Result: `______________`

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
Result: `______________`

**P7. Pre-flight: no second scheduler (a stop condition) [APP, HOST]**

`laravel-schedule` runs `schedule:work`. A `schedule:run` line in **any** crontab would fire every daily command a second time (digests twice,
overdue events twice, the prune twice). Read every place one could hide.

```sh
# APP
supervisorctl status | grep -E 'cron|laravel-schedule'                       # both RUNNING
for u in $(cut -d: -f1 /etc/passwd); do crontab -l -u "$u" 2>/dev/null | sed "s/^/[$u] /"; done
ls -la /etc/crontabs /etc/cron.d /var/spool/cron/crontabs /etc/cron.hourly /etc/cron.daily 2>&1 | head -40
grep -rnE 'schedule:(run|work)|artisan' /etc/crontab /etc/crontabs /etc/cron.d /etc/cron.hourly /etc/cron.daily /var/spool/cron 2>/dev/null
ps -o pid,ppid,args | grep -E 'schedule:(run|work)' | grep -v grep
# HOST (a cron there that runs `docker compose exec … schedule:run` double-fires too)
crontab -l 2>&1; sudo crontab -l 2>&1; ls /etc/cron.d; sudo grep -rn 'schedule' /etc/cron* /var/spool/cron* 2>/dev/null
```

Healthy: `supervisorctl` shows a **`cron` program RUNNING alongside `laravel-schedule`**; **no `schedule:run` line anywhere** (the `crontab -l` loop,
`/etc/crontabs`, `/etc/cron.d` and the `grep` all print nothing about it; other cron jobs may exist, so read each line you do see); and the process
list shows exactly one `schedule:work` plus, at most, a short-lived `schedule:run` whose PPID is that `schedule:work` (that is what `schedule:work`
does every minute). **If a `schedule:run` cron line exists, STOP.** Double-firing digests is a production issue to fix **before** this deploy,
not during it: do not proceed to step 1.
Result: `______________`

### Step 1. Snapshot [wait for `available`]

Take the database snapshot in the AWS console or CLI. **CONFIRM** the identifier and the DB instance name (the repo has neither).
Then wait until it reports `available`. A snapshot still `creating` is not a safety net (§8 step 1).

```sh
# example form, CONFIRM the instance/snapshot names:
aws rds describe-db-snapshots --db-snapshot-identifier <SNAPSHOT_ID> --query 'DBSnapshots[0].Status' --output text
```

Healthy: `available`. **Record the ID in the deploy-log entries before step 5.** Both new tables are `CREATE TABLE`; the snapshot is the
restore point for both migrations.
Snapshot ID: `______________` Status read: `______________`

### Step 2. Pull the code [HOST]

```sh
cd ~/catalyst-engine
OLD=$(git rev-parse HEAD); echo "OLD=$OLD"
git pull --ff-only origin main        # remote and branch from P0.1; the deploy log says only "pulled origin/main", so this is the plain form
git rev-parse HEAD
```

Healthy: a fast-forward, no conflict, and the new `HEAD` is the tip you intend. Then re-fingerprint the key **[APP]**:

```sh
grep '^APP_KEY=' .env | sha256sum | cut -c1-12
```

Healthy: **identical to P2.**
New HEAD: `______________` Fingerprint after: `______________` Same as P2: yes / no

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

diff empty: yes / no Fingerprint: `______________` Same as P2: yes / no

### Step 4. JS dependencies and the two SPA builds [where this runs: P0.7]

The deploy log says only "`pnpm install` on the build host" and "SPA rebuilt"; **how the bundles are built and published is not in
the repo**; P0.6 and P0.7 answer which machine builds them, where `dist/` is served from (the nginx `root`) and how it is swapped in. Copy those answers here before you run anything.
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
browsers never see a bundle that calls an API the box is not yet running. P0.7's answer on how publishing works decides which.
Result: `______________`

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
Output: `______________`

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
Role: `______________` Output: `______________`

### Step 7. Environment [env file from P0.3; the secret from your LAPTOP]

Three lines change (§2). Two are plain, one is a secret. **The secret must never reach the terminal, shell history, logs, the clipboard or chat.**
If P0.3 found any of these names set by compose, change them there instead and skip the matching edit below.

**7a. The two non-secret lines [APP, edit by hand].** Read the current line first (it holds no secret), then edit it in the box's editor:

```sh
grep -n '^FRONTEND_EXTRA_ORIGINS=' .env       # then make it: FRONTEND_EXTRA_ORIGINS=<what it had>,capacitor://localhost,https://localhost
printf 'FCM_PROJECT_ID=catalyst-production-c28e9\n' >> .env
```

Keep whatever value `FRONTEND_EXTRA_ORIGINS` already had; an empty line becomes the two origins alone (the config drops empty entries). If it is
quoted, put the new origins **inside** the quotes.

**7b. The FCM service-account secret [LAPTOP → env file, one pipe].** Base64-encode on the laptop with no newlines and stream it straight into the
env file. The value exists only inside the pipe: not on screen, not in history (the command text contains no value), not in a temp file, not on
the clipboard. Take `<ENV_FILE>` from P0.3 and `<HOST>` is the box's usual `ssh` login. **Bind mount** (the env file is on the host):

```sh
set -- ~/secrets/catalyst-production-c28e9-firebase-adminsdk-*.json
if [ $# -ne 1 ] || [ ! -f "$1" ]; then echo "expected exactly one key file, got: $#"; else
  base64 < "$1" | tr -d '\n' | ssh <HOST> 'tail -c1 <ENV_FILE> | grep -q . && echo >> <ENV_FILE>; { printf FCM_SERVICE_ACCOUNT_JSON_B64=; cat; echo; } >> <ENV_FILE>'
fi
```

**Env file only inside the container** (P0.3 found no bind mount): the same body, wrapped. Use this line in place of the `base64 …` line inside the
`if` above. It has no quotes and no `$`, so it survives the extra layer; `-T` keeps stdin as the pipe:

```sh
  base64 < "$1" | tr -d '\n' | ssh <HOST> 'cd ~/catalyst-engine && docker compose exec -T app sh -c "tail -c1 /app/.env | grep -q . && echo >> /app/.env; { printf FCM_SERVICE_ACCOUNT_JSON_B64=; cat; echo; } >> /app/.env"'
```

What the remote body does: if the file's last byte is not a newline it adds one (otherwise the new variable would be glued onto the last line), then
appends `FCM_SERVICE_ACCOUNT_JSON_B64=<base64>` and a newline. (Tested locally on a fake key file and fake env files with and without a trailing
newline, and an empty one: the last line stays intact, exactly one new line, its length equals the base64 length, and it decodes back to the file.
That test used no real credential.)

**Never:** run `base64 …` without the pipe, `cat` or `echo` the JSON, the encoded value or the env file, `docker compose config`, `env | grep FCM`,
`docker inspect` without a `--format`, or paste any of it into chat or a ticket. (Step 8's tinker line prints only whether the value is set.)

**7c. Read back names, counts and lengths: never the value [APP].**

```sh
grep '^APP_KEY=' .env | sha256sum | cut -c1-12                                   # identical to P2
grep -c '^FCM_PROJECT_ID=catalyst-production-c28e9$' .env                        # expect 1
grep -c '^FCM_SERVICE_ACCOUNT_JSON_B64=' .env                                    # expect exactly 1 (no duplicate line)
grep -c '^FCM_SERVICE_ACCOUNT_JSON_B64=.\+' .env                                 # (i) non-empty: expect 1; prints a count, never the value
awk -F= '/^FCM_SERVICE_ACCOUNT_JSON_B64=/{print length($0)-length("FCM_SERVICE_ACCOUNT_JSON_B64=")}' .env   # a number, never the value
grep '^FRONTEND_EXTRA_ORIGINS=' .env                                             # must contain capacitor://localhost and https://localhost
grep '^SANCTUM_STATEFUL_DOMAINS=' .env                                           # must contain NO bare "localhost"
```

Healthy: as annotated. The length is **a few thousand characters** (a service-account file is about 2.3 KB and base64 adds a third); a number under
about a hundred means a truncated or empty pipe, so remove the line and redo 7b. The credential is **verified only by (i) the non-empty check above
and (ii) `push:test --validate-only` in step 12**: nothing else may print it. These changes are **not live until step 8**.
Fingerprint: `______________` FRONTEND_EXTRA_ORIGINS read: `______________` FCM lines: `______________` length: `______________`

### Step 8. Cache config [APP]

```sh
php artisan config:cache
grep '^APP_KEY=' .env | sha256sum | cut -c1-12                                   # identical to P2
php artisan tinker --execute="echo json_encode(['origins'=>config('cors.allowed_origins'),'fcm_project'=>config('integrations.push.fcm.project_id'),'fcm_secret_set'=>config('integrations.push.fcm.service_account_json_b64')!==null]), PHP_EOL;"
```

Healthy: `config:cache` succeeds; the origins list includes `capacitor://localhost` and `https://localhost`; `fcm_project` is
`catalyst-production-c28e9`; `fcm_secret_set` is `true`. If your CORS config uses a different key, read `config('cors')` whole
(`config/cors.php` reads `FRONTEND_EXTRA_ORIGINS` into `cors.allowed_origins`; checked against the repo and run on the dev database).
Result: `______________`

### Step 9. PHP-FPM reload [command from P0.4]

```sh
supervisorctl status <PHP_FPM_PROGRAM>                          # name from P0.4
supervisorctl signal USR2 <PHP_FPM_PROGRAM>                     # graceful reload; or `kill -USR2 <master pid from P0.4>` if the program wraps FPM
supervisorctl status <PHP_FPM_PROGRAM>
```

Why: opcache serves stale bytecode otherwise (the AH-097 and 2026-08-11 entries in `deploy-log.md` record the reload as a required step). The repo does
not say how the box reloads FPM; P0.4 derived the command above from the box's own supervisor config.
Healthy: FPM stays RUNNING (uptime unchanged for the supervisor program, since USR2 reloads the master in place) and `/up` still answers. **Publish the
SPA bundles now** if step 4 deferred it.
Result: `______________`

### Step 10. Restart the worker [APP]

```sh
php artisan queue:restart
sleep 10
supervisorctl status laravel-queue
```

Healthy: `RUNNING` with an uptime of seconds (supervisor restarted it onto the new code). Without this the worker runs the old job and
mail classes (§4 of the queue runbook). `laravel-schedule` is **not** restarted (§3.2).
Result: `______________`

### Step 11. `/up` and one authenticated request

```sh
curl -s -o /dev/null -w '%{http_code}\n' https://<APP_HOST>/up         # <APP_HOST> from P0.6; expect 200
```

Then log in on the web app (creator or admin) and load the dashboard. Healthy: `200` and the authenticated page renders. If either
fails, stop and assess (§8 step 5).
Result: `______________`

### Step 12. FCM credential [APP]

```sh
php artisan push:test --validate-only
```

Healthy: **`Credential OK: the provider accepted authentication…`**. Anything else is a `config_error`, `retryable` or `rejected`
line: read the code, **do not arm**. This decodes `FCM_SERVICE_ACCOUNT_JSON_B64`, does the OAuth exchange and validates a request
for a placeholder token; it is the credential read-back.
Result: `______________`

### Step 13. Prune dry-run [APP]

```sh
php artisan push:prune-deliveries --dry-run
```

Healthy: **`would delete 0`** (the table is empty). Nothing is deleted.
Result: `______________`

### Step 14. Scheduler [APP]

```sh
supervisorctl status laravel-schedule
php artisan schedule:list
```

Healthy: `laravel-schedule` RUNNING; the list includes `push:prune-deliveries` alongside `messages:send-digest`, `boards:scan-overdue` and
`creators:send-incomplete-nudges`. This is `schedule:work`, **not** cron, so the AH-102b entry's `crontab -l` read does not apply
(production's `cron` service is running, but the scheduler is the supervisor program). P7 already proved there is no _second_ `schedule:run`
line; if anything has changed since, re-run P7 before you go on.
Result: `______________`

### Step 15. Apply the Task 1 decision

**Recommended (§4): no flag change.** Run the read-backs only:

```sh
php artisan tinker --execute="var_dump(Laravel\Pennant\Feature::active('social_verification_enabled'), Laravel\Pennant\Feature::active('post_verified_mail_enabled'), config('integrations.social.driver'));"
```

Healthy: `bool(true)` for social verification (unchanged, as it has been since 2026-07-14), **`bool(false)` for
`post_verified_mail_enabled` (must stay OFF)**, `"mock"`.

**If you override and switch it OFF:** admin → Feature flags → `social_verification_enabled` OFF, then re-run the line above and read
`bool(false)`. Know what that does first (§4 D1.3): new and in-flight posts are stuck `posted` / `pending` with no resolution path.
Decision taken: recommended / OFF / other: `______________` Read-back: `______________`

### Step 16. Arm `push_notifications_enabled` [admin Feature-flags page]

Preconditions (`feature-flags.md:60`, `production-queue-worker.md` §7.5): the FCM variables are set and decode (step 12 passed); the
worker restarted after this deploy (step 10); the Android device proof passed (local walk, 2026-10-11, E3 11 of 12). **Arm before the first
push-capable store build reaches users**, or their first sessions register tokens that never receive anything.

**Arming is inert at deploy time.** `user_push_tokens` is a brand-new, empty table (step 5 just created it) and no native build that registers
tokens is out yet, so the dispatcher finds no token to send to: arming opens the gate for tokens that register later and sends **nothing now**. That
also means arming proves nothing about delivery (step 17 and §6 say what does). Prove it is inert rather than assume it:

```sh
php artisan tinker --execute="echo DB::table('user_push_tokens')->count(), PHP_EOL;"       # APP: expect 0 before you arm
```

If it is **not** `0`, some build is already registering tokens; arming would make those devices reachable, so decide that on purpose before you
flip it.

1. Admin → **Feature flags** → `push_notifications_enabled` **ON**.
2. Read it back:

```sh
php artisan tinker --execute="var_dump(Laravel\Pennant\Feature::active('push_notifications_enabled'));"
php artisan tinker --execute="echo json_encode(DB::select(\"select name, scope, value, created_at, updated_at from features where name = 'push_notifications_enabled'\")), PHP_EOL;"
```

Healthy: `bool(true)`, and one stored row with `value` `true`, `updated_at` just now.

`production-queue-worker.md` §7.5 step 2 (one real push to a named test account) needs a **production-pointed** build with a
registered token; none exists yet. **Do it before you announce the app**, not in this sitting.
Result: `______________`

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
grep -c 'push.config_error\|push.delivery_rejected' storage/logs/laravel*.log        # path/channel from P0.5 (stderr: use `docker compose logs --since 24h app | grep -c …`); expect 0
```

Result: `______________`

### Step 18. Post-deploy smoke, by entry

First, the key fingerprint, last time: `grep '^APP_KEY=' .env | sha256sum | cut -c1-12` → **identical to P2**. `______________`

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
Result: `______________`

**AH-100 / 100b / 100c / 100d (web render only).** Web creator sign-in, dashboard, availability page and Edit profile look and behave
as before (square buttons; no polling on the under-review page); a cookie session is in use and **no `Authorization` header** is
sent; `personal_access_tokens` is still `0` after web traffic. AH-100d also repaired the base `assignments` nav label in ten locales
(bg el et fi ga hu lt lv mt ro): switch one (for example `ro`) and read the top nav.
Result: `______________`

**AH-101.** The creator notification-preferences page shows the grid with an **Email** column; the admin Feature-flags list shows
`post_verified_mail_enabled` **OFF**. No `email/false` rows exist (your read), so the opt-out guard blocks nothing on deploy. The in-app
`assignment.live_verified` row is live and ungated (§4 D1.2); there is no kill switch for it.
Result: `______________`

**AH-102 (backend + app, web side).** The admin Feature-flags page lists `push_notifications_enabled` (now ON, step 16); the creator
web preferences page has **no Push column** (it is invisible on web); the creator dashboard shows no push soft-ask; `push:test
--validate-only` passed (step 12). Optional, needs a creator session: `PATCH /api/v1/me/notification-preferences` with `channel: push`
is accepted. **CONFIRM** if you want it tested here.
Result: `______________`

**AH-102b (admin).** The admin sidebar shows **Operations → Push deliveries** and **Creators → Creator app**; Push deliveries loads
empty, with the footer "No push attempts stored yet."; Creator app loads. (Admin SPA rebuilt in step 4.)
Result: `______________`

**AH-103 (deep links).** Backend: nothing visible without a native build. Web: from the agency bell, a campaign-application row opens the
campaign on the **Applications** tab (`?tab=applications`) if such a row exists; skip honestly if none does. The route keys on push
payloads are proved by the first real push (§6).
Result: `______________`

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
- **Tech-debt row (D1.5), now in `docs/tech-debt.md`:** the stored `social_verification_enabled` row must be removed or set OFF before a real social adapter is wired.

---

## 7. If something goes wrong

**First rule: stop, record the state, change nothing else.** Then:

| Symptom                                                               | Do                                                                                                                                                                                                                                                                                                                                                   |
| --------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **`APP_KEY` fingerprint differs** (any step)                          | **Restart nothing.** The workers still hold the old key in memory (that is what made the 2026-08-11 recovery possible). Restore the value in `.env` from the recoverable copy (P2), `php artisan config:cache`, reload FPM. Do not run `composer install` again                                                                                      |
| A migration fails midway                                              | Do not re-run blindly. Read the error. Both migrations are single `CREATE TABLE`s, so a failure leaves nothing half-applied except possibly one table: `migrate:status`, then fix forward                                                                                                                                                            |
| Code problem after migrating (API or SPA)                             | **Roll the code back, leave the tables.** Both tables are additive and the old code never touches them. `git checkout <OLD>` on the host, rebuild/republish the SPAs (how: P0.7), reload FPM, `queue:restart`                                                                                                                                        |
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
dev database** to check their syntax and output shape; **no command has been run against production**. Revised 2026-10-12 after review: P0
(discovery reads), P7 (second-scheduler pre-flight), the FCM source and the Step 7 procedure. **What is still CONFIRM, because only you can answer
it:** the RDS snapshot name and DB instance (step 1), how to get an RDS owner SQL session and which role runs `migrate` (step 6), a current
off-box copy of the `APP_KEY` (P2), and how the app is re-pointed after an RDS restore (§7). Everything else the repo does not document is read in P0._
