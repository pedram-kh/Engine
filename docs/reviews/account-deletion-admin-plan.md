# AH-104c — The admin erasure queue, the hold, the Erase button, the delivered-work refusal — PLAN (plan-pause)

- **Status:** **PLAN, awaiting clearance. No code, no migration.** Written 2026-10-22 after Pedram accepted the inventory
  ([`account-deletion-admin-inventory.md`](account-deletion-admin-inventory.md)) and ruled Q1 to Q13 (table in §1). Read-back E for the colleague:
  [`account-deletion-production-readback-e.md`](account-deletion-production-readback-e.md).
- **Split (ruled):** **C1** guards and gates (API only, no route) · **C2** hold, queue read side, admin read-through (and the device-measured alignment fix) · **C3** Erase · **C4** the first real
  erasure (a named act, not code). Each of C1 to C3 ends green on its own (Pest, Pint, PHPStan with **no path argument**; on the web side Vitest, ESLint, typecheck, i18n parity). **Nothing that can erase
  is reachable from the admin before C3, and C3 ships behind a flag that is off.**
- **PROD-DATA RISK per chunk:**
  **C1: MEDIUM** (three nullable columns on `users`; a new answer in the login path for a claimed account; the engine's `execute` now needs a claim; the rider stops cancelling two states; nothing
  can erase because no route and no schedule exist). **C2: LOW to MEDIUM** (admin reads; one admin write, the hold, which only prevents things; payloads gain fields). **C3: HIGHEST** (the first
  irreversible action reachable from a browser; flag off at deploy). **C4: HIGHEST** (real data, one named account).
- **Standards applied:** `WORKING-PROCESS` §2 Mode A, §5 (additive migrations, no data write in a migration, flag default off, snapshot before deploy), §5.34 over-reach negatives, §5.35 break-reverts with
  the exit code read, failing-by-default specs for every new surface. Global Pest helper names are prefixed. Local test runs use the `SANCTUM_STATEFUL_DOMAINS` env. One long gate per command, exit code read.

---

## 1. Rulings recorded (2026-10-22) and what each becomes

| Q   | As ruled                                                                                                                                                                                                                               | Becomes      |
| --- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
| 1   | No new table: the queue is a view over `users` and the ledger, plus the hold column                                                                                                                                                    | C1-1, C2-2   |
| 2   | Any platform admin may Erase; operator in ledger and audit                                                                                                                                                                             | C3-4         |
| 3   | **The claim.** Supersedes "cancel until `erased_at`" with "cancel until the claim". **Recorded 2026-10-22** in the request plan (AH-104c note 2), the Part A review and tech-debt (Part A item 2)                                      | C1-2, C1-3   |
| 4   | A held creator may cancel; the hold stays on the row                                                                                                                                                                                   | C2-1         |
| 5   | (a) stays pending, queue shows "Blocked: delivered work"; **and** new live verification for a pending creator refused at the agency end, 422 `creator.unavailable`. "Writable" ruling narrowed (recorded in the request plan, C4 line) | C1-4, C1-5   |
| 6   | Admin-initiated requests later (AH-104c2)                                                                                                                                                                                              | out of scope |
| 7   | No final mail in v1                                                                                                                                                                                                                    | out of scope |
| 8   | Typed ulid + reason + fresh 2FA code                                                                                                                                                                                                   | C3-4         |
| 9   | `erasure:run --execute` asks the same gate; `--force-not-requested` with its own audit reason                                                                                                                                          | C1-6         |
| 10  | `account_erasure_execute_enabled`, off at deploy                                                                                                                                                                                       | C3-1         |
| 11  | A dedicated `erasure` queue and worker with a long timeout                                                                                                                                                                             | C3-2         |
| 12  | Scope: C1 to C3, the device-measured alignment fix, the impersonation refusal; recovery switch on the other 2FA pages stays in tech-debt                                                                                               | §4           |
| 13  | Read-only read-back E for the colleague                                                                                                                                                                                                | before C4    |

## 2. What the plan found that the inventory did not (each is settled here or is a question in §9)

**P1. Correction to inventory F4: production does not run Horizon.** The inventory said Horizon's single supervisor has a 60-second timeout. `config/horizon.php` is the local/dev shape. The production
read-back (2026-10-12, `deploy-log.md`) says **`supervisorctl`: `laravel-queue` RUNNING (`queue:work`, not Horizon)**, `queue.default = database`, and the runbook's program is
`queue:work --memory=768 --tries=3 --max-time=3600` with `stopwaitsecs=120` ([`production-queue-worker.md`](../runbooks/production-queue-worker.md)). The conclusion stands (erasure cannot run on the default
worker) but the obligation changes, and there is a trap the inventory missed: **the `database` connection's `retry_after` is 300**, so a job that runs longer than 300 s is delivered a **second time while
the first is still running**, and `--tries=3` would run it again after a failure. The plan therefore needs a **second connection** (`database_erasure`: same `jobs` table, `retry_after` above the job timeout)
and a **second supervisor program** that runs only that queue with `--tries=1` and a long `--timeout`. The default worker never reads the `erasure` queue, so it cannot pick the job up early.
**C11 is therefore a supervisord obligation, not a Horizon one.**

**P2. The migration moves from C2 to C1.** The gate (C1) must read the hold, so all three `users` columns (`erasure_started_at`, `erasure_held_at`, `erasure_held_by_user_id`) arrive in **one**
migration in C1; C2 adds only the endpoints and the page that write the hold. Nothing else in the split changes.

**P3. The claim is a value, not a convention.** "The engine requires a claim" is made structural: `ErasureRun::execute()` takes an `ErasureClaim` that only `ErasureClaimer` can construct (a final
readonly object with a private constructor and a named factory the claimer calls), and an architecture spec forbids `new ErasureClaim` elsewhere and forbids calling `execute()` from anywhere but the
job and the command. A future caller therefore cannot reach the irreversible steps without having passed the gate. Cost: about **44 call sites in 6 spec files** that call `execute` today
(`ErasureRunTest`, `ErasureCommandTest`, `ErasureMirrorTest`, `ErasureAuditScrubTest`, `ErasureCancelOpenAssignmentsTest`, `ErasedCreatorAgencySurfacesTest`) get one test helper that claims in
`force` mode; mechanical, and their assertions do not change.

**P4. `completed_on_approval` is the same problem as the two ruled states, and it is not covered.** `AssignmentStatus::isPaymentEligible()` is true for `live_verified`, `manually_verified` **and
`completed_on_approval`**. The last is terminal, so the rider never cancels it, but an erased creator cannot be paid for it (the payout methods are deleted). The ruling named two states. **Question C1.**
Either way the guard is written against the **predicate**, not a literal list, with a total-classification spec (P5), so the answer is a one-line decision.

**P5. A total classification spec for assignment states.** Every `AssignmentStatus` case must be in exactly one of: _cancelled by the rider_ (non-terminal, not delivered), _refuses erasure_
(payment or delivered), _terminal, left alone_. CI fails for a new state until someone classifies it. It generalises `PaymentPolicy` (which only looks at names containing "payment").

**P6. "New live verification" has two doors, and only one is an agency end.** The agency's **manual verification** (`CampaignAssignmentResolutionController::manuallyVerify`) is HTTP and gets the 422.
The other door is the **automatic post check** (`VerifyPostedContentJob` → `CampaignAssignmentStateMachine::verifyLive`, system actor), which has no agency end. If the job simply threw after it had
saved the posted-content outcome, the row would read `verified` while the assignment stays `posted`. So the job asks first (`isUnavailableToAgencies()`), and for a pending creator **returns before
calling the provider or saving anything**, leaving the posted content `pending`; the job is already idempotent on `pending`. A cancelled request must then re-run it: **a listener on cancellation
re-dispatches the job for that creator's `posted` assignments whose latest posted content is still `pending`** (new event `AccountDeletionCancelled`, dispatched after commit by `cancel()`). A
manifest spec lists every caller of `verifyLive` and `manuallyVerify` and fails on an unlisted one. The creator's pending verification is therefore **delayed, never lost**, and an erased creator's
`posted` assignment is cancelled by the rider as today. **Question C2 confirms the delay.**

**P7. The 422 line must reach the agency's screen.** `ResolveVerificationDrawer.vue` does not map `creator.unavailable` today (`AcceptApplicationDialog`, `AddCreatorsToPoolDialog` and
`InviteCreatorsDialog` do). C1-5 adds the mapping and a spec; the line itself (`creator.unavailable`) already exists in 24 locales.

**P8. The Erase click cannot compare a digest the admin saw with the one the engine will compute without doing the work twice.** The plan step (the dry-run) reads the whole of `audit_logs` once to
count what 4e would scrub (38 MB at the read-back); the engine repeats it. So: the execute endpoint **claims and queues**; the **job** recomputes and compares the digest. A mismatch (the data moved
since the admin looked) is a normal outcome, not an error: the job writes nothing, **releases the claim** (so the creator can cancel again; one audit row `account.erasure_claim_released` with the
coarse refusal codes), and the page shows "the data changed, review the plan again". Retrying is the same two steps again; a claimed account that failed **after** the files step (`incomplete`) is
**not** released: it stays claimed and the admin re-plans and confirms again (the plan works on a claimed account; the claim is idempotent for the same subject). No separate retry endpoint.

**P9. The dry-run's duration on production data is unknown.** It is a set of `SELECT`s whose cost line says rows read, not seconds. C3 builds the plan endpoint **synchronously with a rate limit**
and C4's preconditions include timing `erasure:run {ulid}` (dry-run) on the snapshot restore. If that exceeds roughly 10 seconds the plan becomes a queued job that writes its result to a short-lived
cache entry the page polls (a contained change). **Question C3.**

**P10. A stuck claim must be visible.** `erasure_started_at` set with no worker picking the job (worker down), a ledger `started` row older than the job timeout, or `db_done` with the mirror failed are
three different "stuck" states. The queue's **In progress** tab shows the claim age, the ledger state and one plain sentence per state, so a down worker is seen in the page and not found by accident.

**P11. Admin 2FA at the click is "verified at the click", not "unused".** The existing `TwoFactorService::verifyTotp` has no replay protection (window only; the earlier plan's P1 recorded the same).
The Erase click verifies a TOTP or a recovery code through `TwoFactorChallengeService` and the existing `TwoFactorVerificationThrottle` (a wrong code never feeds any account-lockout counter, as in the
creator deletion door). A TOTP reused within its window is accepted; a recovery code is burned. I will say "fresh" only in that sense. **Question C4.**

**P12. The hold's reason is retained after erasure.** `audit_logs.reason` is kept by design (runbook §7). The hold dialog says in its own words "do not put personal details in the reason", the column
stores only `erasure_held_at` and `erasure_held_by_user_id`, and the reason lives in the audit row only. `erasure_held_by_user_id` is an **admin's** id, not the subject's data; **Question C5** is the
registry classification.

## 3. The design (decisions the specs will pin)

### 3.1 The state machine of an account in the queue

| State (derived) | From                                                                                      | Creator can cancel by sign-in? | Erase available?                          |
| --------------- | ----------------------------------------------------------------------------------------- | ------------------------------ | ----------------------------------------- |
| **Pending**     | `deletion_requested_at` set, `scheduled_for` in the future                                | yes                            | no (not due)                              |
| **Ready**       | pending, `scheduled_for <= now()`, not held, not claimed, no refusal                      | yes                            | **yes** (after the flag and the click)    |
| **Blocked**     | pending, due, a guard refuses (payment, vendor, delivered work); shows the coarse codes   | yes                            | no                                        |
| **Held**        | `erasure_held_at` set (with or without a request)                                         | yes (the hold stays, Q4)       | no, until released                        |
| **Claimed**     | `erasure_started_at` set, `erased_at` null (ledger `started`, `incomplete`, or `db_done`) | **no** (answers as unknown)    | re-plan and confirm again after a failure |
| **Erased**      | `erased_at` set (ledger `completed`)                                                      | no                             | no                                        |

Precedence when several apply: erased, claimed, held, blocked, ready, pending. The queue's tabs are these states; the counts are in the response `meta`.

### 3.2 The gate (`ErasureClaimer`), one place, asked by the button and by the command

`claim(User $user, ?User $operator, ClaimMode $mode): ErasureClaim`, in **one transaction with the user row locked** (`lockForUpdate`), re-reading everything after the lock:

1. the subject resolves (`SubjectSet::resolve` rules: a creator, not erased);
2. **not held** (both modes; a legal hold is not overridden by force, it is released first);
3. mode `normal`: `deletion_requested_at` set **and** `deletion_scheduled_for <= now()` (the grace is a promise to the creator; no early erasure);
   mode `force_not_requested` (shell only): no request needed; requires an operator and writes the reason `forced: not requested` in the audit row;
4. `Refusals::for($subject)` is empty (payment, vendor, **delivered work**), asked again here;
5. then stamp `erasure_started_at = now()` (idempotent if already stamped for this subject) and write the audit row `account.erasure_started` (operator, reason, mode).

A refusal at any step throws `ErasureRefused` with the coarse codes and **writes nothing**. The claimer is also where `release()` lives (P8) and where `resume()` returns the claim for a subject that is
already claimed (the job, a retry).

### 3.3 What a claimed account looks like (C1-3)

- **Sign-in:** `AuthService` answers exactly as for an unknown email, **before** the password is checked, the same branch and the same audit reason as an erased account
  (`isErased() || isErasureClaimed()`); a spec compares the full envelope with a never-registered address.
- **Cancel:** `AccountDeletionService::cancel()` returns false for a claimed account (the caller already answers false as an unknown account); `request()` answers `NotAvailable`.
- **Tokens:** already refused for a pending user (`CreatorTokenGuard`); the claimed account is still pending, so unchanged.
- **Agencies:** still hidden (the scope reads `deletion_requested_at`); nothing visible changes at the claim.
- **Impersonation:** refused for a pending, claimed or erased target (C1-7, F8).

### 3.4 The delivered-work refusal (C1-4)

`DeliveredWorkGuard` beside `PaymentGuard`, written against `AssignmentStatus::isPaymentEligible()` minus the states `PaymentGuard` already names (so a new payment-eligible state is refused by default),
**once Question C1 is answered**; until then it names `live_verified` and `manually_verified` as ruled. Wired into `Refusals::for()` (so the dry-run's `REFUSED:` line, `execute`, the claim and the button
all see it), into `ErasureEligibility` as the code `delivered_work` (so the **request** is refused with 422 `account_deletion.blocked`, reason `delivered_work`), and into the dialog copy
`account_deletion.blockers.delivered_work` ×24. `CancelOpenAssignments` excludes the same states even though the refusal comes first (belt and braces: a defect in one place cannot cancel
delivered work). Soft-deleted assignments are included in the refusal, as `PaymentGuard` does today.

### 3.5 Erase, end to end (C3)

1. **Plan** `GET /api/v1/admin/compliance/erasure-queue/{user}/plan`: the dry-run (counts, objects, cost, refusals, digest, `campaign_assignments.cancel`, the number of agency notices), the gate's
   verdict, no writes. Rate limited.
2. **Execute** `POST …/{user}/erase` with `ulid` (typed), `reason`, `digest` (from step 1), `mfa_code`: (a) the flag `account_erasure_execute_enabled` must be on (409 `erasure.execute_disabled` if
   not); (b) the 2FA code (P11); (c) the typed ulid equals the subject; (d) `claim()` (3.2); (e) the job is queued on `erasure` **after commit**; (f) **202** with the claim and the ledger status URL.
3. **The job** (`RunErasureJob`, `queue erasure`, connection `database_erasure`, `tries 1`, `timeout` 3600): `resume()` the claim, `ErasureRun::execute($user, $digest, $ulid, $operator)`. A digest
   mismatch or refusal: release the claim and audit it (P8). Any other exception: leave the claim, the ledger says how far it got, and the page says so.
4. **Status** `GET …/{user}` (the queue row with its ledger): the page polls it every few seconds while claimed.

### 3.6 The queue's read side (C2)

`GET /api/v1/admin/compliance/erasure-queue?tab=&cursor=&per_page=`: cursor-paginated on `users.id`, one tab at a time, tab counts in `meta`. Subject key in the URLs and the typed confirmation is the
**user ulid** (the engine, the ledger, the runbook and `erasure:run` all key on it); each row also carries the creator ulid for the link to the existing creator detail page. A row shows name and email
while the account exists (the admin may see them), requested and due dates, days left, hold state and the blocker **codes** (never the guards' own sentences: those carry counts and table names).
The **Erased** tab reads `erasure_ledger` only (user ulid, completed time): no name exists to show and no page may join to the anonymised row for one. Blockers are computed for the rows on the page
only (two or three small queries per row; the queue is tens of rows, not thousands).

### 3.7 The admin read-through (C2)

`is_erased` and `deletion_pending` (admin only; **never** emitted to an agency, the existing rule) on the admin creator list, detail, creator-app usage and impersonation user-search payloads; one SPA
reader (`apps/admin/src/core/creators/creatorLabel.ts`, the twin of the main app's) and a "Deleted creator" label plus a "Deletion requested" chip; an architecture spec with an **admin manifest** (the
shape of `AgencyCreatorSurfaces`) that fails on an admin resource carrying a creator and not the flag, and a static spec forbidding a bare `.display_name` in the admin modules outside the reader.

## 4. Build steps

Each step: **what it changes**, then the specs and the break-reverts it must carry. Every break-revert is run, the exit code read, and the tree restored before the next. Global helper names are prefixed.

### C1 — Guards and gates (API only; no route; PROD-DATA RISK: MEDIUM)

**C1-1 — Schema, model, registry, factories.** One additive migration on `users`: `erasure_started_at`, `erasure_held_at` (nullable timestamps) and `erasure_held_by_user_id` (nullable FK to `users`,
`nullOnDelete`). No backfill, no data write; `down()` drops them. `User` casts and helpers: `isErasureClaimed()`, `isErasureHeld()`. The three columns are classified in `ErasureRegistry` in the same
commit (the registry spec fails CI otherwise; timestamps `KEEP`, the holder per Question C5). `UserFactory` states `erasureClaimed()` and `erasureHeld()`. _Specs:_ migration up and down; the registry
spec passes and **fails when a column is unclassified**; the model helpers. _Break-reverts:_ a column left out of the registry (the spec must fail); the cast removed.

**C1-2 — `ErasureClaim`, `ErasureClaimer`, the gate, the engine signature (P3).** As §3.2. Audit verbs `account.erasure_started` and `account.erasure_claim_released` added to `AuditAction` with their
retention class (I did not check whether a spec forces one for a new verb; if it does, it is satisfied in this commit). `ErasureRun::execute()` now takes the claim; `plan()` is unchanged. The ~44
spec call sites use one helper. _Specs:_ every gate leg refuses (not a creator, erased, held, not pending, not due, one day before due, a refusal from each guard), each with **nothing written** (no
`erasure_started_at`, no audit row, no ledger row); the due-date boundary (the instant of `scheduled_for` passes, one second before refuses); **two concurrent claims** (the second finds it stamped and
is idempotent); a cancel that lands first makes the claim refuse; `force_not_requested` needs an operator, writes the distinct reason, and still refuses a held account; the arch specs (only the
claimer constructs a claim; `execute` is called only from the job and the command). _Break-reverts:_ the lock removed; the re-read after the lock removed; the held check removed; the due check removed
or flipped; the refusals not asked in the claim; the stamp written before the checks; the audit row not written; `execute` accepting no claim (a spec fails to compile: asserted by the arch spec).

**C1-3 — The claim window.** `AuthService` (claimed → unknown account, before the password), `AccountDeletionService::cancel()` and `request()` as §3.3. _Specs:_ the full response envelope of a claimed
account equals a never-registered address's, on both doors (the byte-equality precedent); a claimed creator's correct password and a `cancel_deletion: true` both fail the same way; the account stays
hidden; the erased-login precedent spec extended. _Break-reverts:_ the claimed check placed after the password check (the envelope spec must fail); `cancel()` restoring a claimed account; the audit
reason for a claimed sign-in differing from `unknown_email`.

**C1-4 — The delivered-work refusal, both halves (P4, P5).** As §3.4, plus the total-classification arch spec, the dry-run line, the runbook §4 and §5 tables, the registry text. i18n: **one** new key
in `creator.json` ×24 (`account_deletion.blockers.delivered_work`), machine draft, en and es human pass as before. _Specs:_ each ruled state refuses in `Refusals::for`, in the dry-run, in
`ErasureEligibility`, in the request (422 `account_deletion.blocked` with the reason), and in the claim; a **non-delivered, non-terminal** state is still cancelled and not refused; the rider's
selection excludes the delivered states (and the dry-run count agrees); the classification spec fails for a synthetic new state; the dialog shows the line for the new code; the 24-locale key exists.
_Break-reverts:_ a delivered state dropped from the guard; the guard not wired into `Refusals`; not wired into eligibility; the rider selection not excluding them; the key missing in one locale.

**C1-5 — Q5 at the agency end (P6, P7).** `CampaignAssignmentResolutionController::manuallyVerify` asserts `assertAvailableToAgencies()` before the machine (422 `creator.unavailable`);
`VerifyPostedContentJob` returns before the provider call for an unavailable creator, leaving the posted content `pending`; `AccountDeletionService::cancel()` dispatches `AccountDeletionCancelled`
after commit and a Campaigns listener re-dispatches the job for the creator's `posted` assignments with a `pending` latest posted content; the manifest spec of callers; `ResolveVerificationDrawer`
maps the code. _Specs:_ a pending creator's manual verification answers 422 and writes nothing (assignment stays `posted`, no audit row, no notification); the job leaves a pending creator's posted
content `pending` and does not call the provider (a spy); an ordinary creator is verified exactly as before (the over-reach negative); after a cancellation the job runs and the assignment becomes
`live_verified`; an erased creator's `posted` assignment is cancelled by the rider, not verified; an **existing thread of a pending creator is still writable and silent** (the narrowing does not
widen); the manifest spec fails on a new caller; the drawer shows the neutral line. _Break-reverts:_ the controller check removed; the job check removed (the provider is called); the job saving
the outcome before checking; the re-dispatch listener removed; the check applied to every creator (the negative spec fails); the drawer mapping removed.

**C1-6 — `erasure:run` asks the same gate (Q9).** The dry-run prints the gate's verdict (a plain line per leg with the date for "not due"), `--execute` claims through `ErasureClaimer`,
`--force-not-requested` requires `--operator` and is the only way past "not requested" and "not due" (never past a hold), and the audit row says `forced: not requested`. The runbook §1 and §4 say so.
_Specs:_ the dry-run output for each verdict; execute on a not-due creator refuses; force on a never-requested creator succeeds with the distinct reason and the operator in the ledger; force without an
operator refuses; force on a held account refuses; nothing printed contains a name, email or key (the existing output spec extended). _Break-reverts:_ the command bypassing the claimer; force
skipping the hold; force without the operator; the verdict line missing.

**C1-7 — The impersonation refusal (F8).** `ImpersonationService::start()` and `claim()` refuse a target that is pending, claimed or erased with a new `ImpersonationException`
(code `impersonation.target_unavailable`, 422); the admin search still lists them (the admin must be able to find who they cannot enter) but the SPA shows the reason from the error. The admin app's
error mapping and 24 locales get the one code. _Specs:_ each of the three targets refused at start and, with a hand-made session row, at claim; a normal creator and an agency user unchanged; no
audit row for a refused start beyond the existing refusal precedent. _Break-reverts:_ the check removed from `start()`; removed from `claim()`; applied to an ordinary creator.

**C1-8 — Docs and the gate.** Review file `account-deletion-admin-c1-review.md`; break-revert log; the request plan's AH-104c notes updated to "built"; `05-SECURITY-COMPLIANCE.md` §3.3a (the claim,
the narrowed writable rule, the delivered-work refusal); runbook §1a and §4 and §5; tech-debt (Part A items 2 and 3 closed, new rows for anything found); adhoc log; deploy-log (one additive migration,
**no** worker or env change in C1); resumption template. _Gate:_ full Pest, Pint, PHPStan (all with no path argument, run separately), full web vitest/ESLint/typecheck for the one SPA touch (drawer and
admin error code). **Stop for the C1 review; no push before it.**

### C2 — The hold, the queue read side, the admin read-through (PROD-DATA RISK: LOW to MEDIUM)

**C2-1 — The hold.** `ErasureHold` service: `hold(User $subject, User $admin, string $reason)` and `release(...)`, both locked, idempotent, reason-mandatory, with audit verbs `account.erasure_held` and
`account.erasure_hold_released`; endpoints `POST /admin/compliance/erasure-queue/{user}/hold` and `/release` (platform-admin, MFA gate, form request with the reason); rows in
`docs/security/tenancy.md` §4. A hold needs no request (a suspended person who never asked can be held); the creator is not told and nothing visible changes. _Specs:_ non-admin 403; a reason is
required; hold and release write one audit row each with the admin as actor; a held account fails the gate; **a held creator can still cancel and the hold stays** (Q4); a re-request after a cancel
shows as held; hold on an erased account refused; idempotent. _Break-reverts:_ the hold check removed from the gate; release clearing the cancel columns; the hold cleared by a cancel; the reason not required.

**C2-2 — The queue API.** `AdminComplianceController::erasures` stops being a shell and moves to a real `AdminErasureQueueController` (the shell controller keeps only `exports`); tabs, counts, cursor
pagination, blocker codes, the Erased tab from the ledger only (§3.6). `AdminComplianceShellTest` is rewritten, not deleted. _Specs:_ each tab returns exactly its state (a fixture for every state and
for the precedence cases); a due creator appears under Ready and not Pending; a held due creator under Held; a refusing guard under Blocked with the code; a claimed account under In progress with the
ledger state; **the Erased tab payload contains no name, email, ip or object key** (a key-set pin); non-admin 403; the unknown tab 422; pagination is stable; the query count is bounded (a query-count
spec for a page of 25). _Break-reverts:_ each tab predicate flipped (one mutation per tab); the Erased tab reading `users`; the blockers computed for rows off the page; the precedence order swapped.

**C2-3 — The admin page.** `ErasureQueuePage.vue` becomes the six-tab view with the row table, the hold and release dialogs (mandatory reason, the "no personal details" line), the stuck-state
sentences (P10), empty states, error codes; `compliance.json` ×24 (queue, tabs, columns, states, dialogs, errors), glossary terms ("hold" in UI copy; the word "claim" is avoided: say "Erasing"); the shell
spec replaced. _Specs:_ each tab renders its rows and its empty state; the hold dialog needs a reason; the error code mapping; the stuck sentences; i18n parity ×24; no `meta.shell` path remains.
_Break-reverts:_ the reason not required in the dialog; a tab reading the wrong query; a state sentence missing.

**C2-4 — The admin read-through (§3.7).** The flags on the admin payloads, the reader, the label and chip, the API manifest spec and the SPA static spec, `creator.erased` and the chip text ×24.
_Specs:_ each admin payload for an erased and a pending creator and a normal one; the label in the list, detail, usage and search; the manifest fails on an unflagged resource; the static spec
fails on a bare `display_name`; **an agency payload still never carries the pending state** (the existing agency spec re-run as the negative). _Break-reverts:_ the flag not emitted on one payload (four
mutations); the reader returning the placeholder email; a page bypassing the reader.

**C2-5 — The device-measured alignment fix (apps/main).** The delete sheet's title, checkbox glyph and fields share one left edge **on the phone**. Procedure, in this order, written into the review so
the evidence is the device's: (1) enable WebView inspection in the dev build if it is not enabled (**not verified today**; a Capacitor and Xcode setting), (2) with the phone connected and Web
Inspector on, read `getBoundingClientRect().left` of the three elements on the open sheet and record the numbers, (3) fix, rebuild, reinstall (the `build:native:dev`, `cap sync ios`,
`xcodebuild`, `devicectl` sequence used for the earlier fixes), (4) read the same three numbers again and attach before and after. If inspection cannot be enabled, the fallback is a temporary,
dev-only on-screen readout of the three numbers, removed in the same commit that lands the fix. **Pedram's eyes on the device are the final check**, as with the earlier nit. The jsdom spec asserts only
the structure (the class names and the scoped rule), as it does today, and does not claim to prove a pixel.

**C2-6 — Docs and the gate.** Review file, break-revert log, runbook (the queue, the hold, the states), `05-SECURITY-COMPLIANCE.md`, tenancy allowlist rows, tech-debt, adhoc log, deploy-log (no
migration: it arrived in C1), the C2 walk below. _Gate:_ full Pest, Pint, PHPStan; full admin and main vitest, ESLint and typecheck on **both** apps, i18n parity. **Stop for the C2 review.**

### C3 — Erase (PROD-DATA RISK: HIGHEST; the flag is off at deploy)

**C3-1 — The flag.** `AccountErasureExecuteEnabled` (`account_erasure_execute_enabled`, Pennant, global, default off), added to the admin toggle registry with a description that says "Off: the Erase
button and its endpoint answer 409; the queue, the hold and the command are unaffected", and to `docs/feature-flags.md`. _Specs:_ default off; the toggle writes `feature_flag.toggled` with the reason;
the registry lists it. _Break-reverts:_ the default flipped; the flag missing from the registry.

**C3-2 — The queue plumbing (P1).** Connection `database_erasure` in `config/queue.php` (same table, own `retry_after` above the job timeout, documented in the file beside the existing warning),
`RunErasureJob` (`$connection`, `$queue = 'erasure'`, `$tries = 1`, `$timeout = 3600`, `failed()` leaves the claim), `ErasureRun` untouched. The supervisor program for production is written into
`production-queue-worker.md` §4 and the deploy log: `queue:work database_erasure --queue=erasure --tries=1 --timeout=3600 --max-time=3600 --memory=768`, `numprocs=1`, `stopwaitsecs=3700` so a
deploy's stop waits for the run; and a new runbook rule: **do not deploy or `queue:restart` while an erasure is claimed** (the in-flight run is killed at the stop timeout; every step is idempotent and
the claim persists, but it is avoidable). _Specs:_ the job's connection, queue, tries and timeout are pinned; **`retry_after` exceeds the timeout** (the spec that would have caught P1); the default
worker's queue list excludes `erasure` (a config spec); a job failure leaves the claim and a refusal releases it (P8). _Break-reverts:_ `retry_after` below the timeout (must fail); the job on the
default queue; `tries` raised; a refusal not releasing; an exception releasing.

**C3-3 — The plan and status endpoints.** §3.5 steps 1 and 4, rate limited (a named limiter; the number in the build, a few per minute per admin), read-only (a spec records the queries and hashes the
tables, as the dry-run's own spec does). _Specs:_ the plan equals the command's dry-run for the same subject (counts, digest); a non-admin 403; a refusing subject returns the refusals as codes; a
claimed subject can be re-planned; the limiter answers 429; **no write** of any kind. _Break-reverts:_ the plan writing; the digest differing from the command's; the limiter removed.

**C3-4 — The execute endpoint.** §3.5 step 2, in this order: authorise, flag (409), the 2FA code through the existing challenge and throttle (P11), the typed ulid, the reason, the claim, the job after
commit, 202. Operator recorded in the claim's audit row and, by the job, in the ledger. _Specs:_ every gate refuses with **nothing claimed and nothing queued** (flag off, wrong code, no code, a
throttled admin, wrong ulid, no reason, not due, held, blocked, already erased); a wrong code never feeds an account-lockout counter and respects the throttle; success claims once, queues once on the
right queue **after** the commit (a transaction-rollback spec: a failing claim queues nothing), and returns 202; a second click on a claimed account does not claim or queue a second time (the lock);
the operator is in the ledger and the audit rows; **end to end** (the job run synchronously in the spec): the creator is erased, the agency notices exist, the ledger is `completed`, the claim is not
released; a digest that moved between plan and job releases the claim and audits it; an exception after the files step leaves the claim. _Break-reverts:_ the flag check removed; the 2FA check removed;
the typed-ulid check removed; the claim after the queueing; the queueing inside the transaction; the second-click guard removed; the claim released on a generic exception; the operator not recorded.

**C3-5 — The Erase dialog and the polling (admin SPA).** On a **Ready** row only: the plan view (counts, objects, cost, the number of agency notices, the digest), the three confirmations (typed ulid,
reason, 2FA code) and a danger button; after 202 the row's state and a poll every few seconds showing claimed, `started`, `db_done`, `completed`, `incomplete` ("review the plan and confirm again") or
"the data changed"; the 409, 429 and refusal codes mapped; `compliance.json` ×24. _Specs:_ the button exists only on Ready; disabled until all three confirmations are valid; the plan numbers
shown; each polling state; each error code; the flag-off message; i18n parity. _Break-reverts:_ the button on a non-Ready row; enabled with an empty code; the digest not sent; a state missing.

**C3-6 — Docs, the local walk and the gate.** Review file, break-revert log (with the mutants for the whole chunk), runbook (§1 the operator is the button, §4, the new "Erase button" section, the
worker rule), `05-SECURITY-COMPLIANCE.md`, `feature-flags.md`, tenancy rows, deploy-log (**the new connection, the new supervisor program, the flag; `queue:restart`**), tech-debt, adhoc log. _Gate:_
full Pest, Pint, PHPStan, both apps' web gates. **Stop for the C3 review.**

### C4 — The first real erasure (not code)

Preconditions, each recorded in `deploy-log.md` before the click: (1) C1 to C3 deployed, the `erasure` worker running (`supervisorctl status`), the flag **off**; (2) a database snapshot confirmed
`available`; (3) **read-back E** in hand and reviewed (non-terminal counts, delivered counts, versioning); (4) the bucket's IAM checked for `s3:ListBucketVersions` and `s3:DeleteObjectVersion` if versioned,
and the mirror prefix writable (the runbook §2 list); (5) the **dry-run timed on the snapshot restore** (P9) and the Postgres run of the erasure specs done (tech-debt row 1); (6) **one named account**,
agreed with the person (a test creator of ours, or a real person who asked and whose 14 days ended), its dry-run read in the page: the counts per table are tens or hundreds, `campaign_assignments.cancel`
and the agency notices are as expected; (7) Pedram turns `account_erasure_execute_enabled` on **with a reason**, clicks, and turns it **off** again after. Verification: ledger `completed`, the mirror object
exists, the agency's admins have one notice per assignment, the creator is "Deleted creator" on every agency screen, and the admin's Erased tab shows the ulid and the time. Rollback does not exist:
the path back is a snapshot restore plus `erasure:reapply` (runbook §6).

## 5. The local walks (Pedram's, on the local stack; each is recorded in its review file)

| #     | Chunk | Step                                                                                                             | Pass when                                                                                 |
| ----- | ----- | ---------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| C2-W1 | C2    | Queue page, each tab, with seeded pending / due / held / blocked / claimed / erased creators                     | each lands under the right tab with the right counts                                      |
| C2-W2 | C2    | Hold a due creator, then sign in as that creator and cancel                                                      | hold stays, the row shows "held, no active request"                                       |
| C2-W3 | C2    | Admin list, detail, usage, user search for an erased and a pending creator                                       | "Deleted creator" and the chip, no placeholder email as a name                            |
| C2-W4 | C2    | The delete sheet on the phone: the three left edges, device-measured                                             | same number three times in the Web Inspector, and to your eye                             |
| C3-W1 | C3    | Flag off: open a Ready row's Erase                                                                               | the 409 message, nothing claimed                                                          |
| C3-W2 | C3    | Wrong code, wrong ulid, no reason                                                                                | each refused, nothing claimed                                                             |
| C3-W3 | C3    | Plan, then Erase with the worker **stopped**                                                                     | the row shows claimed and waiting; the creator's sign-in answers as unknown               |
| C3-W4 | C3    | Start the worker                                                                                                 | the page moves to completed; agency notices exist; the label shows                        |
| C3-W5 | C3    | Plan, change the data (an agency action), then Erase                                                             | "the data changed"; claim released; the creator can cancel again                          |
| C3-W6 | C3    | Pending creator with a posted, unverified assignment: agency tries manual verification; then the creator cancels | 422 neutral line; after the cancel the post verifies                                      |
| C3-W7 | C3    | A creator with a `live_verified` assignment requests deletion                                                    | the dialog says delivered work blocks it; with an existing request, the row shows Blocked |

A kill of the worker **mid-run** is covered by the engine's existing `incomplete`/`db_done` specs and the new job specs, not by a manual walk (a local run takes well under a second: there is no window
to kill into, and I will not add a test-only delay to production code).

## 6. Production posture and deploy shape

- **Schema:** three nullable columns on `users` (2,763 rows at read-back B), additive, no backfill; rides the next deploy after C1, snapshot first (the entry already carries migrations).
- **C1 and C2 deploy shape:** a migration, `queue:restart` (the cancellation event and listener, a changed job), no env change, **no flag**, no worker change. The new behaviours are inert until a
  creator has a request and an admin acts.
- **C3 deploy shape:** the `database_erasure` connection (config only), **a new supervisor program**, the new flag **off**, `queue:restart`. A defect in C3 with the flag off changes nothing.
- **Runtime writes by chunk:** C1 none new (the claim is written only by a path no route reaches); C2 the hold (two columns and an audit row); C3 the claim, the ledger and everything the engine already
  writes, only on a click with the flag on.
- **Blast radius of a defect:** the gate wrongly refusing (a Ready creator not erasable: visible in the queue, harmless); the gate wrongly passing (the specs' headline negatives, plus the digest and the
  typed ulid and the dry-run read by a person); the claim wrongly stamping (a creator unable to cancel: the release path and the audit row; bounded to one account at a time by the lock); the Q5 narrowing
  wrongly applied (a verification delayed for one creator and re-run on cancel).
- **Population:** 2,752 creators at read-back B (2026-10-20); with the flag still off, zero requests exist, so the queue is empty on day one.

## 7. Boundaries (what this plan does not do)

No admin-initiated request (AH-104c2). No final mail. No scheduled or automatic erasure of any kind (the specs that forbid a schedule and a route-less command are updated, not weakened: the command
stays unscheduled and the only route to the job is the admin button). No change to the engine's steps, the registry rules for existing tables, or the audit-scrub rules. No audit-snapshot cleanup
(AH-104e). No recovery-code switch on the remaining 2FA pages (tech-debt). No change to `ExportRequestsPage` (still a shell). No second approver.

## 8. Not verified

- Anything new on **Postgres** (the same tech-debt row 1 gap): the queue's range reads and the locked claim. Read-back E's SQL was run on the local container only.
- The **dry-run's duration** and the real run's duration on production-sized data (P9).
- **Whether the dev build's WebView is inspectable** (C2-5).
- That the **`database_erasure` connection with a shared `jobs` table** behaves as I describe under `queue:work` (the table is shared; the queue name separates the workers). It is the documented Laravel
  shape; C3-2's specs pin the config and C3-W3/W4 exercise it locally on the `database` driver, but local dev may run another driver: I will read `QUEUE_CONNECTION` in `.env` before C3 and say what the
  walk actually ran on.
- Whether a **new audit verb needs a retention classification** by a spec (C1-2).
- The **production count of delivered and open assignments** (read-back E).

## 9. Questions for Pedram (each with my recommendation)

1. **C1 — `completed_on_approval` (P4).** It is terminal and payment-eligible; the rider never cancels it, but an erased creator cannot be paid. Refuse erasure while one exists? _Recommend: yes, the guard
   reads `isPaymentEligible()`, which also makes any future payment-eligible state refuse by default. If you rule "exactly the two", the guard names those two and the classification spec still forces a
   decision for every new state._
2. **C2 — the delay (P6).** A pending creator's automatic post verification is skipped and re-run if the request is cancelled; if the account is erased, the `posted` assignment is cancelled by the
   rider. Confirm the delay (as opposed to verifying anyway, which is the hole). _Recommend: confirm._
3. **C3 — the plan endpoint (P9).** Synchronous and rate limited now, becoming a queued job only if the snapshot timing says so. _Recommend: yes._
4. **C4 — "fresh" 2FA (P11).** Accept a TOTP or a recovery code verified at the click (a TOTP has no replay protection in this codebase); I will not describe it as stronger than that. _Recommend: accept._
5. **C5 — the holder column.** `erasure_held_by_user_id` is an admin id, not the subject's data: `KEEP` in the registry (the record of who held) or null it at erasure. _Recommend: `KEEP`; and the hold
   reason lives in the audit row only, with the "no personal details" line._
6. **C6 — release on a refused job (P8).** A digest mismatch or refusal releases the claim and audits it; a failure after the files step keeps it. _Recommend: confirm._
7. **C7 — the subject key.** The user ulid in URLs and the typed confirmation (what the engine, ledger and runbook use), with the creator ulid on the row for the existing detail link. _Recommend: yes._
8. **C8 — no early erasure.** The gate requires `scheduled_for <= now()` in normal mode; only `--force-not-requested` (shell) bypasses "not requested" and "not due". The button never erases before
   the date the creator was promised. _Recommend: yes._
9. **C9 — the flag and the shell.** `account_erasure_execute_enabled` stops the **button and its endpoint** only; the command stays available as the ruled fallback, behind the same gate. _Recommend: yes
   (a kill switch that also kills the fallback would defeat the fallback)._
10. **C10 — the worker rule.** No deploy and no `queue:restart` while an erasure is claimed, as a runbook rule (not enforced in code). _Recommend: yes._
