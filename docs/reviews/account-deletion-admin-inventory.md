# AH-104c inventory — the admin erasure queue, the hold, the Erase button, the delivered-work refusal, the erased read-through

**Mode A, step 1 (inventory). Read-only: nothing here changes code, schema or data.** Written 2026-10-22, after the AH-104b-del walk. Next: Pedram's rulings on §7, then the plan.

**PROD-DATA RISK for the chunk: HIGHEST in the project.** The Erase button is the first thing that can irreversibly delete a real person's data in production (rows, S3 objects and versions, audit snapshots),
and it cancels real assignments and notifies real agency users. Hard stop-gates: schema (a hold column), a new route set, a policy/authorisation rule, a state-machine-adjacent guard, a queue/supervisor change,
i18n ×24 (admin and main), a new audit-verb family, and an action on real production data. Everything before the Erase click can be built and walked on the local stack.

---

## 1. What exists today (facts, with where)

| Thing                     | State                                                                                                                                                                                                                                        | Where                                                                                   |
| ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| Admin erasure queue (API) | A **shell**: `GET /admin/compliance/erasure-queue` answers `data: []`, `meta.shell: true`. No table behind it (the S14 `data_erasure_requests` table was never built and is not needed: see F1).                                             | `Admin/Http/Controllers/AdminComplianceController.php`, `Admin/Routes/api.php`          |
| Admin erasure queue (SPA) | A finished empty page: route `/compliance/erasure-queue`, `ErasureQueuePage.vue`, three columns (`subject_email`, status, requested), i18n in `compliance.json` ×24, a spec and `AdminComplianceShellTest` that pin the shell.               | `apps/admin/src/modules/compliance/`                                                    |
| The request               | `users.deletion_requested_at`, `users.deletion_scheduled_for` (**indexed**), set by the creator; cleared by signing in again; kept on the row after erasure (registry `KEEP`).                                                               | migration `2026_10_21_110000`, `AccountDeletionService`, `ErasureRegistry.php:119`      |
| The engine                | `ErasureRun::plan()` (dry-run, digest) and `execute($user, $digest, $typedUlid, $operator)`; ledger `started → db_done → completed` (or `incomplete`); 2-hour per-subject lock; refusals asked twice.                                        | `Erasure/Run/ErasureRun.php`, `Console/ErasureRunCommand.php`                           |
| What the engine refuses   | `PaymentGuard` (`payment_held`, `payment_released`, a `payment_id`) and `VendorGuard` (a non-mock provider). `PaymentPolicy` is the arch spec that fails CI when a new payment state appears.                                                | `Erasure/Guards/*`                                                                      |
| The request-time refusal  | `ErasureEligibility::blockers()` returns `payment` and `vendor`; `AccountDeletionService` calls it after the password and 2FA; the dialog maps each code to `account_deletion.blockers.{code}` (fallback `.other`).                          | `Erasure/Eligibility/ErasureEligibility.php`, `DeleteAccountDialog.vue:136`             |
| The rider                 | `CancelOpenAssignments` cancels every non-terminal assignment inside the DB transaction, including `live_verified` and `manually_verified` (to be changed, §3.4).                                                                            | `Erasure/Cancellation/CancelOpenAssignments.php`                                        |
| The agency read-through   | `Creator::scopeVisibleToAgencies()`, `is_erased` on resources via `EmitsErasedState`, `creatorLabel()` as the single web reader, an arch spec (`AgencyCreatorSurfaces`) holding the resource manifest.                                       | `Creators/Concerns/EmitsErasedState.php`, `apps/main/src/core/creators/creatorLabel.ts` |
| Admin flag/audit pattern  | Reason-mandatory writes with one audit verb each (`feature-flags`, `automation-rules`, agency suspend, 2FA reset); a reason dialog in the SPA; platform-admin gate plus `EnsureMfaForAdmins`; an allowlist in `docs/security/tenancy.md` §4. | `AdminFeatureFlagController`, `FeatureFlagsPage.vue`                                    |

## 2. Findings (each is something the plan must answer)

**F1. The queue needs no new table.** The state is already in the data: pending = `users.deletion_requested_at` not null, due = `deletion_scheduled_for <= now()`, erased = `erased_at`, history =
`erasure_ledger` (ulid, state, digest, counts, no name). A `data_erasure_requests` table would be a second copy that can disagree with the user row. Recommend: the queue is a **view** with one new
column (the hold, F6), as the first inventory recommended (`account-deletion-inventory.md` §9).

**F2. The engine does not know what "pending", "due" or "held" mean.** `ErasureRun::execute` erases any creator who is not already erased, given the right digest and typed ulid: it never reads
`deletion_requested_at`. That was right for an operator tool, and wrong for a button. The Erase action therefore needs its own gate in front of the engine: **pending, due, not held, not claimed (F3)**,
asked inside the same call that starts the run. `erasure:run` on the server stays the engine-level fallback; whether it should ask the same gate is a question (§7, Q9).

**F3. The cancel-versus-erase race is wider than tech-debt row 2 says.** Row 2 (and the AH-104c note) says "re-read `deletion_requested_at` with the row locked before erasing". But the run's order is
audit scrub, **then the S3 objects (irreversible)**, **then** the database transaction. A lock taken inside the database stage comes after the files are already gone, so a creator who cancels between
the click and the transaction would be restored with an erased photo, portfolio and attachments, and a scrubbed audit trail. And a run that fails after the files step (`incomplete`) leaves the account
pending, so the creator can still sign in and cancel into a damaged account. Recommend a **claim**: one short transaction that locks the user row, re-reads (pending, due, not held), and stamps
`users.erasure_started_at`; from that instant `AccountDeletionService::cancel()` and sign-in treat the account as erased (invalid credentials, as for an erased account), and the engine proceeds. A failed
run stays claimed and is retried by an admin, never reopened to the creator. This changes the ruled "cancel until `erased_at`" to "cancel until an admin's Erase claims the account" (Q3).

**F4. The erasure cannot run in a web request or on the default queue.** The audit scrub reads all of `audit_logs` once (38 MB at the read-back), the file step makes S3 calls (versions too), and the
lock is 2 hours. Horizon's only supervisor has `timeout 60`, `tries 1`; the database queue's `retry_after` is 300 and the longest job today is 240 s. A request timeout kills PHP mid-run (the ledger would
read `incomplete`; every step is idempotent, but a run killed after the files step is exactly F3). Recommend: the click **queues one job on a dedicated `erasure` queue and supervisor** (timeout above the
longest plausible run, `tries 1`, one process), answers 202 with the ledger id, and the page polls the ledger. This is a **deploy obligation** (Horizon config, `horizon:terminate`, the supervisor in
the prod environment block) and goes in `deploy-log.md`.

**F5. The delivered-work refusal has more touchpoints, and one hole.**

- Touchpoints: a third guard beside `PaymentGuard` returning refusals for `live_verified` and `manually_verified` (so `Refusals::for`, the dry-run `REFUSED:` line, `execute` and the button all see it);
  a new `ErasureEligibility` code (say `delivered_work`) and its dialog copy `account_deletion.blockers.delivered_work` ×24; the `PaymentPolicy`-style arch spec for the new list (CI fails if a
  state is added that the guard has not classified); `CancelOpenAssignments` must **stop** cancelling those two states (it still does); the registry text and the runbook §4 and §5 tables.
- **The hole:** the request-time check and the Erase-time check are two instants. A hidden, pending creator's assignments stay writable by the agency (ruled: silently), so an agency can verify a post during
  the 14 days. The request was accepted and is now unerasable: the creator is hidden, locked out except to cancel, and the queue shows a refusal. It needs a defined end state (Q5): stays pending with
  the refusal shown to the admin, or the creator is told, or the request is cancelled by the admin with a reason.
- Production exposure is **unknown**: the read-back counted 709 assignments with 0 in a payment state, but not the non-terminal or the `live_verified`/`manually_verified` count.

**F6. There is no hold anywhere.** Not a column, not a verb, not a mention in the engine. The earlier inventories require the "Ready to erase" predicate and the hold to read the same column.
Two details matter: (a) a **new column on `users` fails the registry spec until classified** (the spec is doing its job); (b) a hold's **free-text reason survives erasure**: `audit_logs.reason` is kept
("reasons and decision notes stay", runbook §7), so a reason that names the person is retained. Recommend the column holds only `erasure_held_at` and `erasure_held_by_user_id`, the reason lives in the audit
row, the dialog says "do not put personal details in the reason", and the audit scrub's existing rules apply as they do to every reason.

**F7. The admin app has no erased or pending awareness at all.** Today an erased creator appears in the admin list, detail, creator-app usage and user search as `display_name: null` and
`erased-{ulid}@erased.invalid`; there is no `is_erased` key, no pending badge, no link to the ledger (`AdminCreatorController::index` returns `display_name` and `email` bare;
`AdminCreatorAppController` selects `creators.display_name` and `users.email`; impersonation search lists `name` and `email`). Nothing leaks (the values are the anonymised ones), but a person reading the
admin cannot tell "erased" from "no name yet". The agency pass is guarded by an architecture spec; **the admin pass has none**.

**F8. Impersonation can open a session as a pending or an erased creator.** `ImpersonationService::start()` refuses only self and platform admins; `claim()` calls `guard('web')->login($target)` with no
check, and nothing in the cookie path reads `isDeletionPending()` or `isErased()` (only `AuthService` and `CreatorTokenGuard` do). _Read from the code, not run._ It is admin-only, reason-mandatory and
audited, so the severity is low-to-moderate, but it defeats "a pending account is locked" and "an erased account cannot sign in". Small fix (refuse both in `start()` and again in `claim()`), best done in
this chunk because the queue will make pending creators one click from the impersonation search.

**F9. A hold and a cancellation must be independent.** The creator's cancel clears the two request columns. The hold is its own column, so a held request that is cancelled stays marked held, and the
admin sees "held, no active request". Without that, a suspended or blacklisted person (the case the hold exists for, PII map D-F) could cancel and re-request to shed it. Whether a held creator may cancel
at all is a question (Q4).

**F10. The first real run notifies real people.** The rider cancels each open assignment and sends one in-app notice per assignment to each affected agency's admins and managers. The production counts
by state are not known. The first real erasure must be a named, agreed account with the dry-run counts read first, and a **read-back (E)** of non-terminal assignment counts by state is needed before the plan.

**F11. The admin-initiated path was promised and not specified.** The request plan said a suspended creator cannot request and "the admin-initiated path covers them (AH-104c)" (Q11). No design for it
exists. Without it a suspended creator has no way to be erased except an operator shell command. It is also the path for a person who emails support (the public `/delete-account` page tells them to).

**F12. Nothing tells the person it happened.** The confirmation mail goes out at request; at erasure the address is destroyed and no mail is sent. Whether a final "your account has been erased" mail
(sent before the address is anonymised, to the old address, one line) is wanted is a question; it would be a new mail to a real user ×24 locales.

## 3. What I would build (a design for rulings, not a decision)

### 3.1 The queue (read side)

`GET /admin/compliance/erasure-queue` stops being a shell. It reads `users` joined to `creators` (pending), with the ledger for history. Tabs or filters: **Pending** (grace running), **Ready to erase**
(`deletion_scheduled_for <= now()`, not held, not claimed), **Held**, **Blocked** (a guard refuses: the guard's coarse code, not its sentence), **In progress / failed** (ledger `started`/`incomplete`/`db_done`),
**Erased** (ledger `completed`, ulid and date only: no name exists to show). Rows show: name and email (the admin may see them while the account exists), requested date, due date, days left, hold state,
blocker codes. Cursor-paginated like the audit viewer; the index on `deletion_scheduled_for` already exists. `meta.shell` goes away and the shell tests are rewritten.

### 3.2 The hold

`users.erasure_held_at` (nullable) and `erasure_held_by_user_id` (nullable, FK). Two admin writes, both **reason-mandatory and audited**: `POST .../{creator}/hold` and `.../release`, new audit verbs
(`account.erasure_held`, `account.erasure_hold_released`). The ready predicate and the Erase gate both read the column. The creator is not told and nothing visible changes for them or the agencies.

### 3.3 The Erase action

Two steps, as the engine already works: **(1) Plan:** the page asks the API for the dry-run for that creator (counts per table, objects, cost, refusals, the digest, `campaign_assignments.cancel` and
the number of agency notices that will go out). **(2) Execute:** the admin types the creator's ulid and a reason; the API (a) re-checks the gate and **claims** (F3) in one locked transaction,
(b) queues the job on the `erasure` queue (F4), (c) returns 202 with the ledger id; the job calls `ErasureRun::execute` with the digest from step 1 (a changed digest refuses, as today), the admin as
`operator`. The page polls the ledger and shows `started`, `db_done`, `completed`, or `incomplete` with "retry" (the same endpoint; the run is idempotent). The `account.erased` audit row already carries the
operator. Confirmation is typed ulid + reason; a password or 2FA re-entry for this one action is a question (Q8).

### 3.4 The delivered-work refusal, both halves

Engine half: the guard, the refusals line, the arch spec, and `CancelOpenAssignments` no longer touching `live_verified`/`manually_verified`. Request half: the new eligibility code, the dialog copy ×24
(machine draft, es/en human pass as before), and the end state for a request made hidden before the work was delivered (F5, Q5).

### 3.5 The erased read-through

Two parts. **Admin pass:** an `is_erased` key (and a `deletion_pending` marker, admin only) on the admin creator list, detail, creator-app usage and user-search payloads, one SPA reader for the label
(the admin twin of `creatorLabel()`), and an arch spec holding the admin manifest, as the agency side has. **History:** the "Erased" tab reads only the ledger; no page may join to the anonymised row for a
name. The agency side is unchanged and needs only a regression run once a real erased creator exists (W11/W12 were walked on a seeded one).

### 3.6 Also in this chunk (from the walk and the earlier reviews)

- **The delete-sheet checkbox alignment, with a device-measured check (Pedram, 2026-10-22).** The browser measurement was wrong for the phone. The fix is not to be accepted on a browser number: measure the
  left edges of the title, the checkbox glyph and the fields **in the app's WebView on the phone** (Safari Web Inspector against the dev build: `getBoundingClientRect()` of the three, or a screenshot
  overlay), then pin them in a spec only as far as a jsdom spec can. Whether the dev build's WebView is inspectable (`isInspectable`/Capacitor debugging) is **not verified**; if it is not, the fallback is a
  temporary on-screen readout in a dev-only build. Both are named here so the plan carries the step.
- **The impersonation refusal** (F8).
- **Second-factor recovery-code switch on the other pages** (tech-debt, opened 2026-10-22): not this chunk unless you want it here (Q12).
- The two first-run deploy checks already in the runbook: a Postgres run of the erasure specs (tech-debt row 1) and the pre-deploy snapshot.

## 4. Schema, registry, audit, i18n, deploy

- **Schema (additive, nullable, no backfill):** `users.erasure_held_at`, `erasure_held_by_user_id`, `erasure_started_at`. Each classified in `ErasureRegistry` in the same commit (the registry spec fails CI otherwise).
  Likely `KEEP` for the timestamps (not identifying once the rest is gone), `NULL` for the holder.
- **Audit verbs (new):** `account.erasure_held`, `account.erasure_hold_released`, `account.erasure_started` (the claim). Each needs its retention class (`AuditAction.php` classifies verbs; I did not check whether a spec forces it for new ones).
- **API:** one controller (`AdminErasureController`: list, show/plan, hold, release, erase, ledger status), form requests with the mandatory reason, the platform-admin gate and `EnsureMfaForAdmins`, rows added to
  `docs/security/tenancy.md` §4.
- **Queue:** the dedicated `erasure` queue and supervisor (F4); `deploy-log.md` entry; `horizon:terminate` on deploy.
- **i18n:** admin `compliance.json` ×24 for the queue, tabs, plan, confirm dialog, states and errors; main `creator.json` ×24 for the one new blocker line. Glossary: "legal hold", "claim" avoided
  in UI copy (say "Erase", "Held").
- **No new flag is proposed:** the queue reads data that exists, the hold is inert until used, and the Erase click is behind admin MFA, a typed ulid and a reason. (If you want a kill switch for Erase alone,
  one admin-toggled flag is the existing pattern: Q10.)

## 5. Size and split (proposal)

Four sub-chunks, each ending green and reviewed, in this order so nothing irreversible is reachable early:

1. **C1: guards and gates (API only).** The delivered-work guard and request half, the claim, the Erase gate service, `CancelOpenAssignments` change, the impersonation refusal. No route yet.
2. **C2: hold, queue read side and admin read-through.** Column and registry, the two hold endpoints, the queue API and page (read-only plus hold/release), the admin `is_erased`/pending pass and spec.
3. **C3: Erase.** Plan endpoint, execute endpoint, the queue and supervisor, the polling page, the confirm dialog. **Walked on the local stack on a seeded creator**, including a killed worker mid-run and a cancel racing the click.
4. **C4: the first real erasure.** A named act, not code: snapshot first, dry-run counts read, the account agreed, flag-free but behind Pedram's own click. Recorded in `deploy-log.md`.

Plus the device-measured alignment fix (3.6) alongside C2 (it is mobile-only and small).

## 6. Not verified

- Anything on **Postgres** for the new queries (the same tech-debt row 1 gap); the queue's "ready" query is a plain indexed range read and I know of no dialect issue.
- **The production shape**: counts of non-terminal and delivered assignments, whether the bucket is versioned (read-back D left it open), how long a real run takes (the dry-run `Cost` line gives rows read,
  not time; a real timing is only available from a snapshot rehearsal).
- **Impersonation (F8)** was read, not run.
- **Whether the dev WebView is inspectable** for the alignment measurement.
- The **Horizon change** has not been tried; the supervisor block is read from `config/horizon.php`.

## 7. Questions for Pedram (each with my recommendation)

1. **No new table (F1):** the queue is a view over `users` + the ledger, plus the hold column. _Recommend: yes._
2. **Who may press Erase:** any `platform_admin`, or only you? There is one admin role today. _Recommend: platform admins (it is the one role), with the operator recorded in the ledger and audit; revisit
   when a second admin exists._
3. **The claim (F3):** accept that from the moment an admin presses Erase the creator can no longer cancel by signing in (it answers as an unknown account), instead of "cancel until `erased_at`". _Recommend:
   yes; the alternative leaves a window in which the files are gone and the account can still be restored._
4. **Hold and the creator:** may a held creator still cancel by signing in (the request is withdrawn; the hold stays on the row, F9)? _Recommend: yes, and the hold stays, so a re-request is visibly held._
5. **Delivered work after the request (F5):** the request is accepted, then an agency verifies a post. Options: (a) it stays pending, the queue shows "Blocked: delivered work", the admin decides (hold or
   wait for settlement); (b) the creator is also told; (c) the admin may cancel the request with a reason. _Recommend (a) now; (b) needs a new mail and a ruling on telling a locked-out person. Also:
   should `live_verified` assignments for a **pending** creator be blocked at the agency's end instead (no new verification while pending)? That removes the hole but changes the "assignments stay writable"
   ruling: your call._
6. **Admin-initiated requests (F11):** in this chunk, later, or never (operator shell for suspended creators)? _Recommend: later (AH-104c2), after the creator-initiated path has erased a real account; until then
   the shell fallback with the dry-run attached._
7. **The final mail (F12):** send one line to the old address before the address is anonymised, or nothing. _Recommend: nothing in v1 (the confirmation at request said what would happen); note it in the
   privacy notice._
8. **Re-authentication for the Erase click:** typed ulid + reason (recommended minimum), plus the admin's password or a fresh 2FA code? _Recommend: add a fresh 2FA code (the admin is MFA-enrolled by
   rule), because this is the one irreversible button; it costs one field._
9. **Should `erasure:run --execute` ask the same gate (pending, due, not held, claimed)?** _Recommend: yes, with an explicit `--force-not-requested` to erase a creator who never asked (legal order), which
   writes a distinct audit reason. Otherwise the shell can bypass the hold._
10. **A kill switch for Erase alone** (an admin-toggled flag, default off until the first agreed account)? _Recommend: yes, one flag, `account_erasure_execute_enabled`, off at deploy; the queue and hold work
    without it._
11. **The `erasure` queue/supervisor (F4):** accept the dedicated supervisor with a long timeout and one process, as a deploy obligation. _Recommend: yes._
12. **Scope of this chunk:** C1 to C3 plus the alignment fix and the impersonation refusal, with the recovery-switch on the remaining 2FA pages left in tech-debt. _Recommend: as stated._
13. **Read-back E before the plan:** non-terminal assignments by state, and the `live_verified`/`manually_verified` count, in production, read-only. Who runs it (the earlier read-backs went through you)?

## 8. Rulings (Pedram, 2026-10-22) and where each goes

| Q   | Ruling                                                                                                                                                                                                                                                              | Goes to      |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
| 1   | No new table: the queue is a view over `users` plus the ledger, plus the hold column.                                                                                                                                                                               | C2           |
| 2   | Any platform admin may press Erase; the operator is recorded in the ledger and the audit row.                                                                                                                                                                       | C3           |
| 3   | **Yes, the claim.** From the admin's Erase the creator can no longer cancel by signing in. This **supersedes "cancel until `erased_at`" with "cancel until the claim"**; recorded in the request plan, the Part A review and tech-debt.                             | C1           |
| 4   | A held creator may still cancel; the hold stays on the row.                                                                                                                                                                                                         | C2           |
| 5   | (a) The request stays pending and the queue shows "Blocked: delivered work"; **plus** no new live verification for a pending creator: refused at the agency end with 422 `creator.unavailable`. The ruling "assignments stay writable" is **narrowed** accordingly. | C1           |
| 6   | Admin-initiated requests later (AH-104c2).                                                                                                                                                                                                                          | out of scope |
| 7   | No final mail in v1.                                                                                                                                                                                                                                                | out of scope |
| 8   | Typed ulid + reason + a **fresh 2FA code** for the Erase click.                                                                                                                                                                                                     | C3           |
| 9   | `erasure:run --execute` asks the same gate; `--force-not-requested` erases a creator who never asked, with its own audit reason.                                                                                                                                    | C1           |
| 10  | One kill switch for Erase: `account_erasure_execute_enabled`, off at deploy.                                                                                                                                                                                        | C3           |
| 11  | The dedicated `erasure` queue and supervisor with a long timeout, as a deploy obligation.                                                                                                                                                                           | C3           |
| 12  | Scope: C1 to C3, the device-measured alignment fix and the impersonation refusal; the recovery switch on the other 2FA pages stays in tech-debt.                                                                                                                    | the plan     |
| 13  | The read-only read-back (E) for the colleague: [`account-deletion-production-readback-e.md`](account-deletion-production-readback-e.md).                                                                                                                            | before C4    |

Next: [`account-deletion-admin-plan.md`](account-deletion-admin-plan.md).
