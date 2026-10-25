# AH-104c C2 — break-revert log (verbatim)

Harness output for every C2 break-revert, taken from a full re-run of every C2 mutation at the **final C2 state** (the working tree the gates ran on), with the terminal colour codes removed. Each
mutation is applied to the working tree, the named specs are run (Pest for the API, Vitest for the web), the harness reads the run's **exit code** (non-zero means the mutation was CAUGHT) and restores the
file; after every block it compares the file with its pre-mutation content (`restored: identical`). The harness and the mutation lists are throwaway scripts under `/tmp`; **they are not in the
repository, and this file is the record.** A mutation whose old string did not occur exactly once aborts (it never runs unchanged and reports "caught").

Counts: **69 distinct mutations** (C2-0 7, C2-1 12, C2-2 16, C2-3 13, C2-4 16, C2-5 5) in **71 runs** in this log (one run aborted and one survivor was re-run; the other survivors' first runs were
made while building and their fixed specs are what the blocks below re-ran). **Not caught: 1, an equivalent mutant.** **Survived or aborted on their first run, all closed: 4.** **Found by the gates, not by a
break-revert: 2.**

| Mutation                                                         | Why it survived or aborted                                                                                                                                                                                                    | Closed by                                                                                                                                                                                                                            |
| ---------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| C2-0 the revocation moved before the gate in `claim()`           | **An equivalent mutant**, not a hole in the specs: the claim is one transaction, so a refusal throws and the revocation rolls back with it. The same shape as C1's "stamp before the checks". Running it shows 31 specs pass. | Nothing to close. The two mutations that matter (no revocation in `claim()`; none in `reclaim()`) are caught, and the single-transaction scan from C1 (`ErasureClaimGuardTest`) is what keeps the revocation inside the transaction. |
| C2-1 the request does not require the reason                     | With `nullable`, a missing field skipped validation and the service's backstop returned 422 anyway, so the status alone could not tell them apart.                                                                            | The spec now requires the 422 to be a **field** error and not an `admin.erasure.hold` code. The re-run is caught (in the C2-1 block below).                                                                                          |
| C2-2 the Erased tab counts every ledger state                    | The counts spec had no `incomplete` ledger row, so counting all rows and counting completed ones agreed.                                                                                                                      | The counts spec adds an Incomplete row (the spec also pins that a stopped run is not an erasure). The re-run is caught.                                                                                                              |
| C2-3 a blocked row hides its reasons                             | **Aborted on the first run:** Prettier had reformatted the target text (the `.map().join()` chain was split over lines), so the old string occurred 0 times. The harness refused to run it unchanged.                         | Re-run against the reformatted text; caught (the last block below).                                                                                                                                                                  |
| C2-4 `deletion_pending` also true once the creator row is erased | Every dataset erased the user row and the creator row together, so the `! $erased` guard in `AdminDeletionFlags::forCreator` was never the deciding term (`isDeletionPending()` already checks the user's `erased_at`).       | `AdminDeletionFlagsTest`: "never says a creator is leaving once the creator row is erased, whatever the user row says" (creator erased, user not). The re-run is caught.                                                             |

**Found by the full gate run, not by any break-revert** (the specs I ran while building did not exercise them; they are the reason the gates run on the whole suite):

- **The claim's revocation moved the erasure digest.** `DryRunReport::digest()` hashed the row counts of `sessions`, `personal_access_tokens` and `user_push_tokens`, and `ErasureRun::execute()` recomputes the
  digest **after** the claim. Once the claim revokes every credential (C2-0), the digest at execute time could never equal the dry-run's for an account that had a session. **Ten specs failed** in
  `ErasureCommandTest`, `ErasureMirrorTest` and `ErasureDryRunTest`. In normal mode this would have been invisible in production (a request already revoked everything), but the shell's
  `--force-not-requested` (an account with live sessions) and the C3 button (a claim, then a job) would have refused their first run with "the digest does not match". Fixed: the credential tables (the
  list `CredentialRevoker::TABLES`, the one list) are printed in the dry-run but **not hashed**; the spec "does not move its digest when a credential row appears or goes, but still prints the count" pins both
  halves, and two specs that used a session row as "the data that moved" now use a row that is still hashed. Both mutations of the fix are in the C2-0 block below (hash them again: 6 specs fail; hash
  nothing relevant: caught).
- **A wall-clock flake in the queue spec.** "treats the grace boundary exactly" compared against a real `now()` and failed once on a loaded machine (a second boundary turned "one second ahead" into "due"). The
  file now freezes time in `beforeEach`.

## C2-0 (API): claim hides the account and revokes the credentials; the digest ignores credentials

```
[C2-0 claim() does not revoke] BROKEN exit=1 Tests:    29 deprecated, 2 failed (122 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureClaimerTest
    caught by: it signs the account out everywhere when it claims it, in both mode…
[C2-0 claim() does not revoke] restored: identical
[C2-0 reclaim() does not revoke] BROKEN exit=1 Tests:    30 deprecated, 1 failed (124 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureClaimerTest
    caught by: it signs the account out when a reapply reclaims it, too
[C2-0 reclaim() does not revoke] restored: identical
[C2-0 revoke runs before the gate (refusal leaves nobody signed in)] BROKEN exit=0 Tests:    31 deprecated (124 assertions)
    !!! NOT CAUGHT
[C2-0 revoke runs before the gate (refusal leaves nobody signed in)] restored: identical
[C2-0 scope ignores the claim] BROKEN exit=1 Tests:    31 deprecated, 4 failed (154 assertions)
    caught by: Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
    caught by: it defines "visible to agencies" once: the scope and the single-row…
    caught by: it leaves a creator out of discovery and answers their profile 404…
    caught by: it offers the creator in the new-conversation picker only where a t…
    caught by: it sends no message digest to the creator, and the control still ge…
[C2-0 scope ignores the claim] restored: identical
[C2-0 single-row twin ignores the claim] BROKEN exit=1 Tests:    27 deprecated, 8 failed (133 assertions)
    caught by: Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
    caught by: it defines "visible to agencies" once: the scope and the single-row…
    caught by: it leaves a creator out of discovery and answers their profile 404…
    caught by: it refuses a new connection request with the neutral 422 and writes…
    caught by: it refuses an invite with the neutral 422 and creates no assignment…
[C2-0 single-row twin ignores the claim] restored: identical
[C2-0 digest hashes the credential tables again] BROKEN exit=2 Tests:    25 deprecated, 6 failed (114 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureDryRunTest
    caught by: it does not move its digest when a credential row appears or goes,…
    caught by: Tests\Feature\Modules\Erasure\ErasureCommandTest
    caught by: it executes only with the digest and the typed ulid, and then erase…
    caught by: it prints the exception class, not its message, when a step fails (…
[C2-0 digest hashes the credential tables again] restored: identical
[C2-0 digest ignores a table that is not a credential (the feature rows)] BROKEN exit=1 Tests:    5 deprecated, 2 failed (17 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureDryRunTest
    caught by: it changes its digest when the data moves between dry-run and execu…
    caught by: it does not move its digest when a credential row appears or goes,…
[C2-0 digest ignores a table that is not a credential (the feature rows)] restored: identical
```

## C2-1 (API): the hold

```
[C2-1 gate ignores the hold] BROKEN exit=1 Tests:    26 deprecated, 2 failed (108 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureHoldTest
    caught by: it stops the erasure gate in every mode, including the operator for…
[C2-1 gate ignores the hold] restored: identical
[C2-1 release also clears the request] BROKEN exit=2 Tests:    25 deprecated, 3 failed (106 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureHoldTest
    caught by: it release clears the two hold columns and leaves the request alone
    caught by: it stops the erasure gate in every mode, including the operator for…
    caught by: it places and releases a hold over the API, answering with the queu…
[C2-1 release also clears the request] restored: identical
[C2-1 a cancel clears the hold] BROKEN exit=1 Tests:    27 deprecated, 1 failed (109 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureHoldTest
    caught by: it survives the creator cancelling the request, and shows again on…
[C2-1 a cancel clears the hold] restored: identical
[C2-1 service accepts a blank reason] BROKEN exit=1 Tests:    26 deprecated, 2 failed (108 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureHoldTest
    caught by: it refuses a blank reason for both verbs and writes nothing with ('…
[C2-1 service accepts a blank reason] restored: identical
[C2-1 request does not require the reason] BROKEN exit=1 Tests:    26 deprecated, 2 failed (112 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureHoldTest
    caught by: it 422s a missing, short or oversized reason with ('hold') / (null)
    caught by: it 422s a missing, short or oversized reason with ('release') / (nu…
[C2-1 request does not require the reason] restored: identical
[C2-1 request drops the minimum length] BROKEN exit=1 Tests:    26 deprecated, 2 failed (106 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureHoldTest
    caught by: it 422s a missing, short or oversized reason with ('hold') / ('too…
    caught by: it 422s a missing, short or oversized reason with ('release') / ('t…
[C2-1 request drops the minimum length] restored: identical
[C2-1 hold allowed on a claimed account] BROKEN exit=1 Tests:    26 deprecated, 2 failed (108 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureHoldTest
    caught by: it refuses an erased account, a claimed account and a non-creator w…
    caught by: it refuses a claimed account with 409 and an erased or non-creator…
[C2-1 hold allowed on a claimed account] restored: identical
[C2-1 hold not idempotent] BROKEN exit=1 Tests:    26 deprecated, 2 failed (104 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureHoldTest
    caught by: it is idempotent: holding a held account, or releasing one that is…
    caught by: it places and releases a hold over the API, answering with the queu…
[C2-1 hold not idempotent] restored: identical
[C2-1 release not idempotent] BROKEN exit=1 Tests:    27 deprecated, 1 failed (108 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureHoldTest
    caught by: it is idempotent: holding a held account, or releasing one that is…
[C2-1 release not idempotent] restored: identical
[C2-1 hold audit names the subject as actor] BROKEN exit=1 Tests:    26 deprecated, 2 failed (101 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureHoldTest
    caught by: it holds an account: stamps it with the admin, writes one audit row…
    caught by: it places and releases a hold over the API, answering with the queu…
[C2-1 hold audit names the subject as actor] restored: identical
[C2-1 hold on an erased account allowed] BROKEN exit=1 Tests:    26 deprecated, 2 failed (108 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureHoldTest
    caught by: it refuses an erased account, a claimed account and a non-creator w…
    caught by: it refuses a claimed account with 409 and an erased or non-creator…
[C2-1 hold on an erased account allowed] restored: identical
[C2-1 endpoint skips the platform-admin check] BROKEN exit=1 Tests:    26 deprecated, 2 failed (108 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureHoldTest
    caught by: it 401s without a session and 403s a non-admin with ('hold')
    caught by: it 401s without a session and 403s a non-admin with ('release')
[C2-1 endpoint skips the platform-admin check] restored: identical
```

## C2-2 (API): the queue

```
[C2-1 (rerun) request does not require the reason] BROKEN exit=1 Tests:    26 deprecated, 2 failed (112 assertions)
    caught by: Tests\Feature\Modules\Erasure\ErasureHoldTest
    caught by: it 422s a missing, short or oversized reason with ('hold') / (null)
    caught by: it 422s a missing, short or oversized reason with ('release') / (nu…
[C2-1 (rerun) request does not require the reason] restored: identical
[C2-2 pending predicate flipped] BROKEN exit=1 Tests:    22 deprecated, 6 failed (120 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it opens on the Pending tab
    caught by: it puts each account in exactly one tab, by the precedence
    caught by: it files a due creator under Ready (not Pending), and a held due cr…
    caught by: it treats the grace boundary exactly: due at the instant it ends, p…
[C2-2 pending predicate flipped] restored: identical
[C2-2 due predicate flipped] BROKEN exit=2 Tests:    19 deprecated, 9 failed (116 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it puts each account in exactly one tab, by the precedence
    caught by: it files a due creator under Ready (not Pending), and a held due cr…
    caught by: it treats the grace boundary exactly: due at the instant it ends, p…
    caught by: it keeps the blocker codes on the Blocked rows, and only the codes
[C2-2 due predicate flipped] restored: identical
[C2-2 held tab includes claimed] BROKEN exit=1 Tests:    26 deprecated, 2 failed (138 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it puts each account in exactly one tab, by the precedence
    caught by: it shows a claimed account as In progress even when it also carries…
[C2-2 held tab includes claimed] restored: identical
[C2-2 requested set includes held (ready/pending show held accounts)] BROKEN exit=1 Tests:    26 deprecated, 2 failed (130 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it puts each account in exactly one tab, by the precedence
    caught by: it files a due creator under Ready (not Pending), and a held due cr…
[C2-2 requested set includes held (ready/pending show held accounts)] restored: identical
[C2-2 in-progress reads the hold column] BROKEN exit=2 Tests:    25 deprecated, 3 failed (133 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it puts each account in exactly one tab, by the precedence
    caught by: it says how far a claimed account got, from the ledger
    caught by: it pages every tab stably, in order, with no repeats and no gaps wi…
[C2-2 in-progress reads the hold column] restored: identical
[C2-2 erased tab reads users (adds a name)] BROKEN exit=1 Tests:    27 deprecated, 1 failed (137 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it lists the Erased tab from the ledger alone, with a pinned key se…
[C2-2 erased tab reads users (adds a name)] restored: identical
[C2-2 erased tab counts every ledger state] BROKEN exit=1 Tests:    27 deprecated, 1 failed (142 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it counts every tab, from the same derivation as the tabs
[C2-2 erased tab counts every ledger state] restored: identical
[C2-2 erased tab lists every ledger state] BROKEN exit=1 Tests:    27 deprecated, 1 failed (135 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it lists the Erased tab from the ledger alone, with a pinned key se…
[C2-2 erased tab lists every ledger state] restored: identical
[C2-2 blockers never evaluated] BROKEN exit=2 Tests:    22 deprecated, 6 failed (124 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it puts each account in exactly one tab, by the precedence
    caught by: it keeps the blocker codes on the Blocked rows, and only the codes
    caught by: it puts a creator with no profile under Blocked with the not-erasab…
    caught by: it counts every tab, from the same derivation as the tabs
[C2-2 blockers never evaluated] restored: identical
[C2-2 precedence swapped (held before claimed)] BROKEN exit=1 Tests:    27 deprecated, 1 failed (139 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it shows a claimed account as In progress even when it also carries…
[C2-2 precedence swapped (held before claimed)] restored: identical
[C2-2 cursor repeats the last row (>=)] BROKEN exit=1 Tests:    24 deprecated, 4 failed (142 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it pages every tab stably, in order, with no repeats and no gaps wi…
    caught by: it does not repeat or skip a row when one is added ahead of the cur…
[C2-2 cursor repeats the last row (>=)] restored: identical
[C2-2 due scan not capped] BROKEN exit=1 Tests:    27 deprecated, 1 failed (142 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it caps the due scan, and says so
[C2-2 due scan not capped] restored: identical
[C2-2 per-row creator lookup (N+1)] BROKEN exit=1 Tests:    25 deprecated, 3 failed (139 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it runs the same number of queries for a page of 25 as for a page o…
[C2-2 per-row creator lookup (N+1)] restored: identical
[C2-2 controller skips the admin check] BROKEN exit=1 Tests:    27 deprecated, 1 failed (142 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it 401s without a session and 403s a non-admin
[C2-2 controller skips the admin check] restored: identical
[C2-2 route reverted to the shell] BROKEN exit=2 Tests:    8 deprecated, 24 failed (77 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminErasureQueueTest
    caught by: it 422s an unknown tab, a bad cursor and a bad page size with ('tab…
    caught by: it 422s an unknown tab, a bad cursor and a bad page size with ('cur…
    caught by: it 422s an unknown tab, a bad cursor and a bad page size with ('per…
    caught by: it opens on the Pending tab
[C2-2 route reverted to the shell] restored: identical
```

## C2-3 (admin SPA): the page and the dialog

```
[C2-3 dialog does not require a reason] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the hold > needs a reason, says it must carry no personal details, and sends it trimmed
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the hold > needs a reason, says it must carry no per
[C2-3 dialog does not require a reason] restored: identical
[C2-3 dialog lowers the minimum] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the hold > needs a reason, says it must carry no personal details, and sends it trimmed
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the hold > needs a reason, says it must carry no per
[C2-3 dialog lowers the minimum] restored: identical
[C2-3 dialog sends the reason untrimmed] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the hold > needs a reason, says it must carry no personal details, and sends it trimmed
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the hold > needs a reason, says it must carry no per
[C2-3 dialog sends the reason untrimmed] restored: identical
[C2-3 dialog drops the no-personal-details line] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the hold > needs a reason, says it must carry no personal details, and sends it trimmed
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the hold > needs a reason, says it must carry no per
[C2-3 dialog drops the no-personal-details line] restored: identical
[C2-3 a tab asks for the wrong tab] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > asks the API for the tab that was clicked, and only that tab
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > asks the API for the tab that was clicked, and only
[C2-3 a tab asks for the wrong tab] restored: identical
[C2-3 load-more drops the cursor] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > appends the next page with the cursor
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > appends the next page with the cursor
[C2-3 load-more drops the cursor] restored: identical
[C2-3 stuck state sentence missing (incomplete shows the claimed sentence)] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > says, per tab, where an account stands and why
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > says, per tab, where an account stands and why
[C2-3 stuck state sentence missing (incomplete shows the claimed sentence)] restored: identical
[C2-3 blocked row hides the reasons] ABORT: old string occurs 0x
[C2-3 held row offers Hold, not Release] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the hold > offers Hold on live accounts that are not held, and Release on held ones
    caught by: ErasureQueuePage (AH-104c C2) > the hold > releases a hold with a reason
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the hold > offers Hold on live accounts that are not
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the hold > releases a hold with a reason
[C2-3 held row offers Hold, not Release] restored: identical
[C2-3 in-progress row offers Hold] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the hold > offers Hold on live accounts that are not held, and Release on held ones
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the hold > offers Hold on live accounts that are not
[C2-3 in-progress row offers Hold] restored: identical
[C2-3 page does not reload after a hold] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the hold > needs a reason, says it must carry no personal details, and sends it trimmed
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the hold > needs a reason, says it must carry no per
[C2-3 page does not reload after a hold] restored: identical
[C2-3 error code not mapped (always generic)] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > the hold > shows the refusal in words when the account has been taken for erasure meanwhile
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > the hold > shows the refusal in words when the accou
[C2-3 error code not mapped (always generic)] restored: identical
[C2-3 shell path comes back] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > has no shell path left
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > has no shell path left
[C2-3 shell path comes back] restored: identical
[C2-3 (rerun) blocked row hides the reasons] BROKEN exit=1
    caught by: ErasureQueuePage (AH-104c C2) > says, per tab, where an account stands and why
    caught by: src/modules/compliance/pages/ErasureQueuePage.spec.ts > ErasureQueuePage (AH-104c C2) > says, per tab, where an account stands and why
[C2-3 (rerun) blocked row hides the reasons] restored: identical
```

## C2-4 (API and admin SPA): the read-through

The first nine are the API payloads (`AdminDeletionFlagsTest`); the rest are the admin reader and pages (Vitest). The last block re-runs the one mutation that survived, after its spec was added.

```
[C2-4 list drops the flags] BROKEN exit=1 Tests:    15 deprecated, 3 failed (38 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminDeletionFlagsTest
    caught by: it flags an account that is leaving on every admin payload that car…
[C2-4 list drops the flags] restored: identical
[C2-4 detail drops the flags] BROKEN exit=1 Tests:    15 deprecated, 3 failed (38 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminDeletionFlagsTest
    caught by: it flags an account that is leaving on every admin payload that car…
[C2-4 detail drops the flags] restored: identical
[C2-4 app usage drops is_erased] BROKEN exit=1 Tests:    15 deprecated, 3 failed (38 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminDeletionFlagsTest
    caught by: it flags an account that is leaving on every admin payload that car…
[C2-4 app usage drops is_erased] restored: identical
[C2-4 app usage drops deletion_pending] BROKEN exit=1 Tests:    15 deprecated, 3 failed (38 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminDeletionFlagsTest
    caught by: it flags an account that is leaving on every admin payload that car…
[C2-4 app usage drops deletion_pending] restored: identical
[C2-4 impersonation search drops the flags] BROKEN exit=1 Tests:    15 deprecated, 3 failed (38 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminDeletionFlagsTest
    caught by: it flags an account that is leaving on every admin payload that car…
[C2-4 impersonation search drops the flags] restored: identical
[C2-4 push deliveries drops deletion_pending] BROKEN exit=1 Tests:    15 deprecated, 3 failed (38 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminDeletionFlagsTest
    caught by: it flags an account that is leaving on every admin payload that car…
[C2-4 push deliveries drops deletion_pending] restored: identical
[C2-4 push deliveries drops is_erased] BROKEN exit=1 Tests:    15 deprecated, 3 failed (38 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminDeletionFlagsTest
    caught by: it flags an account that is leaving on every admin payload that car…
[C2-4 push deliveries drops is_erased] restored: identical
[C2-4 pending also true once erased] BROKEN exit=1 Tests:    17 deprecated, 1 failed (38 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminDeletionFlagsTest
    caught by: it never says a creator is leaving once the creator row is erased,…
[C2-4 pending also true once erased] restored: identical
[C2-4 pending never true for a user] BROKEN exit=1 Tests:    16 deprecated, 2 failed (36 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminDeletionFlagsTest
    caught by: it flags an account that is leaving on every admin payload that car…
    caught by: it derives the flags in one place, from the account alone
[C2-4 pending never true for a user] restored: identical
[C2-4 reader returns the name for an erased creator] BROKEN exit=1
    caught by: AllCreatorsPage — three-chip filters (AH-079) > reads an erased creator and a leaving creator through the reader (AH-104c C2-4)
    caught by: KycQueuePage (Sprint 13, D-4) > reads an erased creator and a leaving creator through the reader (AH-104c C2-4)
    caught by: CreatorListPage — review queue (Sprint 4 Chunk 3, Cluster 3) > reads an erased creator and a leaving creator through the reader (AH-104c C2-
    caught by: Creator app copy and routing (AH-102b) > reads an erased and a leaving creator through the reader (AH-104c C2-4)
    caught by: CreatorDetailPage — an account that is leaving (AH-104c C2-4) > says "Deleted creator" and shows no placeholder email for an erased creator
[C2-4 reader returns the name for an erased creator] restored: identical
[C2-4 reader returns the placeholder email] BROKEN exit=1
    caught by: UserSearchPage (Sprint 13, D-9) > reads an erased and a leaving account through the reader (AH-104c C2-4)
    caught by: AllCreatorsPage — three-chip filters (AH-079) > resets to page 1 when a filter changes after paging forward
    caught by: AllCreatorsPage — three-chip filters (AH-079) > navigates to the detail page when a row name is clicked
    caught by: AllCreatorsPage — three-chip filters (AH-079) > reads an erased creator and a leaving creator through the reader (AH-104c C2-4)
    caught by: KycQueuePage (Sprint 13, D-4) > reads an erased creator and a leaving creator through the reader (AH-104c C2-4)
[C2-4 reader returns the placeholder email] restored: identical
[C2-4 pending shows once erased] BROKEN exit=1
    caught by: creatorLabel > pending is true only for a live account that asked to leave
    caught by: DeletionRequestedChip > is absent for an ordinary, an erased and a missing account
    caught by: src/core/creators/creatorLabel.spec.ts > creatorLabel > pending is true only for a live account that asked to leave
    caught by: src/core/creators/creatorLabel.spec.ts > DeletionRequestedChip > is absent for an ordinary, an erased and a missing account
[C2-4 pending shows once erased] restored: identical
[C2-4 list page bypasses the reader] BROKEN exit=1
    caught by: CreatorListPage — review queue (Sprint 4 Chunk 3, Cluster 3) > reads an erased creator and a leaving creator through the reader (AH-104c C2-
    caught by: an admin creator name has one reader > no admin module reads display_name in code
    caught by: tests/unit/architecture/creator-label-single-reader.spec.ts > an admin creator name has one reader > no admin module reads display_name in c
    caught by: src/modules/creators/pages/CreatorListPage.spec.ts > CreatorListPage — review queue (Sprint 4 Chunk 3, Cluster 3) > reads an erased creator
[C2-4 list page bypasses the reader] restored: identical
[C2-4 detail page title bypasses the reader] BROKEN exit=1
    caught by: CreatorDetailPage — an account that is leaving (AH-104c C2-4) > says "Deleted creator" and shows no placeholder email for an erased creator
    caught by: an admin creator name has one reader > no admin module reads display_name in code
    caught by: tests/unit/architecture/creator-label-single-reader.spec.ts > an admin creator name has one reader > no admin module reads display_name in c
    caught by: src/modules/creators/pages/CreatorDetailPage.spec.ts > CreatorDetailPage — an account that is leaving (AH-104c C2-4) > says "Deleted creator
[C2-4 detail page title bypasses the reader] restored: identical
[C2-4 user search shows the email raw] BROKEN exit=1
    caught by: UserSearchPage (Sprint 13, D-9) > reads an erased and a leaving account through the reader (AH-104c C2-4)
    caught by: src/modules/support/pages/UserSearchPage.spec.ts > UserSearchPage (Sprint 13, D-9) > reads an erased and a leaving account through the reade
[C2-4 user search shows the email raw] restored: identical
[C2-4 push page drops the chip] BROKEN exit=1
    caught by: PushDeliveriesPage (AH-102b) > reads an erased and a leaving creator through the reader (AH-104c C2-4)
    caught by: src/modules/operations/pages/PushDeliveriesPage.spec.ts > PushDeliveriesPage (AH-102b) > reads an erased and a leaving creator through the r
[C2-4 push page drops the chip] restored: identical
[C2-4 (rerun) pending also true once erased] BROKEN exit=1 Tests:    17 deprecated, 1 failed (38 assertions)
    caught by: Tests\Feature\Modules\Admin\AdminDeletionFlagsTest
    caught by: it never says a creator is leaving once the creator row is erased,…
[C2-4 (rerun) pending also true once erased] restored: identical
```

## C2-5 (creator app): the sheet's left edge

These pin the **structure** only (see the review §5): the measurement, not this spec, is the proof of the pixels.

```
[C2-5 glyph inset changed] BROKEN exit=1
    caught by: the delete sheet has one left edge > the box is pulled back by the glyph inset (3px) and by nothing worked out
    caught by: tests/unit/architecture/delete-sheet-left-edge.spec.ts > the delete sheet has one left edge > the box is pulled back by the glyph inset (3px
[C2-5 glyph inset changed] restored: identical
[C2-5 slack arithmetic returns] BROKEN exit=1
    caught by: the delete sheet has one left edge > the box is pulled back by the glyph inset (3px) and by nothing worked out
    caught by: tests/unit/architecture/delete-sheet-left-edge.spec.ts > the delete sheet has one left edge > the box is pulled back by the glyph inset (3px
[C2-5 slack arithmetic returns] restored: identical
[C2-5 wrapper left to its slack] BROKEN exit=1
    caught by: the delete sheet has one left edge > the icon is 24px and its wrapper is exactly the icon, from the edge
    caught by: tests/unit/architecture/delete-sheet-left-edge.spec.ts > the delete sheet has one left edge > the icon is 24px and its wrapper is exactly th
[C2-5 wrapper left to its slack] restored: identical
[C2-5 icon size not pinned] BROKEN exit=1
    caught by: the delete sheet has one left edge > the icon is 24px and its wrapper is exactly the icon, from the edge
    caught by: tests/unit/architecture/delete-sheet-left-edge.spec.ts > the delete sheet has one left edge > the icon is 24px and its wrapper is exactly th
[C2-5 icon size not pinned] restored: identical
[C2-5 title loses the body padding] BROKEN exit=1
    caught by: the delete sheet has one left edge > the title takes the body padding
    caught by: tests/unit/architecture/delete-sheet-left-edge.spec.ts > the delete sheet has one left edge > the title takes the body padding
[C2-5 title loses the body padding] restored: identical
```
