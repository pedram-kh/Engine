# Jobs Board arc, chunk 5 — eyes-on fixes, the board Applications column, lifecycle reflection, the full-loop E2E, and the arc's close-out (AH-059)

- **Status:** **Closed — approved.** Eleven commits carrying code: `a70c548`, `c27926a`, `b2ca89b`,
  `bb825a8`, `75076ef`, `ce841e2`, `e2501b3`, `a4897b5`, `df99cac`, `0c7ea82`, `26a127a`, plus
  `b1ed331` (this review, the AH-059 log entry and the D7 close-out documents) and the close commit
  that flipped this line. Pushed 2026-07-29 — **thirteen in total**, and **the Jobs Board arc ends
  here**.
- **Verdict:** independent review complete: **D1–D7 and C1–C6 verified as built**; **all five
  mutations confirmed load-bearing** — the **branch-reorder** proving the D1 fix is ordering and not
  deletion (the three engagement cases red while case 1, the retained "Not selected" branch, stays
  green), the **17th enum case breaking the build at static analysis** rather than in a creator's
  browser (`match.unhandled` at PHPStan level max, ahead of the catalogue test's
  `UnhandledMatchError`), and the **one-predicate-two-surfaces red** (a single edit to `isFilled()`
  reddening the list page and the Settings tab together, which is what makes "one shared gate" a fact
  rather than a claim); **D2 accepted as explained-not-fixed**, with Pedram's confirmation on record
  and the **zero mail-path diff proven by command output**; the **four zero-diffs** (Boards module,
  migrations, automation seeds, the D2 mail path) likewise by command output rather than prose;
  **production posture LOW-MEDIUM confirmed**, with the **D3 reachability-escalation framing** carried
  into this file as the record — the toggle adds no backend code and changes only how many clicks
  stand between an operator and an irreversible fan-out; **Playwright 27/27** including the
  full-lifecycle spec; and the **`a4897b5` plan-doc sweep accepted as named-not-rewritten** — the
  right call, since rewriting history to hide a bookkeeping slip is the worse habit.
- **Date:** 2026-07-29
- **Provenance:** built by Cursor against the ratified plan and its rulings; reviewed and closed by
  Claude.
- **Ratified plan:** [`jobs-board-c5-plan.md`](jobs-board-c5-plan.md).
  **Binds to:** [`jobs-board-c4-review.md`](jobs-board-c4-review.md) (closed — the applications
  vocabulary, the accept/reject endpoints, the `after_commit` ordering discipline and the Q7
  detail-only ruling this chunk consumes and must not disturb) and
  [`jobs-board-c3-review.md`](jobs-board-c3-review.md) (closed — the visibility predicate and the
  creator job surfaces this chunk reorders branches on).
- **Gate board:** backend **2383 passed / 1 skipped** (8762 assertions), PHPStan level max **0
  errors**, Pint clean; `apps/main` **1357 passed** / 142 files, `apps/admin` **449**, api-client
  **204**; three typechecks clean, ESLint 0 errors; every parity spec green; **full Playwright
  27/27 in 5.1m**, two projects, including the new full-lifecycle spec. Full table in
  [Gate board](#gate-board--full-at-final-head).
- **Five mutations executed and reverted**, one per claim a reader would otherwise have to take on
  trust. Table in [Break-reverts](#break-reverts--five-mutations-verbatim).
- **This chunk ends the arc.** D7 produces the single-deploy runbook entry and the combined
  first-enable ritual; the deploy itself stays held and is Pedram's call.

---

## What shipped

Pedram's eyes-on of chunks 3 and 4 produced five items. Four were fixes and one was a bug report that
turned out not to be a bug. On top of them: the arc's regression net and its close-out.

- **The rejected-chip contradiction is dead (D1).** A creator who was rejected and then invited
  anyway no longer reads "Not selected" beside a live invitation. The application row stays
  `rejected` — the agency's answer to _that_ application was truthful — and the display prefers the
  engagement.
- **The auto-reject mail "bug" was investigated and is not one (D2).** The flag was never armed;
  neither reject path mailed, and both behaved as designed. What shipped is the thing that would have
  made that legible in ten seconds instead of an hour: a structured log line at every emission
  decision, naming the type, the recipient count and the flag state.
- **The campaigns list's "Job board" column is now an interactive toggle (D3)** driving the same
  endpoint and the same gates as the Settings tab, with the failure modes named out loud rather than
  swallowed.
- **The board has an Applications column (D4)** — the first column, real-column visual language,
  pending-only, no drag in and no drag out, with the same Accept and Reject dialogs the tab uses.
- **The creator's job surfaces reflect the engagement's stage (D5)** — In progress / Completed /
  Ended, derived at read time from the assignment status through one exhaustive mapping.
- **One Playwright spec walks the whole loop across both roles (D6)** — the regression net owed since
  chunk 3.
- **The arc's close-out (D7)** — the combined first-enable ritual, the single-deploy runbook entry,
  the resumption template's Part 2, two recorded gaps, this review and the AH-059 entry.

**No migration. No new endpoint. No backend code for D3 or D4 at all** — both drive endpoints that
already existed, and the review proves that by `git diff --stat` returning empty rather than by
saying so.

### Commit split, and why

Eleven commits, split by **surface and decision**, so each one answers a question a reviewer would
ask separately:

| Commit    | Contents                                                                                                |
| --------- | ------------------------------------------------------------------------------------------------------- |
| `a70c548` | S1 — `JobLifecycleState` + its catalogue test. The mapping alone, before anything consumes it.          |
| `c27926a` | S2 — the D2 outcome: docblock corrected to the code, the emission log line, the two `Log::spy()` tests. |
| `b2ca89b` | S3 — `assignment_state` on both resources + the status subquery + api-client types.                     |
| `bb825a8` | S4 — the SPA branch tables on card and detail, plus 7 i18n leaves × 24.                                 |
| `75076ef` | S5 — the list-page toggle, its two dialogs, 15 i18n leaves × 24, the shared floor predicate widened.    |
| `ce841e2` | S6 — the backend pins for what the toggle actually sends. **Test-only; no production line.**            |
| `e2501b3` | S7a — `RejectApplicationDialog` + `useCampaignApplications` extracted. **Pure refactor.**               |
| `a4897b5` | S7b — `BoardApplicationsColumn`, mounted first inside `.board-columns-root`.                            |
| `df99cac` | S8 — the `listable-campaign` test helper + its eight-case test.                                         |
| `0c7ea82` | S9 — the full-lifecycle Playwright spec.                                                                |
| `26a127a` | S10 — PHPStan level max on the new **test** files. Found at the gate; test-only.                        |

Two deliberate features of the split. **`e2501b3` is a pure refactor and stands alone**: it moves the
reject dialog and the list state out of `ApplicationsTab.vue` and changes no assertion, so if a later
commit broke the tab, the refactor is provably not why — the 11-spec guard held byte-for-byte across
it. And **`ce841e2` is test-only**, sitting between the SPA toggle and the board work, because "the
single-key PATCH the new switch sends is judged against the stored row" is a backend claim about
existing code, not a change to it.

`26a127a` is a gate fix on the AH-058 `ef195f8` precedent: level max flagged seven errors in the new
test files (a `Log::shouldHaveReceived()` that resolves through facade forwarding PHPStan cannot see,
and two helpers reaching for the ambient `test()`), all test-only, and it is its own commit rather
than an amend because it crosses three earlier commits' files.

**One honest slip in the split:** `docs/reviews/jobs-board-c5-plan.md` — the ratified plan, which
should have been its own held docs commit — was swept into `a4897b5` (S7b) by a wide `git add`. The
plan's content is unchanged and its ratification predates every code commit; the mistake is that
`git show a4897b5` shows a reviewer 723 lines of plan they did not ask for. Recorded rather than
rewritten: nothing is pushed, but rewriting history to hide a bookkeeping slip is a worse habit than
naming it.

---

## Per-decision evidence

### D1 — the rejected-chip contradiction, killed by branch ordering

**The premise needed correcting first, and C1 is where that happened.** The kickoff said "the D7
subquery already delivers `assignment_ulid` unconditionally — the surfaces just don't consult it on
the rejected branch." True of the detail, **false of the card**: `callerAssignmentUlidSubquery()` is
added only in `show()`, the card resource has no such key, and `CreatorJobDetailTest` **asserts
positively that the card must not carry it**, because c4's Q7 ruled detail-only. So no client-side
change could teach the card about an assignment the server never sent.

**What shipped instead (C1/Q5, ratified).** One new derived field, `assignment_state`, on **both**
resources — which D5 required anyway. `assignment_ulid` stays detail-only and the c4 assertion stays
green **untouched**: Q7 is preserved, not reversed.

```php
// CreatorJobCardResource.php:167 — the whole of it, on both shapes
private function callerAssignmentState(Campaign $campaign): ?string
{
    return JobLifecycleState::tryFromAssignmentStatusValue(
        $campaign->getAttribute('caller_assignment_status'),
    )?->value;
}
```

**The fix itself is branch ordering**, one chain per surface, `v-if` / `v-else-if` on a single element
chain rather than two independent `v-if`s so the two chips cannot both render:

| Surface | Branch 1                                                         | Branch 2                                    | Branch 3+                   |
| ------- | ---------------------------------------------------------------- | ------------------------------------------- | --------------------------- |
| Card    | `lifecycleState !== null` → the stage chip                       | `application_status !== null` → the c3 chip | —                           |
| Detail  | `lifecycleState !== null` → the stage notice (+ assignment link) | accepted notice                             | rejected notice, then Apply |

**Q2(a), ruled and built: the assignment always wins whenever one exists, including an Ended one.**
"Not selected" is therefore **unreachable for a pair that was ever invited**, and that is the honest
story rather than an edge case nobody enumerated — the agency's last act on the pair was an
invitation, not a refusal.

**Q4, ruled and built:** branch 1 **replaces** the accepted and rejected notices when an assignment
exists; the keys for branches 2–3 are retained, none deleted, because a pair with an application and
no assignment still needs them.

The four §5.34 cases run on **three** surfaces — the payload, the card and the detail — and are
enumerated under [§5.34 sets](#534-sets).

### D2 — investigated, explained, **not** fixed

**The finding, in one line: there was no mail-path defect. The flag was never armed, and both reject
paths behaved as designed.** Five independent confirmations are recorded in the plan's §2 (the
`features` row whose `updated_at` never moved; zero `feature_flag.toggled` audit rows for this flag
against two for the others; Mailhog holding 153 messages with **zero** hits for `application` or
`selected`, the gaps landing exactly where the six emissions were; the DB rows and `laravel.log`
proving the job ran to completion and wrote its in-app rows; and empty queues with no application
mailable anywhere in `failed_jobs`).

**The premise correction.** The kickoff described an asymmetry — manual reject mails, campaign-closed
auto-reject does not. That asymmetry **is not in the evidence**. Neither mailed. The manual reject at
18:51:57 sits between two job-posted batches that both arrived, so if it had mailed, the message would
be in Mailhog. **Confirmed by Pedram at plan-pause: no application email was observed at any point in
the eyes-on session** — in-app notification only, for the manual reject too. The observed symptom was
real; the attributed asymmetry was not.

**What shipped (S2), exactly and only:**

1. **The docblock corrected TO the code.** `ApplicationNotificationsEnabled` claimed the queued job
   re-checks the flag on entry to `handle()`. It does not, and that is **correct** — c4's C5 recorded
   the reinterpretation ("a mail flag must not gate database truth") and the c4 review ratified it as
   "a correct improvement on the ruling". The code was right; the docblock was stale in the most
   misleading possible direction, describing a defence that is not there in the class an operator
   reads to understand the flag.
2. **One structured log line at every emission decision**, naming the type, the recipient count, the
   queued count and the suppressed count. The flag is still read in exactly **one** place — `queue()`
   returns whether it queued, so the caller logs the outcome without reading the flag a second time,
   which keeps AH-058's mutation-8 property (one check, four reds) intact.
3. **The tests that pin it:** flag-OFF logs its reason, flag-ON reports the same line as queued, and a
   multi-recipient count on the `submitted` type.
4. **Zero diff on the mail path**, proven by command output below — `AutoRejectPendingApplicationsJob`,
   the mailables, the Blade views and `lang/**` are untouched.

**F3 landed in D7(a)**: the ritual's step 4 is "read both flags back", written with this incident
named as its reason.

### D3 — the list-page listing toggle

The read-only "Job board" chip is now a `v-switch` (Q6: a switch, not a clickable chip) driving
`PATCH /api/v1/agencies/{agency}/campaigns/{campaign}` — **the same endpoint, the same gates, the same
audit snapshot** as the Settings tab. **No backend code was written for it.**

**Asymmetric by design (Q6).** OFF is immediate and ungated: removing a listing is not the dangerous
direction. ON asks first, in a dialog naming the campaign and warning that **rostered creators may be
notified** — because the false→true flip stamps `listed_at` once and dispatches the fan-out, and
delisting afterwards does not un-send the mail.

**Failure is explicit, never silent.** Flipping ON with an incomplete listing refuses and **names every
missing field**; a completed or cancelled campaign refuses with the **status** reason instead. Both
refusals are evaluated locally as a courtesy and re-evaluated by the server as the rule — the spec
that proves the courtesy is not the authority feeds a server 422 through the same dialog.

**One predicate, two surfaces.** `missingListingFloorFields()` was widened to
`Readonly<Partial<Record<ListingFloorField, unknown>>>` so the Settings tab's live edit form and the
list row's stored attributes both fit **without a cast**, and neither surface can drift into a looser
gate than the other. Mutation 3 proves it is one predicate: loosening it once reds a test on each
surface.

**What the wire actually carries** is pinned on the backend (S6, `ce841e2`): a **single-key PATCH
blanks nothing** (every other column survives by omission), the flip is **judged against the stored
row** so an incomplete floor still refuses, **staff cannot flip it from anywhere**, **repeated flips
are safe** (the once-only stamps make the second flip notify nobody), and the audit snapshot is
**identical** to the Settings save's.

### D4 — the board's Applications column

A **pseudo-column**: the first child of `.board-columns-root` (Q7(i)), so it scrolls with the board and
reads as the leftmost column, borrowing a real column's width, header shape and count — and
deliberately distinguishable, with the status and both actions **on the card face**, which is the
affordance difference doing the talking.

**No drag in, no drag out — enforced by ABSENCE (C4, ratified).** There is no `<draggable>` in the
file, the component is **not a member of `localColumns`**, and it never joins the `board-cards` group.
A `:disabled` flag would have been the obvious implementation and the wrong one: a flag is one prop
away from being flipped by someone who does not know what it protects, whereas machinery that was
never wired cannot be un-disabled by accident. The rule it protects is §4.4's: a board drag is
consequence-free, and answering an application is not.

**The two answers are the existing dialogs, not copies** — `AcceptApplicationDialog` (the offer form)
and the newly extracted `RejectApplicationDialog`, both asserted by component identity rather than by
rendered text.

**The accept motion is asserted, not built (C4).** On accept the column refetches **both** surfaces —
its own list and `boardStore.refresh()` — so the application leaves the column because it is no longer
pending, and the new `invited` card appears in the Invited column through the existing listener and
automation. The end-to-end version of that same motion is step 5 of the D6 spec.

**The Applications tab stays.** Keep-both, recorded: the tab is the **full history** including
rejected, the column is a **pending-only working surface**. The revisit note: if the tab's only
remaining unique value turns out to be "the rejected list", it becomes a filter on the column rather
than a tab.

**Zero diff in the Boards module, in migrations and in the automation seeds** — this is a rendering of
applications on the board screen, not a board column in the data, which extends c4's D1 §5.32
annotation rather than crossing it. Proven by command output below.

### D5 — the coarse lifecycle reflection

One enum, one mapping function, **no `default` arm**, spec-pinned over all 16 `AssignmentStatus`
cases. Q3's mapping was confirmed exactly as proposed:

| State           | Cases                                                                                                              | Count |
| --------------- | ------------------------------------------------------------------------------------------------------------------ | ----- |
| **In progress** | `invited`, `countered`, `accepted`, `contracted`, `producing`, `draft_submitted`, `revision_requested`, `approved` | 8     |
| **Completed**   | `posted`, `live_verified`, `manually_verified`, `payment_held`, `payment_released`                                 | 5     |
| **Ended**       | `declined`, `rejected`, `cancelled`                                                                                | 3     |

Two rulings inside that table are the ones worth re-reading. **`approved` is In progress**, not
Completed: nothing is live yet, so "Completed" would over-promise. **The payment pair is Completed**,
which is where the `isTerminal()` trap lives — `payment_released` is terminal **and a success**, so
reusing `isTerminal()` for the Ended family would have told a fully-paid creator their job had
"Ended". The trap is named in the enum's docblock and pinned by its own test.

**A new enum case breaks the build, not the UI.** Mutation 2 adds a 17th case: PHPStan level max
reports `match.unhandled` and the catalogue test throws `UnhandledMatchError` — the failure arrives at
the gate, not in a creator's browser.

Read-time only: no column, no event, no sync. The detail keeps the assignment link as the "full
picture" path (`viewAssignment`), so the reflection replaces the accepted **notice**, never the link
out of it.

### D6 — the full-lifecycle E2E, and where it honestly stops

One spec, two roles, seven steps: the agency lists the campaign **from the list-page toggle** (D3,
through the confirmation dialog) → the creator sees the job on their board → applies with a note → the
agency finds the application in the board's **Applications column** (D4) → accepts it with an offer,
and **both** surfaces move (the application leaves the column, the invited card appears in Invited) →
the creator's job surfaces reflect the engagement (D1 + D5: the chip reads "In progress", the detail
offers the bridge) → the creator accepts the offer and the board reflects it.

**Two product facts the spec states plainly rather than working around.** The seeded campaign has
`requires_per_campaign_contract = false`, so accepting the offer **auto-advances the assignment to
`contracted`** (AH-042 D2) — the board chip reads "Contracted", not "Accepted". And **the card does not
move columns**: board automation put it in Invited on creation and nothing moves it on the accept, so
the assertion is that the **same card ULID is still in Invited with a changed chip**. Both are asserted
as facts about the product, not adjusted-away.

**The stopping point (Q8), confirmed as proposed:** through the creator accepting the offer and the
board reflecting it. Drafts and posting are a named future spec — they need media upload, a review
cycle and a verification driver, and bolting them on would make one spec the suite's single point of
failure rather than its regression net.

**S8's helper is what made the first step honest.** No existing helper provisions a **floor-complete
but unlisted** campaign with a rostered creator, so the spec would have had to start from an
already-listed campaign and skip D3 entirely. `POST /_test/agencies/{agency}/listable-campaign` seeds
exactly that, forces `requires_per_campaign_contract = false`, and leaves **no application, no
assignment and no board card behind** — the loop is what the spec is there to walk.

### D7 — the arc's close-out

| Item                                          | Where it landed                                                                                                                                                                                                                                                                                                                                    |
| --------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **(a)** the combined first-enable ritual      | [`feature-flags.md`](../feature-flags.md) — "The jobs-board arc's combined first-enable ritual" (rationale, preconditions, disarm semantics) and [`production-queue-worker.md` §7.4](../runbooks/production-queue-worker.md) (the operator's short form). **F3's read-both-flags-back is step 4**, with this chunk's incident named as its reason. |
| **(b)** the arc's single-deploy runbook entry | [`production-queue-worker.md` §8.3](../runbooks/production-queue-worker.md) — snapshot → the **four** migrations enumerated with their chunk and their `down()` honesty → the **mandatory** worker restart → no one-shots (one pre-deploy read) → smoke, including two arc-specific reads → record. §8.1 is marked superseded and points here.     |
| **(c)** `RESUMPTION-TEMPLATE.md` Part 2       | arc complete, deploy checklist final, open threads updated.                                                                                                                                                                                                                                                                                        |
| **(d)** the discoverability product gap       | [`tech-debt.md`](../tech-debt.md) — no creator-facing `is_discoverable` control exists, so the flag never varies in production; the product call (creator-controlled vs admin-only) is left **open for Pedram**.                                                                                                                                   |
| **(e)** the AH-059 entry + this review        | [`adhoc-changes-log.md`](adhoc-changes-log.md) and this file.                                                                                                                                                                                                                                                                                      |
| the C5/Q10 tech-debt entry                    | [`tech-debt.md`](../tech-debt.md) — the shared Redis queue, with the `QUEUE_CONNECTION`-override sketch and the one-time flush as the resolution.                                                                                                                                                                                                  |

---

## C1–C6 dispositions

| #      | Finding                                                                                                                 | Disposition                                                                                                                                                                                       |
| ------ | ----------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **C1** | D1's premise is half true — the card does not carry `assignment_ulid`                                                   | **Ratified (Q5).** One derived field, `assignment_state`, on both shapes; `assignment_ulid` stays detail-only; the c4 `:342-355` assertion is green and untouched. Q7 preserved, not reversed.    |
| **C2** | D2 is not a bug — the flag was never armed                                                                              | **Explained, not fixed.** S2 ships the docblock correction, the log line, the two `Log::spy()` tests and the zero-diff proof. No mail path, mailable, key or job changed.                         |
| **C3** | the reject confirm dialog is inline in `ApplicationsTab.vue`                                                            | **Extracted (Q7(ii)).** `RejectApplicationDialog.vue` + `useCampaignApplications`. **The 11-spec guard held** — no assertion moved.                                                               |
| **C4** | the no-drag requirement wants absence, not a disabled flag                                                              | **Ratified**, plus the dual refetch on accept. Asserted as §5.34 negatives and proven non-vacuous by mutation 4.                                                                                  |
| **C5** | dev and E2E share one Redis queue, poisoning `failed_jobs`                                                              | **Recorded, not fixed (Q10).** `tech-debt.md`, carrying the `QUEUE_CONNECTION`-override sketch and the one-time flush.                                                                            |
| **C6** | ripple the kickoff did not enumerate (api-client types, the board's prop chain, the shared floor predicate's signature) | **Built.** `JobLifecycleState` in `packages/api-client`, `canAct` + `campaignCurrency` threaded `CampaignDetailPage → BoardView → BoardColumns → BoardApplicationsColumn`, the predicate widened. |

---

## Break-reverts — five mutations, verbatim

Each mutation was applied, the named gate run, the output captured, then reverted and the gate re-run
green.

### Mutation 1 — the D1 branch order, swapped

`CreatorJobsPage.vue`: `application_status` first, `lifecycleState` second — the contradiction put
back.

```
   × CreatorJobsPage > D1 case 2 — rejected + LIVE invitation: "In progress", and "Not selected" is GONE
     → Cannot call text on an empty DOMWrapper.
   × CreatorJobsPage > D1 case 3 — rejected + ENDED engagement: "Ended" wins, not "Not selected" (Q2a)
     → Cannot call text on an empty DOMWrapper.
   × CreatorJobsPage > D1 case 4 — accepted + engagement: the stage replaces "Accepted"
     → Cannot call text on an empty DOMWrapper.
      Tests  3 failed | 14 passed (17)
```

The three cases with an engagement red; case 1 (rejected, no engagement) stays green, which is the
retained branch proving the fix did not simply delete the old behaviour. **Restore check:**
`CreatorJobsPage.spec.ts` + `CreatorJobDetailPage.spec.ts` → **39 passed**.

### Mutation 2 — a 17th `AssignmentStatus` case nobody mapped

`case Archived = 'archived';` added to the enum. **PHPStan, not just the test:**

```
  Line   JobLifecycleState.php
  73     Match expression does not handle remaining value:
         App\Modules\Campaigns\Enums\AssignmentStatus::Archived
         🪪  match.unhandled
 [ERROR] Found 1 error
```

```
  FAILED  Tests\Feature\Modules\Creators\JobLifecycleS…  UnhandledMatchError
  Unhandled match case App\Modules\Campaigns\Enums\AssignmentStatus::Archived
  at app/Modules/Creators/Enums/JobLifecycleState.php:91
  Tests:    3 failed, 4 passed (28 assertions)
```

This is D5's whole guarantee: a new enum case breaks the **build**, at the static-analysis gate, before
any test runs. **Restore check:** PHPStan `[OK] No errors`; `JobLifecycleStateTest` → **7 passed**.

### Mutation 3 — the shared listing gate, loosened once

`listingFloor.ts`'s `isFilled()` replaced with `return true`. **One mutation, both surfaces:**

```
   × CampaignListPage (Sprint 8 Chunk 1) > refuses an incomplete listing by NAMING every missing field
   × CampaignDetailPage — jobs board toggle (AH-054) > disables the toggle and names the missing
     fields while the listing floor is unmet (D3 mirror)
      Tests  2 failed | 113 passed (115)
```

The list page and the Settings tab red from the same edit, which is the claim "one predicate, two
surfaces" being proven rather than asserted. **Restore check:** `src/modules/campaigns` → **115
passed**.

### Mutation 4 — the no-drag absence, filled in

A `<draggable group="board-cards">` with a `.board-column__drag` handle added to
`BoardApplicationsColumn.vue`.

```
 FAIL  BoardApplicationsColumn — the pending working surface > contains NO draggable and NO drag
       handle (§5.34 negative)
AssertionError: expected true to be false // Object.is equality
 ❯ src/modules/boards/components/BoardApplicationsColumn.spec.ts:175:67
```

The §5.34 negative is non-vacuous: it fails the moment the machinery exists. (Wiring `<draggable>` in
also reds the rest of the file's cases, which is a side effect of the mutation, not extra signal.)
**Restore check:** `src/modules/boards` → **97 passed** across 12 files.

### Mutation 5 — the S2 flag-OFF log line, deleted

`logEmission()` removed from `CampaignApplicationNotifier::rejected()`.

```
  FAILED  Tests\Feature\Modules\Campaigns\AutoReject…  InvalidCountException
  Method info(<Any Arguments>) from Mockery_2_Illuminate_Log_LogManager should be called
  at least 1 times but called 0 times.
  at tests/Feature/Modules/Campaigns/AutoRejectPendingApplicationsTest.php:317
  ...
  at tests/Feature/Modules/Campaigns/AutoRejectPendingApplicationsTest.php:349
  Tests:    2 failed, 15 passed (53 assertions)
```

Both directions red together — the flag-OFF line **and** the flag-ON line — which is the point: a log
line that only appears in one state distinguishes nothing. **Restore check:**
`AutoRejectPendingApplicationsTest` → **17 passed**.

---

## §5.34 sets

### D1's four cases, on three surfaces

The payload set proves the API hands the SPA **both** facts in every combination (a branch cannot order
over a field it was not given); the two SPA sets prove the ordering.

| Case                                | Payload (`CreatorJobDetailTest`) | Card (`CreatorJobsPage.spec.ts`) | Detail (`CreatorJobDetailPage.spec.ts`) |
| ----------------------------------- | -------------------------------- | -------------------------------- | --------------------------------------- |
| 1 — rejected, **no** assignment     | `:507`                           | `:173`                           | `:347`                                  |
| 2 — rejected + **live invitation**  | `:525`                           | `:183`                           | `:359`                                  |
| 3 — rejected + **ended** assignment | `:547`                           | `:201`                           | `:382`                                  |
| 4 — accepted + assignment           | `:570`                           | `:211`                           | `:394`                                  |

Case 1 is the **retained** branch — "Not selected" must keep rendering when there is no engagement, or
the fix would just be a deletion. Case 2 is the eyes-on bug's exact shape. Case 3 is Q2(a).

### D5 — disjoint and complete

`JobLifecycleStateTest` (7 cases): the exhaustiveness pin over `AssignmentStatus::cases()`; the
three families asserted **disjoint and complete** (their union is the 16 cases, pairwise intersections
empty); the three state values pinned as the strings the SPA and the api-client mirror; the
`isTerminal()` non-reuse; the invitation reflecting as In progress (D1 depends on it); the raw-subquery
resolution including the absent case; and a separator-drift guard against the api-client union.
`CreatorJobDetailTest:413` re-runs the full 16-case catalogue **over HTTP**, on both shapes — the
mapping being correct and the mapping actually arriving are different claims.

### D4's negatives

| Negative                                                     | Where                                 |
| ------------------------------------------------------------ | ------------------------------------- |
| no `<draggable>`, no `data-group`, no drag handle            | `BoardApplicationsColumn.spec.ts:171` |
| not inside either draggable group                            | `BoardColumns.spec.ts:199`            |
| absent from `localColumns` — never in a reorder payload      | `BoardColumns.spec.ts:213`            |
| carries no drag handle, unlike every real column             | `BoardColumns.spec.ts:226`            |
| renders **application** cards, never `BoardCard`             | `BoardApplicationsColumn.spec.ts:182` |
| pending-only — history stays in the tab                      | `BoardApplicationsColumn.spec.ts:127` |
| no actions without the `invite` ability (rows still visible) | `BoardApplicationsColumn.spec.ts:194` |

### D3's staff negative, and the rest of the backend set

`CampaignJobsBoardListingTest:405` — **a staff member cannot flip the listing from anywhere**. Beside
it: the single-key PATCH blanking nothing (`:352`), the flip judged against the stored row (`:386`),
repeated flips being safe (`:421`), and the audit snapshot identical to the Settings save (`:443`).

### The cross-creator subquery negative, repeated

`CreatorJobDetailTest:450` — another creator's `producing` assignment on the same campaign leaves both
shapes at `null`. The ULID subquery already carried this negative; the **new status subquery** gets its
own rather than inheriting the claim by proximity.

---

## Zero-diff proofs

By command output, at final code HEAD, against the chunk's base (`5cc382c`, the AH-058 close):

```
$ git diff --stat 5cc382c..HEAD -- apps/api/app/Modules/Boards \
                                   apps/api/database/migrations \
                                   apps/api/database/seeders
(no output)
```

```
$ git diff --stat 5cc382c..HEAD -- \
    apps/api/app/Modules/Campaigns/Jobs/AutoRejectPendingApplicationsJob.php \
    'apps/api/app/Modules/Campaigns/Mail/Application*' \
    apps/api/resources/views/emails \
    apps/api/lang
(no output)
```

The Boards module, every migration, the automation seeds, the auto-reject job, all three application
mailables, every Blade view and the whole of `lang/**` are **byte-identical** to the chunk-4 close.

And the whole of what _did_ change on the backend, for contrast — eight files, of which two are test
helpers:

```
$ git diff --stat 5cc382c..HEAD -- apps/api/app
 .../Services/CampaignApplicationNotifier.php       | 105 +++++++++++-
 .../Modules/Creators/Enums/JobLifecycleState.php   | 116 +++++++++++++
 .../Features/ApplicationNotificationsEnabled.php   |  22 ++-
 .../Http/Controllers/CreatorJobBoardController.php |  43 ++++-
 .../Http/Resources/CreatorJobCardResource.php      |  42 +++++
 .../Http/Resources/CreatorJobDetailResource.php    |   6 +
 .../CreateListableCampaignController.php           | 182 +++++++++++++++++++++
 apps/api/app/TestHelpers/Routes/api.php            |  12 ++
 8 files changed, 519 insertions(+), 9 deletions(-)
```

**No new endpoint, no new route outside `_test`, no enum case added to a shipped enum, no new
notification type, no new flag.**

---

## Locale parity and the copy counts

| Surface                                           | New leaves | Locales | Total   |
| ------------------------------------------------- | ---------- | ------- | ------- |
| SPA creator copy (`creator.json` — D1/D5)         | 7          | 24      | 168     |
| SPA app copy (`app.json` — D3 toggle + D4 column) | 15         | 24      | 360     |
| **Total**                                         | **22**     | 24      | **528** |

The 7 creator leaves are 3 lifecycle chip labels + 3 detail notices + `viewAssignment`. The 15 app
leaves are 13 for the toggle (aria label, confirm title/body/notice, confirm, cancel, both refusal
bodies + their title, close, fix, and the two success toasts) + 2 for the column (title, empty).

**No backend `lang/**`leaves at all** — this chunk adds no mail, which is also why the D2 zero-diff
proof above can include`apps/api/lang` in its path list.

All 24 locales carry real machine-translated copy, including the flaky 10, per AH-046/047. Spot values,
read out of the shipped files:

| Locale | `creator.ui.jobs.lifecycle.in_progress` | `app.campaigns.board.applications.title` | `app.campaigns.listing.toggle.blockedFloor`                                                  |
| ------ | --------------------------------------- | ---------------------------------------- | -------------------------------------------------------------------------------------------- |
| `bg`   | В процес                                | Кандидатури                              | На {name} липсва: {fields}. Попълнете ги в кампанията и опитайте отново.                     |
| `el`   | Σε εξέλιξη                              | Αιτήσεις                                 | Από την {name} λείπουν: {fields}. Συμπληρώστε τα στην καμπάνια και δοκιμάστε ξανά.           |
| `et`   | Pooleli                                 | Kandideerimised                          | Kampaanial {name} on puudu: {fields}. Täida need kampaanias ja proovi uuesti.                |
| `fi`   | Käynnissä                               | Hakemukset                               | Kampanjasta {name} puuttuu: {fields}. Täydennä ne kampanjassa ja yritä uudelleen.            |
| `ga`   | Ar siúl                                 | Iarratais                                | Tá {fields} in easnamh ar {name}. Líon isteach sa bhfeachtas iad agus bain triail eile as.   |
| `hu`   | Folyamatban                             | Jelentkezések                            | A(z) {name} kampányból hiányzik: {fields}. Töltsd ki ezeket a kampányban, majd próbáld újra. |
| `lt`   | Vykdoma                                 | Paraiškos                                | Kampanijai {name} trūksta: {fields}. Užpildykite juos kampanijoje ir bandykite dar kartą.    |
| `lv`   | Norisinās                               | Pieteikumi                               | Kampaņai {name} nav norādīts: {fields}. Aizpildiet tos kampaņā un mēģiniet vēlreiz.          |
| `mt`   | Għaddej                                 | Applikazzjonijiet                        | {name} nieqsa: {fields}. Imliehom fil-kampanja u erġa' pprova.                               |
| `ro`   | În desfășurare                          | Candidaturi                              | Din {name} lipsesc: {fields}. Completează-le în campanie și încearcă din nou.                |

`i18n-locale-parity.spec.ts` is green across every namespace, which covers the key-sets, the `{named}`
placeholder sets (`{name}` and `{fields}` above are the ones this chunk adds) and the CLDR plural forms.

---

## Gate board — full, at final HEAD

| Gate                                           | Result                                                                               |
| ---------------------------------------------- | ------------------------------------------------------------------------------------ |
| `pest` (apps/api, full, serial at 2G)          | **2383 passed, 1 skipped** (8762 assertions), 123.6s                                 |
| `phpstan` (level max, apps/api)                | **0 errors**                                                                         |
| `pint --test` (run outside the sandbox, §5.18) | **passed**                                                                           |
| `vitest` (apps/main, full)                     | **1357 passed** / 142 files                                                          |
| `vitest` (apps/admin, full)                    | **449 passed** / 53 files                                                            |
| `vitest` (packages/api-client)                 | **204 passed** / 9 files                                                             |
| `vue-tsc --noEmit` (apps/main)                 | **clean**                                                                            |
| `vue-tsc --noEmit` (apps/admin)                | **clean**                                                                            |
| `tsc --noEmit` (packages/api-client)           | **clean**                                                                            |
| `eslint` (apps/main)                           | **0 errors** (the same 2 pre-existing `v-html` warnings)                             |
| `eslint` (apps/admin)                          | **0 errors**                                                                         |
| `i18n-locale-parity.spec.ts`                   | **green** (24 locales, all namespaces)                                               |
| `i18n-notifications-parity.spec.ts`            | **green** (18 live types — unchanged; this chunk adds none)                          |
| `templates.spec.ts`                            | **green**                                                                            |
| `listing-floor-parity.spec.ts`                 | **green** (the SPA mirror still matches the PHP trait, after the signature widening) |
| **Playwright (apps/main, full suite)**         | **27/27 passed** in 5.1m, two projects                                               |

Backend counts moved **2338 → 2383** (+45 tests, +175 assertions) and `apps/main` **1319 → 1357**
(+38 tests, +1 file) against the chunk-4 close.

**Playwright procedure.** The dev stack was **down** before the run (ports 8000, 5173, 5174 and the
stray 8001 confirmed free), so `reuseExistingServer: false` never had to defend
`global-setup.ts`'s unconditional `migrate:fresh` against the developer's real database. The suite ran
against `catalyst_e2e` with `TEST_HELPERS_TOKEN` exported. **After the run the stack was restarted and
health-checked: API `/up` 200, main 5173 200, admin 5174 200.**

The new leg, from the run:

```
  ✓  19 [chromium] › playwright/specs/jobs-board-full-lifecycle.spec.ts:94:3 › AH-059 (D6) — the jobs
        board, end to end, both roles › an agency lists a job, a creator applies, the agency accepts on
        the board, and the creator accepts the offer (30.3s)

  27 passed (5.1m)
```

At 30.3s it is the suite's longest spec, as the kickoff allowed. Spec #12 (failed-login lockout) took
25.4s and passed on its AH-057 `test.slow()` budget.

### New and changed tests, by file

| File                                                  | Tests                                                       |
| ----------------------------------------------------- | ----------------------------------------------------------- |
| `JobLifecycleStateTest.php` (new)                     | 7                                                           |
| `CreatorJobDetailTest.php` (extended)                 | 29 total (+9: the D5 catalogue, the D1 four, the negatives) |
| `CampaignJobsBoardListingTest.php` (extended)         | 28 total (+5: the S6 set)                                   |
| `AutoRejectPendingApplicationsTest.php` (extended)    | 15 total (+2: the two log-line directions)                  |
| `ApplicationSubmittedNotificationTest.php` (extended) | 7 total (+1: the multi-recipient count)                     |
| `CreateListableCampaignTest.php` (new)                | 8                                                           |
| `CreatorJobsPage.spec.ts` (extended)                  | 17 total (+5)                                               |
| `CreatorJobDetailPage.spec.ts` (extended)             | 22 total (+6)                                               |
| `CampaignListPage.spec.ts` (extended)                 | 12 total (+6: the D3 set)                                   |
| `BoardApplicationsColumn.spec.ts` (new)               | 14                                                          |
| `BoardColumns.spec.ts` (extended)                     | 11 total (+5: the §5.34 negatives)                          |
| `BoardView.spec.ts` (extended)                        | 6 total (+2: hosts the column, passes `canAct`)             |
| `ApplicationsTab.spec.ts`                             | **11 — unchanged, the C3 guard**                            |
| `jobs-board-full-lifecycle.spec.ts` (Playwright)      | 1                                                           |

---

## Review priorities — where each was discharged

| #   | Priority                                                          | Discharged                                                                                                                                                   |
| --- | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | D1 both §5.34 branches + the contradiction dead                   | The four cases on three surfaces (table above). Case 1 retained, case 2 fixed. Mutation 1 proves the ordering is what does it.                               |
| 2   | D2's mechanism named with evidence, fixed-or-explained            | Plan §2's five confirmations + Pedram's plan-pause confirmation. **Explained, not fixed.** The gap-test is the log-line pair, since the gap was operational. |
| 3   | D4: zero board/automation diff, no-drag enforced, motion asserted | Both `git diff --stat` runs empty; mutation 4 on the absence; the dual refetch asserted in the spec and end-to-end in step 5 of the Playwright leg.          |
| 4   | D5 mapping exhaustive — a new case breaks the build               | Mutation 2: PHPStan `match.unhandled` **and** `UnhandledMatchError`, before any UI renders.                                                                  |
| 5   | D3 gating parity with Settings, one shared gate                   | Mutation 3 reds both surfaces from one edit; the backend set pins the same 422s, the staff negative and the identical audit snapshot.                        |
| 6   | the full-lifecycle spec green in the full board                   | **27/27**, spec #19, 30.3s, dev stack down, restart + health-check after.                                                                                    |
| 7   | locale parity + flaky-10                                          | 528 leaves × 24, zero missing, parity spec green, spot values printed above.                                                                                 |
| 8   | the close-out docs complete per D7's list                         | The D7 table above: (a)–(e) all landed, plus the C5 tech-debt entry.                                                                                         |

---

## Production posture (restated at final code HEAD `26a127a`)

**§5.40 risk: ⚠ LOW-MEDIUM.** Accepted as re-derived at plan-pause. **No migration** — nothing in
D1–D7 alters a table, so `down()` honesty is trivially satisfied: there is nothing to reverse. Four of
the five eyes-on decisions are read-only or display-layer. One is not, and it is the whole reason this
is not `NONE`:

> **D3 is the risk, and it is a REACHABILITY escalation of an existing irreversible write.** The
> list-page toggle adds **no backend code at all** — it drives the same
> `PATCH /api/v1/agencies/{agency}/campaigns/{campaign}` the Settings tab drives today. What changes is
> how many clicks stand between an operator and that endpoint. Today: open a campaign → Settings tab →
> flip a switch → **Save** a full form. Tomorrow: **one click on a table row.** And the false→true flip
> is not undoable in the way a boolean suggests: it stamps `listed_at` **once, on the flip only**, and
> it dispatches `SendJobPostedNotificationsJob`, which — with `job_posted_notifications_enabled` **ON**
> — **mails up to 50 rostered creators** per flip. Delisting afterwards un-lists the campaign. It does
> not un-send the mail. So a mis-click on a row in a table is now one round-trip away from an outbound
> fan-out to real creators, and that is a genuinely new exposure even though the code that does it is
> byte-identical. **This is why Q6 asks for a confirmation step on the ON direction** rather than
> treating the toggle as symmetric.

**The containment is real and worth stating in the same breath.** The confirmation dialog on the ON
direction names the campaign and says creators may be notified. The local gate refuses an incomplete or
terminal campaign before anything is sent, and the server refuses it again as the rule. The once-only
`(campaign, creator)` stamps mean a second flip of the same campaign notifies nobody — asserted at
`CampaignJobsBoardListingTest:421`, not rebuilt. The fan-out is flag-gated, capped at 50 and
roster-scoped.

**D4 puts accept and reject on a second surface and creates nothing new.** The column reads
`campaign_applications` through the existing list endpoint and calls the existing accept/reject
endpoints — the ones reviewed at MEDIUM in c4, with their transaction, their gate list and their ten
mutations intact. The §5.40 delta over chunk 4 is **zero**, and the zero is proven by command output
rather than by claim.

**D1 and D5 are read-time derivations.** Two additive fields on creator-facing payloads and one mapping
function. No column, no event, no sync, no write of any kind. The blast radius of getting the mapping
wrong is a **wrong label on a card**, which is the class of bug D1 exists to fix.

**D2 wrote nothing.** A docblock, a log line, three tests.

**The keysets still hold.** `assignment_state` was added to `CARD_KEYS` deliberately and the exact-keyset
assertions still pass on both shapes, so no brand field or internal id joined a creator-facing payload
by accretion. The cross-creator negative is repeated for the new subquery rather than inherited.

**At T+0 the reachable population is provably zero, for the whole arc.** `listed_on_jobs_board` is a
new column defaulting `false`, and the surfaces that flip it ship in this same deploy, so no campaign
is listed, the board is empty, no application exists, the fan-out has no recipient and every path this
chunk touches is inert. **D3's escalation becomes real the moment the first campaign is listed, not at
deploy.**

**Both mail flags ship and stay OFF, and arming them is not part of the deploy.** The combined ritual
(D7a) is a separate, later, deliberate act with a read-back step.

**The queue-worker restart obligation carries forward unchanged** from chunks 3 and 4 — this chunk adds
no mail copy, but the arc does, and the arc deploys as one unit.

**No `tenancy.md` §4 rows.** This chunk adds no production route at all. The one new route is a `_test`
helper behind the existing token gate, and its own test asserts the gate closed returns **404**.

**One dev-environment hazard is recorded rather than fixed** (C5/Q10): the dev and E2E environments
share a Redis queue, so `failed_jobs` on a developer host is polluted with 158 stale E2E jobs and is not
a usable diagnostic signal there. Production is unaffected — it has no E2E runs — but the first-enable
ritual's "watch `failed_jobs`" step carries the caveat.

---

## What this chunk deliberately did not build

A real board column or any `board_cards` change (D4 renders applications on the board screen; the data
layer is untouched); drag-and-drop of applications, in either direction; a reject-reason field, still
(c4's D4 stands); the Applications tab's removal (keep-both, with the revisit note recorded); an
`is_discoverable` control (D7d — recorded as an unmade **product** decision, not an engineering
backlog item); the `?tab=` deep link from the list-page toggle's "Open campaign" affordance (recorded
as a candidate — the link goes to the campaign, not to its Settings tab); a fix for the shared Redis
queue (C5/Q10 — recorded with its resolution sketched); a drafts-and-posting E2E leg (Q8's named future
spec); any change to the four mail-path files the D2 investigation cleared; and any deploy.

---

## The arc, closed

Five chunks, one deploy, one feature: an agency lists a campaign; the creators on its roster see it and
apply; the agency answers; the accepted creator's engagement runs on the machinery that already
existed.

| Chunk | Entry           | What it added                                                                                                                |
| ----- | --------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| 1 + 2 | AH-053 / AH-054 | The brand completeness floor, the brand logo pipeline, the campaign listing fields and the Settings toggle. **1 migration.** |
| 3     | AH-056          | The creator's board, the job detail, apply, and the job-posted fan-out. **3 migrations.**                                    |
| —     | AH-057          | The eyes-on fix pass over chunk 3's UI. **Zero backend diff.**                                                               |
| 4     | AH-058          | The agency's Applications tab, accept, reject, terminal auto-reject, the application vocabulary. **No migration.**           |
| 5     | AH-059          | These five eyes-on fixes, the board column, the lifecycle reflection, the full-loop E2E, the close-out. **No migration.**    |

**Four migrations total, all additive**, enumerated with their `down()` honesty in
[`production-queue-worker.md` §8.3](../runbooks/production-queue-worker.md). **Two flags, both OFF**,
armed together by one ritual whenever Pedram chooses. **One mandatory worker restart.** **No
scheduler dependency anywhere in the arc** — every trigger is a user action or a queued job, chosen
deliberately because the production scheduler is still unverified.
