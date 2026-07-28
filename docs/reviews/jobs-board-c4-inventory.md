# Jobs Board arc — chunk 4 (agency applications: Applied surface, accept, reject) — read-only inventory

- **Status:** Inventory only. No edits, no plan, no code. Kickoff follows after review.
- **Date:** 2026-07-28
- **Author:** Cursor (read-only pass), for Claude's chunk-4 kickoff.
- **HEAD:** `d4b2de5765f854811d155a06028af0dcfec86026` (`d4b2de5`), **= `origin/main`**
  (`git rev-list --left-right --count origin/main...HEAD` → `0 0`; HEAD is
  `docs(creators): AH-057 entry, closed-review addendum, eyes-on report (AH-057)`). The working tree
  was clean apart from this file.
- **Orientation read before writing:** `docs/WORKING-PROCESS.md` (all 9 sections),
  `docs/PROJECT-WORKFLOW.md` §5 (5.1–5.40, esp. 5.2, 5.6, 5.11, 5.22, 5.32, 5.34, 5.37, 5.38, 5.40),
  `docs/reviews/adhoc-changes-log.md` (AH-057 → AH-034, esp. AH-057, AH-056, AH-051, AH-042, AH-041,
  AH-035), `docs/reviews/jobs-board-c3-review.md` **including its post-close addendum**,
  `docs/reviews/jobs-board-c3-inventory.md` (the I2 table-vs-state analysis),
  `docs/reviews/RESUMPTION-TEMPLATE.md` Part 2, `docs/security/tenancy.md` §4,
  `docs/feature-flags.md`, `docs/tech-debt.md` (the three AH-056 entries).

**§5.40 line for this document:** `PROD-DATA RISK: NONE` — this pass read files and ran `git`
read-only commands. Nothing was executed against any database.

**Plan-pause forecast (re-derived at plan-pause, not binding here): ⚠️ MEDIUM.** Chunk 4 is the arc's
deepest-machinery chunk and the forecast is the strictest §5.40 treatment of the arc so far, for three
independent reasons the code confirms:

1. **It writes into the assignment state machine's entry path.** Accept must create a
   `campaign_assignments` row at `invited`. That entry is a **plain `create()`**, not a guarded machine
   edge (`CampaignAssignmentController.php:203-225`), so `assertSource()`'s fail-closed discipline
   does **not** cover the row's birth — the only protections are the unique pair and whatever the new
   call site chooses to assert. §I2 details every constraint.
2. **It is a cross-table transaction on live tables.** `campaign_applications` (populated by chunk 3's
   creator apply) plus `campaign_assignments` (the platform's most load-bearing table) must move
   atomically, and the assignment create fans out to **seven** registered `AssignmentTransitioned`
   listeners (`CampaignsServiceProvider.php:47-75`) — availability block, verification dispatch,
   notifications, message thread, system message, board card, board automation.
3. **It emits mail to live users.** The reject path notifies a real creator, and (per §I2/§I4) the
   accept path notifies nobody today unless chunk 4 adds the vocabulary — see the
   `assignment.invited`-has-no-emitter finding, which is the sharpest single fact in this inventory.

No migration is expected: `campaign_applications.responded_at` already ships
(`2026_07_27_110000_create_campaign_applications_table.php:89-91`, docblock: "chunk 4 writes it; the
column ships now so chunk 4 adds no migration") and `idx_applications_agency_status` was added for
exactly this chunk (`:97-99`, docblock `:46-50`).

---

## I1 — The board module today, and where an "Applied" column can architecturally sit

### The board's shape: DB-driven columns, a fixed automation catalogue

**Columns are DB rows, fully agency-editable.** `board_columns`
(`2026_06_06_120001_create_board_columns_table.php`) carries `name`, `position`, `color_token`,
`is_terminal_success`, `is_terminal_failure` (`:54-58`), a denormalized `agency_id` with
`BelongsToAgency` (`:48-52`, docblock `:16-20`), and `board_id` CASCADE (`:41-45`, docblock `:30-31`). Defaults are seeded — not fixed — by
`BoardProvisioningService::seedColumns()` (`:40-59`) from `BoardDefaults::columns()`
(`BoardDefaults.php:32-43`), which is the **7-column** default set: `To Define`, `Invited`,
`In Review`, `Approved`, `Posted`, `Paid` (terminal success), `Cancelled / Rejected` (terminal
failure). Seeding is guarded on "board has no columns" (`BoardProvisioningService.php:43-45`) so a
later rename/reorder/delete is never clobbered.

Full column CRUD ships: `store` / `update` / `destroy` / `reorder`
(`BoardColumnController.php:40-138`, routes `Boards/Routes/api.php:44-51`), all gated on `update`
(admin + manager) via `authorizeConfig()` (`BoardColumnController.php:140-146`). Delete refuses the
last column (`:71-80`, `board.column.last_column`) and refuses to orphan cards without a destination
(`:82-91`, `board.column.destination_required`). The SPA renders `store.sortedColumns` from the API
payload (`useBoardStore.ts:71-73`, `BoardView.vue:142`) — nothing about the column set is hard-coded
front-end.

**Automations are a fixed catalogue, editable but not creatable.** `BoardDefaults::automations()`
(`BoardDefaults.php:51-68`) is exactly **10** rows, each an `AuditAction` verb value → target column
NAME. `seedAutomations()` `firstOrCreate`s on `(board_id, event_key)`
(`BoardProvisioningService.php:61-83`). The only write endpoint is
`PATCH …/board/automations/{automation}` (`Boards/Routes/api.php:56-57`), and
`UpdateBoardAutomationRequest` accepts **only** `is_enabled`, `action_type`, `target_column_id`
(`:24-29`). **There is no endpoint that creates an automation, and no way for an agency to bind a new
`event_key`.** A new automation key is therefore a code change in `BoardDefaults` plus a backfill for
existing boards — the AH-041 shape exactly (`2026_07_13_110000_backfill_cancelled_rejected_board_column.php`,
described in the AH log at `adhoc-changes-log.md:858-887`).

### ⚠ The load-bearing constraint: a board card **is** an assignment

This is the single fact that decides I1, and it is structural at three layers:

| Layer        | Evidence                                                                                                                                                                                                                                                                                                                                                                   |
| ------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Schema**   | `board_cards.assignment_id` is `unsignedBigInteger` **NOT NULL**, FK to `campaign_assignments` **CASCADE**, with `unique('assignment_id', 'unique_board_cards_assignment')` (`2026_06_06_120003_create_board_cards_table.php:60-65`). The migration docblock opens "one card per CampaignAssignment" (`:12-13`) and states "a card cannot outlive its assignment" (`:31`). |
| **Resource** | `BoardCardResource` docblock: "A card IS a CampaignAssignment (§4.1)" (`:17`); the whole card face is read off `$card->assignment` (`:43-44`, `:58-80`).                                                                                                                                                                                                                   |
| **SPA**      | `BoardCard.vue:33` reads `props.card.relationships.assignment.data`; a null assignment renders a "removed" placeholder tile (`:19-20`, `:162-168`).                                                                                                                                                                                                                        |

So a **real board column holding applications is not a column problem — it is a card problem.** An
`Applied` column could be created today with one `BoardDefaults` entry plus a backfill, but nothing
could be put in it: an application has no assignment row, and `board_cards` cannot represent one
without a schema change (nullable `assignment_id`, dropping or partialising the UNIQUE, a new
"what is this card about" discriminator, and a null-assignment branch through
`BoardCardResource`, `BoardCard.vue`, `BoardCardDrawer.vue`, `BoardCardService::forAssignment()`,
and `BoardCardMoveService`). That is a destructive-shaped change to a populated table on the
platform's most load-bearing spine, which §5.40 puts behind a separately-reviewed plan.

### Drag semantics, and why they are hostile to an applications column

Card moves are **visualization only**. `BoardCardController::move()` is gated on `invite`
(`:45`) and delegates to `BoardCardMoveService`, whose docblock states board state "is a VISUALIZATION layer: a manual move
records the FACT but drives NO business logic", that the service has "STRUCTURALLY NO reference to the
assignment state machine", and that "a manual move to 'Paid' does NOT release payment" (`:20-26`). The SPA
implements drag with `vuedraggable` (`BoardColumn.vue:15`) in group `board-cards` (`:124`, docblock
`:4-5`), emitting `{ cardId, toColumnId }` (`:49`) → `BoardView.onMove` (`BoardView.vue:61-62`,
`:145`) → `useBoardStore.moveCard` → `POST …/cards/{ulid}/move` with `target_column_id` only and
`reason` omitted (`useBoardStore.ts:204-217`, `board.api.ts:40-49`).

The consequence for an applications column is concrete and bad: **any card in any column can be
dragged to any other column, and the drag means nothing.** An agency dragging a card OUT of "Applied"
would neither accept nor reject the application — the board would silently disagree with the
application's real state. Accept/reject are consequential writes (a cross-table transaction and a
creator-facing notification); the board's one interaction primitive is explicitly defined as
consequence-free. Wiring drag-to-accept would invert the §4.4 visualization invariant that the whole
board module is built on.

### What board automations would (and would not) fire on application events

`BoardAutomationListener::handle()` returns immediately unless the event implements
`AssignmentEventContract` (`:31-35`). `BoardAutomationService::processEvent()` then resolves a
`CampaignAssignment` by id (`:41-45`) and no-ops when the board or card is missing (`:47-50`,
`:69-73`). A `campaign_application.*` event is neither, so:

- **Nothing fires today.** No application event can reach the automation engine without a net-new
  event class (or a contract implementation) plus a new listener registration — and §5.38 explicitly
  prefers binding to an existing contract keyed by `eventKey()` over a fan of per-event classes,
  which pushes toward re-using `AssignmentTransitioned` rather than inventing a parallel spine.
- **What WILL fire, and must be intentional:** accept creates an assignment at `invited` and (if it
  follows the `store()` precedent) dispatches `AssignmentTransitioned(from=to=invited)`
  (`CampaignAssignmentController.php:243-249`). That single dispatch reaches all seven listeners; two
  of them are board consumers in a **locked order** (`CampaignsServiceProvider.php:67-75`):
  `CreateBoardCard` provisions the card (`CreateBoardCard.php:41-52`, `invited`-only), then
  `BoardAutomationListener` runs the `assignment.invited → Invited` automation
  (`BoardDefaults.php:54`). **So an accepted application lands as a normal card in "Invited"
  automatically, with no new board code at all.** That is the strongest architectural argument in this
  section: the board already has the right answer for the post-accept state, and needs nothing for
  the pre-accept state it cannot represent.

### The two options, costed against the real implementation

Reported, not decided.

**Option A — a real board column ("Applied") as the client slide showed.**
Costs: a `BoardDefaults` column entry + an idempotent backfill for existing boards (AH-041 shape);
**a `board_cards` schema change to allow an assignment-less card** (nullable FK, UNIQUE rework,
discriminator) — the expensive, §5.40-reviewable part; a null-assignment render path through
`BoardCardResource`, `BoardCard.vue`, `BoardCardDrawer.vue`'s three tabs (Messages needs a thread that
does not exist, Detail needs an assignment that does not exist, History needs movements); a decision
about what dragging an application card means; and a new "convert this card into an assignment"
action that the move endpoint deliberately cannot express. It also puts applications on a surface
gated `view` for any member while accept/reject will need an execute-tier ability.
Benefits: matches the client slide; one place to see the whole campaign funnel.

**Option B — a separate Applications panel/tab on the campaign detail.**
Costs: a new tab in the seven-tab set (`CampaignDetailPage.vue:425-443`) — a data change in an
existing declaration, following `board` and `drafts` which both render lazily on
`tab === '<key>'` (`:790-792`, `:802-804`); a new agency-side list endpoint + resource; i18n for the
tab label plus the panel. No schema change, no board-module change, no drag semantics to redefine.
Benefits: the surface's affordances (accept / reject buttons, a reject dialog) are exactly what
drawers and tabs already do — `DraftsTab` is the direct precedent for "a campaign-scoped list of
creator submissions with per-row review actions", and `ReviewDraftDrawer` is the precedent for the
consequential-action-with-reason dialog. Applications also **stop** existing once decided (both
statuses terminal), which reads as a queue, not a lane.
Costs of not doing A: the funnel is split across two tabs.

⚠ **One documented expectation cuts against the panel conclusion, and should be surfaced rather than
quietly dropped:** the c3 migration's own docblock justifies `idx_applications_agency_status` as
"added now for **chunk 4's agency-side board column** — a locked arc decision"
(`2026_07_27_110000_create_campaign_applications_table.php:46-50`), and the `agency_id`
denormalization is justified so "chunk 4's agency-side board column scopes for free" (`:24-28`). So
"board column" is written into a shipped artifact. The index and the denormalized `agency_id` serve an
agency-side **status-scoped list** equally well whichever surface renders it, so nothing is wasted — but
if chunk 4 lands a panel instead of a column, that is a §5.32 reinterpretation of a recorded decision
and must be logged as one, with the `board_cards` shape as the evidence.

**The asymmetry worth naming for the kickoff:** the board's column set is cheap and DB-driven, so the
slide's _column_ is trivially addable — but the board's **card** is definitionally an assignment, so
the column would be empty. The "column vs panel" question is really "does chunk 4 change the shape of
`board_cards`?", and the arc's own D1 reasoning (keep applications and assignments in disjoint
namespaces, `campaign_applications` migration docblock `:15-21`) argues no.

---

## I2 — The accept transaction's target: the agency invite path, exactly as it stands

### `CampaignAssignmentController::store()` — the whole path, in order

`POST /api/v1/agencies/{agency}/campaigns/{campaign}/assignments`
(`Campaigns/Routes/api.php:51-52`), standard tenant stack (`auth:web` + `tenancy.agency` + `tenancy`,
`:30`). The method is `CampaignAssignmentController.php:88-254`:

| Step | Lines      | What it does                                                                                                                     |
| ---- | ---------- | -------------------------------------------------------------------------------------------------------------------------------- |
| 1    | `:96`      | `assertBelongsToAgency()` — 404 on cross-tenant campaign (`:363-368`)                                                            |
| 2    | `:97`      | `Gate::authorize('invite', $campaign)` — the **execute** ability: admin + manager + **staff** (`CampaignPolicy.php:60-67`)       |
| 3    | `:108-120` | Offer-attachment isolation backstop — the `upload_id` must sit under THIS campaign's prefix; 422 `assignment.attachment_invalid` |
| 4    | `:125-133` | Creator lookup by ULID requiring **`application_status = approved` AND `is_discoverable = true`**; otherwise `abort(404)`        |
| 5    | `:136-143` | D-1 hard block — `AssignmentInviteGate::isHardBlacklisted()` → 422 `assignment.blacklisted`                                      |
| 6    | `:153-179` | **The idempotency branch** (below)                                                                                               |
| 7    | `:184-201` | D-2 soft warn — hard availability conflict → 409 `assignment.availability_conflict` unless `acknowledged: true`                  |
| 8    | `:203-225` | `CampaignAssignment::create([...])` — the row's birth                                                                            |
| 9    | `:232-241` | **Hand-written** `assignment.invited` audit row                                                                                  |
| 10   | `:243-249` | **Hand-dispatched** `AssignmentTransitioned(from=invited, to=invited)`                                                           |
| 11   | `:251-253` | 201 + `CampaignAssignmentResource`                                                                                               |

**What the create writes** (`:203-225`): `agency_id`, `campaign_id`, `brand_id` (copied from the
campaign, `:206`), `creator_id`, `status = Invited`, **`invited_at = now()`** (`:209`),
**`invited_by_user_id = $actor->id`** (`:210`), `agreed_fee_minor_units`, `agreed_fee_currency`
(upper-cased, `:212`), `fee_per`, `offer_description`, the four `offer_attachment_*` columns
(`:217-220`), `deliverables`, `posting_due_at`, `draft_due_at`.

**There is no token and no `invitation_sent_at`.** Those belong to the _agency-user_ invitation
(`invitations` table / `InviteAgencyUserMail`) and to the creator _roster_ magic-link flow — not to
campaign assignments. A campaign invitation is discovered by the creator by **pulling**
`/creators/me/assignments`, not by following an emailed link. `invited_at` is the only stamp.

**What the invite request requires** (`InviteAssignmentRequest.php:30-65`): `creator_id` (required
string, no `exists` — the gates run in the controller by design, `:33-35`);
`agreed_fee_minor_units` **required, integer, min:1**; `agreed_fee_currency` **required, size:3** and
cross-validated to equal the campaign's `budget_currency` when that is set (`:67-83`). Everything else
is nullable: `fee_per` (≤120), `offer_description` (≤2000), the attachment quad, `deliverables`,
`posting_due_at`, `draft_due_at`, `acknowledged`.

### ⚠ What `store()` emits: **nothing to the creator**

This is the sharpest finding of the pass and it inverts a natural assumption in the brief.

- `SendAssignmentNotifications::handle()`'s `match` covers `draft_submitted`, `draft_approved`,
  `revision_requested`, `draft_rejected`, `contracted`, `manually_verified`, `default => null`
  (`:72-80`). **`AssignmentInvited` is not a branch.** No email, no in-app row.
- `NotificationType::AssignmentInvited` **exists** in the enum (`:44`) but is **not** in the FE
  `LIVE_TYPES` registry (`templates.ts:98-209`) — it sits in the `DEFERRED_WITHOUT_EMITTER` allowlist
  (`templates.spec.ts:35-43`), whose own docblock says an entry there "asserts _no emit site exists_"
  (`:31-33`).
- There is no invitation mailable in the Campaigns module: the eight are `ContractAccepted`,
  `ContractAttached`, `DraftReviewed`, `DraftSubmittedForReview`, `JobPosted`,
  `PostManuallyVerified`, `PostVerificationFailed`, `ResubmitRequested`.
- What the creator _does_ get today: the invitation appears in `GET /creators/me/assignments`
  (`CreatorAssignmentController.php:44-58`), and the dashboard teaser counts `invited` rows
  (`CreatorDashboardPage.vue:124-131`, testid `dashboard-assignments-teaser` `:282-290`) — a **pull**
  surface only.

**Consequence for the product call.** "Accept creates a standard invitation" is machinery-correct, but
if chunk 4 reuses the invite path verbatim, an applicant who applied and was accepted is told
**nothing** — they must revisit the app and notice a count change. That makes an
`application_accepted` → creator notification load-bearing rather than merely polite, and gives the
kickoff a genuine fork: **(a)** add an application-specific accepted notification (creator-facing,
narrow, no change to the invite path), or **(b)** finally light up `assignment.invited` — which would
notify every invited creator platform-wide, i.e. a scope increase and a new mail fan-out to live users
well beyond chunk 4. Reported; (a) is the containable one.

### What accept fires by way of the seven listeners

Every registered `AssignmentTransitioned` consumer, in registration order
(`CampaignsServiceProvider.php:47-75`), evaluated for `action = AssignmentInvited`:

| #   | Listener                            | On `invited`?                                                                                                                      |
| --- | ----------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `CreateAssignmentAvailabilityBlock` | **No-op** — `accepted`-only (`:42-44`), and its docblock records that it relies on the commit transaction for atomicity (`:34-37`) |
| 2   | `DispatchPostedContentVerification` | No-op (posted-only)                                                                                                                |
| 3   | `SendAssignmentNotifications`       | **No-op** (`:72-80`, no branch)                                                                                                    |
| 4   | `CreateMessageThread`               | **Fires** — provisions the assignment's message thread (`invited`-only)                                                            |
| 5   | `WriteSystemMessage`                | **No-op** — `invited` is not in `SYSTEM_MESSAGE_TRANSITIONS` (`:36-47`)                                                            |
| 6   | `CreateBoardCard`                   | **Fires** — `invited`-only (`:41-43`), idempotent on the `assignment_id` UNIQUE                                                    |
| 7   | `BoardAutomationListener`           | **Fires** — moves the new card to the `Invited` column (`BoardDefaults.php:54`)                                                    |

So the brief's "the entire existing machinery takes over" is accurate and verifiable: thread + board
card + board placement all happen off one dispatch, and the offer/accept/contract-gate/draft chain
downstream is untouched because the row is byte-shaped like any other invitation.

### The idempotency branch, and what accept must decide about it

`store()`'s existing-row branch (`:153-179`) is a **two-outcome** switch on the unique
`(campaign_id, creator_id)` pair:

- `declined` → `machine->reofferAfterDecline(...)` — the AH-035 edge, overwriting the whole offer,
  clearing `responded_at`, raising `previously_declined` (`CampaignAssignmentStateMachine.php:173-207`);
  returns **200**.
- **any other status** → the existing row is returned **as-is**, `200`, with **no second row, no audit
  row, no event, and the submitted offer silently discarded** (`:176-178`, comment `:150-152`).

That second outcome is exactly the collision chunk 3's inventory named as decisive for D1, and it is
still live. For chunk 4 it matters in two directions:

1. **Accept must decide what happens when an assignment already exists for the pair.** A creator can
   apply to a campaign and be invited to it independently — nothing prevents it, because the two
   tables are disjoint (that is D1's whole point). The states to enumerate: no row (create);
   `invited` (an offer is already outstanding — accepting the application would be a no-op or a
   re-offer); `declined` (the AH-035 re-offer edge exists); `accepted`/`contracted`/onwards (the
   creator is already engaged); `cancelled`/`rejected` (terminal). §5.6 says the action must be
   idempotent; the kickoff has to say what "accepted an application for an already-invited creator"
   _means_, and the application row's own status flip is a separate question from the assignment's.
2. **Reuse vs bypass of `store()` itself.** Reusing the HTTP endpoint is not available (accept is a
   different route with a different subject), so the realistic options are: extract the create +
   audit + dispatch into a shared service both call; or call the existing endpoint's logic inline in a
   new controller. Whichever way, the create path's **hand-written audit + hand-dispatched event**
   (`:227-249`) must be preserved, because listeners 4/6/7 above are the machinery, and the comment at
   `:227-231` records why the endpoint owns them.

### What accept must reuse vs may bypass — the constraint list

| `store()` step                                                | Reuse / bypass, and why it matters                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| ------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Gate::authorize('invite')`                                   | **Reuse.** Accept is executing a campaign; `invite` is the execute tier (admin+manager+staff). Using `update` instead would silently narrow to admin+manager.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| Approved-creator leg (`:127`)                                 | **Reuse** — and it is free: an applicant passed chunk 3's leg 2 (`JobsBoardVisibility`, approved-or-empty-board) to apply at all.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| **`is_discoverable = true` leg (`:128`)**                     | ⚠ **Cannot be reused as-is.** `is_discoverable` defaults true (`Creator.php:96`) but is creator-settable (`:130`) and is a _browsing-visibility preference_, not eligibility — AH-051 already ruled it bypassed for admin-mediated connections (`Admin/AdminCreatorConnectionController.php:53-55`: "an admin-mediated arrangement is not cold outreach, and is_discoverable is a browsing-visibility preference, not an [eligibility gate]"; `:116`). An applicant who has since hidden from discovery would get a **404 on accept** if this leg were reused verbatim. The precedent for dropping it exists and is documented; the kickoff should name it. |
| Hard-blacklist gate (`:136-143`)                              | **Reuse.** Chunk 3's leg 6 only excludes **brand**-hard-blacklisted creators; the agency-wide hard blacklist could have been added _after_ the application was filed. 422 is the right answer.                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| Availability conflict 409 (`:184-201`)                        | **Open question.** It is a soft warn requiring an `acknowledged` re-submit. Accept-from-an-application is a different UX moment; whether the agency sees the conflict dialog is a product call, but skipping it silently loses a real signal.                                                                                                                                                                                                                                                                                                                                                                                                               |
| The create + `invited_at` + `invited_by_user_id` (`:203-225`) | **Reuse.** `invited_by_user_id` becomes the accepting agency user, which is the honest attribution.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| Offer fields                                                  | **Must be collected.** `agreed_fee_minor_units` is required min:1 and the currency must match the campaign — an application carries **no fee** (`campaign_applications` has `status`, `note`, `responded_at` only). So accept **cannot** be a one-click flip: it needs the offer form. See §I5.                                                                                                                                                                                                                                                                                                                                                             |
| Hand-written audit + event (`:232-249`)                       | **Reuse.** This is the machinery hook.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| Atomicity                                                     | **New.** `store()` has no explicit transaction; the state machine's `commit()` does (`:601-640`, `DB::transaction` at `:612`, the event dispatch at `:627`). A cross-table accept needs its own `DB::transaction` wrapping the application flip + the assignment create, and the event dispatch inside a transaction is already the house pattern (`CreateAssignmentAvailabilityBlock` docblock `:34-37` documents relying on it).                                                                                                                                                                                                                          |

### The state machine's constraints, restated for this chunk

- `invited` is the graph's **entry**, set by the create — not by an edge. The class docblock says so
  explicitly (`CampaignAssignmentStateMachine.php:67-70`: "the `invited` ENTRY state is set by the
  invite flow (Chunk 2), not by a transition here").
- Once at `invited`, the creator's own choices are **unchanged**: `accept()` (`:209-224`),
  `decline()` (`:79-92`) and `counter()` (`:94-119`) all `assertSource([Invited])`, and the controller
  is fail-closed on the same state ("only an `invited` assignment may be accepted, declined or
  countered", `CreatorAssignmentController.php:138-149`, 422 `assignment.not_invited`). So **yes: an
  applicant can still decline the actual offer.** Note the UI subset — **countering was removed from
  the creator surface** by the re-offer-after-decline chunk (AH-035): "the creator only accepts or
  declines, and the agency re-offers a declined invite with new terms… Negotiation happens in the
  shared conversation, not a counter fee-form" (`CreatorAssignmentsPage.vue:12-15`), with accept /
  decline rendered per row (`:75-97`, `:199-225`). The machine's `counter()` edge still exists; the
  creator just has no button for it. Nothing in the machine, the resource, or the creator SPA
  distinguishes an application-sourced invitation from a cold one.
- `CampaignApplicationStatus` has **no state machine class and no edges out of either terminal**
  (`:42-45`, docblock `:23-40`: "The transition guard is the source-status check at the (chunk 4) call
  site"). So the application's guard is code chunk 4 writes, not an inherited `assertSource`.

**The machinery's verdict on the product question:** apply pre-committing the creator is **not
expressible** without changing the assignment state machine — there is no "pre-accepted" entry, and
every path into the creator-facing accept/decline surface runs through `invited`. The recommendation
in the brief (accept creates a standard invitation; apply ≠ contract) is the option the code supports
with zero machine change. The cost is the one named above: the creator is told nothing unless chunk 4
adds a notification.

---

## I3 — The reject path, and where the campaign-terminal hook can go

### What a manual reject needs, mechanically

1. **Status flip + `responded_at`.** `CampaignApplicationStatus::Rejected` (`:45`) and the
   `responded_at` column both already ship; the migration docblock is explicit that chunk 4 writes it
   (`2026_07_27_110000_create_campaign_applications_table.php:89-91`). `CampaignApplication`'s
   `$fillable` carries `status`/`responded_at` and `$casts` maps them (`CampaignApplication.php`), so
   no model change is needed either.
2. **A source guard, hand-written.** `CampaignApplicationStatus` has **no state-machine class** and
   the enum docblock says the guard "is the source-status check at the (chunk 4) call site"
   (`:23-40`). So reject must assert `status === Pending` itself. §5.6's fail-closed posture and the
   `assertSource()` habit (`CampaignAssignmentStateMachine.php:590-596`) are the shape to copy;
   `isTerminal()` (`:53-59`) already exists to express it, and re-rejecting an already-rejected row
   must be a no-op or a 422 — not a second `responded_at` overwrite.
3. **The notification — AH-051 dual-emit.** The house pattern is one `try/catch`-free helper that
   writes the in-app row **and** queues the mail, so a mail failure cannot swallow the in-app row.
   `SendAssignmentNotifications::notifyCreatorOfReview()` is the closest working example
   (`:162-220`): resolve the creator's `User`, `Notification::create(...)` with the
   `NotificationType`, then `Mail::to(...)->queue(...)` with the recipient's locale. AH-051 recorded
   the rule that both legs fire from one place (`adhoc-changes-log.md`, AH-051 entry). Note the
   standing gap: the **email leg is not routed through notification preferences**
   (`docs/tech-debt.md:2075-2081`, an AH-056 entry) — a creator who disables a preference still gets
   the mail. Chunk 4 inherits that debt; it does not create it.
4. **No re-apply — already enforced, and it composes for free.** The unique
   `(campaign_id, creator_id)` pair (`:97`) plus the deliberate **absence of SoftDeletes**
   (docblock `:36-40`: "'No re-apply after rejection' is implemented as the RETAINED terminal row keeping
   the unique pair occupied… there is no row-removing path for this table in any chunk of the arc") means a
   rejected application permanently occupies the pair. `CreatorJobBoardController::apply()` reads the
   existing row and returns 409 with `reapplyBlockCode()` (`CampaignApplicationStatus.php:80-85`), so
   the moment reject writes the status, the creator's apply button is already dead. **Nothing new is
   required for the no-re-apply behaviour** — which is why D1 chose the retained-row design.
5. **Optional reason.** See §I5 for the argument and the precedents; mechanically the column would be
   net-new (`campaign_applications` has `note` — the **creator's** application note — and no agency
   field), so "reason" is not free the way `responded_at` is.

### Where campaign status is written — the single write path holds

- **One route.** `PATCH /api/v1/agencies/{agency}/campaigns/{campaign}` →
  `CampaignController::update()` (`Campaigns/Routes/api.php:39-40`). There is **no** `DELETE`
  campaign route and **no** archive/cancel action route (`Routes/api.php:33-41` is the whole
  campaign-resource block). `status` is a plain fillable (`Campaign.php:103`, cast `:249`, default
  `'draft'` `:88`) validated as `['sometimes', new Enum(CampaignStatus::class)]`
  (`UpdateCampaignRequest.php:55`).
- **No state machine, by design.** `CampaignStatus`'s own docblock: "Unlike
  CampaignAssignmentStateMachine, campaign status has no guarded graph this chunk — it is a settable
  CRUD field" (`CampaignStatus.php:18-20`). Any status can be set to any other status, including back
  out of `cancelled`.
- **`rg` confirms no other writer:** no `CampaignStatus::Cancelled`/`Completed` assignment anywhere
  under `apps/api/app`, no admin-side campaign status endpoint. So a hook placed in `update()` catches
  **every** terminal transition that exists today — the same argument the listing-flip detector makes
  about itself (`CampaignController.php:155-168`).
- ⚠ **Terminal status already hides the job.** `scopeListedOnJobsBoard()` is "the flag AND a
  non-terminal status" (`Campaign.php:236-239`, `LISTABLE_STATUSES = ['draft','active','paused']`
  `:85`), and it is leg 4 of the visibility predicate
  (`JobsBoardVisibility.php:110-111`). So the instant a campaign flips to `completed`/`cancelled`, the
  job disappears from every creator board and `apply()` starts 404-ing. **The pending applications
  are the only thing left behind** — which is precisely the gap the arc decision (auto-reject-with-notice)
  closes. Nothing else leaks.

### What the terminal hook would look like — three shapes, costed

**Shape 1 — an in-controller before/after comparison, i.e. the flip-detector precedent.**
`update()` already snapshots the model before the fill (`$before = $this->auditableSnapshot($campaign)`,
`:148`), computes `$wasListed` pre-fill (`:169`), saves (`:181`), and then dispatches off the
**post-save** state (`:196-197`). A status hook is the identical three lines: capture
`$wasTerminal = in_array($campaign->status->value, LISTABLE_STATUSES) === false` pre-fill, and after
the save, if the campaign is now terminal and was not, enqueue a job. The §5.32 note the code already
carries argues for exactly this: the AH-056 plan "reached for a new campaign event to hang this on. It
does not need one" (`:159-168`).
Cost: three lines plus a job class. Risk: it only covers this controller — but this controller is the
only writer, and a future writer is a future problem the same way `listed_on_jobs_board` has it.

**Shape 2 — a `CampaignStatusChanged` domain event + listener.**
Cost: a new event class, a listener, a `CampaignsServiceProvider` registration (`:47-75` is the
existing block), and §5.38's contract question ("does it implement an existing contract or invent a
spine?"). Benefit: any future status writer gets the behaviour for free.
Verdict against precedent: the platform's most recent, explicitly-reviewed decision on this exact
question chose against it, with a recorded reinterpretation (`:159-168`). Choosing an event now
without new evidence would be reversing a §5.32 ruling three weeks old.

**Shape 3 — a model observer on `Campaign::updated()`.**
Cost: lowest line count, highest opacity — fires on every save path including seeders, factories, and
tests, and would emit real mail from a factory unless guarded. No observer precedent exists in the
Campaigns module. Not recommended; reported for completeness.

**Where the work should sit regardless of shape:** in a **queued job**, not inline. The precedent is
exact — `SendJobPostedNotificationsJob::dispatch($campaign->id)` after the save (`:196-197`), with the
comment explaining why not `dispatchAfterResponse()`: "which would run a mail loop inside the web
process (Q3)" (`:190-195`). Auto-reject is the same shape: a loop over N pending applications, each
with a status write and a mail. Two operational notes carry over from AH-056: the fan-out needs a
**running queue worker** (not the scheduler — the arc's unverified-scheduler blocker does not apply,
`RESUMPTION-TEMPLATE.md:197-203`), and it shares the **default queue**
(`docs/tech-debt.md:2120-2124`).

### How many pending applications can exist per campaign

Bounded, and the bound is the roster:

- **Who may apply at all** = visibility leg 3 — the campaign's agency is one the creator is
  **rostered** with and not blacklisted (`JobsBoardVisibility.php:98-108` via
  `AgencyCreatorRelation::scopePermitsMessaging()` = `relationship_status = roster` AND
  `is_blacklisted` false/null, `:150-158`), **and** leg 2 — the creator is `approved`
  (`:93-96`), **and** leg 6 — not hard-blacklisted for the brand (`:120-131`).
- **One application per creator max** — the unique pair (`:96-97`).
- Therefore **max pending applications ≤ the agency's approved, non-blacklisted roster size**,
  and in practice much lower. This confirms the user's read: bounded by roster size. A loop over that
  set in one queued job is safe at present volume; `idx_applications_agency_status`
  (`:98-99`) exists for exactly these status-scoped reads. The auto-reject job should still
  `chunk()`/`cursor()` rather than hydrate the whole set, matching `JobPostedFanOutService`'s posture.
- ⚠ **Idempotency of the auto-reject job matters more than its size.** A campaign can be flipped
  `active → cancelled → active → cancelled`, and `CampaignStatus` permits it. The job must therefore
  filter on `status = pending` inside the transaction (not just at dispatch time) so a re-run cannot
  re-stamp `responded_at` or re-send mail to an already-rejected creator. The **same
  distinguishability question** as the manual reject also lands here: should an auto-rejected creator
  see the same notification as a hand-rejected one? A separate `NotificationType` would double the
  vocabulary and the mailable count; one type with different copy would not. Reported for the kickoff.

---

## I4 — Vocabulary + prefs: what exists, what each new type drags behind it

### The house convention, and what chunk 3 already spent

`AuditAction` is the vocabulary root; `NotificationType`'s **string value must be an exact
`AuditAction` value**, proven at runtime by `auditAction()` (`NotificationType.php:115-122`) and
pinned by `NotificationTypeEnumTest`
(`tests/Feature/Modules/Notifications/NotificationTypeEnumTest.php`) — the enum docblock names it as
the catalogue tripwire (`:16-19`). Chunk 3 already added the pair that chunk 4 extends:

- `AuditAction::CampaignApplicationSubmitted = 'campaign_application.submitted'` (`AuditAction.php:312`),
  written by `CreatorJobBoardController::apply()` via `Audit::log()` (`:247-259`) — **audit only, no
  NotificationType**. This is the deferral D5 recorded, and it is chunk 4's first item.
- `AuditAction::CampaignJobPosted = 'campaign.job_posted'` (`:325`) **with** its
  `NotificationType::CampaignJobPosted` (`NotificationType.php:113`) — the complete worked example of a
  net-new notification type, end to end, one chunk ago. Everything below is measured against it.

Naming: the existing verb is namespaced `campaign_application.*`, so the house-convention siblings are
**`campaign_application.accepted`** and **`campaign_application.rejected`** — not
`application_accepted` (which would collide semantically with the creator-onboarding
`ApplicationStatus`, the exact confusion `CampaignApplicationStatus`'s docblock was written to prevent,
`:14-20`).

### Direction per type

| Verb                             | Recipient        | Mechanism that already exists                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| -------------------------------- | ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `campaign_application.submitted` | **Agency users** | `Agency::notifiableMembers()` (`Agency.php:114-127`) = memberships with role **agency_admin + agency_manager**, `staff` **excluded**, de-duplicated by user id. The seam that loops it is `SendAssignmentNotifications::notifyAgencyMembers()` (`:295-315`), whose docblock notes the membership query hits the non-`BelongsToAgency` `agency_users` pivot so it is queue-safe with no `runAs` (`:286-292`). ⚠ Note the tension worth raising at kickoff: **`staff` can invite** (`CampaignPolicy.php:60-67`) but staff are **not notifiable**. So the role that may accept an application is not necessarily told one arrived. That asymmetry is pre-existing and intentional (`:287-288`), not a chunk-4 bug — but chunk 4 is the first time it bites the same actor twice. |
| `campaign_application.accepted`  | **Creator**      | `notifyCreatorOfReview()`'s shape (`:162-220`): resolve `$creator->user`, bail if no user or empty email, `notify()` + `Mail::to(...)->locale($user->preferred_language ?: 'en')->queue(...)`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| `campaign_application.rejected`  | **Creator**      | Same shape.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |

None of the three is dual-recipient; `NotificationReach = 'both'` is reserved for AH-051's
relation-disconnected verb (`templates.ts:48-54`) and does not apply here.

### The two tripwires, precisely

1. **Backend:** `NotificationTypeEnumTest` — adding a case is a deliberate edit. `AuditActionEnumTest`
   (`tests/Feature/Modules/Audit/AuditActionEnumTest.php`) is the sibling for the audit verb.
2. **Frontend:** `templates.spec.ts` pins `LIVE_TYPES` against the backend `NotificationType` union
   with `DEFERRED_WITHOUT_EMITTER` as an explicit allowlist (`:31-43`). The registry docblock states
   the failure mode it was created to stop: AH-051 shipped two verbs with real emit sites and no
   registry entries, and the suite stayed green (`templates.ts:26-33`). **A chunk-4 type with an
   emitter and no `LIVE_TYPES` row will fail the build** — which is the desired behaviour, and it also
   means an unregistered type is not an option.
   Additionally `i18n-notifications-parity.spec.ts`
   (`apps/main/tests/unit/architecture/i18n-notifications-parity.spec.ts`) is green **at 15 live
   types** (c3 review `:530`); three new live types make it 18 and it demands, per type, a
   `notifications.types.*` template key **and** a `preferences.typeLabels` sibling ×24 locales — the
   pairing the c3 review calls out explicitly (`:527-529`).

### `LIVE_TYPES` rows the three types would need

Each row is `{ templateKey, recipient, preference }` (`templates.ts:71-88`). The registry currently
holds 15 rows under three groups: `assignment`, `creator`, `messaging`
(`NotificationPreferenceGroup`, `:57`). For chunk 4:

- **`campaign_application.submitted`** — `recipient: 'agency'`; group: the c3 precedent put
  `campaign.job_posted` under **`assignment`** rather than open a fourth group, with the reasoning
  recorded verbatim in the enum: "A new group has to earn its keep, and one type does not… the group
  splits when a SECOND jobs-board type exists to split with" (`NotificationType.php:100-107`).
  ⚠ **Chunk 4 creates that second (and third and fourth) type.** So the deferred split is now due, and
  the kickoff should decide it deliberately: either a new `jobs_board` group (a new
  `NotificationPreferenceGroup` union member, a `PREFERENCE_GROUP_ORDER` entry `:245`, a group heading
  key ×24) or a documented decision to keep stacking under `assignment`. The condition the c3 review
  itself set for splitting has been met.
- **`campaign_application.accepted` / `.rejected`** — `recipient: 'creator'`. Channels: `IN_APP_ONLY`
  (`:90-91`); `digest` is messaging-only. Whether these are **toggleable or always-on** (`preference:
null`) is a real call: the `null` docblock reserves always-on for "consequential account news the
  user must not be able to miss" (`:76-86`). An accept/reject on a job you applied for is arguably
  exactly that — and note that suppressing the accepted in-app row would leave a creator with an
  invitation and **no signal at all**, given §I2's finding that `assignment.invited` has no emitter.
  Recommend surfacing this as an explicit kickoff decision rather than defaulting to toggleable.

### Mailables ×24, and the real i18n bill

`campaign.job_posted`'s complete cost, as the measuring stick (c3 review `:524-541`):
`JobPostedMail` (`Mail/JobPostedMail.php:40-73`: `implements ShouldQueue`, `Queueable`,
`SerializesModels`, `Envelope` with `tags: ['campaigns','job-posted']`, markdown view
`mail.campaigns.job-posted` through the shared `catalyst` theme), **5 lang keys ×24** in
`lang/{locale}/campaigns.php` (`lang/en/campaigns.php:83-89`: `subject`, `greeting`, `body`, `cta`,
`ignore`), and locale-at-queue-time via `->locale($user->preferred_language ?: 'en')`
(`JobPostedFanOutService.php:268`).

- **Backend lang:** `apps/api/lang` holds exactly **24** locale dirs (`bg cs da de el en es et fi fr
ga hr hu it lt lv mt nl pl pt ro sk sl sv`). Two creator-facing mailables (accepted, rejected) at
  ~5 keys each = **~240 leaves**; an agency-facing submitted mailable would add ~120 more — and
  whether `submitted` gets a **mail** at all or in-app only is a scope decision worth taking early
  (in-app-only would be the cheapest defensible answer for a high-frequency internal event).
- **SPA i18n:** `apps/main/src/core/i18n/locales/{locale}/*.json`, same 24. Three live types × (1
  `notifications.types.*` + 1 `preferences.typeLabels`) ×24 = **144 leaves** minimum, plus whatever
  §I5's surfaces need (an Applications tab label, table headers, accept/reject buttons, dialog copy,
  toasts, empty state — realistically another 25-40 keys ×24, i.e. 600-960).
- **Parity + flaky-10:** `i18n-locale-parity.spec.ts` covers keyset, placeholder-token parity, and
  CLDR plural-form-count parity (c3 review `:527-529`). §5.22's flaky-10 obligation means **real
  machine translations, spot-checked in the review table** — the c3 review's table is the format
  (`:533-547`), including one backend key. Note `buildPluralRules()` clamps to two forms, so any new
  pluralised key (e.g. "N applications") must use the two-form shape.
- **Mail copy caveat, operational:** changing or adding a queued mailable's copy requires a **queue
  worker restart** to take effect (`RESUMPTION-TEMPLATE.md:201-203`). Chunk 4 adds queued mail, so the
  deploy note must carry it.

**Aggregate vocabulary bill for chunk 4:** 2 new `AuditAction` cases (accepted, rejected — submitted
already exists), 3 new `NotificationType` cases, 3 `LIVE_TYPES` rows, possibly 1 new preference group,
2-3 mailables, and roughly **1,000-1,400 new i18n leaves**. That is chunk-3 scale on the i18n axis,
which is worth stating at kickoff so it is not discovered at the parity gate.

---

## I5 — Agency UI surfaces

### Where applications can render

`CampaignDetailPage.vue` is a `v-tabs` + `v-window` page with **seven** tabs
(`:425-443`): `overview`, `creators`, `board`, `drafts`, `payments`, `messages`, and
`settings` (`v-if="canEdit"`). Each has a matching `v-window-item` with a `data-test="panel-*"`
(`:447`, `:520`, `:790`, …), and `board`/`drafts` render **lazily** on `tab === '<key>'`. Adding an
`applications` tab is therefore a two-line declaration plus a panel — the smallest surface change
available on this page, and it does not disturb any existing tab.

**The direct precedent for the panel itself is `DraftsTab.vue`** (`components/DraftsTab.vue`, with
`DraftsTab.spec.ts` beside it): a campaign-scoped list of creator submissions with per-row review
actions that open a drawer. Applications are the same shape — a queue of creator submissions with two
terminal actions. Per §I1, the board cannot hold them without a `board_cards` schema change, so the
realistic surface options are a tab, or a section on the Creators tab. Reported; not decided.

### Applicant cards — what an agency may see, and why nothing new is exposed

**Applicants are rostered by construction.** Visibility leg 3 requires
`AgencyCreatorRelation::scopePermitsMessaging()` — `relationship_status = roster` **and** not
blacklisted (`JobsBoardVisibility.php:98-108`, `AgencyCreatorRelation.php:150-158`). A creator who is
not on the agency's roster cannot see the job, so cannot apply.

**That is the exact same predicate as the AH-051 contact gate.** `CreatorPolicy::canSeeContactDetails()`
(`:121-146`) passes when the caller actively belongs to the agency **and** that agency holds a
non-blacklisted `roster` relation — enforced through the identical `permitsMessaging()` primitive
(`:143-145`), with the docblock naming it "the shared roster+non-blacklisted primitive (AH-051 D-1)"
and explicitly failing `pending_request / declined / prospect / ended / external` (`:138-140`).

**Conclusion: an applications surface creates NO new exposure.** Every applicant already appears on
the agency's own roster list with their email (`AgencyCreatorController.php:355`, the roster index's `toRow()`)
and, gate-permitting, their optional contact details. The applications surface would be re-displaying
data the same agency user can already read one tab away. Two caveats worth pinning at kickoff:

1. **Blacklist drift.** A creator can be blacklisted _after_ applying. The contact gate is evaluated
   live and would then fail, while the application row persists. The applications surface must read
   contact through the same policy call rather than caching or denormalizing it — otherwise a
   blacklisted creator's contact leaks through the stale row. That is the one genuinely new failure
   mode this surface introduces.
2. **Minimum viable card.** Nothing forces contact onto the card. Display name + avatar + the
   creator's application `note` + applied-at is sufficient to decide, and matches the "don't widen the
   payload because you can" posture the c3 review took on the brand subset. A "view profile" link to
   the existing roster/discover profile keeps the richer data behind its existing gate.

### The accept dialog — the existing dialogs, and why neither drops in cleanly

| Dialog                     | Shape                                                                                                                                                                                                                                                                                                             | Fit for accept                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| -------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `InviteCreatorsDialog.vue` | **Roster-sourced multi-select** ("Mirrors `AddCreatorsToPoolDialog` (roster-sourced multi-select…)", docblock `:4`), `selected` as a `Set<string>` (`:56`), a single fee applied to every selected creator (`:15`, `:306`), fetches the whole roster on open (`:104-105`), loops N invite calls (`:184`, `:217`). | ⚠ **Wrong subject shape.** Accept has exactly one pre-chosen creator. Reusing it means opening a roster picker for a creator who is already identified. Its offer fields (fee, currency, `fee_per`, `offer_description`, attachment via the campaign-keyed presigned pair `:151-156`, deliverables, due dates) are exactly what accept needs — but the selection half is dead weight, and its per-creator loop + `rosterEmpty` states are irrelevant. |
| `ReinviteDialog.vue`       | **Single-assignment, single-purpose offer dialog** — "the agency's response to a creator counter", major-unit input → minor on the wire (`:80-81`), campaign currency as a read-only suffix (`:42`), per-field 422 binding via `extractFieldErrors` (`:38`, `:120`).                                              | ✔ **The right shape, the wrong payload.** It submits fee only; accept needs the full offer set. This is the closest architectural precedent: a focused, one-subject offer dialog for a specific lifecycle moment.                                                                                                                                                                                                                                     |

**The load-bearing constraint from §I2:** an application carries **no fee** — `campaign_applications`
has `status`, `note`, `responded_at` only — while `agreed_fee_minor_units` is `required|integer|min:1`
and the currency must equal the campaign's (`InviteAssignmentRequest.php:37-38`, `:67-83`). So
**accept can never be a one-click button**; it must open an offer form. The realistic options are
extracting the offer-fields form out of `InviteCreatorsDialog` into a shared child used by both, or a
new `AcceptApplicationDialog` on the `ReinviteDialog` pattern. Either way, `InviteCreatorsDialog` and
the accept dialog must not drift on validation, currency handling, or the attachment presigned pair.

### The reject dialog — required or optional reason?

**Precedents, both directions:**

- **Required, admin-tier:** `AdminRejectCreatorRequest` — `rejection_reason` is
  `['required','string','min:10','max:2000']` (`:36`), with the docblock reasoning: "admin must
  explain why so the creator gets actionable feedback on the rejected-state dashboard surface"
  (`:14-19`), plus a named SPA mirror (`apps/admin/.../RejectCreatorDialog.vue`).
- **Required, agency-tier:** the draft-review reject and request-revision both require
  `review_feedback`, persisted on the draft's review trail in the same transaction and rendered to the
  creator (`ReviewDraftDrawer.vue:264-274` the feedback textarea, `:149-154` the payload, docblock
  `:12` "per-field 422 binding on `review_feedback` (the canonical pattern)";
  `CampaignAssignmentReviewController`).
- **Optional / absent:** the creator's own **decline** takes no reason
  (`CampaignAssignmentStateMachine::decline()`), and availability-block `reason` is
  `sometimes|nullable` (`StoreAvailabilityBlockRequest.php:48`). AH-051's blacklist notification is
  deliberately **not** sent at all, on the reasoning that an unsolicited notice of one's own
  blacklisting is counterproductive (`NotificationType.php:32-35`) — the house does weigh
  creator-facing kindness explicitly, and sometimes chooses less information.

**The argument, as evidence rather than preference.** Every _required_-reason precedent shares one
property: the reason is **rendered back to the creator as actionable feedback**, and the creator can
act on it (fix the profile, resubmit the draft). A rejected application is **terminal and
non-re-appliable** — the retained row permanently blocks re-apply
(`CampaignApplicationStatus.php:36-39`, `:80-85`). A mandatory ≥10-character explanation for an
irreversible "not this time" is a cost paid on every reject with no path for the creator to use it,
and it invites either boilerplate or bluntness at scale. That argues **optional**, with the c3
rejected-notice copy already written and deliberately gentle ("Nem választottak ki" / "Δεν επιλέχθηκες
για αυτή τη δουλειά", c3 review `:537-539`) — i.e. the kind rendering already exists and does not
depend on a reason.

Against that: an optional reason is a **new nullable column** on `campaign_applications` either way,
so "optional" is not cheaper than "required" in schema terms — only in UX terms. And **no column at
all** is the cheapest option and the only one that adds no migration to a chunk that currently needs
none (`responded_at` already ships). The three-way choice — required / optional / none — is a kickoff
decision; the evidence above is what it should be decided against.

**The confirm-dialog pattern to copy either way:** `ReviewDraftDrawer.vue:383-390` — "Terminal-action
guard: rejecting has NO edge out of `rejected`, so the destructive call sits behind an explicit
confirmation", with the dialog kept out of the DOM until asked for (`v-if="rejectConfirmOpen"`,
`data-test="review-reject-confirm"`). A reject that cannot be undone gets a confirmation step, not a
bare button.

---

## I6 — Ripple

### The c3 pins chunk 4 touches

**1. The application status enum's terminal semantics.** `CampaignApplicationStatus::isTerminal()`
(`:53-59`) and `reapplyBlockCode()` (`:80-85`) already exist and already work; chunk 4 is their first
_writer_. The pin to respect: the enum has **no state machine**, so the source guard is chunk-4 code
(docblock `:23-40`) — and `CampaignEnumsTest` / `CampaignApplicationSchemaTest`
(`tests/Feature/Modules/Campaigns/`) are the tripwires that will fail if the enum or the table shape
moves.

**2. The applicant-count `withCount`.** `Campaign::applications()` counts **every** status —
`pending`, `accepted`, `rejected` (`Campaign.php:170-180`) — and `CreatorJobCardResource` surfaces it
as `applicant_count` with the docblock "Every application status counts — 'how much interest does this
job have'" (`:89-91`, mirrored in `packages/api-client/src/types/campaign.ts:849-851`). ⚠ **Chunk 4
makes that count start moving without the number changing**: accepting or rejecting leaves the count
identical, which is the intended "interest" semantic — but an agency-side count of _pending_ (the
thing an agency actually wants a badge for) is a **different** count and must not be conflated with
this one. If the Applications tab shows a badge, it needs its own scoped count, not this `withCount`.

**3. Creator-side rendering — the list already says "Accepted"; the detail does not.** Worth stating
precisely, because the two pages disagree:

- **List (`CreatorJobsPage.vue:114-123`)** interpolates the status into the i18n key —
  `t(`creator.ui.jobs.status.${application_status}`)` (`:121`) — and **all three keys already ship in
  all 24 locales**: `pending: "Applied"`, `accepted: "Accepted"`, `rejected: "Not selected"`
  (`apps/main/src/core/i18n/locales/en/creator.json`; spot-checked pl `Zaakceptowano`, fi `Hyväksytty`,
  hu `Elfogadva`). Colour is `error` for rejected, `primary` otherwise (`:118`). So the accepted chip
  **already renders correctly with no new work** — a c3 side-effect of interpolating the enum.
- **Detail (`CreatorJobDetailPage.vue:259-289`)** branches `rejected` → `rejectedNotice` (`:260-267`),
  then **`v-else-if="status !== null"` → `appliedNotice`** (`:270-277`), then Apply (`:280-288`). The
  `detail` keyset has `appliedNotice` and `rejectedNotice` and **no accepted equivalent**. So an
  accepted applicant on the detail page is told "you applied" — indistinguishable from pending, and
  with no pointer to the offer now waiting for them.

**4. Accepted-state rendering is NEW creator-side work — and the hand-off path is confirmed.** The
standard invitation surface does take over: `GET /creators/me/assignments`
(`CreatorAssignmentController.php:43-58`) → `CreatorAssignmentsPage.vue` (route
`creator.assignments`, `apps/main/src/modules/creators/routes.ts:53-56`, whose comment reads "the creator's
campaign-invitation surface (accept / decline / counter). Consumes GET creators/me/assignments",
`:52-53`), with the per-assignment
detail at **`/creator/assignments/:ulid`** → `creator.assignment.detail` →
`CreatorAssignmentDetailPage.vue` (`routes.ts:62-73`). The response actions require `invited`
(`CreatorAssignmentController.php:138-149`),
which is exactly where the accept transaction lands the row. **So the machinery hand-off needs no new
creator surface** — what it needs is the _bridge_: the accepted job card should say "accepted" and
point at the assignment, otherwise the creator is left on a jobs page that still says "applied" while
an offer sits on a different page they have no reason to visit. Combined with §I2's
no-notification finding, this is the single most user-visible gap in the chunk.

### `api-client` types

`packages/api-client/src/types/campaign.ts` already carries the whole c3 vocabulary:
`CampaignApplicationStatus` (`:825`), `CreatorJobBrand` (`:833-841`), `CreatorJobCardResource`
(`:843-861`), `CreatorJobDetailResource`, `ApplyToJobPayload`, `CreatorJobApplyResponse` (`:888-901`),
and the two 409 codes. Chunk 4 adds an **agency-side** shape that does not exist yet — an application
list item (creator identity + note + applied-at + status), its list response, an accept payload
(which is the invite payload's offer subset), and a reject payload. Note `board.ts` and `campaign.ts`
are separate type modules, which mirrors §I1's conclusion that applications are not board cards.

### i18n scope

Per §I4: 3 notification templates + 3 preference labels (144 leaves), 2-3 mailables (~240-360 backend
leaves), plus the agency surfaces' own copy. The SPA locales live at
`apps/main/src/core/i18n/locales/{locale}/*.json` (24 dirs), the backend at
`apps/api/lang/{locale}/` (24 dirs). Gates: `i18n-locale-parity.spec.ts` (keyset + placeholder tokens

- CLDR plural form counts) and `i18n-notifications-parity.spec.ts`
  (`apps/main/tests/unit/architecture/`, green at 15 live types → 18). §5.22 flaky-10 spot-values
  table required in the review, c3's (`jobs-board-c3-review.md:533-547`) as the format.

### Board specs — what changes if §I1 goes the panel route (nothing) vs the column route (a lot)

The board's test surface is large and would all be in scope for a card-shape change:
**API** — `BoardApiTest`, `BoardAutomationServiceTest`, `BoardColumnDeleteTest`, `BoardLazyHealTest`,
`BoardManualMoveTest`, `BoardManualMoveIsolationTest`, `BoardProvisioningServiceTest`,
`BoardResetTest`, `BoardSchemaSmokeTest`, `CreateBoardCardTest`, `OverdueScanTest`
(`apps/api/tests/Feature/Modules/Boards/`). **SPA** — `BoardView.spec.ts`, `BoardColumns.spec.ts`,
`BoardCard.spec.ts`, `BoardCardDrawer.spec.ts`, `BoardColumnDialog.spec.ts`,
`BoardColumnDeleteDialog.spec.ts`, `BoardAutomationDialog.spec.ts`, `useBoardStore.spec.ts`,
`useBoardPoll.spec.ts`, `boardTokens.spec.ts`, `board.api.spec.ts`.
`BoardSchemaSmokeTest` and `BoardManualMoveIsolationTest` in particular encode the "a card is an
assignment" and "a move has no state-machine path" invariants — i.e. the two things a real Applied
column would have to renegotiate. **The panel route touches none of these**, which is a meaningful
cost signal for the I1 decision.
An accept, by contrast, exercises the board **positively and for free**: `CreateBoardCardTest` and
`BoardAutomationServiceTest` already pin `invited → card in "Invited"`, so a chunk-4 test asserting an
accepted application produces a card in the Invited column is an assertion, not new machinery.

### Playwright exposure

The suite is **16 spec files** (`apps/main/playwright/specs/`) across **two projects**
(`apps/main/playwright.config.ts`): `chromium` on Desktop Chrome, which `testIgnore`s
`MOBILE_ONLY_SPECS` (`:86-91`), and `mobile` on the iPhone 13 profile, whose `testMatch` is **scoped to
`MOBILE_ONLY_SPECS` only** (`:100-115`, currently `creator-shell-mobile.spec.ts` alone). The c3 leg is
`creator-jobs-board.spec.ts` — browse, detail, apply, duplicate-apply — seeded by the
`POST /api/v1/_test/creators/listed-job` helper (`apps/api/app/TestHelpers/Routes/api.php:117`,
`CreateListedJobController`). The agency-side precedent for a campaign-surface spec is
`bulk-invite-creators.spec.ts`.

A c4 leg needs: a **seeded pending application** — the existing helper creates a _listed job_, not an
application, so either it gains a flag or a second helper appears; then the agency signs in, opens the
applications surface, accepts one (offer form) and rejects another, and asserts the two terminal
states. Cross-role assertions (the creator then seeing the invitation) belong to the **full-lifecycle
spec in chunk 5**, per the arc plan — a c4 leg should stay agency-side. Note the mobile project's scoping above: a new c4 spec runs **desktop-only** unless it is
explicitly added to `MOBILE_ONLY_SPECS`, so AH-057's iPhone 13 leg
(`RESUMPTION-TEMPLATE.md:135-143`) does not automatically cover the new agency surface — and that
project runs on the **Chromium engine, not WebKit**, for the host reason recorded there.

### `tenancy.md` §4 — new agency routes need **no** allowlist entry

Section 4 is the cross-tenant allowlist (`docs/security/tenancy.md:89`), and c3's three creator routes
are documented there (`:183-185`) precisely because they are **creator-scoped and drop
`BelongsToAgencyScope`**. Chunk 4's routes are the mirror image: agency-scoped, mounted under
`/api/v1/agencies/{agency}/campaigns/{campaign}/…` with the full `auth:web` + `tenancy.agency` +
`tenancy` stack (`Campaigns/Routes/api.php:30`), reading a table that **carries `agency_id` and
`BelongsToAgency`** (`create_campaign_applications_table.php:24-28`, columns `:63-70`). They are ordinary tenanted
routes and **must not** be added to §4 — adding them would dilute the allowlist's meaning. What they
do need is the house pattern: `assertBelongsToAgency()` first, then `Gate::authorize()`
(`CampaignAssignmentController.php:96-97`), and the ability tier chosen deliberately (`invite` =
admin+manager+staff, per §I2).

Two doc surfaces that **will** need updating: `docs/tech-debt.md` (if the preference-unaware email
channel or the shared default queue is inherited again) and `docs/feature-flags.md` — WORKING-PROCESS
§163-164 requires new emails/notifications to be flag-gated, and
`job_posted_notifications_enabled` (`docs/feature-flags.md:52`, default OFF) is the precedent for a
jobs-board mail flag. Chunk 4 emits mail to live users, so the flag question is not optional.

---

## The three answers that shape the kickoff

**1. Column vs panel (§I1).** The column set is DB-driven and a new column is cheap — but **a board
card IS an assignment** at the schema (`board_cards.assignment_id` NOT NULL + UNIQUE + CASCADE,
`create_board_cards_table.php:60-65`), the resource (`BoardCardResource.php:17`), and the SPA
(`BoardCard.vue:33`). An Applied column is therefore an empty column unless chunk 4 changes the shape
of a populated `board_cards` table, and the board's one interaction primitive — drag — is defined as
having "STRUCTURALLY NO reference to the assignment state machine" (`BoardCardMoveService.php:20-26`), so it cannot
express accept/reject. Meanwhile the board already handles the _post_-accept state perfectly, for
free, via `CreateBoardCard` + the `assignment.invited → Invited` automation. The evidence points to a
**campaign-detail Applications tab** (the `DraftsTab` shape) with the board unchanged — but note the
direction of the §5.32 obligation: "chunk 4's agency-side board column" is written into the shipped c3
migration docblock as a locked arc decision, so it is the **panel** that must be recorded as a
reinterpretation, with the `board_cards` shape as its evidence.

**2. Accept-creates-invitation (§I2).** The machinery supports it with **zero state-machine change** —
`invited` is an entry set by `create()`, and every creator response path
(`accept`/`decline`/`counter` — the last has had no creator UI since AH-035) already asserts `invited`
as its source, so an applicant can still decline the real offer.
Pre-committing the applicant is **not expressible** without new machine states. The constraints to
honour: the offer fields are **required** (fee min:1, currency = campaign currency), so accept needs a
form, not a button; the `is_discoverable` leg must **not** be reused verbatim (AH-051's admin-connection
bypass is the precedent); the hard-blacklist re-check **must** be; the existing-assignment idempotency
branch silently discards an offer today (`CampaignAssignmentController.php:176-178`) and chunk 4 must
say what accept means for each pre-existing status; and the whole thing needs an explicit
`DB::transaction` that `store()` does not currently have. **And the finding to decide against:**
`assignment.invited` has **no emitter** anywhere — no mail, no in-app row, `DEFERRED_WITHOUT_EMITTER`
in the FE registry — so a reused invite path tells the accepted creator nothing.

**3. Terminal-hook placement (§I3).** `CampaignController::update()` is the only writer of campaign
`status` (no DELETE route, no admin endpoint, `rg`-confirmed no other assignment), `CampaignStatus` has
no guarded graph by design (`CampaignStatus.php:18-20`), and the **listing-flip detector three lines
away** is the recorded §5.32 precedent for doing exactly this without a new event
(`CampaignController.php:155-197`). A pre-fill/post-save terminal comparison that dispatches a queued
job is the shape with precedent; a `CampaignStatusChanged` event would reverse a three-week-old
recorded ruling without new evidence. The pending set is bounded by the approved, non-blacklisted
roster (visibility leg 3), so a chunked queued job is safe — the harder requirement is
**idempotency**, because `active → cancelled → active → cancelled` is permitted and the job must
re-filter on `pending` inside its transaction.

---

_End of inventory. No plan, no code, no edits beyond this file._
