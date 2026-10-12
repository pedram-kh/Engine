# AH-102b — admin observability for the creator app — PLAN (plan-pause)

- **Status:** **Awaiting plan review.** No code until it is cleared. Both commits (inventory + this plan) are held, unpushed.
- **Date:** 2026-10-11
- **Entry:** **AH-102b** (A: push delivery tracking + admin visibility; B: creator-app usage admin section; plus the
  admin-sign-in flake rider).
- **Author:** Cursor, against the rulings Q1–Q15 given on the inventory.
- **HEAD:** inventory `65d2b306` + this plan commit, both local, **not pushed**; `origin/main` is `b21b7b2f`, pushed,
  **not deployed** (nothing from AH-099…AH-102 is on production).
- **Inventory this plan is cut from:** [`admin-observability-inventory.md`](admin-observability-inventory.md). Its
  evidence is re-used by section reference (`inv §…`); anything this plan newly read is cited inline.
- **Orientation re-read at plan time:** `WORKING-PROCESS.md` (Mode A, §5.34, §5.35, §5.41, deploy-log disposition),
  `push-notifications-plan.md` (format), `docs/runbooks/deploy-log.md` (the open PENDING chain), and the code paths
  cited below.

---

## 0. The live-data risk line

> **⚠️ PROD-DATA RISK: LOW.** Nothing in this chunk is deployed yet, the push flag is OFF, and the only write path
> that runs against live data (the recorder) cannot execute while the flag is OFF (`PushDispatcher.php:82-84` returns
> before any job is queued).
>
> **What touches existing rows/behaviour, and how it is contained**
>
> 1. **`PushTokenRegistry::liveQuery()` is refactored** (Q11) to share its PAT-liveness predicate. This is the one
>    change to a shipped, tested query. Containment: behaviour-identical by construction, **proven by
>    `PushTokenRegistryTest` passing unedited** (its negative set `:207-284`), plus a direct test of the extracted
>    scope and a per-clause break-revert (§5, BRB-8).
> 2. **`PushDeliveryRecorder::record()` gains a database write inside the push job.** Risk: a failing insert fails
>    the job, the job retries, and the user gets a **duplicate push** (`Delivered` + insert-throws → retry →
>    `send()` again). Containment is the accepted §2.5 design: every step of `record()` separately contained;
>    **never rethrows**; invalidate-first ordering; the likely real production failure (missing `GRANT` on the new
>    table/sequence for `engine_c_user`) is a named deploy step with an empty-table-after-arming read-back (§7).
>    Pinned by break-reverts BRB-1…BRB-3.
> 3. **`SendPushNotificationJob`** gets one extra constructor argument at the recorder call site
>    (`notification_ulid`). No change to send/release/fail control flow; the existing job tests stay green unedited.
> 4. **`bootstrap/app.php` scheduler** gets one new daily entry (`push:prune-deliveries`). The scheduler's existence on
>    production is **unverified** (`RESUMPTION-TEMPLATE.md:1561-1570`; inv §2.2) — a dead scheduler is harmless
>    (the table just grows) and is made **visible** by the admin footer (total rows + oldest row).
>
> **What is new and therefore cannot disturb live data:** the `push_deliveries` table (additive migration, schema only,
> no data touch), three read-only admin endpoints, two admin SPA pages, nav entries.
>
> **Production-data safety carried from the process:** the migration creates one table and modifies no existing table
> or row; the prune command only ever deletes from `push_deliveries`, defaults to a dry-run-able shape, and is
> idempotent. The deploy rides the **existing** PENDING AH-099…AH-102 entry (Q15): one snapshot, one `migrate`.

**Alarm rule check.** The one scheduled job (`push:prune-deliveries`) is a Mode A trigger and gets the full loop
(§5 Stage 1, C1.4). No alarm/notification type is added (Q9: config-error is a tile only).

---

## 1. Rulings → where each is baked in

| Ruling                                                                                                             | Where                                                                                                                  |
| ------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------- |
| Q1 one row per attempt (≤3 per job)                                                                                | C1.3 — recorder writes on every `record()` call; retry-sequence test asserts 3 rows                                    |
| Q2 optional `notification_ulid` on the DTO, passed at the job call site                                            | C1.2 (DTO + call site `SendPushNotificationJob.php:89-100`, value `$this->message->data['notification_ulid'] ?? null`) |
| Q3 no non-attempt recording; admin UI states what is/ isn't counted                                                | C3.1 "What this counts" panel; nothing added to the dispatcher                                                         |
| Q4 `user_id` FK cascade; `user_push_token_id` plain nullable, no FK; `notification_ulid` no FK                     | C1.1 migration                                                                                                         |
| Q5 90 days as `--days`                                                                                             | C1.4                                                                                                                   |
| Q6 ship prune schedule-registered AND manually runnable; footer shows total rows + oldest                          | C1.4 (schedule + command), C2.1 (summary footer fields), C3.1 (footer)                                                 |
| Q7 email not tracked; "Not tracked"; MAIL_MAILER on Pedram's read-back list; AH-102c later                         | C3.1 copy; Appendix A command list; tech-debt row (§8)                                                                 |
| Q8 placements: Operations → "Push deliveries"; Creators → "Creator app"                                            | C3.1 / C3.2 nav entries                                                                                                |
| Q9 config-error tile only                                                                                          | C2.1 summary + C3.1 tile; no notification type                                                                         |
| Q10 new endpoint + page; shipped creators list shape untouched                                                     | C2.2; `AdminCreatorController` is not edited (asserted by diff in the review)                                          |
| Q11 extract shared PAT-liveness predicate; `PushTokenRegistryTest` unedited                                        | C0.1                                                                                                                   |
| Q12 "sign-ins", not "devices"                                                                                      | C3.2 copy and API field names (`sign_ins`)                                                                             |
| Q13 "—" for app version on non-push-registered sign-ins                                                            | C2.2 returns `null`; C3.2 renders "—"                                                                                  |
| Q14 bounded-diagnosis rider, ≤2h, `:69`/`:106` one flake, `mfa-enrollment:82` observation, tech-debt:1364 reopened | C0.2                                                                                                                   |
| Q15 fold into the PENDING AH-099…AH-102 deploy entry                                                               | §7, C5.x docs                                                                                                          |
| §2.5 containment design is the write-path requirement                                                              | C1.3 and BRB-1…BRB-3                                                                                                   |

---

## 2. Shape and the cut line

```
Stage 0  preflight (no new behaviour)
  C0.1  shared PAT-liveness scope extracted from PushTokenRegistry::liveQuery()      [Q11]
  C0.2  admin-sign-in flake rider — bounded diagnosis, ≤2h                            [Q14]

Stage 1  backend half A — the recording store
  C1.1  push_deliveries migration + model + factory
  C1.2  PushDeliveryOutcome.notificationUlid + job call-site argument                 [Q2]
  C1.3  PushDeliveryRecorder rewrite — contained insert                               [§2.5]
  C1.4  push:prune-deliveries command + schedule registration                         [Q5, Q6]

Stage 2  backend admin read endpoints
  C2.1  GET /admin/push-deliveries (+ /summary)
  C2.2  GET /admin/creator-app/users (+ /{creator}/sign-ins)

  ── CHECKPOINT A (review-only, local, NOT a push): backend complete, break-revert outputs attached ──

Stage 3  admin SPA
  C3.1  Operations → Push deliveries page, nav entry, i18n ×24
  C3.2  Creators → Creator app page + sign-ins drawer, nav entry, i18n ×24

Stage 4  verification (no commit): local Postgres walk, EXPLAIN evidence, full admin Playwright, screenshots

Stage 5  docs + closure
  C5.1  docs commit (review file, ad-hoc log, deploy-log, tech-debt, runbook, tenancy.md, resumption)

  ── CHECKPOINT B: one push of the whole chunk, CI green at the tip cited (§5.41) ──
```

Every C-numbered step ends **independently green** (its own tests + the gate subset in §6) and is its own commit,
with the docs pair at the end, matching the AH-102 rhythm. The tip that is pushed is the C5.1 commit; the review
file cites the CI run id that went green **at that tip**.

**Why a review-only Checkpoint A.** The risky part of the chunk is the write path (Stage 1). Reviewing the
containment evidence before any UI is built is cheaper than after; nothing is pushed there, so the one push at
Checkpoint B still ships API and SPA together (API-before-SPA ordering is moot when both ride one deploy).

---

## 3. The settled design (rulings applied)

### 3.1 `push_deliveries` (inv §2.1)

```
push_deliveries
  id                    bigIncrements
  user_id               foreignId → users, cascadeOnDelete
  user_push_token_id    unsignedBigInteger, nullable          -- NO FK (token rows are deleted on logout; history survives)
  platform              string(16)
  notification_type     string(64)
  notification_ulid     char(26), nullable                    -- NO FK; join key to notifications.ulid for "in-app: read/unread/none"
  outcome               string(16)                            -- PushOutcome value
  attempt               unsignedSmallInteger
  final                 boolean default false
  code                  string(64), nullable
  http_status           unsignedSmallInteger, nullable
  detail                string(200), nullable                 -- non-Delivered only; capped again in the recorder
  created_at            timestampTz default now()             -- append-only: no updated_at

  index (user_id, created_at)
  index (outcome, created_at)
  index (created_at)                                          -- retention scans + footer min()
```

- Exactly three indexes (named explicitly in the migration, asserted by a schema test). The table is append-only; the
  model sets `$timestamps = false` and the recorder sets `created_at` explicitly (so time-travel works in tests; the
  column default is the safety net).
- **"Terminal"** = `outcome <> 'retryable' OR final`. The summary tiles count terminal rows only (a retried push is
  one terminal outcome, not three).
- **Never stored:** FCM token, message title/body/data, IP, user-agent. `detail` is `null` for `Delivered`.
- Migration timestamp `2026_10_12_100000_create_push_deliveries_table.php` (after
  `2026_10_10_100000_create_user_push_tokens_table`). Schema only; `down()` drops the table.

### 3.2 Recorder containment (the write-path requirement, inv §2.5)

`PushDeliveryRecorder::record(PushDeliveryOutcome $result): void` becomes three **independent** guarded steps, in this
order, each in its own `try { … } catch (Throwable $e) { … }`:

1. **Invalidate** (only on `TokenInvalid`) — the existing `$this->tokens->invalidate(...)`. Runs **first**: the
   token-hygiene side effect matters more than the audit row and must not depend on the insert succeeding.
2. **Insert** the `push_deliveries` row via `PushDelivery::query()->create(...)`.
3. **Log** — the existing `match` of `Log::info/warning/error` lines, unchanged in content.

A failure in any step is caught and logged as `push.delivery_record_failed` (context: `token_id`, `user_id`,
`outcome`, `step`, exception class — **never** the token, message or exception message body that could echo a
token) and **does not rethrow**. The failure logging is itself wrapped (a throwing logger must not escape).
Rethrowing would fail the job → retry → duplicate push, which is the one outcome this design exists to prevent.

Noted, deliberately **not built** speculatively: a savepoint around the insert (`DB::transactionLevel() > 0`) —
the queue worker does not run `record()` inside a transaction, and Pest runs on SQLite where a failed statement does
not poison the outer transaction. If a future caller invokes `record()` inside a transaction on Postgres, that is a
new call site with its own review.

**The likely real production failure** is `permission denied for table push_deliveries` (or for the sequence
`push_deliveries_id_seq`) because the app role `engine_c_user` needs an explicit `GRANT` on new tables. That is
exactly the failure class this containment turns from "duplicate pushes" into "no rows + an error-log line". It is a
named deploy step (§7) with a read-back, not an assumption.

### 3.3 Shared PAT-liveness scope (Q11)

The predicate currently embedded in `PushTokenRegistry::liveQuery()` (`:114-134`) is, for a PAT and its user:
`users.deleted_at IS NULL`, `expires_at` null-or-future, and — when `config('sanctum.expiration') > 0` —
`created_at > now() - expiration minutes`. It is extracted to one Identity-module class,
`App\Modules\Identity\Support\LiveAppSession` (a static `constrain(Builder $query, string $pat = 'personal_access_tokens', string $users = 'users'): Builder`), used by:

- `PushTokenRegistry::liveQuery()` (which keeps its own push-specific `whereNull('user_push_tokens.invalidated_at')`),
- Half B's "Active/Signed out/Never" derivation and the sign-ins endpoint (§3.5).

The `creator-app:%` name filter is **not** part of the shared scope (push tokens already only exist for creator-app
PATs; Half B adds the name filter itself).

One predicate, one place: the sign-ins endpoint decides `live` by asking the same scope for the live PAT ids, not by
re-implementing the rule in PHP.

### 3.4 Prune command (Q5, Q6)

`php artisan push:prune-deliveries {--days=90} {--dry-run} {--limit=100000}`

- Cutoff = `now()->subDays(days)`; deletes rows with `created_at < cutoff` (strictly less: a row exactly at the
  cutoff survives), oldest-first by `id`, in chunks of ≤5000 per `DELETE` statement, until none remain or `--limit`
  rows have been deleted in this run (a backlog drains over successive daily runs).
- **Loud on bad input:** `--days` must be an integer between **7** and 3650, `--limit` a positive integer; anything
  else exits non-zero with a message and deletes nothing. (The 7-day floor means a fat-fingered `--days=0` cannot
  empty the table — plan-review question P-2.)
- `--dry-run` prints `would delete N (oldest: …, cutoff: …)` and deletes nothing.
- Output always states: rows deleted (or would-be), oldest remaining row date, total remaining.
- Idempotent: a second run immediately after deletes 0.
- Registered in `bootstrap/app.php` next to the existing three daily commands as `->daily()`; the scheduler test pins
  its presence. `docs/runbooks/production-queue-worker.md` §7 gains the row and a "manual run / how to see the
  scheduler is dead" paragraph pointing at the admin footer.

### 3.5 Admin read endpoints (inv §2.4, §3.2)

All under the existing admin stack (`Route::prefix('admin')->middleware(['auth:web_admin', EnsureMfaForAdmins::class])`,
`Modules/Admin/Routes/api.php:50-53`) with the same platform-admin check `AdminAuditLogController` applies. Every new
route is added to the `docs/security/tenancy.md` §4 allowlist table in the docs commit. Responses use **explicit
allow-listed resources** (no `toArray()` of models); a test walks every response key and asserts no key named
`token`/`token_hash`/`ip`/`user_agent`/`message`/`body` and no seeded secret value appears anywhere in the JSON.

**`GET /api/v1/admin/push-deliveries`** — cursor pagination (like `AdminAuditLogController`), ordered `id desc`
(monotonic ≈ time; stable cursor).

| Param                        | Behaviour                                                                                                               |
| ---------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| `outcome`                    | one of `delivered`, `token_invalid`, `retryable`, `config_error`, `rejected`, or the preset `failures`                  |
| `failures` preset            | `outcome IN (token_invalid, config_error, rejected) OR (outcome = 'retryable' AND final)` — i.e. terminal non-delivered |
| `notification_type`          | a known `NotificationType` value                                                                                        |
| `creator`                    | creator ULID → resolved to `user_id`                                                                                    |
| `date_from`, `date_to`       | inclusive day bounds on `created_at`                                                                                    |
| unknown/invalid filter value | **empty page** (the house pattern: `1=0`), not a 500 and not "all rows"                                                 |

Row attributes (allow-list): `id` (stringified bigint — P-5), `created_at`, `platform`, `notification_type`,
`outcome`, `attempt`, `final`, `code`, `http_status`, `detail`, `creator` `{id (ulid), display_name, email}` (resolved
for the page in **one** batched query, `withTrashed` so history for a deleted account still reads), `in_app`
(`read` | `unread` | `none`, resolved for the page in **one** batched `whereIn('ulid', …)` on `notifications`).
Query count per page is bounded and asserted (no N+1).

**`GET /api/v1/admin/push-deliveries/summary?window=24h|7d|30d`** (default `7d`) →

```json
{
  "window": "7d",
  "since": "…",
  "counts": {
    "delivered": 0,
    "token_invalid": 0,
    "retryable_final": 0,
    "config_error": 0,
    "rejected": 0
  },
  "table": { "total_rows": 0, "oldest_at": null }
}
```

Counts are terminal rows only, from one `GROUP BY outcome, final` over `created_at >= since`. `table.total_rows` and
`oldest_at` are the Q6 footer (`count(*)` and `min(created_at)` — bounded by retention; `min` uses the `created_at`
index). An invalid `window` is a 422.

**`GET /api/v1/admin/creator-app/users`** — offset pagination (like All Creators). One row per **creator**
(non-deleted), derived — **no new writes** (inv §3.1):

| State          | Rule                                                                                                                                 |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| **Active**     | ≥ 1 PAT named `creator-app:%` that passes `LiveAppSession`                                                                           |
| **Signed out** | no live creator-app PAT, but an `audit_logs` row `action='auth.login.succeeded'` with `metadata.guard='creator_app'` exists for them |
| **Never**      | neither                                                                                                                              |

Row attributes: `creator` `{id, display_name, email}`, `app_status`, `live_sign_ins` (count), `platforms`
(subset of `ios`/`android`, from the live PAT name suffix), `last_seen_at` (`max(last_used_at)` over live PATs),
`last_app_sign_in_at` (audit `max(created_at)`), `app_version` (from the live push-token rows, else `null`),
`push_state` (`registered` | `dead` | `none`; derived only from push-token rows that belong to **live** PATs —
`registered` = ≥1 with `invalidated_at IS NULL`, `dead` = rows exist but all invalidated, with the latest
`invalidated_reason`), `last_push_registered_at`.

Filters: `app_status`, `platform`, `push` (`registered` | `none`), `search` (email/display-name substring; debounced in
the SPA). Default order: last seen desc, then last app sign-in desc, then `id desc`, nulls last (portable null
ordering is a build detail covered by tests on SQLite and the Stage 4 Postgres pass).

**`GET /api/v1/admin/creator-app/users/{creator}/sign-ins`** — `{creator}` is bound the same way as the
existing `admin/creators/{creator}` route (a ULID; the exact binding mechanism is confirmed at C2.2, not assumed).
Returns the creator's creator-app PATs, newest first, capped at 50 with `meta.total`. **No row id is returned** (P-7:
nothing in the response can reference a PAT): `platform`, `signed_in_at` (`created_at`), `last_seen_at`,
`state` (`live` | `expired` | `signed_out`), `expires_at`, `app_version`, `push_state`, `push_registered_at`.
`live` comes from the shared scope; the non-live reason is computed only for display.

**Never returned anywhere:** FCM token, PAT hash/plain value, message text, IP address, user-agent.

### 3.6 Admin SPA (inv §1.3, §3.2)

- **Operations → Push deliveries** — `modules/operations/pages/PushDeliveriesPage.vue`, route
  `/operations/push-deliveries` (`app.operations.push-deliveries`), API module
  `modules/operations/api/pushDeliveries.api.ts`. Outcome tiles with the 24h/7d/30d switch (config-error tile
  rendered **prominent** when > 0), a `Failures` preset chip, filters (outcome, type, creator, date range), the cursor
  table (patterned on `AuditLogPage.vue`), the footer ("N rows · oldest {date}"), and the **"What this counts"**
  panel: _counts push attempts that reached FCM; does not count creators with no registered device, muted/suppressed
  pushes, throttled sends, token-gone-at-run-time, jobs that exhausted without an attempt, or `push:test`;
  "delivered" means FCM accepted it, not that a device displayed it_. Channel strip: **Push** (this table),
  **In-app** (per-row read/unread), **Email — Not tracked**. Deep link: `?creator=<ulid>&creator_name=<display>`
  (`creator_name` is display-only and never sent to the API).
- **Creators → Creator app** — `modules/creators/pages/CreatorAppPage.vue`, route `/creators/app`
  (`app.creators.app`, registered **before** `/creators/:ulid` like `/creators/all`/`/creators/kyc`), API module
  `modules/creators/api/creatorApp.api.ts`, and a sign-ins drawer `CreatorAppSignInsDrawer.vue` (title "Sign-ins",
  never "devices"; "—" for app version when the sign-in never registered for push). Patterned on
  `AllCreatorsPage.vue` (chip groups, `tableOptions`, `v-data-table-server`) with the debounced search from
  `AgencyListPage.vue`. A "View push deliveries" link in the drawer goes to the deep link above.
- Nav: `core/nav/navItems.ts` (Creators group `:59-76`; Operations group); labels `nav.creatorApp` /
  `nav.pushDeliveries` in `app.json`.
- **i18n:** strings go into the existing namespace files (`creators.json`, `operations.json`, `app.json`), **not** new
  namespace files (that would add an import × the `MessageSchema` plumbing for nothing). ≈ 45 + ≈ 40 + 2 new strings,
  × 24 locales, real translations for all 23 non-English locales (including the flaky ten: bg, el, et, fi, ga, hu, lt,
  lv, mt, ro). Gates: `scripts/i18n/verify-locale.mjs`, `i18n-locale-parity.spec.ts`, `i18n-used-keys-resolve.spec.ts`.

---

## 4. Rider — admin-sign-in flake (Q14), C0.2

**Stage 0, time-boxed ≤ 2 hours, no feature work waits on it.** Facts from the inventory (§4): 17 of 18 admin E2E
runs since 2026-10-06 flaked `admin-sign-in.spec.ts:69`, failing at `:106:24` (`toHaveURL(/127\.0\.0\.1:5174\/$/)`
received `/sign-in`); 1 clean run (`38025689774`); no `apps/admin` diff since `52af5b23`; `:69` and `:106` are **one**
flake. `admin-mandatory-mfa-enrollment.spec.ts:82` (fails at `:132`, `recovery-codes-display` not found) rides along
as an **observation** in the same pass — one box, not a second rider.

Order of work (stop when the box is spent):

1. **Free evidence first.** Download the failing runs' CI artifacts (Playwright trace/screenshot/video are uploaded
   on failure) and read the failing attempt's network log: what did the TOTP submit return, and what did `/admin/me`
   return after it? This costs minutes and may name the cause outright.
2. **Local reproduction** on the isolated E2E stack (dev stack down, isolated DB): `--repeat-each` on the spec with
   `--retries=0`, trace on. Candidate hypotheses to test, none assumed: the `requireAuth` `bootstrap()` re-fetch
   racing the redirect (`tech-debt.md:1364`); a TOTP step-boundary or replay rejection; a session/CSRF (419) bounce
   after submit.
3. **Outcome A — fix:** minimal change, with a §5.35 break-revert (revert the fix → the repeat run fails again;
   restore → passes N/N). **Outcome B — evidence + disposition:** the traced root cause, why it is not fixable
   inside the box, and a re-ruled `tech-debt.md` row (the "deliberately unfixed" ruling at `:1364` is reopened for
   this one spec and re-closed with the evidence either way).
4. The `mfa-enrollment:82` observation records whether it shares the root cause; if it does, the same fix covers it
   and says so; if not, it stays on its existing `tech-debt.md:1361` row with the new evidence appended.

Success is **not** "CI went green once" — it is a named cause with trace evidence, and either a fix with a
reproduced before/after or an honest disposition.

---

## 5. Stage plan

### Stage 0

**C0.1 — `refactor(identity): extract the PAT-liveness predicate`.** Add `LiveAppSession::constrain()`; make
`PushTokenRegistry::liveQuery()` call it (keeping its `invalidated_at` clause). No behaviour change.

- **Proof:** `tests/Feature/Modules/Notifications/PushTokenRegistryTest.php` passes **unedited** (git diff on that
  file is empty — shown in the commit/review).
- **New tests** (`tests/Feature/Modules/Identity/LiveAppSessionTest.php`), against plain PAT queries (no push token
  involved): live PAT included; expired by `expires_at`; expired by `created_at` age when `sanctum.expiration > 0`;
  `expiration` null/0 means age never expires; soft-deleted user excluded; `expires_at` exactly now (excluded) vs one
  second ahead (included) — the §5.34 negatives.
- **Break-reverts BRB-8** (each mutation must fail a named test, then be restored): drop the soft-delete clause; drop
  the `expires_at` clause; drop the `created_at` age clause. For each, name the failing test in
  `PushTokenRegistryTest` **and** in the new file.

**C0.2 — `test(admin): …` the flake rider (§4).** Output is a fix + break-revert, or an evidence-only disposition
(then the commit is docs-only and folded into C5.1).

### Stage 1 — Half A write path

**C1.1 — `feat(notifications): push_deliveries table + model`.** Migration (§3.1), `PushDelivery` model (final, explicit
`$fillable`, casts for `platform`/`outcome` enums and `final`, docblock stating the never-store rule), factory.

- Tests: schema (columns, nullability, defaults, the three named indexes, **no** FK on `user_push_token_id` and
  `notification_ulid`), `user_id` cascade (delete the user → rows gone), `down()` drops the table, factory builds a
  valid row. Any table-list / migration-order test discovered here is named in the review.

**C1.2 — `feat(notifications): carry notification_ulid on PushDeliveryOutcome`.** Optional trailing
`?string $notificationUlid = null`; the job passes `$this->message->data['notification_ulid'] ?? null` at
`SendPushNotificationJob.php:89-100`. No other behaviour.

- Tests (added to `SendPushNotificationJobTest`, none edited): the value reaches the recorder when present; `null`
  when the message has none. **BRB-3:** stop passing it → the first test fails.

**C1.3 — `feat(notifications): persist push delivery attempts, contained`.** The recorder rewrite (§3.2).

- Tests (`PushDeliveryRecorderTest`, new; plus additions to `SendPushNotificationJobTest`):
  - one row per outcome for **each** of the five `PushOutcome` cases, correct columns (`final`, `code`,
    `http_status`, `notification_ulid`);
  - a scripted retry sequence (`retryable`, `retryable`, then `delivered`/exhausted) writes 3 rows with
    `attempt` 1/2/3 and `final` only on the last;
  - `detail` is `null` for `Delivered`, capped at 200 chars otherwise; `code` capped at 64;
  - **privacy:** no row column or log context contains the token or message text; the existing recorder
    privacy/enum-coverage tests (`SendPushNotificationJobTest.php:347`, `:380`) stay green unedited; if the enum
    coverage test needs the new table it is extended, not weakened;
  - **containment (a):** drop the table (`Schema::drop('push_deliveries')`, the stand-in for a missing `GRANT`) then
    run the real job: the job **completes**, the sender's `send()` was called **exactly once**, the job did **not**
    release/retry, and `push.delivery_record_failed` was logged with the allowed context only;
  - **containment (b):** a throwing `invalidate()` still writes the row, and a throwing insert still performs the
    invalidation (both directions);
  - **containment (c):** `Delivered` + failing insert → assert a single `send()` (no duplicate push);
  - a throwing logger does not escape `record()`.
- **Break-reverts:** **BRB-1** remove the catch around the insert → containment (a)/(c) fail. **BRB-2** put invalidate
  and insert in one `try` (or insert before invalidate) → containment (b) fails. **BRB-4** persist `detail` uncapped
  / for `Delivered` → the cap/null test fails.

**C1.4 — `feat(notifications): push:prune-deliveries + daily schedule`.** Command and registration (§3.4).

- Tests (`PushPruneDeliveriesCommandTest`): deletes rows older than the window; **§5.34 over-reach negatives** — a row
  inside the window survives, a row **exactly at the cutoff** survives, rows in `notifications`, `user_push_tokens`,
  `audit_logs`, `user_notification_preferences` are untouched (counts equal before/after), `--dry-run` deletes nothing
  and reports the count, `--limit` is honoured (and drained oldest-first), `--days=6`/`--days=abc`/`--limit=0` exit
  non-zero and delete nothing, a second run deletes 0 (idempotent); chunking proven with a small forced chunk size
  (class constant overridden, or > 5000 rows only if cheap); **schedule pin:** `Schedule::events()` contains the
  command at `daily`.
- **Break-reverts:** **BRB-5** `<` → `<=` (boundary test fails); ignore `--limit` (limit test fails); delete on
  `--dry-run` (dry-run test fails). **BRB-6** also delete `user_push_tokens` older than the cutoff (untouched-tables
  test fails). **BRB-7** remove the schedule registration (schedule pin fails).

### Stage 2 — admin read endpoints

**C2.1 — `feat(admin): push-deliveries list + summary`.** Controller(s), requests (validation), resources, routes (§3.5).

- Tests: **auth negative set** (unauthenticated 401; creator and agency users 403; admin without MFA blocked by
  `EnsureMfaForAdmins`); each filter alone and combined; the `failures` preset includes a terminal `retryable` and
  excludes a non-final `retryable` and `delivered`; unknown outcome/type/creator → empty page; cursor walks the whole
  set without gaps or duplicates and stops; `in_app` read/unread/none (row with a read notification, an unread one,
  one with `notification_ulid = null`, one whose notification was deleted); creator display for a soft-deleted user;
  summary windows (a row 1 second inside vs 1 second outside the window boundary), terminal-only counting (a
  3-attempt sequence counts once), empty table → zeros + `oldest_at: null`; invalid `window` → 422; **query-count
  bound** for a page of 50; **privacy scan** of the JSON.
- **Break-reverts:** **BRB-9** remove the platform-admin check (auth set fails); **BRB-10** add a forbidden key to the
  resource (privacy scan fails); **BRB-12** drop the `retryable AND final` leg / include non-final `retryable` in the
  preset (preset test fails); **BRB-13** shift the window boundary (boundary test fails).

**C2.2 — `feat(admin): creator-app usage list + sign-ins`.**

- Tests: **state derivation matrix with negatives** — live PAT → Active; PAT expired by `expires_at` → Signed out
  (with audit) / Never (without); PAT expired by age → same; soft-deleted user not listed; **web** sign-in audit
  (`guard='web'`) does **not** make a creator "Signed out"; a non-`creator-app:` PAT (e.g. an admin/other token name)
  does not count; `last_seen_at` is the max over **live** PATs only; platform from the name suffix; push states
  (`registered`, `dead`+reason, `none`; a push row on a non-live PAT is ignored); each filter and the search; ordering
  and pagination; sign-ins endpoint (cap + `meta.total`, state per row, "—" case = `app_version: null`, unknown
  creator 404, a creator ULID of a different type 404); auth negative set; **privacy scan** (no token, hash, IP, UA —
  seed all four and assert none appears).
- **Break-reverts:** **BRB-11** treat an expired PAT as live (state test fails); drop the `guard='creator_app'`
  audit filter (web-login test fails); count non-`creator-app:` PATs (name test fails). **BRB-9/BRB-10** repeated for
  these routes.

**Checkpoint A (review-only).** Delivered: all Stage 0–2 commits local, the BRB table with before/after test output,
the gate results, and the diff showing `PushTokenRegistryTest.php` and `AdminCreatorController.php` untouched.
**Nothing is pushed.**

### Stage 3 — admin SPA

**C3.1 — `feat(admin): Push deliveries page`.** API module, page, tiles, filters, cursor table, footer, "What this
counts" panel, channel strip, nav entry + route, i18n ×24.

- Vitest: renders tiles/rows from a fixture; window switch re-fetches with the right param; `Failures` chip sends
  `outcome=failures` and resets the cursor; each filter sends exactly its param; cursor next/previous; the config-error
  tile is prominent only when > 0; footer text with rows/oldest and with an empty table; empty and error states; the
  email channel renders "Not tracked"; the deep-link query prefills the creator filter and `creator_name` is **not**
  sent to the API; the explanatory panel is present. **Break-reverts:** `Failures` sends the wrong param; remove the
  "Not tracked" label.
- Existing specs edited **consciously** (listed in the review): the nav/layout spec(s) that count or enumerate nav
  items.

**C3.2 — `feat(admin): Creator app page`.** API module, page, drawer, nav entry + route (ordering vs `/creators/:ulid`
asserted), i18n ×24.

- Vitest: rows/status chips for the three states; every filter sends its param and resets paging; debounced search
  (single request after the debounce); drawer opens and renders sign-ins incl. "—" version; **"sign-ins" wording, no
  "devices"** (a copy test over the English strings); the deep link to deliveries carries the ULID; routing order test.
  **Break-reverts:** search not debounced; route registered after `/creators/:ulid`.

### Stage 4 — verification (no commit)

Run and attach to the review, in this order:

1. **Postgres walk.** The Pest engine is SQLite, so the queries with JSON-path (`metadata->guard`) and null ordering are
   also run against the local **pgsql** dev database: seed a synthetic population (target ≈ 20k creators, ≈ 5k PATs,
   ≈ 50k `audit_logs` rows, ≈ 100k `push_deliveries`), call each endpoint, and record `EXPLAIN (ANALYZE)` timings.
   **Decision rule (P-6):** if the `app_status=never` filter or default ordering exceeds ~500 ms at that scale, the
   review records it as a tracked tech-debt row with the proposed denormalised `last_app_sign_in_at` column (a later
   chunk) rather than shipping a hidden slow page silently; below it, no action.
2. `push:prune-deliveries --dry-run` and a real run against that dataset (counts match expectation; second run 0).
3. **Eyes-on:** screenshots of both pages (populated, empty, error), light/dark if the SPA supports both.
4. **Full admin Playwright** (dev stack down, isolated DB) — the nav is an E2E-traversed surface. Recorded with the
   known flake status from §4 stated, not hidden.
5. Full gate board (§6).

### Stage 5 — docs

**C5.1 — `docs: AH-102b …`.** See §8.

---

## 6. Test, gate and deploy discipline

**Gates before every commit that touches the stack it names** (the working-process board):

- Backend: Pint `--test` on touched files and once on `--all` before Checkpoint A; PHPStan (Larastan) with
  `--memory-limit`; full Pest serial (`-d memory_limit=2G`) before each checkpoint; the focused files at each C-step.
- Admin SPA: Vitest (full admin suite before Checkpoint B), `vue-tsc`, ESLint, Prettier on touched files,
  locale gates (`verify-locale.mjs`, `i18n-locale-parity`, `i18n-used-keys-resolve`).
- Main SPA, `api-client`: untouched; their suites are run once at Checkpoint B to prove it.
- Full admin Playwright before the push (§5 Stage 4).

**§5.41:** the pushed tip's CI run id is cited green in the review file; the push happens **once**, at Checkpoint B,
after clearance and Pedram's go. The inventory commit `65d2b306` and this plan commit are **held**; they will go up
inside that single push (or earlier only if Pedram says so — pushing docs alone would start a CI cycle for no
benefit).

**§5.35:** each BRB entry in the review file shows the mutation, the failing test name and its output, then the
restore and the passing run.

---

## 7. Deploy plan (folds into the PENDING AH-099…AH-102 entry, Q15)

AH-102b is stacked in `deploy-log.md` as `AH-102b — PENDING`, under the same convention as AH-099…AH-102, and the
existing deploy steps are reused, not duplicated:

1. **Snapshot** — the existing pre-deploy snapshot (the AH-102 migration already mandates one). One snapshot covers
   both migrations.
2. **`php artisan migrate`** — runs `…create_user_push_tokens_table` then `…create_push_deliveries_table`. Additive;
   schema only; no data touched.
3. **GRANT check (named step).** After migrate, as a superuser:

   ```sql
   SELECT has_table_privilege('engine_c_user', 'push_deliveries', 'SELECT,INSERT,UPDATE,DELETE');  -- expect t
   SELECT has_sequence_privilege('engine_c_user', 'push_deliveries_id_seq', 'USAGE');               -- expect t
   ```

   If either is `f`, grant it (`GRANT SELECT, INSERT, UPDATE, DELETE ON push_deliveries TO engine_c_user;`
   `GRANT USAGE, SELECT ON SEQUENCE push_deliveries_id_seq TO engine_c_user;`) **before** any flag is armed. (`DELETE` is
   needed by the prune command; the table is otherwise append-only.)

4. **`queue:restart`** — already a named step in the Checkpoint-A deploy entry; 102b changes the job's call site and the
   recorder, so a worker holding old code must be restarted. If AH-102b ever deploys **separately after** AH-102, this
   step repeats.
5. **Scheduler obligation.** `push:prune-deliveries` is only as alive as the scheduler. Read-backs in Appendix A
   (`crontab -l`, timers, `schedule:list`) establish whether it exists; the admin page footer is the standing
   instrument (a total that only ever grows with an `oldest` older than 90 days = scheduler dead). Manual fallback:
   `php artisan push:prune-deliveries --days=90`.
6. **Flags armed: none.** `push_notifications_enabled` stays OFF in this deploy; arming is its own ritual.
7. **Arming ritual addendum (when push is armed):** `push:test` bypasses the recorder, so it will **not** write a row.
   To prove the recorder in production, trigger a real eligible notification to a creator with a registered device
   (Pedram's own test creator) and read back `SELECT count(*) FROM push_deliveries;` (expect ≥ 1) and see the row on
   the Push deliveries page. **Empty table after arming + a delivered push = the GRANT/recorder failure** — check the
   error log for `push.delivery_record_failed`.

The deploy-log entry carries the snapshot name, the migrate output, the GRANT read-backs, the `queue:restart` line, the
scheduler read-back result, and the dry-run output of `push:prune-deliveries` (expected `would delete 0`), per the
disposition rule.

---

## 8. Docs that move with the build

- `docs/reviews/admin-observability-review.md` — the closing review: BRB table, gate outputs, Stage 4 evidence
  (EXPLAIN timings, screenshots), flake rider outcome, CI run id at the tip.
- `docs/adhoc-changes-log.md` — the **AH-102b** entry.
- `docs/runbooks/deploy-log.md` — `AH-102b — PENDING` entry stacked under the existing chain (§7).
- `docs/runbooks/production-queue-worker.md` §7 — the `push:prune-deliveries` schedule row + manual-run paragraph.
- `docs/security/tenancy.md` §4 — the four new admin route rows (allowlist).
- `docs/tech-debt.md` — (a) retention shipped / scheduler status; (b) **email delivery not tracked → AH-102c, only after
  the transport is known** (MAIL_MAILER read-back); (c) in-app/push non-attempt events not recorded (Q3); (d) the flake
  disposition for `admin-sign-in.spec.ts` + the `mfa-enrollment:82` observation; (e) the Stage 4 performance row if the
  P-6 rule trips.
- `docs/reviews/RESUMPTION-TEMPLATE.md` Part 2 — refreshed state.
- **AH-105 privacy checklist** — note that `push_deliveries` is a **new stored data category** (user id, device
  platform, notification type, outcome, ≤ 90 days) for the privacy-policy / data-map work.
- `docs/09-ADMIN-PANEL.md` §6.9 — only if its Operations list needs the new page named (decide at C5.1; not assumed).

---

## 9. Risk register

| #   | Risk                                                                                    | Likelihood | Impact                     | Mitigation                                                                                                                     |
| --- | --------------------------------------------------------------------------------------- | ---------- | -------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| R1  | Insert failure fails the job → duplicate pushes                                         | Low        | **High** (user-visible)    | §3.2 containment, BRB-1/2, single-`send()` assertions, GRANT deploy step + arming read-back                                    |
| R2  | Missing `GRANT` on the new table/sequence in production                                 | **Medium** | Medium (silent no-data)    | Named deploy step with `has_*_privilege` read-backs; empty-table-after-arming check; `push.delivery_record_failed` log line    |
| R3  | Liveness refactor changes `liveQuery()` semantics                                       | Low        | Medium (wrong tokens live) | `PushTokenRegistryTest` unedited, scope tests, BRB-8                                                                           |
| R4  | Scheduler is dead → table grows unbounded                                               | Unknown    | Low (storage only)         | Footer visibility, manual command, runbook row, Appendix A read-back                                                           |
| R5  | Half B list is slow at scale (correlated subqueries + audit JSON filter)                | Medium     | Low (admin-only page)      | Stage 4 measured EXPLAIN at synthetic scale + P-6 decision rule; indexes on `audit_logs` already include action/created_at     |
| R6  | JSON-path / null-ordering SQL differs between SQLite (tests) and Postgres (prod)        | Medium     | Medium (500 in prod)       | Stage 4 runs every endpoint against local pgsql; endpoints exercised in the full admin Playwright on pgsql                     |
| R7  | A response leaks a token/IP/UA/message                                                  | Low        | **High** (privacy)         | Explicit allow-listed resources, JSON key-scan + seeded-secret tests, BRB-10                                                   |
| R8  | Admin E2E flake (`:69`/`:106`) masks or mimics a nav regression                         | **High**   | Medium                     | Rider (§4) ahead of the SPA work; evidence recorded either way; failures read against the known-flake signature, not dismissed |
| R9  | Admin reads imply guarantees the table doesn't give ("not delivered" ≠ "not displayed") | Medium     | Low                        | "What this counts" panel; "delivered = FCM accepted" wording; email "Not tracked"                                              |
| R10 | Stale queue worker runs old recorder after deploy                                       | Medium     | Medium                     | `queue:restart` named step                                                                                                     |

---

## 10. Plan-review questions

- **P-1 — Checkpoint structure.** A review-only Checkpoint A after the backend (nothing pushed), then one push at
  Checkpoint B. _Recommend yes._ Alternative: a single checkpoint at the end.
- **P-2 — Prune safety rails.** `--days` floor of 7 (no accidental `--days=0` wipe) and a per-run `--limit` default of
  100000 so the first scheduled run after a long outage cannot run unbounded. _Recommend yes._ Alternative: no floor,
  no default cap.
- **P-3 — No new Playwright spec.** Postgres coverage of the new queries comes from the Stage 4 local pgsql pass, not a
  new E2E spec (a new spec adds flake surface while §4 is open). _Recommend no new spec._ Alternative: one page-load
  smoke spec per page.
- **P-4 — Default order of the Creator app list.** Last seen desc, then last app sign-in desc, then `id desc`, nulls
  last (so "who is using it right now" is at the top). _Recommend._
- **P-5 — Row `id` in the deliveries response.** Expose the bigint as a **string** (needed only as a stable React/Vue
  key and cursor; telemetry rows have no public ULID, and adding a ULID column just for this adds insert cost).
  _Recommend string id._ Alternative: add a `ulid` column.
- **P-6 — Performance decision rule.** Measure Half B at ≈ 20k creators; if `never`-filter/default order > ~500 ms,
  record a tech-debt row for a denormalised `last_app_sign_in_at` (no new writes in 102b). _Recommend._
- **P-7 — Sign-ins rows carry no id.** The drawer lists ≤ 50 rows identified by `created_at`; nothing in the response
  can be used to reference a PAT. _Recommend (smallest privacy surface)._
- **P-8 — Docs-only rider outcome.** If §4 ends as "evidence + disposition", C0.2 is folded into C5.1 (no separate
  test commit). _Recommend._

---

## Not verified (carried and new)

- **Scheduler on production** — whether any cron/timer runs `schedule:run` is unknown (Appendix A establishes it).
- **Effective `MAIL_MAILER`, `QUEUE_CONNECTION`, `CACHE_STORE` on production** — unread until Pedram's visit; none gate
  the build, `MAIL_MAILER` gates only the future AH-102c.
- **`engine_c_user` privileges on `push_deliveries`** — cannot exist until the migration runs; verified at deploy (§7).
- **Postgres behaviour of the new Half B queries** — only inferable until the Stage 4 pgsql pass.
- **Whether every CI lane runs Pest on SQLite** — `phpunit.xml:53-54` pins SQLite `:memory:` and `ci.yml` provisions
  Postgres services for other lanes (`:25`, `:171`, `:201`); which lane runs what beyond that is re-confirmed at C1.1.
- **The flake's root cause** — hypotheses in §4 are hypotheses; nothing is established until the artifacts are read.
- **Table-list / migration-order tests** that might enumerate tables — unknown until C1.1 runs the suite.
- **The existing nav/layout specs' exact assertions** — named when C3.1 runs them.

---

## Appendix A — production read-only command list (Pedram's visit)

Read-only by construction: no writes, no `.env` display, no secrets printed. Assumes the app lives at
`/var/www/catalyst/apps/api` and runs as `www-data` (adjust both if the box differs; the deploy runbook states the
real values). Every healthy value is noted beside its command. Results are pasted into the deploy-log entry.

```bash
APP=/var/www/catalyst/apps/api
ART="sudo -u www-data env HOME=/tmp php $APP/artisan"

# 1. Queue worker is running (healthy: a catalyst-worker program RUNNING — queue:work, not Horizon)
sudo supervisorctl status
ps aux | grep '[q]ueue:work'          # healthy: one process; flags like --memory=768 --tries=3 --max-time=3600, no odd --queue/connection override

# 2. QUEUE_CONNECTION (healthy: database or redis; UNHEALTHY: sync or null — pushes would run inline / vanish)
$ART tinker --execute="echo config('queue.default').PHP_EOL;"

# 3. CACHE_STORE (healthy: database or redis; UNHEALTHY: array/null — throttle + locks would not hold across requests)
$ART tinker --execute="echo config('cache.default').PHP_EOL;"

# 4. Scheduler exists (healthy: EXACTLY ONE of: a cron line `* * * * * cd /var/www/catalyst/apps/api && php artisan schedule:run ...` OR one systemd timer — never both; none = the prune would never run)
crontab -l
sudo crontab -l -u www-data
ls /etc/cron.d
systemctl list-timers --all | grep -i catalyst
$ART schedule:list                    # healthy: lists the existing daily commands (no push:prune-deliveries yet — it ships in 102b)

# 5. Effective MAIL_MAILER (healthy: ses or smtp to a real relay; log/array = mail is NOT leaving the box)
$ART tinker --execute="echo config('mail.default').PHP_EOL; echo config('mail.mailers.'.config('mail.default').'.transport').PHP_EOL; echo (config('mail.mailers.'.config('mail.default').'.host') ?? '-').PHP_EOL;"

# 6. channel='push' preference rows (healthy: 0 — nothing writes a push preference until the flag is armed)
$ART tinker --execute="echo DB::table('user_notification_preferences')->where('channel','push')->count().PHP_EOL;"

# 7. Preference-row counts (record the numbers; they are the pre-deploy baseline — no expected value, but they must be KNOWN before the deploy)
$ART tinker --execute="echo DB::table('user_notification_preferences')->selectRaw('channel, is_enabled, count(*) as n')->groupBy('channel','is_enabled')->get()->toJson().PHP_EOL;"
$ART tinker --execute="echo DB::table('user_notification_preferences')->selectRaw('type, count(*) as n')->where('channel','email')->where('is_enabled',false)->groupBy('type')->get()->toJson().PHP_EOL;"

# 8. Social verification (healthy: a real adapter driver and flag false/absent; `mock` with the flag ON = a standing-board item, do NOT arm post_verified_mail_enabled)
$ART tinker --execute="echo config('integrations.social.driver').PHP_EOL;"
$ART tinker --execute="echo DB::table('features')->where('name','social_verification_enabled')->get()->toJson().PHP_EOL;"
#    `[]` means no stored value: the flag then resolves to its default = ON only while the driver above is `mock`.
#    Deliberately a SELECT, not Feature::active(): Pennant's database store can persist a resolved default, which is a write.

# 9. Confirms nothing from AH-099…AH-102b is deployed (healthy: neither the user_push_tokens nor the 2026_10_12_100000_create_push_deliveries_table migration listed as Ran)
$ART migrate:status | tail -n 8
```

### Post-build addendum (2026-10-12, C5.1)

- **Item 9 amended** (above): the healthy read-back now covers both migrations, `user_push_tokens` and `push_deliveries`, since the
  snapshot and the deploy-log entry cover both.
- **Item 4's `schedule:list` expectation** is unchanged until the deploy: it will not list `push:prune-deliveries` until AH-102b is
  deployed; after the deploy it must (deploy-log step 6).
- **Additional deploy read-back from the build:** the `engine_c_user` GRANT on `push_deliveries` **and** `push_deliveries_id_seq`
  (`has_table_privilege` and `has_sequence_privilege`); see the deploy-log entry.
- **Still not read from production (as of Checkpoint B):** every command in this appendix; the results are Pedram's.
