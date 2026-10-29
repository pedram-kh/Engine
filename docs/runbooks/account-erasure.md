# Account erasure — runbook (AH-104a)

**What this is.** How an operator erases one creator account, what the command does and refuses, how to recover after a database restore, and what is
kept and for how long (the retention table in §7 is the input for the privacy notice, AH-105).

**Status (2026-10-25).** The engine is built and proven on sqlite fixtures, and the admin Erase button (§1c) is built locally behind a flag that is off. **Nothing has been erased in production.** Two things
must happen first (§2): a Postgres run of the erasure specs (tech-debt row 1) and the deploy (§8). Do not run `--execute`, or turn the flag on, for a real account before both.

**This is irreversible.** An erased account's name, email, phone, address, files and tokens cannot be recovered by the application. The only way back is a
database restore from a snapshot (7 days), and `erasure:reapply` then erases the account again (§6).

---

## 1. Who runs it, and when

- **The operator is the super admin (Pedram), through the AH-104c "Erase" button** (§1c; built in C3, behind the flag `account_erasure_execute_enabled`, which is off until C4): a request, a 14-day grace
  period, then an admin clicks Erase with a typed account id, a reason and a fresh 2FA code, and an audit row is written. There is **no automatic erasure**: the command is never scheduled and has no
  route (specs enforce both), and the only queued path to the engine is the click's job.
- **If the button is unavailable or broken, `php artisan erasure:run` on the server is the fallback only**, and only with the **dry-run output attached** to the request (counts and
  the digest; it contains no name, email, ip or object key, so it is safe to paste into a ticket).
- **The command asks the same gate as the button (AH-104c C1).** `--execute` first **claims** the account through `ErasureClaimer` (a locked transaction; see `05-SECURITY-COMPLIANCE.md` §3.3a), and the
  dry-run prints the gate's verdict as `NOT READY:` lines (a hold; "has not asked to be deleted"; "the grace has not ended: not due until <date>") and exits 1 when an execute would be refused. A normal
  execute needs a standing request whose grace has ended and no hold. The audit reason is fixed: `shell erasure: the grace has ended`.
- **`--force-not-requested --operator={your_ulid} --reason="..."`** is the only way past "not requested" and "not due". It never passes a hold, a payment, delivered work or a vendor. The reason is kept in the audit
  row after the erasure (`forced, not requested: <reason>`): **write no personal details in it**. Use it only for a legal obligation, and say which.
- **A claimed account is not asked again.** If a run stopped after the claim, the dry-run says `Claimed: yes (since ...)` and `--execute` retries it. The creator cannot sign in or cancel from the claim; a run
  refused before it began (a moved digest, a guard) hands the account back and writes `account.erasure_claim_released`.
- **Deploy rule (Q10): no deploy and no `queue:restart` while an erasure is claimed.** Check first: `select count(*) from users where erasure_started_at is not null and erased_at is null;` must be 0.
- A legal hold means the account is not offered for erasure. The columns (`erasure_held_at`, `erasure_held_by_user_id`) and the gate's refusal exist from C1; the admin page sets and releases a hold from C2
  (§1b). The command refuses a held account too, whoever set the hold.

### 1a. The request side (AH-104b-del): what an operator will see before an account is ready

A creator asks from the **Security** page (web: avatar menu; native: More), behind the global flag `account_deletion_enabled`. Until the flag is on, the entry is absent, `/creator/security` sends the person to the
dashboard and `POST /api/v1/me/account-deletion` answers 404. The public page `/delete-account` (no sign-in, no app link) tells a person who cannot open the app how to ask; it is outside `/creator/`, so the app does
not capture it.

What a request does (full description: `05-SECURITY-COMPLIANCE.md` §3.3a): stamps `users.deletion_requested_at` and `deletion_scheduled_for` (= +14 days), revokes every session, token and push token, writes
`account.deletion_requested`, sends one confirmation mail. **It erases nothing.** The account is only hidden and locked:

- **Agencies.** The creator leaves discovery, the new-conversation list, the job-posted fan-out, the digest and the nudge (`Creator::scopeVisibleToAgencies()`); a **new** invite, connection request, pool add,
  accepted application or new thread is refused with the neutral 422 `creator.unavailable` (the same answer for pending and erased: it never says which). The roster, existing threads, assignments and
  history are unchanged and stay writable, **silently**: no in-app row, push or mail to the creator. Cancelling (signing in again) clears the column and the creator reappears everywhere with no trace.
- **After the erasure** every agency payload carries `is_erased: true` and no name or avatar, and the agency app shows "Deleted creator" (`creatorLabel()`, one reader; an architecture spec forbids reading
  `display_name` or `avatar_url` directly in the agency modules). Open assignments were cancelled by the system (§4) and each agency's admins and managers got one in-app notice per assignment
  (`assignment.cancelled`, reason `creator_account_erased`, no creator name, no mail, no push).
- **What the operator must check before the Erase:** the claim re-reads the request under a row lock (the creator may have cancelled; cancelling is possible until the claim, **not** until `erased_at`),
  that the grace has ended, that there is no legal hold, and the dry-run count for `campaign_assignments.cancel`. An assignment in `live_verified`, `manually_verified` or `completed_on_approval` is
  **refused** (built in AH-104c C1, local until deployed): the engine guard, the dry-run, the request and the rider all agree, and the rider no longer cancels them.

**Turning the flag on is the risk event.** It is global; the walk (plan §9) runs on a named test creator with the flag on for a short window and off again after. A creator who already requested stays pending
and can still cancel by signing in whatever the flag says.

### 1b. The admin queue and the hold (AH-104c C2)

**Where.** Admin app, Compliance, "Erasure queue" (`/compliance/erasure-queue`). It is **read-only except for Hold, Release and (from C3, §1c) Erase**. The data comes from
`GET /api/v1/admin/compliance/erasure-queue?tab=&cursor=&per_page=` (platform admin with 2FA only).

**The six tabs, and what each row means** (an account is in exactly one; the first that applies wins: erased, in progress, held, blocked, ready, pending):

| Tab         | The account                                                                                                                                       | What you can do |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------- | --------------- |
| Pending     | asked to leave; the 14-day grace has not ended (the row says how many days are left). The creator can still cancel by signing in.                 | Hold            |
| Ready       | the grace has ended, no hold, no refusal. Nothing erases it by itself: an admin must click Erase (§1c), and only a Ready row has the button.      | Erase, Hold     |
| Blocked     | the grace has ended but a guard refuses; the row names the reason: `payment`, `delivered_work`, `vendor`, `not_erasable` (no creator profile).    | Hold            |
| Held        | an admin placed a legal hold (the page says "On hold"). Nothing in the platform will erase it, including the command.                             | Release         |
| In progress | an erasure has begun (the account is claimed: the page says "Erasing"). The creator can no longer sign in or cancel. The row says how far it got. | see §1c         |
| Erased      | the erasure finished. The row carries the date and **nothing of the person** (no name, email, ip or key).                                         | none            |

**The stuck states** are in §1c (the row says what to do: from C3 an incomplete or stale run is retried with "Review and confirm again"). Do **not** deploy or `queue:restart` while any account is "Erasing" (Q10; the read is in §1).

**Ready and Blocked are found by a bounded scan.** The guards (vendor above all) cannot be written as SQL, so the page reads the first **200** due accounts (in user-id order, the oldest accounts first) and asks each guard. If more than 200 are due
the tab says so ("Only the first 200 accounts that are due are checked at a time") and the counts carry `due_scan_capped`. At today's volume (a handful of requests) the cap is never reached; the cost is a few queries per due row and **none for the other four tabs**.
Work off the rows shown, or raise the cap (`ErasureQueue::DUE_SCAN_CAP`) before the volume does.

**The hold.** Hold and Release each need a **reason of 10 to 1000 characters**, kept in the audit row only (`account.erasure_held`, `account.erasure_hold_released`): **write no personal details in it** (the dialog
says so). A hold is refused for an account that is already claimed (409: the run has begun; it cannot be held) and for an account that is erased or is not a creator (422). It is idempotent: holding a held
account changes nothing and writes no second row. **A hold survives the creator cancelling** (the creator's cancel touches only the two request columns), so a person who cancels and asks again is still held.

**The digest and the credentials.** The claim revokes every session and token, so the dry-run's digest does not count those rows (they are still printed). A digest from an older release is not valid on this one: run the dry-run again.

**Agencies.** From the claim the creator is hidden from agencies the same way as from a request (C2-0: a force-claimed creator who never asked is no longer discoverable during the run), and every session and token
is revoked **at the claim**, in the same transaction.

**What an admin sees elsewhere.** Every admin screen that shows a creator (the lists, the detail, the app-usage list, push deliveries, the impersonation search) reads it through one helper: an erased creator
reads **"Deleted creator"** with no email (the placeholder `erased-<ulid>@erased.invalid` is never shown), and a creator who has asked to leave carries a **"Deletion requested"** chip. The chip is **admin only**:
an agency never learns that a request exists, so a cancelled request leaves no trace there.

**Closed in C3 (was tech-debt "AH-104c C2-4a"):** an admin can no longer connect a leaving creator (erased, deletion requested, or erasure claimed) to an agency, by hand (422 `creator.unavailable`, both doors) or by an
automation rule (the rule's action records a refusal and connects nothing).

### 1c. The Erase button (AH-104c C3)

**The switch.** `account_erasure_execute_enabled`, in the admin Feature-flags page, **off by default and off at deploy**. Off: the Erase endpoint answers 409 `admin.erasure.execute.disabled` before it reads
anything; the queue, the hold, the plan view and the command do not read the flag, and an erasure already claimed is **not** stopped by turning it off. Turn it on, with a reason, for one named account (C4),
and off again after. It is a pre-condition, not a safety net: the typed id, the digest and the 2FA code are what stand between a click and an erasure.

**Before turning it on.** §2 is done (snapshot, mirror prefix, S3 permissions, the Postgres run); `supervisorctl status` shows `catalyst-erasure-worker` RUNNING (`production-queue-worker.md` §2a); this returns 0:
`select count(*) from users where erasure_started_at is not null and erased_at is null;`.

**The click** (Ready row, "Erase…"). The dialog reads the **plan** (`GET .../erasure-queue/{user}/plan`, read-only, 6 a minute per admin): the database rows and tables it will change, the files, the open assignments
it will cancel (each tells its agency), the rows it reads, and the plan reference (the first 12 characters of the digest). Three things must be given before the button enables: the **account id typed**, a
**reason** (10 to 1000 characters; it goes into the audit row for good, so **no personal details**), and a **2FA code or recovery code**. A recovery code is used up when submitted; a TOTP code is "verified at
the click" and could be accepted again inside its 30 seconds (the verifier has no replay protection; do not read more into it). Wrong codes count against the same per-user 2FA throttle as sign-in (after 5 wrong codes in 15 minutes
the code is no longer even checked; 10 suspend the 2FA enrolment) and never against the account lockout. The button is limited to 10 an hour per admin.

**What the server does, in order:** the flag (409), the body, the typed id (422), a **read-only preflight** (422 `not_ready` with the reasons as codes; 409 `in_progress`; both answered **without** touching the 2FA
code, so a refused click never burns a recovery code), the 2FA code (403 / 423), then the **claim** (one locked transaction; it revokes every session and token and hides the creator from agencies), and only
after it commits the job is queued (202). A refusal at any point claims nothing and queues nothing.

**The run.** One job, `RunErasureJob`, on connection `database_erasure`, queue `erasure`, read only by its own worker (1 try, 1 hour). It recomputes the digest against the plan the admin saw:

- **the data moved since the plan, or a guard now refuses:** nothing was written; the job **releases the claim** (`account.erasure_claim_released`, cause `data_changed` or `refused`), the creator can sign in and
  cancel again, and the page says "The erasure did not start ... Nothing was erased". Review the plan and try again.
- **any other failure** (a crash, a timeout, a storage error): the **claim stays**, because files may already be gone. The ledger says how far it got.
- **success:** the account is erased; its row leaves the queue and appears on the Erased tab.

**What the Erasing row says, and what to do:**

| The row says                                                | It means                                     | Do                                                                                                                       |
| ----------------------------------------------------------- | -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| Waiting for the erasure worker to pick it up                | claimed, no ledger row yet                   | wait a few seconds; the page refreshes itself                                                                            |
| Waiting for over an hour: the worker may be down            | claimed more than 3900 s ago, still no run   | `supervisorctl status`; restart the erasure worker; then **Review and confirm again** (re-plans; queues exactly one job) |
| Erasing is under way                                        | the ledger says `started`                    | wait; do **not** deploy or `queue:restart`                                                                               |
| Erasing has been running for over an hour                   | `started` more than 3900 s ago               | read the worker log; then **Review and confirm again**                                                                   |
| The last run stopped before it finished                     | ledger `incomplete`                          | read the cause in the worker log; **Review and confirm again**                                                           |
| The database step is done, but the last step did not finish | the account **is erased**; the mirror failed | shell only: `php artisan erasure:run {user_ulid} --execute` alone (§4). The page offers no retry for this one.           |

A second click on a claimed account whose job is queued or running queues nothing (409 `in_progress`). A retry is audited as `account.erasure_started` with `mode: retry`.

**What the button cannot do.** Erase early (only the shell's `--force-not-requested` passes the grace, never a hold, a payment, delivered work or a vendor), erase a held account, or skip the 2FA code. The
audit rows are `account.erasure_started` (operator, the reason), `account.erasure_claim_released`, and the engine's own `account.erased`.

**Local development.** `composer dev` runs only the default queue listener, so a click leaves the job waiting. Start the worker in a second terminal:
`php artisan queue:work database_erasure --queue=erasure --tries=1 --timeout=3600`.

### 1d. The Monday routine (AH-105 D10)

Nothing erases itself (§1), so a person has to look. **Pedram works the queue once a week, on Monday.** This is the promise the privacy notice makes ("within one month of the grace ending",
`docs/store/privacy-policy-app-section.md`): the grace is 14 days, a request can wait at most 7 days for the next Monday, so the worst case from the request to the erasure is **about 21 days**, inside the month
with a margin for one failed run and a retry.

**Start condition: after the C4 preconditions close. No calendar date.** The routine does not begin while §2 is open. Today (2026-10-29) **nothing in §2 is closed** (Pedram's statement; each of its six items stays open
until you mark it done), and `account_erasure_execute_enabled` is not armed. Until they are, a request that reaches its day is
**held in the queue as Ready and not erased**: that is the intended state, not a fault. Record the day §2 closes in `deploy-log.md`, and start the routine on the Monday after it.

**Each Monday (10 minutes when the queue is quiet):**

1. **Admin app, Compliance, "Erasure queue".** Read the tab counts. **Pending** are inside the grace (nothing to do; the row says how many days are left). **Ready** are the ones to work. Look at
   **Blocked** and **In progress** too: a Blocked row names its reason (`payment`, `delivered_work`, `vendor`, `not_erasable`) and needs a decision, not a click; an "Erasing" row older than an hour is a stuck run (the table in §1c).
2. **For each Ready row, the pre-click checks of §1c, in order:**
   - `select count(*) from users where erasure_started_at is not null and erased_at is null;` returns **0** (no other erasure is claimed; Q10).
   - `account_erasure_execute_enabled` is on **for this account only** and you will turn it off again after (C4 posture; §1c "The switch").
   - Open the plan: read the open assignments it will cancel and the plan reference. If the counts look wrong for the account, **Hold** (reason, no personal details) and stop.
   - There is **no hold** on the row and no pending support message about this account (step 3). A person who writes in to cancel can still do so by signing in until the claim.
   - A fresh 2FA code. Type the account id. Write a reason with no personal details. Click Erase.
3. **The support inbox** (the address in `core/config/support.ts`; the API's `SUPPORT_EMAIL`). Read the week's mail for deletion requests from people who could not use the app (the public page `/delete-account`
   tells them to write), and for "I changed my mind" notes. A request from outside the app is handled by the creator signing in (which cancels) or by the operator, never by guessing an account from a name.
4. **After each Erase:** the row moves to **Erased**; check it carries no name, email or key; turn `account_erasure_execute_enabled` back off; run the §1 read of claimed accounts again (0).
5. **Write one line in the deploy log or the ops notes** only if something was refused, held or retried. A quiet Monday needs no entry.

**If a Monday is missed.** Nothing breaks and nothing is lost: the Ready row waits. Two missed Mondays push the worst case past the month the notice promises; if that happens, work the queue the same
day it is noticed and say so in the ops notes.

**What this routine does not do.** It does not erase an account whose grace has not ended (only the shell's `--force-not-requested` can, §1), does not pass a hold, a payment, delivered work or a vendor,
and does not touch the S3 or backup retention (§7: backups 7 days, logs 14 days; those age out by themselves).

---

## 2. Before the first real erasure

1. **The deploy is done**: three migrations (`users.erased_at` and `creators.erased_at`, `erasure_ledger`, and the `notifications(actor_user_id)` index, built `CONCURRENTLY`). See the AH-104a
   lines in [`deploy-log.md`](deploy-log.md). No env change, no scheduler change.
2. **A database snapshot exists and is `available`.** Erasure is not restorable by us; the snapshot is.
3. **The erasure specs have run against Postgres** (a CI job; tech-debt row 1). The specs run on sqlite and the Postgres grammar is compiled but not executed.
4. **The mirror prefix exists**: private `erasure-ledger/` on the media bucket, **versioning on** (Object Lock if the infrastructure owner adds it; not required). Confirm the app role can
   `PutObject`, `GetObject` and `ListBucket` on it.
5. **S3 versions.** If the media bucket is versioned, the app role needs `s3:ListBucketVersions` and `s3:DeleteObjectVersion`, or the run stops before it changes anything. If the bucket is **not**
   versioned the purge finds nothing and does nothing, but it still needs `s3:ListBucketVersions` to ask; if that permission is missing and versioning is off, set
   `ERASURE_PURGE_OBJECT_VERSIONS=false` (the dry-run then prints a warning). Production read-back D did not yet answer whether the bucket is versioned: ask.
6. **Read the dry-run's `Cost` line** for the account (§3). The audit scrub reads the whole of `audit_logs` once (38 MB at the read-back).

## 3. The dry-run

```bash
php artisan erasure:run {user_ulid}
```

It writes nothing: it issues `SELECT`s only and leaves every table byte-identical (a spec records the queries and hashes the tables). It prints:

- a table of every step: stage, selection, table, disposition, **rows** (what the step would change) and **read** (what it must scan to find them);
- `Cost:` the total rows read, the number of batches of 500, and the largest read; the notification scrub is batched and indexed, the audit scrub (`audit_logs.S5`) is not indexable;
- `Objects to delete:` counts by kind, never a key;
- `Audit scrub (4e): available`;
- any `REFUSED:` line (§5); with one, `--execute` will refuse and write nothing;
- a **digest**: a hash of the counts, the object list and the subject. It is recomputed at execute time, so an approval of an old state cannot run against a new one. The `read` and `Cost`
  figures are not part of it.

Read the **rows** column against what you expect for one creator: tens or hundreds, not thousands (the notification scrub is the largest by far; production holds 182,832 rows with
`creator_name` and 153,800 with `sender_name` across all creators). A number far larger than that is a predicate leaking across people: stop.

## 4. Execute

**From the admin, the Erase button (§1c) runs this same sequence in a worker.** The rest of this section is the shell command, the fallback.

```bash
php artisan erasure:run {user_ulid} --execute --confirm-digest={digest} --confirm-ulid={user_ulid} [--operator={your_ulid}]
```

Both confirmations are required. In order, a run does this (one failure leaves the account intact until step 4):

1. the audit scrub, **first**, because it needs the identity that step 4 destroys (a failure here leaves the account untouched);
2. vendors: none to call. Any non-`mock` provider on the account is a refusal, so this step is empty today;
3. the objects: avatar, cover, portfolio files and thumbnails, the creator's message attachments, and every stored version on a versioned bucket. Keys are read from the database **before** any row is
   deleted; a key already gone counts as done. Never by prefix; a key outside the creator's own prefixes is counted, skipped and kept;
4. **the database, in one transaction**: anonymise `users` and `creators`, delete the creator's own rows, scrub the JSON copies on other people's rows, write the ledger row and the
   `account.erased` audit row, **cancel the creator's open assignments** (below), and run a **closing audit pass** (a login during step 3 wrote a row step 1 never saw; if the closing pass fails the whole erasure rolls back);
5. the ledger mirror, then the ledger is `completed`.

**Open assignments are cancelled (AH-104b-del).** Every assignment of the creator that is not in a terminal state (`declined`, `rejected`, `completed_on_approval`, `payment_released`,
`cancelled`) and not soft-deleted is moved to `cancelled` through the state machine: no acting user, reason code `creator_account_erased`, one `assignment.cancelled` audit row each. Each
affected agency's admins and managers get one in-app notice per assignment (staff none, no email, no push); it carries no creator name, only `is_erased`. It appears in the dry-run as
`campaign_assignments.cancel` (stage 4h, disposition `CANCEL`) with a count, it is part of the digest, and its count is in the ledger. It runs **inside the database transaction**, after the
engine's writes and before the closing audit pass, so a failure rolls the whole erasure back. Terminal assignments and every other creator's assignments are left as they are.
An assignment in a payment-eligible state (`live_verified`, `manually_verified`, `completed_on_approval`: delivered work) is **not** cancelled: it is a refusal (§5), and the rider's selection and its re-read under
the lock both skip it. The dry-run's `campaign_assignments.cancel` count excludes it.

The output is counts and table names. On an unexpected failure it prints the exception class, not its message.

**If it stops:** read the ledger row (`php artisan tinker`: `ErasureLedger::latest('id')->first()`; it holds a state and numbers only).

- `incomplete`: the database stage did not commit; nothing was erased (some objects may be gone). Re-run the dry-run and execute again; every step is idempotent.
- `db_done`: the account is erased and the mirror write failed. Run `php artisan erasure:run {user_ulid} --execute` **alone**: it finishes the mirror and marks the ledger completed.

## 5. Refusals (the command writes nothing)

| Refusal                                                                                           | Why                                                                                            | What to do                                                          |
| ------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| Not a creator, or already erased                                                                  | The engine only erases creator accounts, once.                                                 | Nothing.                                                            |
| An assignment in `payment_held` / `payment_released`, or a `payment_id`                           | Money is held or owed; erasing would orphan the record. Zero in production at the read-back.   | Settle the payment first (Sprint 10 defines how).                   |
| An assignment in `live_verified`, `manually_verified` or `completed_on_approval` (delivered work) | The creator delivered and the agency may owe for it; erasing would delete the payout method.   | Wait for the agency to settle it; a bulk fix is not a runbook step. |
| `the account is on hold`                                                                          | An admin's legal hold (the gate, any mode, including `--force-not-requested`).                 | Release the hold first (C2); never bypass it.                       |
| `the account has not asked to be deleted` / `the grace has not ended`                             | The 14 days are a promise; no early erasure.                                                   | Wait for the date, or `--force-not-requested` with a legal reason.  |
| A provider other than `mock` on KYC, payout, contracts, social, or an event tied to the subject   | The vendor still holds data the engine cannot delete. No real vendor is enabled in production. | Build the vendor-side delete for that provider first.               |
| A wrong digest or typed ulid                                                                      | The data moved since the dry-run, or the wrong account.                                        | Re-run the dry-run and confirm the new digest.                      |
| Another run for the subject is in progress                                                        | A lock held up to two hours.                                                                   | Wait; do not delete the lock by hand.                               |
| `audit scrub not available`                                                                       | Only an implementation that reports itself unavailable; not the production binding.            | Treat as a bug.                                                     |

A spec makes any **new** table named `payment*`, `payout*` or `escrow*`, and any new assignment state containing "payment", fail CI until the erasure registry says what to do with it.

## 6. After a database restore

A snapshot taken before an erasure brings the person back (email, name, files' rows). The record that says "erase them again" is outside the database: one small object per completed erasure at
`erasure-ledger/{user_ulid}.json` on the media bucket (the ulid and a timestamp, nothing else; the application cannot delete it).

```bash
php artisan erasure:reapply                       # lists who the mirror says was erased and the restored database does not know as erased
php artisan erasure:reapply --execute --confirm-digest={digest} --confirm-count={n} [--operator={ulid}]
```

It lists ulids and counts only. It refuses the **whole batch** if any one person is refused, so nobody is half-done. Entries for a user this database never had are counted and left alone. Do
this **before** the restored application serves traffic where possible, and always before the next backup rotates the old state out.

## 7. What is kept, and for how long (inputs for the privacy notice, AH-105)

| Data                                                                                    | After an erasure                                                                                                                                                                                                                                                                       | How long                            |
| --------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------- |
| `users` and `creators` rows                                                             | Kept as anchors: name `[erased]`, email `erased-{ulid}@erased.invalid`, phone, address, date of birth, bio and photos removed; `erased_at` set; login is refused.                                                                                                                      | Indefinitely (no hard delete built) |
| Files: avatar, cover, portfolio, thumbnails, the creator's message attachments          | Deleted from the bucket, including every stored version on a versioned bucket.                                                                                                                                                                                                         | Gone at erasure                     |
| Sign-in: sessions, API tokens, push tokens, delivery logs, preferences, password resets | Deleted.                                                                                                                                                                                                                                                                               | Gone at erasure                     |
| Tax, KYC, payout, social accounts, availability, pool memberships, receipts             | Deleted, or replaced by a sentinel where the row must exist.                                                                                                                                                                                                                           | Gone at erasure                     |
| Messages the creator sent, applications, drafts, contracts, assignments                 | **Kept** (the other party's record, D-C/D-D); the creator's name on them reads `[erased]`; the application note and the creator's attachments are removed. **Open assignments are set to `cancelled`** (reason `creator_account_erased`, no acting user); finished ones are untouched. | Per the agency's own retention      |
| Notification copies on other people's feeds                                             | The creator's name is removed from the copy; the notification stays.                                                                                                                                                                                                                   | Per the agency's own retention      |
| `audit_logs`                                                                            | **Rows kept.** The person is removed from `ip`, `user_agent`, `metadata`, `before` and `after` (the rules in the PII map §4.1); ids, ulids, actions, reasons and decision notes stay.                                                                                                  | Indefinitely (no retention job)     |
| A failed login with the old address, **after** the erasure                              | Writes the typed address and an ip into a new audit row that nothing scrubs (tech-debt, AH-104e).                                                                                                                                                                                      | Indefinitely until AH-104e          |
| Database snapshots (RDS automated)                                                      | **Still contain the person** until they rotate out. A restore brings them back; `erasure:reapply` erases them again.                                                                                                                                                                   | **7 days**                          |
| Daily application log files                                                             | May contain the email in an exception or failed-login line.                                                                                                                                                                                                                            | **Up to 14 days**, then they rotate |
| Erasure ledger (database)                                                               | The user's ulid, a state, a digest and row counts. No email, name or ip.                                                                                                                                                                                                               | Indefinitely                        |
| Erasure ledger mirror (media bucket, `erasure-ledger/`)                                 | The user's ulid and a completion time. Versioned bucket.                                                                                                                                                                                                                               | Indefinitely                        |

No copy exists in CloudWatch, Sentry, an external log or data store, or an audit export (production read-back D, 2026-10-20). If that changes, this table and the notice change with it.

## 8. Deploy obligations

Recorded in [`deploy-log.md`](deploy-log.md) under the pending entry: three additive migrations, no env change, no scheduler change, no worker restart for this code. The only slow step is the
`CONCURRENTLY` index on `notifications` (no lock); a failed build leaves an invalid index that `DROP INDEX CONCURRENTLY IF EXISTS idx_notifications_actor` clears before the migration is re-run.

## 9. Where things are

- Engine: `apps/api/app/Modules/Erasure/` (registry, selections, steps, files, guards, run, console, ledger).
- Plan and rulings: [`../reviews/account-deletion-erasure-plan.md`](../reviews/account-deletion-erasure-plan.md); PII map: [`../reviews/account-deletion-pii-map.md`](../reviews/account-deletion-pii-map.md);
  read-back results: [`../reviews/account-deletion-production-readback.md`](../reviews/account-deletion-production-readback.md).
- Open risks: [`../tech-debt.md`](../tech-debt.md), "AH-104a erasure engine", "audit_logs is not append-only in production", "Daily application logs".
