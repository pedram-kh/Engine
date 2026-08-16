# Draft Workflow v2 — Chunk B (optional posting flow): Plan

- **Status:** **CLEARED AND BUILT** (2026-08-16). This file is left as the plan-pause artefact it was,
  committed at `59455604` **before** any code, and it is deliberately **not** back-edited to match
  what shipped — a plan rewritten after the fact stops being evidence of what was proposed. Where the
  build diverged (Q1 killing S3 and the backfill command, D3 dropping the `withoutGlobalScope` read),
  the divergence is recorded in the review file, not patched into this one.
- **Outcome:** [`draft-posting-toggle-review.md`](draft-posting-toggle-review.md).
- **Entry:** AH-069.
- **Chunk base:** `45ee2e7f` — `docs: close AH-068 — Draft Workflow v2 chunk A reviewed and approved`.
  Every citation below was re-derived at this SHA, **after** chunk A shipped. See §1.
- **Reads from:** [`draft-workflow-v2-inventory.md`](draft-workflow-v2-inventory.md) §§0.2–0.3, 5–8 —
  cited where it still holds, **corrected where AH-068 moved the ground under it** (§1).
- **Binds to:** [`contract-toggle-off-flow-review.md`](contract-toggle-off-flow-review.md) (AH-042 —
  the chained-transition and dry-run-command precedents this chunk reinterprets),
  `adhoc-changes-log.md` AH-043 (the seven-listener sweep lesson), AH-047 (the creator banner
  sibling), AH-054 (the campaign store-whitelist catch), AH-059 (the E2E stopping point).
- **One structural catch is load-bearing and sits in Q1.** D1's "DB default FALSE" combined with the
  column name D1 proposes makes the **new** flow the default for every new campaign, and — worse —
  opens a deploy window in which every existing campaign reads OFF while the machine edge is live.
  A draft approved in that window lands in an **irreversible terminal state**. §7 Q1 is where that
  gets decided, and it is the one question I would not build past.

---

## 0. §5.40 re-derivation — the binding declaration

The kickoff's scoping line said MEDIUM. On the plan actually proposed below, I re-derive it as:

### ⚠️ PROD-DATA RISK: MEDIUM

Named in plain language, operation by operation:

1. **One additive column on `campaigns`** (`creator_posts_content`, boolean, NOT NULL, defaulted).
   The migration itself rewrites nothing: on Postgres 16 (`docker-compose.yml:3`, `DB_CONNECTION=pgsql`)
   `ADD COLUMN … NOT NULL DEFAULT <const>` is a catalogue-only operation — existing rows are not
   rewritten and read the default. **No existing campaign row is read-for-mutation.** This leg is LOW
   and has a direct in-house precedent at `2026_06_03_110000_add_is_discoverable_to_creators_table.php:15-22`
   ("Default TRUE: every approved creator is discoverable today"), which is `@migration-risk low`.
2. **A backfill command that writes one column on every pre-existing campaign row** — _only if Q1 is
   ruled to D2's letter._ This is the single operation that earns the MEDIUM: it is a total-predicate
   write across a populated table. `--dry-run` first, counts recorded in the deploy log, guarded and
   idempotent. **If Q1 is ruled to the defaulted-ON shape, this leg disappears entirely and the
   chunk's risk drops to the state-machine leg alone.**
3. **A new terminal state written to live assignment rows going forward.** No historical assignment
   is read-for-mutation, no status is rewritten, no row is deleted. But the new edge changes what a
   live assignment _can do next_, and the target is **terminal**: `cancel()` refuses a terminal
   assignment (`CampaignAssignmentStateMachine.php:564-566`) and `markPosted()` only accepts
   `approved` (`:412`), so an assignment that lands there **cannot be moved by any application
   path**. That is the sharpest edge in this chunk and it is why Q1 matters: a wrong toggle read at
   approval time is not a cosmetic bug, it is an unrecoverable one.
4. **Zero row deletion on boards.** D6 is a render filter. No `board_columns` row is created,
   deleted, renamed, or retargeted; no `board_cards` row moves. Pinned by a before/after row-count
   assertion, not by prose (S6).
5. **New mail + a new notification to real creators.** Mail copy changes ×24 locales ⇒ the
   **queue-worker restart** is a deploy obligation (the AH-068 precedent). No new email goes to a
   user who was not already being emailed at this transition.
6. **`down()` honesty.** The migration's `down()` drops the new column. That loses every campaign's
   toggle value and is stated as such in the migration docblock — a true inverse of the schema, an
   admitted non-inverse of the data.
7. **Pre-deploy snapshot mandatory** (migration + possible one-shot). Deploy order documented in
   the review file and in `deploy-log.md` as a PENDING entry before the push.

What is **not** in this chunk's risk: no destructive DDL, no type narrowing, no deletion path, no
flag armed by default, no historical notification or audit row touched.

---

## 1. The AH-068 drift report

The kickoff's instruction was to read the post-A reality and flag drift rather than trust the
inventory's pre-A citations. I re-derived every inventory citation that touches the surfaces chunk A
renamed. **Six are stale, one is unchanged, and one is stale in a way that matters.**

| Inventory citation                                       | Inventory said                   | Reality at `45ee2e7f`                                                                                                                 | Verdict                      |
| -------------------------------------------------------- | -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------- |
| `SendAssignmentNotifications.php:83-118`                 | the transition→notification map  | the `match ($event->action)` is now **`:74-82`**; `notifyCreatorOfManualVerification` is **`:124`**; the review handler is **`:204`** | **STALE — and load-bearing** |
| `CreatorAssignmentDetailPage.vue:176-178` (`isVerified`) | the predicate to fork for D7     | **`:178-180`**                                                                                                                        | STALE (+2)                   |
| `CreatorAssignmentDetailPage.vue:898-915`                | awaiting-verification + verified | awaiting is **`:904-911`**, the AH-047 notice is **`:914-921`**                                                                       | STALE (+6)                   |
| `CreatorAssignmentDetailPage.vue:484` (status label)     | dynamic label consumer           | **`:490`**                                                                                                                            | STALE (+6)                   |
| `DraftsTab.vue:200` (status label)                       | dynamic label consumer           | **`:206`**                                                                                                                            | STALE (+6)                   |
| `BoardCardDrawer.vue:378` (status label)                 | dynamic label consumer           | **`:382`**                                                                                                                            | STALE (+4)                   |
| `CampaignDetailPage.vue:618` (status label)              | dynamic label consumer           | **`:618`** — unchanged                                                                                                                | HOLDS                        |
| `locales/*/app.json:728-744` (status labels)             | 16 keys, the block to extend     | still opens at **`:728`**                                                                                                             | HOLDS                        |

**Why the first row matters and is not just arithmetic.** The inventory's §6.2/§8.3 use
`SendAssignmentNotifications.php:83-118` as the anchor for "what a new verb would flow through". That
range no longer describes the dispatch at all — chunk A inserted a round-resolution step and pushed
the handlers down. More importantly, the listener now **threads `$event->context`** into its
payloads (`:96-101`, `roundFromContext`), which is a mechanism this chunk should reuse rather than
reinvent — see §4 and S4. Reading the stale range would have missed the one new thing chunk A gave
chunk B for free.

**Two further post-A facts the inventory could not know:**

- `lang/*/campaigns.php` ×24 gained an `assignment_notifications.round` / `round_subject` pair, and
  both draft mailables now compose their subject through `Mail/Concerns/CarriesDraftRound.php`. A new
  mailable in this chunk carries no round, so it takes chunk A's §5.3 **test shape** — now the house
  standard — and not the trait.
- Two i18n keys the inventory cites no longer exist: `app.campaigns.review.draftVersion` (renamed to
  `draftRound`) and `creator.ui.assignments.detail.reviewStatus.*` (removed as orphaned). Neither is
  in this chunk's path, but any plan text quoting them would be quoting a deleted key.

Everything else in §§5–8 of the inventory I re-verified as still true at `45ee2e7f`.

---

## 2. Read-pass findings — per-decision dispositions

### 2.1 D1 — CONFIRMED, with the write path's exact list

Three booleans exist on `campaigns`, in three distinct patterns
(`Campaign.php:87-92` / `:115-119` / `:259-263`). The one to copy is
`requires_per_campaign_contract` (create **and** Settings). The AH-054 catch is real and unchanged:
`CampaignController::store()` persists through an **explicit `create([...])` whitelist**
(`:74-113`, the boolean at `:103`), not `$fillable`, so a field can validate, return 201, and never
persist. `update()` is safe by contrast — it uses `$request->validated()` + `fill()` (`:144-189`).

The complete list a new boolean must be registered in, as a checklist: migration → `Campaign`
docblock / `$attributes` / `$fillable` / `casts()` → `CreateCampaignRequest::rules()` →
`UpdateCampaignRequest::rules()` → **`CampaignController::store()`'s array** → `auditableSnapshot()`
(`:289-302`, where both _live_ toggles are listed and the dead `is_marketplace_visible` is not) →
`CampaignResource` (`:49-56`) → `CampaignFactory` → `packages/api-client` `CampaignAttributes` +
`CreateCampaignPayload` + `UpdateCampaignPayload` → `CampaignForm.vue` → `app.json` ×24.

**Pre-existing gap found, worth naming:** `CampaignCrudTest` sends
`requires_per_campaign_contract => true` in its payload but **no test asserts the persisted DB
value** on create. The AH-054 discipline exists precisely to pin that. This chunk will pin it for the
new column; the old column's missing assertion goes to `tech-debt.md` rather than being silently
adopted.

### 2.2 D2 — OVERTURNED as mechanism, intent preserved. See Q1.

The kickoff locks "DB default FALSE → command flips existing rows to TRUE". Two facts make that the
riskier of the available shapes:

1. **Polarity.** With the name D1 proposes, `false` means "creators do NOT post" — which is the
   **new** flow. So `default(false)` makes the new, never-shipped lifecycle the default for every
   campaign created after deploy, and requires a command to rescue every campaign created before it.
   D2's own stated intent is "no live campaign changes behavior at deploy"; a defaulted-`false`
   column achieves the opposite for the whole table until the command runs.
2. **The deploy window is not benign.** Between `migrate` and the command, every campaign reads OFF.
   If the machine edge ships in the same deploy, an agency approving a draft in that window drives a
   live assignment into `completed_on_approval` — a **terminal** state with no application path out
   (`:564-566`, `:412`). Minutes of exposure, unrecoverable outcome, on real rows.

`default(true)` inverts both problems away: existing rows read ON by catalogue-only DDL (no window,
no command, no rewrite), new campaigns default to today's behaviour, the agency opts **into** the new
flow, and the column still reads in the same direction as D1's label. The house has done exactly this
on a populated table before (`add_is_discoverable_to_creators_table.php:15-22`). **Q1 is the ruling.**

### 2.3 D3 — CONFIRMED, and the transaction is already there

The name and the fail-closed posture hold. Two corrections to the mechanism:

- **The "AH-042 accept-pattern verbatim" is the wrong verbatim here.** AH-042 opened a **new** outer
  `DB::transaction` (`CreatorAssignmentController.php:60-85`) because the creator's accept endpoint
  had none. The approve path already runs inside one:
  `CampaignAssignmentReviewController::review()` wraps the draft-trail write **and** the machine call
  in a single `DB::transaction` (`:196-214`). The chained call belongs **inside that existing
  closure** — adding a second nested transaction would be noise, and the "ONE transaction"
  requirement is already satisfied by the code as it stands. Intent preserved, mechanism corrected.
- **`withoutGlobalScope(BelongsToAgencyScope::class)` is cargo here.** AH-042 needed it because the
  actor is the creator, who has no agency scope. This controller receives the **route-bound
  `Campaign $campaign`** already asserted to belong to the agency (`:154-165`), so the toggle is read
  from a model in hand — no query, no scope removal. Copying the scope-strip would import a
  workaround for a problem this surface does not have.

`['auto_advanced' => true]` on the chained hop is kept verbatim — it is the audit-distinguishability
property, and it composes with the existing three contract-less signatures.

### 2.4 D4 — CONFIRMED, and the ripple is bigger than the inventory's table by three sites

The inventory's §8.4 table holds. Three additions found in this pass:

- **`BoardCardService::representativeEventKey()`** (`:135-152`) is exhaustive with **no `default`** —
  the inventory lists it, but not what the right arm is. It must map to
  `AuditAction::AssignmentDraftApproved->value`, i.e. the **Approved** column. Mapping it to `null`
  looks harmless and is not: `resolveColumnForState()` falls back to the invited key and then to the
  first column by position (`:84-111`), so a card-less completed assignment would be lazy-healed into
  **Invited**. This arm is what keeps lazy-heal agreeing with where the card already sits.
- **`CreatorJobDetailTest.php:413-448`** is a 16-row Pest dataset over every status → expected
  `assignment_state`. A 17th case needs a row or it is simply never exercised end-to-end.
- **`MessageThread::HUMAN_SEND_BLOCKED_STATUSES`** (`:63-67`) is a **silent** site: it blocks
  `declined`/`rejected`/`cancelled` and deliberately leaves `payment_released` (terminal _success_)
  chat-open. The new case is a terminal success, so it must **not** be added — recorded as a
  deliberate omission with the `payment_released` precedent, not an oversight.

The order failures fire in is worth stating because it is the D4 break-revert: adding the case with
no family arm reds **PHPStan** (`JobLifecycleState.php:73-92` and `BoardCardService.php:135-152`,
both `default`-free, at level max) **before any test executes**.

### 2.5 D5 — the seven listeners are verb-gated, not status-gated. That changes the sweep's answer.

The finding that shrinks this decision: **all seven listeners gate on `$event->action`, never on
`$event->to`** (registration: `CampaignsServiceProvider.php:47-75`). Four use an early
`if ($event->action !== …) return;`, one uses an `in_array` allowlist, one uses a `match` with an
explicit `default => null`, and one delegates to an event-key lookup that finds nothing. So a new
verb is a **silent no-op in all seven, with no `UnhandledMatchError` anywhere** — nothing has to be
suppressed, and every listener that should speak has to be **opted in deliberately**. That is the
§5.38 contract-keyed posture working as designed. The decided table is §4.

### 2.6 D6 — CONFIRMED as render-filter, and the filter kills the inventory's sharpest edge

API-side, and the argument is concrete: `BoardController::show()` (`:40-58`) and `reset()`
(`:61-82`) both have the route-bound `Campaign` in hand, so the filter costs no query; the SPA has
**three** consumers of `store.sortedColumns` (the grid, the automation target picker
`BoardAutomationDialog.vue:43-48`, the delete-dialog destination list) and a FE filter would have to
be applied to each, with every future consumer silently regaining the column. Filtering at the wire
means the SPA has no concept of a hidden column to get wrong.

Two consequences the kickoff did not name:

- **The automations must be filtered too, or three rows render with a blank target.**
  `isBroken()` only tests `target_column_id === null` (`BoardAutomationDialog.vue:34-39`), so the
  three Posted-targeting automations (`BoardDefaults.php:57-59`) would _not_ show as broken — they
  would show as a `v-select` whose current value is absent from its options. The filter therefore
  drops both the column and the automations whose target is a filtered column. The DB rows are
  untouched; on an OFF campaign those three verbs are unreachable anyway.
- **`BoardResetService` stops being an edge.** The inventory called reset-resurrects-the-column "the
  sharpest edge I found in IB3" (§7.3). With a render filter it is not an edge at all: reset re-adds
  a row that is still filtered on the way out. This is the strongest single argument for filtering
  over omitting, and it is worth one sentence in the review file so nobody "simplifies" it back into
  provision-time omission.

**On cards in a hidden column:** `cardsByColumn` silently drops cards whose column is absent
(`useBoardStore.ts:76-87`) — no throw, but an invisible card. Two guards compose to make that
unreachable rather than unlikely: on an OFF campaign no assignment can ever reach `posted`
(`markPosted()` requires source `approved`, and approval now leaves `approved` in the same
transaction), and a **flip to OFF is refused with a 422 naming the cards** while any card sits in
Posted. The 422 shape to copy is `CampaignApplicationController.php:239-249` — the
`meta.code` + named-identifier pattern, tested at `CampaignApplicationAcceptTest.php:330-335`. There
is no existing 422 that enumerates blocking _cards_; this extends that pattern rather than inventing
one. **Q4 asks about the mid-flight `approved` assignment the 422 does not catch.**

### 2.7 D7 — CONFIRMED as a third branch; the predicate chain is longer than the inventory's two lines

The creator page's action area is a single `v-else-if` chain, and the new branch has to be placed in
it, not bolted on. The chain in DOM order, verified at `45ee2e7f`: `canSubmitDraft` (`:154`… form at
`:828`) → `isAwaitingReview` → `canSubmitPosted` (`:154`, form at `:828`) → `verificationFailed`
(`:164-169`) → `isAwaitingVerification` (`:170-172`) → `isVerified` (`:178-180`, notice at
`:914-921`). Four of those five must become unreachable on an OFF campaign, and they become so **by
construction**: each is keyed on `approved`/`posted`/`live_verified`/`manually_verified`, and an OFF
assignment passes through `approved` only inside the approving transaction. So D7's "post/verify
affordances never render on OFF campaigns" needs **no new predicate on four of them** — a fact worth
pinning with a test rather than asserting, because it is a property of the machine, not of the
template.

The new branch is a genuine third case: `status === 'completed_on_approval'`, its own success alert
with its own `data-testid`, its own key, and a **copy assertion that the string contains no
verification claim** — the AH-047 sibling reads "Your post has been verified by the agency"
(`creator.json:369`), which is exactly what this one must never say.

### 2.8 D8 — the code argues something different from both of D8's options. See Q5.

Traced: **`posting_due_at` has exactly one writer** —
`CampaignInvitationService.php:101-103`, at invite/accept-application time, from the validated offer
(`ValidatesAssignmentOffer.php:60`). It is never set at approval, contract, or by any command. And
**no SPA surface sends it** — every `apps/main` reference is a read (`BoardCard.vue`,
`BoardCardDrawer.vue`, `DraftsTab.vue`) or a test fixture. So in practice it is null unless an API
client sets it.

The overdue scan has **no status filter** (`OverdueScanService.php:82-113`). That means D8's second
option — "the overdue scan excludes the new terminal" — **cannot deliver D8's own requirement.** The
posting deadline passes while the assignment is still at `producing` or `draft_submitted`, long
before any terminal exists; excluding the terminal excludes almost nothing. The only complete
mechanism is a **campaign-level** exclusion (skip the posting leg for OFF campaigns) or never writing
the column for them. Current blast radius is small and should be stated honestly: `assignment.posting_overdue`
has **no seeded automation**, so `processEvent` no-ops and the only observable effect today is a
`posting_overdue_flagged_at` stamp. It becomes real the moment anything maps that verb.

### 2.9 D9 — SCOPE DISCOVERY: the E2E leg is far cheaper than the inventory implied

The inventory's §8.7 reads the deferred draft→review→posted→verified spec as a prerequisite, with
"media uploads and the verification hop" as the cost. For a **toggle-OFF** leg that cost does not
apply: a draft may be submitted with **one external link and no media at all** (AH-044's rule —
`CreatorAssignmentDraftController.php:283-297`), and it may be submitted **directly from
`contracted`**, with the controller lifting through `producing` itself (`:342-347`). So the OFF leg
is: the c5 spec's existing seven steps → flip the toggle in Settings → creator submits a
link-only draft → agency approves in the drawer → creator sees the completion banner. **No S3, no
presigned upload, no verification hop, and no new test-helper endpoint** — the toggle flip is done
through the real Settings switch, which makes it cover D1 as well.

---

## 3. Naming — the two arguments D1 and D3 invited

**D1's column: keep `creator_posts_content`.** It reads in the same direction as the label
("Deliverables are posted by creators"), so no reader has to invert it, and it borrows the
vocabulary already in the schema and the machine — `campaign_posted_content`, the
`assignment.posted_by_creator` verb, `posted_at`. The toggle then reads as exactly what it gates:
"this campaign expects the `posted_by_creator` step". The alternatives I considered and rejected:
`expects_creator_posting` (fine, but invents a verb the schema doesn't use), and any negated form
(`skips_creator_posting`) — negation would make `default(false)` mean today's behaviour, which is
tempting for Q1, but it reintroduces exactly the inverse-reading defect
`CampaignResource.php:51-55` exists to warn about. **Unless Q1 is ruled to keep `default(false)`, in
which case the negated name becomes the lesser evil and Q1 says so.**

**D3's case: keep `CompletedOnApproval` / `completed_on_approval`.** It names the cause and the
outcome and claims nothing about posting or verification. 22 characters, inside the `varchar(32)`
the status column and its length tripwire enforce (`CampaignEnumsTest.php:124-127`). Rejected:
plain `Completed` — it would read as the completion of _any_ campaign type and would make the
creator-facing label ambiguous on toggle-ON campaigns, which reach completion by a different route;
`ApprovedFinal` — vague; anything with `closed` — collides with nothing today but suggests the
cancel/reject family. The audit verb and the notification type share the value
`assignment.completed_on_approval`, which is what the one-vocabulary tripwire requires
(`NotificationTypeEnumTest.php:69-76`).

**The mailable: `AssignmentCompletedOnApprovalMail`.** Verbose, but the house names mailables after
what happened (`DraftReviewedMail`, `PostManuallyVerifiedMail`, `ContractAcceptedMail`), and every
shorter candidate either implies verification or implies a completion the recipient did not earn.

---

## 4. The D5 listener table — decided, all seven, on paper

The AH-043 lesson applied literally: every consumer of `AssignmentTransitioned` gets a recorded
decision **before** code, and each decision names its mechanism and its test.

| #   | Listener                            | Gate today                                                              | Decision                                                                          | Why                                                                                                                                                                                                                                                                                                                                             | Pinned by                                              |
| --- | ----------------------------------- | ----------------------------------------------------------------------- | --------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| 1   | `CreateAssignmentAvailabilityBlock` | `!== AssignmentAccepted` → return (`:42-44`)                            | **No change.** Silent no-op.                                                      | The block is accept-driven and spans the campaign posting window. Nothing releases it on `rejected` or `cancelled` either, so the new terminal behaves exactly like every existing terminal. Changing that is a different chunk.                                                                                                                | a negative: the new verb creates no block              |
| 2   | `DispatchPostedContentVerification` | `!== AssignmentPostedByCreator` → return (`:34-36`)                     | **No change, no guard needed.**                                                   | Verb-gated, so excluded by construction — and doubly so: an OFF campaign can never reach `posted`. Answering D6's "verify the allowlist excludes it or add a guard": **it excludes it.**                                                                                                                                                        | §5.2 split: no `VerifyPostedContentJob` dispatched     |
| 3   | `SendAssignmentNotifications`       | `match` with `default => null` (`:74-82`)                               | **Express.** New arm → creator in-app row + the new mailable. See Q3 on the pair. | The creator must be told the assignment is finished. New `NotificationType`, `LIVE_TYPES` entry, both tripwires, §5.3 render tests to chunk A's standard.                                                                                                                                                                                       | notification + §5.3 mail tests, both legs of §5.2      |
| 4   | `CreateMessageThread`               | `!== AssignmentInvited` → return (`:33-35`)                             | **No change.** Silent no-op.                                                      | The thread already exists from invite; provisioning is idempotent regardless.                                                                                                                                                                                                                                                                   | covered by the parity test's audit-verb assertions     |
| 5   | `WriteSystemMessage`                | `in_array(…, SYSTEM_MESSAGE_TRANSITIONS)` (`:36-47, 70-72`)             | **Express with a distinct, truthful, non-redundant key.**                         | AH-043's ruling verbatim. The thread already gets "The draft was approved."; the new line must add the fact that is new — completion — not repeat approval. Allowlist 10 → 11, `messages.php` ×24 **and** `app.json`'s `app.messaging.system.assignment.*` ×24 (the SPA is the live renderer — `ChatPanel.vue:134-140`, else `systemFallback`). | `SystemMessageTest.php:129-149` (the 10-verb tripwire) |
| 6   | `CreateBoardCard`                   | `!== AssignmentInvited` → return (`:41-43`)                             | **No change.** Silent no-op.                                                      | Cards are created at invite.                                                                                                                                                                                                                                                                                                                    | —                                                      |
| 7   | `BoardAutomationListener`           | event-key lookup; no match → no-op (`BoardAutomationService.php:52-61`) | **No automation seeded. The card stays where approval left it.**                  | The natural target is Approved, which is where `assignment.draft_approved` just put it — and the engine explicitly no-ops a move into the column the card already occupies. Seeding it would be pure noise. The 10-automation default set stays **10**, pinned deliberately rather than incidentally.                                           | `BoardProvisioningServiceTest.php:53-64` stays at 10   |

**The one thing the table cannot decide alone:** listeners 3 and 5 both fire twice in the OFF chain —
once for `draft_approved`, once for `completed_on_approval` — because the chain is two transitions.
Two in-app rows and two system lines are a coherent trail; **two emails seconds apart are not.**
That is Q3.

---

## 5. Standards this chunk applies

- **§5.32** — decisions are intent; mechanisms reinterpreted where code diverges, each divergence
  recorded (§2.2, §2.3, §2.8 are the three).
- **§5.34** — disjoint-and-complete negatives on every gate: the toggle-ON-can-never-reach edge, the
  refuse-flip 422, the store-whitelist persistence, the overdue-scan exclusion, the
  banner-never-claims-verification copy.
- **§5.35 break-revert** on each load-bearing condition, with SHA-256 restore proof: (a) the enum
  case with no family arm → PHPStan red before any test runs; (b) the source guard on the new edge →
  the toggle-ON negative reds; (c) the chained transaction's rollback → the parity test reds;
  (d) the refuse-flip predicate → the 422 negative reds.
- **§5.2** — Event::fake splits on every new emit: the dispatched leg and the no-side-effect leg as
  separate tests.
- **§5.3** — real-render mail test for the new mailable: subject + body per locale, flaky-10 with
  real translations, the queued-locale assertion, and the emitted deep link pinned.
- **§5.40** — additive-first migration, honest `down()`, dry-runnable command if Q1 keeps one,
  Production-posture section, snapshot obligation, deploy order documented before the push.
- **24-locale done-gate** — every new en string regenerated across 24 locales with real MT; flaky-10
  audited value-by-value, never an English fallback.
- **Full gate board before push**, including the standing full Playwright run (claimed as a gate, not
  as coverage).

---

## 6. Sub-step plan

Each sub-step ends green on the gates named. Nothing later depends on a red earlier.

### S1 — D1: the column and its write path

Migration (additive, defaulted per Q1, honest `down()`); `Campaign` docblock / `$attributes` /
`$fillable` / `casts()`; `CreateCampaignRequest` + `UpdateCampaignRequest` rules; **the
`store()` whitelist entry**; `auditableSnapshot()`; `CampaignResource`; `CampaignFactory`;
api-client `CampaignAttributes` + both payload types.

Tests: persisted-DB-value assertions on **create and update** (the AH-054 discipline, closing the gap
§2.1 found for the sibling column); a negative proving an omitted key leaves the default; the
resource emits it.

Gates: backend Pest (Campaigns module), PHPStan max, Pint, api-client vitest + typecheck.

### S2 — D1: the two UI surfaces + label/hint ×24

`CampaignForm.vue` switch (the `:294-301` precedent) for create **and** the shared Settings form;
the explaining hint — Settings uses `persistent-hint` per `CampaignDetailPage.vue:947-958`. Label +
hint keys in `app.campaigns.fields.*` ×24 with real MT, flaky-10 audited.

Tests: `CampaignDetailPage.spec.ts` — the switch renders, round-trips through the Settings PATCH, and
respects role gating. `CampaignForm.vue` still has **no spec file**; this chunk extends the page spec
rather than creating one, and says so rather than letting it be an accident.

Gates: `apps/main` vitest, vue-tsc, ESLint, locale parity.

### S3 — D2: the backfill command _(exists only if Q1 keeps it)_

`campaigns:backfill-posting-toggle` on the AH-042/`RecomputeCreatorCompleteness` shape: `--dry-run`
reporting counts without writing, `chunkById(200)`, skip-if-already-set, a count summary, and an
idempotency docblock that is honest about the guard's limits. Test file on
`AdvanceContractlessAcceptedAssignmentsTest.php`'s shape: dry-run mutates nothing, real run flips,
second run reports zero, **and a leaves-everything-else-alone case**.

Gates: backend Pest (Console), PHPStan, Pint.

### S4 — D3 + D4: the 17th case and the edge, in that order

First the case with **no** family arm — capture the PHPStan red as D4's break-revert evidence — then:
`isTerminal()` → true, `isPaymentEligible()` → true, `JobLifecycleState` → `Completed` (8/**6**/3),
`representativeEventKey()` → the draft-approved key (§2.4), `AuditAction` case, the TS union, the
status label ×24, and the five catalogue tripwires (`CampaignEnumsTest` ×3,
`JobLifecycleStateTest`, `AuditActionEnumTest`) plus the `CreatorJobDetailTest` dataset row.
`HUMAN_SEND_BLOCKED_STATUSES` deliberately **not** extended, with the `payment_released` precedent
recorded in a comment.

Then the machine edge `completeOnApproval()` — source `approved` **only** — and the chain inside the
**existing** transaction in `CampaignAssignmentReviewController::review()`, reading the toggle from
the route-bound campaign, threading `['auto_advanced' => true]` and (per Q3) the flag the
notification listener needs.

Tests: the toggle-ON-never-reaches negative; the illegal-source negatives from all sixteen other
statuses; the rollback break-revert (force a failure after the first hop → assignment stays
`approved`, no audit row, no event); the two-audit-row + two-event assertion; the terminal
consequences (`cancel()` refuses, `markPosted()` refuses).

Gates: full backend Pest, PHPStan max, Pint, api-client, `apps/main` vitest + parity.

### S5 — D5: the listener sweep's two expressive arms

`WriteSystemMessage` allowlist + key + `messages.php` ×24 + `app.json` system block ×24 + the
tripwire bump. `SendAssignmentNotifications` arm + new `NotificationType` + `LIVE_TYPES` +
`templates.ts` + `notifications.json` body ×24 + the preference label + both parity tripwires (the
18-template pin becomes 19) + the new mailable, its Blade view, and its §5.3 render test file.

Tests: §5.2 splits on both; the four no-op listeners each pinned negative; the mail's subject/body
×24 with flaky-10 real translations, queued locale, and the deep link pinned.

Gates: full backend Pest, `apps/main` vitest, both i18n parity specs, `templates.spec.ts`.

### S6 — D6 + D8: the Boards-module leg

The API-side render filter in `show()` and `reset()` (one private helper, used by both), dropping the
Posted column **and** the automations targeting it; the refuse-flip 422 naming the blocking cards, on
the `application.already_engaged` meta pattern; the overdue-scan campaign-level exclusion per Q5.

Tests: the OFF board's payload has no Posted column and no Posted automations while the DB rows are
**unchanged** — a before/after `board_columns` + `board_automations` + `board_cards` row-count and
id-set assertion, which is the zero-deletion proof; reset on an OFF campaign re-adds the row and the
payload still omits it; the 422 negative and its complement (flip allowed when Posted is empty); the
scan skips an OFF campaign's posting deadline and still fires its draft deadline.

Gates: full backend Pest, `apps/main` vitest (board store + view specs), typecheck.

### S7 — D7: the creator's third branch

The new success alert in the `v-else-if` chain with its own `data-testid` and key; the copy ×24. The
four post/verify affordances need no new predicate (§2.7) — that is asserted, not assumed.

Tests: the branch renders for the new status and for no other; the four affordances absent; **the
copy assertion that the string contains no verification claim**; the AH-047 notice unchanged for
`live_verified`/`manually_verified`.

Gates: `apps/main` vitest, locale parity, ESLint, vue-tsc.

### S8 — D9: the toggle-OFF lifecycle E2E leg

Extend the c5 spec's stopping point: flip the toggle in Settings → creator submits a link-only draft
→ agency approves → creator's completion banner → the board shows no Posted column. No new
test-helper endpoint (§2.9). Named limits recorded: the ON path stays uncovered end-to-end, and the
posting/verification spec stays the deferred future item.

Gates: full Playwright, both projects, dev stack down, E2E DB, health-check after.

### S9 — Docs (the second commit of the pair)

AH-069 entry; `draft-posting-toggle-review.md` with the per-decision evidence, the D5 table as
built, the break-revert outputs verbatim with SHA-256 restores, the zero-deletion proof, the
Production-posture section, and the full gate board; the **`deploy-log.md` PENDING entry** with the
deploy order and the worker-restart obligation; `tech-debt.md` (the sibling column's missing
persistence assertion, plus anything found); `RESUMPTION-TEMPLATE.md` Part 2 refresh.

### Then: the full gate board

Full backend Pest serial at 2G, PHPStan max, Pint `--all`, `apps/main` + `apps/admin` + api-client
vitest, vue-tsc, ESLint, all four parity specs, full Playwright both projects.

---

## 7. Open questions — none of these should be guessed

**Q1 (blocking) — the column's default, and therefore whether S3 exists at all.**
D2 locks `default(false)` + a flip-all command. §2.2 argues that shape both makes the new flow the
default for new campaigns and opens a window in which an approval drives a live assignment into an
**unrecoverable terminal**. Three coherent shapes:
(a) **`default(true)`, no command** — existing rows read ON by catalogue-only DDL, no window, no
row ever rewritten, new campaigns keep today's behaviour, column reads with its label. Risk leg 1
disappears. Cost: D2's "previewed, deliberate, recorded" flip has nothing to preview, because
nothing is flipped.
(b) **D2 as written**, plus a mitigation for the window — either ship the machine edge in a
_separate, later_ deploy, or put the whole chain behind a Pennant flag armed after the backfill.
(c) **Negate the column name** (`skips_creator_posting`) so `default(false)` means today's behaviour
— no command, no window, but the stored column reads inversely to the UI label, which is the drift
`CampaignResource.php:51-55` was written to prevent.
**My recommendation: (a).** It satisfies every _stated_ intent of D2 more completely than D2's own
mechanism does, and it removes an irreversible-outcome window rather than mitigating one. If the
preview matters for its own sake, (b)-with-a-flag is the honest second.

**Q2 — should `markPosted()` be guarded on the toggle?**
An OFF campaign cannot reach `posted` through the normal path, because approval leaves `approved` in
the same transaction. But a campaign **flipped OFF while an assignment sits at `approved`** leaves a
live window where the creator's `POST …/posted-content` still succeeds, landing a card in a column
the board no longer renders. Options: (a) guard the creator posted-content endpoint on the campaign
toggle with a 422 — the mirror of "toggle-ON can never reach the new terminal", and the same
fail-closed discipline; (b) extend the refuse-flip predicate to also refuse while any assignment sits
at `approved`; (c) accept it. **Recommendation: (a)** — it makes the invisible-card state unreachable
instead of unlikely, and it is one guard plus one negative.

**Q3 — the double notification on the chained approval.**
Both the notification listener and the system-message listener fire twice (once per transition). Two
in-app rows and two thread lines read as a coherent trail; **two emails within seconds do not.**
Options: (a) **two in-app rows, one email** — suppress the `draft_approved` mail when the transition
carries a `completes_on_approval` context flag set by the controller (the AH-068 context-threading
mechanism, so the listener stays query-free and the audit row records it too), and let the completion
mail carry the news; (b) both emails, distinct copy; (c) suppress the `draft_approved` notification
entirely on OFF campaigns. **Recommendation: (a).** The in-app feed is a trail where two rows are a
feature; email is an interruption where two is a defect. And because every existing campaign stays
ON, this changes **zero live behaviour**.

**Q4 — what the refuse-flip 422 should name, and how precisely.**
The kickoff says "422 naming the cards". Options: the card ULIDs, the creator display names, or a
count plus the first N. The existing precedent names one blocking record with its id and status
(`CampaignApplicationController.php:239-249`); no precedent enumerates many. **Recommendation:** a
count plus the assignment/card ULIDs in `meta`, and human-readable creator names in the message the
SPA shows — an operator needs to know _which creators_, not which ULIDs.

**Q5 — the D8 mechanism, given that D8's second option cannot work.**
Per §2.8, excluding the new terminal from the overdue scan does not deliver "never flag an OFF
assignment as overdue-to-post", because the deadline passes long before the terminal exists.
Options: (a) the scan's posting leg joins `campaigns` and skips OFF campaigns — covers new campaigns,
later flips, and existing rows, in one `where`; (b) never write `posting_due_at` on an OFF campaign
at invite — cheap, but leaves the later-flip case; (c) both. **Recommendation: (a)**, with (b) as a
free addition if the invite path is being touched anyway. Also worth Claude's ruling: the current
blast radius is a column stamp, because `assignment.posting_overdue` has no seeded automation — so
this is prophylaxis, and it should be labelled as such rather than sold as a bug fix.

**Q6 — does the new terminal deserve a board automation after all?**
§4 decides "no automation, the card stays in Approved". The counter-argument: an agency looking at an
OFF campaign's board sees completed assignments sitting in a column named "Approved", with no visual
distinction from ones still awaiting a post on an ON campaign. A card-face treatment would be
net-new UI (the AH-068 Q5 precedent says that is out of scope for a mechanism chunk). **Recommendation:
keep "no automation"**, and record the card-face distinction as a future product option, exactly as
AH-068 recorded its chip.

**Q7 — `isPaymentEligible()` is locked to true by D4. Confirming the consequence is intended.**
D4 rules the new case joins the predicate, which means toggle-OFF assignments become
Sprint-10-payable at approval, with no posting or verification step in between. That is the ruling I
will build. Flagging only that it makes approval the payment trigger for this campaign type — the
inventory's §8.1 warned about the opposite failure (invisible to the release gate), and D4 chose the
right side of it, but Sprint 10's release gate should know that a payable assignment may have no
`campaign_posted_content` row at all. **No change requested; recorded so Sprint 10 inherits it in
writing.**

**Q8 — the OFF path's E2E coverage claim.**
S8 covers the OFF lifecycle end-to-end and leaves the ON path (posting + verification) uncovered, as
it is today. Confirming that is the intended trade: this chunk buys E2E coverage for the **new**
path and does not pay down the pre-existing gap. **Recommendation: yes** — and the review file states
it as a limit, not as coverage.

---

## 8. Expected ripple

Backend: 1 migration, 1 enum case (+2 predicates), 1 audit verb, 1 notification type, 1 machine
method, 1 controller chain, 1 listener arm ×2, 1 mailable + view, 1 board filter helper, 1 refuse-flip
422, 1 scan `where`, 0 or 1 console command. Tripwires touched: `CampaignEnumsTest` ×3,
`JobLifecycleStateTest`, `AuditActionEnumTest`, `NotificationTypeEnumTest`, `SystemMessageTest`,
`BoardProvisioningServiceTest` (asserted unchanged at 10), `CreatorJobDetailTest` dataset.
Frontend: api-client union + 3 campaign types, `CampaignForm`, `CampaignDetailPage`,
`CreatorAssignmentDetailPage`, `useBoardStore`/board specs, `templates.ts`, both notification parity
specs. i18n: `app.json` (label, hint, status label, system line), `creator.json` (banner),
`notifications.json` (body + preference label), `lang/*/campaigns.php` (mail), `lang/*/messages.php`
(system line) — all ×24, flaky-10 audited. Docs: AH-069, review file, deploy-log PENDING, tech-debt,
resumption.

---

## 9. What this plan does not do

No payment flow (Stripe-blocked; eligibility only). No card-face round or completion chip. No board
column deletion, reset change, or provision-time omission. No change to the posting/verification
path on ON campaigns. No cancel-mid-cycle work. No new test-helper endpoint. No `CampaignForm` spec
file. No retro-fix of the sibling column's missing persistence assertion — logged, not adopted.

---

_Provenance: drafted by Cursor at plan-pause per `WORKING-PROCESS.md` §2 step 3, against Claude's
AH-069 kickoff and the read pass recorded above. Every citation re-derived at `45ee2e7f` — post
AH-068 — with the drift from the inventory's pre-A citations reported in §1. No code written; nothing
builds until Claude clears this file, and Q1 in particular._
