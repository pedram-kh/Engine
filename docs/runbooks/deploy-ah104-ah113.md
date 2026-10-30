# Deploy runbook: AH-104 → AH-113, one sitting

**Status: USED 2026-10-29, not as written** (the colleague's run; the record, and what was **not read**, is in the [`deploy-log.md`](deploy-log.md) entry "AH-106 + AH-107 + AH-108 + AH-103b + AH-109 …"). Differences already known: the erasure program is named **`laravel-erasure`** and lives in `apps/api/.docker/prod.supervisord.conf` on the box (not in the GitHub repo); the container was recreated; the mirror shows this file's commit as `a60ffbbf`. Original status line: **DRAFTED 2026-10-29, nothing run.** For Pedram's colleague (who runs the box), in the shape of [`deploy-ah099-ah103.md`](deploy-ah099-ah103.md).
Written from the repo at `066ff29c` and the `PENDING` entry in [`deploy-log.md`](deploy-log.md)
("AH-106 + AH-107 + AH-108 + AH-103b + AH-109 …"), which carries every entry below. Procedure behind it:
[`production-queue-worker.md`](production-queue-worker.md) §2a (the erasure worker), §4, §7, §8 and [`account-erasure.md`](account-erasure.md).

**How to use it.** Run P0 to P9 in order. Every step has its exact command, what a healthy result looks like, and a blank
`Result:` line to fill. Each step says where it runs: **HOST** (`~/catalyst-engine`), **APP** (inside `docker compose exec app sh`, at `/app`),
**BUILD** (the machine that builds the SPAs) or **LAPTOP** / **CONSOLE** (Pedram). Anything the repo does not document is marked **CONFIRM**.
**No secret is printed by any command here**: fingerprints, counts, names and hostnames only. Never run a bare `docker compose config`, never `cat`
or `echo` an env file, never paste shell history anywhere.

> **Layout assumed (from the 2026-10-12 sitting, [`deploy-log.md`](deploy-log.md) AH-103 entry):** the API runs in a container `app` at `/app` under
> supervisor (`laravel-queue`, `laravel-schedule`, php-fpm, nginx, cron), behind `https://platform-api.catalyst-growth.com`; queue `database`, cache
> Redis, mail Postmark; the database is an **Aurora PostgreSQL cluster in `eu-west-1`**; both SPAs are Netlify sites (`platform.catalyst-growth.com`,
> `admin.catalyst-growth.com`); the deploy source is the **Bitbucket mirror** of GitHub, run by the colleague ([`production-queue-worker.md`](production-queue-worker.md) §8.0).
> That sitting did not record the mount shape, the env file path or the PHP-FPM program name. **Take them from your usual procedure** (the earlier runbook's P0.2 to P0.4 read
> them) and write any difference in the `Result:` line. **If `/app` turns out to be baked into the image rather than a bind mount, stop and revise this runbook**: the code
> then reaches the container by rebuilding it, not by `git pull`.

---

## 0. What this deploy is

**PROD-DATA RISK: MEDIUM.** Five migrations, all additive (§2); **nothing is erased, requested or claimed by deploying**, because both account flags stay OFF; one bulk write that only moves
`notifications.read_at` from null to a time (P5); one scheduled delete that only removes tokens Sanctum already rejects (§1, AH-103d).

| Item                                     | Value                                                                                                                                                                                       |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Last deployed tip (from `deploy-log.md`) | `47d2efc5` (AH-103's last runtime commit, deployed 2026-10-12). The three commits after it on that day were docs-only                                                                       |
| Deploy tip                               | **`066ff29c`** (`origin/main` when this was written). 161 commits from `47d2efc5`, which is an ancestor (checked). A docs-only commit (this runbook) follows it; it changes no runtime file |
| Migrations                               | **5**, all additive: 4 `ALTER`/`CREATE INDEX` and **1 `CREATE TABLE`** (`erasure_ledger`) (§2)                                                                                              |
| PHP dependencies                         | **No change**: `apps/api/composer.json` and `composer.lock` are identical at `47d2efc5` and `066ff29c` (empty `git diff --stat`, run 2026-10-29)                                            |
| JS dependencies                          | **No change**: `pnpm-lock.yaml` and the four `package.json` files are identical (empty diff); the SPAs still need a rebuild                                                                 |
| SPA rebuilds                             | `apps/main` (carries `/delete-account`, `/.well-known/apple-app-site-association`, `_headers`) and `apps/admin` (the Erasure queue)                                                         |
| Env changes                              | **None required.** `SUPPORT_EMAIL` is optional (default `support@catalyst-growth.com`). `ERASURE_PURGE_OBJECT_VERSIONS` stays at its default (true)                                         |
| Worker restart                           | **Required** (`PushMessageFactory`, `FcmPushSender`, `RunErasureJob`, `AccountDeletionRequestedMail`, its listener, `VerifyPostedContentJob`, the AH-113 mail strings, the AH-109 view)     |
| PHP-FPM reload                           | **Required** (new module, providers, routes, config, the sign-up limiter)                                                                                                                   |
| Scheduler                                | **One new entry**, `sanctum:prune-expired --hours=24` (daily). No scheduler restart (§3.2 of the earlier runbook: `schedule:work` starts a fresh process every minute)                      |
| New supervisor program                   | **`catalyst-erasure-worker`** for queue `database_erasure` (P4)                                                                                                                             |
| New config (no env change)               | `config/queue.php` gains the connection `database_erasure` (`retry_after` 3700); `config/erasure.php`; `config/app.php` gains `support_email`. All need `config:cache` (P4)                 |
| One-shot command                         | `notifications:clear-read-message-notifications` (P5), dry-run first                                                                                                                        |
| Flags armed in this sitting              | **None.** `account_deletion_enabled` OFF and `account_erasure_execute_enabled` OFF; `push_notifications_enabled` unchanged (P7)                                                             |
| Not in this sitting                      | TestFlight build 3, the production walks, the demo account, the flag flips, the Monday erasure routine, the first real erasure (§3)                                                         |

### Where each entry's obligation lives

| Entry                  | What it carries                                                                                                                                                                | Step             |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------- |
| AH-106, AH-107         | Web only (back arrow, chip); AH-107 adds a read-only `campaign_status` field on four creator endpoints. No migration                                                           | P1, P8           |
| AH-108                 | `message_unread_count` on the unread-count endpoint; push payload gains `aps.badge` (**`queue:restart` is a named step**); the backlog command                                 | P4, P5           |
| AH-103b                | Web build only: the association file and `_headers`. **C1 to C7 gate TestFlight build 3**                                                                                      | P1, P6           |
| AH-103d                | `sanctum:prune-expired` scheduled daily (pre-read, `schedule:list` check, next-day count). Its app part rides build 3                                                          | P0.5, P4, P8     |
| AH-109                 | Blade footer of the job-posted mail (rendered by the worker)                                                                                                                   | P4 (restart), P8 |
| AH-104a                | Three migrations; commands `erasure:run`, `erasure:reapply` (artisan only, no route, not scheduled); the ledger table needs GRANTs                                             | P2, P3, P8       |
| AH-104b-del A and B    | One migration; a queued mail and listener (`queue:restart`); the public `/delete-account` page; `is_erased` on agency payloads; the endpoint answers 404 while the flag is OFF | P2, P4, P7, P8   |
| AH-104c C1, C2, C3     | One migration; the Erasure queue page; the Erase button behind `account_erasure_execute_enabled`; **the second supervisor program**; the **Q10 rule**                          | P2, P4, P7, P8   |
| AH-104d                | The per-email sign-up limiter (`auth-signup-email`, 5 an hour): FPM reload only; the sign-up sheet on the web build                                                            | P4, P8           |
| AH-105, AH-111, AH-112 | Web only (native banner, Report a problem line, privacy link). **Nothing in the API.** The rest (S1, store text, seeder) rides build 3 or nothing                              | P1, P8           |
| AH-113                 | Deletion mail wording (`lang/*/auth.php`, read by the worker: covered by `queue:restart`) and two web strings                                                                  | P4, P8           |

---

## 1. Four rules for the sitting

1. **Stop on an `APP_KEY` change.** Fingerprint it (a hash of the line, never the value) at P0, after P1, after P4 and at the end of P8. If it ever differs: **restart nothing** (the running workers hold the old key in memory, which is what made the
   2026-08-11 recovery possible), restore the value from the recoverable copy, `config:cache`, reload FPM. `composer install` is not run at all (P1.2).
2. **Q10: no deploy and no `queue:restart` while an erasure is claimed.** The check is `select count(*) from users where erasure_started_at is not null and erased_at is null` and must print `0`. It can only be asked **after P2** (the column does not exist before) and **before P4's `queue:restart`**.
   It is safe here because **no erasure can be claimed on the box yet**: the claim columns do not exist until P2, the Erase endpoint answers 409 while `account_erasure_execute_enabled` is OFF (and it is not even routed until this code is live), no scheduler entry or route reaches `erasure:run`
   ([`account-erasure.md`](account-erasure.md) §1), and the only other way in is a person typing `erasure:run --execute`. Nobody does that in this sitting.
3. **Flags stay OFF.** No flag is flipped in this sitting (P7 reads them back). The risk events of this release are the flag flips, and they come after TestFlight build 3 and the production walks, as separate acts.
4. **One command at a time for anything that writes** (P2, P3 grants, P5). Read the output before the next one.

**What `sanctum:prune-expired --hours=24` deletes (read from the vendor class 2026-10-29, which corrects the one-line description in the log entry).** It has **two** legs: tokens whose `expires_at` passed more than 24 h ago, **and** tokens whose `created_at` is older than
`sanctum.expiration` + 24 h (here 129 600 + 1 440 minutes = 91 days). Both only remove tokens Sanctum already refuses (`TokenLoginController` sets `expires_at` to creation + `sanctum.expiration`; nothing slides it), so no live token is touched **as long as
`SANCTUM_TOKEN_EXPIRATION_MINUTES` is unset or unchanged**. P0.5 reads the setting and both counts.

---

## 2. The checklist

### P0. Before (read-only except the snapshot itself)

**P0.1 A fresh snapshot: name and creation time, must be today [CONSOLE or any shell with the AWS CLI]**

The previous deploy's snapshot ID was dated 2026-08-19 and its creation time was never read. **That snapshot is not acceptable here.** Take a new one in the quiet minute just before P1 (anything written after the snapshot is not in it), then read it:

```sh
aws rds describe-db-clusters --region eu-west-1 --query 'DBClusters[].DBClusterIdentifier' --output text      # CONFIRM the cluster id; Aurora cluster, not an instance
aws rds create-db-cluster-snapshot --region eu-west-1 --db-cluster-identifier <CLUSTER_ID> --db-cluster-snapshot-identifier ah104-ah113-predeploy-<YYYYMMDD>
aws rds describe-db-cluster-snapshots --region eu-west-1 --db-cluster-snapshot-identifier ah104-ah113-predeploy-<YYYYMMDD> \
  --query 'DBClusterSnapshots[0].[DBClusterSnapshotIdentifier,SnapshotCreateTime,Status,PercentProgress]' --output text
date -u '+%Y-%m-%d %H:%M UTC'
```

(If the database turns out to be a plain RDS instance, use `describe-db-snapshots` and `--db-snapshot-identifier`.) Healthy: the name is the one you just created, **`SnapshotCreateTime` has today's UTC date**, `Status` is **`available`** and progress `100`. A snapshot still `creating` is not a safety net: **do not start P1**.
Pedram's colleague confirms the console agrees. Note, as in [`production-queue-worker.md`](production-queue-worker.md) §8.2, that a restore creates a **new** cluster and has never been rehearsed.
Snapshot name: `______________` Creation time (UTC): `______________` Status: `______________` Today's date (UTC): `______________`
Result: `______________`

**P0.2 `APP_KEY` fingerprint, and a recoverable copy [APP]**

```sh
grep '^APP_KEY=' .env | sha256sum | cut -c1-12
```

Healthy: 12 hex characters. Write them down; they are compared after P1, after P4 and at the end of P8. Also confirm (without printing the key) that a current copy of the production `APP_KEY` exists outside the box (`docs/05-SECURITY-COMPLIANCE.md` says Secrets Manager): **CONFIRM**.
If the env file is not `/app/.env` (the earlier sitting did not record it), fingerprint wherever the key lives and say where.
Fingerprint: `______________` Env file: `______________` Recoverable copy confirmed: yes / no
Result: `______________`

**P0.3 Did `composer.lock` change since `47d2efc5`? [any checkout, then HOST]**

```sh
git diff --stat 47d2efc5 066ff29c -- apps/api/composer.json apps/api/composer.lock pnpm-lock.yaml     # run 2026-10-29 in the development checkout
```

**Answer written at drafting time: NO.** The output is empty: neither `composer.json` nor `composer.lock` nor `pnpm-lock.yaml` changed between the deployed tip and `066ff29c`. Repeat it on the box so the answer is the box's own:

```sh
cd ~/catalyst-engine && git rev-parse HEAD && git status --short | head -5
git merge-base --is-ancestor 47d2efc5 HEAD && echo "47d2efc5 is an ancestor of HEAD"
git diff --stat 47d2efc5 HEAD -- apps packages pnpm-lock.yaml | tail -3            # expected: empty (the box is at the deployed runtime tip)
```

Healthy: a clean tree, `47d2efc5` is an ancestor, the diff stat is **empty**. Anything else means the box is not where `deploy-log.md` says: stop and reconcile.
**Composer lock changed since `47d2efc5`: NO** (drafting answer) · on the box: yes / no `______` · box HEAD before: `______________`
Result: `______________`

**P0.4 `migrate:status --pending`, pasted [APP]**

```sh
php artisan migrate:status --pending
```

Healthy: **exactly these five, nothing else, and every other migration `Ran`** (the two push tables from 2026-10-12 must already be `Ran`):

```
2026_10_20_100000_add_erased_at_to_users_and_creators
2026_10_20_100100_create_erasure_ledger_table
2026_10_21_100000_add_actor_index_to_notifications
2026_10_21_110000_add_deletion_request_to_users
2026_10_22_100000_add_erasure_claim_and_hold_to_users
```

A sixth name, or one of these already `Ran`, is a stop (someone ran part of this, or the box has other code). Also read the last `Ran` line and its batch: `php artisan migrate:status | grep -v Pending | tail -3`.
Paste the output here (names only):

```
______________
```

Last `Ran` line and batch: `______________`
Result: `______________`

**P0.5 Baselines that the later steps compare with [APP, plus one read from any machine]**

```sh
# (a) the three flags BEFORE: stored values and Feature::active
php artisan tinker --execute="var_dump(Laravel\Pennant\Feature::active('account_deletion_enabled'), Laravel\Pennant\Feature::active('account_erasure_execute_enabled'), Laravel\Pennant\Feature::active('push_notifications_enabled'));"
php artisan tinker --execute="echo json_encode(DB::select(\"select name, scope, value, created_at, updated_at from features where name in ('account_deletion_enabled','account_erasure_execute_enabled','push_notifications_enabled') order by name\")), PHP_EOL;"

# (b) AH-103d: the token setting and what the first prune will remove (both legs, §1)
php artisan tinker --execute="echo json_encode([config('sanctum.expiration'), config('sanctum.stateful')]), PHP_EOL;"
php artisan tinker --execute="echo json_encode(DB::select(\"select (select count(*) from personal_access_tokens where expires_at < now() - interval '24 hours') as by_expires_at, (select count(*) from personal_access_tokens where created_at < now() - interval '131040 minutes') as by_age, (select count(*) from personal_access_tokens) as total\")), PHP_EOL;"

# (c) AH-104b-del Part B: how many creators exist (P8 compares with how many agencies can see)
php artisan tinker --execute="echo DB::table('creators')->count(), PHP_EOL;"

# (d) the live bundles, BEFORE (any machine; GET only)
for H in platform admin; do curl -sS https://$H.catalyst-growth.com/ | grep -o 'assets/[A-Za-z0-9._-]*\.js' | head -3; done
```

Healthy: (a) the push flag `true` (armed 2026-10-12) and **no row or `false`** for the two account flags (neither exists in production yet; reading `Feature::active` stores the default `false` row, which is intended: both defaults are a constant `false`);
(b) `sanctum.expiration` `129600` (an unset env), and `stateful` listing hostnames only; the counts are what the first midnight run will delete (both are expected to be small; **`by_age` must be 0**, tokens only exist since 2026-10-12, less than 91 days ago);
(c) a number, written down; (d) two asset names. At drafting time (2026-10-29, read from outside) they were `platform: assets/index-CcdhI1Xs.js` and `admin: assets/index-Bm7JA9ws.js`; read them again, they change when the sites are deployed.
Push flag before: `______` Account flags before: `______` expiration: `______` stateful hosts: `______` by_expires_at: `______` by_age: `______` total tokens: `______` creators: `______` main bundle before: `______` admin bundle before: `______`
Result: `______________`

### P1. Code

**P1.1 Pull to `066ff29c` [HOST]**

Before this step the colleague confirms **the Bitbucket mirror holds `066ff29c`** (the mirror is a separate human step after the push; [`production-queue-worker.md`](production-queue-worker.md) §8.0). Then, in the **same quiet minute as P2** (the new code reads
`users.erased_at` and the other new columns; keep the gap between the pull and the migration short, and do not reload FPM until P4):

```sh
cd ~/catalyst-engine
OLD=$(git rev-parse HEAD); echo "OLD=$OLD"
git fetch origin                                    # the remote is the Bitbucket mirror; CONFIRM its name from `git remote -v`
git cat-file -t 066ff29c                            # expect: commit (else the mirror has not caught up: STOP)
git merge --ff-only 066ff29c
git rev-parse HEAD
git diff --stat 066ff29c HEAD -- apps packages pnpm-lock.yaml | tail -2       # expected: empty (HEAD may be a docs-only descendant)
```

Healthy: a fast-forward, `HEAD` is `066ff29c` (or a docs-only descendant: the last command prints nothing). Then **[APP]** `grep '^APP_KEY=' .env | sha256sum | cut -c1-12` is **identical to P0.2**.
New HEAD: `______________` Fingerprint after: `______________` Same as P0.2: yes / no
Result: `______________`

**P1.2 PHP dependencies: `composer install --no-dev` only if the lock changed [HOST, then APP]**

P0.3 says the lock did **not** change, so **skip `composer install` entirely** and confirm the guard on the box:

```sh
git diff --stat $OLD..HEAD -- apps/api/composer.json apps/api/composer.lock        # HOST; expected: empty = skip
grep -n 'post-install-cmd' composer.json                                           # APP; expected: no output
grep -n 'key:generate' composer.json                                               # APP; expected: one line, under post-create-project-cmd
```

**If the diff is not empty** (the box was older than expected), use only the safe form, which runs no hooks, then discover packages by hand; **never plain `composer install`** (the 2026-08-11 incident):

```sh
composer install --no-dev --optimize-autoloader --no-scripts   # APP
php artisan package:discover --ansi                            # APP
```

Diff empty: yes / no `______` Composer ran: no / `--no-scripts` form
Result: `______________`

**P1.3 JavaScript dependencies and both builds [BUILD]**

Which machine builds and how `dist/` reaches Netlify is the colleague's mechanism and is **not in the repo** ([`production-queue-worker.md`](production-queue-worker.md) §8.0): if Netlify builds from the mirror itself, P1.3 happens there and you only read the build log; if you build and
publish by hand, run:

```sh
pnpm install --frozen-lockfile                 # the lockfile did not change: this is a no-op check
pnpm --filter @catalyst/main build             # = vue-tsc --noEmit && vite build (web mode). NOT build:native
pnpm --filter @catalyst/admin build
ls -l apps/main/dist/.well-known/apple-app-site-association apps/main/dist/_headers      # both must exist (AH-103b)
ls apps/main/dist/.well-known/                                                            # expected: apple-app-site-association ONLY (no assetlinks.json: ruling R3)
```

Healthy: both builds exit 0 (the type-check runs first, so a TypeScript error fails the build), the association file and `_headers` are in the main `dist/`, and there is **no `assetlinks.json`**. Do **not** run `build:native` (a device build that needs the uncommitted `.env.native-production`).
Build source: Netlify builds from the mirror / built by hand on `______` Build commit (from the Netlify log or `git rev-parse HEAD` on the build host): `______________`
Result: `______________`

**P1.4 Netlify deploy of `main` and of `admin`**

Publish **both** sites from `066ff29c`: `platform.catalyst-growth.com` (the `main` app: carries `/delete-account`, the association file and `_headers`) and `admin.catalyst-growth.com` (the Erasure queue page and the Erase dialog). Either order against the API is safe for the public and creator
pages (an old API ignores the new fields; a new API ignores an old build), but the admin **Erasure queue page needs the new API**: read it only at P8, after P4. Healthy: Netlify shows the production deploy of each site as **Published** for the commit above (or, for a manual
publish, with the time you published), and the bundle hashes differ from P0.5 (d):

```sh
for H in platform admin; do curl -sS https://$H.catalyst-growth.com/ | grep -o 'assets/[A-Za-z0-9._-]*\.js' | head -3; done
```

`main` deploy: `______________` `admin` deploy: `______________` New bundles: main `______` admin `______` (different from P0.5: yes / no)
Result: `______________`

### P2. Migrate [APP]

**Take the snapshot (P0.1) first, and confirm `available`.** Pick a quiet minute: `ALTER TABLE users` takes a brief exclusive lock; the changes are nullable columns without defaults (metadata only) on a table of about 2 763 rows (read-back B), but a long-running transaction elsewhere can make the
`ALTER` wait and queue other requests behind it.

| #   | Migration                                               | Entry       | What it does                                                                                                                                                                                                                               |
| --- | ------------------------------------------------------- | ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | `2026_10_20_100000_add_erased_at_to_users_and_creators` | AH-104a     | `users.erased_at` and `creators.erased_at`, nullable, no default, no backfill. `down()` drops both                                                                                                                                         |
| 2   | `2026_10_20_100100_create_erasure_ledger_table`         | AH-104a     | **`CREATE TABLE erasure_ledger`** (`id()` so `erasure_ledger_id_seq`; unique `ulid`; index on `user_ulid`; FK `run_by_user_id` → `users` `nullOnDelete`). No data written. Stores that an erasure ran, never who the person was            |
| 3   | `2026_10_21_100000_add_actor_index_to_notifications`    | AH-104a     | `CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_notifications_actor ON notifications (actor_user_id)`; **runs outside a transaction, so it takes no lock** on a 288 083-row table. **The Postgres path has never been run** (tech-debt row 9) |
| 4   | `2026_10_21_110000_add_deletion_request_to_users`       | AH-104b-del | `users.deletion_requested_at`, `users.deletion_scheduled_for` (indexed), nullable, no backfill                                                                                                                                             |
| 5   | `2026_10_22_100000_add_erasure_claim_and_hold_to_users` | AH-104c C1  | `users.erasure_started_at`, `erasure_held_at`, `erasure_held_by_user_id` (nullable FK to `users`, `nullOnDelete`); no default, no index, no backfill                                                                                       |

**Expected count: 5**, in this order, one new batch. Seven columns appear (`users`: 6, `creators`: 1), one table, one index. Every column is null for every existing row.

```sh
php artisan migrate --pretend --force      # reads only: prints the SQL
```

Healthy: `ALTER TABLE users`, `ALTER TABLE creators`, one `CREATE TABLE erasure_ledger` with its indexes and FK, the `CREATE INDEX CONCURRENTLY` line, and **nothing else**: no `DROP`, no `UPDATE`, `DELETE` or `INSERT` of data. Anything else is a stop.

```sh
php artisan migrate --force                # --force is required with APP_ENV=production
php artisan migrate:status --pending       # expect: No pending migrations
```

Healthy: exactly five `DONE` lines in the order above, then `No pending migrations`. **Which role runs `migrate`** matters: `ALTER TABLE users` and `CREATE INDEX CONCURRENTLY` on `notifications` need the **table owner**. CONFIRM the role (P3 reads it back).

Then prove the schema and the index (an interrupted `CONCURRENTLY` build leaves an **invalid** index):

```sh
php artisan tinker --execute="echo json_encode(DB::select(\"select c.relname as idx, i.indisvalid as valid from pg_index i join pg_class c on c.oid = i.indexrelid where c.relname = 'idx_notifications_actor'\")), PHP_EOL;"
php artisan tinker --execute="echo json_encode(DB::select(\"select table_name, column_name from information_schema.columns where table_schema = 'public' and column_name in ('erased_at','deletion_requested_at','deletion_scheduled_for','erasure_started_at','erasure_held_at','erasure_held_by_user_id') order by table_name, column_name\")), PHP_EOL;"
php artisan tinker --execute="echo DB::table('users')->whereNotNull('erasure_started_at')->whereNull('erased_at')->count(), ' ', DB::table('users')->whereNotNull('deletion_requested_at')->count(), PHP_EOL;"
grep '^APP_KEY=' .env | sha256sum | cut -c1-12                                     # identical to P0.2
```

Healthy: the index row has `"valid":true`; the second query lists **7 rows** (creators: `erased_at`; users: `deletion_requested_at`, `deletion_scheduled_for`, `erased_at`, `erasure_held_at`, `erasure_held_by_user_id`, `erasure_started_at`); the third prints **`0 0`** (nothing claimed, nothing requested). The **first number is the Q10 check** for P4.
**If the index migration fails:** it leaves an invalid index. `DROP INDEX CONCURRENTLY IF EXISTS idx_notifications_actor` (as the table owner), then re-run `php artisan migrate --force`: migrations 1 and 2 stay `Ran` and the rest continue ([`account-erasure.md`](account-erasure.md) §8).
Migrate role: `______________` Output: `______________` Index valid: `______` Q10 / requests: `______` Fingerprint: `______________`
Result: `______________`

### P3. GRANTs for `engine_c_user` on the new table and its sequence [APP]

Postgres does not extend `CREATE TABLE` access to the application role (the 2026-07-31 `campaign_applications` incident). **Only one table is new: `erasure_ledger`** (the other four migrations add columns to existing tables, which the role's table-level privileges already cover; the second read below
proves it). The migration's own note says the app role needs **SELECT, INSERT and UPDATE** on the table and **USAGE** on `erasure_ledger_id_seq`, and **no DELETE** (the ledger is a record; nothing deletes it). Check each privilege separately: a comma list in `has_table_privilege` passes if
any one is held. This runs as the connection the app uses, so it reads what the app will actually have:

```sh
php artisan tinker --execute="foreach (['SELECT','INSERT','UPDATE','DELETE'] as \$p) { echo 'erasure_ledger ',\$p,' ',var_export(DB::selectOne('select has_table_privilege(current_user, ?, ?) as ok', ['public.erasure_ledger', \$p])->ok, true), PHP_EOL; } foreach (['USAGE','SELECT'] as \$p) { echo 'erasure_ledger_id_seq ',\$p,' ',var_export(DB::selectOne('select has_sequence_privilege(current_user, ?, ?) as ok', ['public.erasure_ledger_id_seq', \$p])->ok, true), PHP_EOL; } echo DB::selectOne('select current_user as u')->u, PHP_EOL;"
php artisan tinker --execute="foreach ([['users','erased_at'],['users','deletion_requested_at'],['users','deletion_scheduled_for'],['users','erasure_started_at'],['users','erasure_held_at'],['users','erasure_held_by_user_id'],['creators','erased_at']] as [\$t,\$c]) { foreach (['SELECT','UPDATE'] as \$p) { echo \$t,'.',\$c,' ',\$p,' ',var_export(DB::selectOne('select has_column_privilege(current_user, ?, ?, ?) as ok', ['public.'.\$t, \$c, \$p])->ok, true), PHP_EOL; } }"
```

(Both were run against the local development database to check their syntax and shape; there every line is `true`, because the dev role owns the table.) Healthy, first command: **six lines then the role**: on the table `SELECT`, `INSERT` and `UPDATE` **`true`**, and **`DELETE` `false`** (the intent); on the sequence `USAGE` and `SELECT` **`true`**; the last line is the app role (expected `engine_c_user`). If the `DELETE` line is `true` the role holds a blanket grant: not a blocker, **record it**. Second command: **14 lines, every one `true`**.

If any required line is `false`, grant it **as the table owner** (**CONFIRM** how you get an owner SQL session on Aurora; the app role cannot grant itself), then re-run the reads:

```sql
GRANT SELECT, INSERT, UPDATE ON erasure_ledger TO engine_c_user;
GRANT USAGE ON SEQUENCE erasure_ledger_id_seq TO engine_c_user;
```

If a column read is `false` (column-level grants in use), grant `SELECT, UPDATE` on that column: `GRANT SELECT (<column>), UPDATE (<column>) ON <table> TO engine_c_user;`. Why this matters: without the table grants the first Erase fails at the ledger insert **after** the account was claimed.
Role: `______________` Table + sequence lines: `______________` DELETE line: `______________` Column lines (14 true?): `______________`
Result: `______________`

### P4. Infra [APP, supervisor in the container]

**P4.1 Cache the config and, if the box uses them, the route and view caches**

```sh
php artisan config:cache
grep '^APP_KEY=' .env | sha256sum | cut -c1-12                                     # identical to P0.2
ls bootstrap/cache/                                                                 # routes-v7.php present? (CONFIRM whether this box caches routes)
php artisan tinker --execute="echo json_encode([config('queue.connections.database_erasure.retry_after'), config('queue.connections.database_erasure.queue'), config('erasure.purge_object_versions'), config('app.support_email')]), PHP_EOL;"
```

Healthy: `config:cache` succeeds; the tinker line prints **`[3700,"erasure",true,"support@catalyst-growth.com"]`** (the new connection, the queue name, the purge default, the address). **If `bootstrap/cache/routes-v7.php` exists**, run `php artisan route:cache` too: this release adds routes (the deletion request, the Erasure queue and hold, the Erase endpoint), and a stale route cache would answer 404 for them.
(Run `view:cache` only if the box already runs it.)
Output: `______________` Route cache present: yes / no
Result: `______________`

**P4.2 PHP-FPM reload [command from your usual procedure; the earlier runbook's P0.4 derives it]**

```sh
supervisorctl status <PHP_FPM_PROGRAM>
supervisorctl signal USR2 <PHP_FPM_PROGRAM>        # graceful reload (or `kill -USR2 <master pid>` if the program wraps FPM)
supervisorctl status <PHP_FPM_PROGRAM>
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/up       # expect 200 (no curl in the image? use wget -S -O /dev/null)
```

Healthy: FPM stays RUNNING and `/up` answers `200`. Opcache serves stale code until this (the earlier entries in `deploy-log.md` record the reload as required).
Program: `______________` Reload command: `______________`
Result: `______________`

**P4.3 Restart the worker [APP]: after the Q10 check**

```sh
php artisan tinker --execute="echo DB::table('users')->whereNotNull('erasure_started_at')->whereNull('erased_at')->count(), PHP_EOL;"   # must print 0 (the Q10 rule)
php artisan queue:restart
sleep 10
supervisorctl status laravel-queue
```

**Why this is safe to do now:** no erasure can be claimed yet (§1, rule 2): the check prints `0`, both flags are OFF, nothing routes to `erasure:run`, and the new erasure worker does not exist until P4.4. **If the check prints anything but `0`: stop**, do not restart; an erasure is in flight.
Healthy: `RUNNING` with an uptime of seconds (supervisor restarted it onto the new code). Without this the worker keeps the old job, mail and listener classes (and old AH-113 mail strings). `laravel-schedule` is **not** restarted.
Q10 count: `______` `laravel-queue` status: `______________`
Result: `______________`

**P4.4 The new supervisor program, `catalyst-erasure-worker`, for queue `database_erasure`**

The admin Erase button queues one job, `RunErasureJob`, that can run for up to an hour. It must **not** share the default worker (`retry_after` 300, 120 s stop budget): a long job would be handed to a second worker mid-run. So it has its own connection (`database_erasure`, the same `jobs` table, `retry_after` 3700), queue
(`erasure`) and program. **`numprocs=1` is deliberate**: two erasures never run side by side. With the flag OFF the worker idles; it must exist and be `RUNNING` **before the flag is ever turned on** (a click with no worker leaves the job waiting in the `jobs` table). Procedure: [`production-queue-worker.md`](production-queue-worker.md) §2a.

**First read the existing queue program and copy its three site-specific values** (the repo's §2 block uses `/var/www/catalyst` and `www-data`; production's program is named `laravel-queue` and runs from `/app`):

```sh
grep -rn -A14 '^\[program:laravel-queue\]' /etc/supervisor* /etc/supervisord* 2>/dev/null | cut -c1-200      # the file, the user=, the command= form, the log directory
```

Write the new file next to it (**CONFIRM** the file's location and that it **survives a container recreate**: a file written only inside a running container is lost on the next `up -d`; it must live in the image or a bind-mounted conf directory, the colleague's call).
Replace the three marked values with what the existing program uses; change nothing else:

```ini
[program:catalyst-erasure-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /app/artisan queue:work database_erasure --queue=erasure --tries=1 --timeout=3600 --max-time=3600 --memory=768
directory=/app
user=<SAME user= AS laravel-queue>
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
; queue:work finishes the in-flight job before exiting; an erasure can take up to an hour.
stopwaitsecs=3700
redirect_stderr=true
stdout_logfile=<SAME LOG DIRECTORY AS laravel-queue>/erasure-worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
```

(`command=` follows the existing program's form: if `laravel-queue` says `php artisan …` from its `directory`, use that form, not `/app/artisan`.) Then:

```sh
supervisorctl reread        # expect: catalyst-erasure-worker: available
supervisorctl update        # expect: catalyst-erasure-worker: added process group (and nothing else restarted)
supervisorctl status
sleep 15
supervisorctl status catalyst-erasure-worker:*
ps -o pid,args | grep '[d]atabase_erasure'
php artisan tinker --execute="echo json_encode([Illuminate\Support\Facades\Queue::connection('database_erasure')->size('erasure')]), PHP_EOL;"
tail -n 5 <LOG DIRECTORY>/erasure-worker.log
```

Healthy: `reread` and `update` name only the new group; `supervisorctl status` shows **`catalyst-erasure-worker:catalyst-erasure-worker_00   RUNNING`** with an uptime of **more than 15 s** (not crash-looping) next to `laravel-queue`, `laravel-schedule`, php-fpm and nginx, all unchanged; `ps` shows a process whose arguments contain `database_erasure --queue=erasure`; the queue size prints **`[0]`**;
the log has no stack trace. `update` must **not** restart `laravel-queue` or `laravel-schedule`: if it does, say so in `Result:`.
Conf file path: `______________` Survives a recreate: yes / no `______` `supervisorctl status` (paste the five lines): `______________` Queue size: `______`
Result: `______________`

**P4.5 Scheduler [APP]**

```sh
supervisorctl status laravel-schedule
php artisan schedule:list
php artisan schedule:list | grep sanctum
```

Healthy: `laravel-schedule` RUNNING (`schedule:work`, **not** cron; production has a `cron` service but no `schedule:run` line, as the earlier runbook's P7 requires); the list shows `messages:send-digest`, `boards:scan-overdue`, `creators:send-incomplete-nudges`, `push:prune-deliveries` **and**
`sanctum:prune-expired --hours=24`; the `grep` prints **exactly one line**, starting `0 0 * * *  php artisan sanctum:prune-expired --hours=24` (followed by a "Next Due" time). **REQUIRED check (AH-103d).** If the scheduler is not running nothing prunes. The first run is at the next midnight, by itself: **do not run it by hand.**
If `schedule:list` has changed its `schedule:run` situation since the earlier sitting (a cron line appeared), stop: a second scheduler double-fires the daily commands.
Recorded `grep sanctum` line: `______________`
Result: `______________`

### P5. One-shots: the AH-108 backlog [APP]

**AH-108.** Every message notification in production today is unread unless the user clicked it, because opening a thread never touched it before this release. Without this command the first badge a creator sees (bell, Messages tab, app icon) would be a large false number. The command marks a notification read **only when its thread has nothing unread for the
recipient** (the services' own definition); a notification whose thread still holds something unread is left alone, and a row it cannot place is left unread and counted. It is idempotent and writes nothing but `notifications.read_at`. **PROD-DATA RISK: LOW.** Run it **after P4.3** (the new code is live, so a thread opened from now on marks itself read). It reads in chunks of 200 and
asks the message services per row: **its duration on production data has not been measured**; run it in a quiet period and do not interrupt it.

**P5.1 Dry run [APP]**

```sh
php artisan notifications:clear-read-message-notifications --dry-run
```

Healthy: one line of the form `Checked N unread message notification(s); M would be marked read, K left unread (thread still has unread messages), U could not be placed (left unread).` and **nothing is written**. Paste it:

```
______________
```

Dry-run: N `______` would be marked read M `______` left unread K `______` unplaceable U `______` Run by: `______` At: `______`

**P5.2 Report the count to Pedram. Do not run the real command until he has seen it and said go.**
Reported to Pedram: yes / no · His go-ahead (who, when): `______________`

**P5.3 Real run [APP]**

```sh
php artisan notifications:clear-read-message-notifications
```

Healthy: `Processed N …; M marked read, …` with **M equal to the dry run's** (a small difference is live traffic: a thread opened or a message received in between; a large one is a stop and a question). Paste it:

```
______________
```

Real run: N `______` marked read M `______` Matches the dry run: yes / no `______` Run by: `______` At: `______`

**P5.4 Idempotence [APP].** Run the dry run again: it must report **`0 would be marked read`**.
Second dry run: `______________`
Result: `______________`

### P6. Universal links: C1 to C7 [any machine with `curl` and `jq`; no login]

Source: [`universal-links-plan.md`](../reviews/universal-links-plan.md) §6, the log entry's C1 to C7. **TestFlight build 3 waits for these.** Before P1.4 both `.well-known` URLs answered `200 text/html` (the SPA fallback, 532 bytes), so a failing C1 _before_ the Netlify deploy is the expected "before" state; run them **after** it.

```sh
H=https://platform.catalyst-growth.com
A=$H/.well-known/apple-app-site-association

# C1 — the static file wins over the SPA fallback (HTTP 200, JSON, NOT index.html)
curl -sS -D - -o /tmp/aasa.json "$A" | head -12
head -c 1 /tmp/aasa.json
jq . /tmp/aasa.json >/dev/null && echo "C1 valid JSON"

# C2 — _headers is honoured (content type on that one path)
curl -sS -o /dev/null -w '%{content_type}\n' "$A"

# C3 — no redirect on the file (Apple does not follow redirects for it)
curl -sS -o /dev/null -w '%{http_code} redirects=%{num_redirects} url_effective=%{url_effective}\n' "$A"

# C4 — the content is exactly what the app expects
jq -e '.applinks.details[0].appIDs[0]=="LTW9262ZXP.com.catalystgrowth.platform"
       and .applinks.details[0].components[0]["/"]=="/creator/*"
       and (.applinks.details|length)==1' /tmp/aasa.json

# C5 — assetlinks.json is still NOT published (R3): the SPA fallback must still answer it
curl -sS -o /dev/null -w '%{http_code} %{content_type}\n' "$H/.well-known/assetlinks.json"

# C6 — the headers rule did not leak, and the site still works
curl -sS -o /dev/null -w '%{http_code} %{content_type}\n' "$H/"
curl -sS -o /dev/null -w '%{http_code} %{content_type}\n' "$H/sign-in"
curl -sS -o /dev/null -w '%{http_code} %{content_type}\n' "$H/creator/jobs/01ZZZZZZZZZZZZZZZZZZZZZZZZ"

# C7 — Apple's CDN view of the file (URL form and lag unverified)
curl -sS "https://app-site-association.cdn-apple.com/a/v1/platform.catalyst-growth.com" | jq -c .
```

| Check | Expected                                                                                                                    | Result                |
| ----- | --------------------------------------------------------------------------------------------------------------------------- | --------------------- |
| C1    | `HTTP/2 200`, **no `location:` header**, first byte **`{`** (a `<` means the fallback answered: FAIL), then `C1 valid JSON` | `____`                |
| C2    | `application/json`                                                                                                          | `____`                |
| C3    | `200 redirects=0 url_effective=` the same URL                                                                               | `____`                |
| C4    | `true`                                                                                                                      | `____`                |
| C5    | `200 text/html` (**`application/json` here means a placeholder was published: STOP**)                                       | `____`                |
| C6    | three lines, each `200 text/html`                                                                                           | `____`                |
| C7    | the same JSON as C4 (it can lag the origin: record when it matches)                                                         | `____` (when: `____`) |

**If C1 or C2 fails** (HTML or the wrong type), a Netlify dashboard rule is shadowing the file or `_headers` is not published: **do not release build 3**; the fix is in the dashboard (exempt the path from the fallback rule, or set the header there), not in the repo. **If C3 fails** (a 3xx): same, and say which hop (a domain-level
redirect also breaks it). **If C5 fails: stop.** **Gate for build 3: C1 to C6 pass and C7 matches.**
Run by: `______________` When: `______________` Forwarded to Pedram: yes / no

### P7. Flags: read back, change nothing [APP]

Both account flags are **OFF** and stay OFF; the push flag is unchanged. Read them from the `features` table (the stored truth), not only from `Feature::active`:

```sh
php artisan tinker --execute="var_dump(Laravel\Pennant\Feature::active('account_deletion_enabled'), Laravel\Pennant\Feature::active('account_erasure_execute_enabled'), Laravel\Pennant\Feature::active('push_notifications_enabled'));"
php artisan tinker --execute="echo json_encode(DB::select(\"select name, scope, value, created_at, updated_at from features where name in ('account_deletion_enabled','account_erasure_execute_enabled','push_notifications_enabled') order by name\")), PHP_EOL;"
```

Healthy: `bool(false)`, `bool(false)`, **`bool(true)`** (the push value from P0.5 (a), unchanged), and the stored rows read `account_deletion_enabled` `"false"`, `account_erasure_execute_enabled` `"false"`, `push_notifications_enabled` `"true"` **with the same `updated_at` as P0.5**. Reading `Feature::active` for the two account flags stores a `false` row if none existed: that is the intended default, not a change. The admin Feature-flags page lists them as
**"Account deletion (creator request)"** and **"Account erasure (admin Erase button)"**, both off. **If either account flag is ON, switch it OFF in the admin page and say so**: nothing may be claimed or requested before TestFlight build 3 and the production walks.
Flag values: `______________` Push `updated_at` unchanged: yes / no
Result: `______________`

### P8. Verify

**P8.1 `/up` and the key [APP, any machine]**

```sh
curl -s -o /dev/null -w '%{http_code}\n' https://platform-api.catalyst-growth.com/up        # expect 200
grep '^APP_KEY=' .env | sha256sum | cut -c1-12                                              # APP; identical to P0.2
```

`/up`: `______` Fingerprint: `______________` Same as P0.2: yes / no
Result: `______________`

**P8.2 Creator and admin web login [browser]**

Sign in on `https://platform.catalyst-growth.com` as a creator and on `https://admin.catalyst-growth.com` as an admin, using your own accounts (no password goes in this file). Healthy: both reach their dashboards. As the creator also check: **`/creator/security` redirects to the dashboard** (the Security entry follows `account_deletion_enabled`, OFF), and the creator invitations list shows a campaign chip on each card (AH-107).
Creator: `______` Admin: `______` `/creator/security`: `______` Chip: `______`
Result: `______________`

**P8.3 `/delete-account` renders [browser, signed out]**

Open `https://platform.catalyst-growth.com/delete-account` in a private window. Healthy: the page shows **"Delete your account"**, the "In the app" and "Can't use the app?" blocks, and the support address, **without signing in**. (A `curl` of this path answers `200` for any path because of the SPA fallback, so it proves nothing; the browser does.) The bundle hash from P1.4 is the other evidence that the new build is live.
Result: `______________`

**P8.4 The admin Erasure queue page [browser, admin]**

Open **Compliance → Erasure queue** (`/compliance/erasure-queue`). Healthy: the page "Erasure queue" loads with its six tabs, **Pending, Ready, Blocked, On hold, Erasing, Erased**, every tab empty (nobody can have asked to be deleted while the flag is OFF); no error banner. Also open **Feature flags** and read the two account flags off (P7).
Result: `______________`

**P8.5 What agencies see did not change [APP]** (AH-104b-del Part B: with every creator's new columns null, the agency discovery set must equal all creators)

```sh
php artisan tinker --execute="echo App\Modules\Creators\Models\Creator::query()->count(), ' ', App\Modules\Creators\Models\Creator::query()->visibleToAgencies()->count(), PHP_EOL;"
php artisan list | grep -E 'erasure|clear-read'
```

Healthy: the two numbers are **equal**, and the first equals P0.5 (c) (or is higher by creators who signed up since); the command list shows `erasure:reapply`, `erasure:run` and `notifications:clear-read-message-notifications`. **Optional, only with a named test creator (never a real one):** `php artisan erasure:run <TEST_CREATOR_ULID>` (a dry run: it prints counts only, no name, email or object key) must print `NOT READY: the account has not asked to be deleted`
and exit 1, changing nothing.
Counts: `______` Commands: `______` Dry-run on a test creator: `______` / not done
Result: `______________`

**P8.6 Per-entry checks that follow the sitting (record when done)**

- **AH-106 and AH-107 web:** the notifications page's back arrow returns; a long job title shows the whole Applied chip; a paused test campaign shows **Paused** on the creator's assignment. (The full list is in the log entry's _Post-deploy verification_.) Result: `______`
- **AH-108:** after P5, a creator with old message notifications sees a small honest bell and Messages-tab number, not the old backlog; one new message each way reads 2 and opening a thread drops it by one. Result: `______`
- **AH-109:** the next job-posted email shows "…you are on the **<agency name>** roster" in its footer, not `:agency`. Result: `______` (when it arrives)
- **AH-113:** the next deletion-request mail reads "permanently deleted after <date>". It cannot arrive while the flag is OFF: **checked on the first walk with the flag ON**, not now. Result: `not yet`
- **AH-104d (production write, Pedram's call):** six sign-ups with one throwaway address in an hour answer 422/201 five times and **429 on the sixth**, with no sixth user row; delete the account after. It creates a user, so it is **not** part of this sitting unless Pedram asks. Result: `______`
- **AH-103d, the day after:** at the first midnight the scheduler runs `sanctum:prune-expired`. Repeat the P0.5 (b) counts: `by_expires_at` and `by_age` must be **0**, and `total` must have dropped by the P0.5 numbers. Result: `______`

### P9. Read-backs for Pedram [CONSOLE / LAPTOP; read-only]

These answer what the repo cannot. They feed the privacy policy's AWS line, [`account-erasure.md`](account-erasure.md) §2 items 4 and 5, and `docs/store/review-notes.md`. Nothing here changes anything.

**P9.1 S3 media bucket: region and versioning**

```sh
php artisan tinker --execute="echo json_encode(['default'=>config('filesystems.default'),'media_bucket'=>config('filesystems.disks.media.bucket'),'media_region_configured'=>config('filesystems.disks.media.region')]), PHP_EOL;"   # APP: names only
aws s3api get-bucket-location   --bucket <MEDIA_BUCKET>        # the bucket's REAL region (null/None means us-east-1; "EU" is the legacy name for eu-west-1)
aws s3api get-bucket-versioning --bucket <MEDIA_BUCKET>        # Status: Enabled / Suspended / (empty = never versioned)
```

Healthy: any answer is acceptable; **write it down**. The configured region (the env value) is not proof of the bucket's region: read it with `get-bucket-location`. The policy says "Ireland (EU)" for AWS hosting; the database is `eu-west-1`; if the bucket is elsewhere, the policy line needs the other region too. **If versioning is `Enabled`**, the app role needs `s3:ListBucketVersions`
and `s3:DeleteObjectVersion` before the first real erasure (a C4 precondition, not this sitting); if it is empty or `Suspended`, the purge finds nothing but still needs `s3:ListBucketVersions`, or `ERASURE_PURGE_OBJECT_VERSIONS=false` must be set knowingly.
Bucket: `______________` Real region: `______________` Configured region: `______________` Versioning: `______________`
Result: `______________`

**P9.2 Postmark message retention**

In the Postmark console, open the server's message stream settings and read the **message retention period** (days) that applies to the stream production mail uses (`mail.default` is `smtp` → `smtp.postmarkapp.com`). The exact menu name is not verified here. The privacy policy and the erasure runbook §7 depend on it (message content and recipient addresses sit with the mail processor for that long after an account is erased).
Retention (days): `______________` Stream: `______________`
Result: `______________`

**P9.3 `SANCTUM_STATEFUL_DOMAINS` has no bare `localhost` (still open from AH-099) [APP]**

```sh
php artisan tinker --execute="echo json_encode(config('sanctum.stateful')), PHP_EOL;"
grep '^SANCTUM_STATEFUL_DOMAINS=' .env        # hostnames only, no secret
```

Healthy: **real production hostnames only; no bare `localhost`** (Sanctum strips only `http(s)://`, so a bare `localhost` makes `https://localhost` a stateful origin and every bearer POST from Android 419s; the repo's `.env.example` default contains `localhost`, production must not). Also read the line in `.env` in case config is cached from before.
Stateful list: `______________` Bare `localhost` present: yes / no
Result: `______________`

### Record it [deploy-log.md]

Fill the `PENDING` entry "AH-106 + AH-107 + AH-108 + AH-103b + AH-109 …" in [`deploy-log.md`](deploy-log.md): the snapshot name and creation time, the five migrations and the batch, the GRANT reads, the erasure program's status line, the `schedule:list` line, both one-shot counts, C1 to C7, the flag read-backs, the fingerprints, the operator, and
**anything unexpected**; set the **Range** to the commit that was actually deployed from the mirror (`47d2efc5` → the SHA you pulled) and flip the status to `DEPLOYED` with today's date. Record deploy state there only. Mark which items stayed **not read**; never write "passed" for a step that was not run.
Done: yes / no

---

## 3. Not in this sitting (named, so nothing is assumed done)

- **TestFlight build 3** and its checklist (the log entry lists it): `aps-environment` `production` on the archive, "Generate Privacy Report" against [`docs/store/app-privacy-answers.md`](../store/app-privacy-answers.md), Associated Domains in the distribution profile, no existing build 3 in App Store Connect,
  the device walks (W1 to W10 for links, W1 to W14 for sign-up, the AH-103d reinstall walk W1 to W6, Report a problem with and without a mail account). **Build 3 waits for P6 (C1 to C7).** The tester note about the one-time sign-out is in the AH-103d bullet.
- **The flag flips**, in this order, each a separate act: `account_deletion_enabled` ON after the walks and before build 3 is submitted (Apple 5.1.1(v)); `account_erasure_execute_enabled` ON only for one named account, and OFF again after (C4).
- **The demo account** ([`docs/store/demo-account-runbook.md`](../store/demo-account-runbook.md): three `tinker` writes, **PROD-DATA RISK LOW to MEDIUM**, by Pedram, after this deploy).
- **The Monday erasure routine and the first real erasure** wait for the C4 preconditions in [`account-erasure.md`](account-erasure.md) §2 (a Postgres run of the erasure specs, the `erasure-ledger/` prefix with versioning, the S3 version permissions, a fresh snapshot, the dry run's `Cost` line).
- **Publishing the privacy policy section and the support page**, before the first external TestFlight tester (Pedram; the mismatches to settle are in [`docs/store/review-notes.md`](../store/review-notes.md)).
- **Android**, later.

---

## 4. If something goes wrong

**First rule: stop, record the state, change nothing else.** Then:

| Symptom                                                                | Do                                                                                                                                                                                                                                                                                                                                   |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **`APP_KEY` fingerprint differs** (any step)                           | **Restart nothing.** The workers hold the old key in memory. Restore the value from the recoverable copy (P0.2), `php artisan config:cache`, reload FPM. Do not run `composer install` again                                                                                                                                         |
| A migration fails                                                      | Do not re-run blindly. Read the error and `migrate:status`. Migrations 1, 2, 4, 5 are single `ALTER`/`CREATE TABLE` statements; a failure leaves at most one change. **For the `CONCURRENTLY` index:** drop the invalid index (P2), then `migrate` again                                                                             |
| 500s or errors on login or agency pages between P1.1 and P2            | The new code reads the new columns before they exist: **run P2 now**, do not roll the code back first. (This is why P1.1 and P2 are one quiet minute)                                                                                                                                                                                |
| Code problem after migrating (API or SPA)                              | **Roll the code back, leave the columns and the table.** Old code ignores them. `git checkout <OLD>` on the host, rebuild and republish the SPAs, `config:cache`, reload FPM, `queue:restart` (after the Q10 check), `supervisorctl stop catalyst-erasure-worker:*` if it is the problem                                             |
| Roll the schema back                                                   | `php artisan migrate:rollback --step=5 --force` drops the seven columns, the index and `erasure_ledger`. Safe while they are empty (they are at deploy: nothing can set them with both flags OFF). **Never after a request or an erasure exists**: that data would be lost                                                           |
| The erasure worker crash-loops or `update` restarted another program   | `supervisorctl stop catalyst-erasure-worker:*`, remove its conf file, `supervisorctl reread && supervisorctl update`. Nothing needs it while the flag is OFF; the default workers are unaffected                                                                                                                                     |
| Universal links: C1 or C2 fails (HTML or wrong type), C3 shows a 3xx   | A Netlify dashboard rule shadows the file or `_headers` was not published. **Do not release build 3.** Fix in the dashboard, not the repo. **C5 shows JSON: stop**, a placeholder `assetlinks.json` was published                                                                                                                    |
| `schedule:list` has no `sanctum:prune-expired`, or two scheduler lines | The code is not the tip, or a cron `schedule:run` exists: stop and reconcile before anything else                                                                                                                                                                                                                                    |
| **Data problem** (not just schema)                                     | **Restore from the P0.1 snapshot.** A restore creates a **new cluster**; it does not roll the live one back in place. **CONFIRM** how the app is re-pointed. It has **never been rehearsed** ([`production-queue-worker.md`](production-queue-worker.md) §8.2) and everything written after the snapshot is lost. Prefer fix-forward |

**Kill switches in this deploy:** none are armed. `account_deletion_enabled` OFF keeps the request endpoint at 404 and the Security entries hidden; `account_erasure_execute_enabled` OFF keeps the Erase endpoint at 409. **No kill switch exists** for the unread-count field, the push badge, the `campaign_status` field, the agency
`is_erased` flags (null for everyone), the sign-up limiter, or the daily token prune.

---

_Provenance: drafted by Cursor on 2026-10-29 from `docs/runbooks/deploy-log.md` (the `PENDING` entry and each AH entry's obligations), `production-queue-worker.md` §2a, §4, §7, §8, `account-erasure.md` §1, §2, §8, the five migration files, `config/queue.php`, `config/erasure.php`, `bootstrap/app.php`, the vendor
`PruneExpired` class, `ErasureRunCommand`, `ClearReadMessageNotifications`, and the repo at `066ff29c`. **Run against the local development database only, to check syntax and output shape** (never production): `schedule:list` (the `sanctum:prune-expired --hours=24` line), `migrate:status --pending`, `php artisan list`, the P3 reads (all `true` there because the dev role owns the table), the flag
reads, the Q10 and visibility counts, the token counts, the index-validity and column reads, and the erasure connection read (`[3700,"erasure",true,…]` and size 0 once config is cached). **Read from outside, GET only, 2026-10-29:** the two live bundle names and `/up` → 200. **No command has been run against production.** The `git diff --stat` for the lock files was run in the development
checkout and is repeated on the box in P0.3. **Still CONFIRM, because the repo cannot say:** the Aurora cluster identifier, the migrate role and whether it owns the tables, the env file path, the PHP-FPM program name and the supervisor conf location (and whether it survives a container recreate), whether the box caches routes, how the Netlify sites are deployed from the mirror, and the S3 and Postmark answers in P9._
