# Deploy runbook: AH-115 + AH-120 (creator web restyle, creator inbox v2, brand exclusivity), one sitting

**Status: DEPLOYED 2026-11-12 for P0 to P9 (as reported by Pedram; the colleague's filled runbook was not returned, so the `Result:` lines are not read). Native build 5 was uploaded and walked on a device on 2026-11-13 (reported; step 12 FAILED and is AH-122, step 14 and the step 4 flag-OFF half were not walked: those are OPEN). Both flags were flipped ON out of order on 2026-11-12. The record is [`deploy-log.md`](deploy-log.md) (the AH-115 + AH-120 entry); deploy state is recorded there only.** Originally DRAFTED 2026-11-11. For Pedram's colleague (who runs the box), in the shape of [`deploy-ah116-ah119.md`](deploy-ah116-ah119.md). **This runbook replaces [`deploy-ah115.md`](deploy-ah115.md)**, which is marked "superseded, not used": AH-115 and AH-120 deploy together, in one sitting.
Written from the repo at **`28df3f27`** (the runtime tip; this runbook is a docs-only commit on top of it) and the AH-115 and AH-120 `PENDING` entries in [`deploy-log.md`](deploy-log.md). Procedure behind it: [`production-queue-worker.md`](production-queue-worker.md) §2a, §4, §7, §8.
CI at `28df3f27` (the code tip): [run 43120555945](https://github.com/pedram-kh/Engine/actions/runs/43120555945), **all four jobs green** (Backend 14m06s, Frontend 7m00s, E2E admin 1m12s, E2E main 8m11s), first attempt. History, for the record: run [43093612585](https://github.com/pedram-kh/Engine/actions/runs/43093612585) (`905d9aa8`) never got an E2E runner and was closed by GitHub; run [43110640162](https://github.com/pedram-kh/Engine/actions/runs/43110640162) (`bafc6c97`) failed Frontend once on a flaky admin spec ([tech-debt](../tech-debt.md) row 30), then passed all four on re-run; the spec fix is `d6d65088` (contained in `28df3f27`).

**How to use it.** Run P0 to P9 in order. Every step has its exact command, what a healthy result looks like, and a blank `Result:` line to fill. Each step says where it runs: **HOST** (`~/catalyst-engine`), **APP** (inside `docker compose exec app sh`, at `/app`), **BUILD** (the machine that builds the SPA) or **LAPTOP** / **CONSOLE** (Pedram). Anything the repo does not document is marked **CONFIRM**.
**No secret is printed by any command here**: fingerprints, counts, names and hostnames only. Never run a bare `docker compose config`, never `cat` or `echo` an env file, never paste shell history, never paste a signed URL. **Never print `.env` files.**

> **Layout assumed (from the 2026-10-12, 2026-10-29 and 2026-11-06 sittings, [`deploy-log.md`](deploy-log.md)):** the API runs in a container `app` at `/app` under supervisor (programs `laravel-queue`, `laravel-schedule`, `laravel-erasure`, `php-fpm`, `nginx`, `cron`), behind `https://platform-api.catalyst-growth.com`; queue `database`, cache Redis, mail Postmark; the database is an **Aurora PostgreSQL cluster in `eu-west-1`**; the web is a Netlify site (`platform.catalyst-growth.com`), deployed from the **Bitbucket mirror** of GitHub. **The mirror rewrites commit SHAs**, so P1.1 identifies the code by **tree hashes**. The env file path and the PHP-FPM program name were never recorded: take them from your usual procedure and write any difference in the `Result:` line.

---

## 0. What this deploy is

**PROD-DATA RISK: LOW.** Two additive migrations: one plain btree index (AH-115) and one nullable column plus one new, empty table (AH-120). **No existing row is read, rewritten, erased or backfilled.** Both flags stay **OFF**, there is no one-shot command and no backfill.

| Item                              | Value                                                                                                                                                                                                                                                                                                                                                                  |
| --------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Last deployed (from `deploy-log`) | The AH-116 to AH-119 sitting of 2026-11-06 (runbook tip **`6d7a9c28`**, reported; **current state not read**; the mirror's SHAs differ)                                                                                                                                                                                                                                |
| Deploy tip                        | **`28df3f27`** (GitHub `origin/main`). `6d7a9c28` is an ancestor (checked); 89 commits between them. The runbook's own commit follows it and changes no runtime file                                                                                                                                                                                                   |
| Migrations                        | **2**, both additive: `2026_11_09_100000_add_creator_index_to_relationship_threads` (index `idx_relationship_threads_creator`) and `2026_11_12_100000_add_brand_exclusivity_to_campaigns` (`campaigns.exclusivity_ends_on` nullable date, new table `campaign_exclusive_brands`, empty)                                                                                |
| PHP dependencies                  | **No change.** `apps/api/composer.lock` is the identical blob at `6d7a9c28` and `28df3f27` (`58ae398d`); `composer.json` has no diff. **No `composer install`.** Answered in P0.3                                                                                                                                                                                      |
| JS dependencies                   | **No change.** `pnpm-lock.yaml` is the identical blob (`94d23dc9`); no `package.json` of `apps/main`, `apps/admin` or the packages changed. `pnpm install --frozen-lockfile` is a no-op                                                                                                                                                                                |
| SPA rebuilds                      | **Main SPA only.** **Admin is unchanged**: no non-spec file under `apps/admin` differs from `6d7a9c28`. The admin Feature-flags page takes both flags' labels from the API. `packages/` changes: api-client types (`campaign.ts`, `messaging.ts`, `user.ts`), `design-tokens/native.css` (admin does not import it)                                                    |
| Env changes                       | **None.**                                                                                                                                                                                                                                                                                                                                                              |
| **Worker restart**                | **REQUIRED this time (P4.3), unlike the AH-115-only plan.** AH-120 changed classes the workers load: `JobPostedFanOutService` (constructor now takes `BrandExclusivity`; `SendJobPostedNotificationsJob` uses it on `laravel-queue`) and `ErasureRegistry` / `AuditPayloadKeys` (the `laravel-erasure` worker). **Q10 is asked immediately before the restart (P4.3)** |
| PHP-FPM reload                    | **Required** (new routes, controllers, feature classes, resource fields; opcache)                                                                                                                                                                                                                                                                                      |
| Scheduler / Supervisor            | No change                                                                                                                                                                                                                                                                                                                                                              |
| One-shot command                  | **None** (P5)                                                                                                                                                                                                                                                                                                                                                          |
| Media bucket CORS                 | **Read-back only** (P6)                                                                                                                                                                                                                                                                                                                                                |
| Flags armed in this sitting       | **None. Both read OFF (P7):** `messages_v2_creator_inbox_enabled` and `campaign_brand_exclusivity_enabled`                                                                                                                                                                                                                                                             |
| Publish                           | **Main site only**                                                                                                                                                                                                                                                                                                                                                     |
| Not in this sitting               | **Native build 5** (Pedram), **both flag flips** (Pedram, each with a reason; the inbox flag only after build 5 is live and its inbox step walked with the flag OFF), the admin SPA                                                                                                                                                                                    |

What reaches production, **with both flags OFF**:

- **AH-115:** the creator web takes the native look (dark; light derived), the Notifications page header, the bell popover at md and above and the bell opening the Notifications page below md, desktop cards for Invitations, Assignments and Job Posts (one column), the country-code picker fix, and the previous creator inbox with the new date rule. API: the creator unified inbox and search endpoints behind the OFF flag.
- **AH-120:** nothing a user can see. The campaign form, Overview, Applications tab, invite dialog, accept dialog and the creator job detail render **byte-identically** with the flag OFF (agency walk 126 / 126 and creator walk 146 / 146 against the committed baselines; the four new walk targets are additive). API: a write that sets an exclusivity list is refused (422) while OFF; nobody is blocked; `is_brand_locked` reads false; the job-posted fan-out is unchanged.

---

## 1. Rules for the sitting

1. **Stop on an `APP_KEY` change.** Fingerprint it (a hash of the line, never the value) at P0, after P1, after P4 and at the end of P8. If it ever differs: **restart nothing**, restore the value from the recoverable copy, `config:cache`, reload FPM. `composer install` is not run at all (P1.2).
2. **Q10 is asked at P0 AND again immediately before `queue:restart` (P4.3).** A claimed erasure (`users.erasure_started_at` set, `erased_at` null) is the one thing that makes a worker restart unwelcome: the restart signals `laravel-erasure` too. The check must print `0 0` (claimed accounts, queued erasure jobs). **If it is not `0 0` at P4.3: do not restart; stop and ask Pedram** (the code that needs the restart is dormant while both flags are OFF, so the sitting can wait for a later quiet minute, see P4.3). The C4 countdown is open (the earliest real due date is **2026-11-15**).
3. **Both flags stay OFF.** Nothing is flipped. P7 reads them back. Reading `Feature::active` for a flag with no stored row writes a `false` row: that is the intended default, not a change.
4. **The API goes first, then the web.** The order keeps the flag-OFF proof clean. Netlify publishes only after P2, P4 and P7 are filled (P1.4). Build early, publish late.
5. **One command at a time for anything that writes** (P2, P4.3). Read the output before the next one.
6. **Nothing is written to the media bucket in this sitting** (P6 is read-only).
7. **Do not run `Ah115Walk*` or `Ah120Walk*` seeders on production.** They create `@ah115-walk.local` / `@ah120-walk.local` users and agencies and switch flags ON. Dev only.

---

## 2. The checklist

### P0. Before (read-only except the snapshot itself)

**P0.1 A fresh snapshot: name and creation time, must be today [CONSOLE or any shell with the AWS CLI]**

Take a new one in the quiet minute just before P1 (anything written after the snapshot is not in it), then read it:

```sh
aws rds describe-db-clusters --region eu-west-1 --query 'DBClusters[].DBClusterIdentifier' --output text      # CONFIRM the cluster id; Aurora cluster, not an instance
aws rds create-db-cluster-snapshot --region eu-west-1 --db-cluster-identifier <CLUSTER_ID> --db-cluster-snapshot-identifier ah115-ah120-predeploy-<YYYYMMDD>
aws rds describe-db-cluster-snapshots --region eu-west-1 --db-cluster-snapshot-identifier ah115-ah120-predeploy-<YYYYMMDD> \
  --query 'DBClusterSnapshots[0].[DBClusterSnapshotIdentifier,SnapshotCreateTime,Status,PercentProgress]' --output text
date -u '+%Y-%m-%d %H:%M UTC'
```

(If the database is a plain RDS instance, use `describe-db-snapshots` and `--db-snapshot-identifier`.) Healthy: the name is the one you just created, **`SnapshotCreateTime` has today's UTC date**, `Status` is **`available`**, progress `100`. A snapshot still `creating` is not a safety net: **do not start P1**. A restore creates a **new** cluster and has never been rehearsed ([`production-queue-worker.md`](production-queue-worker.md) §8.2).
Snapshot name: `______________` Creation time (UTC): `______________` Status: `______________` Today's date (UTC): `______________`
Result: `______________`

**P0.2 `APP_KEY` fingerprint, and a recoverable copy [APP]**

```sh
grep '^APP_KEY=' .env | sha256sum | cut -c1-12
```

Healthy: 12 hex characters, written down; compared after P1, after P4 and at the end of P8. Confirm (without printing the key) that a current copy of the production `APP_KEY` exists outside the box: **CONFIRM**. If the env file is not `/app/.env`, fingerprint wherever the key lives and say where.
Fingerprint: `______________` Env file: `______________` Recoverable copy confirmed: yes / no
Result: `______________`

**P0.3 Did `composer.lock` or `pnpm-lock.yaml` change? [any checkout, then HOST]**

```sh
git rev-parse 6d7a9c28:apps/api/composer.lock 28df3f27:apps/api/composer.lock      # both 58ae398d21002237ef63f37bf0b5ead5fd417253
git diff --stat 6d7a9c28 28df3f27 -- apps/api/composer.json                        # empty
git rev-parse 6d7a9c28:pnpm-lock.yaml 28df3f27:pnpm-lock.yaml                      # both 94d23dc9e4b3279dac336f26d7b54b9447f705b7
git diff --stat 6d7a9c28 28df3f27 -- apps/main/package.json apps/admin/package.json  # empty
```

**Answers written at drafting time (2026-11-11, development checkout): `composer.lock` changed: NO. `pnpm-lock.yaml` changed: NO.** So P1.2 (composer) is skipped and P1.3's `pnpm install --frozen-lockfile` is a no-op. Repeat on the box so the answer is the box's own (the box's last deployed commit is the one `deploy-log.md` reports, **not read**; the mirror's SHA for it differs):

```sh
cd ~/catalyst-engine && git rev-parse HEAD && git status --short | head -5
git rev-parse HEAD:apps/api/composer.lock HEAD:pnpm-lock.yaml        # expected: 58ae398d21002237ef63f37bf0b5ead5fd417253 and 94d23dc9e4b3279dac336f26d7b54b9447f705b7
```

Healthy: a clean tree and both blobs as above. A different blob means the box is not where `deploy-log.md` says: **stop and reconcile** (then P1.2's `--no-scripts` form applies, and the pnpm install is real).
**Composer lock changed: NO** · on the box: blob `______________` · **pnpm lock changed: NO** · on the box: blob `______________` · box HEAD before: `______________`
Result: `______________`

**P0.4 `migrate:status --pending`: exactly the two migrations [APP]**

```sh
php artisan migrate:status --pending
php artisan migrate:status | grep -v Pending | tail -3
```

Healthy: **exactly these two, nothing else, and every other migration `Ran`** (the six of the 2026-11-06 sitting, through `2026_11_06_110001_add_body_search_indexes_to_messages`, must already be `Ran`):

```
2026_11_09_100000_add_creator_index_to_relationship_threads
2026_11_12_100000_add_brand_exclusivity_to_campaigns
```

A third name, either of these already `Ran`, or an older migration still pending is a stop. Paste the output (names only):

```
______________
```

Last `Ran` line and batch: `______________`
Result: `______________`

**P0.5 Q10, row counts, ownership, and the flag baseline [APP]**

```sh
# (a) Q10: must print "0 0": claimed erasures, and queued erasure jobs
php artisan tinker --execute="echo DB::table('users')->whereNotNull('erasure_started_at')->whereNull('erased_at')->count(), ' ', DB::table('jobs')->where('queue','erasure')->count(), PHP_EOL;"

# (b) row counts BEFORE: relationship_threads sizes the index build; campaigns and brands are what AH-120 touches (compared after P2)
php artisan tinker --execute="echo json_encode(['relationship_threads'=>DB::table('relationship_threads')->count(),'relationship_messages'=>DB::table('relationship_messages')->count(),'message_threads'=>DB::table('message_threads')->count(),'creators'=>DB::table('creators')->count(),'campaigns'=>DB::table('campaigns')->count(),'brands'=>DB::table('brands')->count(),'campaign_assignments'=>DB::table('campaign_assignments')->count(),'campaign_applications'=>DB::table('campaign_applications')->count()]), PHP_EOL;"

# (c) who owns the two tables the migrations alter (the migrate role must be the owner or a member of the owner role), and the absence of the new objects
php artisan tinker --execute="echo json_encode(DB::select(\"select tablename, tableowner from pg_tables where schemaname='public' and tablename in ('relationship_threads','campaigns','brands') order by tablename\")), ' ', DB::selectOne('select current_user as u')->u, PHP_EOL;"
php artisan tinker --execute="echo json_encode(['idx_creator'=>DB::select(\"select indexname from pg_indexes where schemaname='public' and indexname='idx_relationship_threads_creator'\"),'pivot'=>DB::select(\"select tablename from pg_tables where schemaname='public' and tablename='campaign_exclusive_brands'\"),'col'=>DB::select(\"select column_name from information_schema.columns where table_name='campaigns' and column_name='exclusivity_ends_on'\")]), PHP_EOL;"

# (d) every stored flag BEFORE: the baseline P7 compares with
php artisan tinker --execute="echo json_encode(DB::select('select name, scope, value, updated_at from features order by name')), PHP_EOL;"

# (e) the live main bundle BEFORE (any machine; GET only)
curl -sS https://platform.catalyst-growth.com/ | grep -o 'assets/[A-Za-z0-9._-]*\.js' | head -3
```

Healthy: (a) **`0 0`** (rule 2 if not: record it; it does not stop P1 to P3, it governs P4.3); (b) eight numbers, written down (`relationship_threads` is expected small, **not read**; P9 reads the real size); (c) the owner rows and the role (expected `engine_c_user`; **CONFIRM which role runs `migrate`** and that it owns `campaigns` and `relationship_threads`, and that it may `CREATE TABLE` in `public`), and **all three "new object" lists empty**; (d) a list: the four 2026-11-06 flags read `true` (reported armed 2026-11-08: `campaign_region_restriction_enabled`, `assignment_offer_edit_enabled`, `messages_v2_inbox_enabled`, `messages_v2_edit_search_enabled`), the push flag `true`, and **neither `messages_v2_creator_inbox_enabled` nor `campaign_brand_exclusivity_enabled` has a row yet**; (e) the asset name (it changes when the site is published).
Q10 / erasure jobs: `______` Counts: `______________` Owner / role: `______________` New objects absent: `______________` Flags before (names, values, `updated_at`): `______________` main bundle before: `______`
Result: `______________`

### P1. Code

**P1.1 Pull the mirror's copy of `28df3f27` [HOST]**

Before this step the colleague confirms **the Bitbucket mirror holds the tip** (a separate human step after the push). The mirror's SHAs differ from GitHub's, so **prove the code by tree hashes**. The GitHub values for `28df3f27` (read 2026-11-11):

```
apps      e34ca182f2a84184e6720c0d66fdee2848e86e61
packages  5b7f1c590269895e183d0132359d552af639ea9a
pnpm-lock 94d23dc9e4b3279dac336f26d7b54b9447f705b7
```

Same quiet minute as P2 (keep the gap between pull and migrate short; do not reload FPM until P4):

```sh
cd ~/catalyst-engine
OLD=$(git rev-parse HEAD); echo "OLD=$OLD"
git remote -v | head -2                                    # CONFIRM the remote is the Bitbucket mirror
git fetch origin
git log origin/main -5 --format='%h %s'                    # the top commits' SUBJECTS must include: "fix(admin-creators): debounce spec keeps the fake clock, no real-time wait; tech-debt row 30"
git merge --ff-only origin/main
git rev-parse HEAD
git rev-parse HEAD:apps HEAD:packages HEAD:pnpm-lock.yaml  # must equal the three values above (HEAD may be a docs-only descendant: docs/ is not in these trees)
```

Healthy: a fast-forward; the three tree hashes **equal** the table above. A difference means the mirror holds other content (or another tip): **stop**. Then **[APP]** `grep '^APP_KEY=' .env | sha256sum | cut -c1-12` is **identical to P0.2**.
Mirror HEAD (SHA, subject): `______________` Trees equal: yes / no Fingerprint after: `______________` Same as P0.2: yes / no
Result: `______________`

**P1.2 PHP dependencies: skipped, the lock did not change [HOST, then APP]**

P0.3 says `composer.lock` did **not** change: **do not run `composer install`**, and confirm on the box:

```sh
git diff --stat $OLD..HEAD -- apps/api/composer.lock          # HOST; expected: empty = skip
grep -n 'post-install-cmd' composer.json                       # APP; expected: no output
```

**If the lock diff is not empty** (the box was older than expected), only the safe form, which runs no hooks; **never plain `composer install`** (the 2026-08-11 incident):

```sh
composer install --no-dev --optimize-autoloader --no-scripts   # APP
php artisan package:discover --ansi                            # APP
```

Diff empty: yes / no `______` Composer ran: no / `--no-scripts` form
Result: `______________`

**P1.3 JavaScript dependencies and the MAIN build only [BUILD]**

If Netlify builds from the mirror itself, this happens there and you only read the build log; if you build and publish by hand:

```sh
pnpm install --frozen-lockfile                 # a no-op this time (P0.3): it must not change the lockfile
pnpm --filter @catalyst/main build             # = vue-tsc --noEmit && vite build (web mode). NOT build:native
ls -l apps/main/dist/.well-known/apple-app-site-association apps/main/dist/_headers      # both must exist
ls apps/main/dist/.well-known/                                                            # expected: apple-app-site-association ONLY
```

**Do not build or publish the admin site.** Confirm it is unchanged, on the box:

```sh
git diff --name-only $OLD..HEAD -- apps/admin | grep -v '\.spec\.ts$'        # expected: no output (only a test file differs)
git diff --name-only $OLD..HEAD -- packages | grep -v '\.spec\.ts$'          # expected: packages/api-client/src/types/campaign.ts, messaging.ts, user.ts and packages/design-tokens/native.css
grep -rn 'native.css' apps/admin/src apps/admin/index.html | head -3         # expected: no output (admin does not import it)
```

Healthy: install finishes without changing the lockfile, the main build exits 0 (the type-check runs first), the association file and `_headers` are present, no `assetlinks.json`; the admin diff prints nothing; the api-client type files only **add** types. If Netlify auto-builds the admin site on the mirror push, leave that deploy **unpublished** (it would be functionally identical) and say so. Do **not** run `build:native`.
Build source: Netlify builds from the mirror / built by hand on `______` Build commit: `______________` Install changed the lock: no / yes Admin diff empty: yes / no Admin built or published: no
Result: `______________`

**P1.4 Netlify: stage now, PUBLISH THE MAIN SITE ONLY, AFTER P2, P4 AND P7 ARE FILLED**

Publish `platform.catalyst-growth.com` from this tip. **Gate (rule 4): both migrations have run (P2), the API is live and the workers restarted or the restart consciously deferred (P4), and both flags read back OFF (P7).** Until then the build sits as an unpublished deploy. **`admin.catalyst-growth.com` is not published.** Healthy: Netlify shows the production deploy **Published** for the commit above, and the bundle hash differs from P0.5 (e):

```sh
curl -sS https://platform.catalyst-growth.com/ | grep -o 'assets/[A-Za-z0-9._-]*\.js' | head -3
```

Gate met at (time): `______________` `main` published: `______________` `admin` published: no New bundle: `______` (different from P0.5: yes / no)
Result: `______________`

### P2. Migrate [APP]

**Take the snapshot (P0.1) first, and confirm `available`.** Pick a quiet minute. Nothing rewrites a row.

| #   | Migration                                                     | What it does                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| --- | ------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `2026_11_09_100000_add_creator_index_to_relationship_threads` | `CREATE INDEX idx_relationship_threads_creator ON relationship_threads (creator_id)`: one plain btree, **not `CONCURRENTLY`**. The build holds a lock that **blocks writes** to `relationship_threads` (reads continue) for its duration, expected milliseconds on a small table (size read in P9)                                                                                                                                                                                                                                                    |
| 2   | `2026_11_12_100000_add_brand_exclusivity_to_campaigns`        | `ALTER TABLE campaigns ADD COLUMN exclusivity_ends_on date NULL` (nullable, no default: a **metadata-only** change on Postgres, no table rewrite, brief `ACCESS EXCLUSIVE` lock); `CREATE TABLE campaign_exclusive_brands` (`id`, `campaign_id` FK **CASCADE** to `campaigns`, `brand_id` FK **RESTRICT** to `brands`, timestamps, `unique(campaign_id, brand_id)`, `index(brand_id, campaign_id)`). **The table is created empty; no campaign row changes.** Every campaign reads "no exclusivity" until an agency sets a list, which needs the flag |

**Expected count: 2**, one new batch.

```sh
php artisan migrate --pretend --force      # reads only: prints the SQL
```

Healthy: **only** `create index "idx_relationship_threads_creator" ...`, `alter table "campaigns" add column "exclusivity_ends_on" date null ...`, `create table "campaign_exclusive_brands" ...` with its two foreign keys, the unique constraint and the index. **No `DROP`, no `UPDATE`, `DELETE` or `INSERT` of data, no other `ALTER`.** Anything else is a stop.

```sh
php artisan migrate --force                # --force is required with APP_ENV=production
php artisan migrate:status --pending       # expect: No pending migrations
```

Healthy: exactly two `DONE` lines, then `No pending migrations`. **Note both `DONE` durations (the lock times).** Then prove the objects are valid and the counts unchanged:

```sh
php artisan tinker --execute="echo json_encode(DB::select(\"select c.relname as idx, i.indisvalid as valid, i.indisready as ready from pg_index i join pg_class c on c.oid = i.indexrelid where c.relname in ('idx_relationship_threads_creator','idx_exclusive_brands_brand_campaign','unique_exclusive_brand_campaign_brand')\")), PHP_EOL;"
php artisan tinker --execute="echo json_encode(['pivot_rows'=>DB::table('campaign_exclusive_brands')->count(),'ends_on_not_null'=>DB::table('campaigns')->whereNotNull('exclusivity_ends_on')->count(),'campaigns'=>DB::table('campaigns')->count(),'brands'=>DB::table('brands')->count(),'relationship_threads'=>DB::table('relationship_threads')->count(),'creators'=>DB::table('creators')->count()]), PHP_EOL;"
grep '^APP_KEY=' .env | sha256sum | cut -c1-12                                     # identical to P0.2
```

Healthy: three index rows, each `"valid":true,"ready":true`; **`pivot_rows` is `0` and `ends_on_not_null` is `0`**; the other counts equal P0.5 (b) (plus live traffic); the fingerprint is identical to P0.2.
**If a migration fails:** each is one transaction (Postgres DDL), so nothing partial is left; read `migrate:status`, fix the cause, run `php artisan migrate --force` again. **Rollback:** `php artisan migrate:rollback --step=2 --force` drops the index, the column and the (empty) table. **Rolling back AFTER an agency has saved exclusivity lists discards those lists** (the migration's own docblock says so): snapshot first, and do not roll back once the flag has been ON, without asking Pedram.
Migrate role: `______________` DONE lines and durations (lock times): `______________` Index valid / ready (3): `______` pivot_rows / ends_on_not_null: `______` Counts: `______________` Fingerprint: `______________`
Result: `______________`

### P3. GRANT: confirm the app role can use the new table [APP]

Migration 2 creates a **new table and its id sequence**. The application role gets table-level privileges on a table only if the box's default privileges (or the migrate role being the app role) provide them; the 2026-10-29 and 2026-11-06 sittings each needed this read. Confirm, do not assume:

```sh
git diff --name-only --diff-filter=A $OLD..HEAD -- apps/api/database/migrations      # HOST; expected: exactly the two files in P0.4
php artisan tinker --execute="foreach (['SELECT','INSERT','UPDATE','DELETE'] as \$p) { if (!DB::selectOne('select has_table_privilege(current_user, ?, ?) as ok', ['public.campaign_exclusive_brands', \$p])->ok) { echo 'FALSE campaign_exclusive_brands ',\$p,PHP_EOL; } } foreach (['USAGE','SELECT','UPDATE'] as \$p) { if (!DB::selectOne('select has_sequence_privilege(current_user, ?, ?) as ok', ['public.campaign_exclusive_brands_id_seq', \$p])->ok) { echo 'FALSE campaign_exclusive_brands_id_seq ',\$p,PHP_EOL; } } foreach (['SELECT','INSERT','UPDATE'] as \$p) { if (!DB::selectOne('select has_table_privilege(current_user, ?, ?) as ok', ['public.relationship_threads', \$p])->ok) { echo 'FALSE relationship_threads ',\$p,PHP_EOL; } } echo 'grants read', PHP_EOL;"
```

Healthy: two new migration files; only `grants read` is printed. A `FALSE` line means the app role cannot use the object: **the AH-120 write paths would 500 once the flag is ON** (with the flag OFF nothing writes the table, so this does not break the deploy). Fix as the table owner, then read again: `GRANT SELECT, INSERT, UPDATE, DELETE ON campaign_exclusive_brands TO <APP_ROLE>; GRANT USAGE, SELECT ON SEQUENCE campaign_exclusive_brands_id_seq TO <APP_ROLE>;` (**CONFIRM** the app role's name; `engine_c_user` is expected). Record it and tell Pedram.
New migration files: `______` Grants read: `______________`
Result: `______________`

### P4. Infra [APP, supervisor in the container]

**P4.1 Cache the config, and the route cache if the box uses it**

```sh
php artisan config:cache
grep '^APP_KEY=' .env | sha256sum | cut -c1-12                                     # identical to P0.2
ls bootstrap/cache/                                                                 # routes-v7.php present? (CONFIRM whether this box caches routes)
```

Healthy: `config:cache` succeeds. **If `bootstrap/cache/routes-v7.php` exists**, run `php artisan route:cache`: this release adds the creator inbox and search routes (AH-115) and `POST agencies/{agency}/campaigns/{campaign}/exclusivity-conflicts` (AH-120), and a stale route cache answers 404 for them.
Output: `______________` Route cache present: yes / no / `route:cache` run: yes / no
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

**P4.3 `queue:restart`: NEEDED. Q10 first, immediately before, in the same minute**

Why a restart is needed this time: a `queue:work` process keeps the classes it already loaded in memory. AH-120 changed two things those workers run:

- `JobPostedFanOutService` takes a new constructor argument (`BrandExclusivity`) and `SendJobPostedNotificationsJob` (queue `database`, program `laravel-queue`) builds it. A worker started before the deploy holds the **old** class, which does not apply the exclusion (harmless while the flag is OFF) and, depending on autoload order, can mix old and new files. Restarting removes the doubt.
- `ErasureRegistry` and `AuditPayloadKeys` (the `laravel-erasure` worker) gained the rule for the new table (`campaign_exclusive_brands`: `NotApplicable`, no creator data). An erasure run must see the registry that knows the table, or the registry-coverage guarantee is stale in the running process.

Confirm the file list on the box, then ask Q10, then restart. **Run the three in order, each one read before the next:**

```sh
git diff --name-only $OLD..HEAD -- apps/api/app | grep -E 'Jobs/|Mail/|Notifications/|Listeners/|Console/|Erasure|JobPostedFanOut'   # expected: ErasureRegistry.php, AuditPayloadKeys.php, JobPostedFanOutService.php (and nothing surprising)
supervisorctl status laravel-queue laravel-erasure                                                                                      # RUNNING; write the uptimes
php artisan tinker --execute="echo DB::table('users')->whereNotNull('erasure_started_at')->whereNull('erased_at')->count(), ' ', DB::table('jobs')->where('queue','erasure')->count(), PHP_EOL;"   # Q10, must print: 0 0
```

**Only if Q10 printed `0 0`:**

```sh
php artisan queue:restart                                                                                                               # signals every queue:work to exit after its current job; supervisor starts new ones
sleep 15; supervisorctl status laravel-queue laravel-erasure laravel-schedule                                                           # RUNNING, uptimes now short (the workers restarted); laravel-schedule untouched
```

**If Q10 printed anything else: do NOT run `queue:restart`.** A claimed account may be mid-run on `laravel-erasure`, and the restart signals it. Record the output, stop, tell Pedram. This is safe to defer: with both flags OFF the new code paths are dormant (nothing blocks anyone, nothing writes the new table), so the deploy can finish P4 to P9 on the old workers; **the restart is then a hard prerequisite for flipping `campaign_brand_exclusivity_enabled`** (rule: do it at the next moment Q10 reads `0 0`, and record it in `deploy-log.md`).
Files listed: `______________` Uptimes before: `laravel-queue` `______` `laravel-erasure` `______` Q10 at the restart: `______` Restarted: yes / deferred (why) `______` Uptimes after: `______________`
Result: `______________`

**P4.4 Supervisor status [APP]**

```sh
supervisorctl status
```

Healthy: `cron`, `laravel-erasure`, `laravel-queue`, `laravel-schedule`, `nginx`, `php-fpm` all `RUNNING`, none missing, none crash-looping. No program is added by this release.
`supervisorctl status` (paste all lines): `______________`
Result: `______________`

### P5. One-shots: none [APP]

AH-115 and AH-120 have **no one-shot command, backfill or seeder to run**. Confirm it from the repo on the box:

```sh
git diff --name-only $OLD..HEAD -- apps/api/app/Console apps/api/database/seeders | head -20
```

Healthy: the only files listed are the dev walk seeders **`Ah115WalkSeeder.php`, `Ah115WalkCleanupSeeder.php`, `Ah120WalkSeeder.php`, `Ah120WalkCleanupSeeder.php`** (they exist in `database/seeders` and are **never run on production**: they create `@ah115-walk.local` / `@ah120-walk.local` users and agencies and switch flags on; both are also guarded to `local` and `testing` and throw anywhere else), and no `Console/` file. **No row is written in this step.**
Files listed: `______________` Ran any one-shot: no
Result: `______________`

### P6. Media bucket CORS: read-back only, both rules present [CONSOLE or any shell with the AWS CLI]

**Nothing is written in this sitting.** AH-115 and AH-120 add no CORS need; the read matters because build 5 will be walked on devices that upload photos with a browser `PUT` from `capacitor://localhost` / `https://localhost`.

```sh
BUCKET=<MEDIA_BUCKET>            # APP: php artisan tinker --execute="echo config('filesystems.disks.media.bucket'), PHP_EOL;"
aws s3api get-bucket-cors --bucket "$BUCKET" | jq -c '.CORSRules[] | {AllowedOrigins, AllowedMethods}'      # read only; origins and methods only
```

Healthy: **rule A** lists `https://platform.catalyst-growth.com` and `https://admin.catalyst-growth.com` with `GET` (and `HEAD`), and **rule B** lists `capacitor://localhost` and `https://localhost` with `PUT`. Then the read-only preflights (no signature, nothing written, no secret printed):

```sh
REGION=<REAL_REGION>            # the bucket's own region (P9.1)
for O in capacitor://localhost https://localhost https://platform.catalyst-growth.com https://evil.example; do
  echo "== $O"; curl -s -o /dev/null -D - -X OPTIONS \
    -H "Origin: $O" -H "Access-Control-Request-Method: PUT" -H "Access-Control-Request-Headers: content-type" \
    "https://$BUCKET.s3.$REGION.amazonaws.com/relationship-messages/cors-probe" | grep -i -E '^(HTTP|access-control-allow-(origin|methods|headers))'
done
```

Healthy: the two native origins and the platform origin answer **`HTTP/… 200`** with `access-control-allow-origin` equal to the origin sent and `PUT` allowed; **`https://evil.example` answers 403** with no allow-origin line.
**If a rule is missing: do not write it here.** Record it as **NOT PRESENT**, and tell Pedram: it is the open item from the AH-116 to AH-119 sitting ([`deploy-ah116-ah119.md`](deploy-ah116-ah119.md) P6 has the exact rules and the merge command). It does not block this deploy; it blocks the build 5 photo-upload walk.
Rule A present: yes / no Rule B present: yes / no Preflights (capacitor / https://localhost / platform / evil): `______` / `______` / `______` / `______` Bucket region: `______`
Result: `______________`

### P7. Flags: both read OFF; change nothing [APP]

```sh
php artisan tinker --execute="var_dump(Laravel\Pennant\Feature::active('messages_v2_creator_inbox_enabled'), Laravel\Pennant\Feature::active('campaign_brand_exclusivity_enabled'));"
php artisan tinker --execute="echo json_encode(DB::select(\"select name, scope, value, updated_at from features where name in ('messages_v2_creator_inbox_enabled','campaign_brand_exclusivity_enabled') order by name\")), PHP_EOL;"
php artisan tinker --execute="echo json_encode(DB::select('select name, scope, value, updated_at from features order by name')), PHP_EOL;"
```

Healthy: **`bool(false)` twice**; the two stored rows (reading `Feature::active` created them if they did not exist) read `"false"`; the full list equals P0.5 (d) **plus those two rows and nothing else changed** (every other flag keeps its value and `updated_at`: the four 2026-11-06 flags stay as read at P0, the push flag stays `true`). The admin Feature-flags page lists both ("Messages v2: creator inbox" and the brand-exclusivity flag), off.
**If either is ON, switch it OFF in the admin Feature-flags page and say so**: nothing is armed in this sitting. The flips come later, by Pedram, each with a reason.
Flags values: `______________` Others unchanged (names, values, `updated_at`): yes / no
Result: `______________`

### P8. Verify

**P8.1 `/up`, the key, the 404s with the flags OFF [APP, any machine]**

```sh
curl -s -o /dev/null -w '%{http_code}\n' https://platform-api.catalyst-growth.com/up        # expect 200
grep '^APP_KEY=' .env | sha256sum | cut -c1-12                                              # APP; identical to P0.2
php artisan migrate:status --pending                                                        # APP; No pending migrations
php artisan route:list --path=message-inbox --json | jq -r '.[].uri' | head -3              # APP: the creator route exists in the code
php artisan route:list --path=exclusivity-conflicts --json | jq -r '.[].uri' | head -3      # APP: the AH-120 read exists in the code
```

`/up`: `______` Fingerprint: `______________` Same as P0.2: yes / no Pending: `______` Routes listed: `______`
Result: `______________`

**P8.2 Sign-ins and `/me` [browser]**

Sign in with your own accounts (no password goes in this file): **a creator** and **an agency user** on `https://platform.catalyst-growth.com`. Healthy: each reaches its dashboard. In the creator session read `/me` in the network tab: **`features.messages_v2_creator_inbox` is `false`** and **`features.campaign_brand_exclusivity` is `false`**; the agency's `features.messages_v2_inbox` stays as it was at P0 (reported `true`) and its `features.campaign_brand_exclusivity` is `false`.
Creator: `______` Agency: `______` `/me` creator flags false: `______` agency exclusivity flag false: `______`
Result: `______________`

**P8.3 Creator web restyle [browser, creator, dark and light]**

At **1280** and at **390** wide: the dashboard, Availability, Job Posts, Invitations, Profile and the Notifications page take the native look (dark; light derived): bottom navigation below md, top navigation from md, compact header, native cards and fields; the Notifications page has its header. At **960 and wider**, Invitations, Assignments and Job Posts show **one column of full-width cards, content left-aligned, the actions on one line aligned right**; below 960 the phone layout. In Profile and onboarding **Contact details**, the phone and WhatsApp country-code picker shows **one flag and one code per row, no overlap**. Spot-check Inter renders on menus and dialogs.
Creator pages: `______` Desktop cards (1280): `______` Phone (390): `______` Country-code picker: `______`
Result: `______________`

**P8.4 Agency unchanged, with the AH-120 pages in focus [browser, agency]**

Open the agency Dashboard, Campaigns, Messages, Brands and Creators in dark and light. Healthy: **no visual change** against what you saw before the deploy (agency walk 126 / 126). **Then, with the flag OFF, open a campaign's Overview, Edit and Applications tab, and the Invite dialog:** there is **no** "blocked brands" row, **no** exclusivity controls in the form, **no** Conflict badge and **no** invite warning. Anything that looks different is a stop.
Agency pages: `______` Campaign Overview / Edit / Applications / Invite show nothing new: `______`
Result: `______________`

**P8.5 Creator inbox is still the OLD one while the flag is OFF [browser, creator, then network tab]**

Open **Messages** as the creator. Healthy: **the previous list of agency conversations renders**: no "Direct" or "Brand · Campaign" tag, no search field, no All | Unread control, no Direct chip; a thread opens. In the network tab, `GET .../creators/me/message-inbox` (and the search route) answers **404** while the flag is OFF; the old creator inbox request is unchanged. The new date rule shows in the list ("time / Nov 9 / Nov 9, 2025").
Old inbox: `______` 404 seen: `______` Dates: `______`
Result: `______________`

**P8.6 The bell: popover at 1280, the page at 390 [browser, creator]**

At **1280**: the top-bar bell opens a **popover** (restyled, one scroll area, rows open their target). At **390** (below md): the bell opens the **Notifications page** directly, no popover. Check the unread badge still shows on the bell in both. Agency bell unchanged.
Popover (1280): `______` Page (390): `______` Badge: `______`
Result: `______________`

**P8.7 Brand exclusivity is inert with the flag OFF [browser, creator, then network tab]**

As a creator, open any job on the Job Posts board and its detail: **Apply is offered as before; no "Not available to you" alert**; in the network tab the detail payload has `is_brand_locked: false` and `brand_lock_reason: null`. An apply behaves as before (do not apply to a real job just to test; the read is enough).
Detail payload `is_brand_locked` false: `______` No locked alert: `______`
Result: `______________`

**P8.8 Row counts and the key at the end [APP]**

Repeat the P2 counts command. Healthy: **`pivot_rows` is still `0` and `ends_on_not_null` is still `0`** (nothing wrote the new objects: the flag is OFF); the other counts equal P0.5 (b) (plus live traffic); the fingerprint equals P0.2.
Counts: `______________` Fingerprint at the end: `______________` Same as P0.2: yes / no
Result: `______________`

### P9. Read-only: production size and the creator inbox plan [APP; read-only]

**Nothing here writes.** It answers the question the pre-deploy entry could not (the EXPLAIN in the plan ran on 300 seeded rows): how big `relationship_threads` is in production, and whether the creator inbox query uses the new index. Run it **after P2** (so the index exists) and **before the inbox flag flip**.

**P9.1 Row count and size**

```sh
php artisan tinker --execute="echo json_encode(DB::select(\"select (select count(*) from relationship_threads) as threads, (select count(*) from relationship_messages) as rel_messages, (select count(*) from messages) as messages, (select count(*) from creators) as creators, (select count(*) from campaigns) as campaigns, pg_size_pretty(pg_total_relation_size('relationship_threads')) as threads_size\")), PHP_EOL;"
```

Healthy: numbers, written down; `relationship_threads` expected small. Record the size.
Counts and size: `______________`
Result: `______________`

**P9.2 EXPLAIN of the creator inbox query (the busiest creator; plain `EXPLAIN`, which does not run the query)**

```sh
cat > /tmp/explain-creator-inbox.php <<'EOF'
$cid = DB::table('relationship_threads')->select('creator_id')->groupBy('creator_id')->orderByRaw('count(*) desc')->value('creator_id') ?? DB::table('creators')->value('id');
$creator = App\Modules\Creators\Models\Creator::query()->find($cid);
$viewer = App\Modules\Identity\Models\User::query()->find($creator->user_id);
$m = new ReflectionMethod(App\Modules\Messaging\Services\CreatorMessageInboxQuery::class, 'threads');
$m->setAccessible(true);
$b = $m->invoke(new App\Modules\Messaging\Services\CreatorMessageInboxQuery(), $creator, $viewer)->select('inbox.*')->orderByDesc('last_at')->orderByDesc('type')->orderByDesc('id')->limit(31);
foreach (DB::select('EXPLAIN '.$b->toSql(), $b->getBindings()) as $r) { echo array_values((array) $r)[0], PHP_EOL; }
EOF
php artisan tinker --execute="$(cat /tmp/explain-creator-inbox.php)"
rm -f /tmp/explain-creator-inbox.php
```

(The script reads one creator's id and user id to build the real query; it prints only plan lines, no value from a row. Each plan line may carry that creator's numeric id in a filter, which is not personal data on its own; **do not paste the ids into the log**, replace them with `<id>`.)
Healthy: the `relationship_threads` leg shows an **Index Scan or Bitmap Index Scan on `idx_relationship_threads_creator`** (`Index Cond: (creator_id = <id>)`), **not** a `Seq Scan on relationship_threads` filtered by `creator_id`. A `Seq Scan` on a table this small is acceptable to the planner and is not a fault by itself: if one shows, record the row count from P9.1 and the plan, and tell Pedram before the inbox flag flip. Do **not** run `EXPLAIN ANALYZE` on production unless Pedram asks (it executes the query).
Plan uses `idx_relationship_threads_creator`: yes / no `______` Seq Scans seen on (table names only): `______________` Plan top-line cost: `______`
Result: `______________`

### Record it [deploy-log.md]

Fill the **AH-115 + AH-120** `PENDING` entry: the snapshot name and creation time, the two migrations and their `DONE` durations (the lock times), the index validity and the empty pivot, the grants read (P3), the supervisor lines (P4.4), **the Q10 reading at the restart and whether `queue:restart` ran or was deferred (P4.3)**, the CORS read-back (P6), the flag read-backs (P7), the fingerprints, the operator, **both SHAs** (mirror and GitHub) with the tree-hash check, the P9 counts and plan, and **anything unexpected**. Set the **Range** to what was actually deployed and flip the status to `DEPLOYED` with today's date **for steps 1 to 4 only**; the build 5 walk and both flag flips stay open in the entry.
Record deploy state there only. Mark what stayed **not read**; never write "passed" for a step that was not run.
Done: yes / no

---

## 3. Not in this sitting (named, so nothing is assumed done)

- **Native build 5** (`CURRENT_PROJECT_VERSION` 5 already set in the repo): by Pedram. Archive, read the entitlements, upload, install over build 4; then the build 5 device walk (`docs/reviews/creator-restyle-review.md`, steps 1 to 13, plus the AH-120 step 14: a brand-locked job shows "Not available to you" and no Apply, on the device). **Build 4 is safe with either flag on or off.**
- **The two flag flips**, by Pedram in the admin Feature-flags page, each with a reason, each read back on the page, not assumed:
  - `messages_v2_creator_inbox_enabled` ON **only after build 5 is live and its inbox step walked with the flag OFF**. Turning it OFF rolls the inbox back (it writes nothing of its own).
  - `campaign_brand_exclusivity_enabled` ON **only after (a) the worker restart of P4.3 has happened (Q10 `0 0`), and (b) the P3 grant read printed no `FALSE`**. Turning it OFF returns every surface to the pre-AH-120 behaviour; **the saved exclusivity lists stay in the table**, and they apply again the moment the flag is turned back ON.
- **The admin site**: not rebuilt, not published.
- **The bucket CORS write** (if P6 finds a rule missing): the AH-116 to AH-119 runbook's P6.
- **No worker restart while an erasure is claimed** (the earliest real due date is 2026-11-15; [`account-erasure.md`](account-erasure.md)). Q10 is the gate, twice (P0.5 and P4.3).

---

## 4. If something goes wrong

**First rule: stop, record the state, change nothing else.** Then:

| Symptom                                      | Do                                                                                                                                                                                                                                                                                                                     |
| -------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **`APP_KEY` fingerprint differs** (any step) | **Restart nothing.** Restore the value from the recoverable copy (P0.2), `php artisan config:cache`, reload FPM. Do not run `composer install`                                                                                                                                                                         |
| Tree hashes in P1.1 differ from the table    | The mirror holds other content. **Stop before migrating**; ask Pedram. Do not pull "close enough"                                                                                                                                                                                                                      |
| A migration fails or an index is invalid     | Read the error and `migrate:status`. Each migration is one transaction. Drop an invalid `idx_relationship_threads_creator` as the table owner, then run `php artisan migrate --force` again                                                                                                                            |
| Message sends or campaign writes hang in P2  | The index build blocks writes to `relationship_threads`; the `ALTER` takes a brief lock on `campaigns`. Both end with the migration. Do not kill the session mid-way. If clearly stuck, `pg_terminate_backend` the migration's backend as the owner (the transaction rolls back) and re-run at a quieter minute        |
| 404s on creator or agency pages after P4     | A stale route cache: `php artisan route:cache` (or `route:clear` if the box does not cache routes), reload FPM                                                                                                                                                                                                         |
| Q10 is not `0 0` at P4.3                     | **Do not `queue:restart`.** Record it, finish P4.4 to P9 on the old workers (the new code is dormant, both flags OFF), tell Pedram. The restart is a prerequisite for the exclusivity flag flip, not for this deploy                                                                                                   |
| Code problem after the API deploy            | **Roll the code back, leave the schema** (old code ignores the column, the empty table and the index): `git checkout <OLD>` on the host, `config:cache`, reload FPM, then `php artisan queue:restart` **only after Q10 reads `0 0`** (the workers hold the new classes otherwise)                                      |
| Code or look problem after the web publish   | Redeploy the previous `dist` in Netlify (main only). The API changes are additive and harmless to the old web                                                                                                                                                                                                          |
| Roll the schema back                         | `php artisan migrate:rollback --step=2 --force` drops the index, the column and the table. **Safe while the flag has never been ON** (the table is empty). **If lists were saved, they are discarded:** snapshot first and ask Pedram                                                                                  |
| A flag reads ON                              | Switch it OFF in the admin Feature-flags page and say so (P7)                                                                                                                                                                                                                                                          |
| **Data problem** (not just schema)           | **Restore from the P0.1 snapshot.** A restore creates a **new cluster**; it does not roll the live one back in place. **CONFIRM** how the app is re-pointed. Never rehearsed; everything written after the snapshot is lost. Prefer fix-forward (no existing row is changed by this release, so this should not arise) |

**Kill switches in this deploy:** `messages_v2_creator_inbox_enabled` (OFF) and `campaign_brand_exclusivity_enabled` (OFF). **No kill switch exists** for the creator restyle, the Notifications page header, the bell behaviour, the desktop cards or the country-code picker: they ship live with the web build (rollback is the previous `dist`).

---

_Provenance: drafted by Cursor on 2026-11-11 from `docs/runbooks/deploy-log.md` (the AH-115 and AH-120 `PENDING` entries), [`deploy-ah115.md`](deploy-ah115.md) (the AH-115 steps carried over, P9.2 EXPLAIN included, which was run against the local development database only), [`deploy-ah116-ah119.md`](deploy-ah116-ah119.md), the two migration files, `git diff 6d7a9c28 28df3f27` (lock files, package files, migrations, `apps/admin`, `packages`, `apps/api/app`, console and seeders) and the tree hashes quoted in P1.1. The AH-120 migration, both new walk seeders and the flag-OFF and flag-ON behaviour were exercised on the local development database and stack (walk 2026-11-11, passed). **No command has been run against production.** **Still CONFIRM, because the repo cannot say:** the Aurora cluster identifier, the migrate role and whether it owns the altered tables and may create tables, the app role's name and its default privileges on new tables (P3), the env file path, the PHP-FPM program name, whether the box caches routes, how the Netlify site is deployed from the mirror, the bucket's CORS rules (read in P6), and the production size of `relationship_threads` (P9.1)._
