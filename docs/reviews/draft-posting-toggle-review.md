# Draft Workflow v2, chunk B — the optional posting flow (AH-069)

- **Status:** **Built, gates green, push held.** Two commits here, on top of the plan-pause commit
  `59455604`, which is itself still unpushed — so the push, when it comes, moves `origin/main` by
  **three**. The pair: `451388f1` —
  `feat(campaigns): let a campaign end at draft approval when creators do not post (AH-069)` — and the
  docs commit carrying this file, the AH-069 log entry, the `deploy-log.md` PENDING entry, the two
  tech-debt entries, the Sprint-10 spec pointer and the resumption refresh.
- **Date:** 2026-08-16
- **Provenance:** built by Cursor against the ratified plan
  ([`draft-posting-toggle-plan.md`](draft-posting-toggle-plan.md), committed at plan-pause as
  `59455604`) and Claude's Q1–Q8 rulings.
- **Chunk base:** `59455604` —
  `docs(reviews): plan-pause for Draft Workflow v2 chunk B (optional posting flow, AH-069)`.
- **Binds to:** [`contract-toggle-off-flow-review.md`](contract-toggle-off-flow-review.md) (AH-042 —
  the chained-transition precedent, reinterpreted here; see §2), `adhoc-changes-log.md` AH-043 (the
  seven-listener sweep), AH-047 (the creator banner sibling this chunk sits beside), AH-054 (the
  campaign store-whitelist catch), AH-059 (the E2E stopping point this chunk extends),
  AH-068 (the notification/mail surfaces this chunk re-enters, one chunk later).
- **§5.40 risk: LOW–MEDIUM, re-derived at build.** The plan declared MEDIUM on the assumption that
  D2's backfill command would ship. Q1(a) removed it, and with it the only total-predicate write in
  the chunk. See [§1](#1-540-risk-re-derived-at-build).

---

## 1. §5.40 risk, re-derived at build

### ⚠️ PROD-DATA RISK: LOW–MEDIUM

The plan's seven-item declaration, checked item by item against what actually shipped.

| #   | Plan's leg                                       | Shipped                                                                                                                                              | Risk                                                                                 |
| --- | ------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| 1   | One additive `campaigns` column                  | `creator_posts_content boolean NOT NULL DEFAULT true`. Catalogue-only on Postgres; no existing row read or rewritten.                                | **LOW**                                                                              |
| 2   | A backfill command writing every campaign row    | **GONE.** Q1(a) made the column default `true`, which is what the command would have written. No data mutation ships.                                | **removed**                                                                          |
| 3   | A new terminal state written to live assignments | `completed_on_approval`, reachable only from `approved`, only on a toggle-OFF campaign. No historical row read-for-mutation.                         | **MEDIUM** — the sharpest edge; see [§3](#3-the-q1-two-layer-design-and-its-534-set) |
| 4   | Zero row deletion on boards                      | Held. The Posted column is filtered at RENDER; the row, its automations and every card survive. Pinned by row counts.                                | **LOW**                                                                              |
| 5   | New mail + notification to real creators         | Both shipped. Mail copy moved ×24 ⇒ **queue-worker restart is a deploy obligation.**                                                                 | **LOW**, with an operational obligation                                              |
| 6   | Honest `down()`                                  | Held, and stated in the migration docblock: dropping the column discards every campaign's posture and re-running `up()` resets everything to `true`. | **LOW**                                                                              |
| 7   | Pre-deploy snapshot                              | Still mandatory. Deploy order is now **migrate only** — no command. See `deploy-log.md`.                                                             | —                                                                                    |

**Why leg 2 disappearing matters more than its own line.** The plan's argument for MEDIUM was not
"a boolean write is dangerous" — it was the WINDOW. With `default(false)` plus a command, every
campaign in the table reads OFF between `migrate` and the command finishing. An approval landing in
that window drives a live assignment into `completed_on_approval`, which no application path can
leave (`cancel()` refuses a terminal; `markPosted()` only accepts `approved`). Minutes of exposure,
an unrecoverable row. Defaulting the column ON removes the window entirely, and removes the need for
a data mutation at all.

What is still **not** in this chunk's risk: no destructive DDL, no type narrowing, no deletion path,
no flag armed by default, no historical notification or audit row touched, no board row deleted.

---

## 2. The D3 correction, as ratified

The plan's kickoff asked for the AH-042 accept-pattern **verbatim**: outer transaction,
`withoutGlobalScope` campaign read, `['auto_advanced' => true]` context, distinct verb. Three of
those four shipped. The `withoutGlobalScope` read did not, and the read pass argued it should not:

AH-042's accept path runs on the **creator's** session, where the campaign is not reachable through
the tenant scope, so the bypass is load-bearing there. This chunk's chain runs inside
`CampaignAssignmentReviewController::review()`, on the **agency's** session, with the campaign
already resolved by the route binding and already authorised. Copying the bypass would have been
cargo — a scope disabled for no reason anyone reading it later could reconstruct. Ratified at
plan-pause.

The chain itself is in one transaction, and the transaction is the one that was already there:

```
DB::transaction(function () { … });   // the pre-existing review transaction
  ├─ the draft's review_status / reviewed_at / review_feedback
  ├─ machine->approve(...)                    → assignment.draft_approved
  └─ machine->completeOnApproval(...)         → assignment.completed_on_approval   [only when OFF]
```

Both audit rows or neither. The rollback is pinned by a test that makes the second transition throw
and then asserts the assignment is still `draft_submitted` **and** the draft still unreviewed — no
approved-but-half-completed state, and no draft carrying a review its assignment never got.

---

## 3. The Q1 two-layer design, and its §5.34 set

Q1 was ruled to (a) **plus** the product default. The two are different layers and they never have
to agree:

| Layer                   | Value   | What it governs                                                                                                                                      |
| ----------------------- | ------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| **DB column default**   | `true`  | The SAFETY FLOOR. Any path that does not name the field — a direct API POST, a factory, a seeder, a future import — falls back to today's lifecycle. |
| **Create form default** | `false` | The PRODUCT decision. A campaign created through the UI hands off at approval. The form always sends the value explicitly.                           |

They do not conflict precisely because the form always names it: the column default only ever
governs the paths that don't. Recorded here because a reader finding `default(true)` in the
migration and `creator_posts_content: false` in `emptyForm()` would otherwise be entitled to think
one of them is a bug.

**The §5.34 set** — `CampaignPostingToggleTest.php`, disjoint and complete on the write path:

| Case                          | Expected         | Why it is in the set                                                     |
| ----------------------------- | ---------------- | ------------------------------------------------------------------------ |
| Form sends `false`            | persists `false` | the product default actually lands                                       |
| Form sends `true`             | persists `true`  | the flip works in both directions                                        |
| Key absent from the POST      | reads `true`     | **the safety floor** — the ruling's own §5.34 case                       |
| Raw DB insert, column unnamed | reads `true`     | the floor is in the SCHEMA, not only in the controller                   |
| Factory default               | `true`           | every pre-existing test keeps the lifecycle it was written against       |
| PATCH omits the key           | unchanged        | a Settings save about something else does not silently reset the posture |
| Non-boolean value             | 422              | the validation edge                                                      |
| Create and update             | audited          | "who turned it off, and when" is answerable                              |

The absent-key case is also what catches the AH-054 trap: `store()`'s array is a **whitelist**, not
`$fillable`, so a field missing from it validates, returns 201 and never persists. Verified present.

---

## 4. The D5 listener table — verified listener-by-listener

The plan's table, re-checked against the built code. Every row held; the two "express" rows are the
only ones that gained a line of code.

| #   | Listener                            | Planned                        | Built                                                                                       | §5.2 split                                                                                           |
| --- | ----------------------------------- | ------------------------------ | ------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| 1   | `CreateAssignmentAvailabilityBlock` | no change, silent no-op        | **unchanged** — gated on `AssignmentAccepted`                                               | the new verb creates no block                                                                        |
| 2   | `DispatchPostedContentVerification` | no change, no guard needed     | **unchanged** — gated on `AssignmentPostedByCreator`, so excluded by construction           | `Bus::fake()` + assert `VerifyPostedContentJob` **not** dispatched on the OFF approval               |
| 3   | `SendAssignmentNotifications`       | express, new arm               | **new arm** → `notifyCreatorOfCompletionOnApproval()`; new `NotificationType`, new mailable | mail dispatched leg + in-app row leg, and the Q3 pair below                                          |
| 4   | `CreateMessageThread`               | no change, silent no-op        | **unchanged** — gated on `AssignmentInvited`                                                | covered by the audit-verb assertions                                                                 |
| 5   | `WriteSystemMessage`                | express, distinct truthful key | **allowlist 10 → 11**, `assignment.completed_on_approval` with its own line ×24             | the closing line is asserted present and asserted **different** from the approval line               |
| 6   | `CreateBoardCard`                   | no change, silent no-op        | **unchanged** — gated on `AssignmentInvited`                                                | —                                                                                                    |
| 7   | `BoardAutomationListener`           | **no automation seeded**       | **none seeded**; the default set stays at 10                                                | the card is asserted to sit exactly where the approval put it (Approved), not moved and not orphaned |

The verb-gated finding held: **nothing had to be suppressed.** Five listeners are untouched because
their own gate already excludes a verb they have never seen, and two opt in deliberately. That is
the AH-043 lesson producing the outcome it was written to produce.

### The Q3 one-mail proof, both directions

Two in-app rows, one email. The mechanism is the AH-068 one: the controller threads
`completes_on_approval` into the **approve** transition's context, and
`SendAssignmentNotifications` reads it off the event — the listener stays query-free and the audit
row records the flag.

| Direction                            | In-app rows | Emails | Pinned by                                                                                             |
| ------------------------------------ | ----------- | ------ | ----------------------------------------------------------------------------------------------------- |
| Toggle **OFF** — the new path        | **2**       | **1**  | `DraftReviewedMail` asserted **not** queued; `AssignmentCompletedOnApprovalMail` asserted queued once |
| Toggle **ON** — every campaign today | **1**       | **1**  | `DraftReviewedMail` asserted queued once; the completion mail asserted **not** queued                 |

The second row is the one that protects production: the flag's ABSENCE has to change nothing, and
that is a separate assertion rather than an inference from the first row.

---

## 5. D6 — the render filter, and the zero-deletion proof

### The mechanism, and why it is not a name match

A hidden column is derived from the **automations that target it**, never from the string
`"Posted"`:

> a column is hidden when the posting family (`assignment.posted_by_creator`,
> `assignment.live_verified`, `assignment.manually_verified`) targets it **and nothing else does**.

Two consequences, both wanted and both pinned:

- **It survives a rename.** An agency that renamed Posted still gets the hand-off board.
  (`hides the posting column after it has been RENAMED`)
- **It can never hide somebody else's destination.** Approved is targeted by the draft-approved verb
  as well as the resubmit verb, so it stays — even though a resubmit is unreachable on a hand-off
  campaign. (`keeps the Approved column even though its resubmit verb is unreachable here`)

Automations targeting a hidden column are dropped from the payload alongside it, which answers the
plan's "verify the null-target/silent-no-op reality covers it or add a guard": rather than rendering
a rule that points at nothing, the rule leaves with its target.

**Cards are deliberately NOT filtered.** A card can only be on a posting column if it reached
`posted`, which a hand-off campaign's assignment cannot do and which the refuse-flip prevents
retroactively. Filtering cards would hide that invariant being violated instead of surfacing it, and
the SPA's `cardsByColumn` already degrades safely by dropping cards whose column is absent.

### The zero-deletion proof

Not prose — three assertions and a round trip.

| Claim                                       | Evidence                                                                         |
| ------------------------------------------- | -------------------------------------------------------------------------------- |
| The column row survives the filter          | `board_columns` count is **7** after a hand-off GET; the `Posted` row `exists()` |
| Its automations survive                     | `board_automations` count is **10** after the same GET                           |
| No card is deleted by the refusal           | `board_cards` count unchanged after a refused flip                               |
| The board is restored, not rebuilt          | toggle back ON → **7 columns**, `Posted` among them, same request path           |
| Reset re-seeds and the filter still applies | `POST …/board/reset-to-defaults` on a hand-off campaign returns **6** columns    |

The board-reset posture the plan proposed as "likely moot" is confirmed moot: reset re-seeds the
default seven and the same resource filters the response, so the two features cannot drift.

### The refuse-flip (Q4)

422 `campaign.posting_cards_present` while cards sit on a posting-only column. **Creator display
names in the message; count and assignment/card ULIDs in `meta`** — Q4 as ruled.

The refusal derives its "posting-only" set with the _same rule_ the render filter uses. Two
different rules here would be a bug generator: the set refused and the set hidden have to be
identical or the refusal is protecting the wrong cards.

Negatives, all pinned: flip to **ON** is never refused however many cards are posted; a campaign
with **no board** flips freely (the common case — configuring before anyone is invited); and a PATCH
that never mentions the toggle is not refused because cards happen to be posted.

On the SPA, the refusal is shown verbatim (no `[code]` prefix — the server sentence is written to be
read, in the caller's own language) and the switch is put back where the server still has it.

---

## 6. D8 + Q5 — `posting_due_at`, fixed on both sides, labelled as prophylaxis

**This is prophylaxis, and the review says so plainly rather than selling a bug fix.** The current
blast radius of the unguarded sweep is a column stamp (`posting_overdue_flagged_at`) and an audit
row: **no automation is seeded against the posting-overdue verb**, so nothing moves and nobody is
told. What these guards stop is that becoming a false "you are late to post" the day somebody maps
the verb.

Both halves shipped, because they cover different populations:

- **(b), the writer.** `CampaignInvitationService::invite()` stamps `posting_due_at` only when the
  campaign expects posting. A deadline that was never written cannot be missed by any future reader.
  The **draft** deadline is untouched: a hand-off campaign still wants its draft on time.
- **(a), the sweep.** `OverdueScanService` narrows the posting sweep to campaigns with
  `creator_posts_content = true`. This is what covers the rows the writer fix cannot reach —
  assignments invited while the toggle was ON, on a campaign since turned OFF.

The sweep reads through to the **campaign**, not to the assignment's status. A status-based
exclusion would have covered only assignments already approved and left an OFF campaign's
`contracted` assignment flagged for a post it will never be asked to make. Pinned by exactly that
case.

One property worth naming: an excluded assignment is **not flagged either**. The one-shot marker
stays null, so if the toggle goes back on the deadline is live again and the sweep can still catch
it. A "skip" that stamped the marker would have silently spent the assignment's single overdue.

---

## 7. Q2 — the posting endpoint, guarded from the other side

422 `assignment.posting_not_required` when a creator posts to a hand-off campaign.

In practice the existing `status !== approved` check already catches this, because an approval on a
hand-off campaign completes the assignment. The guard covers the sliver the status check cannot: an
assignment approved while the toggle was ON, on a campaign turned OFF before the creator posted.
Let that through and the card lands on a column the board no longer renders — a row that is present
in the database and invisible on screen.

Pinned as one negative with a full no-side-effect assertion (no `campaign_posted_content` row, no
transition, no audit verb) plus the inert-on-ON positive. The surface mirrors it: `canSubmitPosted`
reads the toggle as well as the status, so the creator is never offered an action the server
refuses. The meta flag defaults to `true` when absent — showing the post step and letting the server
refuse is safer than hiding a step a posting campaign genuinely needs.

**This makes the invisible-card state unreachable from both directions** — the agency cannot flip a
campaign into it, and the creator cannot post into it.

---

## 8. D7 — the banner is a third branch, and never claims verification

`isCompleteOnApproval` sits beside `isVerified`, deliberately not inside it. Same shape, same
colour, different claim — and the claim is the part that has to be right. The verified banner says
the post was found and confirmed live; nothing was posted here and nothing was verified.

> "Your draft has been approved. This assignment is complete — no further action is needed."

The copy assertion is a negative and it is explicit: the banner's text must not contain `verified`,
`verify`, or **`post`**. Mutating the en string to borrow the verified sentence reddens it
([§10](#10-break-reverts)).

The other half of D7 — "post/verify affordances never render on OFF campaigns" — is a separate
assertion rather than an inference: with the completion banner showing, the post form, the
awaiting-verification alert, the in-place resubmit form and the draft form are all asserted absent.
A page showing both would be telling the creator the work is done and asking them to do more in the
same breath.

---

## 9. D4 — the 17th case, handled loudly

| Consumer              | Assignment                                                 | Tripwire                                                 |
| --------------------- | ---------------------------------------------------------- | -------------------------------------------------------- |
| `AssignmentStatus`    | 17th case, `isTerminal()` yes                              | `CampaignEnumsTest` catalogue pins the exact 17-case set |
| `isPaymentEligible()` | **yes** — approval IS this campaign type's completion      | the payment-eligible set is pinned by value              |
| `JobLifecycleState`   | family = `Completed`                                       | `JobLifecycleStateTest` (8 / 6 / 3, disjoint + complete) |
| `AuditAction`         | `assignment.completed_on_approval`                         | `AuditActionEnumTest` catalogue                          |
| `NotificationType`    | `assignment.completed_on_approval`                         | `NotificationTypeEnumTest` + both FE parity tripwires    |
| `BoardCardService`    | maps to the Approved column's event key                    | the card is asserted not to move past approval           |
| TS union + api-client | added                                                      | `vue-tsc` across three packages                          |
| Status label i18n ×24 | `app.campaigns.assignmentStatus` ×24                       | `i18n-locale-parity` (en SOT, all namespaces)            |
| Admin SPA             | **no consumer** — the admin SPA reads no assignment status | verified by search, recorded rather than assumed         |

**Q7, recorded as the ruling requires.** `completed_on_approval` is payment-eligible, and the
consequence is intended: **Sprint 10 inherits, in writing, that a payable assignment on this
campaign type may have no `campaign_posted_content` row at all.** Any payment path that reaches for
the posted-content row to build a payout reference must treat its absence as normal for this status,
not as a data error. A one-line pointer to this paragraph is in `tech-debt.md`.

---

## 10. Break-reverts

Eight mutations, each applied, each reddening the test it was aimed at, each restored **byte for
byte with a SHA-256 match** (not `git checkout` — the work was uncommitted, so the restore is from a
byte copy taken immediately before the mutation).

| #   | Mutation                                                   | File                                   | Reddened                                                           | SHA-256 restore |
| --- | ---------------------------------------------------------- | -------------------------------------- | ------------------------------------------------------------------ | --------------- |
| 1   | enum case added with **no** `JobLifecycleState` family arm | `JobLifecycleState.php`                | **PHPStan, before any test ran** — the exhaustiveness pin          | identical       |
| 2   | `hiddenColumnIds()` always empty (render filter removed)   | `BoardResource.php`                    | the hand-off column assertion + the rename case                    | identical       |
| 3   | refuse-flip finds no stranded cards                        | `CampaignController.php`               | the 422 and the zero-deletion negative                             | identical       |
| 4   | Q2 posting-endpoint guard removed                          | `CreatorAssignmentDraftController.php` | the creator-post refusal + its no-side-effect assertions           | identical       |
| 5   | D8 sweep exclusion ignored                                 | `OverdueScanService.php`               | the hand-off overdue negative (the ON case correctly stayed green) | identical       |
| 6   | Q5b writer fix removed (`posting_due_at` stamped on OFF)   | `CampaignInvitationService.php`        | the writer negative (the ON case correctly stayed green)           | identical       |
| 7   | banner copy replaced with a verification claim             | `en/creator.json`                      | **the copy assertion** — the reason it exists                      | identical       |
| 8   | `canSubmitPosted` stops reading the toggle                 | `CreatorAssignmentDetailPage.vue`      | the Q2 surface mirror                                              | identical       |

Mutations 5 and 6 are worth a note: in both, the toggle-ON sibling test stayed **green** under the
mutation. That is the §5.34 pair doing its job — the guard is narrow, and the test that proves it is
narrow is a different test from the one that proves it works.

---

## 11. The E2E leg, and the limit it does not hide

`hand-off-at-approval-lifecycle.spec.ts` — both roles, one session:

1. the board renders its Posted column while posting is ON (the BEFORE half, without which step 2
   could pass on a board that never had the column);
2. the agency turns posting off **through the real Settings switch** — this is D1's write path
   covered end to end, not a fixture;
3. the board immediately stops rendering the Posted column, and only that column;
4. the creator submits a **link-only** draft (media uploads are a different chunk's apparatus and a
   different chunk's flakiness);
5. the agency approves — one click;
6. the creator sees the completion banner **and** no post form, no awaiting-verification alert, and
   not the verified banner.

**Flaky-10 audited: 10/10 green.**

**The limit, stated rather than glossed.** This leg covers the toggle-**OFF** path only. The posting
path — approve → post → verify — remains the named pre-existing E2E gap it was before this chunk.
AH-069 does not close it and does not pretend to; what changed is that the OFF path now has coverage
the ON path still lacks.

The leg starts at a CONTRACTED assignment via a new test-helper
(`POST /_test/agencies/{agency}/contracted-assignment`) because list → apply → offer → accept is
already walked end to end by `jobs-board-full-lifecycle`. The helper deliberately leaves the toggle
**ON** — pre-setting it would have deleted step 2, the `CreateListableCampaignController` reasoning
verbatim — and that property is itself pinned by a feature test.

---

## 12. Recorded as future product options, not built

- **Q6 — the card face.** No automation is seeded for the new verb, confirmed. A card completed at
  approval and a card merely approved therefore look identical on the board. Distinguishing them is
  a **product** question, and the shape it would take already exists: the AH-068 chip precedent —
  a status chip that says what the card's state actually is, rather than a new column or a new
  automation. Logged, not built.
- **The overdue marker reset.** Unchanged from its P1 posture: the marker does not reset when a
  deadline is cleared or extended. Already in `tech-debt.md` from Sprint 12.

---

## 13. Gate board

Run at the final code head, after the last docs edit that touches code paths.

| Gate                  | Result                                                                      |
| --------------------- | --------------------------------------------------------------------------- |
| Backend (Pest)        | **2522 passed, 1 skipped** · 9368 assertions                                |
| PHPStan (level max)   | **0 errors**                                                                |
| Pint                  | **passed**                                                                  |
| `apps/main` (Vitest)  | **1500 passed / 154 files**                                                 |
| `apps/admin` (Vitest) | **449 passed / 53 files**                                                   |
| `packages/api-client` | **204 passed / 9 files**                                                    |
| Typecheck (all three) | **clean**                                                                   |
| ESLint                | **0 errors** (2 pre-existing `vue/no-v-html` warnings, untouched by AH-069) |
| New E2E leg, flaky-10 | **10/10 green**                                                             |
| Full Playwright       | **28/28 passed, first pass** (8.1m, no re-runs)                             |

The full E2E suite is claimed here as the **standing pre-push gate, not as coverage of this chunk**;
the coverage claim is the one leg in [§11](#11-the-e2e-leg-and-the-limit-it-does-not-hide).

Two notes on the E2E line, since 28/28 first-pass is not the recent norm. The suite grew from 27 to
28 — the added test is this chunk's leg. And `2fa-enrollment-and-sign-in`, the cold-start flake that
AH-064, AH-066 and AH-068 each recorded and each had to re-run in isolation, passed **first** this
time (27.1s). That is one clean observation, not a fix: nothing in AH-069 touches enrollment, so the
flake should be treated as still present until a run that was trying to provoke it fails to.

---

## 14. What this chunk deliberately did not do

- **No backfill command.** Q1(a) removed the need; the column default is the write the command would
  have performed.
- **No `withoutGlobalScope` in the chain.** The route-bound campaign is in hand; the bypass would
  have been cargo.
- **No widened `isVerified`.** A third branch, because the banner must never claim verification.
- **No card filtering on the board.** Hiding a card would hide an invariant violation instead of
  surfacing it.
- **No Sprint-10 payment wiring.** Eligibility now; payment flows stay Stripe-blocked. The Q7
  consequence is recorded in [§9](#9-d4--the-17th-case-handled-loudly) and pointed at from
  `tech-debt.md`.
- **No posting-path E2E.** Named as the standing gap in [§11](#11-the-e2e-leg-and-the-limit-it-does-not-hide).
