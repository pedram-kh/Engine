# AH-104c C1 — break-revert log (verbatim)

Harness output for every C1 break-revert, taken from the runs in this session with the terminal colour codes removed and the output trimmed to the exit code and the first failing spec names. Each mutation is
applied to the working tree, the named specs are run (Pest for the API, Vitest for the web), the harness reads the run's **exit code** (non-zero means the mutation was CAUGHT) and restores the file; after
every block it compares the file with its pre-mutation content (`restored: identical`). The harness and the mutation lists are throwaway scripts under `/tmp`; **they are not in the repository, and this file
is the record.** A mutation whose old string did not occur exactly once aborts (it never runs unchanged and reports "caught").

Counts: **48 distinct mutations** (C1-1 4, C1-2 12, C1-3 4, C1-4 7, C1-5 9, C1-6 7, C1-7 5). **Survived, or caught for the wrong reason, on the first run: 5** (below), all closed:

| Mutation                                                   | Why it survived                                                                                                                                                                                    | Closed by                                                                                                                                                                                           |
| ---------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| C1-2 the row lock removed from `claim()`                   | sqlite's `lockForUpdate()` does nothing, so no behavioural spec can see it. It was never caught by a behavioural spec; it is caught by the **source scan** added for it (3 locks, 3 transactions). | `ErasureClaimGuardTest`: "takes the user row lock in every method that stamps or clears the claim". Not provable on a second connection here (tech-debt "AH-104b-del Part A" item 4).               |
| C1-2 the stamp written before the checks                   | An **equivalent mutant** while the claim is inside one transaction: the refusal throws and the stamp rolls back. It is not a hole in the specs.                                                    | Shown by the next two rows: removing the transaction alone fails the scan; removing it **and** stamping first fails 12 behavioural specs ("refuses, and writes nothing, on every leg of the gate"). |
| C1-4 the rider's re-read ignoring payment-eligible states  | The race (an assignment verified between the selection and the re-read) was not under test.                                                                                                        | `ErasureCancelOpenAssignmentsTest`: "skips an assignment that was verified after the selection" (a listener flips the second assignment mid-run).                                                   |
| C1-5 `AccountDeletionCancelled` dispatched on every cancel | The "second cancel dispatches nothing" assertion was **vacuous**: Pennant had already resolved the flag as off, so the listener returned for the wrong reason.                                     | The spec now flushes the flag cache and asserts the flag is on before the second cancel.                                                                                                            |
| C1-5 the drawer mapping removed                            | Not a survivor but a wrong-reason catch: the first run failed because the spec loaded only `app.json`, so `creator.unavailable` had no text even with the mapping in place.                        | The spec loads `creator.json` too; the rerun fails for the right reason.                                                                                                                            |

## C1-1 — schema, model, registry (API)

```
[C1-1 KEEP rule omits erasure_held_at] BROKEN exit=1 Tests: 12 deprecated, 1 failed
    caught by: ErasureRegistrySchemaTest > it classifies every table, column and foreign key of the migrated s…
[C1-1 FK not declared in refs] BROKEN exit=1 Tests: 12 deprecated, 1 failed
    caught by: ErasureRegistrySchemaTest > it classifies every table, column and foreign key of the migrated s…
[C1-1 cast removed (started_at)] BROKEN exit=1 Tests: 33 deprecated, 1 failed
    caught by: ErasureClaimSchemaTest > it casts the two timestamps to dates and keeps the holder as an adm…
[C1-1 nullOnDelete removed] BROKEN exit=2 Tests: 6 deprecated, 1 failed
    caught by: ErasureClaimSchemaTest > it lets a holder be deleted without losing the account that was hel…
(each: restored: identical)
```

(The registry's own failing-by-default guard also failed on the first build, before the FK was declared: that is the C1-1 "column left out" proof in the wild.)

## C1-2 — the claimer, the gate (API)

Specs: `ErasureClaimerTest`, `ErasureClaimGuardTest`.

```
[C1-2 lock removed (claim)] BROKEN exit=1 Tests: 31 deprecated, 1 failed
    caught by: ErasureClaimGuardTest > it takes the user row lock in every method that stamps or clears th…   (the scan, see the table)
[C1-2 hold check removed] BROKEN exit=1 Tests: 27 deprecated, 5 failed
    caught by: ErasureClaimerTest > it refuses, and writes nothing, on every leg of the gate with ('hel…
[C1-2 due check flipped] BROKEN exit=2 Tests: 20 deprecated, 12 failed
    caught by: ErasureClaimerTest > it claims a request whose grace has ended: stamps the account, writ…
[C1-2 due check removed] BROKEN exit=1 Tests: 27 deprecated, 5 failed
    caught by: ErasureClaimerTest > it passes at the instant the grace ends and not a second before (th…
[C1-2 request check removed] BROKEN exit=1 Tests: 29 deprecated, 3 failed
    caught by: ErasureClaimerTest > it refuses, and writes nothing, on every leg of the gate with ('nev…
[C1-2 refusals not asked in claim] BROKEN exit=1 Tests: 28 deprecated, 4 failed
    caught by: ErasureClaimerTest > it refuses, and writes nothing, on every leg of the gate with ('pay…
[C1-2 already-claimed early return removed] BROKEN exit=1 Tests: 31 deprecated, 1 failed
    caught by: ErasureClaimerTest > it is idempotent: a second claim returns a claim for the same accou…
[C1-2 audit row not written (claim)] BROKEN exit=2 Tests: 29 deprecated, 3 failed
    caught by: ErasureClaimerTest > it claims a request whose grace has ended: stamps the account, writ…
[C1-2 stamp written BEFORE the checks] BROKEN exit=0 Tests: 32 deprecated (123 assertions)
    !!! NOT CAUGHT                                    (equivalent mutant: the transaction rolls the stamp back; see the table)
[C1-2 force without operator allowed] BROKEN exit=1 Tests: 31 deprecated, 1 failed
    caught by: ErasureClaimerTest > it requires a reason, and an operator to force
[C1-2 claim() transaction removed] BROKEN exit=1 Tests: 31 deprecated, 1 failed
    caught by: ErasureClaimGuardTest > it takes the user row lock in every method that stamps or clears th…
[C1-2 stamp before checks, with the transaction also removed] BROKEN exit=1 Tests: 15 deprecated, 12 failed
    caught by: ErasureClaimerTest > it refuses, and writes nothing, on every leg of the gate with ('hel…
(each: restored: identical)
```

The plan also listed "the re-read after the lock removed". The re-read **is** the locked read (`lockForUpdate()->firstOrFail()` replaces the caller's stale model), so the lock mutation above is the re-read
mutation; the stale-model spec ("re-reads the row under the lock: a stale model cannot claim a request that was cancelled, held or rescheduled meanwhile") fails under the hold, due and request mutations as listed.

## C1-3 — the claim window (API)

Specs: `ErasureClaimSignInTest`, `ErasureClaimerTest`.

```
[C1-3 claimed check moved after password check] BROKEN exit=1 Tests: 7 deprecated, 3 failed
    caught by: ErasureClaimSignInTest > it refuses the SPA sign-in of a claimed account, with the right pas…
[C1-3 claimed audit reason differs] BROKEN exit=1 Tests: 9 deprecated, 1 failed
    caught by: ErasureClaimSignInTest > it records the refusal under the same audit reason as an unknown em…
[C1-3 cancel() restores a claimed account] BROKEN exit=1 Tests: 35 deprecated, 2 failed
    caught by: ErasureClaimSignInTest > it answers the cancel itself as an unknown account when the claim l…
    caught by: ErasureClaimerTest > it ends the creator's right to cancel: a cancel that follows the cl…
[C1-3 request() accepts a claimed account] BROKEN exit=1 Tests: 9 deprecated, 1 failed
    caught by: ErasureClaimSignInTest > it refuses a new deletion request from a claimed account as not ava…
(each: restored: identical)
```

## C1-4 — the delivered-work refusal (API, then the strings)

Specs: `ErasureDeliveredWorkGuardTest`, `AccountDeletionRequestTest`, `ErasureCancelOpenAssignmentsTest`, `ErasureSelectionsCoverageTest`, `ErasureClaimerTest`.

```
[C1-4 guard states emptied] BROKEN exit=1 Tests: 54 deprecated, 12 failed
    caught by: ErasureDeliveredWorkGuardTest > it refuses every payment-eligible assignment state, naming the tabl…
[C1-4 guard drops completed_on_approval] BROKEN exit=1 Tests: 62 deprecated, 4 failed
    caught by: ErasureDeliveredWorkGuardTest > it is the set of payment-eligible states, read from the predicate a…
[C1-4 guard not wired into Refusals] BROKEN exit=1 Tests: 90 deprecated, 3 failed
    caught by: ErasureClaimerTest > it refuses, and writes nothing, on every leg of the gate with ('del…
[C1-4 guard not wired into eligibility] BROKEN exit=1 Tests: 61 deprecated, 5 failed
    caught by: AccountDeletionRequestTest > it refuses up front while the creator has delivered work, in a word…
[C1-4 rider selection not excluding delivered] BROKEN exit=1 Tests: 64 deprecated, 2 failed
    caught by: ErasureDeliveredWorkGuardTest > it leaves delivered work out of the rider's selection, and the dry-…
[C1-4 cancel re-read ignores payment-eligible] BROKEN exit=0 Tests: 66 deprecated (667 assertions)
    !!! NOT CAUGHT                                    (closed by the next run)
[C1-4 cancel re-read ignores payment-eligible (retry, after the spec)] BROKEN exit=1 Tests: 12 deprecated, 1 failed
    caught by: ErasureCancelOpenAssignmentsTest > it skips an assignment that was verified after the selection: deliv…
[C1-4 en locale line missing (main), Vitest SecurityPage.spec.ts] BROKEN exit=1
    caught by: Security page (web) > blocked (422): a delivered-work reason gets its own line, not the generic one (AH-104c)
    caught by: Security page (native) > blocked (422): a delivered-work reason gets its own line, not the generic one (AH-104c)
(each: restored: identical)
```

The key missing in **one other** locale is held by two checks, both green and neither mutated: `i18n-account-deletion-codes.spec.ts` (harvests the reasons from `ErasureEligibility` and asserts copy in every
`UI_LOCALES`; its non-vacuity line now lists `delivered_work`) and `node scripts/i18n/verify-locale.mjs <23 locales>` (exit 0, 23 PASS).

## C1-5 — Q5 at the agency end (API, then the drawer)

Specs: `PendingCreatorVerificationTest`, `CampaignAssignmentResolutionTest`, `VerifyPostedContentJobTest` (plus `ErasureClaimerTest` and `AccountDeletionRequestTest` for the event).

```
[C1-5 controller check removed] BROKEN exit=1 Tests: 26 deprecated, 3 failed
    caught by: PendingCreatorVerificationTest > it refuses an agency's manual verification of a pending creator wit…
[C1-5 job check removed] BROKEN exit=2 Tests: 25 deprecated, 4 failed
    caught by: PendingCreatorVerificationTest > it holds back the automatic check for a pending creator: the provid…
[C1-5 check applied to every creator (job)] BROKEN exit=1 Tests: 23 deprecated, 6 failed
    caught by: PendingCreatorVerificationTest > it still verifies an ordinary creator automatically (the over-reach negative)
[C1-5 check applied to every creator (controller)] BROKEN exit=1 Tests: 26 deprecated, 3 failed
    caught by: PendingCreatorVerificationTest > it still verifies an ordinary creator by hand (the over-reach negative)
[C1-5 listener unregistered] BROKEN exit=1 Tests: 27 deprecated, 2 failed
    caught by: PendingCreatorVerificationTest > it re-runs the held-back check when the creator cancels, and the assignment then verifies
[C1-5 listener ignores the flag] BROKEN exit=1 Tests: 28 deprecated, 1 failed
    caught by: PendingCreatorVerificationTest > it dispatches nothing when the verification flag is off, and nothin…
[C1-5 listener re-runs non-pending too] BROKEN exit=1 Tests: 28 deprecated, 1 failed
    caught by: PendingCreatorVerificationTest > it dispatches the check for each posted assignment whose latest pos…
[C1-5 event dispatched for every cancel() call] BROKEN exit=0 Tests: 84 deprecated (423 assertions)
    !!! NOT CAUGHT                                    (vacuous assertion; closed by the next run)
[C1-5 event dispatched for every cancel() call (retry, after the spec)] BROKEN exit=1 Tests: 11 deprecated, 1 failed
    caught by: PendingCreatorVerificationTest > it dispatches nothing when the flag is off, and nothin…
[C1-5 drawer mapping removed, Vitest ResolveVerificationDrawer.spec.ts (rerun after the fixture fix)] BROKEN exit=1
    caught by: ResolveVerificationDrawer > says the creator is no longer available when the server refuses with creator.una…
(each: restored: identical)
```

"The job saving the outcome before checking" is not a separate mutation: the check is a `return` placed before the provider call, so there is no outcome to save before it; the spec asserts nothing was
saved (the post stays `pending`) and the provider spy was not called, and "job check removed" fails exactly those.

## C1-6 — `erasure:run` asks the same gate (API)

Specs: `ErasureCommandTest` (and `ErasureClaimGuardTest` for the bypass).

```
[C1-6 command builds its own claim (bypass)] BROKEN exit=2 Tests: 22 deprecated, 7 failed
    caught by: ErasureCommandTest > it executes only with the digest and the typed ulid, and then erases…
[C1-6 force flag ignored] BROKEN exit=1 Tests: 23 deprecated, 1 failed
    caught by: ErasureCommandTest > it forces an account that never asked: needs --operator and --reaso…
[C1-6 force without operator/reason allowed] BROKEN exit=1 Tests: 23 deprecated, 1 failed
    caught by: ErasureCommandTest > it forces an account that never asked: needs --operator and --reaso…
[C1-6 verdict line missing] BROKEN exit=1 Tests: 21 deprecated, 3 failed
    caught by: ErasureCommandTest > it prints the gate's verdict in the dry-run, with the date when the…
[C1-6 dry-run exits 0 despite gate failures] BROKEN exit=1 Tests: 21 deprecated, 3 failed
    caught by: ErasureCommandTest > it prints the gate's verdict in the dry-run, with the date when the…
[C1-6 no release on refusal] BROKEN exit=1 Tests: 23 deprecated, 1 failed
    caught by: ErasureCommandTest > it hands the account back when the run is refused before it began,…
[C1-6 digest check after the claim (claim first)] BROKEN exit=1 Tests: 23 deprecated, 1 failed
    caught by: ErasureCommandTest > it refuses an execute without both confirmations, with a wrong dige…
(each: restored: identical)
```

"Force skipping the hold" is the C1-2 "hold check removed" mutation (the command asks `ErasureClaimer::requestGates`, one method for every mode); it fails "forces ... still refuses a held account" there.

## C1-7 — the impersonation refusal (API, then the admin message)

Specs: `AdminImpersonationLeavingAccountTest`.

```
[C1-7 check removed from start()] BROKEN exit=1 Tests: 3 deprecated, 4 failed
    caught by: AdminImpersonationLeavingAccountTest > it refuses to start an impersonation of an account that asked to be…
[C1-7 check removed from claim()] BROKEN exit=1 Tests: 6 deprecated, 1 failed
    caught by: AdminImpersonationLeavingAccountTest > it refuses to CLAIM a hand-off that was started before the account…
[C1-7 check applied to every target (start)] BROKEN exit=1 Tests: 5 deprecated, 2 failed
    caught by: AdminImpersonationLeavingAccountTest > it still impersonates an ordinary creator, and an agency user (the…
[C1-7 claimed not counted as leaving] BROKEN exit=1 Tests: 5 deprecated, 2 failed
    caught by: AdminImpersonationLeavingAccountTest > it refuses to start an impersonation of an account that asked to be…
[C1-7 en locale line missing (admin), Vitest UserSearchPage.spec.ts] BROKEN exit=1
    caught by: UserSearchPage > says in words that a leaving account cannot be entered (AH-104c), not the raw error code
(each: restored: identical)
```

## Gates at the final C1 state

See the review file §4. Each run on its own, exit code read.
