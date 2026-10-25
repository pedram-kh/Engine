# Deploy log — what production runs, and when

**This file is the authoritative record of what is deployed to production.** One entry per
production deploy, newest first. If you want to know what code production is running, or what
shipped on a given day, this is the file — not the push history, not a chat thread, not an AH
entry.

**Why it exists.** Push state and deploy state are different facts, and they drifted for weeks
because deploy state was carried inline in `RESUMPTION-TEMPLATE.md` Part 2 while deploys are
**colleague-managed and advance without notice**. A fact maintained in two places goes stale in
one of them. So deploy state now lives here and **only** here: the resumption template points at
this file rather than restating it, exactly as push state was collapsed to `origin/main` being the
single authority.

**The standing convention this file exists to serve: pushed ≠ deployed.** Never infer a deploy
from a push. Deploy state is **read from the server** — `php artisan migrate:status` for schema,
`supervisorctl status` / `crontab -l` for the worker and scheduler — and then written down here.

**How to use it.**

- **Before a deploy:** open a new entry at the top with the template below, filled in as far as
  you can (range, AH entries, migrations, obligations), marked `PENDING`.
- **During:** fill the blanks as each step of the runbook checklist completes
  ([`production-queue-worker.md` §8](production-queue-worker.md)). The snapshot ID goes in
  **before** the migration runs, not after.
- **After:** flip the status to `DEPLOYED`, record the verification results, and record anything
  that surprised you — that last field is the one that pays for the file.

**Related.** Deploy **procedure** is `production-queue-worker.md` §8 (the checklist), §8.3 (the
Jobs Board arc's single deploy), §7.3–7.4 (flag first-enable rituals). Deploy **obligations** per
change are in the AH entries (`../reviews/adhoc-changes-log.md`). This file records what actually
happened.

---

## Entry template (copy this)

```
### YYYY-MM-DD · <short description> — STATUS

- **Status:** PENDING | DEPLOYED | FAILED / ROLLED BACK
- **Range:** `47d2efc5` (AH-103's last runtime commit, the deployed runtime tip; the commits between it and `8aa11376` are docs, `.env.example` and the iOS camera strings / build 2) → **`222b0d39`** (the pushed tip before AH-103b; the range extends to whatever tip is deployed: AH-103b's last runtime commit is `82c8a2e7`, and AH-109 and AH-105 will land on top before the deploy).
- **AH entries carried:** AH-NNN → AH-NNN (list them, or name the arc)
- **Migrations run:** <count> — every name, or "none"
- **Pre-deploy reads:** command + the number it returned, or "none"
- **Snapshot ID:** <identifier> · confirmed `available` before migrating? yes/no
- **Infra:** worker restart (required? done?), cron/scheduler changes, env changes
- **One-shot commands:** each command + its dry-run counts + its real-run counts, or "none"
- **Post-deploy verification:** `/up` status, the authenticated request, any change-specific reads
- **Flags armed:** which, or "none — <why>"
- **Operator:** who ran it
- **Anything unexpected:** the honest field. "Nothing" is a valid answer; silence is not.
```

---

## AH-106 + AH-107 + AH-108 + AH-103b + AH-109 — pre-tester UI batch (shell back arrow, clipped Applied chip, campaign status on the creator side, unread indicators and icon badge) and universal links — PENDING

> **AH-106, AH-107, AH-108 and AH-103b are all pushed (GitHub only); nothing here is deployed.** Opens a new entry because everything below it is
> DEPLOYED. One production deploy and **one** TestFlight build 3 for all of them (Q9 ruling; AH-103b rides the same release, with its own web checks first). **Timing (Pedram, 2026-10-19): the deploy and
> build 3 come after AH-105** (the privacy checklist), not before. AH-108 adds a named `queue:restart` and a one-shot backlog command.

- **Status:** PENDING. AH-106 (`1c3c7aaf`, `e390581e`) was pushed 2026-10-17. AH-107 (`0801577e`, `b115639e`, `b1fd5e8b`, `77600807`, `480cfcd8`, `e557d358`) and AH-108 (`59c52ede`, `65df6b6e`, `9f47184f`, `715a1f72`, `3f73c8e7`, `16f06b54`, `4dad01b5`, `031b2e1b`, `0cc86e9a`, plus docs) were pushed 2026-10-19 as `54075f02..222b0d39` (19 commits). The AH-108 device walk passed 1–7 from the Xcode install (reported by Pedram); the production-APNs leg waits for build 3. **AH-103b** (`6ee14c36` plan, `ec172d59`, `7db73f78`, `12ba5d78`, `3b1f7ebf`, `f6cfffd3`, `82c8a2e7`, plus docs) was pushed 2026-10-19 after Stage 3 clearance; its device walk W1–W10 has **not** run and needs the web deploy, C1–C7 and build 3.
- **Range:** `47d2efc5` (AH-103's last runtime commit, the deployed runtime tip; the commits between it and `8aa11376` are docs, `.env.example` and the iOS camera strings / build 2) → AH-106 tip `e390581e`; AH-107 tip `480cfcd8` (local, not on GitHub; the range extends to whatever tip is pushed).
- **AH entries carried:** AH-106, AH-107, AH-108, AH-103b, AH-109, AH-103d, **AH-104a**
- **AH-103d rider: `sanctum:prune-expired --hours=24`, scheduled daily (API change; rides the API deploy).** Deletes `personal_access_tokens` rows whose `expires_at` passed more than
  24 h ago; live tokens are never touched. **PROD-DATA RISK: LOW** (it deletes only rows Sanctum already rejects; the first run clears the whole backlog once).
  - **Pre-deploy read (read-only, record the number):** `SELECT count(*) FROM personal_access_tokens WHERE expires_at < now() - interval '24 hours';` → **\_\_** rows expected to go on the first run.
  - **Post-deploy `schedule:list` check (REQUIRED):** `php artisan schedule:list | grep sanctum` must print exactly one line, `0 0 * * *  php artisan sanctum:prune-expired --hours=24`. Recorded output: **\_\_**
    (If the scheduler cron is not running nothing prunes; see `production-queue-worker.md` §7.)
  - **After the first midnight run:** repeat the count query: **\_\_** (expected 0 for rows expired more than 24 h ago).
- **AH-103d (reinstall marker, tech-debt 17) — BUILT 2026-10-20, app only; NOT pushed; rides TestFlight build 3.** Commits `31446982`, `e4a72325`, `4189f57a` (+ the docs commit).
  **API: untouched, no migration, no config, no deploy step.** **Build-3 obligations it adds:** (1) **the marker build signs every pre-marker install out once** (TestFlight
  builds 1 and 2, and any Xcode install): the user lands on the normal sign-in page with no message, signs in, and stays signed in. **Tell the testers** (note below).
  (2) The `personal_access_tokens` and `user_push_tokens` rows of those testers **disappear at that moment, by design** (the wipe is the real sign-out); not an incident.
  (3) After the first build-3 launch, deleting and reinstalling the app signs the user out, which it did not before. **Tester note (verbatim for build 3):** "This build signs
  you out once on first launch. Sign in again and you stay signed in as before. It also means deleting and reinstalling the app now signs you out, which it did not before."
  Proof and the device walk: [`reinstall-marker-review.md`](../reviews/reinstall-marker-review.md). **The walk (W1–W6) is Pedram's and is not yet done.**
- **AH-104a (erasure engine) — BUILT 2026-10-21; local, not deployed.** Plan [`account-deletion-erasure-plan.md`](../reviews/account-deletion-erasure-plan.md) §10; procedure
  [`account-erasure.md`](account-erasure.md). **PROD-DATA RISK: LOW at deploy** (three additive migrations, no backfill, nothing is erased by deploying: the two commands are artisan-only, have no route and
  are not scheduled; both are specced). The **first real `erasure:run --execute` is HIGH and is a separate, later act** (irreversible; runbook §2 first).
  - **Migrations (additive):** `2026_10_20_100000_add_erased_at_to_users_and_creators` (nullable columns) · `2026_10_20_100100_create_erasure_ledger_table` · `2026_10_21_100000_add_actor_index_to_notifications`
    (`notifications(actor_user_id)`, `CREATE INDEX CONCURRENTLY`, outside a transaction, so no lock; **the Postgres path has never been run**, tech-debt row 9. If it fails it leaves an invalid index:
    `DROP INDEX CONCURRENTLY IF EXISTS` it, then re-run).
  - **Infra:** no env, no scheduler, no worker restart for this code. **The media bucket needs the private `erasure-ledger/` prefix, versioning on** (the colleague; the application writes there and cannot
    delete), and, **if the bucket is versioned**, `s3:ListBucketVersions` and `s3:DeleteObjectVersion` for the application role (otherwise set `ERASURE_PURGE_OBJECT_VERSIONS=false`). **No scrub role or connection** (ruling 2026-10-21).
  - **Post-deploy verification:** `php artisan migrate:status` shows the three; `php artisan list | grep erasure` shows `erasure:run` and `erasure:reapply`; `php artisan erasure:run <test creator ulid>` with no
    `--execute` prints a report and changes nothing. Recorded: **\_\_**
  - **Before the first real erasure (not a deploy step):** the erasure specs pass on **Postgres** in CI (tech-debt row 1); a snapshot exists; the runbook §2 checklist.
  - **Read-backs B, C and D are in** ([`account-deletion-production-readback.md`](../reviews/account-deletion-production-readback.md)); S3 media versioning is still unanswered.
- **AH-104b-del Part A (the deletion request, API + sign-in step) — BUILT 2026-10-21; local, not deployed.** Part B (below) is built on top of it, also local, awaiting Pedram's review. Plan [`account-deletion-request-plan.md`](../reviews/account-deletion-request-plan.md).
  **PROD-DATA RISK: LOW at deploy** (one additive migration, the flag is OFF, the endpoint answers 404 until Pedram turns it on). The AH-104a rider (open assignments cancelled at erasure) is **HIGH when AH-104c first
  runs it** and is a separate, later act.
  - **Migration (additive):** `2026_10_21_110000_add_deletion_request_to_users` (two nullable timestamp columns on `users`, one index; no default, no backfill; table of a few thousand rows).
  - **Infra:** **`php artisan queue:restart` is REQUIRED** (a new queued mailable and listener, `AccountDeletionRequestedMail`). No scheduler change. Optional env `SUPPORT_EMAIL` (default
    `support@catalyst-growth.com`, read through `config('app.support_email')`); nothing breaks if unset.
  - **Flag:** `account_deletion_enabled` is **OFF** at deploy. **Pedram turns it ON** in the admin Feature-flags page after the device walk passes and before build 3 is submitted (named step on the build-3 checklist).
  - **Post-deploy verification:** `php artisan migrate:status` shows the migration; `POST /api/v1/me/account-deletion` as a signed-in creator answers **404** (flag off). The production re-run of the walk
    (**W2, W4, W5 only**, on a **named test creator**, with the flag ON for the window and **OFF again after**) is in the plan §device walk. Recorded: **\_\_**
  - **Not a deploy step:** the web sign-in step and the new bundle strings ride the next web build; machine-translated copy in 24 locales needs Pedram's pass on **en and es** (the public page and the
    consequences copy) before the flag flips.
- **AH-104b-del Part B (the pages and the agency side) — BUILT 2026-10-21; local, not deployed; awaiting Pedram's Part B review.** Review
  [`account-deletion-request-b-review.md`](../reviews/account-deletion-request-b-review.md), break-reverts in
  [`account-deletion-request-b-break-reverts.md`](../reviews/account-deletion-request-b-break-reverts.md).
  **PROD-DATA RISK: LOW at deploy.** **No migration, no backfill, no new command, no scheduler change, no new env.** Part B reads the Part A columns and adds behaviour that is inert until a creator
  has `deletion_requested_at` or `erased_at` set, which cannot happen while `account_deletion_enabled` is OFF and nothing has been erased. The one read that touches everyone is the agency discovery and
  messaging queries, which now filter on that column (`Creator::scopeVisibleToAgencies()`); with the column null for every creator the result set is unchanged (pinned by a spec that a live creator is
  never flagged or hidden). **The risk event is turning the flag ON, not the deploy**, and that is Pedram's step after the walk.
  - **Infra:** `queue:restart` is already required by Part A (same queue classes plus one new in-app notification type); nothing further.
  - **API surface:** agency payloads gain an `is_erased` boolean (`creator_is_erased` on the campaign board cards), never a name; `/me` gains `features.account_deletion` for creators only; a **new 422
    `creator.unavailable`** on the agency actions listed in `05-SECURITY-COMPLIANCE.md` §3.3a. The new web build ships **with** the API (an old build does not read `is_erased`; no creator can be erased
    yet, so nothing shows wrongly in the interim).
  - **Web:** the Security page, the dialog, `/delete-account` (static; safe to link from the store listing), the "Deleted creator" label on every agency screen, the `assignment.cancelled` notice, and the
    route-key contract file `notification-route-keys.json` (the API contract driver reads it; deploy both together).
  - **Post-deploy verification:** as Part A (endpoint 404 with the flag off) plus: `/delete-account` loads without signing in; an agency's discovery and roster still list every creator (compare the
    count with the day before); `/creator/security` as a creator redirects to the dashboard while the flag is off. Recorded: **\_\_**
  - **Before the flag flips (named build-3 steps):** the device walk W1-W10 passes (and W11, W12, proposed in the review); the machine-translated strings in 24 locales get a human pass in **en and es**.
- **AH-104c C1 (the gate: claim, delivered-work refusal, pending-creator verification, impersonation refusal) — BUILT 2026-10-22; local, not pushed, not deployed; awaiting Pedram's C1 review.** Plan
  [`account-deletion-admin-plan.md`](../reviews/account-deletion-admin-plan.md) §4 C1; review [`account-deletion-admin-c1-review.md`](../reviews/account-deletion-admin-c1-review.md). **PROD-DATA RISK: MEDIUM**
  (one additive migration with no backfill and no data write; no route reaches the claim, so nothing is erased or claimed by deploying; the behaviours that reach everyone are four narrow guards, each with an
  "ordinary creator is unchanged" spec).
  - **Migration (additive):** `2026_10_22_100000_add_erasure_claim_and_hold_to_users` (nullable `erasure_started_at`, `erasure_held_at`, and `erasure_held_by_user_id`, a nullable FK to `users` with `nullOnDelete`;
    `users` was 2,763 rows at read-back B; no default, no index, no backfill; `@migration-risk low`). Snapshot first, as for every deploy that carries a migration.
  - **Infra:** `php artisan queue:restart` (a changed job and a new listener); **no worker, supervisor, env or flag change in C1.** The worker and `database_erasure` connection are C3.
  - **Behaviour that reaches everyone, and its check:** (1) `erasure:run` now claims before it runs and refuses a not-due or never-requested account; (2) `VerifyPostedContentJob` returns early for a pending or erased
    creator (ordinary creators verify as before); (3) the agency manual verify answers 422 `creator.unavailable` for such a creator; (4) impersonation refuses a leaving target. With zero requests and the flag
    off, (2) to (4) cannot trigger. Post-deploy: `php artisan migrate:status` shows the migration; `php artisan erasure:run <test creator ulid>` (dry-run) prints `NOT READY: the account has not asked to be deleted` and exits 1.
  - **Rule that starts here (Q10): no deploy and no `queue:restart` while an erasure is claimed** (`select count(*) from users where erasure_started_at is not null and erased_at is null` = 0). Nothing can be claimed
    until C3 or a shell `--execute`, so the rule bites from the first real erasure.
  - **Not verified on Postgres:** the claim's row lock (sqlite ignores `lockForUpdate`); tech-debt "AH-104b-del Part A" item 4.
- **AH-104c C2 (the hold, the erasure queue, the admin read-through, the sheet's left edge) — BUILT 2026-10-25; local, not pushed, not deployed; awaiting Pedram's C2 review.** Plan
  [`account-deletion-admin-plan.md`](../reviews/account-deletion-admin-plan.md) §4 C2; review [`account-deletion-admin-c2-review.md`](../reviews/account-deletion-admin-c2-review.md). **PROD-DATA RISK: LOW to
  MEDIUM** (no migration: the hold columns arrived in C1; the claim now also revokes credentials, which touches `sessions` and `personal_access_tokens` of an account **only at a claim**, and nothing claims
  until C3 or a shell `--execute`; the hold endpoints write one audit row and one user column each, on a creator an admin chose; the queue and the read-through are reads).
  - **Migration:** none. **Infra:** `php artisan queue:restart` is not required (no job changed); a normal deploy of the API and both SPAs. **No env, flag, worker or supervisor change.** The admin SPA's
    `vite.config.ts` gained `VTabs` in the pre-bundle list (dev only).
  - **Behaviour that reaches everyone, and its check:** (1) five admin payloads (the creator list and detail, the app-usage list, push deliveries, the impersonation search) now carry `is_erased` and `deletion_pending` (additive keys; an
    admin SPA older than the API ignores them, a newer one reads them as false when absent); (2) an agency payload is unchanged and a spec says so; (3) the delete sheet's checkbox rules (creator app, CSS only); (4) the erasure **digest** no longer counts `sessions`, `personal_access_tokens` and `user_push_tokens` (still printed in the dry-run): a digest printed before this deploy is not valid after it, and none is outstanding because nothing has ever been erased.
    Post-deploy: open Compliance, Erasure queue: the six tabs load and the counts add up; the Pending tab lists exactly the creators with a standing request.
  - **Rule from C1 still applies (Q10):** no deploy and no `queue:restart` while an erasure is claimed.
  - **Not verified on Postgres:** the hold's row lock and the queue's range reads (sqlite ignores `lockForUpdate`); tech-debt "AH-104b-del Part A" item 4. **Not verified on the phone:** the sheet's alignment (see the review).
- **AH-104c C3 (the Erase button, its flag, its worker, and C2-4a) — BUILT 2026-10-25; local, not pushed, not deployed; awaiting Pedram's C3 review.** Plan
  [`account-deletion-admin-plan.md`](../reviews/account-deletion-admin-plan.md) §4 C3; review [`account-deletion-admin-c3-review.md`](../reviews/account-deletion-admin-c3-review.md). **PROD-DATA RISK: the
  HIGHEST of the arc, and ZERO at deploy:** the flag `account_erasure_execute_enabled` is **off**, so the endpoint answers 409 and nothing can be claimed or erased by this code. The risk event is the first click
  (C4), not this deploy.
  - **Migration:** none. **Env:** none (`QUEUE_CONNECTION` stays `database`; the job names its own connection). **Config:** `config/queue.php` gains the connection `database_erasure` (same `jobs` table).
  - **Infra, REQUIRED before the flag is ever turned on:** a **second supervisor program**, `catalyst-erasure-worker`, `numprocs=1`, `stopwaitsecs=3700`
    (`production-queue-worker.md` §2a): `reread`, `update`, `start`, and `supervisorctl status` must show it RUNNING. Without it the job waits in the `jobs` table. **`php artisan queue:restart` is required**
    for the deploy (the API gained a job and changed `AccountDeletionService`'s collaborators), **and the Q10 rule applies: not while an erasure is claimed.**
  - **Behaviour that reaches everyone, and its check:** (1) the creator's own deletion request now verifies the 2FA code through `SecondFactorStepUp` (a refactor with the same outcomes; its specs are unchanged);
    (2) an admin can no longer connect a creator who is leaving to an agency (422 `creator.unavailable`; an automation rule skips it); (3) the admin SPA gains the Erase dialog, the polling and ×24 strings.
    Post-deploy: open Compliance, Erasure queue; a Ready row shows "Erase…", the dialog loads a plan, and **clicking Erase answers "switched off"** (flag off). Check the Feature-flags page lists
    "Account erasure (admin Erase button)" as off.
  - **Not verified on Postgres:** the claim's row lock under a concurrent click, `afterCommit` ordering, the plan's reads. **Not measured:** the dry-run's duration on production data (tech-debt "AH-104c C3" item 1,
    a C4 precondition).
- **Migrations run:** AH-104a adds three (above); everything else in this entry: none. Recorded: **\_\_**
- **Pre-deploy reads:** AH-103d: the expired-token count (above). AH-104a: none.
- **Snapshot ID:** AH-104a's migrations are additive and the table `notifications` (288,083 rows at the read-back) is indexed concurrently, but **take and confirm a snapshot before migrating** (standing rule). The open snapshot question under AH-103 is unchanged. Recorded: **\_\_**
- **Infra:** **`php artisan queue:restart` is REQUIRED (AH-108, named step):** `PushMessageFactory` and `FcmPushSender` changed, and a worker holding the old classes keeps sending pushes with no `aps.badge`. A message queued by the previous release still sends safely (pinned by a spec). **API deploy:** AH-107 adds a read-only `campaign_status` / `campaign.status` field on four creator endpoints; AH-108 adds `message_unread_count` on `GET /api/v1/me/notifications/unread-count`. No migration, no env change, no cron, no flag. Either deploy order is safe (an older backend sends no status and no message count; the SPA renders no chip and the tab reads 0; an older app ignores the new fields). **AH-103b (web build only, no API change):** the build now publishes `/.well-known/apple-app-site-association` and a one-path `_headers`; both are in `dist/` after `vite build` (verified). **Nothing is configured in the Netlify dashboard unless C1 or C2 fails** (a dashboard rule shadowing the file is the one thing the repo cannot rule out). The SPA fallback is a dashboard rule, not in the repo. **TestFlight build 3 must wait for C1–C7** (below), and must be archived with Associated Domains in its distribution profile (read the archive's entitlements for `applinks:platform.catalyst-growth.com` before uploading). Build 2 testers are unaffected: a creator link keeps opening Safari for them. **AH-109 (API deploy, a Blade view):** the job-posted email footer now names the agency instead of showing a literal `:agency`; no migration, no env change, covered by the same `queue:restart` (the worker renders the mail).
- **One-shot commands:** **AH-108 backlog command, run right after `queue:restart`:** `php artisan notifications:clear-read-message-notifications`.
  Every message notification that exists in production today is unread unless the user clicked it, because opening a thread never touched it
  before this release; without this the first badge a creator sees would be a large false number. **Order: `--dry-run` first, then REPORT THE
  COUNT TO PEDRAM, and only after he has seen it, the real run.** Both counts are recorded here.
  - **Dry-run count:** **\_\_** (rows that would be marked read) · run by: **\_\_** · when: **\_\_**
  - **Reported to Pedram:** yes / no · his go-ahead: **\_\_**
  - **Real-run count:** **\_\_** (rows marked read) · matches the dry-run? yes / no · run by: **\_\_** · when: **\_\_**
- **Post-deploy verification:** web: open the notifications page from the bell and confirm the back arrow returns; apply to a job with a long title at phone width and confirm the Applied chip shows its full label. **AH-107:** on the invitations list at phone width, confirm every card shows a campaign chip (Upcoming / Active / Paused / Completed / Cancelled) and that a long-title card's "Contracted" chip is not cut; pause a test campaign from its Settings tab and confirm the creator's assignment detail shows "Paused" and the banner with the draft form still usable; confirm a paused job shows the Paused chip on the job board. Native: the same checks on build 3. **AH-108:** after `queue:restart` and the backlog command, sign in as a creator with old message notifications and confirm the bell and the Messages tab no longer show the old backlog (a small honest number, or nothing); have the agency send one message in a campaign thread and one in the relationship thread and confirm bell and tab read 2 and, on build 3, the icon reads 2; open one thread and confirm the tab and bell drop by one and the icon follows; confirm a push sent after deploy shows the badge (`push_deliveries` row, in-app row Read after opening). On build 2 devices expect in-app badges only.
- **AH-103b web-deploy checks C1–C7 (BUILT; the colleague runs them during the deploy and sends the output to Pedram, who forwards it so the result is recorded here).**
  Run from any machine against the live host, no login needed. Before this deploy both `.well-known` URLs answer `200 text/html` (the
  SPA's fallback, 532 bytes), so a failing C1 is the expected "before" state. Source: [`universal-links-plan.md`](../reviews/universal-links-plan.md) §6.

  ```bash
  H=https://platform.catalyst-growth.com
  A=$H/.well-known/apple-app-site-association

  # C1 — the static file wins over the SPA fallback (HTTP 200, JSON, NOT index.html)
  curl -sS -D - -o /tmp/aasa.json "$A" | head -12      # expect: HTTP/2 200, no `location:` header
  head -c 1 /tmp/aasa.json                             # expect: {   (a `<` means the fallback answered: FAIL)
  jq . /tmp/aasa.json >/dev/null && echo "C1 valid JSON"

  # C2 — _headers is honoured (content type on that one path)
  curl -sS -o /dev/null -w '%{content_type}\n' "$A"     # expect: application/json

  # C3 — no redirect on the file (Apple does not follow redirects for it)
  curl -sS -o /dev/null -w '%{http_code} redirects=%{num_redirects} url_effective=%{url_effective}\n' "$A"
  #   expect: 200 redirects=0 url_effective=<the same URL>

  # C4 — the content is exactly what the app expects
  jq -e '.applinks.details[0].appIDs[0]=="LTW9262ZXP.com.catalystgrowth.platform"
         and .applinks.details[0].components[0]["/"]=="/creator/*"
         and (.applinks.details|length)==1' /tmp/aasa.json          # expect: true

  # C5 — assetlinks.json is still NOT published (R3): the SPA fallback must still answer it
  curl -sS -o /dev/null -w '%{http_code} %{content_type}\n' "$H/.well-known/assetlinks.json"
  #   expect: 200 text/html   (application/json here means a placeholder was published: STOP)

  # C6 — the headers rule did not leak, and the site still works
  curl -sS -o /dev/null -w '%{http_code} %{content_type}\n' "$H/"          # expect: 200 text/html
  curl -sS -o /dev/null -w '%{http_code} %{content_type}\n' "$H/sign-in"   # expect: 200 text/html
  curl -sS -o /dev/null -w '%{http_code} %{content_type}\n' "$H/creator/jobs/01ZZZZZZZZZZZZZZZZZZZZZZZZ"   # expect: 200 text/html

  # C7 — Apple's CDN view of the file (URL form and lag unverified)
  curl -sS "https://app-site-association.cdn-apple.com/a/v1/platform.catalyst-growth.com" | jq -c .
  #   expect: the same JSON as C4. It can lag the origin; record when it matches.
  ```

  **Results (blank until the colleague's output is forwarded):**
  - **C1** `200`, JSON, not HTML: **\_\_** · **C2** `application/json`: **\_\_** · **C3** `200 redirects=0`: **\_\_** · **C4** `true`: **\_\_**
  - **C5** `200 text/html` (still not published): **\_\_** · **C6** three `200 text/html`: **\_\_** · **C7** Apple's copy matches: **\_\_** (when: **\_\_**)
  - Run by: **\_\_** · when: **\_\_** · forwarded by Pedram: **\_\_**

  **If C1 or C2 fails** (HTML or the wrong type): a Netlify dashboard rule is shadowing the file, or `_headers` is not published. **Do not
  release build 3**; report back. The fix is in the dashboard (exempt the path from the fallback rule, or set the header there), not in
  the repo. **If C3 fails** (a 3xx): same, and say which hop (a domain-level redirect would also break the file). **If C5 fails: stop**, a
  placeholder `assetlinks.json` was published, which the ruling forbids. **Gate for build 3:** C1–C6 pass and C7 matches. A tester who
  installs build 3 before the file is live has no links until they update or reinstall. Order: **web deploy, then C1–C7, then build 3.**

- **AH-103b device walk (after build 3 from TestFlight):** W1–W10 on a real iPhone, in [`universal-links-plan.md`](../reviews/universal-links-plan.md) §7: a
  creator link opens the app (backgrounded and killed), a signed-out tap returns after sign-in, `/auth/*` and agency links stay in Safari,
  `/creator/nonsense` opens the dashboard, a real emailed link from the Mail app, a regression check on push taps and the icon badge.
  **Not run.** Android: an explicit-intent check only (it proves the handler, not verification; `pm get-app-links` is expected to show the
  domain unverified). Result: **\_\_**
- **AH-109 check (after the API deploy):** the next job-posted email shows "…you are on the **<agency name>** roster" in its footer, not `:agency`. Result: **\_\_**
- **Flags armed:** none.
- **Source:** pushed to GitHub `origin/main` 2026-10-17 (tip then `54075f02`; CI green on all four jobs, [run 39046559682](https://github.com/pedram-kh/Engine/actions/runs/39046559682)). **A push is not a deploy:** the colleague mirrors GitHub → Bitbucket and deploys from there (API and both SPAs); the mirror and deploy have not happened. **Second push 2026-10-19:** `54075f02..222b0d39` (AH-107 and AH-108, 19 commits), then the docs close-out `fe39bc82`. CI on the push of `222b0d39` ([run 39185216676](https://github.com/pedram-kh/Engine/actions/runs/39185216676)) was **cancelled** by the next push (its frontend and backend jobs had passed; its E2E jobs never finished). **CI on the tip `fe39bc82` is green on all four jobs** ([run 39185656319](https://github.com/pedram-kh/Engine/actions/runs/39185656319)); that tip contains AH-106, AH-107 and AH-108. **AH-103b** was pushed on top of it on 2026-10-19 (after `6ee14c36`, which carried its plan); pushed as `6ee14c36..5e0fa6d9`; **CI on `5e0fa6d9` is green on all four jobs** ([run 39216620050](https://github.com/pedram-kh/Engine/actions/runs/39216620050); main E2E 38 passed, admin E2E 2). AH-109 (the footer fix, `c5600dc3`) followed in a later push; its CI result is the tip of the next entry in the Resumption.
- **Operator:** not yet.
- **Anything unexpected:** nothing yet. **Retired 2026-10-18:** the earlier known gap (the AH-106 chip's Playwright leg had not been run) is closed: CI on the pushed AH-106 tip was green on all four jobs, including that leg ([run 39046559682](https://github.com/pedram-kh/Engine/actions/runs/39046559682)). Known gaps carried: no device walk for AH-106 or AH-107; AH-107's `mt`/`ro` creator-visible status strings are unreviewed MT (see tech debt). Known gaps carried from AH-108: the production-APNs proof (TestFlight build 3, walk items 1–5 again) and the Android check were not run; one unexplained black screen on a first Xcode Run (not reproduced); the new creator aria strings are unreviewed MT. Tech-debt rows are in the AH-108 section of `tech-debt.md`.

---

## AH-103 — deep links: a notification opens the screen it is about — DEPLOYED

> **Deployed 2026-10-12** (reported by Pedram; see the Deploy record below). The note under it is the pre-deploy text, kept as written. **Pending after the deploy:** the first TestFlight build (below).

> **Stacks on AH-102b and everything under it (AH-099 … AH-102b, all PENDING).** Pushed 2026-10-12; **not deployed**; deploy timing
> is Pedram's call. Review: [`deep-links-review.md`](../reviews/deep-links-review.md).

- **Status:** DEPLOYED (2026-10-12, one sitting for AH-099 → AH-103; as reported by Pedram, not read from the server by the assistant; see the Deploy record below). Before the deploy it read: PENDING (**pushed 2026-10-12** to `origin/main` at `47d2efc5`, CI **green on all four jobs**: [run 38289855211](https://github.com/pedram-kh/Engine/actions/runs/38289855211); not deployed)
- **Range:** base `cb79abfa` (`origin/main` before the push) → tip **`47d2efc5`**, 20 commits (`8378e4e5` … `47d2efc5`; the list is in the
  review §1 and the ad-hoc log).
- **AH entries carried:** AH-103
- **Migrations run:** **none.** No schema change.
- **Pre-deploy reads:** none of its own. **Production read-backs, 2026-10-12 (Pedram, `docker compose exec app sh` at `/app`):** `supervisorctl`: `laravel-queue` RUNNING (`queue:work`, not Horizon), `laravel-schedule` RUNNING (`schedule:work`, up 41 days), cron / nginx / php-fpm / syslog RUNNING, postfix / sshd / dnsmasq STOPPED. `queue.default` = `database`; `cache.default` = `redis`; `mail.default` = `smtp` (smtp.postmarkapp.com). `user_notification_preferences` with `channel='push'` = **0**. Preference rows: `digest/true` 19, `in_app/false` 15, `email/false` **none**. `integrations.social.driver` = `mock`; `features.social_verification_enabled` stored `"true"` (created 2026-07-14 07:58:21). `migrate:status`: last run `2026_08_27_140000_create_campaign_attachments_table` (batch 8); nothing from AH-099 onward has run. Relevant here: the queue is `database` (the `queue:restart` step is unchanged). Merged checklist for AH-099 → AH-103: [`deploy-ah099-ah103.md`](deploy-ah099-ah103.md).

**What goes live on deploy**

- **Backend:** the push payload gains additive, ULID-only **route keys** on a per-type allowlist (`PushMessageFactory`); `campaign_ulid`
  is added to the `data` bag of **new** in-app rows at three emit sites (`campaign.job_posted`, the agency leg of
  `message.received_by_agency`, `campaign_application.submitted`). No existing row is rewritten. `v` stays `1`.
- **Creator app (native build):** a tap on a push or on a notification row opens its destination; a revoked or lapsed session returns to
  the target after sign-in; a signed-out thread tap lands on the list after sign-in (accepted degradation); the not-found assignment links
  back to the list; **the push token is re-registered on every sign-in** (the AH-102 defect fix, one extra idempotent `POST` per cold
  start). A native release is needed to ship the app side; the web build ships with the normal front-end deploy.
- **Agency web:** the bell opens a campaign on the right tab (`?tab=`).
- **No new route, no preference-contract change.**

**Named deploy steps**

1. **Either order is safe** (plan §0.4): old backend + new app, pushes carry no route keys and the tap falls back to the list; new
   backend + old app, the extra keys are ignored. Neither produces a dead tap.
2. **`php artisan queue:restart`** — a named step: `PushMessageFactory` changed, and a worker holding the old class map would keep
   building payloads without route keys until it restarts.
3. PHP-FPM reload (opcache), then `/up` and one authenticated request. **Result:** reload done (reported); the `/up` code not read; web login OK for a creator and an admin (reported).
4. **A real push, if the flag is already armed:** emit one for an id-bearing type, tap it on a device, and read the landing screen.
   **Result:** not done: no registered token exists and no native build is out.

- **Infra:** `queue:restart` (required); no cron, scheduler or env change.
- **One-shot commands:** none.
- **Post-deploy verification:** `/up`; one authenticated request; one real tap if a device and the flag allow. **Then, on the first
  TestFlight build after this deploy** (next to the production-APNs proof): sign in on the iPhone, confirm a new `user_push_tokens` row
  exists for it, and confirm a push is delivered (AH-103 finding 2 was device-verified on the Samsung only). **Result: DONE 2026-10-13 (~03:03, reported by Pedram):** TestFlight 1.0.0 (1) signed in and registered (admin Creator app page: iOS, app 1.0.0, Push registered); one real push delivered; the tap opened the right thread (see the TestFlight record in the Deploy record below).
- **Flags armed:** none by this chunk. The push flag was armed ON in the same sitting (AH-102's entry); the read-back is **not read**.
- **Deploy record, 2026-10-12: shared by AH-099 … AH-103 (one sitting).** _Source: Pedram's report of what his colleague did ("his usual procedure plus this release's extras"). Nothing here was read from the server by the assistant. A read that was not reported is written **not read**, never "passed"._
  - **Snapshot:** reported taken before the deploy. ID as given: [`snapshot-2026-08-19`](https://717248306247-f5u6bh2m.eu-west-1.console.aws.amazon.com/rds/home?region=eu-west-1#db-snapshot:engine=aurora-postgresql;id=snapshot-2026-08-19) (an Aurora PostgreSQL cluster snapshot, `eu-west-1`). **The ID is dated 2026-08-19, not 2026-10-12.** Its creation time, and whether it was `available` before migrating: **not read.** Until its creation time is read in the console, **do not treat it as a restore point for tonight's migrations** (anything written since it was taken would not be in it). Aurora's continuous backup / point-in-time window: **not read.**
  - **Code:** `composer install` skipped (as planned: `composer.json` and `composer.lock` unchanged since `9b7d6deb`); `pnpm install` run and both SPAs (main, admin) rebuilt. On-box `git rev-parse HEAD`: **not recorded.** The range is `9b7d6deb` → runtime tip `47d2efc5` (AH-103's last runtime commit); the three commits after it (`f932eda4`, `702e2a06`, `8228b803`) are docs-only, so a pull of `origin/main` at any time after `47d2efc5` runs the same runtime. How the bundles were published to nginx: **not read** (the admin showing the two new pages is evidence that the admin bundle is being served).
  - **Migrate:** the two expected tables only, `user_push_tokens` and `push_deliveries` (reported). `migrate:status` afterwards (the batch number): **not read.**
  - **Env:** `FRONTEND_EXTRA_ORIGINS` appended; `FCM_PROJECT_ID` and `FCM_SERVICE_ACCOUNT_JSON_B64` set (reported). The resulting `FRONTEND_EXTRA_ORIGINS` value, a `SANCTUM_STATEFUL_DOMAINS` read, and how the secret was transferred: **not read / not reported.**
  - **Restarts:** `config:cache`, PHP-FPM reload and `queue:restart` done (reported). The reload method, and the worker's uptime afterwards: **not read.**
  - **Reported passing:** `push:test --validate-only` → "Credential OK"; `schedule:list` includes `push:prune-deliveries`; `APP_KEY` fingerprint unchanged before and after; web login OK (creator and admin); the admin shows **Push deliveries** and **Creator app**; `post_verified_mail_enabled` OFF.
  - **Flags:** `push_notifications_enabled` **armed ON** in the admin; `social_verification_enabled` unchanged (the Task 1 recommendation, "confirm harmless, no flag change"); `post_verified_mail_enabled` OFF. The read-back after arming (`Feature::active` and the stored `features` row) and the reason text: **not read.** Arming is inert today: `user_push_tokens` is new and no native build that registers tokens is out (its row count before arming: **not read**).
  - **Not read (so not "passed"):** the P0 discovery reads; the AH-099 pre-deploy reads (`personal_access_tokens` `Ran` and count, `SANCTUM_STATEFUL_DOMAINS` free of a bare `localhost`, `AUTH_GUARD`); the **P6 Task 1 reads** (posts stuck `pending`, `live_verified` counts; **the condition on which the Task 1 recommendation was accepted is therefore still open**); **P7** (no second `schedule:run` cron on the container or the host) and `supervisorctl status laravel-schedule` after the deploy; the **12 GRANT read-backs** on `user_push_tokens`, `push_deliveries` and their id sequences for `engine_c_user`; `push:prune-deliveries --dry-run`; the `/up` status code; `push_deliveries` after arming; the token-door smoke (unknown email → `401`, count still `0`); platform-admin refusal on the main host; a creator `GET /api/v1/me` on a cookie; the AH-101 preferences page's Email column; AH-100d's `ro` nav label; the agency bell's campaign tab; a log grep for `push.config_error`.
  - **Deferred by Pedram, 2026-10-13 (not blocking the TestFlight build):** the post-deploy read-backs, namely the GRANT reads (the 12 on `user_push_tokens` and `push_deliveries`), a fresh snapshot (and the creation time of the reported one), the `SANCTUM_STATEFUL_DOMAINS` read, and the cron / second-scheduler read (P7), wait until the colleague who runs the box is back. They stay **not read** until then.
  - **Read from outside the box, 2026-10-13 (no login; assistant):** the SPAs are **not** served by the container's nginx: `platform.catalyst-growth.com` CNAMEs to `catalyst-engine-main.netlify.app` and `admin.catalyst-growth.com` to `unique-taffy-3d2553.netlify.app` (both `server: Netlify`). The API is **`https://platform-api.catalyst-growth.com`** (the host the live main bundle has baked in as its base URL; Laravel behind `nginx/1.28.3`, valid TLS, `GET /up` → 200, an unauthenticated `GET /api/v1/me` → `401 {"message":"Unauthenticated."}`). A CORS preflight (`OPTIONS /api/v1/auth/token`) from `Origin: capacitor://localhost` and from `https://localhost` is answered `204` with that origin allowed and `Access-Control-Allow-Credentials: true`; a random origin gets no allow header. That is outside evidence that the `FRONTEND_EXTRA_ORIGINS` change is live (the resulting env value itself is still **not read**). How the Netlify sites are deployed (git-connected or manual) is **not read**. **Closed 2026-10-17 (reported by Pedram):** deploys come from a **Bitbucket mirror** of GitHub, run by the colleague for the API and both SPAs; GitHub `main` is not the deploy source and a push never deploys ([`production-queue-worker.md` §8.0](production-queue-worker.md)).
  - **TestFlight 1.0.0 (1) verified on production, 2026-10-13 ~03:03 (reported by Pedram from the phone and the admin; not read from the server by the assistant).** Build: `pnpm build:native` against `https://platform-api.catalyst-growth.com/api/v1` (the bundle was checked for that URL and for no `localhost:8000`), Firebase `catalyst-production-c28e9`, version 1.0.0 build 1, `ITSAppUsesNonExemptEncryption` NO, Sentry DSN empty (accepted for TestFlight).
    - **Reported:** admin **Creator app** page shows iOS sign-in live, app 1.0.0, **Push registered**. `push_deliveries`: **1 row**, `message.relationship_received_by_creator`, iOS, **Delivered**, attempt 1, provider `200`, in-app notification **Read**. On the phone the **banner was shown** and the **tap opened the right thread**.
    - **Closes** AH-102 open item 1 (the production-APNs proof) and open item 4 (the AH-103 finding-2 re-registration on the iPhone). Also settles the earlier "after the first real push `push_deliveries` must be `> 0`" check (now 1 row) and the "no registered token exists" caveat on AH-103's real-tap step (a real tap was done, on the iPhone).
    - **GRANTs: proven in practice for `INSERT` only** (a row was written to both `user_push_tokens` and `push_deliveries`, so a missing `INSERT` grant is ruled out for `engine_c_user`). The formal **12-line read** (including SELECT/UPDATE/DELETE and the two id sequences, `USAGE`/`SELECT`) is **still not read**.
    - **Still not read, deferred until the colleague is back:** the 12 GRANT lines, a fresh snapshot (and the reported one's creation time), `SANCTUM_STATEFUL_DOMAINS`, and the cron / second-scheduler read (P7). The `apns` side of the Firebase console (the `.p8` upload) was not read, but a production delivery with provider `200` makes it moot.
    - **Still open from the AH-102 list:** item 2 (token-rotation re-POST, accepted) and item 3 (item 10 on iOS: `UNREGISTERED` after an uninstall; not reported in this run).
  - **Worth reading next (read-only):** the snapshot's creation time (console); the 12 GRANT reads (`INSERT` is now proven by the TestFlight rows; SELECT/UPDATE/DELETE and the sequences are not); P7 (a second scheduler would double every digest); `SANCTUM_STATEFUL_DOMAINS` before the first Android build; the P6 queries.
- **AH-103 specifics.** Reported: `queue:restart` and the PHP-FPM reload done; creator and admin web login OK. **Not done, by design:** a real push tap (no registered token exists and no native build is out). **Not read:** the agency bell's campaign tab, the `/up` code. The iPhone finding-2 re-verify is still planned for the first TestFlight build (below).
- **Operator:** Pedram's colleague, with his usual procedure plus this release's extras (reported by Pedram). The record is below.
- **Anything unexpected:** **Yes, one item to check: the snapshot ID.** The ID reported for a snapshot taken before this deploy is dated 2026-08-19 (see the Deploy record); its creation time was not read, so tonight's migrations may have no restore point. Everything else reported was as planned; the long **not read** list above is the honest state, not a failure.

---

## AH-102b — admin observability for the creator app: push delivery log, two admin pages — DEPLOYED

> **Deployed 2026-10-12** (reported by Pedram; see the Deploy record below). The note under it is the pre-deploy text, kept as written.

> **Stacks on AH-102 (Checkpoints A and B) and everything under it (AH-099 … AH-102, all PENDING).** Pushed 2026-10-12 after
> Checkpoint B clearance and Pedram's go; **not deployed**; deploy timing is Pedram's call. Review:
> [`admin-observability-review.md`](../reviews/admin-observability-review.md).

- **Status:** DEPLOYED (2026-10-12, one sitting for AH-099 → AH-103; as reported by Pedram, not read from the server by the assistant; the shared record is in the **AH-103** entry). Before the deploy it read: PENDING (**pushed 2026-10-12** to `origin/main` at `176f46fc`, CI **green on all four jobs**: [run 38199714770](https://github.com/pedram-kh/Engine/actions/runs/38199714770); not deployed)
- **Range:** base `b21b7b2f` (`origin/main` at plan time) → tip **`176f46fc`** (a docs-only closing commit follows). Commits: `65d2b306`, `e4f7bac7`, `9bf80e41`,
  `30b5894c`, `9197cd0b`, `686d653b`, `c67d9505`, `03411f16`, `b64beee8`, `4f4659fa`, `53e32e7a`, `61627374`, `d95988ea`,
  `eea2e89e`, `ff97a781` (docs), `3f8c97a1` (type-filter select), `176f46fc` (docs).
- **AH entries carried:** AH-102b
- **Migrations run:** **1** — `2026_10_12_100000_create_push_deliveries_table` (`CREATE TABLE` only, additive; one FK to `users`
  with cascade; three indexes; no data writes, no change to an existing table). `down()` drops the table (a log of attempts,
  retention is 90 days anyway).
- **Pre-deploy reads:** none beyond the earlier entries'. **Production read-backs, 2026-10-12 (Pedram, `docker compose exec app sh` at `/app`):** `supervisorctl`: `laravel-queue` RUNNING (`queue:work`, not Horizon), `laravel-schedule` RUNNING (`schedule:work`, up 41 days), cron / nginx / php-fpm / syslog RUNNING, postfix / sshd / dnsmasq STOPPED. `queue.default` = `database`; `cache.default` = `redis`; `mail.default` = `smtp` (smtp.postmarkapp.com). `user_notification_preferences` with `channel='push'` = **0**. Preference rows: `digest/true` 19, `in_app/false` 15, `email/false` **none**. `integrations.social.driver` = `mock`; `features.social_verification_enabled` stored `"true"` (created 2026-07-14 07:58:21). `migrate:status`: last run `2026_08_27_140000_create_campaign_attachments_table` (batch 8); nothing from AH-099 onward has run. **Relevant here:** the scheduler is `schedule:work` under supervisor, **not cron**, so step 6's `crontab -l` read does not apply: read `supervisorctl status laravel-schedule` and `php artisan schedule:list` instead. `schedule:work` starts a fresh `schedule:run` every minute, so the new `push:prune-deliveries` entry needs **no scheduler restart**.

**What goes live on deploy**

- A row in `push_deliveries` for every push send attempt, **only once `push_notifications_enabled` is armed** (the job, and so
  the recorder, is not reached with the flag OFF).
- `GET /api/v1/admin/push-deliveries`, `/summary`, `GET /admin/creator-app/users`, `/users/{creator}/sign-ins` (platform admins
  only; read-only); two admin SPA pages, **Operations → Push deliveries** and **Creators → Creator app**.
- `push:prune-deliveries`, scheduled `->daily()`.
- **No existing behaviour changes:** the recorder's insert is contained, and `PushTokenRegistry`'s liveness SQL is byte-identical.

**Named deploy steps (in order)**

1. **Snapshot** taken and confirmed `available` (migration in this deploy). **Snapshot ID:** [`snapshot-2026-08-19`](https://717248306247-f5u6bh2m.eu-west-1.console.aws.amazon.com/rds/home?region=eu-west-1#db-snapshot:engine=aurora-postgresql;id=snapshot-2026-08-19) as reported; dated 2026-08-19, creation time and `available` **not read** (see the AH-103 Deploy record).
2. `php artisan migrate` (one migration), then the code.
3. **`php artisan queue:restart`** — **a named step**: `PushDeliveryOutcome` and `PushDeliveryRecorder` changed, and a worker
   holding the old class map would run the old recorder.
4. PHP-FPM reload (opcache), then `/up` and one authenticated request.
5. **`engine_c_user` GRANT**, **in this order**: grant `SELECT, INSERT, UPDATE, DELETE` on `push_deliveries` and `USAGE, SELECT`
   on `push_deliveries_id_seq`, then read both back: `SELECT has_table_privilege('engine_c_user', 'push_deliveries',
'SELECT,INSERT,UPDATE,DELETE');` and `SELECT has_sequence_privilege('engine_c_user', 'push_deliveries_id_seq', 'USAGE');`
   → both must be `true`. The snapshot in step 1 covers both migrations. **Result:** **not read** (the GRANT read-backs were not reported).
6. **Scheduler read-back** (the prune relies on it): `crontab -l` shows `schedule:run` for the app user; `php artisan
schedule:list` lists `push:prune-deliveries`. **Result:** `schedule:list` includes `push:prune-deliveries` (reported). The `crontab -l` read does not apply (the scheduler is supervisor `schedule:work`); `supervisorctl status laravel-schedule` after the deploy: **not read**.
7. **Prune dry-run:** `php artisan push:prune-deliveries --dry-run` → **expected `would delete 0`** (the table is empty).
   **Result:** **not read**.

- **Infra:** `queue:restart` (required); one new scheduler entry (needs the cron that `docs/runbooks/production-queue-worker.md`
  §7 describes); no env change.
- **One-shot commands:** the prune dry-run above; nothing mutates.
- **Post-deploy verification:** `/up`; the admin sidebar shows the two new entries; **Push deliveries** loads (empty, footer
  "No push attempts stored yet."); **Creator app** loads.
- **Flags armed:** none by this entry. `push_notifications_enabled` was armed ON in the same sitting (AH-102 Checkpoint A's entry); read-back **not read**.
- **Arming addendum (when `push_notifications_enabled` is armed later):** `push:test` **bypasses the recorder** (it calls the sender directly, by design), so it will never write a row. After a **real** delivered push, an **empty `push_deliveries`** means a missing
  GRANT or a recorder failure: look for `push.delivery_record_failed` in the log (it carries the step and the exception class,
  never the message). The Push deliveries footer (total rows and oldest row) is also the scheduler's instrument: an "oldest"
  date older than the retention window means the prune is not running.
- **Deploy record (2026-10-12; the shared record is in the AH-103 entry).** Reported: migration `2026_10_12_100000_create_push_deliveries_table` ran (the `push_deliveries` table, no other change); `queue:restart` and the PHP-FPM reload done; `schedule:list` includes `push:prune-deliveries`; the admin shows **Push deliveries** and **Creator app**. **Not read:** the `GRANT` read-backs for `push_deliveries` and `push_deliveries_id_seq` (a missing grant would show only when the first real push is recorded); the prune dry-run; whether the pages load empty with the footer "No push attempts stored yet."; `supervisorctl status laravel-schedule`. After the first real push `push_deliveries` must be `> 0`.
- **Operator:** Pedram's colleague, with his usual procedure plus this release's extras (reported by Pedram). The shared record is in the **AH-103** entry.
- **Anything unexpected:** Nothing reported. See the AH-103 Deploy record for what was not read; for this entry the GRANT read-backs matter most.

## AH-102 (Checkpoint A, backend) — push notifications: token table, FCM sender, dispatcher — DEPLOYED

> **Deployed 2026-10-12** (reported by Pedram; see the Deploy record below). The note under it is the pre-deploy text, kept as written.

> **Stacks on AH-101 and everything under it (AH-099 … AH-101, all PENDING).** Pushed at Checkpoint A clearance, **not
> deployed**; deploy timing is Pedram's call. This entry covers **backend only** (Stages 0–2). The matrix
> column, the app's push module and the Android build (Stages 3–4), and iOS (**AH-102-ios**) come later and get
> their own entries.

- **Status:** DEPLOYED (2026-10-12, one sitting for AH-099 → AH-103; as reported by Pedram, not read from the server by the assistant; the shared record is in the **AH-103** entry). Before the deploy it read: PENDING (pushed, not deployed)
- **Range:** base `4d468406` (`origin/main` at plan time, pushed, not deployed) → tip **TBD at push** (the commits are
  `faf96d4d`, `1f7d2de0`, `93b7d767`, `ff0a4112`, `2f3d62eb`, `37d482d9`, `6ff737d9`, `238fa85b`, `cdfa13e6`,
  `68ffb21c`, `72204f1d`, `a8423726`, `8c7d3318` [R2 rider: a dev-server-only Vite config change in `apps/main`], plus the docs commits). The deploy range also carries the undeployed
  AH-099 … AH-101 chain.
- **AH entries carried:** AH-102 (backend half)
- **Migrations run:** **1** — `2026_10_10_100000_create_user_push_tokens_table` (`CREATE TABLE` only; FKs _to_ `users`
  and `personal_access_tokens`; no data writes, no change to an existing table). Tagged `@migration-risk low`; its
  `down()` drops the table and says so (data loss of registered tokens — they re-register on next app launch).

**What goes live on deploy**

- `POST` / `DELETE /api/v1/me/push-tokens` (creator-app bearer only; **registration is not behind the flag**, by
  design, so a build that registers before arming still works).
- The `push` channel on `GET` / `PATCH /me/notification-preferences`, and a `type_defaults` block in the response.
  **Existing clients are unaffected:** they ignore the new key, and the SPA does not render a push column until
  Stage 3 ships (backend-first order: the API accepts `push` before any client sends it).
- `notify()` restructure: the in-app early return is gone and the push leg runs on both paths. With the flag OFF the
  leg returns before touching the database for ineligible types and after a single flag read for eligible ones.
- `push:test` artisan command; the `push_notifications_enabled` flag (**OFF**).

**What is gated OFF**

- **`push_notifications_enabled` ships OFF.** Do **not** arm until: the FCM credential is present, `push:test
--validate-only` passed on the server, the Android device proof passed, and the arming is timed **before** the first
  push-capable store build reaches users (so their first sessions' registered tokens are not stranded).

**Named deploy steps (in order)**

1. **Pre-deploy reads (Pedram):**
   - Worker + queue connection: `supervisorctl status` shows `queue:work` (not Horizon); effective `QUEUE_CONNECTION`.
     **Result (2026-10-12, Pedram):** `laravel-queue` RUNNING as `queue:work`, not Horizon; effective `queue.default` = **`database`**.
   - `CACHE_STORE` effective value (the push throttle needs an atomic `Cache::add`; the OAuth token cache needs it too).
     **Result (2026-10-12, Pedram):** `cache.default` = **`redis`** (atomic).
   - `SELECT count(*) FROM user_notification_preferences WHERE channel='push';` → **expected `0`.** **Result (2026-10-12, Pedram):** **0**.
   - **FCM credential:** `FCM_PROJECT_ID` is set, and `FCM_SERVICE_ACCOUNT_JSON_B64` **is set and decodes to valid JSON**
     carrying `client_email`, `private_key` and `project_id` — read back **without printing the value**. (`push:test
--validate-only` performs exactly this decode and then the OAuth exchange.) **Result (2026-10-12):** `FCM_PROJECT_ID` and `FCM_SERVICE_ACCOUNT_JSON_B64` set during the deploy (reported); `push:test --validate-only` → "Credential OK" (reported). The variable itself was never read back.
   - **Production read-backs, 2026-10-12 (Pedram, `docker compose exec app sh` at `/app`):** `supervisorctl`: `laravel-queue` RUNNING (`queue:work`, not Horizon), `laravel-schedule` RUNNING (`schedule:work`, up 41 days), cron / nginx / php-fpm / syslog RUNNING, postfix / sshd / dnsmasq STOPPED. `queue.default` = `database`; `cache.default` = `redis`; `mail.default` = `smtp` (smtp.postmarkapp.com). `user_notification_preferences` with `channel='push'` = **0**. Preference rows: `digest/true` 19, `in_app/false` 15, `email/false` **none**. `integrations.social.driver` = `mock`; `features.social_verification_enabled` stored `"true"` (created 2026-07-14 07:58:21). `migrate:status`: last run `2026_08_27_140000_create_campaign_attachments_table` (batch 8); nothing from AH-099 onward has run.
2. **Snapshot** taken and confirmed `available` (migration in this deploy). **Snapshot ID:** [`snapshot-2026-08-19`](https://717248306247-f5u6bh2m.eu-west-1.console.aws.amazon.com/rds/home?region=eu-west-1#db-snapshot:engine=aurora-postgresql;id=snapshot-2026-08-19) as reported; dated 2026-08-19, creation time and `available` **not read** (see the AH-103 Deploy record).
3. `php artisan migrate` (one migration), then the code.
4. **`php artisan queue:restart`** — **a named step**: this deploy adds a job class (`SendPushNotificationJob`), and a
   worker holding the old class map would fail it.
5. PHP-FPM reload (opcache), then `/up` and one authenticated request.
6. **`engine_c_user` GRANT check** (post-migrate, pre-arm): `SELECT has_table_privilege('engine_c_user',
'user_push_tokens', 'SELECT,INSERT,UPDATE,DELETE');` → must be `true`, and `USAGE` on the id sequence. Default
   privileges may not cover new tables (the history is not reconstructable), so this is checked, not assumed.
   **Result:** **not read** (the GRANT read-backs were not reported).
7. **Smoke:** `php artisan push:test --validate-only` → "Credential OK". **Result:** "Credential OK" (reported).

- **Infra:** `queue:restart` (required); new env vars `FCM_PROJECT_ID`, `FCM_SERVICE_ACCOUNT_JSON_B64` (the service-account file
  on Pedram's laptop, not Secrets Manager); no cron / scheduler change.
- **One-shot commands:** none that mutate (`push:test` is read-only against our data).
- **Post-deploy verification:** `/up`; the admin Feature-flags page lists `push_notifications_enabled` OFF; a creator's
  `PATCH /me/notification-preferences` with `channel: push` is accepted; `push:test --validate-only` passes.
- **Flags armed:** `push_notifications_enabled` was **armed ON** in the admin during this deploy (reported; it shipped OFF). The read-back (`Feature::active`, the stored row) is **not read**. Inert today: `user_push_tokens` is new and empty.
- **Deploy record (2026-10-12; the shared record is in the AH-103 entry).** Reported: migration `2026_10_10_100000_create_user_push_tokens_table` ran (the `user_push_tokens` table); `FCM_PROJECT_ID` and `FCM_SERVICE_ACCOUNT_JSON_B64` set; `queue:restart`, `config:cache` and the PHP-FPM reload done; `push:test --validate-only` → "Credential OK"; `push_notifications_enabled` armed ON in the admin (so the Feature-flags page lists it). **Not read:** the `GRANT` read-backs on `user_push_tokens` and its id sequence; the `PATCH /me/notification-preferences` push-channel check; the `user_notification_preferences` `channel='push'` count after the deploy; `user_push_tokens` count before arming.
- **Operator:** Pedram's colleague, with his usual procedure plus this release's extras (reported by Pedram). The shared record is in the **AH-103** entry.
- **Anything unexpected:** Nothing reported. Known gap: the GRANT read-backs for `user_push_tokens` were not reported, so the first device registration is the first proof. The flag shipped OFF and was armed in the same sitting, as the runbook allowed (inert while no token exists).

## AH-102 (Checkpoint B, app) — push column, push registration module, Android FCM wiring, iOS enabled — DEPLOYED

> **Deployed 2026-10-12** (reported by Pedram; see the Deploy record below). The note under it is the pre-deploy text, kept as written.

> **Stacks on AH-102 Checkpoint A and everything under it (AH-099 … AH-101, all PENDING).** **Cleared 2026-10-11:** Pedram's
> Android walk (E1/E2/E3, real FCM, Samsung SM-A515F) passed 11 of 12 items; item 12 is partial (rotation re-POST not
> verified, accepted as a known gap: self-healing in production, see `docs/tech-debt.md`). SPA + Android project only.

- **Status:** DEPLOYED (2026-10-12, one sitting for AH-099 → AH-103; as reported by Pedram, not read from the server by the assistant; the shared record is in the **AH-103** entry). Before the deploy it read: PENDING (**closed 2026-10-11**: pushed to `origin/main` at `e2501352`, CI **green on all four jobs** ([run 38173240196](https://github.com/pedram-kh/Engine/actions/runs/38173240196): main SPA Playwright 34 passed, 0 flaky; admin SPA Playwright 1 flaky, passed on retry, the known `admin-sign-in.spec.ts:69`, tech-debt 16); not deployed). **Pushing is not deploying.**
- **Range:** from Checkpoint A's pushed tip `6e827e40` → **`e2501352`** (a docs-only closing commit follows it). The commits: `1b16a6f6`
  (docs), `f61c0ec1` (C3.1), `20942da6` (C3.2), `a377cf97` (C4.1), `6d07b727` (C4.2), `55b4ef21` (a test), `1eef233c` (docs),
  `9119b9c4` (google-services.json), `0d51aed9` (iOS wiring), `8521aadc` / `899882d3` / `c803d7db` / `96518a3e` / `d0a5fd18` (docs),
  **`72afb8ae`** (fix: a cold-start push tap waits for the router; found by the Android walk), `9dd7f167` (the Android-bridge logging
  spec), `4b4dca71` (the `NSAllowsLocalNetworking` ATS exception), **`9d28206c`** (fix: no Android-only channel call on iOS; found by
  the iPhone spike), **`9ab318dd`** (`IOS_PUSH_ENABLED = true`), `e2501352` (docs).
- **AH entries carried:** AH-102 (app half)
- **Migrations run:** **none.** No schema change, no backfill.
- **Pre-deploy reads:** none beyond Checkpoint A's (results recorded in that entry).

**What goes live on deploy**

- **Web:** the SPA ships with the Push column code, but it is **invisible on web** (`pushChannelAvailable()` is `false`
  without a native shell); the page renders exactly as before (pinned by web-polarity specs and the unedited AH-101
  page spec). Locale files gain push strings ×24. No route, API or env change.
- **The Android build** is handed to Pedram's store process; it is not part of the web deploy. `google-services.json`
  (project "Catalyst Production") is committed (`9119b9c4`).
- **The iOS project** gains the push wiring (`0d51aed9`: plugin, entitlement, plist, AppDelegate). The real-iPhone spike
  passed (2026-10-11, review §28) and `IOS_PUSH_ENABLED` is now `true` in code (`9ab318dd`, pushed); the server flag keeps push off. The
  **TestFlight production-APNs proof is pending the first upload** and is required before the first iOS store build. The iOS `Info.plist`
  gains `NSAllowsLocalNetworking` (local-network traffic only; production talks HTTPS, so it changes nothing there).

**What is gated OFF**

- **`push_notifications_enabled` stays OFF.** The Android device proof is **done** (2026-10-11, E3 11 of 12). Still required
  before arming: the production FCM credential (the service-account file on Pedram's laptop, shipped into the env file by the deploy runbook's step 7; **not** Secrets Manager), `push:test --validate-only` against it, and Pedram's go,
  timed **before** the first push-capable store build reaches users. iOS is on in code after the spike (review §28) and also waits for the server flag and the TestFlight run.

- **Infra:** none for the web deploy (the queue-restart and env steps are Checkpoint A's). **Never run a bare
  `npx cap sync`** (R-7); Android builds need JDK 21.
- **One-shot commands:** none.
- **Post-deploy verification:** web: the notification preferences page has no Push column; the creator dashboard shows no
  soft-ask. Android (E3): [`push-notifications-device-walk.md`](../reviews/push-notifications-device-walk.md) (run once on
  2026-10-11 against the local API; the first PRODUCTION run is the first store build, after the flag is armed).
- **Flags armed:** none by the app half; `push_notifications_enabled` was armed ON in the same sitting (Checkpoint A's entry).
- **Deploy record (2026-10-12; the shared record is in the AH-103 entry).** The web side shipped with the SPA rebuild (reported). **Not read:** that the notification preferences page has **no Push column** on web and the creator dashboard shows no soft-ask. **Not part of this deploy:** the Android and iOS builds. **The iOS TestFlight build and the production-APNs proof were done afterwards, 2026-10-13: see the TestFlight record in the AH-103 entry.**
- **Operator:** Pedram's colleague, with his usual procedure plus this release's extras (reported by Pedram). The shared record is in the **AH-103** entry.
- **Anything unexpected:** Nothing reported. The token-rotation re-POST and the tech-debt 17 decision are still open (below); the TestFlight production-APNs proof was **closed 2026-10-13**.
  _Carried from before the deploy:_ named open items, none blocking: ~~the TestFlight production-APNs proof (pending the first upload)~~
  **closed 2026-10-13 (AH-103 entry, TestFlight record)**; the token-rotation re-POST (accepted, self-healing); item 10 on iOS not observed (likely sandbox-APNs
  feedback timing, unverified; not reported in the TestFlight run either). Tech-debt 17 (the iOS Keychain sign-in survives a reinstall) is decided before the first iOS store build.

## AH-101 — notification preference matrix, post-verified notice, third creator-app UI batch — DEPLOYED

> **Deployed 2026-10-12** (reported by Pedram; see the Deploy record below). The note under it is the pre-deploy text, kept as written.

> **Stacks on AH-100d and everything under it (all PENDING).** Half A is backend + SPA; Half B is SPA /
> native only. **Closed 2026-10-10:** Pedram's device walk passed (sign-in sheet, iOS splash, bell closes on
> navigation, composer above the tab bar, paperclip placements, no hamburger in native). **Pushed** at `b7a3342f`,
> CI green on all four jobs ([run `38024623651`](https://github.com/pedram-kh/Engine/actions/runs/38024623651)). **Pushing is not deploying: deploy timing is Pedram's call.**

- **Status:** DEPLOYED (2026-10-12, one sitting for AH-099 → AH-103; as reported by Pedram, not read from the server by the assistant; the shared record is in the **AH-103** entry). Before the deploy it read: PENDING (pushed, not deployed)
- **Range:** base `a1a20ead` (inventory), then four commits: Half A feat `34d90a4f` + docs `6c32df5d`; Half B feat
  `7d4f3e36` + docs `811a0356`. (A docs-only close/provenance commit follows them.)
- **AH entries carried:** AH-101
- **Migrations run:** none. No backfill, no schema change.

**What goes live on deploy**

- **`NotificationService::isEmailEnabled` guard — subtract-only.** It can only _block_ an email for a user who has
  turned that type off (a stored `email=false` row); it adds no sends and no recipients. It is called after each
  site's Pennant flag, so it never replaces a kill switch. **Behaviour change on deploy:** every existing
  `email=false` row is honoured immediately, however it got there (review §0, §6).
- **The notification preferences matrix page** (grid on web, stacked cards on native).
- **The in-app `assignment.live_verified` notice** — creator-only, ungated.
- **All Half B UI changes** (bell, thread height, attach controls, onboarding menu, sign-in sheet, iOS splash). These
  are native-scoped; the web render is unchanged, pinned by native-and-web polarity tests (the web assertions
  that existed before were kept; the added web-polarity tests pass).

**What is gated OFF**

- **`post_verified_mail_enabled` ships OFF and must NOT be armed** until Pedram's production read-back is done and
  confirms (a) the **preference-row counts** (so the email-opt-out blast radius is a known number) and (b) a **real,
  non-mock verification driver** (`SOCIAL_PROVIDER` / `config('integrations.social.driver')`, and
  `Feature::active('social_verification_enabled')`). If the driver is `mock` or unset, posted content may already be
  mock-"verified" in production — a standing-board item independent of this chunk (tech-debt, review §0).

- **Pre-deploy reads:** **required.** (1) `SELECT channel, is_enabled, COUNT(*) FROM user_notification_preferences
GROUP BY 1, 2;` and `... WHERE channel='email' AND is_enabled=false GROUP BY type;` — the count of people who will
  stop receiving a mail must be a known number. (2) The driver and flag read above — see the review §6. Neither blocks
  the deploy; (2) blocks arming the new flag.
- **Pre-deploy read results (2026-10-12, Pedram):** (1) `email/false`: **none**, so the email-opt-out blast radius is **0 users** on deploy; `in_app/false` 15 rows; `digest/true` 19. (2) `integrations.social.driver` = **`mock`** and `social_verification_enabled` is stored `"true"` (since 2026-07-14), so posts submitted since then **have been mock-"verified" in production**; **`post_verified_mail_enabled` must stay OFF** (this blocks arming it, as written above). The in-app notice will fire for those mock verifications once deployed; the analysis and the recommendation (confirm harmless, no flag change) are in [`deploy-ah099-ah103.md`](deploy-ah099-ah103.md) §4. **Production read-backs, 2026-10-12 (Pedram, `docker compose exec app sh` at `/app`):** `supervisorctl`: `laravel-queue` RUNNING (`queue:work`, not Horizon), `laravel-schedule` RUNNING (`schedule:work`, up 41 days), cron / nginx / php-fpm / syslog RUNNING, postfix / sshd / dnsmasq STOPPED. `queue.default` = `database`; `cache.default` = `redis`; `mail.default` = `smtp` (smtp.postmarkapp.com). `user_notification_preferences` with `channel='push'` = **0**. Preference rows: `digest/true` 19, `in_app/false` 15, `email/false` **none**. `integrations.social.driver` = `mock`; `features.social_verification_enabled` stored `"true"` (created 2026-07-14 07:58:21). `migrate:status`: last run `2026_08_27_140000_create_campaign_attachments_table` (batch 8); nothing from AH-099 onward has run.
- **Snapshot ID:** n/a (no schema change)
- **Infra:** **restart the queue worker** (new mailable class `PostVerifiedMail`). No cron / scheduler / env
  change.
- **One-shot commands:** none.
- **Post-deploy verification:** `/up`; the creator preferences page shows the grid with an Email column; a
  creator with one `email=false` row for a type still gets every other mail; flag list in the admin shows
  `post_verified_mail_enabled` OFF.
- **App build (Half B):** no deploy; a **clean iOS build** is needed for the new splash (the asset catalog and
  `assets/` changed; iOS caches launch screens, so delete and reinstall to see it). No new plugin, so no `cap sync`.
- **Flags armed:** none — `post_verified_mail_enabled` ships OFF and stays OFF (reported OFF after the deploy).
- **Deploy record (2026-10-12; the shared record is in the AH-103 entry).** Reported: `queue:restart` done (the new `PostVerifiedMail` class); `post_verified_mail_enabled` **OFF** after the deploy; `social_verification_enabled` unchanged (the Task 1 recommendation: confirm harmless, no flag change). The pre-deploy reads were taken before the deploy (results above): `email/false` none, so the email-opt-out guard blocks nothing. **Not read:** the creator preferences page's grid with an Email column; the **P6 Task 1 reads** (the stated condition of the Task 1 acceptance). The in-app `assignment.live_verified` row is now live and ungated, with no kill switch, so mock verifications since 2026-07-14 will notify creators from here on.
- **Operator:** Pedram's colleague, with his usual procedure plus this release's extras (reported by Pedram). The shared record is in the **AH-103** entry.
- **Anything unexpected:** Nothing reported. The P6 reads that the Task 1 acceptance was conditional on were not taken; see the AH-103 Deploy record.

## AH-100d — Creator app Figma fidelity audit and app-resume state refresh — DEPLOYED

> **Deployed 2026-10-12** (reported by Pedram; see the Deploy record below). The note under it is the pre-deploy text, kept as written.

> **Stacks on AH-100c, AH-100b, AH-100 and AH-099, all PENDING.** Deploy in order, or together as one
> entry. Their obligations apply unchanged. **Pushed** after Pedram's third re-walk; the tip and CI run are in the AH-100d review header.

- **Status:** DEPLOYED (2026-10-12, one sitting for AH-099 → AH-103; as reported by Pedram, not read from the server by the assistant; the shared record is in the **AH-103** entry).
- **Range:** `b214c31f` → the AH-100d docs commits (feat commits `ceae669a`…`766c2d63`, then the
  second re-walk batch `9ef51fd4`…`2b8294d5`).
- **Second batch, deploy-relevant:** the web bundle gains five new locale keys
  (`availability.creatorNav.tab.*`, unused on the web) and the **repaired base `assignments` label in ten
  locales (bg el et fi ga hu lt lv mt ro), which the web top nav and More menu DO read**. Nothing else on
  the web changes. Still an `apps/main` SPA rebuild only; no migration, no env, no dependency.
- **AH entries carried:** AH-100d.
- **Migrations run:** **none.** Zero `apps/api` diff.
- **Pre-deploy reads:** none of its own. The 2026-10-12 production read-backs are recorded in the AH-099, AH-101 and AH-102 entries; none applies here.
- **Snapshot ID:** not required. No migration, no data change.
- **Infra:**
  - **`apps/main` SPA rebuild only** (plus `packages/ui` and `packages/design-tokens`, built into it;
    `apps/admin` consumes `packages/ui` and its `PortfolioGallery` is unchanged when the new prop is
    absent). Every behavior is native-gated; the web bundle gains only unused i18n keys in every locale
    file and the repaired `welcome_back` strings.
  - New dependency `@capacitor/app` (lockfile changed): `pnpm install` on the build host.
  - No PHP-FPM reload, no worker restart, no env change, no scheduler change.
  - **The creator app is not deployed by this entry.** A device build needs `npx cap sync` for the new
    plugin.
- **One-shot commands:** none.
- **Post-deploy verification (results in the Deploy record below):**
  1. `/up` → 200.
  2. Web creator dashboard, wizard and Edit profile look and behave as before (square web buttons,
     no polling on the under-review page).
- **Flags armed:** none.
- **Deploy record (2026-10-12; the shared record is in the AH-103 entry).** Reported: `pnpm install` and the `apps/main` rebuild done; creator and admin web login OK. **Not read:** the `/up` code; the web dashboard / Edit profile render checks; the `ro` top-nav label (the AH-100d locale repair). The `@capacitor/app` dependency came in with the `pnpm install` (reported).
- **Operator:** Pedram's colleague, with his usual procedure plus this release's extras (reported by Pedram). The shared record is in the **AH-103** entry.
- **Anything unexpected:** Nothing reported. Not read: see the Deploy record above.

---

## AH-100c — Creator app eyes-on fixes: iOS input zoom, edit-profile form body, frame-07 dashboard pass — DEPLOYED

> **Deployed 2026-10-12** (reported by Pedram; see the Deploy record below). The note under it is the pre-deploy text, kept as written.

> **Stacks on AH-100b, AH-100 and AH-099, all PENDING.** Deploy in order, or together as one entry.
> Their obligations apply unchanged. Pushed on Pedram's clearance after his re-walk (2026-10-09).

- **Status:** DEPLOYED (2026-10-12, one sitting for AH-099 → AH-103; as reported by Pedram, not read from the server by the assistant; the shared record is in the **AH-103** entry).
- **Range:** `e5beeddc` → `112e7eb6` (the AH-100c feat commit is `9f4934e9`; the rest is docs-only).
- **AH entries carried:** AH-100c.
- **Migrations run:** **none.** Zero `apps/api` diff.
- **Pre-deploy reads:** none of its own. The 2026-10-12 production read-backs are recorded in the AH-099, AH-101 and AH-102 entries; none applies here.
- **Snapshot ID:** not required. No migration, no data change.
- **Infra:**
  - **`apps/main` SPA rebuild only.** Every addition is native-gated, so a browser renders what it did
    before, **with one exception on the web bundle: ten new i18n keys ship in every locale file** (they
    are unused on the web). `packages/design-tokens` changes only the native layer.
  - No PHP-FPM reload, no worker restart, no env change, no scheduler change.
  - **The creator app is not deployed by this entry.** The fixes reach a device through a local build
    (review §9).
- **One-shot commands:** none.
- **Post-deploy verification (results in the Deploy record below):**
  1. `/up` → 200.
  2. Web creator dashboard and Edit profile look and behave as before (completeness bar and the
     invitation alert are still the web ones).
- **Flags armed:** none.
- **Deploy record (2026-10-12; the shared record is in the AH-103 entry).** Reported: `pnpm install` and the `apps/main` rebuild done; creator and admin web login OK. **Not read:** the `/up` code; the web dashboard and Edit profile render checks.
- **Operator:** Pedram's colleague, with his usual procedure plus this release's extras (reported by Pedram). The shared record is in the **AH-103** entry.
- **Anything unexpected:** Nothing reported. Not read: see the Deploy record above.

---

## AH-100b — Creator app Figma-faithful page passes, walkthrough findings and the real app icon — DEPLOYED

> **Deployed 2026-10-12** (reported by Pedram; see the Deploy record below). The note under it is the pre-deploy text, kept as written.

> **Stacks on AH-100 and AH-099, both PENDING.** Deploy in order, or together as one entry. Their
> obligations apply unchanged.

- **Status:** DEPLOYED (2026-10-12, one sitting for AH-099 → AH-103; as reported by Pedram, not read from the server by the assistant; the shared record is in the **AH-103** entry).
- **Range:** `60de0811` → `6c2b86ec` (the AH-100b feat commit; `bdf279c1` above it is a one-test fix, everything else docs-only).
- **AH entries carried:** AH-100b.
- **Migrations run:** **none.** Zero `apps/api` diff.
- **Pre-deploy reads:** none of its own. The 2026-10-12 production read-backs are recorded in the AH-099, AH-101 and AH-102 entries; none applies here.
- **Snapshot ID:** not required. No migration, no data change.
- **Infra:**
  - **`apps/main` SPA rebuild only.** Every addition is native-gated, so a browser renders what it did
    before. The JS bundle does change (the icon and bottom-sheet code paths ship, dead on the web).
  - No PHP-FPM reload, no worker restart, no env change, no scheduler change.
  - **The creator app is not deployed by this entry.** The icon and splash live in the `ios/` and
    `android/` projects and reach a device through a local build (review §9).
- **One-shot commands:** none.
- **Post-deploy verification (results in the Deploy record below):**
  1. `/up` → 200.
  2. Web creator and sign-in pages look and behave as before.
- **Flags armed:** none.
- **Deploy record (2026-10-12; the shared record is in the AH-103 entry).** Reported: `pnpm install` and the `apps/main` rebuild done; creator and admin web login OK. **Not read:** the `/up` code; the web creator and sign-in page render checks.
- **Operator:** Pedram's colleague, with his usual procedure plus this release's extras (reported by Pedram). The shared record is in the **AH-103** entry.
- **Anything unexpected:** Nothing reported. Not read: see the Deploy record above.

---

## AH-100 — Creator app client, Capacitor wrap and native theme — DEPLOYED

> **Deployed 2026-10-12** (reported by Pedram; see the Deploy record below). The note under it is the pre-deploy text, kept as written.

> **Stacks on AH-099, which is itself PENDING.** Deploy them in order (AH-099 first), or together as
> one entry; either way AH-099's obligations apply unchanged.

- **Status:** DEPLOYED (2026-10-12, one sitting for AH-099 → AH-103; as reported by Pedram, not read from the server by the assistant; the shared record is in the **AH-103** entry).
- **Range:** `d1ab5820` → `75ce384d` (the AH-100 feat commit; everything above it is docs-only).
- **AH entries carried:** AH-100.
- **Migrations run:** **none.** Zero `apps/api` diff.
- **Pre-deploy reads:** none of its own. AH-099's reads still apply if they are deployed together. The 2026-10-12 production read-backs are recorded in the AH-099, AH-101 and AH-102 entries; none applies here.
- **Snapshot ID:** not required — no migration, no data change.
- **Infra:**
  - **`apps/main` SPA rebuild only.** The web renders byte-identically: the bearer transport and
    native shell are dead code for a browser.
  - No PHP-FPM reload of its own, no worker restart, no env change, no scheduler change.
  - **The creator app is not deployed by this entry.** It builds locally (review §9). A
    production app build (`build:native` with the uncommitted `.env.native-production`) is
    pointless until AH-099's token door exists on production.
- **One-shot commands:** none.
- **Post-deploy verification (results in the Deploy record below):**
  1. `/up` → 200.
  2. Web creator sign-in, dashboard and availability page look and behave as before. A cookie
     session is in use, and no `Authorization` header is sent.
  3. `personal_access_tokens` `count(*)` is unchanged by web traffic.
- **Flags armed:** none — the web has no new behaviour to arm.
- **Deploy record (2026-10-12; the shared record is in the AH-103 entry).** Reported: `pnpm install` and the `apps/main` rebuild done; creator and admin web login OK. **Not read:** the `/up` code; the dashboard and availability page render checks; that no `Authorization` header is sent on the web; that `personal_access_tokens` stays `0` after web traffic.
- **Operator:** Pedram's colleague, with his usual procedure plus this release's extras (reported by Pedram). The shared record is in the **AH-103** entry.
- **Anything unexpected:** Nothing reported. Not read: see the Deploy record above.

---

## AH-099 — Creator app token auth, backend — DEPLOYED

> **Deployed 2026-10-12** (reported by Pedram; see the Deploy record below). The note under it is the pre-deploy text, kept as written.

> **Standalone entry.** Production was current through AH-098 (`9b7d6deb`) before this. The
> inventory/docs commits between are docs-only. **Deploys dark: nothing calls the new path until
> AH-100.**

- **Status:** DEPLOYED (2026-10-12, one sitting for AH-099 → AH-103; as reported by Pedram, not read from the server by the assistant; the shared record is in the **AH-103** entry).
- **Range:** `9b7d6deb` → `e665e9ce` (from-SHA exclusive: the previous deployed tip; to-SHA is the
  feat commit — everything above it is docs-only)
- **AH entries carried:** AH-099
- **Migrations run:** **none.** Verified: no migration file in the diff; the stock
  `2026_05_07_003823_create_personal_access_tokens_table` already shows `Ran` locally.
  **Verify on the box before reload:** `php artisan migrate:status` lists it as `Ran` (and nothing
  `Pending` that this deploy introduced).
- **Pre-deploy reads:** **production, 2026-10-12 (Pedram):** `migrate:status` last run is `2026_08_27_140000_create_campaign_attachments_table` (batch 8) and nothing from AH-099 onward has run; the stock `…create_personal_access_tokens_table` predates that line, so it should read `Ran`, but reads 1–4 below are **not yet taken**. They are step P4 of the merged checklist [`deploy-ah099-ah103.md`](deploy-ah099-ah103.md). Other read-backs that day: `mail.default` = `smtp` (Postmark); `queue.default` = `database`; `cache.default` = `redis`; `integrations.social.driver` = `mock`.
  1. `php artisan migrate:status | grep personal_access_tokens` → `Ran`.
  2. `SELECT count(*) FROM personal_access_tokens;` → expected `0` (zero callers exist).
  3. **`SANCTUM_STATEFUL_DOMAINS` contains no bare `localhost`.** This is the **Android-only**
     trap: Sanctum strips only `http(s)://` before matching, so a bare `localhost` entry would
     make Android's `https://localhost` a stateful origin and every bearer POST would 419
     (iOS's `capacitor://localhost` never matches). The repo `.env.example` default _does_
     contain `localhost` — that is for local dev; production must not.
  4. `AUTH_GUARD` is unset or `web`.
- **Snapshot ID:** not required — no migration and no data change. (Operator may take one anyway.)
- **Infra:** **PHP-FPM reload — required** (new guard class, config, provider registration). No
  worker restart. No SPA rebuild (zero `apps/main` / `apps/admin` diff). No scheduler/cron change.
  **Env:** set `FRONTEND_EXTRA_ORIGINS=capacitor://localhost,https://localhost` (adds the two
  Capacitor origins to CORS — an env change, not code; **append** to any existing value).
  `SANCTUM_TOKEN_EXPIRATION_MINUTES` is optional (defaults to `129600` = 90 days). Run
  If config is cached on the box, re-cache it so the new `auth.php` / `sanctum.php` keys are read.
- **One-shot commands:** none.
- **Post-deploy verification (results in the Deploy record below):**
  1. `/up` → 200.
  2. A creator **cookie** session still works on `GET /api/v1/me` (the web path did not move).
  3. A platform-admin session on `GET /api/v1/me` of the **main** SPA host is refused as before.
  4. `POST /api/v1/auth/token` with an **unknown email** → `401 auth.invalid_credentials`, and
     `count(*)` on `personal_access_tokens` stays `0`. (Do **not** smoke it with a real creator's
     email: a correct password would mint a live token with no client to hold it, and a wrong
     one would bump that creator's failed-login lockout counter.)
- **Flags armed:** none — the path has no callers; no flag exists or is needed.
- **Deploy record (2026-10-12; the shared record is in the AH-103 entry).** Reported: no migration of its own (the two in this sitting belong to AH-102 and AH-102b); `FRONTEND_EXTRA_ORIGINS` appended; `config:cache` and the PHP-FPM reload done; `APP_KEY` fingerprint unchanged; creator and admin web login OK. **Not read:** pre-deploy reads 1–4 (`personal_access_tokens` `Ran` and count, `SANCTUM_STATEFUL_DOMAINS` free of a bare `localhost`, `AUTH_GUARD`); post-deploy checks 1–4 one by one (`/up` code, a creator cookie `GET /api/v1/me`, the platform-admin refusal on the main host, the unknown-email token-door `401` with the token count staying `0`). A working web login is evidence that the cookie path did not move; it is not a pass of check 2.
- **Operator:** Pedram's colleague, with his usual procedure plus this release's extras (reported by Pedram). The shared record is in the **AH-103** entry.
- **Anything unexpected:** Nothing reported. Not read: see the Deploy record above. **Carry:** read `SANCTUM_STATEFUL_DOMAINS` for a bare `localhost` before the first production Android build (it would make every Android bearer POST a 419). The token door has no callers yet, so nothing is exposed in the meantime.

---

## AH-098 — master agreement section on the creator profile — DEPLOYED

> **Standalone entry.** Production was current through AH-097 before this deploy — nothing stacked
> on top of it; it was the entire undeployed backlog.

- **Status:** DEPLOYED (2026-09-04).
- **Range:** `7b8401fe` (the AH-097 close-out tip, exclusive — the previous deployed tip) →
  `271294db` (the AH-098 feature commit) **+ the docs rider `9b7d6deb`** (opened this `PENDING`
  record — docs-only, zero diff outside `docs/`, carries no runtime obligation of its own, same
  class as every other deploy-log-rider commit in this file's history).
  **Deployed tip: `9b7d6deb`, per Pedram.** Code-only per this entry's own shape (zero `apps/api`
  diff, SPA-only rebuild) — the docs rider on top carries nothing further for the box to apply.
- **AH entries carried:** AH-098 only.
- **Migrations run:** none. **Zero `apps/api` diff in this range** — both fields the profile
  section needed (`has_signed_master_contract`, `click_through_accepted_at`) were already shipped
  on `CreatorResource`'s creator-self attributes; verified in the AH-098 entry
  ([`adhoc-changes-log.md`](../reviews/adhoc-changes-log.md)) rather than assumed. No
  `apps/api/database/migrations/**` file in this range.
- **Pre-deploy reads:** none required — no schema/data change to verify against.
- **Snapshot ID:** not applicable — no migration, code-only deploy per this entry's own shape.
- **Infra:**
  - **No worker restart required — confirmed, none performed.** This range adds no mailable and
    touches no `apps/api/lang/**` file — the three new i18n keys (`accepted_header`, `accepted_on`,
    `toggle_label`) ×24 live entirely under `apps/main/src/core/i18n/locales/**`, the SPA's own
    bundle, not backend copy a queue worker would cache.
  - **No PHP-FPM reload required — confirmed, none performed.** Zero `apps/api` diff means no PHP
    bytecode changed.
  - No cron/scheduler change, no env change, no new secrets/disk, no flag.
  - **Frontend build/deploy: REQUIRED, `apps/main` only — done.** Carries the new
    `CreatorMasterAgreementSection.vue` (the profile's new bottom section) + the extracted
    `MasterAgreementTerms.vue` (now the one rendering path shared with the wizard's
    `ClickThroughAccept.vue`, refactored in the same commit) + the 24 locale files' three new keys
    each. `apps/admin` is untouched by this range — no rebuild owed there.
- **One-shot commands:** none.
- **Post-deploy verification:**
  1. `/up` 200. ✅ **Confirmed.**
  2. A click-through-accepted creator's profile page shows the collapsed "Master agreement"
     section with the accepted date; expanding it scrolls the full agreement text inside the
     height-bounded panel — the page itself must not grow by the document's length. ✅ **Confirmed
     by Pedram on production.**
  3. A never-accepted creator's profile shows **no section at all** — absent, not an empty state.
     **Not run this deploy** — only check 2 was exercised; recorded honestly rather than assumed
     from check 2's pass.
- **Flags armed:** none — this change is not flag-gated.
- **Operator:** Pedram.
- **Date:** 2026-09-04.
- **Anything unexpected:** not recorded.
- **Ref:** [ad-hoc log](../reviews/adhoc-changes-log.md) AH-098; [tech-debt](../tech-debt.md)'s
  "No versioned retrieval of the master agreement text…" entry (a read-on-re-read limitation, not
  a deploy obligation — nothing for this deploy to carry or verify against it).

---

## AH-097 — campaign description on the creator assignment detail — DEPLOYED

- **Status:** DEPLOYED (2026-09-01)
- **Range:** `0eb3a7da` → `7abd52c4` (from-SHA exclusive: the previous deployed tip, per the
  2026-08-31 AH-086→AH-096 entry below). The two commits between them (`6d4994e3`, `b9e819f0`) are
  docs-only (deploy-log + resumption-template updates) — `7abd52c4` is the only runtime-affecting
  commit in this range.
  **Deployed runtime code: `7abd52c4`.** The on-box `git rev-parse HEAD` was not itself recorded,
  but the pull-target reasoning stands regardless: the deploy pulled `origin/main` whose tip at
  the time was `28547822` (the deploy-log rider on top of the feature commit), and the two
  commits above `7abd52c4` in that range are docs-only — so whatever the exact on-box SHA, the
  deployed _runtime_ code is `7abd52c4` either way.
- **AH entries carried:** AH-097 only.
- **Migrations run:** none. No schema change — `campaign.description` already exists on the
  `campaigns` table (added long before this batch); this change only reads and renders it on a
  surface that didn't render it before. **Verified at deploy time: none pending.**
- **Pre-deploy reads:** none needed (no schema/data change to verify against).
- **Snapshot ID:** not applicable — no migration.
- **Infra:** no worker restart needed (verified — none owed by this range). **PHP-FPM reloaded.**
  No cron/scheduler change, no env change, no new secrets/disks. **SPA (apps/main) rebuilt + API
  rebuilt** (a Vue template change + a controller payload change).
- **One-shot commands:** none.
- **Post-deploy verification:** ✅ **smoke confirmed by Pedram on production** — opened a described
  campaign's assignment detail and confirmed the "About this campaign" block renders; opened an
  undescribed one and confirmed the block is absent (no empty card).
- **Flags armed:** none — this change is not flag-gated.
- **Operator:** Pedram.
- **Anything unexpected:** nothing reported.
- **Ref:** [ad-hoc log](../reviews/adhoc-changes-log.md) AH-097.

---

## 2026-08-31 · AH-086 → AH-096 — agency sign-in + invite-door, members 2FA, DOB→age + graduated floor scoring, platform automations, agency files library (chunks A+B), campaign-duration hide, invite-acceptance account creation, roster age-filter fix — DEPLOYED

> This entry **merges** what were, until this update, **five stacked `PENDING` entries** — AH-086 +
> b + AH-087, AH-088 + AH-089, AH-090, AH-091, and AH-092 (itself carrying the AH-093, AH-095, and
> AH-096 riders) — per the arc-deploy convention (`production-queue-worker.md` §8.3 — a themed
> backlog does not ship as a subset) and the same precedent as the 2026-08-19 AH-067 → AH-083
> merge below. Pedram confirmed the deploy carrying this **entire held backlog** ran on
> **2026-08-31/09-01**. Per his instruction: **fields he did not supply are written "not
> recorded," not `TBD`** — an honest gap, not a placeholder awaiting a later fill.

- **Status:** **DEPLOYED.** Confirmed by Pedram — deploy ran 2026-08-31/09-01.
- **Range:** `6f5351de` (the AH-085 deployed tip, exclusive) → `0eb3a7da` (the AH-096 fix — **"the
  deployed tip," per Pedram**). **Note, same class as the 2026-08-19 AH-085 entry's own "Box HEAD ≠
  origin tip" note below:** `origin/main` now reads one commit further, `6d4994e3`
  (`docs(runbooks): AH-096 rides the pending stack — deploy-log rider` — this entry's own
  predecessor edit, docs-only, zero diff outside `docs/`). The deployed code is byte-identical to
  what that commit sits on top of. **48 commits** in the range; the ones with an actual runtime
  diff, in order:
  - `2e7c02ad` — `feat(auth): agency-facing sign-in variant, one component (AH-086)`.
  - `0edcffa1` — `feat(agencies): team-invite email's CTA doors through /agency/sign-in (AH-086b)`
    — the magic-link door (`AgencyInvitationService` + `InviteAgencyUserMail` + `SignInPage.vue`).
  - `2de36602` — `fix(tests): Larastan-safe assertion chaining in AH-086b invite tests` (test-only).
  - `48a984a8` — `feat(admin): agency members drill-in + per-member 2FA reset/exempt (AH-087)`.
  - `c140b001` — `feat(spa): members card with 2FA controls + exemption-aware guard (AH-087)`.
  - `0fba5a4c` — `feat(creators): date of birth with derived age everywhere (AH-088)` — the
    migration, model, resources, floor, filter, both SPAs, api-client types, 72 locale files.
  - `ad696308` — `fix(tests): stop comparing a whole base64url segment to a single char (flaky)`
    (test-only).
  - `4d56d312` — `feat(creators): graduated per-field floor scoring (AH-089)` — no migration.
  - `f31d7c7b` — `refactor(creators): extract CreatorAgencyDirectConnectService + suspended-agency
guard (AH-090)` — the one deliberate manual-path behaviour change (see below).
  - `1c996c77` — `feat(admin): platform automation rules — table, kill switch, job, on-approval hook
(AH-090)` — the migration, model, enums, factory, the Pennant flag, the job, the hook.
  - `1a4e0a1f` — `feat(admin): automation-rules CRUD, agency picker projection, admin SPA surface
(AH-090)`.
  - `f4ac735c` — `feat(creators): stamp the firing rule onto the connect's audit row (AH-090 Q4)` —
    metadata-only; no schema.
  - `8241be30` — `fix(tests): explicit Event facade import — CI-only Larastan resolution gap` — a
    carried, unrelated fix; no schema, no app code, one test file's import line.
  - `706a0b6e` — `feat(agencies): Agency Files library, chunk A — upload, list, delete (AH-091)` —
    the migration, model, service, controller/policy/resource, routes, the shared
    `AttachmentMimeTypes` extraction, i18n ×24.
  - `ec2401cd` — `feat(campaigns): Campaign attachments from the Files library, chunk B (AH-092)` —
    the migration, model, controller/resources/requests, the creator read path on both doors,
    i18n ×24.
  - `abe4657d` — `feat(creators): hide campaign duration on Jobs Board surfaces (AH-093)` — the
    template-only render hide. **Rider — no obligation of its own.**
  - `7580e59c` — `test(architecture): used-key coverage tripwire for both SPAs (AH-094)` —
    pure-test; zero runtime diff, no deploy obligation.
  - `c9df1888` — `feat(agencies): invite-acceptance creates the account for stranger invitees
(AH-095)` — the new `create-account` route, Form Request, controller action, service method, the
    collision-race guard, the dedicated `AuditAction` case, the preview endpoint's two new fields,
    the SPA's `'no-account'` branch + set-password form, i18n ×24.
  - `0eb3a7da` — `fix(roster): thread age_min/age_max into the query string + class-sweep pin
(AH-096)` — the `roster.api.ts` fix, wrapper-level + structural + Playwright pins, the
    test-helper-only `CreateRosterCreatorsController` change. **Rider — no obligation of its own.**
  - The remaining 30 commits are docs-only: read-only inventories, kickoffs/plan-pauses, review
    files, close-outs, and this file's own five predecessor `PENDING` entries (now collapsed into
    this one) — see `adhoc-changes-log.md` for the full per-commit-per-entry mapping.
- **AH entries carried:** **AH-086** (base) + the **AH-086b addendum** + **AH-087, AH-088, AH-089,
  AH-090, AH-091, AH-092, AH-093, AH-095, AH-096.** **AH-094** (pure-test, both SPAs'
  `i18n-used-keys-resolve.spec.ts`) is carried for range completeness only — no runtime file
  changed, nothing for a deploy to have carried. AH-093 and AH-096 are both zero-obligation riders
  (no migration, no restart, no new production route; AH-096's one `apps/api` touch is a
  `/api/v1/_test/*` test-helper route `TestHelpersServiceProvider` never registers outside
  `local`/`testing` — **not present in production at all**).
- **Migrations run:** **5, in this order:**
  1. `2026_08_27_100000_add_mfa_exemption_to_users_table.php` (AH-087) — adds
     `users.mfa_exempted_at` (timestamp, nullable) + `users.mfa_exemption_reason` (text, nullable).
     Additive-nullable: no backfill, no `UPDATE`. Every pre-existing row reads NULL, which is what
     makes the exemption branch inert on arrival.
  2. `2026_08_27_110000_add_date_of_birth_to_creators_table.php` (AH-088) — adds
     `creators.date_of_birth` (date, nullable). Additive-nullable: no backfill. Every pre-existing
     row reads NULL until a creator or an admin backfills it on next edit.
  3. `2026_08_27_120000_create_admin_automation_rules_table.php` (AH-090) — `CREATE TABLE`, ships
     EMPTY, stays empty until an operator creates a rule.
  4. `2026_08_27_130000_create_agency_files_table.php` (AH-091) — `CREATE TABLE`, ships EMPTY,
     stays empty until an agency member uploads a file.
  5. `2026_08_27_140000_create_campaign_attachments_table.php` (AH-092) — `CREATE TABLE`, ships
     EMPTY, stays empty until an agency member attaches a file to a campaign.

  AH-089, AH-093, AH-095, AH-096 add **none** — AH-089 changes only the floor's arithmetic (no
  schema); AH-095's `User::create()` needed no column the `users` table didn't already have.
  **`down()` honesty:** #1 and #2 are true structural-and-content inverses (net-new nullable
  columns, no pre-existing data at risk beyond what accrues after deploy — see the rollback notes
  on each in the collapsed entries' git history). #3, #4, #5 are structural inverses with a
  **lossy content inverse** — each `dropIfExists` discards every row written after this deploy
  (rules, files, attachments respectively); no pre-existing table content is at risk since all
  three are net-new.

- **⚠ POST-DEPLOY COMMAND — REQUIRED, and it WRITES:** `php artisan creators:recompute-completeness`
  (owed by the AH-088/089 range; AH-090, AH-091, AH-092 each add none of their own). **Ritual
  output, as supplied by Pedram:**
  ```
  dry-run:  1295 checked / 1295 would change
  run:      1295 recomputed / 1295 updated
  dry-run:  1295 checked / 0 would change   ← idempotency proven
  ```
  The third line is the cheapest possible confirmation the command completed and is safe to
  re-run. **Post-recompute platform-wide average: 44.51**, across all 1295 creator rows.
  **⚠ Not directly comparable to the "≈97 for previously-complete creators" figure the AH-088/089
  entry's blast-radius estimate used.** That figure described one cohort (creators who were
  previously AT the old six-field floor, missing only DOB); **1295 is the full `creators` table —
  every `application_status`, including pending/abandoned/rejected**, most of whom were never
  near 100 to begin with and whose scores are dominated by fields this recompute doesn't touch.
  A single platform-wide average across a population that heterogeneous cannot confirm or refute
  the graduated-floor arithmetic on its own — **the distribution check below is what actually
  confirms it.**
- **Pre-deploy reads:**
  - `SELECT COUNT(*) FROM creators WHERE date_of_birth IS NOT NULL;` — must be an error before the
    migration, **0** immediately after. **Run: not recorded.**
  - `SELECT ROUND(AVG(profile_completeness_score)) FROM creators;` (the PRE average, for
    comparison against the 44.51 POST figure above) — **not recorded**, if captured at all.
- **Snapshot ID:** **not recorded** · confirmed `available` before migrating? **not recorded.**
  Required — this range contains two nullable-column migrations plus three lossy-`down()`
  `CREATE TABLE`s.
- **Infra:**
  - **`php artisan migrate`: REQUIRED** — the five migrations above. Expected output: five
    `Migrating: ...` / `Migrated: ... (Xms)` pairs, in the numbered order above. **Actual migrate
    output: not recorded.**
  - **`php artisan creators:recompute-completeness`: REQUIRED and RUN** — see the ritual output
    above (this is the one field in this section that IS recorded).
  - **Queue-worker restart: REQUIRED** — carried from the AH-086b/AH-087 range (one existing
    mailable's docblock touched, three net-new mailables — `MfaResetByAdminMail`,
    `MfaExemptionGrantedMail`, `MfaExemptionRemovedMail` — plus 24 `apps/api/lang/*/auth.php`
    files). Nothing after AH-087 in this range adds a further mailable or touches
    `apps/api/lang/**`. **Restart performed: not recorded.**
  - **PHP-FPM reload: REQUIRED** — synchronous request-path PHP moved in nearly every chunk in this
    range (auth, users, creators, agency files, campaign attachments, automation rules, the
    invite-acceptance controller). **Reload performed: not recorded.**
  - **Frontend build/deploy: REQUIRED, both SPAs.** `apps/main` carries AH-086's `/agency/sign-in`,
    AH-086b's invite-forward branch, AH-087's guard split, AH-088's wizard field + five render
    surfaces + roster column/filter, AH-091's Files nav + page, AH-092's Attachments tab, AH-093's
    duration hide, AH-095's `'no-account'` branch, and AH-096's `roster.api.ts` fix. `apps/admin`
    carries AH-087's Members card, AH-088's read-only age row, and AH-090's Automations module.
    None of this reaches a browser without both bundles going out.
  - No cron/scheduler change anywhere in the range. No env changes, no new secrets, no new
    disk/bucket (AH-091/092 reuse the existing `media` disk under new prefixes/keys).
- **One-shot commands:** the recompute ritual above — the only one in this range.
- **Post-deploy verification:**
  1. `/up` 200 + one authenticated request. **Result: not recorded.**
  2. `migrate:status` shows all five migrations above as `Ran`. **Result: not recorded.**
  3. `/agency/sign-in` renders the agency heading, no creator-signup CTA (AH-086); a fresh invite
     contains a `/agency/sign-in?token=...&agency=...` link (AH-086b).
  4. Admin panel → any agency → **Members** card loads with MFA column intact; Feature-flags page
     lists `platform_automations_enabled` as **OFF**.
  5. A creator's profile page shows the **Date of birth** field; that creator's agency roster shows
     the age column, and the age-min/age-max filter narrows the table (AH-096's own check — the
     pre-fix symptom was exactly this filter doing nothing).
  6. Agency Files (`/files`) and a campaign's **Attachments** tab both load and round-trip one
     test file each (AH-091, AH-092).
  7. Jobs Board card list and job detail show no "Duration: …" text, while the JSON payload still
     carries `listing_duration` (AH-093, byte-identical-payload proof).
  8. Seed a fresh invitation for a never-signed-up email, load its accept URL unauthenticated, and
     confirm the set-password form renders and successfully lands the new user signed in on the
     agency dashboard (AH-095); a byte-identical existing-account path still shows the sign-in
     prompt.
  9. **Smoke results for items 3–8: not recorded** — Pedram to confirm, or mark not recorded.
  10. **✅ CLOSED — the graduated-floor distribution check, run by Pedram on the production box
      (2026-09-01), evidence below:**

      ```sql
      SELECT
        COUNT(*) FILTER (WHERE profile_completeness_score >= 99)                                  AS at_or_above_99,
        COUNT(*) FILTER (WHERE profile_completeness_score >= 95 AND profile_completeness_score < 99) AS graduated_95_to_99,
        COUNT(*) FILTER (WHERE profile_completeness_score >= 78 AND profile_completeness_score <= 82) AS the_cliff_78_to_82,
        ROUND(AVG(profile_completeness_score)) FILTER (WHERE application_status = 'approved')      AS approved_only_avg
      FROM creators;
      ```

      **Result, across 1,295 creator rows:** `at_or_above_99 = 1` · `graduated_95_to_99 = 131` ·
      `the_cliff_78_to_82 = 30`. (`approved_only_avg` not recorded this round.)

      **Verdict: AH-089's graduated floor CONFIRMED in production.** The 95–98.99 bucket is
      exactly where a creator missing only DOB should land (complete-minus-DOB, the graduated
      outcome) — the 131-strong bucket there is the previously-complete cohort now sitting at
      ≈97, missing nothing but a birthday. Under the superseded all-or-nothing floor (AH-088
      alone) that same cohort would show up EMPTY at 95–99 with those ~130 creators instead
      piled into 78–82 — the "cliff" signature this check exists to catch. The 78–82 bucket
      that IS populated (30) reads as ordinary mid-distribution creators genuinely missing
      multiple fields, not the cliff: the count is far short of the ~130 the cliff scenario
      would produce, and it's a normal bucket size for a broad completeness spread across 1,295
      rows. One creator has already self-healed into the `≥99` bucket by adding a birthday —
      the AH-088 self-heal-on-next-edit mechanic working exactly as designed, live in
      production. **The graduated-floor outcome is no longer "distribution unverified" — it is
      verified, with evidence, above.**

- **Flags armed:** **none.** `platform_automations_enabled` (new in this range, AH-090) ships OFF
  and is **NOT** armed by this deploy — arming is a separate, deliberate operator act (create the
  rules, read them back, THEN flip the flag; ritual in `feature-flags.md`). The pre-existing three
  mail flags (`job_posted_notifications_enabled`, `application_notifications_enabled`,
  `missing_creator_mails_enabled`) are untouched by this range and remain at their last recorded
  state (OFF, not armed, per the 2026-08-19 AH-083 entry below) — not independently re-verified
  this session.
- **⚠ Two deliberate behaviour changes to EXISTING paths, live the moment this deployed — no flag
  gates either:**
  1. **DOB now required on profile save (AH-088).** An existing creator's next profile SAVE is
     blocked until they enter a birthday (pending/rejected hard-block, approved soft-warn) — the
     AH-026 D3 backfill-on-next-edit posture, at cohort scale. Self-heals per creator the moment
     they fill it in; does not email anyone, does not change `application_status`, does not push
     anyone back into the wizard.
  2. **Suspended-agency direct-connect now refused (AH-090).** An admin direct-connecting a
     creator to a **suspended** agency gets `422 connection.agency_suspended`. Before AH-090
     nothing in the connect chain checked suspension, so that connection succeeded and produced a
     roster relation into an agency whose users cannot log in. This removes a capability rather
     than adding one; the removed capability had no legitimate use.
- **Operator:** **not recorded.**
- **Anything unexpected:** **not recorded**, beyond what is already on record from when these
  entries were still open: the AH-090 close-out's own push redded CI on a pre-existing, unrelated
  test file (`8241be30`, fixed same-day, CI green at that tip — see the collapsed entry's own note,
  now folded into the commit list above), and one flaky-test fix during the AH-088 session
  (`ad696308`, a base64url-segment comparison, unrelated to any deploy obligation).

---

## 2026-08-19 · AH-085 — brand select pagination fix — DEPLOYED

> **Box HEAD (`6f5351de`) ≠ origin tip — expected, not drift.** Two commits landed on
> `origin/main` after this deploy ran: `e6488eb1` (records the CI-fix commit + the green run —
> `docs/reviews/adhoc-changes-log.md` only) and `f2d8851a` (opens this very entry —
> `docs/runbooks/deploy-log.md` only). Both are **docs-only, zero-diff outside `docs/`, inert**
> — confirmed by `git show --stat` on each: one file changed, in `docs/`, nothing under
> `apps/` or `packages/`. The deployed code is byte-identical to what those two commits sit on
> top of; production running `6f5351de` while origin reads `f2d8851a` is exactly the
> pushed-≠-deployed distinction this file exists to track, not an undeployed-code gap.

- **Status:** **DEPLOYED.** Confirmed by Pedram, 2026-08-19 — smoke passed: the brand select now
  lists all brands across the five pickers named below.
- **Range:** `39788759` → `6f5351de` (from-SHA exclusive — the previous deployed tip; to-SHA is
  the deployed box's HEAD, not origin's current tip — see the note above). **3 commits, all
  today, one themed fix:**
  - `edb30dd7` — `fix(brands): every brand reachable in select pickers, not just page 1` (AH-085).
  - `41b7b95b` — `docs(reviews): AH-085 brand select pagination bug — diagnosis, consumer table,
fix` (docs-only).
  - `6f5351de` — `fix(api): rename BrandSelectOptionsTest helper — collided with an existing
global function` (AH-085) — a same-day CI-fix: the first push's CI run failed on a PHP fatal
    `Cannot redeclare function`, a test-helper naming collision with an unrelated existing test
    file, caught and fixed before this entry was opened. No production runtime file in this
    commit — test-only.
- **AH entries carried:** **AH-085** only.
- **Migrations run:** **none.** No file under `apps/api/database/migrations/**` in this range —
  `BrandController::index` and the new `BrandOptionResource` are read-path only, no schema touch.
- **Pre-deploy reads:** **none required.** Read-path fix, no data mutation, no backfill.
- **Snapshot ID:** not applicable — no migration, so no schema-rollback surface exists for this
  deploy. (Standard code-rollback via git/deploy tooling covers this range like any other.)
- **Infra:** **deploy shape is code-only** — an `apps/api` PHP change (`BrandController`, new
  `BrandOptionResource`) plus an `apps/main` SPA change (five picker components + `brands.api.ts`
  - a new api-client type); no schema, no flags, no scheduler/cron change.
  * **No queue-worker restart required** — confirmed: this range adds no mailable, touches no
    `apps/api/lang/**` file, and `BrandController`/`BrandOptionResource` are resolved per-request
    only (grepped every `ShouldQueue` class in `app/Modules/Brands/` — none exist; nothing in this
    diff runs inside a queued job).
  * **PHP-FPM reload — not explicitly recorded, but implied.** `BrandController::index`'s new
    `?for=select` branch is opcached bytecode like any other controller change; without fresh
    bytecode being served, the fix cannot take effect for any request. Pedram's smoke result
    (brand select correctly lists all brands, past the old 25-row page, across all five pickers)
    is only possible if the new controller code is actually running — which means either a
    reload happened or FPM workers had already cycled past it by the time smoke ran. **Restart
    performed: not recorded** as an explicit step; the live, verified-working fix is the evidence
    it happened one way or another. Same posture as the 2026-08-11 `CreatorPolicy` deploy above,
    minus the explicit confirmation that one got.
  * `apps/main`'s SPA bundle rebuild + redeploy is confirmed to have reached browsers — smoke was
    run against the actual pickers, not the API directly.
- **One-shot commands:** **none.**
- **Post-deploy verification:** ✅ **smoke confirmed by Pedram** — the brand select lists all
  brands across the five pickers (campaign create, campaign-list filter, pool create, pool edit,
  blacklist dialog), not just the first alphabetical page. `/up` + authenticated-request status:
  **not separately recorded**, folded into the same confirmation.
- **Flags armed:** **none — this range touches no flag.**
- **Operator:** **Pedram.**
- **Anything unexpected:** none during the build/push itself, beyond the CI-fix commit named
  above under Range (a test-only naming collision, caught by CI before anything reached this
  entry — no production-facing surprise).

---

## 2026-08-19 · AH-067 → AH-083 — composer fix, Draft Workflow v2, the eyes-on batch, four admin/creator items, and the missing creator emails — DEPLOYED

> This entry **merges** what were, until today, two stacked `PENDING` entries — the AH-067 composer
> fix (opened 2026-08-11) and the AH-068/069 Draft Workflow v2 entry (opened 2026-08-16, which had
> accumulated AH-071 → AH-083 on top via update notes without ever opening a new entry, per its own
> "whoever deploys next carries the full range" convention). Pedram confirmed on 2026-08-19 that the
> deploy carrying this **entire backlog** ran successfully, in one operation, per the arc-deploy
> convention (`production-queue-worker.md` §8.3 — a themed backlog does not ship as a subset). Per
> his instruction: **fields he did not supply are written "not recorded," not `TBD`** — an honest gap,
> not a placeholder awaiting a later fill.

- **Status:** **DEPLOYED.** Confirmed by Pedram, 2026-08-19.
- **Range:** `6cdf0a5` → `54f59948` — the entire held backlog since the 2026-08-11 deploy, in one
  operation. Code commits in the range:
  - `b13ee71` — `fix(api): stop composer install from rotating the production APP_KEY` (AH-067).
  - `36fa454f` — `feat(drafts): number the review rounds and say so on every surface` (AH-068).
  - `451388f1` — `feat(campaigns): let a campaign end at draft approval when creators do not post`
    (AH-069).
  - the AH-070 CI/process pair + follow-through (`f771a7cf`, `66d1fa18`, `a7900152`, `e08ab2b7`) —
    `.github/workflows` + `phpunit.xml` + one pre-existing-flake test fix; **no production runtime
    file touched**, carried here only for range completeness.
  - the AH-071 → AH-078 batch, `5e61795a` → `3ef5d8ec` (sixteen commits, all `apps/main`-only — see
    `adhoc-changes-log.md` for the per-commit-per-entry mapping), the AH-074 regression pin
    (`c1f0add6`, `apps/main` test-only), and `368970d4` (a Playwright timeout bump, test-only).
  - `b2bc310e` — `feat(admin): add application-status, KYC, and Connected filters to All Creators`
    (AH-079).
  - `1754560e` — `feat(campaigns,boards,roster): creator profile everywhere` (AH-080).
  - `b2b3ee43` — `feat(campaigns,creators): minimal rich text for briefs and descriptions` (AH-081),
    plus `5f7c96d4` (a same-day follow-up CSS fix).
  - `f59c29c5` — `feat(campaigns): insert-link button + campaign description cap raise` (AH-082).
  - `7b86bf90` — `feat(campaigns,messaging): missing creator emails — invite received + debounced
message` (AH-083).
  - (The remainder of the range is docs-only: inventories, plan-pauses, review files, this entry's
    own predecessor entries.)
- **AH entries carried:** **AH-067, AH-068, AH-069, AH-070** (CI/process only — no deploy obligation
  of its own, carried for range completeness), **AH-071, AH-072, AH-073, AH-074, AH-075, AH-076,
  AH-077, AH-078, AH-079, AH-080, AH-081, AH-082** (zero `apps/api` diff across the last four — no new
  obligation), **AH-083.**
- **Migrations run:** **2**, either order (unrelated tables):
  1. `2026_08_16_100000_add_creator_posts_content_to_campaigns_table` (AH-069) — adds
     `campaigns.creator_posts_content boolean NOT NULL DEFAULT true`. On Postgres a
     **catalogue-only** change: no table rewrite, no existing row read or written; every existing
     campaign reads `true`, exactly today's behaviour. No follow-up backfill command — the Q1 ruling
     deleted it, since defaulting the column ON already writes what the command would have, without
     the window in which live campaigns would have read OFF.
  2. `2026_08_19_100000_create_message_email_debounces_table` (AH-083) — additive-only `CREATE
TABLE`, no existing row touched. `down()` is **lossy** — see the migration's own docblock.
- **Pre-deploy reads:** none required by either migration. Optional sanity read available
  **after** migrating, not required before: `select count(*) from campaigns where
creator_posts_content is not true;` — expect **0**. **Run: not recorded.**
- **Snapshot ID:** **not recorded** · confirmed `available` before migrating? **not recorded.** This
  is a real safety-relevant gap, not paperwork: migration 1's `down()` drops the column
  (discarding every campaign's posting posture on rollback) and migration 2's `down()` is lossy by
  its own docblock — the snapshot is what makes either rollback recoverable, and its presence at
  migrate time is unconfirmed after the fact.
- **Infra:** **queue-worker restart REQUIRED** (unchanged from both source entries' own posture) —
  AH-068 changed both draft-review mail templates in all 24 locales; AH-069 added a new mailable
  (`AssignmentCompletedOnApprovalMail`) plus new `lang/*/campaigns.php` keys ×24; AH-083 added two
  more mailables (`InviteReceivedMail`, `NewMessageMail`) plus new `lang/*/campaigns.php` and
  `lang/*/messages.php` keys ×24 each. A worker running pre-deploy code renders stale copy, and for
  the three new mailables would not know the classes at all. No cron/scheduler change, no env
  change. **Restart performed: not recorded.**
- **One-shot commands:** **none** across the whole range.
- **Post-deploy verification:** the checklist the range's own review files specify — **results not
  recorded** for any of them:
  1. `/up` green, then one authenticated request.
  2. The posting-toggle sanity count above returns **0**.
  3. A campaign's Settings tab shows the **"Deliverables are posted by creators"** switch **on** for
     an existing campaign (the safety floor).
  4. That campaign's board still renders its **Posted column** (the render filter is off for ON
     campaigns, which is every campaign that exists at deploy time).
  5. A campaign created through the form pre-sets the switch **off** (the two-layer default).
- **Flags armed:** **none.** As of this deploy, **three** feature flags exist in a registered,
  **OFF, not-armed** state — none newly OFF because of this deploy, but worth stating together since
  this is the first entry where all three coexist:
  - `job_posted_notifications_enabled` and `application_notifications_enabled` — live since the
    2026-07-31 Jobs Board arc deploy; arming both is a combined ritual Pedram runs deliberately, per
    `feature-flags.md`, independent of any code deploy.
  - `missing_creator_mails_enabled` — **new in this range** (AH-083), registered but not armed by
    this deploy; both mail legs it gates stay silent (their in-app rows still write) until armed
    separately.
  - Also still OFF and unrelated to this range: `incomplete_creator_nudge_enabled` (blocked on the
    scheduler — see the standing blocker in `RESUMPTION-TEMPLATE.md`).
- **Operator:** **not recorded.**
- **Anything unexpected:** **not recorded**, beyond the one thing already on record from when these
  entries were still open — **AH-068 was originally pushed without a deploy-log entry**; the
  now-merged entry above was written to cover it retroactively, so the worker-restart obligation it
  created was never sitting only in a review file.

---

## 2026-08-11 · Two production bug fixes (creator-typed agency admins, roster-picker search) — DEPLOYED

> **Update:** the 2026-07-31 entry below has now been closed out retroactively (see its own
> `DEPLOYED` status and evidence) in the same pass that wrote this note, so this entry no longer
> sits stacked above an open predecessor.

- **Status:** **DEPLOYED.** Reviewed and approved by Pedram; the verbatim check against the
  AH-065/AH-066 report found no divergences; pushed to `origin/main`. The deploy itself completed
  successfully — after an incident and recovery during the `composer install` step (see "Anything
  unexpected" below), which is why this entry's status moved straight to `DEPLOYED` rather than
  sitting in `PENDING` waiting on a clean run. **On-box HEAD: not yet supplied** — record it here
  once Pedram has it; the pushed range below is what was _shipped_, not independently confirmed
  as what the box is _running_.
- **Range:** `6601ba0` → `6cdf0a5` — **3 commits** (`ec8cd00` docs-only, then the two fixes):
  - `ec8cd00` — `docs(runbooks): deploy log — record of production deploys, today's entry pending`
    (this file's own creation; no runtime change).
  - `df1c56c` — `fix(creators): authorise agency teammates by membership, not users.type` (AH-065).
  - `6cdf0a5` — `fix(pickers): search the roster server-side so every creator is reachable`
    (AH-066).
- **AH entries carried:** **AH-065, AH-066.** Two independent, pre-existing-defect fixes — not a
  themed arc, no ordering dependency between them.
- **Migrations run:** **none.** Neither fix touches `apps/api/database/migrations/**`.
- **Pre-deploy reads:** **none required by this deploy's own changes.** One check was already run
  ahead of it and is recorded here for completeness rather than as a blocking step: Pedram's
  `has_table_privilege('engine_c_user', 'campaign_applications', 'SELECT,INSERT,UPDATE')` — and the
  same for `campaign_job_notifications` — both returned `true` on 2026-08-11, confirming the
  GRANTs the 2026-07-31 entry's "Anything unexpected" field describes are now in place. **No
  further on-box SQL is needed for this deploy.** Left honestly open: whether that fix predates
  today or was applied by a colleague somewhere in between, and whether the original incident was
  exactly as broad as first diagnosed in chat or narrower — neither is reconstructable now; what's
  confirmed is the _current_ state, not the fix's history.
- **Snapshot ID:** _TBD — Pedram's call whether a snapshot is proportionate here._ Deploy shape is
  code-only (below), so schema risk is genuinely zero, unlike the lossy-`down()` migrations the
  prior entry carried — but the standing checklist (`production-queue-worker.md` §8 step 1, "do
  not skip step 1") doesn't carve out an exception, so flagging rather than silently skipping.
- **Infra:** **deploy shape is code-only** — an `apps/api` PHP change plus an `apps/main` SPA
  rebuild; no schema, no flags, no scheduler/cron change. No queue-worker restart on mail/lang
  grounds (neither fix adds a mailable or touches `apps/api/lang/**`, and `CreatorPolicy` is
  resolved per-request, never inside a queued job — grepped every `ShouldQueue` class for it,
  none). **The one mandatory step: a PHP-FPM reload**, so `CreatorPolicy.php`'s new bytecode is
  actually served instead of opcache's stale copy — `CreatorPolicy` sits on the hot path of every
  agency-side creator request, and a stale copy would silently keep denying the very 26 creator-typed
  admins this deploy exists to fix. `apps/main`'s SPA bundle needs its normal rebuild + redeploy for
  the picker fix to reach browsers — standard for any frontend change, not new here.
- **One-shot commands:** **none.**
- **Post-deploy verification:** _TBD — smoke results not yet supplied; update from TBD to actual
  as Pedram provides them._ The standard checks remain `/up` 200 + one authenticated request, plus
  two fix-specific reads that cost nothing: (1) a `creator`-typed agency admin (or the SQL query
  Pedram already ran) can see a connected creator's contact details and send a relationship
  message; (2) the campaign-invite or add-to-pool search finds a creator known to sit past the old
  100-row alphabetical cutoff. **Additionally required after this incident, before calling smoke
  clean:** confirm a TOTP-enrolled user can still complete 2FA sign-in against the _recovered_ key
  — MFA was down platform-wide mid-deploy, and "recovered" is only proven by a real decrypt/verify
  round-trip on live traffic, not by the recovery procedure's own internal check alone.
- **Flags armed:** **none — this range touches no flag.**
- **Operator:** _TBD._
- **Anything unexpected: yes — an `APP_KEY` rotation mid-deploy, MFA down platform-wide, fully
  recovered.** During this deploy, `composer install --no-dev` fired the repo's install-time
  script chain, which included `@php artisan key:generate` in `post-install-cmd` — a hook Composer
  runs on **every** install, not just first scaffold (see AH-067, above). This **rotated the live
  production `APP_KEY`**. **Immediate effect:** every TOTP/MFA secret and every session became
  undecryptable under the new key — MFA down platform-wide. **Not affected:** business data,
  passwords (bcrypt/argon2 hashes are key-independent), and files. **Timeline mattered:**
  containers had already been restarted _before_ the `composer install` step, so the running
  queue/schedule workers still held the **old** key in memory even after `.env` on disk had the
  new one. **Recovery:** the old key was extracted from the still-running queue worker's process
  memory via a **host-side** memory dump (`ptrace` from the host — in-container `gcore` was
  permission-denied), verified by successfully decrypting a stored TOTP secret with it, then
  restored to `.env`, `php artisan config:cache` re-run, and PHP-FPM reloaded. **Outcome: full
  recovery, zero data loss, no user action required.** The deploy then completed successfully.
  **Root cause fixed, not worked around:** `composer.json`'s `post-install-cmd` duplicated
  `key:generate` and the `.env` bootstrap copy — both already correctly scoped to
  `post-create-project-cmd`/`post-root-package-install` (Composer hooks that fire once, at
  scaffolding, never on a redeploy). Fixed and pinned same-day — see **AH-067**.

---

## 2026-07-31 · The Jobs Board arc + the post-arc UI batch — DEPLOYED

- **Status:** **DEPLOYED** — confirmed retroactively on 2026-08-11, not recorded live at deploy
  time. **Evidence:** `6601ba0`'s **author date is `2026-07-31T19:40:30+02:00`**, an
  exact-to-the-second match against the deployed tip's timestamp established in the deploy-state
  verification pass — `git log --format='%h A=%ad C=%cd'` shows the author/committer dates
  diverge on this one commit (`C=19:47:23`), consistent with it being picked up by a deploy
  pipeline at the author timestamp and finishing its own commit metadata later. Independently,
  Pedram's production GRANT check —
  `has_table_privilege('engine_c_user', 'campaign_applications', 'SELECT,INSERT,UPDATE')` and the
  same for `campaign_job_notifications` — both return `true`, meaning chunk 3's two new tables are
  live, migrated, and (now) permissioned. Neither fact is possible unless this range shipped.
  Fields below that nobody captured live are marked **"not recorded"** rather than `TBD` — this
  deploy already happened without this file open to fill in as it went; there is no value left to
  retrieve for them, only an honest gap. Procedure that should have governed it:
  [`production-queue-worker.md` §8.3](production-queue-worker.md).
- **Range:** `f5be920` → `6601ba0` — **60 commits.** This is the **entire undeployed backlog**: every
  commit since the 2026-07-26 deploy, in one operation. `6601ba0` is the last **code** commit in the
  range; the docs commit that adds this log sits on top of it and carries no runtime change, so the
  next deploy's from-SHA is whatever tip is actually checked out, not necessarily `6601ba0`.
- **AH entries carried:** **AH-053 → AH-064.** The five-chunk Jobs Board arc (AH-053, AH-054,
  AH-056, AH-058, AH-059), the AH-055 brand-detail fix, the AH-057 eyes-on fix pass, and the
  AH-060→AH-064 UI batch. **The arc must not ship as a subset** — a partial deploy produces
  half-states nobody designed (a listable campaign with no board to list it on, or a board whose
  applications no agency can answer). §8.3 is explicit on this.
- **Migrations run:** **4**, all additive, all from arc chunks 1–3 (chunks 4 and 5 add none, and
  the whole AH-060→064 batch adds none — it has **zero `apps/api/**` diff\*\*):
  1. `2026_07_27_100000_add_jobs_board_listing_to_campaigns` (AH-054) — six nullable columns + one
     boolean defaulting `false`. No backfill.
  2. `2026_07_27_110000_create_campaign_applications_table` (AH-056) — new table. **`down()` is
     lossy.**
  3. `2026_07_27_110001_create_campaign_job_notifications_table` (AH-056) — new table, the
     once-per-pair fan-out stamp. **`down()` is lossy.**
  4. `2026_07_27_110002_add_listed_at_to_campaigns` (AH-056) — one nullable timestamp. No backfill.

  No existing row is read or rewritten by any of the four. **Rollback is not a revert:** dropping
  the two new tables destroys every application and every notification stamp. After creators have
  applied, restore from the snapshot instead.

- **Pre-deploy reads:** `php artisan brands:audit-floor` (AH-053) — **not recorded**. The
  command's purpose (sizing the population AH-053's new 422 affects, before agencies met it) is
  moot to run retroactively: agencies have been living with the behaviour since deploy, and a
  support-ticket signal would already exist if the affected population were large. Not backfilled
  with a guess; recorded as a genuine gap.
- **Snapshot ID:** **not recorded** · confirmed `available` before migrating? **not recorded** —
  this is the one field here where the gap is a real safety concern, not just paperwork, given
  step 2 carried two lossy-`down()` `CREATE TABLE`s and the restore path has **never been
  rehearsed** (§8.2) even when a snapshot ID **is** known. No retroactive fix possible; flagged for
  the next deploy's discipline.
- **Infra:** the queue-worker restart was **mandatory** on this deploy (four new mailables, 24
  locales of new backend copy; a long-running worker caches translations and would otherwise keep
  sending missing-key bodies until bounced). **Done: not recorded** — whether `queue:restart` ran
  at deploy time can't be confirmed 11+ days later, though it's moot now regardless: a live worker
  would have recycled naturally many times over in the interval either way.
- **One-shot commands:** **none.** The arc has none, and the UI batch has none.
- **Post-deploy verification:** **not recorded.** `/up` 200 and an authenticated request were not
  captured at deploy time, and this session has no production access to backfill them — an honest
  gap, not a retroactively-fabricated result. The fact that stands in for it: the
  `campaign_applications` permission error (below) proves the API was serving real traffic against
  the new schema post-deploy — live and in use, just partially broken by the missing GRANTs.
- **Flags armed:** **NONE — deliberately deferred.** Both jobs-board mail flags
  (`job_posted_notifications_enabled`, `application_notifications_enabled`) ship and stay **OFF**.
  Arming them is the combined ritual in §7.4, done when Pedram chooses, and **this deploy is
  complete and correct without it**. Also still OFF and unrelated:
  `incomplete_creator_nudge_enabled` (blocked on the scheduler, §7.3).
- **Operator:** **not reconciled.** This entry originally named Pedram, written when he expected
  to run the deploy himself. The subsequent incident report (below) referenced "my colleague
  deployed to production," which reads as the colleague having executed the actual run. Which is
  accurate isn't established here; recorded as a discrepancy rather than silently left as Pedram.
- **Anything unexpected: yes — the `campaign_applications` / `campaign_job_notifications`
  GRANTs.** Shortly after this range went live, a colleague hit
  `SQLSTATE[42501]: permission denied for table campaign_applications` in production. The
  migrating role had created chunk 3's two new tables (and their `_id_seq` sequences), but the
  application's own DB role, `engine_c_user`, was never separately granted table access — Postgres
  does not extend `CREATE TABLE` access to other roles automatically. This blocked the creator
  Jobs board, agency invites (`settlePendingApplication`), and `AutoRejectPendingApplicationsJob`
  for however long it went unfixed. **Status as of 2026-08-11: verified resolved.** Pedram ran
  `has_table_privilege('engine_c_user', 'campaign_applications', 'SELECT,INSERT,UPDATE')` → `true`,
  and the same for `campaign_job_notifications` → `true`. **Left honestly unresolved:** exactly
  when and how the GRANTs were applied, and whether the original error's scope matched the full
  missing-GRANT diagnosis made in chat or was narrower — a colleague may have fixed it directly, or
  the initial read may have overstated the blast radius. Neither is reconstructable from this
  file; what's verified is the **current** state, not the incident's complete timeline. Tracked
  going forward at [`RESUMPTION-TEMPLATE.md`](../reviews/RESUMPTION-TEMPLATE.md)'s Open threads,
  marked resolved.
- **Operator notes carried by this range** — nothing to do at deploy, but worth knowing if
  something looks odd afterwards:
  - **Assignment audit trails gain a row that has no tab behind it** (AH-058 D3b). `POST …/assignments`
    now settles a pending application for the pair it invites, in the same transaction, so an
    operator reading audit history will sometimes see a `campaign_application.accepted` row beside
    an invite nobody accepted from the Applications tab. A pair with **no** application is
    byte-identical to before, pinned field by field.
  - **The campaigns-list listing toggle shortens the path to a real fan-out** (AH-059 D3). It drives
    the same PATCH the Settings tab drives, so once the mail flags are armed a mis-click on a table
    row is one round-trip from notifying real creators. The ON direction sits behind a confirmation
    dialog for exactly that reason; OFF stays immediate. Inert at T+0 and while the flags are OFF.
  - **A 5.6 MB PDF joins the deploy artefact** (AH-063, `apps/main/public/creator-guide.pdf`).
    Harmless, but every build and deploy now carries it; logged as tech-debt with
    `catalyst-engine-public-prod` as the CDN target.
  - **The worker restart rule is the arc's, not the UI batch's.** AH-060→064 ships new copy only in
    the SPA's own `locales/**`, not `apps/api/lang/**`, and the worker renders mail from the latter.
    The restart is mandatory here on the **arc's** grounds (four new mailables, 24 locales of new
    backend copy).

> **⚠ One thing in this range goes live immediately and has no flag: the Meta Pixel (AH-064).**
> From the moment this deploys, every visitor to `/sign-in` — the platform's front door — is
> tracked without consent and `_fbp` is set on their browser. That is Pedram's recorded decision,
> taken with the §2.1/§2.7 consent conflict and the UK PECR exposure on the table, and it is logged
> in `../tech-debt.md` with a Sprint-14 resolve-by against the CMP. It is called out here because
> it is the only externally-visible consequence in this deploy that is **not** dormant behind a
> flag: there is no switch to turn it off, only a code change. Everything else the arc adds is inert
> at T+0.

> **Why T+0 is quiet otherwise.** At deploy, **zero campaigns are listed** —
> `listed_on_jobs_board` is a new column defaulting `false`, and the surfaces that flip it are in
> this same deploy. So the board is empty, no fan-out has a recipient, no application exists to
> answer, and every path the arc adds is inert until an operator lists a campaign. That is the
> arc's primary containment, and it is a fact about the data rather than a hope.

---

## 2026-07-26 · AH-051 / AH-052 — canonical 403 envelope + admin-initiated connections — DEPLOYED

- **Status:** DEPLOYED. This is the tip production ran until the 2026-07-31 deploy above.
- **Range:** → `f5be920`
- **AH entries carried:** AH-051 (admin-initiated agency↔creator connections, the AH-005 contact-gate
  tightening, the first termination path via `ended`) and AH-052 (canonical 403 error envelope),
  plus the AH-051 post-close eyes-on fixes.
- **Migrations run:** **none.** `php artisan migrate` correctly reported **`Nothing to migrate`** —
  `ended` is a plain-varchar enum value with no CHECK constraint, so the sixth `RelationshipStatus`
  needed no schema change.
- **Pre-deploy reads:** none as such — the contact-exposure audit was run **post**-deploy (see
  below), because the gate was already live by the time it was read.
- **Snapshot ID:** not recorded. (Reconstructed entry — the snapshot step predates this log, so
  whether one was taken is not on record. With no migration in the range the exposure was low, but
  the ID is missing, and that gap is part of why this file exists.)
- **Infra:** the queue-worker restart **was** required and **was** performed — the range carries the
  `530d7d8` / `bdc957b` admin-connected mail-body trims across 24 locales. This is where the
  standing rule came from: the new copy did not appear until the worker was bounced, because the
  long-running process caches translations in memory.
- **One-shot commands:** both of the then-outstanding one-shots were run, each dry-run first, and
  **both are now closed**:
  - `php artisan creators:recompute-completeness` (AH-026 D5) — **279 creators checked, 1 score
    updated.** The near-zero delta is the expected result, not a failure: it means the persisted
    scores were already consistent with the AH-026 formula for all but one row.
  - `php artisan campaigns:advance-contractless-accepted` (AH-042 D4) — **0 eligible rows**, so it
    was closed as moot. Nothing was ever stuck at `accepted` on a `requires=false` campaign. The
    command stays in the codebase as a safety net.
- **Post-deploy verification:** `/up` 200 + an authenticated request. Additionally
  `php artisan migrate:status` was read on this date and reported **Ran through batch 5** — the read
  that established the pre-history below, and which incidentally revealed that everything through
  AH-050 was **already live** while the resumption template still listed its migrations as pending.
- **Post-deploy read, recorded with no action taken:** `php artisan relations:audit-contact-exposure`
  (AH-051 D-1) — **2 `pending_request` relations across 1 agency, of which 1 had contact data
  populated.** That is the realized blast radius of the contact-gate tightening: one agency lost
  visibility of one creator's contact details. Judged small enough that no remediation or
  notification was warranted.
- **Flags armed:** none.
- **Operator:** Pedram.
- **Anything unexpected:** **two things, both of which became standing rules.** (1) The
  queue-worker translation cache, above — found the hard way while verifying the mail trims, and now
  a permanent deploy obligation for any `lang/**` change. (2) **AH-052 changed a client-visible
  contract in production**: every `authorize()` denial (82 call sites) and every `abort(403)` now
  returns the JSON:API error envelope with code `auth.forbidden` instead of Laravel's default
  `{"message": …}`. Both SPAs consume the envelope and were verified; the residual exposure is
  anything **outside this repo** that pattern-matched the old shape. Live since this date, so it is
  a thing to check **if** 403 handling misbehaves, not a pre-deploy gate.

---

## Pre-history — the colleague-era deploys (RECONSTRUCTED, dates unknown)

> **⚠ Everything in this section is reconstructed, not recorded.** These deploys happened before
> this log existed and were **colleague-managed**, so no operator, snapshot ID, verification result
> or date survives for any of them. What follows is inferred from two sources only: the prod
> `php artisan migrate:status` read on **2026-07-26** (which reported **Ran through batch 5**, with
> `2026_07_13_110000_backfill_cancelled_rejected_board_column` in **batch 3**), and the migration
> filenames mapped onto the AH entries that introduced them. **Treat the batch→AH mapping as a
> hypothesis, not a record.**
>
> Ordered **oldest first** here — the deliberate exception to this file's newest-first rule, because
> these entries are undated and only their relative order is knowable.
>
> **How to close this gap, if it ever matters:** run `php artisan migrate:status` on prod and paste
> the full output into this section. That resolves every batch boundary in one read. Nothing else
> can — the information does not exist anywhere else.

| Batch | Migrations (by date cluster)                                                                                                                                                                                                                                               | AH range / origin                                                                               | Confidence                                                                                                                  |
| ----- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| 1     | The Phase-1 spine — `0001_01_01_*` through the Sprint 0–13 build (users, agencies, creators, campaigns, boards, notifications, messaging, contracts, talent pools, impersonation). **56 migrations.**                                                                      | Sprints 0–13 + 3.5, pre-AH                                                                      | **Inferred.** The initial production deploy; the batch boundary is not on record. The count is exact (73 total − 17 later). |
| 2     | `2026_06_27` → `2026_07_08`, **7 migrations** — portfolio `processing_status` (`7b62272`, **AH-004**), creator contact details (`5dc1e1f`), the three relationship-messaging tables (`2656e5a`, **AH-010a**), creator `accent` (`7faeff8`), users `last_name` (`ce3bbda`). | AH-004 and AH-010a confirmed from the adding commits; the other three commits name no AH number | **Inferred** from the date cluster. Commits are exact (`git log --diff-filter=A`); the batch grouping is not.               |
| 3     | `2026_07_12` → `2026_07_13` — assignment offer fields, `previously_declined`, draft `links`, and the board-column backfill.                                                                                                                                                | AH-033 → AH-041 (the direct-iteration fix batch), incl. AH-034, AH-035, AH-040, AH-041          | **Anchored** — the AH-041 backfill is the one migration prod explicitly placed in batch 3.                                  |
| 4     | `2026_07_16_100000_add_incomplete_nudge_sent_at_to_creators_table`                                                                                                                                                                                                         | AH-048 (incomplete-creator nudge) — additive-nullable column, no backfill, no index             | **Inferred.** Confirmed `Ran`; the batch number is not on record.                                                           |
| 5     | `2026_07_19_100000_add_content_companions_to_creators_table`                                                                                                                                                                                                               | AH-050 (content companions) — additive-nullable jsonb                                           | **Inferred**, but bounded: batch 5 was the highest on 2026-07-26, and this is the newest migration that was `Ran` by then.  |

**What is solid about the pre-history, regardless of batch numbers:** as of the 2026-07-26 read,
**every migration through `2026_07_19` was `Ran`** and nothing was pending. AH-042 through AH-052
added no migrations at all. So the pending-migration list was **empty** going into the 2026-07-26
deploy, and the next four pending migrations are the arc's — the ones in the 2026-07-31 entry above.

**The lesson this pre-history taught, now a standing convention.** Everything through AH-050 turned
out to be **already live** while the resumption template still listed its migrations as pending.
Deploys advance without notice, so deploy state must be **read from the server and written down**,
never inferred from push history. That is the rule this file exists to enforce.
