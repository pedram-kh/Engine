# Jobs Board arc, chunk 4 — agency applications: the Applications tab, accept, reject, terminal auto-reject (AH-058)

- **Status:** **Closed — approved.** Nine commits carrying code: `0abba72`, `78c2dd8`, `86a44a4`,
  `5f6486c`, `af0f343`, `d33df9e`, `ef195f8`, `8ff9985`, `a1c66ab`, plus `9a330d6` (this review and
  the AH-058 log entry) and the close commit that flipped this line. Pushed 2026-07-28 with the two
  held chunk-4 docs commits (`895f28a` inventory, `685991c` plan) riding along — thirteen in total.
- **Verdict:** independent review complete: **D1–D8 and C1–C7 verified as built**; **all ten
  mutations confirmed load-bearing**, including the **C1 ordering enforcement** (moving the accept
  emission inside the transaction reds the rollback test's `Mail::assertNothingQueued()`, so the
  after-commit ordering is enforced by a test rather than by a code-reading claim) and the **D3b
  non-vacuity pair** (the hook dropped from each `store()` branch reds its own case, which is what
  keeps the byte-identity pin from passing on a hook that does nothing); the **C5 reinterpretation
  accepted as a correct improvement on the ruling** — a mail flag must not gate database truth, so
  re-reading the flag at emission time inside the notifier rather than as an early return from
  `handle()` is the right seam, and an early return would have left a campaign closed under a
  flag-OFF window with its applications pending forever; the **§5.34 byte-identity pin**, the **D3
  collision matrix** and the **no-exposure keysets** all green; **production posture MEDIUM
  confirmed**, with the T+0-provably-zero population as its primary containment (no migration, no
  listed campaign, no application to answer at deploy); full **Playwright 26/26** including the
  agency-side leg.
- **Date:** 2026-07-28
- **Provenance:** built by Cursor against the ratified plan and its rulings; reviewed and closed by
  Claude.
- **Ratified plan:** [`docs/reviews/jobs-board-c4-plan.md`](jobs-board-c4-plan.md).
  **Inventory:** [`docs/reviews/jobs-board-c4-inventory.md`](jobs-board-c4-inventory.md).
  **Binds to:** [`docs/reviews/jobs-board-c3-review.md`](jobs-board-c3-review.md) (the closed chunk-3
  review — the applications table, the visibility predicate, the applicant-count semantics and the
  re-apply refusal codes this chunk consumes and must not disturb).
- **Gate board:** backend **2338 passed / 1 skipped** (8587 assertions), PHPStan level max **0
  errors**, Pint clean; `apps/main` **1319 passed** / 141 files, `apps/admin` **449**, api-client
  **204**; three typechecks clean, ESLint 0 errors; both parity specs green; **full Playwright 26/26
  in 4.7m** including the new agency-side leg. Full table in
  [Gate board](#gate-board--full-at-final-head).
- **Ten mutations executed and reverted** — one per claim that could otherwise be read rather than
  proven. Table in [Break-reverts](#break-reverts--ten-mutations-verbatim).

---

## What shipped

The agency half of applications, which is what makes chunk 3 mean anything. An agency now **reads**
the applications on a campaign, and **answers** them.

- **The Applications tab** — eighth tab on the campaign detail, lazy, pending-first, with a badge
  counting **pending only**. Per row: the applicant at roster level (display name, avatar), their
  note, when they applied, and the status.
- **Accept** opens a real offer form and creates a **standard invitation**. The applicant lands at
  `invited` and the whole existing machinery takes over — thread, board card, board automation,
  offer/accept/contract-gate/drafts — and they can still **decline**. Applying is not a contract.
- **Reject** is terminal, pending-only, and confirmed in a dialog. The creator is told they were not
  selected, in a kind generic copy that carries no agency reason, because none is collected.
- **Terminal auto-reject** — a campaign that becomes `completed` or `cancelled` answers whatever was
  still pending, with a campaign-closed variant of the same rejected notification.
- **The application vocabulary chunk 3 deferred** — `campaign_application.submitted` (→ the agency's
  admins and managers), `.accepted` and `.rejected` (→ the creator), all three dual-emitting in-app
  plus a queued localized mail, behind one new default-OFF flag on the **mail leg only**.
- **The D7 bridge** — an accepted creator is carried from the job page to the offer waiting for them
  instead of being left on a page that says "applied".

**No migration.** Chunk 3's `campaign_applications` table, its `responded_at` column and its
`idx_applications_agency_status` index are the whole storage this chunk needs.

Chunk 5 owns the full-lifecycle cross-role Playwright spec and the arc's close-out.

### Commit split, and why

Nine commits, split by **surface and risk**, not by sub-step:

| Commit    | Contents                                                                                                     |
| --------- | ------------------------------------------------------------------------------------------------------------ |
| `0abba72` | S1 — `AssignmentOffer` + `CampaignInvitationService` extracted from `store()`. **Pure refactor.**            |
| `78c2dd8` | S2 — the vocabulary, the flag, the notifier, three mailables, i18n ×24 both sides, the `submitted` emission. |
| `86a44a4` | S3–S5 — the three endpoints: list, accept, reject, plus the shared offer-validation trait.                   |
| `5f6486c` | S6 — D3b. **The one touch on the live invite path**, alone, so it is readable alone.                         |
| `af0f343` | S7 — the terminal flip detector and its queued job.                                                          |
| `d33df9e` | S8 — api-client types, `campaigns.api`, `OfferFieldsForm` extraction, the tab and its two dialogs.           |
| `ef195f8` | PHPStan fixes on the new test files (found at the S9 gate; test-only).                                       |
| `8ff9985` | S9 — the D7 bridge, backend and SPA.                                                                         |
| `a1c66ab` | S10 — the Playwright leg and its `_test` helper.                                                             |

The split's one deliberate feature is **`5f6486c` standing alone**. D3b is the only change in this
chunk that alters behaviour on a path the agency already uses every day, and a reviewer who wants to
read exactly that and nothing else should be able to `git show` one commit. The pure refactor is
first and separate for the same reason in reverse: if a downstream commit broke an invite, the
refactor is provably not why, because it changed no test.

`ef195f8` is a gate fix, not a finding: PHPStan level max flagged ten nullable-array-offset and
generic-type issues in the **new test files** at S9. Test-only, no production line touched. It is
its own commit rather than amended in because it crosses three earlier commits' files.

---

## Per-decision evidence

### D1 — Applications are a TAB, and the annotation the kickoff asked for

**The claim being annotated.** Chunk 3's migration docblock says the denormalized `agency_id` exists
so "chunk 4's **agency-side board column** scopes for free"
(`2026_07_27_110000_create_campaign_applications_table.php:26`), and again that
`idx_applications_agency_status` is "added now for chunk 4's **agency-side board column**" (`:49`).
Chunk 4 did not build a board column. Per §5.32 this is a **recorded reinterpretation**, and the
evidence is structural, not aesthetic:

- **A board card IS an assignment, at three layers.** `board_cards.assignment_id` is `NOT NULL`,
  `UNIQUE`, and `ON DELETE CASCADE`. An applicant has no assignment — that is the entire point of an
  application — so an "Applied" column would need a card with no assignment, which the schema
  forbids three times over.
- **§4.4's invariant cannot express accept.** Dragging a card is consequence-free by design; the
  board's automations react to assignment transitions, they do not cause them. An accept is a
  cross-table transaction that creates an assignment and sends an offer. Making that a drag would
  either break the invariant or make the column a lie.

**Nothing chunk 3 shipped is wasted.** Both artefacts the docblock justifies serve the tab
identically: `idx_applications_agency_status` is exactly the index a status-scoped per-agency list
wants, and the denormalized `agency_id` is what lets the auto-reject job re-impose tenancy in a
worker that has none (see C5 below — it turned out to be load-bearing for a use the docblock did not
anticipate). The docblock's **mechanism** word was wrong; its **reasoning** was right.

**And the board handles post-accept for free**, asserted rather than rebuilt: after an accept, a
`board_cards` row exists for the new assignment **and sits in the `Invited` column**
(`CampaignApplicationAcceptTest.php:162`) — `CreateBoardCard` fires on `assignment.invited` and the
seeded automation moves the card, both pre-existing. The E2E leg then watches the same thing happen in
a real browser.

**The tab itself.** Eighth tab, lazily mounted on `tab === 'applications'` per the board/drafts
pattern; pending-first then newest; per-row identity at roster level. The badge counts **pending
only**, through its own scoped count (`CampaignApplicationController::pendingCount():436`), never
`applications_count` — the I6.2 conflation warning, pinned twice: once that the badge counts only
pending, once that **the status filter narrows the page but not the badge**
(`CampaignApplicationListTest.php:143`, `:162`).

**No new exposure, stated as a keyset rather than as prose.** The row's attribute keyset is asserted
exactly — `status`, `note`, `applied_at`, `responded_at`, `creator` — and the nested creator object
exactly `id`, `display_name`, `avatar_url` (`CampaignApplicationListTest.php:75`). A second test
proves the applicant's email does not appear **anywhere in the response body**
(`:101`). The reasoning is worth stating because it is the whole answer to "what may an agency see of
an applicant": leg 3 of chunk 3's visibility predicate is `permitsMessaging()`, so **every applicant
is rostered by definition**, and the same primitive backs `canSeeContactDetails()`. The agency can
already read this creator's contact details on the roster page. The tab does not add a second
surface for it.

### D2 — Accept creates a standard invitation, through a shared service

**S1 extracted first, as a pure refactor.** `AssignmentOffer` (a readonly value object with a
`fromValidated()` factory) plus `CampaignInvitationService::invite()`, which owns exactly what
`store()` hand-wrote: the `campaign_assignments` create, the hand-written `assignment.invited` audit
row, and the hand-dispatched `AssignmentTransitioned(from = to = invited)`. The comment explaining
_why_ the endpoint owns them — it is the reason the board and thread listeners fire at all — moved
with the code. The refactor's gate was **that no test changed**: `CampaignAssignmentInviteTest`,
`CampaignAssignmentStateMachineTest`, `CreateBoardCardTest` and `BoardAutomationServiceTest` all
stayed green untouched.

**The gate list, as built** (`CampaignApplicationController::accept():158`):

| Leg                         | Disposition                                          |
| --------------------------- | ---------------------------------------------------- |
| `assertBelongsToAgency()`   | first, `:170`                                        |
| `Gate::authorize('invite')` | `:171` — execute tier (Q4)                           |
| pending-only source guard   | 422, `not_pending`                                   |
| creator still approved      | 422                                                  |
| agency-wide hard blacklist  | **kept**, 422, `:222`                                |
| availability conflict       | 409 + `acknowledged` re-submit                       |
| the D3 pair matrix          | `:243` for the refusal branch                        |
| `is_discoverable`           | **DROPPED**, with the AH-051 ruling quoted at `:143` |

The dropped leg is worth restating because it is a deliberate divergence from the invite path:
browsing preference is not eligibility, and an applicant who has since hidden themselves from
discovery must not 404 on their own application. Both directions are proven by mutation
(break-reverts 5 and 6).

**`invited_by_user_id` is the accepting user**, and the offer validation is shared with the invite
path rather than restated: `ValidatesAssignmentOffer` is one trait consumed by both
`InviteAssignmentRequest` and `AcceptApplicationRequest`, so fee/currency/attachment rules cannot
drift. `AcceptApplicationRequest` deliberately has **no `creator_id`** — the applicant is the
application's own creator, asserted server-side (`CampaignApplicationAcceptTest.php:525`) and
client-side (`AcceptApplicationDialog.spec.ts:188`).

**Byte-indistinguishable downstream, and still declinable.** The happy-path test's own name is the
claim: "flips the application, creates an INVITED assignment, **and can still be declined**"
(`:115`). Three separate tests then pin the downstream facts — both audit rows (`:146`), the event
dispatched once with `from = to = invited` (`:180`), and the board card in its `Invited` column
(`:162`).

### D3 — The pair-collision matrix

One test per branch, all in `CampaignApplicationAcceptTest`:

| Pre-existing assignment | Outcome                                                          | Test                        |
| ----------------------- | ---------------------------------------------------------------- | --------------------------- |
| none                    | create at `invited`, 201                                         | `:280`                      |
| `declined`              | the AH-035 `reofferAfterDecline()` edge **on the same row**, 200 | `:289`                      |
| any other status        | 422 `application.already_engaged`, **naming the engagement**     | `:318` (dataset per status) |
| application not pending | 422, no second assignment, `responded_at` unmoved                | `:352`, `:374`              |

The declined branch is the one that matters: re-offering through the machine rather than inserting a
second row is what keeps the unique `(campaign, creator)` pair intact and what makes an
accept-from-application indistinguishable from AH-035's own re-invite.

### D3b — Invite-path convergence, the live-path touch

Built exactly as C4/Q3 ruled: `settlePendingApplication(...)` is called from **both** `store()`
branches — the create path (`CampaignAssignmentController.php:252`) and the **declined re-offer**
path (`:206`) — and `store()` gained the `DB::transaction()` it did not have before (`:192`, `:249`).
The emission runs after the transaction returns, through one shared helper
(`emitSettledApplication():377`).

The behavioural delta is named rather than slipped in, in the method's own docblock (`:106`): a
mid-flight failure that previously left an orphaned assignment row now leaves none. That is a strict
improvement, and it is also the reason the byte-identity pin is asserted field-by-field rather than
by "it still works" — see [the §5.34 pin](#the-534-byte-identity-pin-d3b) below.

The idempotent no-op branch settles nothing, deliberately: if `store()` makes no offer, there is
nothing to tell the applicant they were accepted for
(`AssignmentInviteConvergenceTest.php:277`). An already-terminal application is not re-answered
(`:251`). And in the bulk loop — N invites, one of which carries a pending application — the other
N−1 are untouched (`:333`).

### D4 — Reject

Pending-only, no reason column, no migration. The source guard is hand-written against
`isTerminal()`, and a second reject is 422 **without a second `responded_at` write**
(`CampaignApplicationRejectTest.php:172`, §5.6). The pending-only guard cuts both ways: an
**accepted** application cannot be rejected either (`:199`).

**No agency reason exists anywhere** — not in the request, not in a column, not in the mail, not in
the in-app copy. Asserted three times: the audit row carries the cause and no reason (`:106`), the
mailable carries no rejection detail (`ApplicationMailTest.php:148`), and the creator-facing copy is
the same kind generic sentence either way. The audit row plus its actor is the record. The three-way
argument was heard at kickoff; this is the only version that keeps the chunk migration-free and does
not put an agency's private reasoning in a creator's inbox.

**The no-re-apply composition is asserted end-to-end across the two chunks** (`:219`): after a
reject, the creator's own apply endpoint refuses with `job.application_rejected`. That seam is the
reason the terminal row is retained rather than deleted, so it is worth a test that crosses the
chunk boundary rather than trusting each side separately.

### D5 — Terminal auto-reject

**The flip detector, in its own precedent's shape.** Three lines in `CampaignController::update()`:
`$wasTerminal = $campaign->status->isTerminal()` captured pre-fill beside `$wasListed` (`:175`,
`:177`), and after the save, `AutoRejectPendingApplicationsJob::dispatch($campaign->id)` when the
campaign has just become terminal (`:212`). Post-save placement, plain `dispatch()`, for the same
reason AH-056 wrote down: the worker must never read a campaign whose new status is not yet
committed. No `CampaignStatusChanged` event — that would reverse the recorded AH-056 ruling without
new evidence.

`CampaignStatus::isTerminal()` is the one small addition (`completed`, `cancelled`), mirroring the
helper the application status enum already had.

**The job is where the idempotency lives.** Its `status = pending` filter is executed **in the
worker**, never in the dispatcher (`AutoRejectPendingApplicationsJob.php:104`), so
`active → cancelled → active → cancelled` re-runs find nothing to do. Terminality is **re-checked**
against the current campaign (`:94`), so a campaign re-opened between the flip and the worker keeps
its pending applications — the reason to reject them no longer exists. `cursor()`-chunked, one small
transaction per row (`:110`), emission per row after that row's own commit (`:117`), so one row's
write failure cannot hold another row's notice hostage. Thirteen tests, including a no-op when the
campaign was deleted, and an explicit test that the job reaches its rows **with no ambient tenant at
all** (`AutoRejectPendingApplicationsTest.php:320`).

**One type, cause-parameterized.** The auto-reject reuses `campaign_application.rejected` with
`data.cause = 'campaign_closed'` (Q8), against `'agency_rejected'` from the HTTP path. Same subject,
different body, selected in the mailable — one sentence of difference is not worth doubling 24
locales of copy or the vocabulary.

### D6 — Vocabulary, the group split, and the flag

Three `NotificationType` cases on the same strings as their `AuditAction` siblings
(`campaign_application.submitted` already shipped as an audit action in chunk 3; the accepted and
rejected pair are new on both enums). The one-vocabulary tie is proved at runtime by
`NotificationType::auditAction()`.

**Q5's split, built.** `jobs_board` is a new `NotificationPreferenceGroup` with its own
`PREFERENCE_GROUP_ORDER` entry, and `campaign.job_posted` **moved into it** from `assignment` —
honouring the trigger chunk 3 wrote into the enum ("the group splits when a SECOND jobs-board type
exists to split with") rather than re-arguing it. Four toggles under an "Assignments" heading, one of
them agency-facing, was the outcome avoided.

**Directions.** `submitted` → `Agency::notifiableMembers()`, which is admin + manager. Staff are
excluded, and that asymmetry is recorded as **pre-existing, not fixed here**: a staff member may
`invite` and may now accept and reject, but is not notified when a creator applies. The exclusion
lives in `notifiableMembers()`, one shared primitive across the platform, and narrowing this
chunk's recipients differently from every other agency notification would be the worse bug.
`accepted` / `rejected` → the creator.

**The flag.** `application_notifications_enabled`, default OFF, `NAME` const +
`default(): Closure`, registered in `CreatorsServiceProvider` **and** in
`AdminFeatureFlagController::FLAGS` — the AH-056 tinker-only lesson, pinned by an HTTP arm/disarm
test rather than trusted. It is checked in exactly **one place**, inside the notifier's private
`queue()` (`CampaignApplicationNotifier.php:223`), on the mail leg only, so the HTTP paths and the
queued job cannot disagree and no call site can forget it.

**C2's phrasing, as ruled: the flag gates mail; in-app honours the recipient's own preference.** Not
"in-app always writes" — `NotificationService::notify()` returns without writing when the recipient
has switched that type off, and a test pins exactly that: a member who silenced the type in-app gets
no row, **and the flag does not override the preference**
(`ApplicationSubmittedNotificationTest.php:139`).

### D7 — The bridge

Backend: `assignment_ulid` on `CreatorJobDetailResource`, **always present, null when there is no
assignment for the pair**, resolved by a correlated subquery over `campaign_assignments`
(`CreatorJobBoardController::callerAssignmentUlidSubquery():358`) rather than inferred from
`application_status === 'accepted'` (C6). `DETAIL_KEYS` in `CreatorJobDetailTest` gained one entry —
that red was the tripwire working.

The subquery, not the status, is what makes the **degradation** honest: an accepted application whose
assignment was later cancelled renders the plain accepted notice with no link rather than linking
into a 404. Both halves are pinned, plus the cross-creator negative — the subquery never bridges to
another creator's assignment on the same campaign (proven by mutation 9).

SPA: `CreatorJobDetailPage.vue` gained the missing third branch — `acceptedNotice` with a
`viewOffer` link to `/creator/assignments/{ulid}` when the ULID is present. The list chip already
rendered "Accepted" in all 24 locales as a chunk-3 side effect and is **asserted as a regression pin,
not rebuilt** (`CreatorJobsPage.spec.ts`).

### D8 — E2E

One agency-side leg on the **desktop** project (the mobile project's `testMatch` is scoped to
`MOBILE_ONLY_SPECS` and would not have picked up a new spec):
`playwright/specs/campaign-applications.spec.ts` — seed an agency admin, sign in with a minted TOTP,
seed two pending applications, open the tab, **reject one through the confirm dialog**, **accept the
other through the offer form**, then assert both terminal states and **one card in the board's
Invited column**. Anchored on `data-test` attributes throughout, never on English copy. 13.5s in the
full run.

The seed helper is the **sibling** C3 ruled for, not an extension:
`POST /_test/agencies/{agency}/pending-applications`, agency-keyed, following
`CreateRosterCreatorsController`'s shape. Chunk 3's `CreateListedJobController` is creator-keyed and
mints its own agency with no user attached, so an agency-side spec cannot sign into it; giving it a
second mutually exclusive mode is the §5.26 smell.

---

## The plan-pause findings, as built

### C1 — every emission is after the commit, and the residual failure mode is recorded

`config/queue.php` sets `'after_commit' => false` on all four connections, so a `Mail::queue()`
issued inside an open transaction is visible to a worker **immediately**. The ruling was one
transaction for every DB write and the dual-emit after it returns. Built that way at all four
emission sites:

| Site                | Transaction                                             | Emission                                       |
| ------------------- | ------------------------------------------------------- | ---------------------------------------------- |
| apply (`submitted`) | the existing application insert                         | `$notifier->submitted(...)` after it           |
| accept              | `CampaignApplicationController.php:278`                 | `:312`, after the transaction returns          |
| reject              | `:354`                                                  | `:359`                                         |
| `store()` (D3b)     | `CampaignAssignmentController.php:192` / `:249`         | `:209` / `:255` via `emitSettledApplication()` |
| auto-reject job     | one per row, `AutoRejectPendingApplicationsJob.php:110` | `:117`, per row, after that row's own commit   |

**The inverted residual failure mode, recorded as ruled.** The ordering does not remove a failure
mode; it **exchanges** one for a strictly better one. Before: a rolled-back accept whose creator had
already been mailed that they were accepted for an invitation that does not exist. After: a committed
accept whose in-app row failed to write — the creator has a real offer waiting and did not get a
notice about it. The second is recoverable by the creator simply opening the app; the first is a lie
that cannot be recalled. That is the trade, chosen deliberately.

**AH-051's "both legs fire from one place" is preserved** — the place moved, the pairing did not.
`CampaignApplicationNotifier` holds all three dual-emits, so in-app and mail cannot be forgotten
independently at any of the four sites.

**The pre-existing counter-pattern is now a tech-debt entry, as ruled.**
`SendAssignmentNotifications` queues mail from **inside** `CampaignAssignmentStateMachine::commit()`'s
transaction — the same defect class, on the platform's single status authority. It is named,
scoped, and given the ruled trigger ("the next chunk touching the state-machine emission path"), and
it is **not** fixed here: moving the event dispatch out of `commit()` is a change on the critical path
of every assignment transition in the app and deserves its own review, not a rider on an applications
chunk. See `docs/tech-debt.md`, "Mail is queued INSIDE the state machine's transaction".

### C2 — ratified phrasing, used verbatim

"**The flag gates mail; in-app honours the recipient's own preference.**" See D6 above and
`ApplicationSubmittedNotificationTest.php:139`.

### C3 — the sibling helper

Built as ruled. See D8.

### C4 — both branches, and `store()`'s new transaction

Built as ruled. See D3b, and [the §5.34 pin](#the-534-byte-identity-pin-d3b).

### C5 — the job's tenancy posture, with one recorded reinterpretation

Three of the four consequences are literal: the job carries the campaign's **integer key**
(`__construct(private readonly int $campaignId)`); `BelongsToAgencyScope` is dropped **explicitly** on
both re-reads and re-imposed by matching the application's own denormalized `agency_id` (`:85`,
`:101`, `:102`); and `Audit::log()` receives an **explicit `agencyId`** from the row, with
`actor_type = 'system'`, which is the truth — no human pressed reject.

**The fourth is a reinterpretation, recorded.** The ruling said "in-`handle()` flag re-check". The
flag **is** re-read in the worker at emission time, but inside the notifier's `queue()` rather than as
an early return from `handle()`. The reason is a distinction the ruling's wording did not separate:
the rejections and their in-app notices are **database truth about a closed campaign**, and a
**mail** flag must not decide whether the truth gets written. An early return would mean a campaign
closed while the flag was OFF leaves its applications pending forever. The defence-in-depth property
the ruling wanted is intact and tested — a job enqueued while the flag was ON and picked up after it
was flipped OFF queues no mail, and still writes its rows
(`AutoRejectPendingApplicationsTest.php:265`). The reasoning is written into the job's docblock
(`:56-63`) so a future reader does not have to reconstruct it.

### C6 — `assignment_ulid` unconditional and assignment-derived

Built as ruled, including the cancelled-assignment degradation. See D7.

### C7 — the three hand-maintained lists

All three edited by hand and green:

- `NotificationPreferencesPage.spec.ts` — creator **9 types / 10 toggles → 11 / 12**, agency
  **4 / 5 → 5 / 6**, and the group count 3 → 4 with `jobs_board` asserted by name.
- `i18n-notifications-parity.spec.ts` — `LIVE_TYPES` **15 → 18**, plus its role-split arrays. This is
  the spec whose own docblock records that it went stale during AH-051 and let users get the
  fallback; it is hand-maintained on purpose and was updated by hand.
- `templates.spec.ts` (derived) — green, catching registration where the parity spec catches
  translation. Both are required and both were run.

`CampaignApplicationSchemaTest` — chunk 3's tripwire on the table and the unique pair — stayed
**green untouched**, which is the right result for the table's first writer.

---

## The eight questions, as answered and built

| Q   | Ruling                                                                 | Where it landed                                                                                                |
| --- | ---------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| Q1  | AH-058, `jobs-board-c4-review.md`                                      | this file; the log entry in `adhoc-changes-log.md`                                                             |
| Q2  | extract the offer form; build the dialog on the `ReinviteDialog` shape | `OfferFieldsForm.vue` (new shared child), consumed by `InviteCreatorsDialog` **and** `AcceptApplicationDialog` |
| Q3  | both `store()` branches                                                | D3b above                                                                                                      |
| Q4  | `invite` for accept **and** reject, `view` for the list                | recorded below                                                                                                 |
| Q5  | SPLIT — new `jobs_board` group                                         | D6 above                                                                                                       |
| Q6  | build as specified, state the trade                                    | recorded below                                                                                                 |
| Q7  | detail only                                                            | D7 above                                                                                                       |
| Q8  | `data.cause ∈ agency_rejected / campaign_closed`                       | D5 above; pinned in the mail spec and both notification tests                                                  |

**Q2's refactor, and what guarded it.** `InviteCreatorsDialog` had the right fields and the wrong
shape — a roster-sourced multi-select with one fee applied to every selected creator and N invite
calls in a loop. The offer fields (major-unit fee input → minor units on the wire, campaign currency
as a read-only suffix, the campaign-keyed presigned attachment pair, per-field 422 binding) moved
into `OfferFieldsForm.vue`, and `AcceptApplicationDialog` was built on the `ReinviteDialog`
one-subject shape around it. `InviteCreatorsDialog`'s existing spec guarded the refactor exactly as
predicted and needed no changes; the anti-drift argument is now structural rather than a convention —
there is one implementation of fee/currency/attachment validation and two consumers of it.

**Q4, recorded as a choice.** The list is `view`-gated; accept and reject are both
`Gate::authorize('invite')`. A fifth identical admin+manager+staff clone alongside `review`,
`message` and `attachContract` would have bought a better name and nothing else. The consequence is
asserted rather than left implicit, in three tests: **staff may accept** (`:541`), **staff may
reject** (`CampaignApplicationRejectTest.php:241`), and **staff may read the list** (`:238`) — with
the test names saying that staff is the weakest role holding `invite`, so a future tightening of the
policy reddens a test whose name explains the intent.

**Q6, recorded as asked.** `submitted` is the only one of the three whose volume is driven by
**creators** rather than by an agency action: N applications to one listing = N mails to every admin
and manager. Built as specified. The trade: at the current base (~279 creators, a few dozen listings)
this is a handful of mails; at a popular listing it is an inbox pattern that invites a mute rather
than a read. The flag contains it, and **a per-campaign application digest is the named future
trigger** — recorded here rather than in `tech-debt.md`, because the volume is speculative and an
entry for a load nobody has measured is the speculative tuning this codebase avoids. The same note
is in the flag's `feature-flags.md` row, which is where an operator watching mail volume will look.

---

## Break-reverts — ten mutations, verbatim

Every claim in this chunk that could be **read** rather than **proven** was mutated and the red
observed. Each mutation was reverted immediately after the run, and the tree was confirmed clean
against `git status --porcelain` before the next one.

### 1 — C1's ordering: the accept emission moved INSIDE the transaction

**Defect.** `$notifier->accepted(...)` moved from after `DB::transaction(...)` to inside it, in
`CampaignApplicationController::accept()`.

**Red — 3 tests.** "emits the creator notification AFTER the commit, with the assignment link",
"FLAG OFF: accept still works, still writes the in-app row, and queues no mail", and **the rollback
test**, which is the one that matters: with the emission inside, the rolled-back accept had already
queued the mail, so `Mail::assertNothingQueued()` failed.

This is the mutation the whole C1 finding exists for. Without it, the ordering is a code-reading
claim; with it, the ordering is enforced by a test that fails the moment someone tidies the emission
"closer to the write it describes".

### 2 — the accept transaction removed entirely

**Defect.** The `DB::transaction()` wrapper dropped from `accept()`, leaving the application flip and
the assignment write unwrapped.

**Red — 1 test.** The rollback test: with no transaction, the forced failure left the application
**accepted** with no assignment — the torn state that is blast-radius item 2 in the §5.40 line.

### 3 — D3b's hook dropped from `store()`'s DECLINED branch

**Defect.** `$decisions->settlePendingApplication($campaign, $creator)` replaced with `null` in the
declined re-offer branch.

**Red — 1 test.** "D3b · DECLINED RE-OFFER branch: the AH-035 edge settles the application too".

**This is C4's finding, proven.** The kickoff's wording ("creating an assignment for a pair with a
pending application") reads as create-only, and a create-only hook passes every other test in the
suite while leaving an application pending forever on exactly the pair AH-035 exists to serve. The
red here is the difference between the plan's reading and the kickoff's wording being caught by a
test rather than by a reviewer.

### 4 — D3b's hook dropped from `store()`'s CREATE branch

**Defect.** The same substitution in the create branch.

**Red — 2 tests.** "D3b · CREATE branch: a pending application for the invited pair settles as
accepted" and the **BULK** case.

Taken with mutation 3, this is what makes the byte-identity pin non-vacuous: the pin asserts that a
pair with **no** application is unchanged, and these two assert that a pair **with** one is changed
on both branches. A pin without them could pass on a hook that does nothing at all.

### 5 — the dropped `is_discoverable` leg put back

**Defect.** `if (! $creator->is_discoverable) { … }` re-added to `accept()`.

**Red — 1 test.** "BREAK-REVERT · a creator hidden from discovery is STILL accepted (the dropped
is_discoverable leg)".

The **absence** of a gate leg is as much a decision as its presence, and it is the kind of decision a
later "consistency" pass silently reverses by copying the invite path's gate list. This test is that
decision's guard.

### 6 — the kept hard-blacklist re-check removed

**Defect.** `if ($gate->isHardBlacklisted(...))` replaced with `if (false)`.

**Red — 2 tests.** "BREAK-REVERT · an agency-wide HARD blacklist added after the application 422s the
accept" and "a BRAND-scoped hard blacklist 422s the accept too (both predicates)".

The second red is the load-bearing collateral: the re-check covers **both** blacklist predicates, so a
mutation that satisfies one and not the other cannot pass. A third test pins the opposite direction —
a **soft** blacklist does not block — so the tiers stay distinct severities rather than collapsing
into "any blacklist blocks".

### 7 — the auto-reject job's in-worker `pending` re-filter dropped

**Defect.** `->where('campaign_applications.status', CampaignApplicationStatus::Pending->value)`
removed from the job's read.

**Red — 2 tests.** "IDEMPOTENT: a second run sends nothing twice and writes nothing twice" and
"leaves ALREADY-ANSWERED applications untouched".

The re-filter is the entire idempotency design, and the sequence it defends against
(`active → cancelled → active → cancelled`) is one an operator can produce by hand in a minute.

### 8 — the mail flag forced open in the notifier

**Defect.** `if (! Feature::active(ApplicationNotificationsEnabled::NAME))` replaced with
`if (false)` in `CampaignApplicationNotifier::queue()`.

**Red — 4 tests, one per emission site.** The flag-OFF case in `CampaignApplicationAcceptTest`,
`CampaignApplicationRejectTest`, `ApplicationSubmittedNotificationTest` and
`AutoRejectPendingApplicationsTest`.

Four reds from one mutation is the point: the flag is checked in exactly one place, and all four
sites are covered by that one check. A fifth emission site added later without a flag-OFF test would
still be gated, because it cannot reach `Mail::queue()` except through `queue()`.

### 9 — the D7 subquery's `creator_id` filter dropped

**Defect.** `->where('campaign_assignments.creator_id', $creator->id)` removed from
`callerAssignmentUlidSubquery()`.

**Red — 1 test.** "it never bridges to ANOTHER creator assignment on the same campaign".

Without it, a creator whose own application is pending could be handed a **different creator's**
assignment ULID on a campaign with any invited creator — a cross-creator leak on a creator-facing
resource, and the exact failure a correlated subquery invites if the correlation is incomplete.

### 10 — the badge count conflated with the applicant count

**Defect.** The pending filter removed from `CampaignApplicationController::pendingCount()`.

**Red — 2 tests.** "meta.pending_total counts PENDING ONLY — never the interest-semantics applicant
count" and "the status filter narrows the page but NOT the badge".

The I6.2 conflation warning, proven in both directions: the badge must not count answered
applications, and it must not follow the page's filter either.

### Restore check

After each revert, `git status --porcelain` was confirmed clean and the affected file's suite
re-run green. The gate board in this review was produced at the **final, unmutated HEAD**.

---

## The §5.34 byte-identity pin (D3b)

The kickoff asked for field-by-field, and it is field-by-field
(`AssignmentInviteConvergenceTest.php:116`). For an ordinary invite — a pair with **no** application —
the test asserts the created row's `agency_id`, `campaign_id`, `creator_id`, `status`,
`agreed_fee_minor_units`, `agreed_fee_currency`, `fee_per`, `offer_description`,
`invited_by_user_id`, a non-null `invited_at` and a null `responded_at`; then the three downstream
facts a new transaction could have broken — **exactly one** `assignment.invited` audit row, the
board card's existence, and the 201 with `status = invited`.

Then the negative half, which is what makes it a byte-identity pin rather than a happy path: **nothing
from the applications half of the world fired.** No application row exists, no
`campaign_application.accepted` audit row, no such notification, and no `ApplicationAcceptedMail` —
**with the flag deliberately ARMED**, so a silent flag-OFF cannot be the reason nothing was sent.

Two more cases fence the pin: an application from **another creator** on the same campaign is left
alone (`:159`), and the rollback case proves `store()`'s new transaction actually rolls back, leaving
the application pending with nothing mailed (`:307`).

---

## Review priorities — where each was discharged

| #   | Priority                                            | Discharged                                                                                                                                                                                                                                                                   |
| --- | --------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | the transaction break-revert, grown by C1           | `CampaignApplicationAcceptTest.php:237` — forced failure, then **no assignment**, application **still pending**, `Mail::assertNothingQueued()`, **zero notification rows**. Mutations 1 and 2.                                                                               |
| 2   | the D3 matrix per state + D3b byte-identity         | `:280`, `:289`, `:318` (dataset), `:352`, `:374`; the pin at `AssignmentInviteConvergenceTest.php:116` with mutations 3 and 4 proving non-vacuity; the bulk case at `:333`.                                                                                                  |
| 3   | dropped-discoverable + kept-blacklist               | `:392` (hidden creator **succeeds**) and `:410` / `:430` (blacklist **422s**), plus `:448` keeping the soft tier distinct. Mutations 5 and 6.                                                                                                                                |
| 4   | auto-reject idempotency + flag-OFF with in-app kept | `AutoRejectPendingApplicationsTest.php:207`, `:236`, `:265`, `:286`; the dispatch-edge matrix at `:94`–`:143`. Mutations 7 and 8.                                                                                                                                            |
| 5   | §5.2 splits per emission                            | Four sites, three types: `ApplicationSubmittedNotificationTest` (apply), `CampaignApplicationAcceptTest.php:180`/`:195` (dispatch leg separate from the notify leg), `CampaignApplicationRejectTest.php:131`, `AutoRejectPendingApplicationsTest.php:161`.                   |
| 6   | the D7 bridge + accepted-state keyset ×24           | `CreatorJobDetailTest` (updated `DETAIL_KEYS`, null and populated cases, the cross-creator negative), `CreatorJobDetailPage.spec.ts` three-state branch table, `CreatorJobsPage.spec.ts` chip pin, `acceptedNotice` + `viewOffer` ×24 with flaky-10 spot values. Mutation 9. |
| 7   | tripwires                                           | `NotificationTypeEnumTest`, `AuditActionEnumTest`, `templates.spec.ts`, `i18n-notifications-parity.spec.ts` (15 → 18), `i18n-locale-parity.spec.ts`, both prefs-count specs, and `CampaignApplicationSchemaTest` green untouched.                                            |
| 8   | full Playwright including the c4 leg                | **26/26 in 4.7m**, two projects, dev stack down, isolated E2E database.                                                                                                                                                                                                      |

---

## Locale parity and the copy counts

| Surface                                   | New leaves | Locales | Total    |
| ----------------------------------------- | ---------- | ------- | -------- |
| Backend mail (`lang/*/campaigns.php`)     | 13         | 24      | 312      |
| SPA notifications (`notifications.json`)  | 7          | 24      | 168      |
| SPA app copy (`app.json` — tab + dialogs) | 27         | 24      | 648      |
| SPA creator copy (`creator.json` — D7)    | 2          | 24      | 48       |
| **Total**                                 |            |         | **1176** |

The 13 backend leaves are 4 (`submitted`) + 4 (`accepted`) + 5 (`rejected`, which carries **two body
variants under one subject** — the `body_ . $cause` shape, on the draft-reviewed precedent). The 7 SPA
notification leaves are 3 templates + the `jobs_board` group heading + 3 `typeLabels`.

All 24 locales carry **real machine-translated copy**, including the flaky 10 (`bg, el, et, fi, ga,
hu, lt, lv, mt, ro`), per the AH-046/047 ruling. `ApplicationMailTest` renders all three mailables in
a non-English locale with the interpolated values intact (`:155`) and separately renders them in the
flaky-10 locales asserting **real translations, not the English fallback** (`:169`).

`i18n-locale-parity.spec.ts` is green across every namespace, which covers the key-set, the
`{named}` placeholder sets and the CLDR plural forms for all four touched files.

---

## Gate board — full, at final HEAD

| Gate                                           | Result                                                   |
| ---------------------------------------------- | -------------------------------------------------------- |
| `pest` (apps/api, full, serial at 2G)          | **2338 passed, 1 skipped** (8587 assertions), 154s       |
| `phpstan` (level max, apps/api)                | **0 errors**                                             |
| `pint --test` (run outside the sandbox, §5.18) | **passed**                                               |
| `vitest` (apps/main, full)                     | **1319 passed** / 141 files                              |
| `vitest` (apps/admin, full)                    | **449 passed** / 53 files                                |
| `vitest` (packages/api-client)                 | **204 passed** / 9 files                                 |
| `vue-tsc --noEmit` (apps/main)                 | **clean**                                                |
| `vue-tsc --noEmit` (apps/admin)                | **clean**                                                |
| `tsc --noEmit` (packages/api-client)           | **clean**                                                |
| `eslint` (apps/main)                           | **0 errors** (the same 2 pre-existing `v-html` warnings) |
| `eslint` (apps/admin)                          | **0 errors**                                             |
| `i18n-locale-parity.spec.ts`                   | **green** (24 locales, all namespaces)                   |
| `i18n-notifications-parity.spec.ts`            | **green** (**18** live types)                            |
| `templates.spec.ts`                            | **green** (derived registration guard)                   |
| **Playwright (apps/main, full suite)**         | **26/26 passed** in 4.7m, two projects                   |

Backend counts moved **2234 → 2338** (+104 tests, +542 assertions) and `apps/main` **1278 → 1319**
(+41 tests, +2 files) against the chunk-3 close.

**Playwright procedure.** The dev stack was already down when the run started (ports 8000, 5173, 5174
and the stray 8001 confirmed free), so nothing was taken down and nothing was restarted — the
`reuseExistingServer: false` invariant is intact either way, and it is a post-incident invariant
because a reused dev API would have been pointed at the developer's real database by
`global-setup.ts`'s unconditional `migrate:fresh`. The suite ran against `catalyst_e2e` with
`TEST_HELPERS_TOKEN` exported.

The new leg, from the run:

```
  ✓   5 [chromium] › playwright/specs/campaign-applications.spec.ts:43:3 › AH-058 — agency answers job
        applications › the agency rejects one applicant, accepts another, and the accept lands on the
        board (13.5s)

  26 passed (4.7m)
```

Spec #20 (failed-login lockout) took 36.9s and passed — it carries AH-057's `test.slow()` for exactly
that reason, so this is the budget working, not a flake.

### New and changed tests, by file

| File                                               | Tests                                                         |
| -------------------------------------------------- | ------------------------------------------------------------- |
| `ApplicationMailTest.php`                          | 22 (8 cases; the locale ones are datasets)                    |
| `ApplicationSubmittedNotificationTest.php`         | 6                                                             |
| `AssignmentInviteConvergenceTest.php`              | 8                                                             |
| `AutoRejectPendingApplicationsTest.php`            | 15                                                            |
| `CampaignApplicationAcceptTest.php`                | 27 (23 cases; the D3 matrix is a dataset)                     |
| `CampaignApplicationListTest.php`                  | 10                                                            |
| `CampaignApplicationRejectTest.php`                | 10                                                            |
| `CreatorJobDetailTest.php` (extended)              | +5, plus the updated `DETAIL_KEYS`                            |
| `AdminFeatureFlagTest.php` (extended)              | +1 (HTTP arm/disarm for the new flag)                         |
| `NotificationTypeEnumTest` / `AuditActionEnumTest` | catalogues extended                                           |
| `ApplicationsTab.spec.ts`                          | 11                                                            |
| `AcceptApplicationDialog.spec.ts`                  | 10                                                            |
| `CampaignDetailPage.spec.ts` (extended)            | 35 total (+3: the tab mounts, the badge, the badge's absence) |
| `CreatorJobDetailPage.spec.ts` (extended)          | 16 total (+3: the D7 third state, its link, its degradation)  |
| `campaigns.api.spec.ts` (extended)                 | 15 total (+4)                                                 |
| `CreatorJobsPage.spec.ts` (extended)               | +1 (the c3 accepted-chip regression pin)                      |
| `NotificationPreferencesPage.spec.ts` (extended)   | counts + the `jobs_board` group by name                       |
| `campaign-applications.spec.ts` (Playwright)       | 1                                                             |

---

## Production posture (restated at final code HEAD `a1c66ab`)

**§5.40 risk: ⚠ MEDIUM.** Unchanged from the plan-pause derivation, which was accepted as
re-derived.

**No migration. At all.** Chunk 3 already shipped `campaign_applications.responded_at` and
`idx_applications_agency_status`, and D4's refusal of a reject-reason column is what keeps it that
way. **`down()` honesty is therefore trivially satisfied: there is nothing to reverse.** That removes
the single largest §5.40 surface a chunk can have and is the main reason this stays MEDIUM rather than
higher.

**The arc's first UPDATE on a row a creator created.** Chunks 1–3 only inserted rows or stamped one
nullable timestamp on a campaign the operator had just edited. Chunk 4 **updates
`campaign_applications.status`** — a state flip on a creator's own row — plus `responded_at`. Every
write is guarded by a hand-written pending-only source check (the enum's docblock says the guard is
the call site's job), and both directions of that guard are tested: an answered application cannot be
re-answered, and an accepted one cannot be rejected.

**What it creates on the platform's most load-bearing table.** Accept inserts a
`campaign_assignments` row at `invited` through a plain `create()` (now inside
`CampaignInvitationService`), and that one insert fans out to seven registered `AssignmentTransitioned`
listeners, three of which act on `invited`: message thread, board card, board automation. This is
deliberate — it is what "byte-indistinguishable from a cold invitee" means — and it is asserted rather
than assumed.

**The live-path change, and its containment.** `store()` is the invite endpoint an agency uses every
day, and the bulk-invite dialog calls it once per selected creator in a loop. It now runs inside a
transaction and carries one guarded hook. This is the chunk's highest-regression-probability change,
which is why the byte-identity pin is asserted field-by-field, why two mutations prove the hook is
real, and why it is the only content of its own commit (`5f6486c`).

**Emission ordering is a design property, not luck.** Every notification in this chunk is emitted
**after** its transaction commits, because `after_commit => false` means the alternative is a worker
reading a mail for a write that may still roll back. The residual failure mode is the inverted, better
one: a committed accept whose in-app row failed to write, rather than a rolled-back accept that
already mailed. The pre-existing counter-pattern in `SendAssignmentNotifications` is now a named
tech-debt entry with a trigger.

**The outbound-mail exposure.** Three new mailables to live users: two to creators (the ~279-creator
base) and — new for this arc — **one to agency users**, fanned to every admin and manager of the
agency. Containment is one Pennant flag, `application_notifications_enabled`, default OFF, gating the
**mail** legs only. Two honest limits on that containment: (a) it is a per-emission gate, not a cap —
unlike AH-056's fan-out there is no `--limit`, because volume is bounded by human action, with exactly
one exception, the auto-reject loop, whose bound is the roster; (b) in-app rows still write with the
flag OFF, by design, and still honour each recipient's own preference.

**No per-creator email opt-out**, for the same platform-wide reason as chunk 3: the email channel has
never been wired through preference reads. The AH-056 tech-debt entry was **widened** to cover these
three types rather than duplicated, and this chunk does not meet its trigger — these are answers to
actions the recipient took, not recurring outreach.

**At T+0 the reachable population is provably zero.** `campaign_applications` does not exist in
production; chunk 3 is undeployed and the arc deploys as one unit. So at deploy there are no
applications to accept, reject or auto-reject, no campaign is listed, and every path in this chunk is
inert until a creator applies.

**Queue posture.** The auto-reject job uses the default connection, the default queue and
framework-default retries, like every other job on this platform. Its volume is bounded by the roster
and it does one small transaction per row, so a mid-loop failure is recoverable by retry and a burst
cannot exceed one agency's roster. No cap and no `--limit`: the in-worker `pending` re-filter is the
containment, and a cap would only make a closed campaign's applications sit pending in a way nobody
would drain.

**No scheduler dependency.** The trigger is the status flip, never a cron — the production scheduler's
existence is still unverified, so a feature whose only trigger is `schedule:run` could ship, pass
every test, and never fire.

**The deploy obligations**, restated in `RESUMPTION-TEMPLATE.md`: no migration; the new flag ships OFF
and is armed **alongside `job_posted_notifications_enabled`** as one ritual; and the **queue worker
must be restarted** because this deploy carries new `lang/**` mail copy and three new mailable
classes, and a long-running worker caches translations in memory.

**No `tenancy.md` §4 rows**, restating the inventory's ruling. The three new routes are ordinary
tenanted agency routes under `/agencies/{agency}/campaigns/{campaign}`, guarded by
`assertBelongsToAgency()` plus a `Gate::authorize()` per the house pattern. §4 is an allowlist of
routes that deviate from tenancy, and adding conforming routes to it would dilute exactly the signal
it exists to carry. The negative set is where the claim is proven instead: another agency's campaign
**404s** (never 403 — §5.4 non-fingerprinting), a caller with no membership 404s, and an application
belonging to another campaign of the **same** agency is invisible to the list and refused by both
actions.

---

## What this chunk deliberately did not build

A real board column or any change to `board_cards` (D1 — the board is asserted, not extended); a
reject-reason column, field or rendering (D4); a withdraw-application path; an
application-withdrawn or accept-declined vocabulary (the applicant declining their offer is the
existing `assignment.declined`, unchanged); a `CampaignStatusChanged` domain event (D5); an
email-channel preference toggle for the three new types (the AH-056 tech-debt entry was widened, not
resolved); a per-campaign application digest (Q6's named future trigger); a cap or `--limit` on the
auto-reject loop; the cross-role full-lifecycle Playwright spec (chunk 5); and the creator-side
dashboard applications teaser (not in the arc plan).

**One deferred fix, named:** the staff asymmetry — a staff member may invite, accept and reject, but is
not notified when a creator applies. It lives in `Agency::notifiableMembers()`, one shared primitive
across every agency notification on the platform, and it is recorded as pre-existing rather than
patched for one chunk's three types.

---

## Correction — 2026-07-29 (appended at AH-059, so this file is not read as the last word)

Pedram's eyes-on of this chunk reported that the campaign-closed auto-reject produced an in-app
notification but **no email, while the manual reject mailed** — recorded at the time as eyes-on item
#2 and carried into the AH-059 kickoff as a suspected defect in the `campaign_closed` variant's mail
leg. **The observed symptom was real; the attributed asymmetry was not.** AH-059's investigation found
`application_notifications_enabled` was **never armed** during that session, so **neither** path
mailed and both behaved exactly as this review describes. Nothing in chunk 4's mail path is defective,
and nothing here needed changing: the four files were re-verified byte-identical at the AH-059 close.
Full evidence in [`jobs-board-c5-plan.md`](jobs-board-c5-plan.md) §2 and
[`jobs-board-c5-review.md`](jobs-board-c5-review.md) (D2).
