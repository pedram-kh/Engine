# Runbook — Production queue worker

> Operational reference for running the Laravel queue worker in production.
> Written after a live incident (2026-07-09): portfolio image uploads stuck at
> "Processing…" because no worker was consuming the queue. The upload path is
> async by design (AH-004) — **without a running worker, images never become
> visible**, emails/notifications never send, and webhook/saga jobs never run.

---

## 1. What the worker is for

Every job in the app dispatches to the **`default` queue** on the connection
named by `QUEUE_CONNECTION` (no `onQueue()`/`onConnection()` overrides exist
in the codebase). The most user-visible consumers:

| Flow                                  | Job                        | Symptom when the worker is down            |
| ------------------------------------- | -------------------------- | ------------------------------------------ |
| Portfolio image upload (AH-004)       | `ProcessPortfolioImageJob` | Item stuck at "Processing…" forever        |
| Bulk creator invitations              | `BulkCreatorInvitationJob` | "Queued — 0% complete" card never advances |
| Mail (verification, invites, digests) | queued Mailables           | Emails silently never sent                 |
| Webhooks (KYC / e-sign / Stripe)      | `Process*WebhookJob`       | Vendor events accepted but never processed |

The portfolio job is the reason the worker's memory flag is load-bearing: it
decodes full-resolution images up to 50 MP (`PortfolioImageProcessor::MAX_MEGAPIXELS`),
which needs far more than PHP's default 128 MB. The canonical invocation —
identical to the local `dev:queue` script — is:

```bash
php artisan queue:work --memory=768 --tries=3 --max-time=3600
```

- `--memory=768` — the AH-004 matched pair (see `local-dev.md` §7.2): if
  `MAX_MEGAPIXELS` ever rises toward 100, this must rise to ~1 GB+ with it.
- `--tries=3` — transient failures (S3 blips) retry; real failures land in
  `failed_jobs` instead of looping forever.
- `--max-time=3600` — the worker exits cleanly every hour and the supervisor
  restarts it, which bounds memory creep and picks up newly deployed code.

The worker **must run with the same `.env` as the API** (same
`QUEUE_CONNECTION`, same DB/Redis, same AWS credentials) — a worker on a
different connection consumes nothing and the queue silently piles up.

---

## 2. supervisord (the standard setup on a plain VPS)

The program config is **version-controlled** at
[`infra/supervisor/catalyst-worker.conf`](../../infra/supervisor/catalyst-worker.conf)
— that repo file is the **source of truth**; the copy under
`/etc/supervisor/conf.d/` is installed _from it_, never hand-edited in place.
Its contents:

```ini
[program:catalyst-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/catalyst/apps/api/artisan queue:work --memory=768 --tries=3 --max-time=3600
directory=/var/www/catalyst/apps/api
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
; queue:work traps SIGTERM and finishes the in-flight job before exiting.
; Give it long enough for a worst-case 50 MP image decode:
stopwaitsecs=120
redirect_stderr=true
stdout_logfile=/var/log/catalyst/worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
```

Install it (alongside the §7.1 scheduler config — the two ship together):

```bash
sudo mkdir -p /var/log/catalyst
sudo cp infra/supervisor/catalyst-worker.conf    /etc/supervisor/conf.d/
sudo cp infra/supervisor/catalyst-scheduler.conf /etc/supervisor/conf.d/   # see §7.1
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start catalyst-worker:* catalyst-scheduler:*
sudo supervisorctl status          # both must read RUNNING (see below)
```

Expected `supervisorctl status`:

```
catalyst-scheduler                 RUNNING
catalyst-worker:catalyst-worker_00 RUNNING
```

If the real deploy location or PHP-FPM user differs from
`/var/www/catalyst/apps/api` / `www-data`, change `command=`/`directory=`/`user=`
**in the repo file** and re-install — keep the worker and scheduler configs in
agreement on paths, user, and `.env`. Raise `numprocs` only when a single worker
can't keep up (watch queue depth, §5) — each process must be budgeted ~768 MB+
of RAM. (The scheduler, by contrast, must stay `numprocs=1` — see §7.1.)

## 3. systemd (alternative, if supervisord isn't installed)

`/etc/systemd/system/catalyst-worker.service`:

```ini
[Unit]
Description=Catalyst Engine queue worker
After=network.target redis-server.service

[Service]
User=www-data
WorkingDirectory=/var/www/catalyst/apps/api
ExecStart=/usr/bin/php artisan queue:work --memory=768 --tries=3 --max-time=3600
Restart=always
RestartSec=3
# SIGTERM lets the in-flight job finish; allow a worst-case decode:
TimeoutStopSec=120

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now catalyst-worker
sudo systemctl status catalyst-worker
```

Pick **one** of §2/§3 — never both (two supervisors fighting over restarts).

---

## 4. Deploy hook (required, every deploy)

Workers resolve Job classes at boot and hold them in memory. After every code
deploy, tell running workers to exit after their current job (the supervisor
restarts them on the new code):

```bash
php artisan queue:restart
```

Skipping this runs **stale job code** until the next `--max-time` recycle —
a classic source of "the fix is deployed but the bug still happens" confusion.

The long-running `schedule:work` process (§7.1) caches command classes at boot
the same way, and — unlike the worker — does **not** watch the `queue:restart`
signal. Restart it explicitly through Supervisor on every deploy so it picks up
new code:

```bash
sudo supervisorctl restart catalyst-scheduler:*
```

So a full deploy restarts **both** programs — the worker via `queue:restart`, the
scheduler via `supervisorctl restart` — and `supervisorctl status` should read
`RUNNING` for `catalyst-worker` **and** `catalyst-scheduler` afterward. If this
deploy changed either repo config under `infra/supervisor/`, re-install first
(`cp … /etc/supervisor/conf.d/ && supervisorctl reread && update`, §2) so the
restart picks up the new program definition. (If you drive the scheduler by
cron/systemd `schedule:run` instead of §7.1, the scheduler restart is
unnecessary — each tick is a fresh process that boots the new code.)

---

## 5. Troubleshooting

**Symptom: portfolio image stuck at "Processing…" / bulk invite stuck at 0%.**

```bash
# 1. Is a worker alive?
sudo supervisorctl status catalyst-worker:*     # or: systemctl status catalyst-worker
ps aux | grep "queue:work"

# 2. Is work piling up? (QUEUE_CONNECTION=redis, the default)
php artisan tinker --execute="echo Redis::connection()->llen('queues:default');"
#    (QUEUE_CONNECTION=database instead:)
php artisan tinker --execute="echo DB::table('jobs')->count();"

# 3. Did jobs crash out?
php artisan queue:failed
```

- **Worker down, jobs queued** → start the worker; the backlog drains and
  stuck items resolve on their own (a stuck-`processing` portfolio item flips
  to `ready` with no re-upload needed).
- **Worker up, queue empty, item still `processing`** → the job was lost
  (dispatched before the worker existed on a queue that was since flushed, or
  dispatched under a different `QUEUE_CONNECTION`). The creator deletes the
  stuck item (✕) and re-uploads; the design keeps this safe (`ProcessPortfolioImageJob`
  marks decode failures `failed`, never silent — a _lost_ job is the only way
  to get forever-`processing`).
- **Jobs in `queue:failed`** → `php artisan queue:failed` shows the exception;
  `php artisan queue:retry <id>` re-runs after fixing the cause. An
  OOM-killed worker (image decode with too little memory) bypasses the
  `failed` marking — if you see the worker process dying around large images,
  check the `--memory` flag and the machine's available RAM first.

---

## 6. Related

- `local-dev.md` §7 — the local counterpart (why `pnpm dev` spawns a worker,
  sync-queue behaviour in tests, stale-job cleanup after `db:reset`).
- `docs/reviews/ah-004-portfolio-overhaul-plan.md` §6 — the 50 MP cap ↔ worker
  memory matched pair.
- `docs/tech-debt.md` — "Completeness-formula changes need a manual
  `creators:recompute-completeness` run": a **separate** post-deploy artisan
  step (one-shot, idempotent), not a worker concern, listed here so deploy
  checklists find both.

---

## 7. The scheduler — required, separate from the worker

The queue worker (§1–§6) drains jobs that have **already been dispatched**. It
does **not** trigger time-based commands. Those are registered in
`apps/api/bootstrap/app.php` via `withSchedule()` and only fire if Laravel's
scheduler runs once a minute:

| Command                           | Cadence   | What it does when it fires                                                           |
| --------------------------------- | --------- | ------------------------------------------------------------------------------------ |
| `messages:send-digest`            | `daily()` | Queues the daily unread-messages digest (Sprint 11, D-9).                            |
| `boards:scan-overdue`             | `daily()` | Fires overdue board events (Sprint 12 Chunk 3, D-5; class `ScanOverdueAssignments`). |
| `creators:send-incomplete-nudges` | `daily()` | The incomplete-creator nudge — **a no-op until the flag is enabled** (see below).    |

**Without a running scheduler (§7.1), none of these ever fire** — the
incomplete-creator nudge will silently never send even after the flag is flipped
ON. This is the scheduler's equivalent of the §1 "no worker → nothing sends"
incident.

### 7.1 The scheduler process — `schedule:work` under Supervisor (our setup)

We run the scheduler as a **long-running `schedule:work` process under the same
Supervisor that manages the queue worker (§2)** — not a crontab. `schedule:work`
stays resident and invokes the due commands itself every minute, so the
scheduler gets the same autostart / autorestart / centralised-logging treatment
as the worker, with no crontab to maintain.

The program config is **version-controlled** at
[`infra/supervisor/catalyst-scheduler.conf`](../../infra/supervisor/catalyst-scheduler.conf)
— the **source of truth**, installed to `/etc/supervisor/conf.d/` the same way
as the worker (§2), never hand-edited in place. Its contents:

```ini
[program:catalyst-scheduler]
process_name=%(program_name)s
command=php /var/www/catalyst/apps/api/artisan schedule:work
directory=/var/www/catalyst/apps/api
user=www-data
numprocs=1
autostart=true
autorestart=true
; schedule:work may be mid-command when told to stop; let a tick finish.
stopwaitsecs=60
redirect_stderr=true
stdout_logfile=/var/log/catalyst/scheduler.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
```

It installs together with the worker in the §2 `cp … && supervisorctl reread &&
update` step — there is no separate procedure. `command=`/`directory=`/`user=`
mirror the §2 worker exactly (same deploy path, PHP-FPM user, and `.env`); if you
change one, change both. After install, `supervisorctl status` must show
`catalyst-scheduler  RUNNING` alongside `catalyst-worker` (see §2).

- **This replaces the cron line.** Do **not** also run `* * * * * schedule:run`
  (or the §7.2 systemd timer) while this program is active — the two tick
  independently and every daily command fires **twice** (two nudge emails per
  creator). Exactly one scheduler mechanism, ever.
- **`numprocs=1` — exactly one instance, cluster-wide.** Unlike the worker
  (which scales to N processes), the scheduler must be a **single** process on a
  **single** host. Two `schedule:work` processes — whether `numprocs=2` here or
  one per host in a multi-server deployment — double-fire every scheduled
  command. Today's commands are **not** guarded with `onOneServer()`, so there
  is **no framework-level protection** against a second instance; the
  one-instance rule is operational, not enforced in code. **Before** the
  scheduler ever runs on more than one host, add `->onOneServer()` to each
  scheduled command in `withSchedule()` (it needs a shared atomic-lock cache —
  Redis) so only one host wins the tick.
- Runs with the **same `.env` as the API + worker** (same DB, same
  `QUEUE_CONNECTION`) — the scheduled commands only _queue_ Mailables, so the §1
  worker must also be running for anything to be delivered.
- **`stopwaitsecs=60`** lets an in-flight tick finish on restart/deploy instead
  of being killed mid-command.

### 7.2 Alternative: cron or systemd timer (if you're not using Supervisor)

If you are **not** running the §7.1 `schedule:work` program, drive the scheduler
with a per-minute `schedule:run` instead — the classic Laravel production setup.
Use exactly one of these, and never alongside §7.1.

**Cron** — a single line, every minute; Laravel decides internally which
commands are actually due:

```cron
* * * * * cd /var/www/catalyst/apps/api && php artisan schedule:run >> /dev/null 2>&1
```

Add it to the deploy user's crontab (`crontab -e` as `www-data`, or a file in
`/etc/cron.d/`), running with the **same `.env` as the API + worker**.

**systemd timer** — `/etc/systemd/system/catalyst-scheduler.service` + `.timer`:

```ini
# catalyst-scheduler.service
[Unit]
Description=Catalyst Engine scheduler tick

[Service]
Type=oneshot
User=www-data
WorkingDirectory=/var/www/catalyst/apps/api
ExecStart=/usr/bin/php artisan schedule:run
```

```ini
# catalyst-scheduler.timer
[Unit]
Description=Run the Catalyst scheduler every minute

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now catalyst-scheduler.timer
sudo systemctl list-timers catalyst-scheduler.timer
```

Pick **one** scheduler mechanism total — the Supervisor `schedule:work` program
(§7.1, our setup), the cron line, or the systemd timer. Never more than one, and
the one-instance-cluster-wide constraint from §7.1 applies to all three.

### 7.3 First-enable procedure for the incomplete-creator nudge

The nudge command runs daily but is gated by the `incomplete_creator_nudge_enabled`
Pennant flag (default **OFF** — see `docs/feature-flags.md`). To turn it on
safely:

1. **Preview volume (mutates nothing, ignores the flag):**

   ```bash
   php artisan creators:send-incomplete-nudges --dry-run
   # → [dry-run] would send N nudge(s): verify=X, finish=Y (cap 50). No changes made.
   ```

   The `(cap 50)` is the **per-run limit** — the command sends at most 50
   nudges per run by default, **oldest-first** (by `creators.created_at`), so a
   large backlog drains deterministically over successive daily ticks rather
   than blasting everyone on the first enable. Override with `--limit=N` (e.g.
   `--dry-run --limit=200` to preview a larger drain). `--limit=0` or a
   non-numeric value fails loudly (exit non-zero, nothing sent).

2. **Read the counts.** `verify` = unverified self-serve creators sitting
   incomplete 48h+; `finish` = verified-but-incomplete. If the numbers look
   sane (no surprise backlog spike), proceed. If the total is far above the cap
   and you want a slower drain, leave the default; to drain faster, raise
   `--limit` (the scheduled command uses the default cap — change it there only
   if you deliberately want a bigger daily batch).
3. **Flip the flag ON** from the admin **Feature-flags** page ("Incomplete-creator
   nudge"). A reason is **mandatory** and is written to the audit log
   (`feature_flag.toggled`).
4. The next daily tick sends up to the cap (oldest-first) and stamps
   `creators.incomplete_nudge_sent_at` on **only** the creators it sent to — the
   once-only guard. Anyone over the cap is untouched and picked up by the next
   run. A second run over the same set sends zero. Confirm the worker (§1) is
   draining the mail queue.

To pause it again, flip the flag OFF from the same page (again with a reason).
Already-stamped creators are never re-nudged regardless.

> **Deploy-note territory (template Part 2).** Production needs the scheduler
> (§7.1 `schedule:work` under Supervisor — our setup — or a §7.2 cron/timer) in
> place alongside the queue worker. The two pending one-shot commands
> (`creators:recompute-completeness` and any future backfill) are _manual_
> post-deploy steps and are **not** on the scheduler — don't conflate them with
> the scheduler process.

---

## 8. Deploy order (the checklist)

> **Why this lives here.** We chose to extend this runbook with the deploy
> checklist rather than spin up a separate `production-deploy.md` — deploy,
> worker, and scheduler ops are one operational surface, and a single file
> means an operator finds every obligation in one place. This section is the
> concrete procedure behind `PROJECT-WORKFLOW.md` §5.40 (Production-data
> safety). **We are live** — treat every deploy carrying a migration, backfill,
> or one-shot command as capable of destroying irreplaceable data.

Run these steps **in order**. Do not skip step 1.

1. **Manual DB snapshot — and verify it completed before proceeding.** Take an
   RDS snapshot (or the equivalent for the live DB) and **wait for it to report
   `available`**. A snapshot that is still `creating` is not a safety net. Record
   its identifier — it goes in the deploy record (step 6) and is what you restore
   from if step 2 or 4 goes wrong. **Never** proceed to a migration on the word
   of a snapshot you have not seen finish.
2. **`php artisan migrate`.** Migrations are additive-first (§5.40); a deploy
   should not carry a destructive migration without a separately-reviewed plan.
   Read the output — confirm the exact migration range that ran.
3. **Infra changes** — install/update the Supervisor programs from the
   version-controlled configs (`cp infra/supervisor/*.conf
/etc/supervisor/conf.d/ && supervisorctl reread && update`, §2), covering both
   the worker and the §7.1 `schedule:work` scheduler (our setup — or a §7.2
   cron/timer); env var changes; worker + scheduler (re)starts (§4 deploy hook).
   Apply these before running any command that depends on them. Confirm
   `supervisorctl status` shows both `catalyst-worker` and `catalyst-scheduler`
   RUNNING.
4. **One-shot commands** — each with `--dry-run` first **where supported**: run
   the dry-run, **read the output**, confirm the counts/rows look sane, then run
   for real. One command at a time; do not batch-fire.
5. **Smoke-verify** — `GET /up` returns **200**, and **one authenticated request
   succeeds** (log in / hit an authed endpoint). If either fails, stop and
   assess before considering the deploy done.
6. **Record the deploy** — date, migration range (from step 2), snapshot ID
   (from step 1), and every command run (from step 4), appended to the deploy
   log / this runbook's history or the resumption template, so the next deploy
   and any incident review can reconstruct exactly what shipped.

### 8.1 First concrete instance — the current pending-deploy list

The pending-deploy obligations carried in `RESUMPTION-TEMPLATE.md` Part 2, mapped
onto the checklist above (this is the next real deploy):

1. **Snapshot** — take it, wait for `available`, record the ID.
2. **`php artisan migrate`** — this range carries the \*\*AH-033–AH-041 migrations
   - the AH-041 board backfill** (default-name-only rename predicate,
     agency-rename-survives test — see §5.40) and **AH-048's additive-nullable
     `creators.incomplete_nudge_sent_at` column\*\* (metadata-only `ADD COLUMN`, no
     backfill, no index).
3. **Infra** — install both Supervisor programs from `infra/supervisor/*.conf`
   (§2) and confirm `catalyst-worker` + `catalyst-scheduler` are RUNNING, so
   `messages:send-digest`, `boards:scan-overdue`, and
   `creators:send-incomplete-nudges` actually fire.
4. **One-shot commands** (each `--dry-run` first, read, then real):
   - `php artisan creators:recompute-completeness` (AH-026 D5) — idempotent; a
     second run reports 0 changes.
   - `php artisan campaigns:advance-contractless-accepted` (AH-042 D4) —
     idempotent; scoped to `accepted` + `requires=false`.
   - The incomplete-creator nudge is **NOT** a one-shot here — it is a flag flip
     (§7.3), done from the admin after the dry-run count looks sane. Not on this
     list; noted so it isn't run as a command by mistake.
5. **Smoke-verify** — `/up` 200 + one authenticated request.
6. **Record** — date, the AH-033–041 + AH-048 migration range, the snapshot ID,
   and the two one-shot commands run.

### 8.2 Backup/restore posture — honest audit (standing open item, owned by Pedram)

The checklist above assumes a working snapshot-and-restore path. That assumption
is **currently unverified**:

- **RDS automated snapshots** — assumed enabled; **not confirmed** on this
  deployment.
- **PITR (point-in-time recovery) retention** — retention window **not
  confirmed**.
- **A tested restore** — **never rehearsed.** A snapshot you have never restored
  from is a hope, not a backup.

Until a restore has been rehearsed **once**, end-to-end (snapshot → restore to a
scratch instance → verify data integrity), the §5.40 production-data-safety
standard is **incomplete** and every deploy should lean even more conservatively.
This is a standing open item owned by Pedram, mirrored in
`RESUMPTION-TEMPLATE.md` Part 2 → Open threads.
