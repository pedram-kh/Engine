# infra/supervisor

Version-controlled [Supervisor](http://supervisord.org/) program configs for the
two always-on Catalyst Engine background processes. **These files are the source
of truth** — the copies under `/etc/supervisor/conf.d/` on the server are
installed _from here_, not hand-edited in place.

| File                      | Program              | Runs                        | Purpose                                                        |
| ------------------------- | -------------------- | --------------------------- | -------------------------------------------------------------- |
| `catalyst-worker.conf`    | `catalyst-worker`    | `php artisan queue:work`    | Drains dispatched jobs (mail, image processing, saga jobs).    |
| `catalyst-scheduler.conf` | `catalyst-scheduler` | `php artisan schedule:work` | The always-on scheduler tick — fires the `->daily()` commands. |

Full rationale lives in the runbook: [`docs/runbooks/production-queue-worker.md`](../../docs/runbooks/production-queue-worker.md)
§2 (worker) and §7.1 (scheduler).

## Install / update (on the server)

```bash
sudo mkdir -p /var/log/catalyst
sudo cp infra/supervisor/catalyst-worker.conf    /etc/supervisor/conf.d/
sudo cp infra/supervisor/catalyst-scheduler.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start catalyst-worker:* catalyst-scheduler:*
sudo supervisorctl status          # both should read RUNNING
```

Expected `supervisorctl status`:

```
catalyst-scheduler                 RUNNING
catalyst-worker:catalyst-worker_00 RUNNING
```

## Conventions & constraints

- **Paths / user are canonical.** `command=`, `directory=`, and `user=` assume
  the API at `/var/www/catalyst/apps/api` running as `www-data`. If the real
  deploy location or PHP-FPM user differs, change it **here** and re-install —
  keep both files in agreement (they must share the same `directory=`, `user=`,
  `.env`, DB, and `QUEUE_CONNECTION`).
- **Scheduler is one instance, cluster-wide.** `catalyst-scheduler.conf` is
  `numprocs=1` and must run on exactly **one** host. A second instance
  (a second `numprocs`, or one per host in a multi-server deploy) double-fires
  every scheduled command — the daily commands are **not** guarded with
  `->onOneServer()`. Add `->onOneServer()` (Redis lock) in `withSchedule()`
  before ever scaling the scheduler onto a second host. The worker, by contrast,
  may raise `numprocs` freely (budget ~768 MB+ RAM each).
- **Restart both on every deploy.** Both processes cache resolved classes at
  boot; the deploy hook restarts them so new code takes effect — see the runbook
  §4.
