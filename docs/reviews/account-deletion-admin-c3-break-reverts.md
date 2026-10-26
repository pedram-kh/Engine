# AH-104c C3 — break-revert log (verbatim)

Harness output for every C3 break-revert, with the terminal colour codes removed. Each mutation is applied to the working tree, the named specs are run (Pest for the API, Vitest for the web), the
harness reads the run's **exit code** (non-zero means the mutation was CAUGHT) and restores the file; after every block it compares the file with its pre-mutation content (`restored: identical`).
The harness and the mutation lists are throwaway scripts under `/tmp`; **they are not in the repository, and this file is the record.** A mutation whose old string did not occur exactly once aborts
(it never runs unchanged and reports "caught").

Counts: **61 distinct mutations** (40 API, 21 web) in **64 runs** (three API survivors were re-run after their specs were added). **Not caught at the end: 0.** **Survived on the first run, all
closed: 3.**

| Mutation                                            | Why it survived                                                                                                        | Closed by                                                                                                                                                                                       |
| --------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| C3-3e audit scrub unavailable is not a blocker      | No spec made the audit-log step unavailable, so the plan was "ready" either way.                                       | `AdminErasureEraseTest`: "says the plan is not ready when the audit-log step is not available, and why" (binds `FakeAuditScrub` with `available=false`; expects the blocker and `ready` false). |
| C3-4g a second click queues again                   | Specs covered the second click being refused at the preflight, not one that reaches the claim after the first made it. | `AdminErasureEraseTest`: "queues nothing for a second click that reaches the claim after the first made it" (`EraseStarter::start` twice; one job pushed).                                      |
| P10b the stale boundary moved (`>=` instead of `>`) | The run-state dataset had no row at exactly 3900 s.                                                                    | `AdminErasureQueueTest`: dataset rows "waiting, at the boundary" and "running, at the boundary" (stuck false and `can_retry` false at exactly 3900 s).                                          |

## API mutations (first run)

```
[C3-1a default flipped to on] BROKEN exit=1 Tests:    3 failed (7 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureExecuteFlagTest
    caught by: it is defined, named as documented, and OFF by default
    caught by: it is on the admin toggle list with a description that says what of…
    caught by: it refuses to flip it with no reason
[C3-1a default flipped to on] restored: identical
[C3-1b flag missing from the admin registry] BROKEN exit=1 Tests:    2 deprecated, 1 failed (8 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureExecuteFlagTest
    caught by: it is on the admin toggle list with a description that says what of…
[C3-1b flag missing from the admin registry] restored: identical
[C3-2a retry_after below the job timeout] BROKEN exit=1 Tests:    13 deprecated, 1 failed (48 assertions)
    caught by: Tests\Feature\Modules\Erasure\RunErasureJobTest
    caught by: it has a retry_after above the job timeout: the spec that would hav…
[C3-2a retry_after below the job timeout] restored: identical
[C3-2b job on the default queue] BROKEN exit=1 Tests:    13 deprecated, 1 failed (49 assertions)
    caught by: Tests\Feature\Modules\Erasure\RunErasureJobTest
    caught by: it is pinned to its own connection and queue, one try, an hour of t…
[C3-2b job on the default queue] restored: identical
[C3-2c job on the default connection] BROKEN exit=1 Tests:    13 deprecated, 1 failed (48 assertions)
    caught by: Tests\Feature\Modules\Erasure\RunErasureJobTest
    caught by: it is pinned to its own connection and queue, one try, an hour of t…
[C3-2c job on the default connection] restored: identical
[C3-2d tries raised] BROKEN exit=1 Tests:    13 deprecated, 1 failed (50 assertions)
    caught by: Tests\Feature\Modules\Erasure\RunErasureJobTest
    caught by: it is pinned to its own connection and queue, one try, an hour of t…
[C3-2d tries raised] restored: identical
[C3-2e a refusal does not release the claim] BROKEN exit=2 Tests:    11 deprecated, 3 failed (41 assertions)
    caught by: Tests\Feature\Modules\Erasure\RunErasureJobTest
    caught by: it releases the claim, audited, when the data moved since the admin…
    caught by: it releases the claim as a refusal, not as moved data, when a guard…
    caught by: it releases the claim as a refusal when the audit scrub is not avai…
[C3-2e a refusal does not release the claim] restored: identical
[C3-2f any exception releases the claim] BROKEN exit=1 Tests:    13 deprecated, 1 failed (47 assertions)
    caught by: Tests\Feature\Modules\Erasure\RunErasureJobTest
    caught by: it does NOT release the claim on any other exception: the files are…
[C3-2f any exception releases the claim] restored: identical
[C3-2g the cause is always "refused"] BROKEN exit=1 Tests:    13 deprecated, 1 failed (49 assertions)
    caught by: Tests\Feature\Modules\Erasure\RunErasureJobTest
    caught by: it releases the claim, audited, when the data moved since the admin…
[C3-2g the cause is always "refused"] restored: identical
[C3-2h a duplicate delivery runs again] BROKEN exit=1 Tests:    13 deprecated, 1 failed (53 assertions)
    caught by: Tests\Feature\Modules\Erasure\RunErasureJobTest
    caught by: it finishes a run that stopped after its database stage: the same j…
[C3-2h a duplicate delivery runs again] restored: identical
[C3-2i job timeout raised past retry_after] BROKEN exit=1 Tests:    12 deprecated, 2 failed (46 assertions)
    caught by: Tests\Feature\Modules\Erasure\RunErasureJobTest
    caught by: it is pinned to its own connection and queue, one try, an hour of t…
    caught by: it has a retry_after above the job timeout: the spec that would hav…
[C3-2i job timeout raised past retry_after] restored: identical
[C3-3a plan digest differs from the command's] BROKEN exit=2 Tests:    43 deprecated, 2 failed (305 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it returns the same plan as the command's dry-run: the digest, ever…
    caught by: it erases end to end: the click, then the job; the creator is erase…
[C3-3a plan digest differs from the command's] restored: identical
[C3-3b plan says ready regardless] BROKEN exit=1 Tests:    41 deprecated, 4 failed (309 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it says why an account is not ready, as codes: the gate's legs and…
[C3-3b plan says ready regardless] restored: identical
[C3-3c plan limiter removed] BROKEN exit=1 Tests:    44 deprecated, 1 failed (322 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it limits the plan to six a minute per admin, with a code the page…
[C3-3c plan limiter removed] restored: identical
[C3-3d plan carries the email] BROKEN exit=1 Tests:    44 deprecated, 1 failed (322 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it names nobody: no email, name, phone or object key in the plan
[C3-3d plan carries the email] restored: identical
[C3-3e audit scrub unavailable is not a blocker] BROKEN exit=0 Tests:    45 deprecated (325 assertions)
    !!! NOT CAUGHT
[C3-3e audit scrub unavailable is not a blocker] restored: identical
[C3-4a flag check removed] BROKEN exit=1 Tests:    44 deprecated, 1 failed (319 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it refuses, claims nothing and queues nothing, on every gate with (…
[C3-4a flag check removed] restored: identical
[C3-4b second factor ignored] BROKEN exit=1 Tests:    42 deprecated, 3 failed (294 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it refuses, claims nothing and queues nothing, on every gate with (…
    caught by: it never feeds a sign-in lockout counter with a wrong code, and res…
[C3-4b second factor ignored] restored: identical
[C3-4c typed id not compared] BROKEN exit=1 Tests:    42 deprecated, 3 failed (305 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it refuses, claims nothing and queues nothing, on every gate with (…
    caught by: it does not spend a recovery code, nor feed the throttle, when the…
    caught by: it limits the click itself to ten an hour per admin
[C3-4c typed id not compared] restored: identical
[C3-4d preflight skipped] BROKEN exit=1 Tests:    37 deprecated, 8 failed (303 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it does not spend a recovery code, nor feed the throttle, when the…
    caught by: it does not claim or queue a second time on a second click while th…
    caught by: it lets a retry through only for an incomplete run, or a run past t…
[C3-4d preflight skipped] restored: identical
[C3-4e erase limiter removed] BROKEN exit=1 Tests:    44 deprecated, 1 failed (323 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it limits the click itself to ten an hour per admin
[C3-4e erase limiter removed] restored: identical
[C3-4f afterCommit removed] BROKEN exit=1 Tests:    44 deprecated, 1 failed (324 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it queues only after the transaction it was called in has committed…
[C3-4f afterCommit removed] restored: identical
[C3-4g second click queues again] BROKEN exit=0 Tests:    45 deprecated (325 assertions)
    !!! NOT CAUGHT
[C3-4g second click queues again] restored: identical
[C3-4h operator not recorded] BROKEN exit=1 Tests:    43 deprecated, 2 failed (319 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it claims once, queues once on the erasure queue and connection, an…
    caught by: it erases end to end: the click, then the job; the creator is erase…
[C3-4h operator not recorded] restored: identical
[C3-4i retry not audited] BROKEN exit=1 Tests:    42 deprecated, 3 failed (322 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it lets a retry through only for an incomplete run, or a run past t…
[C3-4i retry not audited] restored: identical
[C3-4j digest not passed to the job] BROKEN exit=2 Tests:    44 deprecated, 1 failed (315 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it erases end to end: the click, then the job; the creator is erase…
[C3-4j digest not passed to the job] restored: identical
[C3-4k refusal answered as success] BROKEN exit=1 Tests:    44 deprecated, 1 failed (320 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it queues nothing when the gate refuses under the lock, after the s…
[C3-4k refusal answered as success] restored: identical
[P10a incomplete cannot be retried] BROKEN exit=1 Tests:    77 deprecated, 2 failed (482 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it shows how far a claimed account's run has got, and whether it lo…
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it lets a retry through only for an incomplete run, or a run past t…
[P10a incomplete cannot be retried] restored: identical
[P10b stale boundary moved (>=)] BROKEN exit=0 Tests:    79 deprecated (488 assertions)
    !!! NOT CAUGHT
[P10b stale boundary moved (>=)] restored: identical
[P10c stale window below the job timeout] BROKEN exit=1 Tests:    78 deprecated, 1 failed (487 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it lets a retry through only for an incomplete run, or a run past t…
[P10c stale window below the job timeout] restored: identical
[P10d db_done not stuck] BROKEN exit=1 Tests:    78 deprecated, 1 failed (479 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it keeps an account whose run stopped at db_done on the In progress…
[P10d db_done not stuck] restored: identical
[P10e db_done retryable] BROKEN exit=1 Tests:    78 deprecated, 1 failed (479 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it keeps an account whose run stopped at db_done on the In progress…
[P10e db_done retryable] restored: identical
[P10f erased db_done hidden from the queue] BROKEN exit=1 Tests:    78 deprecated, 1 failed (478 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it keeps an account whose run stopped at db_done on the In progress…
[P10f erased db_done hidden from the queue] restored: identical
[P11a admin without 2FA passes] BROKEN exit=1 Tests:    48 deprecated, 1 failed (342 assertions)
    caught by: Tests\Feature\Modules\Identity\SecondFactorStepUpTest
    caught by: it passes an account with no second factor unless the caller requir…
[P11a admin without 2FA passes] restored: identical
[P11b soft cap not enforced] BROKEN exit=1 Tests:    47 deprecated, 2 failed (332 assertions)
    caught by: Tests\Feature\Modules\Identity\SecondFactorStepUpTest
    caught by: it answers required for no code, invalid for a wrong one, rate limi…
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it never feeds a sign-in lockout counter with a wrong code, and res…
[P11b soft cap not enforced] restored: identical
[P11c wrong code not counted] BROKEN exit=1 Tests:    46 deprecated, 3 failed (332 assertions)
    caught by: Tests\Feature\Modules\Identity\SecondFactorStepUpTest
    caught by: it answers required for no code, invalid for a wrong one, rate limi…
    caught by: it suspends the enrolment at the hard cap by the same knocking as s…
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it never feeds a sign-in lockout counter with a wrong code, and res…
[P11c wrong code not counted] restored: identical
[P11d suspended enrolment ignored] BROKEN exit=1 Tests:    48 deprecated, 1 failed (340 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it never feeds a sign-in lockout counter with a wrong code, and res…
[P11d suspended enrolment ignored] restored: identical
[C2-4a-a service guard removed] BROKEN exit=1 Tests:    63 deprecated, 8 failed (272 assertions)
    caught by: Tests\Feature\Modules\Admin\RunAutomationRuleJobTest
    caught by: it skips a creator who is leaving: a request standing, a claim, or…
    caught by: Tests\Feature\Modules\Creators\Admin\AdminCreatorConnectionTest
    caught by: it refuses a creator who is leaving (a deletion request, a claim, o…
    caught by: it connects the same creator again once the request is cancelled (t…
[C2-4a-a service guard removed] restored: identical
[C2-4a-b door 1 guard removed] BROKEN exit=1 Tests:    67 deprecated, 4 failed (282 assertions)
    caught by: Tests\Feature\Modules\Creators\Admin\AdminCreatorConnectionTest
    caught by: it refuses a creator who is leaving (a deletion request, a claim, o…
[C2-4a-b door 1 guard removed] restored: identical
[C2-4a-c wrong code] BROKEN exit=1 Tests:    63 deprecated, 8 failed (266 assertions)
    caught by: Tests\Feature\Modules\Creators\Admin\AdminCreatorConnectionTest
    caught by: it refuses a creator who is leaving (a deletion request, a claim, o…
[C2-4a-c wrong code] restored: identical
DONE
```

## API survivors, re-run with their new specs

```
[C3-3e audit scrub unavailable is not a blocker (re-run with its new spec)] BROKEN exit=1 Tests:    46 deprecated, 1 failed (331 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it says the plan is not ready when the audit-log step is not availa…
[C3-3e audit scrub unavailable is not a blocker (re-run with its new spec)] restored: identical
[C3-4g second click queues again (re-run with its new spec)] BROKEN exit=1 Tests:    46 deprecated, 1 failed (331 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it queues nothing for a second click that reaches the claim after t…
[C3-4g second click queues again (re-run with its new spec)] restored: identical
[P10b stale boundary moved (>=) (re-run with its new spec)] BROKEN exit=1 Tests:    81 deprecated, 2 failed (498 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it shows how far a claimed account's run has got, and whether it lo…
[P10b stale boundary moved (>=) (re-run with its new spec)] restored: identical
```

## Admin SPA mutations

```
[S1 Erase offered on every row] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > offers Erase on a Ready row only, and the retry button only where the server
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > offers Erase on a Rea
[S1 Erase offered on every row] restored: identical
[S2 retry offered without the server saying so] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > offers Erase on a Ready row only, and the retry button only where the server
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > offers Erase on a Rea
[S2 retry offered without the server saying so] restored: identical
[S3 typed id not required] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > keeps the button disabled until the id, the reason and the code are all fille
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > keeps the button disa
[S3 typed id not required] restored: identical
[S4 code not required] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > keeps the button disabled until the id, the reason and the code are all fille
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > says what admin.erasure.execute.disabled means, and empties the code field
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > says what admin.erasure.execute.ulid_mismatch means, and empties the code fie
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > says what admin.erasure.execute.in_progress means, and empties the code field
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > says what admin.erasure.execute.mfa_required means, and empties the code fiel
[S4 code not required] restored: identical
[S5 reason length not required] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > keeps the button disabled until the id, the reason and the code are all fille
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > keeps the button disa
[S5 reason length not required] restored: identical
[S6 a plan that is not ready can be sent] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > shows why a plan that is not ready is not ready, and does not offer the click
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > shows why a plan that
[S6 a plan that is not ready can be sent] restored: identical
[S7 digest not sent] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > sends the typed id, the trimmed reason, the digest it showed and the code
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > sends the typed id, t
[S7 digest not sent] restored: identical
[S8 code not emptied after an attempt] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > says what admin.erasure.execute.disabled means, and empties the code field
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > says what admin.erasure.execute.ulid_mismatch means, and empties the code fie
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > says what admin.erasure.execute.in_progress means, and empties the code field
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > says what admin.erasure.execute.mfa_required means, and empties the code fiel
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > says what admin.erasure.execute.mfa_invalid means, and empties the code field
[S8 code not emptied after an attempt] restored: identical
[S9 skipped objects counted as deleted] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > reads the plan when it opens and shows it as numbers, with no person in it
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > reads the plan when i
[S9 skipped objects counted as deleted] restored: identical
[S10 id not upper-cased] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > sends the typed id, the trimmed reason, the digest it showed and the code
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > sends the typed id, t
[S10 id not upper-cased] restored: identical
[S11 404 reads as released] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > watching the account > follows it from waiting to running to erased, then sto
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > watching the account
[S11 404 reads as released] restored: identical
[S12 a released account keeps being watched as erasing] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > watching the account > says nothing was erased when the claim was released an
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > watching the account
[S12 a released account keeps being watched as erasing] restored: identical
[S13 timer not cleared on unmount] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > watching the account > stops the timer when the page goes away
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > watching the account
[S13 timer not cleared on unmount] restored: identical
[S14 watching never ends] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > watching the account > follows it from waiting to running to erased, then sto
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > watching the account > says nothing was erased when the claim was released an
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > watching the account
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > watching the account
[S14 watching never ends] restored: identical
[S15 stuck not shown] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > says, per tab, where an account stands and why
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > says, per tab, where an account stands and why
[S15 stuck not shown] restored: identical
[S16 an incomplete run keeps the page polling] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > the Erasing tab on its own > reloads by itself while an account is waiting or
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > the Erasing tab on it
[S16 an incomplete run keeps the page polling] restored: identical
[S17 server error code not mapped] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the hold > shows the refusal in words when the account has been taken for erasure meanwhile
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > does not offer the click when the plan failed to load
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > says what admin.erasure.execute.disabled means, and empties the code field
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > says what admin.erasure.execute.ulid_mismatch means, and empties the code fie
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > says what admin.erasure.execute.in_progress means, and empties the code field
[S17 server error code not mapped] restored: identical
[S18 refusal reasons not read] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > reads the reasons out when the server refuses an account that is not ready
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > reads the reasons out
[S18 refusal reasons not read] restored: identical
[S19 code not sent] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > sends the typed id, the trimmed reason, the digest it showed and the code
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > sends the typed id, t
[S19 code not sent] restored: identical
[S20 no watch banner] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > sends the typed id, the trimmed reason, the digest it showed and the code
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > watching the account > follows it from waiting to running to erased, then sto
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > watching the account > keeps looking through a failed poll
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > sends the typed id, t
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > watching the account
[S20 no watch banner] restored: identical
[S21 creator.unavailable not mapped] BROKEN exit=1
    caught by: CreatorDetailPage — per-field edit (Sprint 3 Chunk 4 sub-step 9) > agency connections (AH-051 D-9) > maps the connect code creator.unavailab
    caught by: src/modules/creators/pages/CreatorDetailPage.spec.ts > CreatorDetailPage — per-field edit (Sprint 3 Chunk 4 sub-step 9) > agency connections
[S21 creator.unavailable not mapped] restored: identical
```

## Follow-up after the C3 review: the audit-log preflight (review question 1)

Five more mutations against `EraseStarter` and its new specs (the click's preflight, the retry preflight and `start()` refuse when the audit-log step is not available). All caught.

```
[Q1-a preflight (first click) does not check the audit-log step] BROKEN exit=1 Tests:    51 deprecated, 1 failed (361 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it does not spend a recovery code, nor feed the throttle, when the…
[Q1-a preflight (first click) does not check the audit-log step] restored: identical
[Q1-b preflight (retry) does not check the audit-log step] BROKEN exit=1 Tests:    51 deprecated, 1 failed (361 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it does not claim an account for a retry either, when the audit-log…
[Q1-b preflight (retry) does not check the audit-log step] restored: identical
[Q1-c start() does not check the audit-log step] BROKEN exit=1 Tests:    51 deprecated, 1 failed (360 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it refuses at the claim itself when the audit-log step went away af…
[Q1-c start() does not check the audit-log step] restored: identical
[Q1-d the check never refuses] BROKEN exit=1 Tests:    47 deprecated, 5 failed (343 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it refuses, claims nothing and queues nothing, on every gate with (…
    caught by: it names the audit-log step as the reason, when that is what stops…
    caught by: it does not claim an account for a retry either, when the audit-log…
    caught by: it refuses at the claim itself when the audit-log step went away af…
[Q1-d the check never refuses] restored: identical
[Q1-e the check refuses with the wrong code] BROKEN exit=1 Tests:    50 deprecated, 2 failed (356 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureEraseTest
    caught by: it names the audit-log step as the reason, when that is what stops…
    caught by: it does not claim an account for a retry either, when the audit-log…
[Q1-e the check refuses with the wrong code] restored: identical
```

## Follow-up from the C3 walk: the account id on the row and in the dialog

Eight mutations against `AccountIdCopy`, the queue row and the dialog. Two survived their first run and are re-run below the first block: ID-6 was an **equivalent mutant** (the explicit "no clipboard" guard was
redundant with the `try`, so I removed the guard and kept the `try`, with a spec for a missing clipboard); ID-7 had no spec for a refused copy (added: "does not say Copied when the browser refuses the copy").

```
[ID-1 the row does not show the id] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be able to read the id they are asked to type) > shows every
    caught by: ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be able to read the id they are asked to type) > copies the i
    caught by: ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be able to read the id they are asked to type) > says nothing
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be ab
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be ab
[ID-1 the row does not show the id] restored: identical
[ID-2 the row shows the id of another thing] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be able to read the id they are asked to type) > shows every
    caught by: ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be able to read the id they are asked to type) > copies the i
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be ab
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be ab
[ID-2 the row shows the id of another thing] restored: identical
[ID-3 the dialog does not show the id] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > shows the id the admin is asked to type, above the field, with a copy button
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > shows the id the admi
[ID-3 the dialog does not show the id] restored: identical
[ID-4 the copy button copies something else] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be able to read the id they are asked to type) > copies the i
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > shows the id the admin is asked to type, above the field, with a copy button
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be ab
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > shows the id the admi
[ID-4 the copy button copies something else] restored: identical
[ID-5 "Copied" never goes away] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be able to read the id they are asked to type) > copies the i
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be ab
[ID-5 "Copied" never goes away] restored: identical
[ID-6 no clipboard guard] BROKEN exit=0
    !!! NOT CAUGHT
[ID-6 no clipboard guard] restored: identical
[ID-7 a failed copy still says Copied] BROKEN exit=0
    !!! NOT CAUGHT
[ID-7 a failed copy still says Copied] restored: identical
[ID-8 the typed check dropped (confirm without the id)] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > keeps the button disabled until the id, the reason and the code are all fille
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the Erase click (AH-104c C3) > keeps the button disa
[ID-8 the typed check dropped (confirm without the id)] restored: identical
```

Re-runs after the guard was removed and the spec added:

```
[ID-6 (re-run) a missing clipboard still says Copied] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be able to read the id they are asked to type) > says nothing
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be ab
[ID-6 (re-run) a missing clipboard still says Copied] restored: identical
[ID-7 (re-run) a failed copy still says Copied] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be able to read the id they are asked to type) > does not say
    caught by: ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be able to read the id they are asked to type) > says nothing
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be ab
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the account id (AH-104c C3 walk: an admin must be ab
[ID-7 (re-run) a failed copy still says Copied] restored: identical
```
