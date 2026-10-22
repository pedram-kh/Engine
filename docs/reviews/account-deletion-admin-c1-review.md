# AH-104c C1 — review (the gate: claim, delivered-work refusal, pending-creator verification, impersonation refusal)

**Status:** C1 built locally as a feat + docs commit pair, **not pushed**. The plan is pushed (`d4d47571..8f55fc32`). This review is the stop Pedram asked for: nothing below is pushed or deployed.
Plan: [`account-deletion-admin-plan.md`](account-deletion-admin-plan.md) §4 C1. Break-revert log, verbatim: [`account-deletion-admin-c1-break-reverts.md`](account-deletion-admin-c1-break-reverts.md).

**PROD-DATA RISK: MEDIUM at deploy.** One additive migration on `users` (three nullable columns, no default, no index, no backfill, no data write; 2,763 rows at read-back B). **No route reaches the claim**: it is
written only by `erasure:run --execute` (never scheduled), so deploying C1 erases and claims nothing. Four narrow behaviours reach everyone (the claim window's sign-in branch, the pending-creator verification
hold, the manual-verify guard, the impersonation refusal); each is inert while no creator has a request, a claim or an erasure, and each has an "ordinary creator is unchanged" spec. The risk event is the first real
`--execute` or the C3 click, not this deploy. Deploy shape: migration, `queue:restart`, no env, no flag, no worker change (deploy-log entry added).

## 1. What was built

| Step | What                                                                                                                                                                                                                                                                                                 |
| ---- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| C1-1 | Migration `2026_10_22_100000_add_erasure_claim_and_hold_to_users`; `User` casts and `isErasureClaimed()`, `isErasureHeld()`, `isLeavingOrErased()`; the three columns classified in `ErasureRegistry` (`KEEP`, the FK declared in `refs`); `UserFactory` states `erasureClaimed()`, `erasureHeld()`. |
| C1-2 | `ErasureClaimer` (`claim`, `reclaim`, `resume`, `release`, `requestGates`), `ErasureClaim`, `ClaimMode`; two audit verbs; `ErasureRun::execute()` takes the claim and re-reads it; `ErasureReapply` goes through `reclaim()`.                                                                        |
| C1-3 | Sign-in answers a claimed account as an unknown email before the password check (audit reason `unknown_email`); `cancel()` returns false and `request()` is "not available" for a claimed account.                                                                                                   |
| C1-4 | `DeliveredWorkGuard` in `Refusals`, `ErasureEligibility::DELIVERED_WORK`, the rider's selection and re-read; one new key ×24 (`account_deletion.blockers.delivered_work`); a total classification spec of `AssignmentStatus`.                                                                        |
| C1-5 | Manual verify refuses a pending or erased creator (422 `creator.unavailable`); `VerifyPostedContentJob` returns before the provider call; `AccountDeletionCancelled` (after commit, only by the call that cancelled) re-runs the held-back checks; the drawer maps the code.                         |
| C1-6 | `erasure:run`: dry-run prints `NOT READY:` verdicts and `Claimed: yes`, exits 1 when an execute would be refused; `--execute` claims through the claimer; `--force-not-requested` with `--operator` and `--reason`; a refusal before the run began releases the claim.                               |
| C1-7 | `ImpersonationService::start()` and `claim()` refuse a pending, claimed or erased target (422); the admin message ×24.                                                                                                                                                                               |
| C1-8 | This file, the break-revert log, the request plan's AH-104c notes, `05-SECURITY-COMPLIANCE.md` §3.3a, runbook §1, §1a, §4, §5, tech-debt, adhoc log, deploy log, resumption template.                                                                                                                |

## 2. The ten rulings, and the spec that holds each

| Ruling                                                                                         | Held by                                                                                                                                                                                                                                                                                    |
| ---------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Q1 `completed_on_approval` refuses too, via the `isPaymentEligible()` predicate                | `ErasureDeliveredWorkGuardTest`: "is the set of payment-eligible states, read from the predicate"; "refuses every payment-eligible assignment state"; "classifies every assignment state" (total over `AssignmentStatus`). Break-reverts: states emptied, `completed_on_approval` dropped. |
| Q2 delay the automatic post check for a pending creator                                        | `PendingCreatorVerificationTest`: "holds back the automatic check ... the provider is not called, nothing is saved, the post stays pending"; "re-runs the held-back check when the creator cancels".                                                                                       |
| Q3 plan endpoint synchronous, rate-limited                                                     | **Not C1** (C2).                                                                                                                                                                                                                                                                           |
| Q4 fresh 2FA means verified at the click                                                       | **Not C1** (C3).                                                                                                                                                                                                                                                                           |
| Q5 the hold columns are `KEEP`; the reason lives in the audit row only                         | `ErasureRegistrySchemaTest` (classifies every column and FK; fails on the omissions I mutated); the "no personal details" line is in the runbook §1 and the command's `--reason` help text.                                                                                                |
| Q6 digest mismatch or refusal releases the claim; a failure after the files step keeps it      | `ErasureCommandTest`: "hands the account back when the run is refused before it began"; the failure-keeps-claim assertions; `ErasureClaimerTest` `release()` refuses once any ledger row exists.                                                                                           |
| Q7 user ulid is the subject key                                                                | `ErasureClaim::$userUlid`; `ErasureRun::execute()` checks it equals the user's; `ErasureClaimerTest`: "refuses a claim that belongs to another account, and one that was released after it was issued".                                                                                    |
| Q8 no early erasure; only the shell `--force-not-requested` bypasses "not requested"/"not due" | `ErasureClaimerTest`: the gate legs, "passes at the instant the grace ends and not a second before"; "forces an account that never asked"; force never passes a hold or a guard. `ErasureCommandTest` for the flag.                                                                        |
| Q9 the flag gates the button only; the shell stays the fallback                                | **Not C1** (C3). Nothing in C1 reads a flag for the erasure.                                                                                                                                                                                                                               |
| Q10 no deploy or `queue:restart` while an erasure is claimed                                   | A runbook rule (§1) and the deploy-log entry, with the read to run (`erasure_started_at is not null and erased_at is null`).                                                                                                                                                               |

## 3. Behaviour to read before you clear C1

- **The gate, in order.** subject resolves → not held → (normal) a request stands and `deletion_scheduled_for` is not in the future → no refusal. Then stamp and audit. A refusal throws and **writes nothing**:
  this holds because the claim is one transaction (removing the transaction _and_ stamping first is caught by 12 specs; see the break-revert log's equivalent-mutant note).
- **The claim is returned again, not re-decided.** A second `claim()` on an account already claimed returns the claim with no new audit row. `resume()` makes no gate decision and only refuses an unclaimed account.
- **What the creator sees from the claim:** sign-in answers as for an unknown address, before the password check, with the audit reason `unknown_email`; `cancel()` is false; a new request is "not available".
- **`release()` is guarded by the ledger.** It returns false when any `ErasureLedger` row exists for the user, because a ledger row is written before the first irreversible step. So a run that got as far as
  deleting files can never hand the account back to the creator.
- **What `Refusals` now says.** Payment, then delivered work (`campaign_assignments: N assignment(s) with delivered work (live_verified, manually_verified, completed_on_approval)`), then vendor: a table, a count and
  the states; never a person.
- **One behaviour change for ordinary creators that is not obvious:** a creator whose only assignments are `completed_on_approval` could be erased before and is now refused (Q1). The rider no longer cancels any
  payment-eligible assignment, and re-reads under the lock to skip one that became payment-eligible after the selection.
- **The error codes.** Impersonation: `admin.impersonation.target_unavailable` (see §5, a deviation from the plan's name). Pending-creator manual verify: `creator.unavailable` (the existing neutral code).

## 4. Gates

Each run on its own, exit code read, at the final C1 state (working tree; nothing committed when these ran).

| Gate                           | Command (from the package directory)                                                                                        | Exit | Result                                                                                        |
| ------------------------------ | --------------------------------------------------------------------------------------------------------------------------- | ---- | --------------------------------------------------------------------------------------------- |
| API tests, whole suite, serial | `SANCTUM_STATEFUL_DOMAINS='localhost,localhost:5173,127.0.0.1,127.0.0.1:5173,127.0.0.1:5174' php vendor/bin/pest` (no path) | 0    | 3916 deprecated, 10 passed, **0 failed**; 20,144 assertions (the local PDO notice, as before) |
| API style                      | `php vendor/bin/pint --test` (no path)                                                                                      | 0    | `passed`                                                                                      |
| API static analysis            | `php vendor/bin/phpstan analyse --memory-limit=2G` (no path)                                                                | 0    | `[OK] No errors`                                                                              |
| Main app tests                 | `pnpm test` (`vitest run`)                                                                                                  | 0    | 263 files, 3169 tests passed                                                                  |
| Admin app tests                | `pnpm test`                                                                                                                 | 0    | 62 files, 540 tests passed                                                                    |
| API client tests               | `npx vitest run`                                                                                                            | 0    | 9 files, 228 tests passed                                                                     |
| Lint                           | `pnpm lint` (ESLint) in `apps/main` and `apps/admin`                                                                        | 0, 0 | clean                                                                                         |
| Types                          | `pnpm typecheck` (`vue-tsc --noEmit`) in `apps/main` and `apps/admin`                                                       | 0, 0 | clean                                                                                         |
| i18n parity                    | `node scripts/i18n/verify-locale.mjs <the 23 non-en locales>`                                                               | 0    | 23 PASS (main + admin against `en`)                                                           |

The new specs: `ErasureClaimerTest` 27, `ErasureClaimGuardTest` 5, `ErasureClaimSchemaTest` 7, `ErasureClaimSignInTest` 10, `ErasureDeliveredWorkGuardTest` 20, `PendingCreatorVerificationTest` 12,
`AdminImpersonationLeavingAccountTest` 7, `ErasureCommandTest` 24 (extended); three Vitest cases (the drawer, the Security dialog's delivered-work line, the admin message).

**Found by the gates, fixed (not by me beforehand):**

- The first full Pest run failed `AgencyCreatorSurfacesTest`: the new listener was not classified in the manifest, and the job and the resolution controller were still listed as "unchanged". Reclassified
  (`silent`, `guarded`, `via-relation`, each with its reason).
- The first full Vitest run failed `i18n-account-deletion-codes.spec.ts` (its non-vacuity line pinned the reasons as `payment, vendor`; now `delivered_work, payment, vendor`) and **my own drawer spec** (it loaded only
  `app.json`, so the text was the raw key). Both fixed.
- PHPStan reported 19 errors in my specs and one nullsafe in the command (the repo's gate covers `tests/`). Fixed without ignores.
- Two main-app specs timed out once (5 s) on the first, cold run of a loaded machine and passed on the next two (`SecurityPage.spec.ts` alone, then the full suite). Not reproduced; noted, not explained.

## 5. Deviations from the plan, and what I decided

1. **22 `execute()` call sites in 5 spec files, not ~44 in 6.** The plan's estimate was a count from the inventory; all were moved to one helper (`Tests\Support\ErasureClaims::for`).
2. **`ErasureClaim` has a public constructor.** The plan said only the claimer constructs one; PHP has no "friend", so an architecture scan (`ErasureClaimGuardTest`) is the guarantee, with the spec helper the one
   sanctioned test-side exception.
3. **`reclaim()` for `erasure:reapply`** (not in the plan). A restore that brings a person back must be erased again with no request, grace or hold: that decision was made and carried out once. `reclaim()`
   requires a ledger-mirror entry for the ulid, asks the guards, and audits with its own reason and mode `reapply`. Without it the engine's new signature made the reapply path uncallable.
4. **`release()` refuses once any ledger row exists** (Q6 gave the rule; the ledger check is how it is enforced).
5. **The rider's selection also excludes `PaymentGuard::knownPaymentStates()`** (plan: delivered states only). The payment states were already refused as a whole account, so this is belt-and-braces and costs one line.
6. **Impersonation code is `admin.impersonation.target_unavailable`, not the plan's `impersonation.target_unavailable`.** Every existing code in that family is `admin.impersonation.*` and the admin SPA
   resolves the code as a locale key; the plan name would have broken the family. Same 422.
7. **A source scan stands in for the lock** (sqlite cannot show one). Recorded as tech-debt "AH-104b-del Part A" item 4.
8. **A real regression I introduced and caught:** my first manual-verify guard used `loadMissing('creator:id,ulid,display_name,erased_at')`, which stripped `creator->user` and made the manual-verify mail
   listener lose its recipient. `CampaignAssignmentResolutionTest` caught it; the guard now reads a separate full `Creator::find`.
9. **Not in the plan: the manifest rows** (above) and `AccountDeletionCancelled` as an Identity event (the listener lives in Campaigns, so Identity does not import Campaigns).

## 6. Not verified, and the new tech-debt

- **Postgres.** Nothing in C1 has run on Postgres: the claim's locked reads, the migration's FK with `nullOnDelete`, the new registry classification. (Tech-debt item 4; the same slot as the queue's range reads.)
- **A force-claimed creator that never asked is not hidden from agencies during the run** (tech-debt item 5, new): `Creator::isUnavailableToAgencies()` reads `deletion_requested_at` only. Bounded to a run's
  minutes and to the shell's `--force-not-requested`. The creator's own sign-in is refused from the claim. Say if you want it closed in C2 (one more condition in the scope and its single-row twin, plus the
  manifest spec's matrix).
- **An existing session or token of a force-claimed creator** is not revoked at the claim (a normal request already revoked them all). They end at the erasure, which deletes `sessions` and tokens. Same bound.
- **No device or browser walk** was run for C1 (there is no new screen); the C2 plan carries the walks.
- **`reclaim()` reads the ledger mirror (`entries()`)**; the spec covers "no record" and "a guard refuses", not a mirror that is unreadable. I have not shown what `entries()` does when the disk errors
  (it would propagate the exception from `erasure:reapply`, which has no claim to leave behind at that point), and I did not read it further.

## 7. Questions for Pedram

None block C2. Three for the review, each with my recommendation:

1. **Close the force-claim visibility gap in C2?** _Recommend: yes_ (one condition and its manifest row); it removes tech-debt item 5.
2. **Revoke the sessions and tokens of a force-claimed creator at the claim (not only at the erasure)?** _Recommend: yes_, in the same transaction as the stamp, through `CredentialRevoker` (the one list); it makes
   "claimed" mean the same thing in both modes.
3. **Accept deviation 6 (the `admin.impersonation.*` code name)?** _Recommend: yes._
