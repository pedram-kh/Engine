# Deploy runbook: AH-121 + AH-122 (Sentry error reporting on the API and both web sites; the country-code picker fix), one sitting

**Status: DRAFTED 2026-11-15. NOT RUN. Nothing here has been run against production, and nothing has been pushed.** For Pedram's colleague (who runs the box and the Netlify sites), in the shape of [`deploy-ah115-ah120.md`](deploy-ah115-ah120.md). The record of what actually happened goes in [`deploy-log.md`](deploy-log.md) (the AH-121 entry; AH-122 rides the same web deploy); deploy state is recorded there only.
Written from the repo at the code tip **`77797fcd`** (the docs commits after it, this runbook's included, change no file under `apps/`, `packages/` or `pnpm-lock.yaml`). **CI at the pushed tip: not read** (nothing is pushed; fill the run URL here at push time: `______________`). **Restamp the tip, the tree hashes and the CI run in P1.1 if anything lands on `main` before this is run.** Plan, rulings and evidence: [`../reviews/sentry-plan.md`](../reviews/sentry-plan.md) (R, B, D). Procedure behind it: [`production-queue-worker.md`](production-queue-worker.md) §2a, §4, §8.

**How to use it.** Run P0 to P9 in order. Every step has its exact command, what a healthy result looks like, and a blank `Result:` line to fill. Each step says where it runs: **HOST** (`~/catalyst-engine`), **APP** (inside `docker compose exec app sh`, at `/app`), **NETLIFY** (the colleague's site settings), **BROWSER**, or **PEDRAM**. Anything the repo does not document is marked **CONFIRM**.
**No secret is printed by any command here, and a DSN is a secret for this purpose.** Never `cat` or `echo` an env file, never print the DSN or the token, never paste either into chat, a ticket, a screenshot or a terminal log. Fingerprints, counts, `set` / `empty` and names only. Pedram gives the values to the colleague **privately**; the DSN is typed into the env file with an editor, never with `echo`. **Never print `.env` files.**

> **Layout assumed (from the earlier sittings, [`deploy-log.md`](deploy-log.md)):** the API runs in a container `app` at `/app` under supervisor (programs `laravel-queue`, `laravel-schedule`, `laravel-erasure`, `php-fpm`, `nginx`, `cron`), behind `https://platform-api.catalyst-growth.com`; `/app` is a bind mount of the git checkout (reported); the web sites are Netlify sites (`platform.catalyst-growth.com`, main; `admin.catalyst-growth.com`, admin) built from the **Bitbucket mirror with git history** (reported). **The mirror rewrites commit SHAs**, so P1.1 identifies the code by **tree hashes**. The env file path and the PHP-FPM program name were never recorded: take them from your usual procedure and write any difference in the `Result:` line.

---

## 0. What this deploy is

**PROD-DATA RISK: NONE for rows.** No migration, no backfill, no one-shot command, no data write, no erasure code path changed. **The real risk is a new data egress:** exception text and request context leaving our boxes for a third party (Sentry, EU). It is bounded by the scrubber on each surface, by "the account ulid only, never email, name, IP or token", and by the empty-DSN-sends-nothing switch. The repo privacy texts are merged (C4); the **public web notice is Catalyst's**: text sent to Jordan **not yet**, publication **not read**. It does **not** block the API and web DSNs; it **does** block App Store submission of build 6. **Engineering view, not legal advice.**

| Item                              | Value                                                                                                                                                                                                                                                                                                                                                                               |
| --------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Last deployed (from `deploy-log`) | The AH-115 + AH-120 sitting of 2026-11-12, deploy tip **`28df3f27`** (reported; **current state not read**)                                                                                                                                                                                                                                                                         |
| Deploy tip                        | **`77797fcd`** (GitHub). 21 commits after `28df3f27`: the Sentry plan, C1 (API), C2 (SPAs), C3 (native; **inert on the web and the API**), the malformed-DSN test, the CI test fix `695d3cb1` (test only), the API trial fix `3c4a6133`, the breadcrumb fix `77797fcd` (SPAs and native; not yet deployed), docs, and **AH-122** (`911e0b75`, main web only)                        |
| Migrations                        | **None.** `git diff --name-only 28df3f27 77797fcd -- apps/api/database` is empty (checked at drafting). **Q10 applies anyway** (P4.3): the workers must load the new vendor classes                                                                                                                                                                                                 |
| PHP dependencies                  | **CHANGED.** `apps/api/composer.lock` blob is now **`2fb4ae4761b4ff2511c9f1bbc3cd21d177428194`** (was `58ae398d21002237ef63f37bf0b5ead5fd417253`). Five packages added (`sentry/sentry-laravel` 4.30.0, `sentry/sentry` 4.37.0, `nyholm/psr7` 1.8.2, `symfony/options-resolver`, `symfony/psr-http-message-bridge`). **Safe form only (P1.2): never plain `composer install`**      |
| JS dependencies                   | **CHANGED.** `pnpm-lock.yaml` blob **`5c469f97d0d3568587be59f5d944876dc1a80736`** (was `94d23dc9e4b3279dac336f26d7b54b9447f705b7`): `@sentry/vue` 10.76.2, `@sentry/vite-plugin` 5.4.1 (both SPAs), `@sentry/capacitor` 4.5.0 (main; native only, a dynamic import, not in the web entry chunk). `@sentry/cli` is allowed to run its install script (root `package.json`)           |
| SPA rebuilds                      | **Both.** Main (Sentry + AH-122) and **admin (Sentry; the AH-115/AH-120 deploy published main only)**                                                                                                                                                                                                                                                                               |
| Env changes                       | **Yes, on three surfaces** (the table in P3, who sets each). A DSN left empty is the off state                                                                                                                                                                                                                                                                                      |
| **Worker restart**                | **REQUIRED (P4.3).** The workers load the new vendor classes and the exception handler wiring (`bootstrap/app.php`, `AppServiceProvider`, `config/sentry.php`). **Q10 must print `0 0` immediately before it.** The first real erasure is due **2026-11-15** (the date at drafting; the C4 countdown): check [`account-erasure.md`](account-erasure.md) §1e before choosing the day |
| PHP-FPM reload                    | **Required**                                                                                                                                                                                                                                                                                                                                                                        |
| Scheduler / Supervisor            | No change                                                                                                                                                                                                                                                                                                                                                                           |
| One-shot command                  | **None** (P5)                                                                                                                                                                                                                                                                                                                                                                       |
| Flags armed in this sitting       | **None.** No flag is added or changed                                                                                                                                                                                                                                                                                                                                               |
| Snapshot                          | **Not needed, and why:** no migration, no data command and no row is written or rewritten by this release (no `database/` file differs, P2), so there is no database state to roll back to. Rollback is the previous code (§4). The Q10 gate, not a snapshot, is what protects the erasure worker                                                                                   |
| Not in this sitting               | **Native build 6** (Pedram; waits for the public web notice), the **uptime monitors** and **alert rules** (Pedram, after a week of volume), **dSYM and native source-map upload** (tech-debt row 34), **Android** (never built, row 33)                                                                                                                                             |

What reaches production:

- **AH-121 API:** exceptions reach Sentry (project `catalyst-api`) through the scrubber, once `SENTRY_LARAVEL_DSN` is set. With it empty the SDK has no transport and sends nothing. Ignored on purpose: validation, authentication, authorisation, not-found, CSRF-mismatch and throttling exceptions, and any HTTP status below 500.
- **AH-121 web:** unhandled errors in each SPA reach `catalyst-main` / `catalyst-admin` through the SPA scrubber, once the DSN is baked into that build. **No session replay, no session tracking, no tracing, no feedback widget, no console or DOM breadcrumbs.** Source maps are built hidden, uploaded to the release and deleted, only when the token is set.
- **AH-122 (main web only):** the country-code picker selects by country, not by shared dial code (+1 showed several selected rows). No API change.
- **What an event contains:** the account ulid (the only user field) and a `user_type` tag; error text with personal details removed; device or browser, OS and release (the phone also the device's language and region setting); no email, name, IP, location, time zone, cookies, tokens, session tracking or replay. Evidence: sentry-plan.md D2, D7.

---

## 1. Rules for the sitting

1. **Stop on an `APP_KEY` change.** Fingerprint it (a hash of the line, never the value) at P0, after P1, after P4 and at the end of P8. If it ever differs: **restart nothing**, restore the value from the recoverable copy, `config:cache`, reload FPM. **Plain `composer install` is never run** (the 2026-08-11 `APP_KEY` incident): P1.2 is the only form allowed.
2. **Q10 is asked at P0 AND again immediately before `queue:restart` (P4.3), and it must print `0 0`.** A claimed erasure (`users.erasure_started_at` set, `erased_at` null) is the one thing that makes a worker restart unwelcome: the restart signals `laravel-erasure`. **If it is not `0 0` at P4.3: do not restart; stop and ask Pedram.** The Sentry DSN then is **not live for the workers** (php-fpm reports on its own after its reload); the restart is done at the next moment Q10 reads `0 0`, and recorded in `deploy-log.md`. **The API deploy and the worker restart must not run while an erasure is claimed.**
3. **No DSN, token or env value is ever printed or pasted.** See the banner. A step that needs to know whether a value is set uses `set` / `empty` or a count.
4. **The API goes first, then the web.** Netlify publishes only after P4 and P6 are filled (P1.4, P7). Build early, publish late. Web DSNs are baked in at build time, so the Netlify env (P3) is set **before** the build, and the unpublished build is checked (P1.4) before it goes live.
5. **One command at a time for anything that writes** (P3, P4). Read the output before the next one.
6. **Any failed raw-JSON check turns that surface off first (§4), then the cause is fixed.** A failed check never waits for the next day.
7. **Do not run the `Ah115Walk*` or `Ah120Walk*` seeders on production** (dev only).
8. **`php artisan sentry:test` is not the test.** It builds its own client with its own options and **bypasses the scrubber and `config/sentry.php`** (`vendor/sentry/sentry-laravel/.../TestCommand.php:76-164`, read), so a good-looking event from it proves nothing about what the app sends. P6 uses `report()` through the configured client.

---

## 2. The checklist

### P0. Before (read-only)

**P0.1 The two gates [PEDRAM, reported]**

- **Gate 0, the DPA:** accepted by Pedram, **reported 2026-11-13** ([`../compliance/dpa-registry.md`](../compliance/dpa-registry.md)). Closed. Nothing was read from Sentry.
- **Gate 1, the repo docs (C4):** merged on `main` (the privacy answers, the in-app section and the web-notice text, `review-notes.md`, the erasure runbook, the PII-map addendum, tech-debt). The **public web notice** is a separate, later gate: **sent to Jordan: not yet; publication: not read**. It does not block this deploy.
- **Read before the first real event, by Pedram (write each down; none was read at drafting):** the **retention figure** in the Sentry org settings (the pricing page says 30 days on the free plan, reported), Enhanced Privacy, Allow Shared Issues, Global Sensitive Fields, each project's IP-address setting, and that the org's Advanced Data Scrubbing rule (Remove, Anything, `$user.geo.**`) is still there. The 14-day trial has no paid plan chosen; what the free plan keeps afterwards is **not read**.

Retention read: `______` Enhanced Privacy: `______` Allow Shared Issues: `______` Global Sensitive Fields: `______` Geo rule present: yes / no
Result: `______________`

**P0.2 No snapshot: confirm there is nothing for one to protect [any checkout]**

```sh
git diff --name-only 28df3f27 77797fcd -- apps/api/database apps/api/app/Console | head -5     # expected: no output (no migration, seeder or command)
```

Healthy: no output. Anything listed means this runbook is stale: **stop** and ask Pedram (a migration brings back P0.1 snapshot, P2 and P3 of the previous runbook).
Output empty: yes / no
Result: `______________`

**P0.3 `APP_KEY` fingerprint, and a recoverable copy [APP]**

```sh
grep '^APP_KEY=' .env | sha256sum | cut -c1-12
```

Healthy: 12 hex characters, written down; compared after P1, after P4 and at the end of P8. Confirm (without printing the key) that a current copy of the production `APP_KEY` exists outside the box: **CONFIRM**. If the env file is not `/app/.env`, fingerprint wherever the key lives and say where.
Fingerprint: `______________` Env file: `______________` Recoverable copy confirmed: yes / no
Result: `______________`

**P0.4 The lock files changed, so read the box's Composer first [HOST, then APP]**

```sh
cd ~/catalyst-engine && git rev-parse HEAD && git status --short | head -5
git rev-parse HEAD:apps/api/composer.lock HEAD:pnpm-lock.yaml      # before the pull: expected 58ae398d21002237ef63f37bf0b5ead5fd417253 and 94d23dc9e4b3279dac336f26d7b54b9447f705b7
composer --version                                                   # APP
grep -n 'post-install-cmd' composer.json                             # APP; expected: no output
grep -n 'key:generate' composer.json                                 # APP; expected: only under post-create-project-cmd
```

Healthy: a clean tree, both blobs as above (a different blob means the box is not where `deploy-log.md` says: **stop and reconcile**), a Composer version written down, **no `post-install-cmd`**, and `key:generate` only under `post-create-project-cmd`. **The lock was written by Composer 2.11.1** (`plugin-api-version` moved from `2.6.0` to `2.11.0`); whether an older Composer on the box installs from it is **not read**, which is what P1.2's dry run answers before anything is changed.
Box HEAD before: `______________` Composer version: `______________` `post-install-cmd` absent: yes / no
Result: `______________`

**P0.5 Q10, the baseline, and the sites before [APP, any machine]**

```sh
# Q10: must print "0 0": claimed erasures, and queued erasure jobs
php artisan tinker --execute="echo DB::table('users')->whereNotNull('erasure_started_at')->whereNull('erased_at')->count(), ' ', DB::table('jobs')->where('queue','erasure')->count(), PHP_EOL;"
php artisan migrate:status --pending                                       # expected: No pending migrations (nothing pending beyond what the last sitting left; write anything else)
curl -s -o /dev/null -w '%{http_code}\n' https://platform-api.catalyst-growth.com/up   # 200
supervisorctl status laravel-queue laravel-erasure laravel-schedule        # RUNNING; write the uptimes
curl -sS https://platform.catalyst-growth.com/ | grep -o 'assets/[A-Za-z0-9._-]*\.js' | head -3        # main bundle BEFORE
curl -sS https://admin.catalyst-growth.com/ | grep -o 'assets/[A-Za-z0-9._-]*\.js' | head -3           # admin bundle BEFORE
```

Healthy: **Q10 prints `0 0`** (if not, record it; P4.3 asks again and decides), no pending migration, `/up` 200, three RUNNING. The Q10 read here is **not** the one that gates the restart.
Q10: `______` Pending: `______` `/up`: `______` Uptimes: `______________` Main bundle before: `______` Admin bundle before: `______`
Result: `______________`

### P1. Code

**P1.1 Pull the mirror's copy of the tip [HOST]**

**Deploy of 2026-11-15: this proof FAILED on the box** (`apps/api` tree `bd78dc68…` on the box against `48eab25a…` on GitHub, no matching GitHub commit in the last 400; cause not read; see the deploy log and tech-debt row 39). Treat a mismatch as a stop until the cause is read, not as "the mirror explains it" (a mirror changes commit SHAs, not tree hashes). The Bitbucket mirror must hold the tip first (a separate human step after the push). The mirror's SHAs differ from GitHub's, so **prove the code by tree hashes**. GitHub values at `77797fcd` (the `apps` hash moved twice since `21ef79bf`: the CI test fix touched `apps/main`, and the API trial fix (`3c4a6133`: fixed `server_name`, no OS kernel version) touched `apps/api`, and the breadcrumb fix (`77797fcd`, the current code tip) touched `apps/main` and `apps/admin` only (`apps/api`, `packages`, `pnpm-lock.yaml` and `composer.lock` did not change, so the API release name stands). So **the API release name is now `catalyst-api@48eab25ab42d`**, not `a4aa908faafe`; read 2026-11-15 on the development checkout; **restamp if the tip moves**):

```
apps              7087ba8ed2f51af3f6d4b6d34a8e4ad582d1fc2a
apps/api          48eab25ab42d0b9dd4c44ae5394b01044c4ba6bb
packages          5b7f1c590269895e183d0132359d552af639ea9a
pnpm-lock         5c469f97d0d3568587be59f5d944876dc1a80736
composer.lock     2fb4ae4761b4ff2511c9f1bbc3cd21d177428194      (apps/api/composer.lock)
```

```sh
cd ~/catalyst-engine
OLD=$(git rev-parse HEAD); echo "OLD=$OLD"
git remote -v | head -2                                    # CONFIRM the remote is the Bitbucket mirror
git fetch origin
git log origin/main -5 --format='%h %s'                    # the top commits' SUBJECTS must include "fix(sentry): fixed api server_name and no os kernel version in events" and "test(sentry): native plugin check copy no longer depends on temp or repo depth" and, further down, "test(sentry): malformed API dsn cannot break requests, errors or artisan (AH-121)"
git merge --ff-only origin/main
git rev-parse HEAD
git rev-parse HEAD:apps HEAD:apps/api HEAD:packages HEAD:pnpm-lock.yaml HEAD:apps/api/composer.lock      # must equal the five values above (HEAD may be a docs-only descendant: docs/ is not in these)
```

Healthy: a fast-forward; the five hashes **equal** the table. A difference means the mirror holds other content or another tip: **stop**. Then **[APP]** the `APP_KEY` fingerprint is **identical to P0.3**.
**Release names, for the record (they derive from these hashes):** API `catalyst-api@` + the first 12 hex of `apps/api`'s tree = **`catalyst-api@48eab25ab42d`**. The two SPA names are `catalyst-main@<12 hex>` and `catalyst-admin@<12 hex>`, computed by the build as the first 12 hex of `sha1(<apps/<surface> tree> + <packages tree>)`; **read them from the first test event, do not predict them** (`-dirty` appended means the Netlify checkout was modified: a finding).
Mirror HEAD (SHA, subject): `______________` Trees equal: yes / no Fingerprint after: `______________` Same as P0.3: yes / no
Result: `______________`

**P1.2 PHP dependencies: the lock changed, the safe form only [HOST, then APP]**

First the dry run, which changes nothing, and **stop if it errors**:

```sh
composer install --no-dev --no-scripts --dry-run               # APP; expected: lists the five new packages to install, exits 0
```

If it errors on the `plugin-api-version` field alone (the box's Composer is older than 2.11), that is the known risk: **stop and tell Pedram**. Setting the field back to `2.6.0` by hand is a one-line fix that **needs Pedram's word**; do not edit the lock on your own.

Then, only if the dry run exits 0:

```sh
composer install --no-dev --optimize-autoloader --no-scripts   # APP; never plain "composer install"
php artisan package:discover --ansi                            # APP; REQUIRED: this registers the Sentry service provider
grep '^APP_KEY=' .env | sha256sum | cut -c1-12                 # APP; identical to P0.3
```

Healthy: the install adds the five packages and changes nothing else; `package:discover` lists `sentry/sentry-laravel`; the fingerprint is unchanged. **`package:discover` is not optional**: without it the service provider is not registered and nothing reports.
Dry run exit code: `______` Composer ran: `--no-scripts` form Discover lists sentry-laravel: yes / no Fingerprint: `______________`
Result: `______________`

**P1.3 The two SPA builds [NETLIFY, then read the build logs]**

Set the web env (P3.2) **first**, then trigger both builds from the mirror. Netlify must build with git history (reported). Read each build log for:

- `pnpm install --frozen-lockfile` succeeds (the lock **did** change, so this is a real install). **`@sentry/cli`'s install script must be allowed to run** (it is in the root `package.json` `onlyBuiltDependencies`; **whether Netlify's pnpm honours it was not read**). If the log says "Ignored build scripts: @sentry/cli", the upload has no uploader: write it down, the site still builds and runs, only stack traces stay minified.
- `vue-tsc --noEmit`, `vite build` and then `node scripts/check-no-sourcemaps.mjs` all exit 0. **A `.map` file left in `dist` fails the build, and on Netlify a failed build leaves the previous deploy live (the safe failure).**
- With `SENTRY_AUTH_TOKEN` set, the log shows the Sentry plugin creating the release and uploading source maps. **This upload has never been run with a real token** (sentry-plan.md D6): what the plugin does when the upload fails (fails the build or only warns) was **not read**. Write down what the log says; either outcome is safe, because maps are deleted after a successful upload and the check fails the build if any remain.
- No line `[sentry] WARNING: no release for catalyst-...` (that line means git was not available to the build: events would arrive without a release).
- **Do not run `build:native`** (that is Pedram's, build 6).

Main build commit: `______________` Admin build commit: `______________` Install ok: yes / no `@sentry/cli` script ran: yes / no / not shown Upload lines: `______` Release warning absent: yes / no
Result: `______________`

**P1.4 Check each unpublished build in the browser, before it is published [BROWSER]**

Open each site's **deploy permalink** (the unpublished build; **CONFIRM** the URL form in the Netlify UI). Open the DevTools console and reload.

Healthy: **no "Invalid Sentry Dsn" console error** (the SDK's wording; D7 note 1: an invalid DSN logs that line and sends **nothing**, silently as far as Sentry is concerned). The earlier admin attempt failed exactly this way because a different endpoint was pasted from the Client Keys page. **If the line appears: do not publish; correct the site env value (P3.2) and rebuild.** An empty DSN logs nothing and sends nothing; that is the off state, not a failure, but then P8 has nothing to verify.
Main permalink console clean: yes / no Admin permalink console clean: yes / no
Result: `______________`

### P2. Migrate: none

There is no migration. P0.2 proved it. Do not run `php artisan migrate`.
Result: `______________`

### P3. Env: the table, and who sets each

**P3.1 The API env on the box [APP; the colleague types, Pedram supplies the DSN privately]**

| Surface | Variable                                                          | Value                                                                        | Where                                                             | Who sets                                                        |
| ------- | ----------------------------------------------------------------- | ---------------------------------------------------------------------------- | ----------------------------------------------------------------- | --------------------------------------------------------------- |
| API     | `SENTRY_LARAVEL_DSN`                                              | the `catalyst-api` project's DSN                                             | the box `.env`                                                    | **Colleague** types it; **Pedram supplies the value privately** |
| API     | `SENTRY_ENVIRONMENT`                                              | `production`                                                                 | the box `.env`                                                    | **Colleague**                                                   |
| API     | `SENTRY_RELEASE`                                                  | `catalyst-api@48eab25ab42d` (the tree hash of `apps/api`; P1.1)              | the box `.env`                                                    | **Colleague** (written by the commands below)                   |
| Main    | `VITE_SENTRY_DSN_MAIN`                                            | the `catalyst-main` project's DSN                                            | the main Netlify site env                                         | **Colleague**; Pedram supplies privately                        |
| Main    | `SENTRY_AUTH_TOKEN`                                               | the org token limited to release and source-map upload                       | the main Netlify site env                                         | **Colleague**; Pedram supplies privately                        |
| Main    | `SENTRY_ORG`, `SENTRY_PROJECT`, `SENTRY_URL`                      | `catalyst-engine`, `catalyst-main`, the EU region URL (**CONFIRM** the host) | the main Netlify site env                                         | **Colleague** (names; values were not read at drafting)         |
| Admin   | `VITE_SENTRY_DSN_ADMIN`                                           | the `catalyst-admin` project's DSN                                           | the admin Netlify site env                                        | **Colleague**; Pedram supplies privately                        |
| Admin   | `SENTRY_AUTH_TOKEN`, `SENTRY_ORG`, `SENTRY_PROJECT`, `SENTRY_URL` | as main, with `SENTRY_PROJECT` = `catalyst-admin`                            | the admin Netlify site env                                        | **Colleague**; Pedram supplies the token privately              |
| Phone   | `VITE_SENTRY_DSN_APP`                                             | the `catalyst-app` project's DSN                                             | the gitignored `apps/main/.env.native-production` on Pedram's Mac | **Pedram** (build 6, not this sitting)                          |

`SENTRY_RELEASE` is **only an override** for the SPAs: **leave it unset** in Netlify (the release derives from tree hashes at build time). **No DSN is ever put in a tracked file, a screenshot, a ticket or chat.** An empty DSN is the off state on every surface.

Write the API release, **without printing the file**, from the HOST value of P1.1:

```sh
REL="catalyst-api@$(git rev-parse --short=12 HEAD:apps/api)"; echo "$REL"        # HOST; expected: catalyst-api@48eab25ab42d
# APP is a different shell: set REL there to the printed value, then write the line without printing .env (use the real path if it is not /app/.env)
REL=catalyst-api@48eab25ab42d    # APP; the value HOST printed above
grep -q '^SENTRY_RELEASE=' .env && sed -i "s|^SENTRY_RELEASE=.*|SENTRY_RELEASE=$REL|" .env || echo "SENTRY_RELEASE=$REL" >> .env
grep -c '^SENTRY_RELEASE=' .env                                                    # expected: 1
grep -c '^SENTRY_ENVIRONMENT=production$' .env                                     # expected: 1 (add the line with an editor if 0)
```

Then the colleague adds `SENTRY_LARAVEL_DSN=<value>` **with an editor** (never `echo`). Check **only its shape**, never its value, **before** `config:cache` (a malformed value does **not** break the app: `SentryMalformedDsnTest` boots a fresh app with three malformed values and proves requests, route errors and artisan behave as with an empty DSN, because the SDK treats it as no DSN. But then **nothing is sent, silently**, so the shape is checked first to catch a bad paste before it costs a silent hour):

```sh
grep -c '^SENTRY_LARAVEL_DSN=.\+' .env                                             # expected: 1 (set); 0 means empty
php -r 'require "vendor/autoload.php"; $l=""; foreach(file(".env") as $x){ if(str_starts_with($x,"SENTRY_LARAVEL_DSN=")){$l=trim(substr($x,19));} } try{ Sentry\Dsn::createFromString(trim($l,"\"\x27")); echo "dsn shape ok\n"; }catch(Throwable $e){ echo "dsn shape INVALID\n"; }'
```

Healthy: `1`, `1`, `1`, `dsn shape ok`. **This checks the shape only, not that it is the `catalyst-api` project's key.** If it prints `INVALID`: do not cache; correct the line; ask Pedram. Nothing reads the new env until P4.1, so the DSN is effectively **set last**: it goes live at the P4.2 reload together with the code.
`SENTRY_RELEASE` line count: `______` environment count: `______` DSN set: yes / no Shape: ok / INVALID
Result: `______________`

**P3.2 The web env [NETLIFY; before the builds]**

For **each** site, set the rows of the table above. Healthy: the Netlify UI shows the variables present **by name** (do not screenshot values). `SENTRY_RELEASE` is not present. Then run P1.3 and P1.4.
Main env set: yes / no Admin env set: yes / no `SENTRY_RELEASE` absent: yes / no
Result: `______________`

### P4. Infra [APP, supervisor in the container]

**P4.1 Cache the config, and the route cache if the box uses it**

```sh
php artisan config:cache                                                            # this FAILS if config/sentry.php contained a Closure; a test pins that it does not
grep '^APP_KEY=' .env | sha256sum | cut -c1-12                                     # identical to P0.3
ls bootstrap/cache/                                                                 # routes-v7.php present? (CONFIRM whether this box caches routes)
php artisan tinker --execute="echo config('sentry.dsn') ? 'dsn set' : 'dsn empty', ' ', config('sentry.environment'), ' ', config('sentry.release'), PHP_EOL;"
```

Healthy: `config:cache` succeeds; the fingerprint is unchanged; the last line prints `dsn set production catalyst-api@48eab25ab42d` (the DSN itself is **not** printed). **If a route cache exists**, run `php artisan route:cache` (this release adds no route, but keep the box's usual procedure).
Output: `______________` Route cache present: yes / no
Result: `______________`

**P4.2 PHP-FPM reload [command from your usual procedure]**

```sh
supervisorctl status <PHP_FPM_PROGRAM>
supervisorctl signal USR2 <PHP_FPM_PROGRAM>        # graceful reload (or `kill -USR2 <master pid>` if the program wraps FPM)
supervisorctl status <PHP_FPM_PROGRAM>
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/up       # expect 200 (no curl in the image? use wget -S -O /dev/null)
```

Healthy: FPM stays RUNNING and `/up` answers `200`. **From this reload the web processes report to Sentry** (the code and the DSN are both live for php-fpm).
Program: `______________` Reload command: `______________`
Result: `______________`

**P4.3 `queue:restart`: NEEDED. Q10 first, immediately before, in the same minute, and it must print `0 0`**

Why: a `queue:work` process keeps the classes and config it loaded at start. The workers must load the new vendor classes, the handler wiring and the config that now holds the DSN, or a failed job reports nothing and the old and new files can mix. Run the three in order, each read before the next:

```sh
git diff --name-only $OLD..HEAD -- apps/api/app apps/api/bootstrap apps/api/config      # HOST; expected: the Sentry files only (SentryScrubber.php, SentryUserContext.php, AppServiceProvider.php, bootstrap/app.php, config/sentry.php) and nothing surprising
supervisorctl status laravel-queue laravel-erasure                                       # APP; RUNNING; write the uptimes
php artisan tinker --execute="echo DB::table('users')->whereNotNull('erasure_started_at')->whereNull('erased_at')->count(), ' ', DB::table('jobs')->where('queue','erasure')->count(), PHP_EOL;"   # Q10, must print: 0 0
```

**Only if Q10 printed `0 0`:**

```sh
php artisan queue:restart                                                                # signals every queue:work to exit after its current job; supervisor starts new ones
sleep 15; supervisorctl status laravel-queue laravel-erasure laravel-schedule            # RUNNING, uptimes now short; laravel-schedule untouched
```

**If Q10 printed anything else: do NOT run `queue:restart`.** A claimed account may be mid-run on `laravel-erasure`. Record the output, stop, tell Pedram. This is safe to defer: php-fpm already reports (P4.2); only the **workers** are not yet reporting, so a failed job or the erasure worker's own failure sends nothing until the restart. **Treat Sentry as not live for the workers** and do the restart at the next moment Q10 reads `0 0`, recorded in `deploy-log.md`.
Files listed: `______________` Uptimes before: `laravel-queue` `______` `laravel-erasure` `______` **Q10 at the restart: `______`** Restarted: yes / deferred (why) `______` Uptimes after: `______________`
Result: `______________`

**P4.4 Supervisor status [APP]**

```sh
supervisorctl status
```

Healthy: `cron`, `laravel-erasure`, `laravel-queue`, `laravel-schedule`, `nginx`, `php-fpm` all `RUNNING`, none missing, none crash-looping. No program is added by this release.
`supervisorctl status` (paste all lines): `______________`
Result: `______________`

### P5. One-shots: none [APP]

```sh
git diff --name-only $OLD..HEAD -- apps/api/app/Console apps/api/database/seeders | head -20     # expected: no output
```

Healthy: no output. Nothing to run.
Result: `______________`

### P6. Verify the API with one test event per check [APP, then PEDRAM reads the raw JSON]

**P6.1 `/up`, the key, no pending migration**

```sh
curl -s -o /dev/null -w '%{http_code}\n' https://platform-api.catalyst-growth.com/up        # expect 200
grep '^APP_KEY=' .env | sha256sum | cut -c1-12                                              # identical to P0.3
php artisan migrate:status --pending                                                        # the same as P0.5
```

`/up`: `______` Fingerprint: `______________` Same as P0.3: yes / no
Result: `______________`

**P6.2 One handled exception through the configured client [APP]** (not `sentry:test`, rule 8). It carries no user (no request, no guard), which is expected:

```sh
php artisan tinker --execute="report(new \Exception('AH-121 production test event from the API'));"
```

**P6.3 One `QueryException` against a bound email, to prove only the class and the SQLSTATE arrive [APP]** (a read-only select on a table that does not exist; no row is touched; the address is a throwaway `.invalid` one):

```sh
php artisan tinker --execute="try { DB::select('select * from ah121_no_such_table where email = ?', ['qa-ah121@example.invalid']); } catch (\Throwable \$e) { report(\$e); echo get_class(\$e), PHP_EOL; }"
```

Healthy: it prints `Illuminate\Database\QueryException`. **Wait about a minute (ingestion lag), then PEDRAM opens both events in `catalyst-api` and reads the raw JSON with the checklist in P8.3.** For P6.3 add: the strings `qa-ah121`, `example.invalid`, `ah121_no_such_table` and `select` appear **nowhere**; the exception value is the class and the SQLSTATE only.
**P6.4 Before anything else, read `environment` [PEDRAM]:** open the **first** production event in `catalyst-api` and read `environment` **before** any other check. It must be exactly **`production`** (it comes from `SENTRY_ENVIRONMENT=production` in the box `.env`, P3.1). If it reads anything else (for example `testing` or `local`), **stop: turn the API off (§4)** and fix the env line first. Why this is explicit: with `SENTRY_ENVIRONMENT` empty the tag falls back to `APP_ENV`, and a **process-level `APP_ENV` beats the `.env` file** (the local trial read `testing` because the shell that started it had `APP_ENV=testing` exported). Also read `server_name` = **`catalyst-api`** (never a hostname) and `contexts.os`: `name` and `version` present, **`kernel_version` null or absent**.
Both commands ran: yes / no Event ids: `______________` / `______________` Checklist (P8.3) passed: yes / no
**If any check fails: turn the API off first (§4), then fix.**
Result: `______________`

**Local baseline (API trial, 2026-11-15, real Sentry, EU, `catalyst-api` project; code at `695d3cb1`, i.e. before the `server_name` and kernel-version fix; Pedram's Mac, so `environment` read `testing` and `server_name` was the hostname: both fixed or explained above).** Raw JSON checked by Claude, PASS on the privacy rules: **a** `aad69970405d4118b0c1f6560988f914` (route exception, signed-in creator: user `{id: <ulid>}`, `user_type` creator); **b** `42f07fd9c2a04cb18472aa8228d2c359` (guest: no user); **c** `1736c7d278b144ddb897c273e7a87289` (QueryException: both exception values are `<Class> (SQLSTATE 42P01)`, the planted name and phone appear nowhere); **d** `afce9dd7135741e99a5e1f4078e25f78` (queued job on a real worker: no user, `sapi` cli, no job payload). 404 and 422: nothing arrived. All four: no cookies, Authorization, `ip_address` or body; headers `Accept`, `Host`, `User-Agent` only; geo nulled; release `catalyst-api@a4aa908faafe`. Accepted as is: the `User-Agent` header is sent (browser context; the privacy texts say "device or browser model"); the `modules` list (composer package versions) is sent, harmless.

### P7. Publish the web sites [NETLIFY]

**Gate: P4 and P6 are filled and passed, P1.4 was clean for both builds.** Publish **both** builds, main and admin. Healthy: Netlify shows the production deploy **Published** for the commit of P1.1, and each bundle hash differs from P0.5:

```sh
curl -sS https://platform.catalyst-growth.com/ | grep -o 'assets/[A-Za-z0-9._-]*\.js' | head -3
curl -sS https://admin.catalyst-growth.com/ | grep -o 'assets/[A-Za-z0-9._-]*\.js' | head -3
```

Gate met at (time): `______________` `main` published: `______________` `admin` published: `______________` New bundles differ from P0.5: yes / no
Result: `______________`

### P8. Verify the web

**P8.1 AH-122 (main web) [BROWSER, creator, onboarding]**

Open the onboarding country-code picker. Choose **Canada**, then **Bahamas**: each shows **one flag and exactly one selected row** (before the fix, +1 showed several). Result: `______`

**P8.2 Sign-ins still work [BROWSER]**

Sign in with your own accounts (no password goes in this file): **a creator** and **an agency user** on `https://platform.catalyst-growth.com`, **an admin** on `https://admin.catalyst-growth.com`. Healthy: each reaches its dashboard; the DevTools console shows **no "Invalid Sentry Dsn"** on either site. Result: `______`

**P8.3 One test event per SPA, and the raw-JSON checklist [BROWSER, then PEDRAM reads]**

On each site, **signed in as a test account whose email, first name, last name and display name Pedram knows**, raise one error from the console. **An error typed or thrown directly in the console does not reach `window.onerror`, and Sentry is not a console global, so a bare `throw` or a `Sentry.captureException(...)` sends nothing (the first production attempt, 2026-11-15). Use the timer form: `setTimeout(() => { throw new Error('AH-121 production test event') }, 0)`; it is an uncaught error and goes through the SDK's global handler** ( for example open `/reset-password?token=TESTTOKEN123&email=qa-ah121@example.invalid` once in that tab first, then throw, so the URL rules are exercised). Wait about a minute, then **PEDRAM exports the raw JSON of each event** (the rendered view is not the proof; the Sentry integration the assistant uses cannot show `sdk.integrations` or breadcrumbs). **Pass only if all hold (one list, used for P6.2, P6.3 and each SPA event):**

1. The test account's **email, first name, last name and display name** appear **nowhere** in the JSON (search each), nor `qa-ah121`, nor `TESTTOKEN123`.
2. No **`Authorization`, `Cookie`, `Bearer`, `signature`, `X-Amz-`, `password`** string and **no token value**. (The literal `[token]` is the scrubber's redaction marker, not a token.)
3. The **`user` block has `id` only**: it equals the test account's **ulid**, and there is **no `email`, `username`, `name` or `ip_address` key** and no IP address anywhere in the JSON. `user_type` is `creator`, `agency` or `admin` as expected. (The API tinker events have **no user**: expected.)
4. **`user.geo` is null or absent** (the organisation rule). **This is the per-project first-live geo re-check: record it per project in P9.**
5. `request.url` (and every breadcrumb URL) has **no `?` and no `#`**.
6. **No `contexts.culture`** and no time zone, locale or calendar value (web). (Phone events: no `timezone`; `contexts.device.locale` is expected and declared.)
7. `sdk.integrations` matches the allow-list: **no `BrowserSession`, `Replay`, `CultureContext`, `BrowserTracing`** (sentry-plan.md D7).
8. `release` is the expected name (API `catalyst-api@48eab25ab42d`; SPAs `catalyst-<surface>@<12 hex>`, **no `-dirty`**) and `environment` is `production`.
9. **No breadcrumbs from another account's session** in the same tab: sign in as one account, navigate, sign out, sign in as another, raise the error: the event must hold none of the first account's navigation or API paths (the breadcrumb clearing on an account change; production event `43eedc28…` showed the carry-over before that fix).
10. No request body, no cookies, no headers beyond `Accept`, `Host` and `User-Agent` (API and web). **API: `server_name` is `catalyst-api` and `contexts.os.kernel_version` is null or absent.**

Record per event: id, surface, pass / fail per item, and the release name read.
Main event id: `______________` Admin event id: `______________` API event ids (P6): `______________` Release names read: `______________` All nine items pass: yes / no
**If any item fails: turn that surface off first (§4), then fix.**
Result: `______________`

### P9. Close out: the per-project geo re-check, and what is left

The org-level geo rule covers all four projects, but Sentry derives geo from the IP even with "Prevent Storing of IP Addresses" on, so **each project is checked once, when its DSN first goes live** (checklist item 4):

| Project          | First live event | `user.geo` null or absent | Checked by | Date     |
| ---------------- | ---------------- | ------------------------- | ---------- | -------- |
| `catalyst-api`   | `______________` | yes / no                  | `______`   | `______` |
| `catalyst-main`  | `______________` | yes / no                  | `______`   | `______` |
| `catalyst-admin` | `______________` | yes / no                  | `______`   | `______` |
| `catalyst-app`   | later (build 6)  | later                     | Pedram     | later    |

Result: `______________`

### Record it [deploy-log.md]

Fill the **AH-121** `PENDING` entry (AH-122 is cross-referenced as carried by this web deploy): the tip and **both SHAs** (mirror and GitHub) with the five-hash tree check, the box's **`composer --version`** and the dry-run result, the install form used, **the Q10 reading at the restart and whether `queue:restart` ran or was deferred**, the supervisor lines, the three release names read from the events, the **event ids and the checklist result per surface**, the per-project geo re-check, the Netlify build-log findings (the `@sentry/cli` script, the upload lines), the operator, and **anything unexpected**. **Retention read from the org settings** goes there too (and then into the privacy texts, replacing the pricing-page 30 days). Flip the status to `DEPLOYED` with today's date **for the surfaces actually live only**; the phone stays open. Record deploy state there only. Mark what stayed **not read**; never write "passed" for a step that was not run.
Done: yes / no

---

## 3. Not in this sitting (named, so nothing is assumed done)

- **Native build 6** (`CURRENT_PROJECT_VERSION` 6 and Android `versionCode` 6 are already in the repo): by Pedram, after this deploy, with `VITE_SENTRY_DSN_APP` in `.env.native-production` and **without** `VITE_SENTRY_TEST_EVENT`. **A build made with that flag is a walk build only and is never submitted for App Store review.** The device walk is [`native-sentry.md`](native-sentry.md). **Submission waits for the public web notice to be published (publication not read) and for the Privacy Report of the signed archive to be read** ([`../store/app-privacy-answers.md`](../store/app-privacy-answers.md)). It carries AH-122 to the phone.
- **Uptime monitors and alert rules** (Pedram): the API `/up` on Sentry's one included monitor; the two web origins on one free external monitor service (not chosen); error alerts to Pedram's email **after a week of watching volume**. `/up` is a weak signal ([`../tech-debt.md`](../tech-debt.md) row 37); Sentry Crons is deferred (row 38).
- **Native source maps and dSYMs** (tech-debt row 34), **Android** (row 33): later.
- **No worker restart while an erasure is claimed.** Q10 is the gate, twice (P0.5 and P4.3).

---

## 4. If something goes wrong, and how to turn each surface off

**First rule: stop, record the state, change nothing else.** Then:

| Symptom                                                  | Do                                                                                                                                                                                                                                                                                                                                       |
| -------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **`APP_KEY` fingerprint differs** (any step)             | **Restart nothing.** Restore the value from the recoverable copy (P0.3), `php artisan config:cache`, reload FPM. Do not run plain `composer install`                                                                                                                                                                                     |
| Tree hashes in P1.1 differ from the table                | The mirror holds other content. **Stop before changing anything**; ask Pedram. Do not pull "close enough"                                                                                                                                                                                                                                |
| The Composer dry run errors (P1.2)                       | **Stop.** Change nothing. Tell Pedram; the `plugin-api-version` workaround needs his word                                                                                                                                                                                                                                                |
| `config:cache` fails (P4.1)                              | Read the error. A Closure in a config file is the usual cause: it should not be there (a test pins it). Leave the previous cache, tell Pedram; the code is not live until FPM reloads                                                                                                                                                    |
| The API 500s after the reload                            | **Turn the API off (below), then roll the code back** (below). An unreadable error means read the Laravel log, not the env file                                                                                                                                                                                                          |
| **Q10 is not `0 0` at P4.3**                             | **Do not `queue:restart`.** Record it, finish P4.4 to P6 (php-fpm reports), tell Pedram. The workers do not report until the restart                                                                                                                                                                                                     |
| A raw-JSON check fails (P6, P8.3)                        | **Turn that surface off first**, then ask Pedram. If a person's data is in an event: in Sentry use the issue's "Delete and discard", note it in the incident log, fix the scrubber (a test and a break-revert first)                                                                                                                     |
| "Invalid Sentry Dsn" in the console, or no event arrives | A wrong value pasted (a different endpoint than the project's DSN from its Client Keys page). Nothing is sent, nothing breaks. Correct the value and rebuild (web) or re-cache (API)                                                                                                                                                     |
| Build fails on a leftover `.map`                         | The safe failure: the previous deploy stays live. Read the build log, tell Pedram                                                                                                                                                                                                                                                        |
| Code problem after the API deploy                        | **Roll the code back** (no schema to leave): `git checkout <OLD>` on the host, then the safe form `composer install --no-dev --optimize-autoloader --no-scripts` and `php artisan package:discover --ansi` (the lock reverts with the code), `config:cache`, reload FPM, then `php artisan queue:restart` **only after Q10 reads `0 0`** |
| Code or look problem after the web publish               | Redeploy the previous `dist` in Netlify (each site separately). The API change is harmless to the old web                                                                                                                                                                                                                                |
| **Data problem** (not expected: no row is written)       | Nothing in this release writes a row, so there is nothing to restore. Fix forward                                                                                                                                                                                                                                                        |

**How to turn each surface off, fastest first (no code change; an empty DSN or a disabled key sends nothing):**

- **Everything, instantly, with no deploy: disable the project's client key in Sentry** (Project Settings, Client Keys, Disable). The SDK is then rejected and stops sending; this works for the **web sites and for binaries already installed on phones**, and it is the only instant switch for the web. Pedram does this; **PEDRAM** can do it from the Sentry UI at any hour.
- **API:** empty `SENTRY_LARAVEL_DSN` in the box `.env` (with an editor), `php artisan config:cache`, reload php-fpm, **then `php artisan queue:restart` only after Q10 reads `0 0`** (the same rule as P4.3; until then the workers keep the old config and keep reporting). Nothing is sent from php-fpm after the reload.
- **Main and admin web:** the DSN is baked at build time, so **emptying it needs a Netlify redeploy**: clear `VITE_SENTRY_DSN_MAIN` / `VITE_SENTRY_DSN_ADMIN` in that site's env and rebuild, or redeploy the previous `dist`. Use the client-key switch meanwhile.
- **Phone:** disable the `catalyst-app` client key. A future build with an empty `VITE_SENTRY_DSN_APP` sends nothing.

---

_Provenance: drafted by Cursor on 2026-11-15 from `docs/reviews/sentry-plan.md` (B9, D5, D6, D7, D8), the shape of [`deploy-ah115-ah120.md`](deploy-ah115-ah120.md), [`deploy-log.md`](deploy-log.md) (the AH-115 + AH-120 and AH-122 entries), `git diff 28df3f27 77797fcd` (lock files, `apps/api`, `apps/api/database`, `apps/admin`, `apps/main`, `packages`), the tree hashes quoted in P1.1, and the read-only source of `sentry/sentry-laravel` (`TestCommand.php`) and `sentry/sentry` (`Dsn.php`) in `apps/api/vendor`. The scrubber, the user hook, the empty-DSN switch, the SPA clients and the native app were exercised locally and against the real Sentry projects from a development machine (D2, D7, D8); **no command has been run against production, and no production DSN is set.** **Still CONFIRM, because the repo cannot say:** the box's Composer version, the env file path, the PHP-FPM program name, whether the box caches routes, how the Netlify sites build from the mirror (including whether pnpm runs `@sentry/cli`'s install script there and what the Sentry plugin does when an upload fails), the EU region URL for `SENTRY_URL`, the Netlify deploy-permalink form, the exact console one-liner for a test error per SPA, the retention figure in the Sentry org, and the Sentry settings listed in P0.1. Engineering view, not legal advice, wherever the privacy texts are touched._
