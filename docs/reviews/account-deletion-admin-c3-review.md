# AH-104c C3 — review (Erase: the flag, the queue, the plan, the click, the watch)

**Status: CLOSED 2026-10-26.** C3 was built as a feat + docs pair, cleared, pushed (`29f1ed0a..5ddcf6e6`, CI green, run 40230258931), walked (W1 to W8 pass, §10) and followed by three small fixes from
the review and the walk (§9), pushed with CI green at the tip. Nothing is deployed yet; C4 is a named act after the deploy.
Plan: [`account-deletion-admin-plan.md`](account-deletion-admin-plan.md) §4 C3 and §3.5. Break-revert log, verbatim: [`account-deletion-admin-c3-break-reverts.md`](account-deletion-admin-c3-break-reverts.md).

**PROD-DATA RISK: HIGHEST in what the code can do, ZERO at deploy.** C3 is the first change that can erase a person. It is behind `account_erasure_execute_enabled`, **off at deploy** (a Pennant
Closure default of false, not a stored row), so deploying C3 erases nothing and claims nothing. **No migration** (the `jobs` table is shared by the new connection). **No env.** One config change
(`database_erasure` in `config/queue.php`) and **one infra change that is required before the flag is turned on**: a second supervisor program, `catalyst-erasure-worker`
([`production-queue-worker.md`](../runbooks/production-queue-worker.md) §2a). Without it a click would claim the account (the creator is signed out and made unavailable to agencies, see §3) and nothing would erase it;
the page says so ("Waiting for the erasure worker…", and after an hour "…may be down"). The risk event is still C4, the first real erasure, which is a named act and not code.
Deploy shape: API and admin SPA deploy; `queue:restart` required (a new job class and changed claim code) **but not while an account is claimed** (Q10; nothing can be claimed until the flag is on);
add the supervisor program before the flag. Deploy-log entry written.

## 1. What was built

| Step  | What                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| C3-1  | The flag. `AccountErasureExecuteEnabled` (`account_erasure_execute_enabled`), on the admin toggle list with a description that says what it gates (Q9: the button and its endpoint, nothing else). The job never reads it: a click that was accepted finishes even if the flag is turned off after.                                                                                                                                                                                                                                                    |
| C3-2  | The queue plumbing. Connection `database_erasure` (the same `jobs` table, `retry_after` 3700 above the job's 3600 timeout, a spec pins the inequality), queue `erasure`. `RunErasureJob`: `tries` 1, `timeout` 3600, `failOnTimeout`, one account per job. A refusal or a moved digest releases the claim (audited, cause `refused` or `data_changed`); any other exception **keeps** the claim (Q6). Supervisor program `catalyst-erasure-worker` documented, `numprocs=1`.                                                                           |
| C3-3  | `GET /admin/compliance/erasure-queue/{user}/plan` (read-only, `admin-erasure-plan`, 6 per minute per admin; a spec hashes the table state before and after) and `GET …/{user}` (the row, polled). `ErasePlanner` returns the dry-run as numbers (rows per table, files, assignments to cancel, vendor calls, cost, the digest) and the **reasons** the account is not ready (gate codes and blockers). `ErasureRunStatus` is the one reader of "how far has the run got": waiting, running, incomplete, db_done, completed, and `stuck` / `can_retry`. |
| C3-4  | `POST …/{user}/erase` (`admin-erasure-erase`, 10 per hour per admin). Order: authorise, flag (409), validate, subject (404), typed id (422), read-only preflight (422 `not_ready` / 409 `in_progress`), second factor, **the claim** (`EraseStarter`), the job after commit, 202. `SecondFactorStepUp` is the existing TOTP / recovery-code check, pulled out of `AccountDeletionService` (the creator's delete request) so the creator's click and the admin's click read one implementation (Q4).                                                    |
| C3-5  | The admin SPA. An Erase button on **Ready** rows only; the dialog (plan as numbers, no person in it; reasons when not ready; typed id; reason 10 to 1000; fresh code, emptied after every attempt); the page then **watches** the account (every 4 s) and says "erased" or "did not start"; the Erasing tab reloads itself while anything waits or runs, and stops when the page goes away. A retry button only where the server says `can_retry`. Locales ×24.                                                                                        |
| C2-4a | Closed, as ruled. `DirectConnectOutcome::CreatorUnavailable` (`creator.unavailable`, 422). `AdminCreatorConnectionController::store` answers it and the automation job skips and records. The C2-4 manifest's two `open` entries are now `guarded` and `delegated`. The creator detail page maps the code to a sentence.                                                                                                                                                                                                                               |
| C3-6  | This file, the break-revert log, the runbook (§1, §1b, new §1c with the state-to-action table, §4), `05-SECURITY-COMPLIANCE.md`, tenancy rows, tech-debt (C2-4a closed; new "AH-104c C3" section, 9 items), `feature-flags.md`, the adhoc log, the deploy log, the glossary, the resumption template.                                                                                                                                                                                                                                                  |

## 2. The C2 review's rulings, and what I did with each

| Ruling                                                                                   | Done as                                                                                                                                                                                                                                                            |
| ---------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| C2-4a: close it in C3                                                                    | C2-4a above. `CreatorAgencyDirectConnectServiceTest`, `AdminCreatorConnectionTest`, `RunAutomationRuleJobTest` and the surfaces manifest; the SPA spec is an `it.each` over `connection.creator_not_approved` and `creator.unavailable`.                           |
| 200-row scan accepted, with the cap notice                                               | No change; the notice stays.                                                                                                                                                                                                                                       |
| `admin.erasure.hold.*` accepted                                                          | No change. The new codes follow the same rule: `admin.erasure.execute.*` and `admin.erasure.plan.*`.                                                                                                                                                               |
| Digest does not hash the credential tables                                               | No change. It matters here: the claim at click time revokes credentials, and the job's digest check runs after it. `ErasureRun` still compares the digest after the claim, and the specs in C2 and `RunErasureJobTest` pin that a credential row does not move it. |
| Pedram reads the delete sheet's checkbox numbers on the phone in the C3 walk, not before | Nothing was installed or measured on the phone. The reading is **W8** below, with the snippet and the numbers I expect.                                                                                                                                            |

## 3. Behaviour to read before you clear C3

- **Who can click, and what stops a mistake.** Any platform admin (Q2). The button exists only on a Ready row and only the server decides Ready. To erase: the typed id (case-insensitive; the API upper-cases),
  a reason of 10 to 1000 characters (audit row and ledger only, "no personal details"), and a two-factor code **entered at the click** (TOTP or a recovery code). The code is checked **before** the claim, the
  field is emptied after every attempt, and wrong codes feed the existing per-admin throttle (5 per 15 minutes soft, 10 hard; it never locks the admin out by itself). **There is no replay protection on a
  TOTP code inside its 30-second window**; this is "verified at the click" and nothing stronger (Q4).
- **The plan is a promise.** The digest the dialog shows (12 characters of it) is sent with the click. If the data moved between the plan and the job, the job refuses, **releases the claim**, and the page
  says "The erasure did not start: the data changed since the plan…". The creator is back to normal (Ready again) and nothing was erased.
- **What "claimed" does to the creator.** From the click: signed out everywhere, tokens and push tokens revoked, creator sign-in answers unknown, made unavailable to agencies exactly as a pending request already does (C2-0): gone from discovery, the new-conversation list, the fan-out, the digest and the nudge, and refused for any new invite, connection request, pool add, accepted application or new thread (422 `creator.unavailable`). **The agency's roster, existing threads, assignments and history are unchanged and stay as they are, by the AH-104b-del design** (runbook §1); they read "Deleted creator" only after the erasure. If the worker is down, they stay
  that way, and the page says so (waiting, after an hour waiting-stuck). The creator can still cancel until the claim; after it, no cancel.
- **The state sentences on the Erasing tab** (en): `waiting` "Waiting for the erasure worker to pick it up."; `waiting_stuck` "Waiting for over an hour: the erasure worker may be down. Check it (see the
  erasure runbook), then review the plan and confirm again."; `running` "Erasing is under way."; `running_stuck` "Erasing has been running for over an hour and may have stopped. Review the plan and confirm
  again."; `incomplete` "The last run stopped before it finished. Review the plan and confirm again."; `db_done` "The database step is done, but the last step did not finish. This needs an engineer: follow the
  erasure runbook." **Stuck is strictly more than 3900 s** (the job's timeout plus five minutes; at exactly 3900 s it is not stuck; a spec pins both sides).
- **Retry is the same click.** On `incomplete` or a stale waiting or running state the button reads "Review and confirm again" and goes through the same dialog, plan, id, reason and code. `db_done` is never
  retryable from the page: the mirror is finished from the shell (runbook §1c).
- **Error codes the click can answer** (all `admin.*` so the SPA resolves them; 2FA failures are 403 or 423 because the SPA treats 401 as "sign out"):

  | Code                                                       | Status              | When                                 | Page says                                                                                                                                  |
  | ---------------------------------------------------------- | ------------------- | ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------ |
  | `admin.erasure.execute.disabled`                           | 409                 | flag off                             | "Erasing from the admin is switched off. A platform admin turns it on in Feature flags, with a reason, for one named account."             |
  | `admin.erasure.execute.ulid_mismatch`                      | 422                 | typed id wrong                       | "The id you typed does not match this account."                                                                                            |
  | `admin.erasure.execute.not_ready`                          | 422                 | gates refuse; `meta.codes` lists why | "This account is not ready to be erased." + the reasons                                                                                    |
  | `admin.erasure.execute.in_progress`                        | 409                 | already claimed                      | "An erasure of this account is already in progress."                                                                                       |
  | `admin.erasure.execute.mfa_required` / `mfa_invalid`       | 403                 | no code / wrong code                 | "Enter a two-factor code or a recovery code." / "That code is not valid."                                                                  |
  | `admin.erasure.execute.mfa_rate_limited` / `mfa_suspended` | 423                 | too many wrong codes / 2FA suspended | "Too many wrong codes. Wait 15 minutes, then try again." / "Your two-factor sign-in is suspended. Ask another platform admin to reset it." |
  | `admin.erasure.execute.rate_limited`                       | 429                 | 10 per hour per admin                | "Too many attempts. Wait an hour, then try again."                                                                                         |
  | `admin.erasure.plan.not_available` / `rate_limited`        | not available / 429 | plan                                 | "This account cannot be planned for erasure." / "Too many plan requests. Wait a minute, then try again."                                   |

- **The flag gates the button and its endpoint, nothing else** (Q9). The plan endpoint, the queue, the hold and the shell command do not read it.
- **No early erasure** (Q8). The API has no path around the grace period; only the shell's `--force-not-requested` does, as before.

## 4. Gates

Each run on its own, exit code read, at the final C3 state (working tree; nothing committed when these ran). Local PHP is 8.5, which prints PDO deprecation notices (`Constant PDO::MYSQL_ATTR_SSL_CA`):
that is the "deprecated" count in every Pest summary, environmental and the same as in C1 and C2.

| Gate                           | Command (from the package directory)                                                                                        | Exit | Result                                                                     |
| ------------------------------ | --------------------------------------------------------------------------------------------------------------------------- | ---- | -------------------------------------------------------------------------- |
| API tests, whole suite, serial | `SANCTUM_STATEFUL_DOMAINS='localhost,localhost:5173,127.0.0.1,127.0.0.1:5173,127.0.0.1:5174' php vendor/bin/pest` (no path) | 0    | 4097 deprecated, 18 passed, **0 failed**; 21,044 assertions                |
| API style                      | `php vendor/bin/pint --test` (no path)                                                                                      | 0    | `passed`                                                                   |
| API static analysis            | `php vendor/bin/phpstan analyse --memory-limit=2G` (no path)                                                                | 0    | `[OK] No errors` (level 8, `tests/` included; no ignores, casts or `@var`) |
| Admin app tests                | `npx vitest run` in `apps/admin`                                                                                            | 0    | 64 files, 597 tests passed                                                 |
| Lint                           | `npx eslint . --ext .ts,.vue` in `apps/admin`                                                                               | 0    | clean                                                                      |
| Types                          | `npx vue-tsc --noEmit` in `apps/admin`                                                                                      | 0    | clean                                                                      |
| Format                         | `npx prettier --check src tests` in `apps/admin`                                                                            | 0    | clean                                                                      |
| i18n parity                    | `node scripts/i18n/verify-locale.mjs <the 23 non-en locales>` (repository root)                                             | 0    | 23 PASS (main + admin against `en`)                                        |

`apps/main` and `packages/api-client` have **no change in this chunk** (C2-5 was the last change to `apps/main`), so their suites were not re-run; C2's results stand for them.
New and changed specs (Pest, run by file): `AdminErasureEraseTest` 47, `RunErasureJobTest` 14, `ErasureExecuteFlagTest` 3, `SecondFactorStepUpTest` 4, `AdminErasureQueueTest` 36 (plus the C2-4a edits to
`AdminCreatorConnectionTest`, `CreatorAgencyDirectConnectServiceTest`, `RunAutomationRuleJobTest`, `ErasureClaimerTest`, `ErasureClaimGuardTest`, `ErasureCommandTest`, `AdminCreatorSurfacesTest`).
Admin SPA: the "Erase click" block in `ErasureQueuePage.spec.ts` and the `it.each` in `CreatorDetailPage.spec.ts`.

**Break-reverts:** 61 distinct mutations (40 API, 21 SPA), 64 runs; **all caught**; three survived their first run and were closed with specs (log, with the table of survivors). Every file restored
identical.

## 5. Deviations from the plan, and what I decided

1. **The `admin.erasure.execute.*` and `admin.erasure.plan.*` codes** (not `erasure.*`), for the same reason as the hold's and the impersonation code: the admin SPA resolves a code as an `admin.*` key.
2. **`SecondFactorStepUp` extraction.** The plan said "the existing challenge and throttle". `AccountDeletionService` held that logic inline (the creator's delete request); I moved it to a service with an
   outcome enum and made `AccountDeletionService` and the Erase endpoint both read it (`SecondFactorStepUpTest`, 4; the existing deletion-request specs pass unchanged). The throttle numbers did not change.
   The admin passes `requireEnrolment: true`, so an admin with no second factor is refused where the creator path would pass an unenrolled account.
3. **The click's preflight does not check "audit-log step unavailable".** The plan endpoint shows it as a blocker (`audit_scrub_unavailable`, a spec) and the dialog will not offer the click, but a request
   sent by hand would claim the account and the job would then **release it as `refused`**. The creator would have been signed out for the minutes in between. I left it because the UI cannot reach it and
   the job's release is audited; say if you want the preflight to refuse it (a one-line change and one spec).
4. **`admin.erasure.execute.mfa_not_enrolled` has no locale string.** The API can answer it, but `EnsureMfaForAdmins` is on the whole admin route group and refuses a non-enrolled admin first, so the page cannot reach it.
   If it ever does, the generic "action failed" sentence shows. Not worth 24 files today; tech-debt item.
5. **Two quick clicks and a retry.** The claim is one locked transaction, so a second click that reaches it after the first queues nothing (a spec). **Two retry clicks on a stale state** may each queue a job; the
   second finds the account erased and does nothing, and `numprocs=1` means they cannot run at once. Tolerated, and in tech-debt (item 3).
6. **"Another run in progress" can, in theory, release a claim.** If two jobs for one account ever ran, the loser's refusal path releases the claim the winner holds. `numprocs=1` and `tries=1` prevent it; I
   did not add a guard (tech-debt).
7. **A local worker is needed to walk this** (`php artisan queue:work database_erasure --queue=erasure --tries=1 --timeout=3600`). `composer dev` is unchanged and does not start it.
8. **Path correction in the C2 docs.** The C2 review and the runbook named the queue at `/api/v1/admin/erasure-queue`; the real path is `/api/v1/admin/compliance/erasure-queue`. Fixed everywhere (docs only).
9. **The vendor step (`VendorGuard`) is still not exercised by any local walk**: it asks an external service. Nothing in C3 changes that; C4 is where it first runs for real.

## 6. Not verified, and the new tech-debt

- **The phone.** Nothing was installed or measured on the device in C3, as ruled (W8).
- **Postgres.** The new specs, like the hold's and the claim's, have run on sqlite only (the same open item as before C3). The `jobs` table is shared, and `FOR UPDATE` semantics differ.
- **Production timing.** The plan endpoint is synchronous; its rows-read cost is printed, but the **wall time of the dry-run on production data is unmeasured** (P9). Six requests a minute per admin is the cap;
  C4 should read the time on the first real plan before the flag is on for anyone else.
- **A real worker under supervisor.** The job is tested through the sync and the database driver in specs; the supervisor program is documentation until it is deployed. The walk below runs a real worker locally.
- **A real browser walk** of the dialog against the API: the component specs cover it with real Vuetify and i18n, not a Playwright run.
- **Still open from earlier:** the colleague's read-back E (before C4), AH-103d W3 on TestFlight build 3.
- **New tech-debt** ("AH-104c C3" in [`tech-debt.md`](../tech-debt.md), 9 items): dry-run wall time; sqlite-only specs; double retry; the release-on-in-progress
  refusal; `db_done` from the shell only; "fresh 2FA" is verified-at-the-click; the polled row; **(8) the Erase click's preflight does not refuse `audit_scrub_unavailable` (deviation 3); (9) no string for
  `mfa_not_enrolled` (deviation 4).**

## 7. The C3 walk (for Pedram, local, web admin unless marked)

Setup: API and the admin SPA running; the default queue worker running (`composer dev` does this); **and** `php artisan queue:work database_erasure --queue=erasure --tries=1
--timeout=3600` in a second terminal **but stopped for W4**. `QUEUE_CONNECTION` in `apps/api/.env` is `redis`; the erasure job does **not** use it (it names its own connection, the DB `jobs` table), so a local
Redis is not what matters for this walk, the database is. Use a test creator whose request is due (set `deletion_scheduled_for` to a past time) with no payments, no delivered work and no vendor account.

1. **W1, flag off.** Compliance, Erasure queue, Ready. The row shows Erase. Open it, fill the id, a reason and a code, click: the page says "Erasing from the admin is switched off…". Nothing changed on the account.
2. **W2, flag on, wrong things.** Feature flags: turn `account_erasure_execute_enabled` on, with a reason. Try a wrong id ("does not match"), a wrong code ("not valid"; the field empties), then a short reason
   (the confirm stays disabled). Held and Pending rows show no Erase button.
3. **W3, the plan.** The dialog shows numbers only (rows, tables, files, assignments to cancel, rows read, a 12-character plan reference) and no name or email.
4. **W4, no worker.** With the erasure worker stopped, do the real click with the right id, a reason and a fresh code. The page moves to Erasing and says "Waiting for the erasure worker to pick it up."; the
   creator is signed out. As `walk-agency`: the creator is gone from **Discovery** and from the **new-conversation** list, but **stays on the Roster** (and in existing threads and assignments) until the erasure.
5. **W5, worker on.** Start the erasure worker. The sentence changes to "Erasing is under way.", then the banner says the account was erased; the Erased tab lists it with no name; an agency that had them now shows
   "Deleted creator"; an assignment that was open told its agency.
6. **W6, the data moved.** For a second test creator: open the dialog (the plan loads), then in the database change something the plan counts (add a row it hashes), then confirm. The banner says "The erasure
   did not start…", the account is back in Ready, nothing was erased.
7. **W7, a stale wait.** For a third creator with the worker stopped, click Erase, then set `users.erasure_started_at` to two hours ago for that user (SQL for whichever database you run). The sentence becomes
   the "over an hour" one and the button reads "Review and confirm again". Do it: **exactly one** new job is queued (`select count(*) from jobs where queue = 'erasure'` goes from 1 to 2: the original and the retry).
   Start the worker; the account is erased once and the second job does nothing. Then turn the flag **off** again and run the Q10 check: `select count(*) from users where erasure_started_at is not null and erased_at is null` is 0.
8. **W8, the phone (the C2-5 reading you asked to do during this walk).** Install nothing new; use the build you have. Security, Delete account. With the inspector attached (or Safari's Web Inspector over
   USB), paste the snippet in [`account-deletion-admin-c2-review.md`](account-deletion-admin-c2-review.md) §5 and read the four numbers. **I expect `titleBox` 1, `ackIconBox` 22, `password` 25, `word` 25**
   (the checkbox's icon box starts 3 px left of the edge on purpose, so the drawn box lands at 25; `titleBox` is the card border). Please send the four numbers as printed; I did not measure on the device.

## 8. Questions for Pedram

None block the commit pair. Three for the review, each with my recommendation:

1. **Deviation 3: make the Erase click's preflight refuse `audit_scrub_unavailable`?** _Recommend: yes, as a small follow-up before C4_ (it is one line and a spec; the UI already cannot reach it).
2. **Deviation 4 (no string for `mfa_not_enrolled`): leave as is?** _Recommend: yes_, it is unreachable behind `EnsureMfaForAdmins`.
3. **C4 precondition: do the production dry-run timing and the colleague's read-back E first, with the flag still off.** _Recommend: yes_, in that order; then C4 is the first real erasure, named in the deploy
   log, for one test account.

## 9. Follow-up after the review (Pedram's rulings)

- **Question 1, yes:** the Erase click now refuses when the audit-log step is not available, **before** the second factor is checked and before any claim (first click, retry and `start()` itself). 422
  `admin.erasure.execute.not_ready` with `meta.codes` `['audit_scrub_unavailable']`; the dialog already has the sentence ("the audit-log step is not available here"). Deviation 3 and tech-debt item 8 are closed.
  Specs: a dataset case in the every-gate and the no-recovery-code specs, plus three dedicated specs (named reason, retry path, refusal at the claim). Pest 0 failed (4102 deprecated, 18 passed, 21,073
  assertions), Pint, PHPStan clean; the log has the five break-reverts.
- **Question 2:** left as is (deviation 4, tech-debt item 9). **Question 3:** the C4 order stands: dry-run timing and read-back E first, flag off.
- **Walk finding: the id was not readable.** The Erase dialog asks the admin to type the account's id, but the queue row showed only the name and email, and the dialog showed the id only as a small grey hint
  under the field (no way to copy it). Now **every queue row shows its id with a copy button** (`AccountIdCopy`), and the dialog shows "Account id: <id>" above the typed field, also with a copy button;
  the grey hint is gone (`dialog.erase.ulid_hint` removed, `admin.compliance.erasures.id.*` added, ×24 locales). **The typed confirmation is unchanged and still the only check**: copying does not fill the
  field (a spec pins it). The id is not personal data (the Erased tab already shows it). Admin Vitest 602 passed, ESLint, vue-tsc, Prettier, i18n parity clean; 8 break-reverts, all caught after two were
  closed (log).

## 10. The walk record (Pedram, 2026-10-26, local stack, dev build on the iPhone)

W1 to W8 **pass**.

- **W4 passed as designed.** The page said "Waiting for the erasure worker…" and the creator was signed out. The claimed creator **stayed on the agency's roster** until the erasure: the roster, existing threads,
  assignments and history are unchanged by a pending request or a claim (AH-104b-del), and C2-0 only makes a claim hide the creator where a request already does (discovery, the new-conversation list,
  the fan-out, the digest, the nudge, and every new action). My W4 text had said the roster would drop them; that was wrong and is corrected in §3 and §7.
- **W6 misfired once, and the product was right.** The Erase was confirmed before the data was changed, so `walk-erase-2` was erased normally ("The account was erased", Ready 3 to 2); `undo` then failed
  on the anonymised email. A second account was reseeded and W6 re-run in the right order (open the dialog, change the data, then confirm).
- **W8, the device reading** (iPhone 16 Pro, Safari Web Inspector, selector-free snippet): `activeOverlays` 1, `dataTestAttributes` 37, **`titleBox` 1, `ackIconBox` 22, fields 25 and 25**: exactly the expected
  numbers. **The C2-5 alignment fix is confirmed on the device.**
- **Found in the walk and fixed:** the account id was not readable (row and dialog; §9), and the dev stack lost its Docker engine three times, which is environmental (Docker Desktop stopped itself; the
  data and the claim state were intact each time).
- **Not exercised by the walk:** Postgres CI for the erasure specs (the local walk ran on the Docker Postgres 16, which is the first run of the C1 migration and the whole flow on Postgres, but not the test
  suite), the dry-run's wall time on production data, and a supervisor-run worker. They remain the C4 preconditions.
