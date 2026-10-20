# AH-104a — The erasure engine — PLAN (plan-pause)

- **Status:** **Stages 0 to 7 BUILT except 4e; stopped at Checkpoint A (2026-10-20).** Stage 4e (the audit scrub) waits on read-back C, so `erasure:run --execute`
  is **refused in every environment** until it lands (`AuditScrub` is bound to an implementation that reports itself unavailable; fail closed). Stage 8 (runbook,
  deploy-log disposition, privacy-notice inputs) is not started. **Deviation from §5's order:** Stage 6 (payment guard) was committed **before** Stage 5, so there
  is never a commit with the command and without the guard. **Slot kept:** in `ErasureRun` the audit scrub runs **before** the S3 and vendor steps, so 4e drops in
  with no reorder. Local commits are not pushed; the push waits for Pedram's trigger at Checkpoint A.
- **Date:** 2026-10-20
- **Entry:** **AH-104a** (command, dry-run, registry, ledger, restore replay; **no UI, no route, not scheduled**). **AH-104b-del** (request flow + Security
  page), **AH-104c** (admin queue, Erase, hold), **AH-104d** (in-app sign-up) and **AH-104e** (stop writing PII into audit snapshots) are out of scope here.
- **Inputs:** [`account-deletion-pii-map.md`](account-deletion-pii-map.md) (APPROVED, D-A to D-G as written; cited `map §…`),
  [`account-deletion-inventory.md`](account-deletion-inventory.md) (`inv §…`). Anything newly read for this plan is cited inline.
- **Orientation re-read at plan time:** `WORKING-PROCESS.md` (Mode A, §5.35 break-revert, §5.41 CI-green-at-tip, §6 alarm rule), `docs/security/tenancy.md`,
  and the code paths cited below.

---

## 0. The live-data risk line

> **⚠️ PROD-DATA RISK: HIGH for the command this chunk builds; LOW for the build itself. Both are stated, neither is "none".**
>
> **The command (when an operator runs it, not when it is deployed).** It **irreversibly** overwrites identity columns of one `users` and one `creators` row,
> **deletes** the creator-owned rows listed in map §3 (sessions, tokens, push rows, tax/KYC/payout/social/portfolio/availability rows, pivots, receipts, preferences),
> **rewrites** JSON inside rows other people own (`notifications.data`, `audit_logs.before/after/metadata`, `tracked_jobs.result`, `integration_events.payload`,
> `messages.attachments`), **deletes S3 objects**, and may call a vendor. There is **no undo** other than a database restore, which the ledger (§4.6) re-applies.
> It never runs without an explicit subject and, in execute mode, a digest from a fresh dry-run (§4.5).
>
> **The build.** Two **additive** migrations (nullable `erased_at` on `users` and `creators`; one new table) with **no backfill**, so no existing row changes.
> The command has **no route, no schedule and no UI**; nothing in the deployed app can reach it except `php artisan` on a machine that has production credentials.
> The one grant change (D-B, §4.4) is an **infrastructure** act the colleague performs, separately, and is stated again at that stage.
>
> **Not in this chunk:** nothing here erases any real person. The first real erasure is AH-104c's Erase click.

---

## 1. Rulings → where each is baked in

| Ruling                                                                             | What it fixes                                                                                                                   | Where it lands                               |
| ---------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------- |
| Anonymise in place, explicit marker column, no row deletion of anchors             | `erased_at` on `users` and `creators`; login blocked by it; anchors never deleted                                               | Stage 1; Stage 4a/4c                         |
| **D-A** creator-owned satellites are ERASE-ROW                                     | The delete list; each husk fallback kept in the registry in case it is reversed                                                 | Stage 2 (registry), Stage 4b                 |
| **D-B** column-level `UPDATE` on `audit_logs`, one role, one command               | The scrub never goes through the model; arch test that nothing else writes the table                                            | Stage 4e, §4.4                               |
| **D-C** messages: keep text, erase the creator's files; `applications.note` nulled | The attachment scrub and the per-object S3 deletes                                                                              | Stage 4c, 4f                                 |
| **D-D** other parties' decision records kept                                       | Explicit KEEP rows with a reason in the registry (they are the proof the omission was a decision)                               | Stage 2                                      |
| **D-E** tax and KYC: ANON the PII columns, keep the skeleton                       | Sentinels for `NOT NULL` encrypted columns; no migration to make them nullable                                                  | Stage 4b                                     |
| **D-F** no email hash retained                                                     | Nothing is stored that could block re-registration; the admin hold (AH-104c) is the control                                     | §4.6 (the ledger carries no email), AH-104c  |
| **D-G** `post_url` kept                                                            | KEEP row with reason                                                                                                            | Stage 2                                      |
| Erasure ledger, no PII, **re-applied after any restore**                           | A ledger that survives the thing it protects against (§4.6, which found a flaw in the obvious design)                           | Stage 1 (table), Stage 7 (mirror and replay) |
| Registry spec fails CI on any unclassified table or column                         | The registry as code and the schema-reading spec                                                                                | Stage 2                                      |
| `invitation_token_hash` nulled                                                     | One column in the relations step                                                                                                | Stage 4c                                     |
| Failing-by-default spec for any future payment state                               | A refusal in the engine plus a spec that fails when a payment table, model or reachable payment transition appears unclassified | Stage 6                                      |
| Audit logs and `notifications.data` scrubbed, rows kept                            | Predicates S1–S5 and the key lists, verbatim from map §4                                                                        | Stage 4d, 4e                                 |
| AH-104e is its own chunk                                                           | Nothing in this plan changes what the audit trail writes going forward                                                          | §9                                           |

---

## 2. Shape and the cut line

**Stage 0** read-only evidence → **Checkpoint 0** (you read it) → Stage 1 schema → Stage 2 registry and its spec → Stage 3 subject resolver and dry-run →
Stage 4 the erasure steps, one commit each → Stage 5 the command itself (two-phase, resumable) → Stage 6 the payment guard → Stage 7 ledger mirror and
restore replay → **Checkpoint A** (code complete, CI green, a dry-run and an execute proven end to end on a seeded graph, review) → push is your trigger →
Stage 8 docs close. Every commit is conventional, one concern, and leaves the tree green (tests, Pint, PHPStan **with no path argument**, so `tests/` is included).

Dependency edges: 4e **depends on the answer to read-back item C** (the grants). 4f/vendors **depends on read-back A3**. If either answer is bad, the plan says
what changes (see `account-deletion-production-readback.md`, last table), and that stage is re-planned before it starts.

---

## 3. Stage 0 — read-only evidence (no production code, no data)

**0.1 The enumeration script.** One dev-only script, `apps/api/tools/enumerate-audit-actions.php`, using `nikic/php-parser` (already in `vendor/` as a Larastan
dependency; I did not add it to `composer.json`). It parses `app/` and prints, **per `AuditAction` case** (133 today, `AuditAction.php`):

- the case name and its string value, and whether it `requiresReason()` (`AuditAction.php:509` area);
- every call site that logs it (about 149 `Audit::log(` / `AuditLogger::log(` matches in 53 files today, from a count, not yet a read), as `path:line`;
- the **literal keys** passed in `metadata:`, `before:`, `after:`, and whether `reason:` and `actor:`/`subject:` are given;
- **anything dynamic** (a spread, a variable, a method call as the array) flagged "needs a manual read" rather than guessed;
- the six `Audited` models (`rg "use Audited"`) and each `auditableAllowlist()`, by reflection, so the snapshot keys are read from the code, not from the map;
- cases with **no call site** (dead actions), and call sites with **no matching case**.

It writes `docs/reviews/account-deletion-audit-enumeration.md`, generated, with the command and the git SHA at the top. **It is committed**, so the
result is reproducible and Stage 5's guard (§4.7) can reuse the same parser. It reads source only: no database, no network.

**0.2 The production read-back list** is written ([`account-deletion-production-readback.md`](account-deletion-production-readback.md)) and is **for your
colleague now**, not at Stage 0's end: it needs lead time, it is read-only and aggregate-only, and its answers decide Stages 4e and 4f. Sections: A vendor and
driver state, B row counts per affected table, C `audit_logs` grants, D backups / S3 versioning / audit archive, E two questions for you.

### 3.1 Stage 0 result (2026-10-20) — what the enumeration found

Script: `apps/api/tools/enumerate-audit-actions.php` (commit `ffddcdb7`); output: [`account-deletion-audit-enumeration.md`](account-deletion-audit-enumeration.md)
(generated, deterministic, SHA at the top). Static analysis only; **nothing was run against any database**. Corrections to the plan's own numbers: there are **98 audit
call sites** (94 literal, 4 dynamic), not "about 149"; **133 cases**, 86 with a direct site, 30 reached only indirectly, 17 with no reference (one of them, `user.deleted`,
is not dead, see F1); the "41 sites were sampled, not read" caveat is **closed**: all 98 were read by the parser, and the 4 dynamic ones by hand (below).

**F1 — `user.deleted` and the `Audited` trait (needs a ruling: Q10).** The enumeration shows `UserDeleted` / `CreatorDeleted` with no call site, which is what §4.5
relied on ("existing, currently unused"). They are **not** unused: `User` and `Creator` use the `Audited` trait, whose default `auditAction()` derives
`lower(class_basename).".".event`, so the trait **emits `user.deleted` / `creator.deleted` on any Eloquent `delete()`** (with a `before` snapshot that holds `email`, `name`,
`last_name`, `last_login_ip`). Nothing deletes a user today (grep: no `->delete()` / `forceDelete` on either model), so no such row exists yet; but reusing the same
verb for "this account was erased" would make two different events share one name, and the day anything soft-deletes a user the audit trail cannot tell them apart.
**Two consequences, both binding on the build whatever Q10 answers:** (i) the erasure steps use the **query builder, never Eloquent**, on the six audited models (a
model `save()` or `delete()` would emit a `user.updated` / `user.deleted` audit row whose `before`/`after` carry the very PII being erased), and an arch spec asserts it
for `app/Modules/Erasure/`; (ii) the run's own audit row carries counts only.

**F2 — payload keys the map did not name** (map §4.1 amended, see there): `creator.admin.field_updated` carries `metadata.before` / `metadata.after` = the **old and new
value of whichever creator field an admin edited** (so a name, a phone, a bio can sit there), plus `field` and `reason`; `creator.approved` carries
`metadata.welcome_message` (free text, and the live column is already ANON in map §3); `creator.kyc.manually_verified` carries `metadata.note` (free text).
Nothing else in 98 sites carries a free-text or personal value. Reviewed and **not personal** (flags, enums, timestamps, ids, counts): `email_verified_at`, `has_note`,
`tax_form_type`, `tax_id_country`, `tax_profile_complete`, and every other key in the enumeration's section 5.

**F3 — whole-model snapshots.** The direct calls listed in the enumeration's section 3.2 pass `->toArray()`, a variable or a helper as `before`/`after` (brands, talent
pools, agency files, campaigns, automation rules, agency settings), so their keys cannot be read statically. Every one is an **agency-side record**: none has a user or a
creator as its subject, so none falls under S1–S5. The audit-metadata guard (§4.7) therefore asserts on **literal keys only** and carries those sites as an explicit
"snapshot of agency data, not creator data" list keyed by `path:line`, so a new non-literal call cannot be added silently. (`CreatorBlacklisted`'s `$metadata` is
`scope`, `type`, `brand_id`; `BulkInviteCompleted`'s `$stats` is three counters; both read by hand.) The four dynamic-action calls were read by hand: the three `Audited` hooks (F1) and the assignment state machine's `commit()`, whose
`context` keys at every caller are `draft_id`, `version`, `media_count`, `link_count`, `posted_content_id`, `platform`, `platform_post_id`, the two fee fields,
`re_offered_after_decline`, `completes_on_approval`: **no PII**.

**0.3 Exit (Checkpoint 0).** You read the enumeration and the read-back answers. I amend map §4.1's key list **only if the script finds keys it does not name**,
as a docs commit that cites the script output, and the plan's Stage 4e and 4f are confirmed or re-planned. **Stop for your clearance before Stage 1.**
**PROD-DATA RISK for Stage 0: NONE** (source reading; the colleague's queries are `BEGIN READ ONLY`).

---

## 4. The design, by concern

### 4.1 Where it lives

A new module, **`app/Modules/Erasure/`** (service provider, `Services/`, `Registry/`, `Console/`), because the command deliberately reaches across Identity,
Creators, Campaigns, Messaging, Notifications, Audit and TrackedJobs, and putting that reach in any one of them would make that module the owner of the others'
tables. The cross-tenant reads follow the existing precedent for commands that must see every agency: explicit `withoutGlobalScope(BelongsToAgencyScope::class)` per
model, as in `AuditBrandFloor.php:95` and `AuditContactExposure.php:52` (91 `withoutGlobalScope(s)` uses in `app/` today), with **a spec listing every model the engine touches** so
a new tenant-scoped model cannot be silently skipped or silently over-reached. (Question Q4.)

### 4.2 The registry (the map as code)

`ErasureRegistry` is a typed list: for every table, **N/A with a reason**, or per-column `{disposition, reason, sentinel?, husk?}` using the map's seven codes
(ANON, ERASE-ROW, KEEP, SCRUB-JSON, DEL-MATCH, EXPIRE, N/A). **Granularity (Q3):** column-level for every table that holds creator data or a foreign key to
`users` / `creators` (the "affected set": the tables of map §3.1 to §3.6); table-level N/A, with a reason, for the rest. The spec reads the schema from the migrated test database
(`Schema::getTables()` / `getColumns()`) and **fails** when:

1. a table exists that the registry does not name;
2. a column exists on an affected-set table that the registry does not name (a new column forces a decision);
3. a registry entry names a table or column that no longer exists (stale);
4. a **new foreign key to `users` or `creators`** appears on **any** table, including an N/A one;
5. a column on an **N/A** table matches a PII name pattern (`email`, `name`, `phone`, `address`, `ip`, `token`, `dob`/`birth`, `handle`, …) and is not explicitly classified.

Rules 4 and 5 are what stop N/A from becoming a hole. Each failure message names the table or column and says which file to edit. **Proof:** add a column, add a
table, add an FK, rename a column in a scratch migration; each must fail the spec (break-reverts, logged verbatim).

### 4.3 The subject set and the dry-run

`SubjectSet::resolve(User $user)` builds, **before any write**: `user_id`, `creator_id`, `old_email`, the ids of the creator's assignments, applications, relationship
threads, relations, tax profile, payout methods, KYC rows, social accounts, and the vendor ids on them. It is a value object holding no more than the map's §0 lists.
The **dry-run** runs every step in a mode that executes the same selecting queries and writes nothing, and prints **per table and per predicate** the count it would
change, plus the S3 keys it would delete (counts and prefixes, never names) and the vendor calls it would make. **Proofs:** the dry-run's counts **equal** the real
run's counts on the same graph (a spec runs both and compares); the dry-run leaves every table byte-identical (row-hash before and after); both are break-reverted.

### 4.4 The audit scrub and its role (D-B), stated plainly

`audit_logs` is append-only by model (`AuditLog.php:117,127,138`) and, per the spec, by grant (`05-SECURITY:198`). The scrub is the **only** code that updates it,
and it does so with the **raw query builder** (never the model) over a **second database connection** (`audit_scrub`) that logs in as a dedicated role holding
`GRANT UPDATE (ip, user_agent, before, after, metadata) ON audit_logs`. Without that connection configured, **Stage 4e refuses to run** (it does not fall back to the
app role, and it does not skip silently: the command reports "audit scrub not run: no scrub connection" and the ledger row records it, so the run is **incomplete**,
not complete). An **arch spec** asserts that no other file under `app/` issues an `UPDATE` against `audit_logs`. The grant itself is the colleague's act and is
**not** part of any commit; the plan lists the exact statement for them when Stage 4e is reached, after read-back C. Predicates S1–S5 and the key actions are **verbatim map §4.1**.

### 4.5 The command: two-phase, resumable, refuses

`php artisan erasure:run {user_ulid}`

- **Default is dry-run.** It prints the §4.3 report and a **digest** (a hash of the counts, S3 key list and vendor-call list).
- **Execute needs `--execute --confirm-digest=<digest>`**, and the digest is **recomputed fresh at execute time**; a mismatch (the data moved since the dry-run)
  aborts with no change. This is what stops an old approval being run against a new state. (Question Q2: a stricter alternative is a typed confirmation of the ulid.)
- **Refuses** unless the subject is a `creator` user, not already erased, and: **has no assignment in a payment state or with a `payment_id`** (§Stage 6), and **has no
  non-`mock` vendor row** unless the vendor step for that provider exists (§Stage 4g). Refusals exit non-zero with the reason and **write nothing**.
- **Order (so a failure at any point is re-runnable):** resolve the subject set → **vendor calls** (idempotent: "already gone" counts as done) → **S3 deletes** of keys
  captured from the DB (S3 delete is idempotent) → **one database transaction** for every DB step (anonymise, delete, scrub JSON, the audit scrub through its own
  connection immediately after commit, because it cannot share the transaction) → **ledger** `completed`. A failure before the transaction leaves the database
  untouched; a failure after leaves a ledger row in `db_done`, and re-running resumes from there. **Each step is idempotent** (a second run changes nothing).
- **Audit trail of the run:** as planned, it reuses `AuditAction::UserDeleted` and `CreatorDeleted` (`AuditAction.php:54,88`; `UserDeleted` requires a reason, `:509`), with
  `reason = "erasure {ledger ulid}"` and `metadata` holding **only counts**. **Stage 0 amended the premise** ("currently unused"): the `Audited` trait derives these same
  verbs for any Eloquent delete of a `User`/`Creator` (§3.1 F1), so **Q10** asks whether to add one new case instead. Until Q10 is answered the build does not start
  Stage 5. Either way a spec asserts the row carries **no email, name or ip of the subject**, and that an agency actor's row is untouched.
- **Never scheduled, never reachable from HTTP.** A spec asserts the command is absent from `bootstrap/app.php`'s schedule and from every route file.

### 4.6 The erasure ledger, and the flaw in the obvious design

The obvious ledger is a table. **A table in the database cannot protect the database from a restore**: restoring a snapshot taken before an erasure also restores the
ledger as it was before it, so the very record that says "erase this creator again" is gone. So the ledger is **two things**:

1. **`erasure_ledger` table** (operational, queryable, drives AH-104c): `ulid`, `user_ulid` (the pseudonymous key; **not** the id, not the email), `state`
   (`started`, `db_done`, `completed`, `incomplete`), per-step counts (numbers only), `started_at`, `completed_at`, `run_by_user_id` (the operator), `digest`. **No
   email, no name, no ip, no free text.** A spec asserts the column list against an allowlist.
2. **A write-through mirror outside the database**: one small JSON object per **completed** erasure at `erasure-ledger/{user_ulid}.json` on a private prefix, containing
   `user_ulid` and `completed_at` and nothing else. After any restore, **`erasure:reapply --dry-run`** lists mirror entries whose user is **not** erased in the restored
   database and **`--execute`** re-runs the engine for each (same digest rule, same refusals). The mirror's bucket, versioning and Object Lock are **Question Q1**
   and read-back item D.

D-F holds: the ledger stores **no email** (nor a hash of one). The mirror's keys are `users.ulid`, which audit rows and URLs already carry, so it adds no new identifier.

### 4.7 The audit-metadata guard (permanent, from Stage 0's parser)

A spec reuses the Stage 0 parser to assert: **every literal key passed in `metadata:`, `before:` or `after:` at any audit call site is either in the scrub key list
(map §4.1) or in an explicit "not personal" list with a reason.** A future call site that writes `metadata: ['email' => …]` fails CI until someone decides. This
is the permanent form of "41 sites were sampled, not read" and it is what lets AH-104e later shrink the list rather than guess it.

---

## 5. Sub-steps

| #   | Sub-step                                                                                                                                                                                                                                     | Green when                                                                                       |
| --- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| 0   | Enumeration script + generated doc; read-back list to the colleague. **Read-only.**                                                                                                                                                          | Script runs from a clean checkout; its output is committed; **Checkpoint 0**                     |
| 1   | Migrations: `users.erased_at`, `creators.erased_at` (nullable timestamp, no default, no backfill, no index); `erasure_ledger`. Models, factories. Login blocked when `erased_at` is set (Identity policy; **security-relevant, stop-gate**). | Migrate up and down on an empty and a seeded DB; login refused for an erased user; Pint, PHPStan |
| 2   | `ErasureRegistry` + the schema spec (§4.2) + the audit-metadata guard (§4.7). **No behaviour.**                                                                                                                                              | Registry classifies all 57 tables; the five failure modes each proven by a break-revert          |
| 3   | `SubjectSet` + the dry-run reporter + the "models touched" spec. **Writes nothing.**                                                                                                                                                         | Counts equal across dry-run and (later) real run; byte-identical tables                          |
| 4a  | Identity and tokens: `users` and `creators` ANON; `sessions`, `personal_access_tokens`, `user_push_tokens`, `push_deliveries`, preferences, `password_reset_tokens` (by old email), `features`, `failed_jobs` (by old email). Marker set.    | Per-column spec from the registry; the second run changes nothing                                |
| 4b  | Satellites: tax and KYC ANON (sentinels), payout, social, portfolio, availability ERASE-ROW; pivots and receipts; `campaign_job_notifications`, `talent_pool_creators`.                                                                      | As above; the agency-held rows around them are untouched (negative specs)                        |
| 4c  | Agency-held rows: `agency_creator_relations.invitation_token_hash` and expiry nulled; `campaign_applications.note` nulled; `messages` / `relationship_messages` attachments JSON scrubbed (the creator's only); anchors **never** deleted.   | Anchors count identical before and after; an agency's own rows byte-identical                    |
| 4d  | JSON scrubs that need no role: `notifications` (three populations, keys `creator_name`, `sender_name`, `counterparty_name`), `tracked_jobs.result.failures[].email`, `integration_events.payload`.                                           | Push copy still renders with a scrubbed name (the `PushMessageFactory` fallback); a spec per key |
| 4e  | **The audit scrub** (S1–S5, §4.4), through the dedicated connection; refuses without it. **Depends on read-back C.**                                                                                                                         | Each predicate proven; `ip` only where the actor is the subject; ids untouched; arch spec passes |
| 4f  | Files: per-object S3 deletes through the disk abstraction (avatar, cover, portfolio, thumbnails, the creator's message attachments, **versions too if read-back D says versioned**); never by shared prefix.                                 | Fake disk records exactly the expected keys and no others; shared-prefix negative                |
| 4g  | Vendors: **refuse** on any non-`mock` provider row (fail closed). If read-back A3 shows a live vendor, this sub-step becomes the vendor-side deletes behind the provider contracts (a conscious edit of the pinned contract-surface spec).   | Refusal proven by a break-revert; or the vendor step with its own dry-run line                   |
| 5   | `erasure:run` (dry-run default, digest, refusals, order, resumability), the ledger writes, the audit row.                                                                                                                                    | End-to-end on a seeded graph: dry-run, execute, re-execute, kill-mid-run then resume             |
| 6   | The payment guard (§ below): engine refusal + the failing-by-default spec.                                                                                                                                                                   | Refusal and spec both break-reverted                                                             |
| 7   | The ledger mirror and `erasure:reapply`.                                                                                                                                                                                                     | Restore simulated: wipe the erased state in a copy, replay re-erases it                          |
| 8   | Docs: runbook `docs/runbooks/account-erasure.md`, adhoc entry, deploy-log disposition, tech-debt rows, Resumption, privacy-notice inputs for AH-105.                                                                                         | Verbatim read by you before the push                                                             |

**Stage 6 detail (the payment guard).** Payments are not built (`app/Modules/Payments/` has directories and no Sprint 10 code; `payment_held` and `payment_released`
exist as `AssignmentStatus` cases, `AssignmentStatus.php:87`). The guard has two halves: **(a)** the engine refuses a subject with an assignment in either state or a
non-null `payment_id`; **(b)** a spec that **fails** if a table named like `payment*`, `payout*` or `escrow*` appears in the schema without a registry entry that carries an
explicit "payment policy" field, or if the set of `AssignmentStatus` cases whose name contains `payment` changes without the registry's `knownPaymentStates` being updated.
Sprint 10 therefore cannot make erasure quietly unsafe. Break-reverts: remove the refusal; add a `payments` table in a scratch migration; add a payment case.

---

## 6. Proof expected (break-revert, §5.35)

Every decision gets a mutation that must fail a named spec, logged verbatim with the spec name and the failing assertion, and the harness reads the **run's exit code**
as well as the assertion (a standing lesson from AH-103b). The list the build will carry, minimum:

- **Registry:** unclassified table, unclassified column, stale entry, new FK to `users`, PII-named column on an N/A table (five).
- **Dry-run:** writes something; counts differ from the real run; skips a step the real run performs (three).
- **Identity:** `email` left; `name` left; unique placeholder collides; `erased_at` not set; login allowed after erasure; a second run changes a row (six).
- **Satellites and anchors:** an anchor deleted; an agency's row touched; a D-D column scrubbed; the D-A husk path used by mistake (four).
- **JSON scrubs:** a population skipped (three, one per `notifications` population); `ip` nulled on an agency-actor row; a non-subject's `before`/`after` key removed;
  `metadata.email` left on a no-actor failed login; the scrub falling back to the app connection (five).
- **Command:** execute without digest; stale digest accepted; refusal bypassed (payment, vendor, non-creator, already erased); DB transaction not atomic (a failure
  mid-way leaves a partial state); the command found in the schedule or a route (six).
- **Ledger:** a PII column added to `erasure_ledger`; mirror not written; replay skips an entry (three).
- **Audit row of the run:** carries the subject's email; reason missing (two).

Gate list at each checkpoint: Pest for the touched modules, **Pint**, **PHPStan with no path argument**, and, once SPA-visible, nothing (no SPA change in this chunk).

---

## 7. Standards applied

- **Mode A**; test-first for each destructive step: the **negative** (what must not change) is written before the positive.
- **No `Model::query()->update()` on `audit_logs`** outside Stage 4e's scrub, arch-spec enforced. **No new `AuditAction` case.** No i18n key, no route, no resource shape, no
  policy change **except** the login block in Stage 1 (stop-gate: it is security-relevant and states exactly which guard it edits).
- **Tenancy:** every cross-agency read is explicit and enumerated (§4.1); `docs/security/tenancy.md` §4's table does not grow a row because there is no route.
- **No PII in logs, exceptions or command output:** the dry-run prints counts, table names and S3 key counts, never a name, email or object name; a spec captures the
  output of a seeded run and asserts the seeded email and name are absent.
- **Sentinels, not English:** `users.name` and the `NOT NULL` encrypted columns get the fixed sentinel `[erased]`; a spec asserts **no API response** emits it (the agency
  resources emit an `is_erased` flag in AH-104b-del/c, not a label).
- Conventional commits, kebab-case scope, header ≤100, body lines ≤100, a blank line before the body.

---

## 8. Questions for review

1. **Q1 — Where does the ledger mirror live?** I propose a private `erasure-ledger/` prefix on the **media bucket** (no new bucket), written with the app's existing disk, keys
   = `users.ulid`. Needs: the app role may `PutObject` there, nothing may delete it from the app, and ideally **Object Lock or versioning** so it cannot be wiped by the same
   mistake that needs a restore. That is infrastructure for the colleague (read-back D asks the versioning question). The alternative (a separate bucket with Object Lock) is
   cleaner and costs an infra ticket. Which?
2. **Q2 — Execute confirmation.** The digest rule (§4.5) stops stale approvals. Do you also want a **typed confirmation** of the subject's ulid (an operator cannot paste a
   digest and a wrong ulid at once)? I recommend **both**; it is a few lines and it is the last gate before an irreversible act.
3. **Q3 — Registry granularity.** Column-level for the affected set, table-level N/A plus the FK and name-pattern rules for the rest (§4.2). The strict alternative
   (every column of all 57 tables: 568 declared columns in the schema dump, before the implicit `id` and timestamp columns) is noisy and I do not think it buys more than rules 4 and 5. Confirm, or ask for strict.
4. **Q4 — Module placement.** A new `Erasure` module (§4.1) vs inside `Identity`. I recommend the new module for the ownership reason above.
5. **Q5 — Vendors.** Fail closed on any non-`mock` provider row in 104a and build the vendor-side deletes only if read-back A3 shows one is live (§Stage 4g). This avoids
   extending the pinned provider contracts (KYC 4, e-sign 4, payment 2 methods) speculatively. Confirm, or ask for the contract extension regardless.
6. **Q6 — Sentinel.** `[erased]` for `users.name` and the `NOT NULL` encrypted columns, `erased-{ulid}@erased.invalid` for email (`.invalid` is reserved, RFC 6761, so it can never
   receive mail). The alternative for `name` is the empty string. I prefer `[erased]` because the "no API response emits it" spec then catches a leak; an empty string leaks silently.
7. **Q7 — Request-time lock and revoke** belongs to **AH-104b-del** (it is the request flow), not here. The command still deletes tokens, sessions and push rows
   (idempotent), so an admin-initiated erasure with no prior request is also safe. Confirm that split.
8. **Q8 — Deploy.** The two migrations ride the **one pending combined deploy** (after AH-105, with build 3); the command is dormant until AH-104c. Stack the obligation on
   the open `PENDING` deploy-log entry, or hold AH-104a's push until the deploy window? I recommend stack and push, because nothing is armed.
9. **Q9 — Who can run it.** Read-back item E2. Until the answer, the runbook says "operator named by Pedram, from a machine with production access, never from a laptop
   without the dry-run output attached."

### 8.1 Rulings (2026-10-20, Pedram) and the question Stage 0 added

| Q                          | Ruling                                                                                                                                                                                                                                                       |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Q1**                     | Mirror on the **media bucket**, private prefix `erasure-ledger/`, **versioning on**. **Object Lock: ask the colleague (nice-to-have, not a blocker).** Goes to read-back D.                                                                                  |
| **Q2**                     | **Both**: the fresh digest **and** a typed confirmation of the subject's ulid.                                                                                                                                                                               |
| **Q3–Q7**                  | As proposed (column-level registry for the affected set; new `Erasure` module; fail closed on non-`mock` vendors; `[erased]` and `erased-{ulid}@erased.invalid` sentinels; request-time lock and revoke belongs to AH-104b-del).                             |
| **Q8**                     | **Stack the obligation and push.** Pushes still happen on Pedram's trigger.                                                                                                                                                                                  |
| **Q9**                     | The operator is **the super admin (Pedram) through the AH-104c Erase button**. A manual `php artisan erasure:run` on the server is a **fallback only**, with the dry-run output attached to the request. The runbook says exactly this.                      |
| **Read-back A (vendors)**  | **No real external service is enabled in production; every provider is `mock`.** Stage 4g stays **fail-closed** and **no vendor-side deletes are built**. (Re-open only if a non-mock provider is ever enabled; the engine refuses that subject until then.) |
| **Read-back E (operator)** | As Q9.                                                                                                                                                                                                                                                       |
| **Read-back B, C, D**      | **Pending** (Pedram's colleague; Pedram forwards the output). **C gates Stage 4e**; D gates the S3-version decision in 4f and the Object Lock note in Q1.                                                                                                    |

**Q10 — NEW from Stage 0 (needs your ruling before Stage 5; does not block Stage 1).** The run's audit row: **(a)** reuse `user.deleted` / `creator.deleted` as ruled
(zero enum change, but the same verb the `Audited` trait emits for a real model delete, §3.1 F1), or **(b)** add **one** case, `account.erased` (reason required, one row
per run, `subject = the user`), so "erased" and "deleted" can never be confused. **I recommend (b):** it is one enum line plus the doc table in `05-SECURITY-COMPLIANCE`, and
it keeps the audit trail honest for the one event a regulator or the person themselves may ask about. Stage 5 waits for the answer.

---

## 9. Not in this chunk (so nothing is built twice)

The request flow, re-auth and its rate limiter, the 14-day clock, the "Ready to erase" view, the legal hold and the Erase button (AH-104b-del, AH-104c); the agency
`is_erased` flag on ~20 resources and its i18n label (AH-104b-del/c); in-app sign-up (AH-104d); **stopping new PII going into audit snapshots** (AH-104e, after this
chunk; the §4.7 guard is what makes it safe to do); the audit retention job and the Glacier archive the spec describes; vendor-side deletes unless read-back A3 forces them;
the privacy notice text (AH-105 consumes the runbook's retention table).

## 10. Deploy obligations (to be written into the deploy log at Stage 8)

Two additive migrations (names recorded at build), **no backfill**, **no flag, no env**, **no scheduler change**, no worker restart needed for the code. One infra
dependency that is **not** a deploy step: the scrub role and connection (Stage 4e) and the mirror prefix (Stage 7), both done by the colleague and both verified by a
dry-run on a **non-production copy first**. Pre-deploy reads: none beyond the read-back already collected. Post-deploy verification: `/up`, and `php artisan list | grep
erasure` shows the commands and `php artisan erasure:run <ulid>` with no `--execute` on a **test creator** prints a report and changes nothing.
