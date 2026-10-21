# Account erasure — runbook (AH-104a)

**What this is.** How an operator erases one creator account, what the command does and refuses, how to recover after a database restore, and what is
kept and for how long (the retention table in §7 is the input for the privacy notice, AH-105).

**Status (2026-10-21).** The engine is built and proven on sqlite fixtures. **Nothing has been erased in production.** Two things must happen first (§2):
a Postgres run of the erasure specs (tech-debt row 1) and the deploy (§8). Do not run `--execute` on a real account before both.

**This is irreversible.** An erased account's name, email, phone, address, files and tokens cannot be recovered by the application. The only way back is a
database restore from a snapshot (7 days), and `erasure:reapply` then erases the account again (§6).

---

## 1. Who runs it, and when

- **The operator is the super admin (Pedram), through the AH-104c "Erase" button** once it exists: a request, a 14-day grace period, then an admin clicks Erase with a confirmation and an
  audit row. There is **no automatic erasure**: the command is never scheduled and has no route (specs enforce both).
- **Until AH-104c exists, or if it is broken, `php artisan erasure:run` on the server is the fallback only**, and only with the **dry-run output attached** to the request (counts and
  the digest; it contains no name, email, ip or object key, so it is safe to paste into a ticket).
- A legal hold (AH-104c) means the account is not offered for erasure. The command does not know about holds; the check is on the person running it.

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
- **What the operator must check before AH-104c's Erase:** the request is still pending under a row lock (the creator may have cancelled, including after `scheduled_for`), no legal hold, and the dry-run
  count for `campaign_assignments.cancel`. An assignment in `live_verified` or `manually_verified` will be **refused** (ruled 2026-10-21, to be built in AH-104c: engine guard plus a request-time refusal),
  not cancelled; today (before AH-104c) the rider still cancels those two states, which is why nothing runs against a real account yet.

**Turning the flag on is the risk event.** It is global; the walk (plan §9) runs on a named test creator with the flag on for a short window and off again after. A creator who already requested stays pending
and can still cancel by signing in whatever the flag says.

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
An assignment in `live_verified` or `manually_verified` (delivered work, payment-eligible) **is** cancelled today: read that line of the dry-run before executing.

The output is counts and table names. On an unexpected failure it prints the exception class, not its message.

**If it stops:** read the ledger row (`php artisan tinker`: `ErasureLedger::latest('id')->first()`; it holds a state and numbers only).

- `incomplete`: the database stage did not commit; nothing was erased (some objects may be gone). Re-run the dry-run and execute again; every step is idempotent.
- `db_done`: the account is erased and the mirror write failed. Run `php artisan erasure:run {user_ulid} --execute` **alone**: it finishes the mirror and marks the ledger completed.

## 5. Refusals (the command writes nothing)

| Refusal                                                                                         | Why                                                                                            | What to do                                            |
| ----------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- | ----------------------------------------------------- |
| Not a creator, or already erased                                                                | The engine only erases creator accounts, once.                                                 | Nothing.                                              |
| An assignment in `payment_held` / `payment_released`, or a `payment_id`                         | Money is held or owed; erasing would orphan the record. Zero in production at the read-back.   | Settle the payment first (Sprint 10 defines how).     |
| A provider other than `mock` on KYC, payout, contracts, social, or an event tied to the subject | The vendor still holds data the engine cannot delete. No real vendor is enabled in production. | Build the vendor-side delete for that provider first. |
| A wrong digest or typed ulid                                                                    | The data moved since the dry-run, or the wrong account.                                        | Re-run the dry-run and confirm the new digest.        |
| Another run for the subject is in progress                                                      | A lock held up to two hours.                                                                   | Wait; do not delete the lock by hand.                 |
| `audit scrub not available`                                                                     | Only an implementation that reports itself unavailable; not the production binding.            | Treat as a bug.                                       |

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
