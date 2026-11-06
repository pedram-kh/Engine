# Deploy runbook: AH-116 → AH-119, one sitting

**Status: DRAFTED 2026-11-06, nothing run.** For Pedram's colleague (who runs the box), in the shape of [`deploy-ah104-ah113.md`](deploy-ah104-ah113.md).
Written from the repo at `6d7a9c28` (runtime tip; this runbook is a docs-only commit on top of it) and the five `PENDING` entries in [`deploy-log.md`](deploy-log.md):
**AH-116 API, AH-116 web, AH-117, AH-118, AH-119.** Procedure behind it: [`production-queue-worker.md`](production-queue-worker.md) §2a, §4, §7, §8 and [`account-erasure.md`](account-erasure.md).
CI at `6d7a9c28`: [run 42277564265](https://github.com/pedram-kh/Engine/actions/runs/42277564265), green (Backend, Frontend, E2E main, E2E admin).

**How to use it.** Run P0 to P9 in order. Every step has its exact command, what a healthy result looks like, and a blank `Result:` line to fill. Each step says where it runs: **HOST** (`~/catalyst-engine`),
**APP** (inside `docker compose exec app sh`, at `/app`), **BUILD** (the machine that builds the SPAs) or **LAPTOP** / **CONSOLE** (Pedram). Anything the repo does not document is marked **CONFIRM**.
**No secret is printed by any command here**: fingerprints, counts, names and hostnames only. Never run a bare `docker compose config`, never `cat` or `echo` an env file, never paste shell history, never paste a signed URL.
(A key was printed into a transcript on 2026-11-06: tech-debt row 18. The rule stands: **never print `.env` files.**)

> **Layout assumed (from the 2026-10-12 and 2026-10-29 sittings, [`deploy-log.md`](deploy-log.md)):** the API runs in a container `app` at `/app` under supervisor (programs `laravel-queue`, `laravel-schedule`, **`laravel-erasure`**, `php-fpm`, `nginx`, `cron`), behind
> `https://platform-api.catalyst-growth.com`; queue `database`, cache Redis, mail Postmark; the database is an **Aurora PostgreSQL cluster in `eu-west-1`**; both SPAs are Netlify sites (`platform.catalyst-growth.com`, `admin.catalyst-growth.com`); the deploy source is the **Bitbucket mirror** of GitHub,
> run by the colleague. **The mirror rewrites commit SHAs** (two pairs already differ: `a60ffbbf` = `5d2ecdbc`, `c295185` = `1bc398e8`), so P1.1 identifies the code by **tree hashes**, not by the GitHub SHA. The mount shape, env file path and PHP-FPM program name were never recorded: take them
> from your usual procedure and write any difference in the `Result:` line. **If `/app` is baked into the image rather than a bind mount, stop and revise this runbook.**

---

## 0. What this deploy is

**PROD-DATA RISK: MEDIUM.** Six migrations, all additive (§2); **no data is written, erased, requested or claimed by deploying**: all four new flags stay OFF, there is no one-shot command, and the two GIN index builds are the only slow step.

| Item                              | Value                                                                                                                                                                                                                                                                                                            |
| --------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Last deployed (from `deploy-log`) | API **`066ff29c`** (the 2026-10-29 runtime tip, reported, current state **not read**); main SPA mirror `c295185` = GitHub **`1bc398e8`** (AH-114, 2026-11-02, reported); admin at the 2026-10-29 build (reported)                                                                                                |
| Deploy tip                        | **`6d7a9c28`** (GitHub, `origin/main` when this was written). `066ff29c` is an ancestor (checked); 120 commits between them. The runbook's own commit follows it and changes no runtime file                                                                                                                     |
| Migrations                        | **6**, all additive: 12 columns on 5 tables, 1 plain index, **2 GIN indexes** (§2)                                                                                                                                                                                                                               |
| PHP dependencies                  | **No change that needs `composer install`.** `apps/api/composer.lock` is the identical blob at `066ff29c` and `6d7a9c28` (`58ae398d`). `composer.json` gained one dev script (`test:pg`), which nothing in production reads. Answered in P0.3                                                                    |
| JS dependencies                   | **Changed.** `pnpm-lock.yaml` +12 lines: `@capacitor/share` 8.0.3 added to `apps/main` (the image lightbox's native download; **web builds bundle it too**). So `pnpm install --frozen-lockfile` is a **real install**, not a no-op                                                                              |
| SPA rebuilds                      | **Both.** `apps/main` (AH-116 web, AH-118, AH-119) and `apps/admin` (AH-117, AH-118's push-type filter)                                                                                                                                                                                                          |
| Env changes                       | **None.**                                                                                                                                                                                                                                                                                                        |
| Worker restart                    | **Conditional on Q10.** AH-118 needs `queue:restart` (a new mailable, notification type, push type, lang files). AH-119 changes `Steps` and `ErasureRegistry`, which `laravel-erasure` loads. `queue:restart` signals **every** `queue:work`, the erasure worker included, so it runs **only if Q10 = 0** (P4.3) |
| PHP-FPM reload                    | **Required** (new routes, controllers, features, config)                                                                                                                                                                                                                                                         |
| Scheduler                         | No change                                                                                                                                                                                                                                                                                                        |
| Supervisor                        | No new program. `laravel-erasure` already exists; status is read in P4.4                                                                                                                                                                                                                                         |
| One-shot command                  | **None expected** (confirmed in P5)                                                                                                                                                                                                                                                                              |
| Media bucket                      | **CORS change** (P6): allow `GET` from the two web origins, for the web image download                                                                                                                                                                                                                           |
| Flags armed in this sitting       | **None.** Four new flags, all OFF (P7)                                                                                                                                                                                                                                                                           |
| Not in this sitting               | Native build 4 (carries `@capacitor/share`), the Stage A and Stage B flag flips, the two AH-118 flag flips, the production walks (§3)                                                                                                                                                                            |

### Where each entry's obligation lives

| Entry      | What it carries                                                                                                                                                                                                                                                      | Step           |
| ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------- |
| AH-116 API | Campaign `status_counts`, talent-pool member filters, archived-brand read. HTTP only, **no migration**, no queue. One route option changes (`withTrashed(['show'])`): rebuild the route cache if the box has one                                                     | P4.1, P8       |
| AH-116 web | Main SPA only. Needs the AH-116 API (this sitting's API goes first)                                                                                                                                                                                                  | P1, P8         |
| AH-117     | Admin SPA only (list navigation)                                                                                                                                                                                                                                     | P1, P8         |
| AH-118     | Migrations 1 and 2 (`campaigns.restrict_to_listing_regions`, `messages.system_event_data`); flags `campaign_region_restriction_enabled`, `assignment_offer_edit_enabled`; **`queue:restart`**; admin push-deliveries filter                                          | P2, P4.3, P7   |
| AH-119     | Migrations 3 to 6 (owner, brand colour, edit/delete columns, two GIN indexes); flags `messages_v2_inbox_enabled`, `messages_v2_edit_search_enabled`; the lightbox (not behind a flag); the erasure code in `Steps` and `ErasureRegistry`; the media-bucket CORS rule | P2, P3, P6, P7 |

---

## 1. Rules for the sitting

1. **Stop on an `APP_KEY` change.** Fingerprint it (a hash of the line, never the value) at P0, after P1, after P4 and at the end of P8. If it ever differs: **restart nothing** (the running workers hold the old key in memory), restore the value from the recoverable copy, `config:cache`, reload FPM. `composer install` is not run at all (P1.2).
2. **Q10: no deploy step that restarts a worker while an erasure is claimed.** The check is `select count(*) from users where erasure_started_at is not null and erased_at is null` and must print `0`. Unlike the October sitting the columns **already exist**, so it is asked at **P0** (to decide whether to start at all) and again **immediately before `queue:restart`** (P4.3).
   If it is not `0` at P0, you may still migrate and deploy the API code, but **P4.3 is skipped** and the sitting is recorded as partial (AH-118's `queue:restart` and the erasure worker's reload then wait). The C4 countdown is open: the earliest real due date is **2026-11-15**.
3. **Flags stay OFF.** Nothing is flipped. P7 reads them back. Reading `Feature::active` for a flag with no stored row writes a `false` row: that is the intended default, not a change.
4. **The API goes first, then the web.** The new web calls new endpoints (`message-inbox`, edit/delete/search routes, the status counts, the region switch). Netlify publishes only after P4 and P7 are filled (P1.4). Build early, publish late.
5. **One command at a time for anything that writes** (P2, P3 grants, P6). Read the output before the next one.
6. **The CORS call replaces the whole bucket configuration.** `put-bucket-cors` does not merge. P6 reads the existing rules first (browser uploads already need a `PUT` rule) and writes back **existing rules plus the new one**.

---

## 2. The checklist

### P0. Before (read-only except the snapshot itself)

**P0.1 A fresh snapshot: name and creation time, must be today [CONSOLE or any shell with the AWS CLI]**

Take a new one in the quiet minute just before P1 (anything written after the snapshot is not in it), then read it:

```sh
aws rds describe-db-clusters --region eu-west-1 --query 'DBClusters[].DBClusterIdentifier' --output text      # CONFIRM the cluster id; Aurora cluster, not an instance
aws rds create-db-cluster-snapshot --region eu-west-1 --db-cluster-identifier <CLUSTER_ID> --db-cluster-snapshot-identifier ah116-ah119-predeploy-<YYYYMMDD>
aws rds describe-db-cluster-snapshots --region eu-west-1 --db-cluster-snapshot-identifier ah116-ah119-predeploy-<YYYYMMDD> \
  --query 'DBClusterSnapshots[0].[DBClusterSnapshotIdentifier,SnapshotCreateTime,Status,PercentProgress]' --output text
date -u '+%Y-%m-%d %H:%M UTC'
```

(If the database is a plain RDS instance, use `describe-db-snapshots` and `--db-snapshot-identifier`.) Healthy: the name is the one you just created, **`SnapshotCreateTime` has today's UTC date**, `Status` is **`available`**, progress `100`. A snapshot still `creating` is not a safety net: **do not start P1**.
**This snapshot also covers `down()` of migration 5, which discards withdrawn message text.** A restore creates a **new** cluster and has never been rehearsed ([`production-queue-worker.md`](production-queue-worker.md) §8.2).
Snapshot name: `______________` Creation time (UTC): `______________` Status: `______________` Today's date (UTC): `______________`
Result: `______________`

**P0.2 `APP_KEY` fingerprint, and a recoverable copy [APP]**

```sh
grep '^APP_KEY=' .env | sha256sum | cut -c1-12
```

Healthy: 12 hex characters, written down; compared after P1, after P4 and at the end of P8. Confirm (without printing the key) that a current copy of the production `APP_KEY` exists outside the box (Secrets Manager per `docs/05-SECURITY-COMPLIANCE.md`): **CONFIRM**. If the env file is not `/app/.env`, fingerprint wherever the key lives and say where.
Fingerprint: `______________` Env file: `______________` Recoverable copy confirmed: yes / no
Result: `______________`

**P0.3 Did `composer.lock` change? [any checkout, then HOST]**

```sh
git diff --stat 066ff29c 6d7a9c28 -- apps/api/composer.lock          # run 2026-11-06 in the development checkout: EMPTY
git rev-parse 066ff29c:apps/api/composer.lock 6d7a9c28:apps/api/composer.lock      # both 58ae398d21002237ef63f37bf0b5ead5fd417253
git diff --stat 066ff29c 6d7a9c28 -- apps/api/composer.json          # 3 lines: a dev-only script, test:pg
git diff --stat 066ff29c 6d7a9c28 -- pnpm-lock.yaml apps/main/package.json   # pnpm-lock +12, apps/main/package.json +1: @capacitor/share 8.0.3
```

**Answer written at drafting time: `composer.lock` changed: NO** (the same blob on both sides). `composer.json` changed only by the `test:pg` script, which production never runs. **`pnpm-lock.yaml` changed: YES** (one dependency, `@capacitor/share`), which is why P1.3 runs a real `pnpm install`.
Repeat on the box so the answer is the box's own (the box's last deployed API commit is the `066ff29c` of the log, **reported, not read**; the mirror's SHA for it differs):

```sh
cd ~/catalyst-engine && git rev-parse HEAD && git status --short | head -5
git rev-parse HEAD:apps/api/composer.lock          # APP-side checkout root; expected 58ae398d21002237ef63f37bf0b5ead5fd417253
```

Healthy: a clean tree and the lock blob `58ae398d…`. A different blob means the box is not where `deploy-log.md` says: **stop and reconcile** (then P1.2's `--no-scripts` form applies).
**Composer lock changed: NO** (drafting answer) · on the box: blob `______________` · pnpm lock changed: YES · box HEAD before: `______________`
Result: `______________`

**P0.4 `migrate:status --pending`, pasted [APP]**

```sh
php artisan migrate:status --pending
php artisan migrate:status | grep -v Pending | tail -3
```

Healthy: **exactly these six, nothing else, and every other migration `Ran`** (the October ones, through `2026_10_22_100000_add_erasure_claim_and_hold_to_users`, must already be `Ran`):

```
2026_11_05_100000_add_restrict_to_listing_regions_to_campaigns
2026_11_05_100001_add_system_event_data_to_messages
2026_11_06_100000_add_owner_user_id_to_agency_creator_relations
2026_11_06_100001_add_color_to_brands
2026_11_06_110000_add_edit_and_delete_columns_to_messages
2026_11_06_110001_add_body_search_indexes_to_messages
```

A seventh name, one of these already `Ran`, or an October migration still pending is a stop. Paste the output (names only):

```
______________
```

Last `Ran` line and batch: `______________`
Result: `______________`

**P0.5 Q10, row counts, ownership, and the flag baseline [APP]**

```sh
# (a) Q10: must print 0, and the queued erasure jobs must be 0
php artisan tinker --execute="echo DB::table('users')->whereNotNull('erasure_started_at')->whereNull('erased_at')->count(), ' ', DB::table('jobs')->where('queue','erasure')->count(), PHP_EOL;"

# (b) row counts BEFORE (compared after P2, and they size the GIN builds)
php artisan tinker --execute="echo json_encode(['campaigns'=>DB::table('campaigns')->count(),'messages'=>DB::table('messages')->count(),'relationship_messages'=>DB::table('relationship_messages')->count(),'brands'=>DB::table('brands')->count(),'agency_creator_relations'=>DB::table('agency_creator_relations')->count(),'users'=>DB::table('users')->count()]), PHP_EOL;"

# (c) who owns the five tables (the migrate role must be the owner or a member of the owner role)
php artisan tinker --execute="echo json_encode(DB::select(\"select tablename, tableowner from pg_tables where schemaname='public' and tablename in ('campaigns','messages','relationship_messages','agency_creator_relations','brands') order by tablename\")), ' ', DB::selectOne('select current_user as u')->u, PHP_EOL;"

# (d) every stored flag BEFORE: the baseline P7 compares with
php artisan tinker --execute="echo json_encode(DB::select('select name, scope, value, updated_at from features order by name')), PHP_EOL;"

# (e) the live bundles BEFORE (any machine; GET only)
for H in platform admin; do curl -sS https://$H.catalyst-growth.com/ | grep -o 'assets/[A-Za-z0-9._-]*\.js' | head -3; done
```

Healthy: (a) **`0 0`**: this is the Q10 rule (see rule 2 if not); (b) six numbers, written down (at the 2026-10-20 read `messages` was about 7 100 and `relationship_messages` about 3 800; re-read, they set how long the GIN builds hold their lock);
(c) five rows and the role (expected `engine_c_user`; **CONFIRM which role runs `migrate`** and that it owns these tables); (d) a list; **none of the four new flags has a row yet** (`campaign_region_restriction_enabled`, `assignment_offer_edit_enabled`, `messages_v2_inbox_enabled`, `messages_v2_edit_search_enabled`) and the push flag is `true`; (e) two asset names (they change when the sites are deployed).
Q10 / erasure jobs: `______` Counts: `______________` Owners / role: `______________` Flags before (paste names, values, `updated_at`): `______________` main bundle before: `______` admin bundle before: `______`
Result: `______________`

### P1. Code

**P1.1 Pull the mirror's copy of `6d7a9c28` [HOST]**

Before this step the colleague confirms **the Bitbucket mirror holds the tip** (a separate human step after the push). The mirror's SHAs differ from GitHub's, so **prove the code by tree hashes**. The GitHub values for `6d7a9c28` (read 2026-11-06):

```
apps      bd95756a0252cda39eedaa34ffa570ec9bd0ee48
packages  9e6cc01f609c8157617e3c3ea56bfffd62d33331
pnpm-lock 94d23dc9e4b3279dac336f26d7b54b9447f705b7
```

Same quiet minute as P2 (the new code reads the new columns; keep the gap between pull and migrate short; do not reload FPM until P4):

```sh
cd ~/catalyst-engine
OLD=$(git rev-parse HEAD); echo "OLD=$OLD"
git remote -v | head -2                                    # CONFIRM the remote is the Bitbucket mirror
git fetch origin
git log origin/main -3 --format='%h %s'                    # the top commits' SUBJECTS must include: "docs: AH-119 walk passed, build 4 share plugin, tech-debt rows 17 and 18"
git merge --ff-only origin/main
git rev-parse HEAD
git rev-parse HEAD:apps HEAD:packages HEAD:pnpm-lock.yaml  # must equal the three values above (HEAD may be a docs-only descendant: docs/ is not in these trees)
```

Healthy: a fast-forward; the three tree hashes **equal** the table above. A difference means the mirror holds other content (or another tip): **stop**. Then **[APP]** `grep '^APP_KEY=' .env | sha256sum | cut -c1-12` is **identical to P0.2**.
Mirror HEAD (SHA, subject): `______________` Trees equal: yes / no Fingerprint after: `______________` Same as P0.2: yes / no
Result: `______________`

**P1.2 PHP dependencies: `composer install --no-dev` only if the lock changed [HOST, then APP]**

P0.3 says the lock did **not** change: **skip `composer install` entirely**, and confirm the guard on the box:

```sh
git diff --stat $OLD..HEAD -- apps/api/composer.lock          # HOST; expected: empty = skip
grep -n 'post-install-cmd' composer.json                       # APP; expected: no output
grep -n 'key:generate' composer.json                           # APP; expected: one line, under post-create-project-cmd
```

**If the lock diff is not empty** (the box was older than expected), only the safe form, which runs no hooks; **never plain `composer install`** (the 2026-08-11 incident):

```sh
composer install --no-dev --optimize-autoloader --no-scripts   # APP
php artisan package:discover --ansi                            # APP
```

Diff empty: yes / no `______` Composer ran: no / `--no-scripts` form
Result: `______________`

**P1.3 JavaScript dependencies and both builds [BUILD]**

If Netlify builds from the mirror itself, this happens there and you only read the build log; if you build and publish by hand:

```sh
pnpm install --frozen-lockfile                 # a REAL install this time: pnpm-lock.yaml gained @capacitor/share 8.0.3 (apps/main)
pnpm --filter @catalyst/main build             # = vue-tsc --noEmit && vite build (web mode). NOT build:native
pnpm --filter @catalyst/admin build
ls -l apps/main/dist/.well-known/apple-app-site-association apps/main/dist/_headers      # both must exist
ls apps/main/dist/.well-known/                                                            # expected: apple-app-site-association ONLY
```

Healthy: install finishes without changing the lockfile (`--frozen-lockfile` fails if it would), both builds exit 0 (the type-check runs first), the association file and `_headers` are present, and there is no `assetlinks.json`. Do **not** run `build:native` (a device build). `@capacitor/share` is bundled into the web build and is a no-op there: the web branch never calls it.
Build source: Netlify builds from the mirror / built by hand on `______` Build commit: `______________` Install changed the lock: no / yes
Result: `______________`

**P1.4 Netlify: stage now, PUBLISH ONLY AFTER P4 AND P7 ARE FILLED**

Publish **both** sites from this tip: `platform.catalyst-growth.com` (main: AH-116 web, AH-118, AH-119) and `admin.catalyst-growth.com` (AH-117, AH-118). **Gate (rule 4): the API is live (P4 done), `migrate:status` shows no pending (P2), and the four flags read back OFF (P7).** Until then the builds sit as unpublished deploys.
Order: **main first, then admin**; either is safe once the gate is met. Healthy: Netlify shows each production deploy **Published** for the commit above (or the time you published), and the bundle hashes differ from P0.5 (e):

```sh
for H in platform admin; do curl -sS https://$H.catalyst-growth.com/ | grep -o 'assets/[A-Za-z0-9._-]*\.js' | head -3; done
```

Gate met at (time): `______________` `main` published: `______________` `admin` published: `______________` New bundles: main `______` admin `______` (different from P0.5: yes / no)
Result: `______________`

### P2. Migrate [APP]

**Take the snapshot (P0.1) first, and confirm `available`.** Pick a quiet minute. Nothing here rewrites a row. `ALTER TABLE` takes a brief exclusive lock; a long-running transaction elsewhere can make it wait and queue requests behind it. The two GIN builds are **not `CONCURRENTLY`** and run inside the migration transaction: each **blocks writes** to its table for the length of the build.

| #   | Migration                                                         | Entry  | What it does                                                                                                                                                                                                                                                                                                                                |
| --- | ----------------------------------------------------------------- | ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `2026_11_05_100000_add_restrict_to_listing_regions_to_campaigns`  | AH-118 | `campaigns.restrict_to_listing_regions` **boolean NOT NULL DEFAULT false** (a constant default: on PostgreSQL 11 and later it is a catalog change, not a table rewrite; CONFIRM the Aurora engine version is 11+, it is 16 locally)                                                                                                         |
| 2   | `2026_11_05_100001_add_system_event_data_to_messages`             | AH-118 | `messages.system_event_data` jsonb, nullable, no default                                                                                                                                                                                                                                                                                    |
| 3   | `2026_11_06_100000_add_owner_user_id_to_agency_creator_relations` | AH-119 | `agency_creator_relations.owner_user_id` bigint, nullable, FK to `users` `nullOnDelete`, plus index `idx_acr_agency_owner (agency_id, owner_user_id)` (plain `CREATE INDEX`, short lock on a small table)                                                                                                                                   |
| 4   | `2026_11_06_100001_add_color_to_brands`                           | AH-119 | `brands.color` varchar(16), nullable                                                                                                                                                                                                                                                                                                        |
| 5   | `2026_11_06_110000_add_edit_and_delete_columns_to_messages`       | AH-119 | On **both** `messages` and `relationship_messages`: `edited_at` timestamptz, `original_body` text, `deleted_by_user_id` bigint FK to `users` **`restrictOnDelete`**, `deleted_body` text; all nullable, no default. **`down()` discards withdrawn text**                                                                                    |
| 6   | `2026_11_06_110001_add_body_search_indexes_to_messages`           | AH-119 | **Two GIN indexes**, pgsql only: `idx_messages_body_fts` and `idx_relationship_messages_body_fts`, both on `to_tsvector('simple', coalesce(body, ''))`. **Index build #1 locks `messages`, build #2 locks `relationship_messages`**, each for the build time (milliseconds to seconds at the P0.5 (b) counts). Never run on production data |

**Expected count: 6**, in this order, one new batch. Twelve columns appear (`campaigns` 1, `messages` 5, `relationship_messages` 4, `agency_creator_relations` 1, `brands` 1), three indexes. Every new column is null (or `false`) for every existing row.
(`restrictOnDelete` on `deleted_by_user_id` means a user row cannot be hard-deleted while a message names them as its deleter. With the flags OFF no such row exists; erasure scrubs it.)

```sh
php artisan migrate --pretend --force      # reads only: prints the SQL
```

Healthy: `ALTER TABLE` on `campaigns`, `messages`, `agency_creator_relations`, `brands`, `relationship_messages`; the FK and index statements; **two `CREATE INDEX ... USING gin`**; and **nothing else**: no `DROP`, no `UPDATE`, `DELETE` or `INSERT` of data. Anything else is a stop.

```sh
php artisan migrate --force                # --force is required with APP_ENV=production
php artisan migrate:status --pending       # expect: No pending migrations
```

Healthy: exactly six `DONE` lines in the order above, then `No pending migrations`. The migrate role must own the tables (P0.5 (c)). Then prove schema, indexes and counts (an interrupted build leaves an **invalid** index):

```sh
php artisan tinker --execute="echo json_encode(DB::select(\"select c.relname as idx, i.indisvalid as valid, i.indisready as ready from pg_index i join pg_class c on c.oid = i.indexrelid where c.relname in ('idx_messages_body_fts','idx_relationship_messages_body_fts','idx_acr_agency_owner') order by c.relname\")), PHP_EOL;"
php artisan tinker --execute="echo json_encode(DB::select(\"select table_name, column_name, data_type, is_nullable, column_default from information_schema.columns where table_schema = 'public' and ((table_name='campaigns' and column_name='restrict_to_listing_regions') or (table_name='messages' and column_name in ('system_event_data','edited_at','original_body','deleted_by_user_id','deleted_body')) or (table_name='relationship_messages' and column_name in ('edited_at','original_body','deleted_by_user_id','deleted_body')) or (table_name='agency_creator_relations' and column_name='owner_user_id') or (table_name='brands' and column_name='color')) order by table_name, column_name\")), PHP_EOL;"
php artisan tinker --execute="echo json_encode(['campaigns'=>DB::table('campaigns')->count(),'messages'=>DB::table('messages')->count(),'relationship_messages'=>DB::table('relationship_messages')->count(),'brands'=>DB::table('brands')->count(),'agency_creator_relations'=>DB::table('agency_creator_relations')->count(),'users'=>DB::table('users')->count(),'edited_or_deleted'=>DB::table('messages')->whereNotNull('edited_at')->orWhereNotNull('deleted_by_user_id')->count()]), PHP_EOL;"
grep '^APP_KEY=' .env | sha256sum | cut -c1-12                                     # identical to P0.2
```

Healthy: three index rows, all `"valid":true,"ready":true`; **12 column rows**, every one `YES` nullable except `campaigns.restrict_to_listing_regions` (`NO`, default `false`); the counts equal P0.5 (b) (plus live traffic) and `edited_or_deleted` is **0**.
**If a GIN migration fails:** a transaction rolls the whole migration back, so no index is left, but check: if `idx_messages_body_fts` or `idx_relationship_messages_body_fts` exists and is not valid, `DROP INDEX IF EXISTS <name>` as the table owner and run `php artisan migrate --force` again; migrations before it stay `Ran`.
Migrate role: `______________` DONE lines: `______________` Indexes valid: `______` Columns (12?): `______` Counts: `______________` Fingerprint: `______________`
Build time of the two GIN indexes (from the `DONE` line durations): `______` / `______`
Result: `______________`

### P3. GRANT and column read-backs for `engine_c_user` on every new column [APP]

Postgres does not extend table access to the application role for **new tables**, but this release creates **none**: every change is a column or an index on an existing table, which the role's table-level privileges cover. **Prove it rather than assume it**: if the box uses column-level grants, a new column would be invisible to the app (`permission denied for table`).
The check runs as the connection the app uses, for the 12 new columns × `SELECT`, `INSERT`, `UPDATE`, and **prints only the failures**:

```sh
php artisan tinker --execute="\$cols = ['campaigns'=>['restrict_to_listing_regions'],'messages'=>['system_event_data','edited_at','original_body','deleted_by_user_id','deleted_body'],'relationship_messages'=>['edited_at','original_body','deleted_by_user_id','deleted_body'],'agency_creator_relations'=>['owner_user_id'],'brands'=>['color']]; \$n=0; \$bad=0; foreach (\$cols as \$t=>\$cs) { foreach (\$cs as \$c) { foreach (['SELECT','INSERT','UPDATE'] as \$p) { \$n++; \$ok = DB::selectOne('select has_column_privilege(current_user, ?, ?, ?) as ok', ['public.'.\$t, \$c, \$p])->ok; if (!\$ok) { \$bad++; echo 'FALSE ',\$t,'.',\$c,' ',\$p,PHP_EOL; } } } } echo 'checked=',\$n,' false=',\$bad,' role=',DB::selectOne('select current_user as u')->u,PHP_EOL;"
```

Healthy: **`checked=36 false=0 role=engine_c_user`** and no `FALSE` line. (Run against the local development database 2026-11-06: `checked=36 false=0`, role `catalyst`, which owns the tables; production's value is what matters.)
Also read the table-level privilege on the five tables, and that `messages` is writable end to end by the role (a harmless read of the privilege, no write):

```sh
php artisan tinker --execute="foreach (['campaigns','messages','relationship_messages','agency_creator_relations','brands'] as \$t) { foreach (['SELECT','INSERT','UPDATE'] as \$p) { if (!DB::selectOne('select has_table_privilege(current_user, ?, ?) as ok', ['public.'.\$t, \$p])->ok) { echo 'FALSE ',\$t,' ',\$p,PHP_EOL; } } } echo 'tables read', PHP_EOL;"
```

Healthy: only `tables read` is printed. If any line is `FALSE`, grant it **as the table owner** (**CONFIRM** how you get an owner SQL session on Aurora; the app role cannot grant itself): table level `GRANT SELECT, INSERT, UPDATE ON <table> TO engine_c_user;`, or per column `GRANT SELECT (<column>), INSERT (<column>), UPDATE (<column>) ON <table> TO engine_c_user;`, then re-run both reads. **No sequence or new table needs a grant in this release** (no `CREATE TABLE`).
Role: `______________` Column read (`checked=36 false=0`?): `______________` Table read: `______________`
Result: `______________`

### P4. Infra [APP, supervisor in the container]

**P4.1 Cache the config and, if the box uses them, the route and view caches**

```sh
php artisan config:cache
grep '^APP_KEY=' .env | sha256sum | cut -c1-12                                     # identical to P0.2
ls bootstrap/cache/                                                                 # routes-v7.php present? (CONFIRM whether this box caches routes)
```

Healthy: `config:cache` succeeds. **If `bootstrap/cache/routes-v7.php` exists**, run `php artisan route:cache`: this release adds routes (message inbox, owner reassign, edit/delete/search, offer edit) and changes one route option (`withTrashed(['show'])` on `brands`), and a stale route cache answers 404 for them. (Run `view:cache` only if the box already runs it.)
Output: `______________` Route cache present: yes / no
Result: `______________`

**P4.2 PHP-FPM reload [command from your usual procedure]**

```sh
supervisorctl status <PHP_FPM_PROGRAM>
supervisorctl signal USR2 <PHP_FPM_PROGRAM>        # graceful reload (or `kill -USR2 <master pid>` if the program wraps FPM)
supervisorctl status <PHP_FPM_PROGRAM>
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/up       # expect 200 (no curl in the image? use wget -S -O /dev/null)
```

Healthy: FPM stays RUNNING and `/up` answers `200`. Opcache serves stale code until this.
Program: `______________` Reload command: `______________`
Result: `______________`

**P4.3 Restart the workers [APP]: only if Q10 = 0, asked again right now**

```sh
php artisan tinker --execute="echo DB::table('users')->whereNotNull('erasure_started_at')->whereNull('erased_at')->count(), ' ', DB::table('jobs')->where('queue','erasure')->count(), PHP_EOL;"   # must print: 0 0
php artisan queue:restart
sleep 10
supervisorctl status laravel-queue laravel-erasure
```

**If the check prints anything but `0 0`: do not run `queue:restart`.** It signals every `queue:work`, and an erasure worker mid-run would be asked to stop (it finishes its in-flight job first, up to an hour, but a stop is not what the C4 countdown wants). Skip the step, **record it as not run**, and say so in the log: AH-118's mail and notification classes and the erasure code then load at the next worker restart.
Healthy when run: `laravel-queue` `RUNNING` with an uptime of seconds; `laravel-erasure` `RUNNING` (it restarts onto the new `Steps` and `ErasureRegistry` after any in-flight job). `laravel-schedule` is **not** restarted by `queue:restart` and needs nothing.
Q10 count / erasure jobs: `______` Ran: yes / no (reason) `______` `laravel-queue`: `______________` `laravel-erasure`: `______________`
Result: `______________`

**P4.4 Supervisor status, including `laravel-erasure` [APP]**

```sh
supervisorctl status
ps -o pid,args | grep '[d]atabase_erasure'
php artisan tinker --execute="echo json_encode([Illuminate\Support\Facades\Queue::connection('database_erasure')->size('erasure')]), PHP_EOL;"
```

Healthy: `cron`, `laravel-erasure`, `laravel-queue`, `laravel-schedule`, `nginx`, `php-fpm` all `RUNNING` (the six programs recorded 2026-10-29), **no program missing and none crash-looping** (uptimes sane); `ps` shows a process whose arguments contain `database_erasure`; the queue size prints `[0]`. **No new program is added by this release.**
`supervisorctl status` (paste all lines): `______________` Erasure queue size: `______`
Result: `______________`

**P4.5 Scheduler [APP]**

```sh
supervisorctl status laravel-schedule
php artisan schedule:list
```

Healthy: `laravel-schedule` RUNNING (`schedule:work`, not cron; the `cron` service has no `schedule:run` line). The list is **unchanged** from the October sitting: `messages:send-digest`, `boards:scan-overdue`, `creators:send-incomplete-nudges`, `push:prune-deliveries`, `sanctum:prune-expired --hours=24`. This release adds **no** scheduled entry: a sixth or a missing line is a stop (the code is not the tip).
Entries: `______________`
Result: `______________`

### P5. One-shots: none expected [APP]

None of AH-116, AH-117, AH-118 or AH-119 has a one-shot command, backfill or seeder (no `notifications:clear-…`, no `erasure:*`, no `db:seed`). **Confirm** it from the repo on the box:

```sh
git diff --name-only $OLD..HEAD -- apps/api/app/Console apps/api/database/seeders | head -20
php artisan list | grep -E 'messages|erasure|clear-read'
```

Healthy: the first prints **no new command or seeder file that the five entries rely on** (any listed file is read and recorded; the dev walk seeders `Ah119WalkSeeder` and `Ah119WalkCleanupSeeder` **exist in `database/seeders` and are never run on production**); the second shows only commands that were already there. **No row is written in this step.**
Files listed: `______________` Commands: `______________` Ran any one-shot: no
Result: `______________`

### P6. Media bucket CORS: allow `GET` from the two web origins [CONSOLE or any shell with the AWS CLI]

**Why.** The image lightbox's web "Download" reads the signed URL with a `GET` from the page (to save it as a file without opening a tab). A cross-origin read needs `Access-Control-Allow-Origin`. Without it the download **falls back to a plain link** (the browser opens or saves the URL by its own rules): nothing breaks, the Download button is just weaker. The Capacitor app does not need this (it shares the URL through the system sheet).
**What `put-bucket-cors` does.** It **replaces the whole configuration**. Browser uploads already `PUT` to presigned URLs, so a rule for `PUT` almost certainly exists: **read it first and write back the existing rules plus the new one**; never paste the new rule alone.

```sh
BUCKET=<MEDIA_BUCKET>            # APP: php artisan tinker --execute="echo config('filesystems.disks.media.bucket'), PHP_EOL;"
aws s3api get-bucket-cors --bucket "$BUCKET" > /tmp/cors-before.json ; echo "exit=$?"     # exit=254 with NoSuchCORSConfiguration means none exists yet
jq -c '.CORSRules[] | {AllowedOrigins, AllowedMethods}' /tmp/cors-before.json             # origins and methods only
```

Healthy: a list of existing rules (write them down) or `NoSuchCORSConfiguration`. **If a rule already allows `GET` from both `https://platform.catalyst-growth.com` and `https://admin.catalyst-growth.com`, you are done: record it and skip the write.**
**The rule to add (exact):**

```json
{
  "AllowedOrigins": ["https://platform.catalyst-growth.com", "https://admin.catalyst-growth.com"],
  "AllowedMethods": ["GET", "HEAD"],
  "AllowedHeaders": [],
  "ExposeHeaders": [],
  "MaxAgeSeconds": 3000
}
```

`GET` with no custom request header is a simple cross-origin request, so there is no preflight and no `AllowedHeaders` entry is needed; the list is empty on purpose. No wildcard origin, no `localhost`, no `capacitor://localhost` (the app shares, it does not fetch).
Write the merged configuration, then read it back (the first line keeps every existing rule and appends the new one; with `NoSuchCORSConfiguration` start from `{"CORSRules":[]}`):

```sh
jq '.CORSRules += [{"AllowedOrigins":["https://platform.catalyst-growth.com","https://admin.catalyst-growth.com"],"AllowedMethods":["GET","HEAD"],"AllowedHeaders":[],"ExposeHeaders":[],"MaxAgeSeconds":3000}]' /tmp/cors-before.json > /tmp/cors-after.json
jq -c '.CORSRules[] | {AllowedOrigins, AllowedMethods}' /tmp/cors-after.json                 # read it: the old rules are all still there
aws s3api put-bucket-cors --bucket "$BUCKET" --cors-configuration file:///tmp/cors-after.json
aws s3api get-bucket-cors --bucket "$BUCKET" | jq -c '.CORSRules[] | {AllowedOrigins, AllowedMethods}'
```

Then prove it from outside **without printing the signed URL** (it carries temporary credentials):

```sh
KEY=$(aws s3api list-objects-v2 --bucket "$BUCKET" --max-keys 1 --query 'Contents[0].Key' --output text)
SIGNED=$(aws s3 presign "s3://$BUCKET/$KEY" --expires-in 120)
curl -s -o /dev/null -D - -H "Origin: https://platform.catalyst-growth.com" "$SIGNED" | grep -i -E '^(HTTP|access-control-allow-origin)'
curl -s -o /dev/null -D - -H "Origin: https://evil.example" "$SIGNED" | grep -i -E '^(HTTP|access-control-allow-origin)'
unset SIGNED KEY
```

Healthy: first call `HTTP/… 200` and **`access-control-allow-origin: https://platform.catalyst-growth.com`**; second call `200` and **no** `access-control-allow-origin` line. Also run the existing upload path (P8.7 does) to prove the old `PUT` rule survived.
Existing rules before: `______________` Rule added or already present: `______________` Allow-origin (platform): `______` (evil.example): none? `______` Bucket region (from P9.1): `______`
Result: `______________`

### P7. Flags: the four new flags read back OFF; change nothing [APP]

```sh
php artisan tinker --execute="var_dump(Laravel\Pennant\Feature::active('campaign_region_restriction_enabled'), Laravel\Pennant\Feature::active('assignment_offer_edit_enabled'), Laravel\Pennant\Feature::active('messages_v2_inbox_enabled'), Laravel\Pennant\Feature::active('messages_v2_edit_search_enabled'));"
php artisan tinker --execute="echo json_encode(DB::select(\"select name, scope, value, updated_at from features where name in ('campaign_region_restriction_enabled','assignment_offer_edit_enabled','messages_v2_inbox_enabled','messages_v2_edit_search_enabled') order by name\")), PHP_EOL;"
php artisan tinker --execute="echo json_encode(DB::select('select name, scope, value, updated_at from features order by name')), PHP_EOL;"
```

Healthy: **four `bool(false)`**; the stored rows (reading `Feature::active` created them if they did not exist) read `"false"` for each of the four names; and the full list equals P0.5 (d) **plus those four rows and nothing else changed** (every other flag keeps its value and `updated_at`; push stays `true`). The admin Feature-flags page lists the four, all off.
**If any of the four is ON, switch it OFF in the admin page and say so**: nothing is armed in this sitting. The flips come later, by Pedram, each with a reason (§3).
Flag values: `______________` Others unchanged (names, values, `updated_at`): yes / no
Result: `______________`

### P8. Verify

**P8.1 `/up` and the key [APP, any machine]**

```sh
curl -s -o /dev/null -w '%{http_code}\n' https://platform-api.catalyst-growth.com/up        # expect 200
grep '^APP_KEY=' .env | sha256sum | cut -c1-12                                              # APP; identical to P0.2
php artisan migrate:status --pending                                                        # APP; No pending migrations
```

`/up`: `______` Fingerprint: `______________` Same as P0.2: yes / no
Result: `______________`

**P8.2 Sign-ins [browser]**

Sign in with your own accounts (no password goes in this file): **an agency user** on `https://platform.catalyst-growth.com`, **a creator** on the same site, **an admin** on `https://admin.catalyst-growth.com`. Healthy: each reaches its dashboard. In the agency session read `/me` in the network tab (or the browser console of the app): **`features.messages_v2_inbox` and `features.messages_v2_edit_search` are `false`**, and `features.campaign_region_restriction` and `features.assignment_offer_edit` are `false`.
Agency: `______` Creator: `______` Admin: `______` `/me` features (all four false?): `______`
Result: `______________`

**P8.3 Campaigns tabs with counts (AH-116) [browser, agency]**

Open **Campaigns**. Healthy: every status tab (Draft, Active, Paused, Completed, Cancelled) shows a count, the header reads "N campaigns, M active", and the counts do not change when a tab is selected (they describe the whole agency, not the filtered list). Optionally in the network tab: `GET .../campaigns?per_page=1` returns `meta.status_counts` with the five keys summing to `meta.total`.
Counts seen: `______________` Header: `______________`
Result: `______________`

**P8.4 Admin Approvals back-navigation (AH-117) [browser, admin]**

Open **Approvals** with per-page 10 (so a second page exists), go to page 2, pick a status chip, scroll, open a row, then press **Back**. Healthy: the list returns on page 2 with the chip, the scroll position is kept, and one list request is made on a cold load. Optionally repeat on one other converted page (KYC queue, All creators or Agencies).
Approvals: `______` Other page: `______`
Result: `______________`

**P8.5 Old Messages page while the inbox flag is OFF (AH-119) [browser, agency, then API]**

Open **Messages** as the agency user. Healthy: **the old relationship inbox renders** (no Direct/Mine chips, no All|Unread control, no filter popovers), a thread opens, and a message shows **no "⋯" control**. Then, with the same session cookie from the browser's network tab, **do not paste the cookie anywhere**; instead read the status codes in the network tab for these requests, or from the app side:

```sh
php artisan route:list --path=message-inbox --json | jq -r '.[].uri' | head -3        # APP: the route exists in the code
```

Healthy at the HTTP level: `GET .../message-inbox` and `GET .../message-search` answer **404** and `PATCH`/`DELETE` on a message answer **404** while their flags are OFF. The creator **Messages** list and an assignment chat are unchanged.
Old inbox: `______` 404s seen: `______` Creator messages: `______`
Result: `______________`

**P8.6 Image lightbox, which is not behind a flag (AH-119) [browser]**

In a thread that already contains an **image attachment** (any real or test thread; **open, do not send**): the image shows as a thumbnail, **tapping it opens a full-screen view on a dark backdrop**, with a close (✕) and a download button top-right; **Esc** and a click on the backdrop close it; **no new tab opens**. Then press Download: with P6 done the file is saved with its own name; with no CORS rule the browser opens or saves the URL instead (record which).
Lightbox: `______` Esc / backdrop: `______` New tab: none? `______` Download: saved as file / fell back `______`
Result: `______________`

**P8.7 Uploads still work after the CORS change [browser, agency]**

In a **test** thread (a named test agency, never a real conversation), paste or attach a small image and send it. Healthy: the upload completes and the image appears (it is the browser `PUT` to the signed URL that the old CORS rule serves; P6 added a rule, it must not have removed one). Delete the test message if the flag-OFF UI allows it; otherwise note the thread.
Upload: `______` Thread used: `______________`
Result: `______________`

**P8.8 Brands and campaigns render (AH-119 colour dot) [browser, agency]**

The brand list and the campaigns table render; the new brand colour dot is the neutral derived one (`brands.color` is null everywhere). An archived brand's page (open one by its link, if any exists) shows the Archived pill and no Edit or Archive button.
Result: `______________`

**P8.9 Row counts and the schema [APP]**

Repeat the P2 counts command and the P3 column read. Healthy: the counts equal P0.5 (b) (plus live traffic), `edited_or_deleted` is still **0**, and `checked=36 false=0`.
Counts: `______________` P3 read: `______________` Fingerprint at the end: `______________` Same as P0.2: yes / no
Result: `______________`

### P9. Read-backs still open from 2026-10-29 [CONSOLE / LAPTOP; read-only]

Unchanged questions from [`deploy-ah104-ah113.md`](deploy-ah104-ah113.md) P9 that were never answered (the log records them as **not read**). They feed the privacy policy's AWS line, [`account-erasure.md`](account-erasure.md) §2 items 4 and 5 and `docs/store/review-notes.md`. Nothing here changes anything.

**P9.1 S3 media bucket: region and versioning**

```sh
php artisan tinker --execute="echo json_encode(['default'=>config('filesystems.default'),'media_bucket'=>config('filesystems.disks.media.bucket'),'media_region_configured'=>config('filesystems.disks.media.region')]), PHP_EOL;"   # APP: names only
aws s3api get-bucket-location   --bucket <MEDIA_BUCKET>        # the bucket's REAL region (null/None means us-east-1; "EU" is the legacy name for eu-west-1)
aws s3api get-bucket-versioning --bucket <MEDIA_BUCKET>        # Status: Enabled / Suspended / (empty = never versioned)
```

Healthy: any answer is acceptable; **write it down**. The configured region is not proof of the bucket's region. The policy says "Ireland (EU)"; the database is `eu-west-1`; if the bucket is elsewhere the policy line needs the other region too. **If versioning is `Enabled`**, the app role needs `s3:ListBucketVersions` and `s3:DeleteObjectVersion` before the first real erasure (a C4 precondition); if empty or `Suspended`, the purge still needs `s3:ListBucketVersions`, or `ERASURE_PURGE_OBJECT_VERSIONS=false` must be set knowingly.
Bucket: `______________` Real region: `______________` Configured region: `______________` Versioning: `______________`
Result: `______________`

**P9.2 Postmark message retention**

In the Postmark console, open the server's message stream settings and read the **message retention period** (days) for the stream production mail uses (`mail.default` is `smtp` → `smtp.postmarkapp.com`). The exact menu name is not verified here. The privacy policy and [`account-erasure.md`](account-erasure.md) §7 depend on it.
Retention (days): `______________` Stream: `______________`
Result: `______________`

**P9.3 `SANCTUM_STATEFUL_DOMAINS` has no bare `localhost` (open since AH-099) [APP]**

```sh
php artisan tinker --execute="echo json_encode(config('sanctum.stateful')), PHP_EOL;"
grep '^SANCTUM_STATEFUL_DOMAINS=' .env        # hostnames only, no secret
```

Healthy: **real production hostnames only; no bare `localhost`** (a bare `localhost` makes `https://localhost` a stateful origin and every bearer POST from Android 419s). Read the `.env` line in case config is cached from before.
Stateful list: `______________` Bare `localhost` present: yes / no
Result: `______________`

### Record it [deploy-log.md]

Fill the five `PENDING` entries, **AH-116 API, AH-116 web, AH-117, AH-118, AH-119**: the snapshot name and creation time, the six migrations and the batch, the grant read, the index validity, the supervisor lines (P4.4), whether `queue:restart` ran and the Q10 count, the CORS rules before and after, the four flag read-backs, the fingerprints, the operator, **both SHAs** (mirror and GitHub) with the tree-hash check, and **anything unexpected**. Set each **Range** to what was actually deployed and flip the status to `DEPLOYED` with today's date.
Record deploy state there only. Mark what stayed **not read**; never write "passed" for a step that was not run.
Done: yes / no

---

## 3. Not in this sitting (named, so nothing is assumed done)

- **Native build 4** (`CURRENT_PROJECT_VERSION` 4 already set in the repo): carries `@capacitor/share` 8.0.3 (new native plugin; the Android and iOS projects changed by `cap sync`). Archive, read the entitlements, upload, install; then the device walks: messages v2 (`docs/reviews/messages-v2-device-walk.md`, W1 to W9) and campaign rules (N1 to N10, `campaign-rules-plan.md` §6).
- **The flag flips, in this order, each a separate act by Pedram with a reason, and each read back:** `campaign_region_restriction_enabled`, then `assignment_offer_edit_enabled` (AH-118); `messages_v2_inbox_enabled` (Stage A) after the web deploy; `messages_v2_edit_search_enabled` (Stage B) **only after build 4 is live** (a build 3 install renders a deleted message as a blank bubble).
- **No `queue:restart` and no worker restart while an erasure is claimed** (the earliest real due date is 2026-11-15; [`account-erasure.md`](account-erasure.md)).
- **Rotate the Firebase service-account key before the App Store release** (tech-debt row 18).
- **The native lightbox shares the signed URL, not the file** (tech-debt row 17).

---

## 4. If something goes wrong

**First rule: stop, record the state, change nothing else.** Then:

| Symptom                                                                    | Do                                                                                                                                                                                                                                                                                                                 |
| -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **`APP_KEY` fingerprint differs** (any step)                               | **Restart nothing.** Restore the value from the recoverable copy (P0.2), `php artisan config:cache`, reload FPM. Do not run `composer install` again                                                                                                                                                               |
| Tree hashes in P1.1 differ from the table                                  | The mirror holds other content. **Stop before migrating**; ask Pedram. Do not pull "close enough"                                                                                                                                                                                                                  |
| A migration fails                                                          | Do not re-run blindly. Read the error and `migrate:status`. Each migration is a transaction: a failure leaves the earlier ones `Ran` and the failing one undone. Fix the cause (usually ownership or a lock wait), run `php artisan migrate --force` again                                                         |
| The GIN build holds writes too long (requests queue, 500s on message send) | It ends when the build ends; do not kill the session mid-build. If it is clearly stuck, `pg_terminate_backend` the migration's backend as the owner: the transaction rolls back and no index is left. Re-run at a quieter minute                                                                                   |
| 500s or errors on agency/creator pages between P1.1 and P2                 | The new code reads the new columns before they exist: **run P2 now**, do not roll the code back first (this is why P1.1 and P2 are one quiet minute)                                                                                                                                                               |
| P3 prints `FALSE` lines                                                    | Grant as the table owner (P3), re-read. Until then message sends and campaign creation can fail with `permission denied`                                                                                                                                                                                           |
| Code problem after migrating (API or SPA)                                  | **Roll the code back, leave the columns and indexes.** Old code ignores them. `git checkout <OLD>` on the host, rebuild and republish the SPAs, `config:cache`, reload FPM, `queue:restart` only after the Q10 check                                                                                               |
| Roll the schema back                                                       | `php artisan migrate:rollback --step=6 --force` drops the columns and indexes. Safe while they are empty (they are at deploy: both message flags are OFF). **Never after a message was edited or deleted**: `down()` of migration 5 discards `original_body` and `deleted_body`. Restore from the snapshot instead |
| The CORS write removed an existing rule (uploads fail in P8.7)             | Re-apply `/tmp/cors-before.json`: `aws s3api put-bucket-cors --bucket "$BUCKET" --cors-configuration file:///tmp/cors-before.json`. That is why P6 saves it first                                                                                                                                                  |
| Web Download falls back to a plain link                                    | Not a fault: the CORS rule is missing or the browser cached the image without CORS headers (a hard reload clears it). Re-read P6                                                                                                                                                                                   |
| A flag reads ON                                                            | Switch it OFF in the admin Feature-flags page and say so (P7)                                                                                                                                                                                                                                                      |
| `laravel-erasure` is not `RUNNING` after P4.3                              | Read `supervisorctl status` and its log; `supervisorctl start laravel-erasure:*` once. Nothing needs it while erasure is not run; the default worker is unaffected                                                                                                                                                 |
| **Data problem** (not just schema)                                         | **Restore from the P0.1 snapshot.** A restore creates a **new cluster**; it does not roll the live one back in place. **CONFIRM** how the app is re-pointed. Never rehearsed; everything written after the snapshot is lost. Prefer fix-forward                                                                    |

**Kill switches in this deploy:** the four new flags are the switches for their features, all OFF. **No kill switch exists** for the lightbox, the status counts, the pool filters, the brand colour dot or the archived-brand read: they ship live with the web build.

---

_Provenance: drafted by Cursor on 2026-11-06 from `docs/runbooks/deploy-log.md` (the five `PENDING` entries), [`deploy-ah104-ah113.md`](deploy-ah104-ah113.md), the six migration files, `git diff 066ff29c 6d7a9c28` (lock files, migrations, `composer.json`, `apps/main/package.json`) and the tree hashes quoted in P1.1. **Run against the local development database only, to check syntax and output shape** (never production): the P3 column read (`checked=36 false=0`, role `catalyst`) and the P2 index and column reads (three valid indexes, 12 columns, owners `catalyst`). **No command has been run against production.** **Still CONFIRM, because the repo cannot say:** the Aurora cluster identifier and engine version, the migrate role and whether it owns the five tables, the env file path, the PHP-FPM program name, whether the box caches routes, how the Netlify sites are deployed from the mirror, the existing CORS rules on the media bucket, and the S3 and Postmark answers in P9._
