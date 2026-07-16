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

`/etc/supervisor/conf.d/catalyst-worker.conf`:

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

Adjust `command=`/`directory=` paths and `user=` to the real deploy location
and PHP-FPM user. Then:

```bash
sudo mkdir -p /var/log/catalyst
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start catalyst-worker:*
sudo supervisorctl status          # → catalyst-worker:catalyst-worker_00  RUNNING
```

Raise `numprocs` only when a single worker can't keep up (watch queue depth,
§5) — each process must be budgeted ~768 MB+ of RAM.

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

## 7. The scheduler (`schedule:run`) — required, separate from the worker

The queue worker (§1–§6) drains jobs that have **already been dispatched**. It
does **not** trigger time-based commands. Those are registered in
`apps/api/bootstrap/app.php` via `withSchedule()` and only fire if Laravel's
scheduler runs once a minute:

| Command                           | Cadence   | What it does when it fires                                                           |
| --------------------------------- | --------- | ------------------------------------------------------------------------------------ |
| `messages:send-digest`            | `daily()` | Queues the daily unread-messages digest (Sprint 11, D-9).                            |
| `boards:scan-overdue`             | `daily()` | Fires overdue board events (Sprint 12 Chunk 3, D-5; class `ScanOverdueAssignments`). |
| `creators:send-incomplete-nudges` | `daily()` | The incomplete-creator nudge — **a no-op until the flag is enabled** (see below).    |

**Without `schedule:run`, none of these ever fire** — the incomplete-creator
nudge will silently never send even after the flag is flipped ON. This is the
scheduler's equivalent of the §1 "no worker → nothing sends" incident.

### 7.1 The cron entry (standard)

The scheduler is a single cron line that runs **every minute**; Laravel decides
internally which commands are actually due:

```cron
* * * * * cd /var/www/catalyst/apps/api && php artisan schedule:run >> /dev/null 2>&1
```

Add it to the deploy user's crontab (`crontab -e` as `www-data`, or a file in
`/etc/cron.d/`). The command must run with the **same `.env` as the API +
worker** (same DB, same `QUEUE_CONNECTION`) — the scheduled commands only
_queue_ Mailables, so the §1 worker must also be running for anything to be
delivered.

### 7.2 systemd timer (alternative, if you prefer no crontab)

`/etc/systemd/system/catalyst-scheduler.service` + `.timer`:

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

Pick **one** of the cron / timer approaches — never both.

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

> **Deploy-note territory (template Part 2).** Production needs this
> `schedule:run` cron/timer in place alongside the queue worker. The two
> pending one-shot commands (`creators:recompute-completeness` and any future
> backfill) are _manual_ post-deploy steps and are **not** on the scheduler —
> don't conflate them with this cron.
