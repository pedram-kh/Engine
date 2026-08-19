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
- **Range:** `<from-SHA>` → `<to-SHA>` (from-SHA exclusive: the previous deployed tip)
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

## 2026-08-19 · AH-085 — brand select pagination fix — PENDING

- **Status:** **PENDING.**
- **Range:** `39788759` → `e6488eb1` (from-SHA exclusive — the previous deployed tip). **4
  commits, all today, one themed fix:**
  - `edb30dd7` — `fix(brands): every brand reachable in select pickers, not just page 1` (AH-085).
  - `41b7b95b` — `docs(reviews): AH-085 brand select pagination bug — diagnosis, consumer table,
fix` (docs-only).
  - `6f5351de` — `fix(api): rename BrandSelectOptionsTest helper — collided with an existing
global function` (AH-085) — a same-day CI-fix: the first push's CI run failed on a PHP fatal
    `Cannot redeclare function`, a test-helper naming collision with an unrelated existing test
    file, caught and fixed before this entry was opened. No production runtime file in this
    commit — test-only.
  - `e6488eb1` — `docs(reviews): AH-085 — record the CI-fix commit and the green run at tip`
    (docs-only).
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
  * **PHP-FPM reload REQUIRED** — `BrandController::index`'s new `?for=select` branch is opcached
    bytecode like any other controller change; without a reload, FPM workers keep serving the
    pre-fix compiled code and the fix does not take effect for any request until workers cycle or
    a reload forces fresh bytecode. Same posture as the 2026-08-11 `CreatorPolicy` deploy above.
  * `apps/main`'s SPA bundle needs its normal rebuild + redeploy for the five fixed pickers to
    reach browsers — standard for any frontend change, not new here.
- **One-shot commands:** **none.**
- **Post-deploy verification:** the standard `/up` 200 + one authenticated request, plus two
  fix-specific reads: (1) an agency with more than 25 active brands shows every brand — not just
  the first alphabetical page — in the campaign-create Brand select; (2) the same agency's pool
  create/edit Brand select reaches the same full set. **Results: not yet supplied.**
- **Flags armed:** **none — this range touches no flag.**
- **Operator:** _TBD._
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
