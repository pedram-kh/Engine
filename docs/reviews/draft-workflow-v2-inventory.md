# Draft Workflow v2 — Read-Only Inventory

**Status:** Inventory only. No edits, no plan, no code. Answers a Claude-written
question set per `PROJECT-WORKFLOW.md` §3 step 1 / `WORKING-PROCESS.md` §2 Mode A step 1.

**Author:** Cursor (read pass)

**Date:** 2026-08-16

**HEAD at read time:** `ea9d686` — `docs: record the APP_KEY incident (AH-067), pin the runbook, log AH-067`. Working tree clean.

**Scope of the ask.** Two client-requested asks, inventoried separately:

- **(A) Numbered, visible draft rounds** on the existing
  submit → review → request-changes → resubmit cycle, with preserved per-round
  history (submission + its feedback). Locked upstream: a round = one submission;
  feedback closes a round rather than incrementing it; no round cap in v1.
- **(B) A per-campaign creation toggle** — "Deliverables are posted by creators",
  default OFF for new campaigns, existing campaigns migrated ON. When OFF:
  approval auto-advances the assignment to completion, the Posted column does not
  render on that campaign's board, post/verify affordances never engage, and the
  creator sees the green-banner pattern with approved-complete copy.

**PROD-DATA RISK: NONE** — this document is a read pass. No file outside
`docs/reviews/` was touched; no migration, command, or query was run against any
database. The §5.40 risk lines for the _prospective_ work are re-derived per
prospective chunk in §9 below, and must be re-derived again at each plan-pause
per `WORKING-PROCESS.md` §6 — the numbers here are scoping input, not a
declaration for a build.

**Reviewed against:** `WORKING-PROCESS.md`; `PROJECT-WORKFLOW.md` §5 (esp. §5.32,
§5.34, §5.35, §5.37, §5.38, §5.40); `docs/reviews/adhoc-changes-log.md`
(AH-040 → AH-067); `jobs-board-c4-review.md`; `jobs-board-c5-review.md`;
`campaign-drafts-tab-review.md`; `contract-toggle-off-flow-review.md`;
`10-BOARD-AUTOMATION.md`; `03-DATA-MODEL.md`.

---

## 0. The three answers that shape the kickoffs

Read this section first; the rest is evidence.

### 0.1 IA1 — versions are **RETAINED**, not overwritten. Ask (A) is a presentation-layer ask.

Resubmission **inserts a new row**. `campaign_drafts` has a `version` integer
column and a `unique (assignment_id, version)` constraint, and the submit path
computes `max(version) + 1` inside a transaction. Per-round feedback is already
stored on the same row as the submission it answers
(`campaign_drafts.review_feedback` + `reviewed_at` + `reviewed_by_user_id`).

**The domain model ask (A) wants already exists.** `campaign_drafts.version` _is_
the round number: one row per submission, and the review columns on that row are
that round's closing feedback. No counter column is needed, no `draft_revisions`
table, no feedback-linkage table. The locked semantics map 1:1 onto storage that
shipped in Sprint 9 Chunk 1:

| Locked semantic                            | Existing mechanism                                                                                                                                   |
| ------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| a round = one submission                   | one `campaign_drafts` row per submit, `version = max+1` (`CreatorAssignmentDraftController.php:316-318`)                                             |
| feedback closes a round, doesn't increment | review writes update the **latest** row in place; no new row (`CampaignAssignmentReviewController.php:196-204`)                                      |
| preserved per-round history                | rows are never updated on resubmit and never deleted; unique `(assignment_id, version)` (`2026_06_05_110000_create_campaign_drafts_table.php:92-93`) |

What is missing is **presentation and vocabulary only**: every surface today says
"Version N" / "Draft vN" rather than "Draft N — awaiting review", the creator's
history rows omit `submitted_at` and `review_feedback`, and no notification or
system message carries the round number. See §2 for the surface-by-surface gap
and §3 for the two semantic edges that are genuinely unresolved.

### 0.2 IB2 — there is **no `Completed` assignment state**. "Auto-advance to completion" has no target today.

`AssignmentStatus` has 16 cases and **none of them is `completed`**
(`AssignmentStatus.php:51-66`). The nearest things to "completion" are three
different concepts that do not coincide:

1. **`payment_released`** — the enum's terminal _success_
   (`AssignmentStatus.php:72-81`). Unreachable: both `holdPayment()` and
   `releasePayment()` throw `escrowUnavailable()` unconditionally
   (`CampaignAssignmentStateMachine.php:539-556`). Sprint 10 territory.
2. **`live_verified` / `manually_verified`** — the reachable end of the happy
   path, and the only two `isPaymentEligible()` states
   (`AssignmentStatus.php:93-99`). Both are **non-terminal**.
3. **`JobLifecycleState::Completed`** — the creator-facing coarse family, which
   begins at `posted` (`JobLifecycleState.php:83-87`).

So the ask's phrase "auto-advances the assignment to completion" cannot be
implemented as written. The reachable post-approval states are `posted` →
`live_verified` (auto, flag-gated) or `posted` → `manually_verified` (agency,
reason-mandatory). Both require passing _through_ `posted`, which is precisely
the state ask (B) exists to skip: `markPosted()` only accepts `approved` as a
source (`CampaignAssignmentStateMachine.php:412`) and `verifyLive()` /
`manuallyVerify()` only accept `posted` (`:445`, `:488`).

The kickoff therefore has to choose a mechanism, and the choice is load-bearing
rather than cosmetic. The three shapes visible from the code, with the
suppress-vs-express consequence of each, are laid out in §6.2. The D5 answer the
ask asks for: **whichever status is chosen must fall in the `Completed` family of
`JobLifecycleState::fromAssignmentStatus()`** — that match has no `default` arm
(`JobLifecycleState.php:73-92`) and is pinned by an exhaustiveness test that
iterates `AssignmentStatus::cases()` (`JobLifecycleStateTest.php:23-36`), so a new
case cannot be added without a deliberate family assignment. Of the states that
exist, `manually_verified` and `live_verified` are already in `Completed`;
`approved` is in `InProgress` and would have to move families — which would
change the creator-facing label on **every** toggle-ON campaign too, because the
mapping is per-status, not per-campaign.

### 0.3 IB3 — per-campaign column omission is **not expressible today**, but the board is per-campaign data, so it is reachable.

Board columns are **rows, not an enum**: one `boards` row per campaign
(`campaign_id` UNIQUE — `2026_06_06_120000_create_boards_table.php:50`), and
`board_columns` rows hang off `board_id` with a free-text `name` varchar(64)
(`2026_06_06_120001_create_board_columns_table.php:37-53`). Every board is
seeded from the same 7-column / 10-automation `BoardDefaults` set with **zero
per-campaign or per-agency variance** (`BoardProvisioningService.php:41-83`).

That is good news structurally and bad news for the ask's framing:

- **Nothing renders conditionally per campaign today.** The SPA renders whatever
  columns the API returns, sorted by `position`
  (`useBoardStore.ts:71-73`, `BoardColumns.vue:51-60`). The only conditional
  column is `BoardApplicationsColumn`, and it is a _pseudo_-column mounted
  outside `localColumns` (`BoardColumns.vue:80-85`) — the AH-059 D4 pattern,
  which is the closest precedent for "a column that is not a `board_columns`
  row" but the _inverse_ of what (B) needs.
- **Omission at provision time is a small change** — `seedColumns()` iterates
  `BoardDefaults::columns()` and `seedAutomations()` maps target column _name_ →
  id, tolerating a miss by storing `target_column_id = null`
  (`BoardProvisioningService.php:64-69`). A null target is a **silent runtime
  no-op** in `processEvent()` (`BoardAutomationService.php:57-60`) — no
  exception, no log line.
- **Omission on an EXISTING board is the hard part, and the reality is worse
  than "the column already exists".** Because every existing campaign migrates
  to ON, no existing board _needs_ the column removed on day one — but the
  toggle can be flipped OFF later on a campaign whose board already has a Posted
  column, possibly with cards in it. Three facts govern that case:
  `seedColumns()` refuses to run when the board has any columns at all
  (`:43-45`), so re-provisioning will never _re-add_ an omitted column;
  `BoardResetService` (`BoardResetTest.php`, 9 tests) restores all 7 defaults
  and would re-add it; and column delete is a real, guarded endpoint that
  re-homes cards and refuses the last column (`BoardColumnDeleteTest.php`,
  4 tests). Nothing in the lazy-heal path re-seeds columns — `ensureBoard()`
  only provisions `if ($board->wasRecentlyCreated)`
  (`BoardService.php:60-64`), and `forCampaign()` heals missing _cards_, not
  columns (`:70-86`).

So: provisioning-side omission is cheap; the existing-board posture is a genuine
product decision with a card-relocation question attached. Reported, not decided
— see §7.3 for the full enumeration.

---

## 1. Premise corrections before the evidence

Per `WORKING-PROCESS.md` §7 (verify load-bearing claims against actual code), four
references in the question set point at something other than what they name. None
changes the ask; all four change where to look.

| The ask says                                                   | The repo says                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| -------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| "the AH-046 resubmit-in-place UI" as a **draft**-cycle surface | AH-046 is a **copy-only** change to `creator.ui.assignments.detail.resubmitInPlace.intro` across 24 locales (`adhoc-changes-log.md:1244-1268`). "Resubmit in place" belongs to the **posted-content verification** flow (ACT3), not the draft cycle: it fires no transition, touches no `campaign_drafts` row, and only nudges the creator to PATCH the post URL (`CampaignAssignmentResolutionController.php:111-140`). It is **not** a draft-round surface. |
| "the AH-046 empty-draft rule"                                  | That is **AH-044** — `422 draft.empty` when both media and links are empty (`adhoc-changes-log.md:1317-1352`; `CreatorAssignmentDraftController.php:283-297`).                                                                                                                                                                                                                                                                                                |
| "the verifiedNotice sibling"                                   | Correct — **AH-047**, `assignment-verified-notice` (`adhoc-changes-log.md:1215-1240`; `CreatorAssignmentDetailPage.vue:907-915`).                                                                                                                                                                                                                                                                                                                             |
| "auto-advances the assignment to completion"                   | No `completed` state exists. See §0.2.                                                                                                                                                                                                                                                                                                                                                                                                                        |

AH-042 (contract-less auto-advance), AH-041 (board-column machinery + backfill),
AH-054 (additive campaign columns), and AH-059 D5 (three-family reflection) are
all cited accurately and are used as reference shapes throughout.

One further correction with product weight, in the opposite direction — the ask's
"NO round cap v1 (logged open — contract clause 2.4 adjacency noted)" understates
the adjacency. Clause 2.4 of the live master agreement is not merely adjacent; it
sets a **default numeric cap**:

> **2.4** The number of revision rounds to which clause 2.2 applies shall be
> determined on a Campaign-by-Campaign basis and set out in the relevant Brief.
> Unless the Brief specifies otherwise, Creator shall provide up to three (3)
> rounds of revisions per Deliverable at no additional cost.
> — `apps/api/resources/contracts/master-agreement.en.md:34-39`

So the contract already says "three, per campaign, unless the Brief says
otherwise", and ask (A) is about to put a **visible round counter** in front of
both parties for the first time. Shipping a visible "Draft 5" against a contract
that promises three free rounds is a product exposure, not a schema question. Not
a decision for this document — flagged for the kickoff, and note the house limit
from `WORKING-PROCESS.md` §7: engineering review is not legal review (AH-029).

---

## 2. IA1 — Draft storage and the version reality

### 2.1 What is stored per submission today

`campaign_drafts`, created by
`apps/api/database/migrations/2026_06_05_110000_create_campaign_drafts_table.php`.
The migration's own docblock states the intent:

```14:16:apps/api/database/migrations/2026_06_05_110000_create_campaign_drafts_table.php
 * One row per submission attempt;
 * `version` increments per resubmission so the full history is preserved
 * (D-6). Per docs/03-DATA-MODEL.md §7 (`campaign_drafts`, :572-600).
```

Columns relevant to rounds, with lines:

| Column                                                    | Line        | Shape                                                                                                                                                               |
| --------------------------------------------------------- | ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `assignment_id`                                           | `:40-44`    | FK → `campaign_assignments`, `cascadeOnDelete()`                                                                                                                    |
| `version`                                                 | `:46-48`    | `integer`, NOT NULL, no default                                                                                                                                     |
| `submitted_by_creator_id`                                 | `:50-54`    | nullable FK → `creators`, `nullOnDelete()`                                                                                                                          |
| `submitted_at`                                            | `:55`       | `timestamp`, nullable                                                                                                                                               |
| `caption` / `hashtags` / `mentions` / `media_attachments` | `:58-62`    | the submission payload                                                                                                                                              |
| `links`                                                   | added later | `jsonb`, nullable (`2026_07_13_100000_add_links_to_campaign_drafts.php:19-21`, AH-040)                                                                              |
| `review_status`                                           | `:65`       | `string`, default `'pending'`; widened 16 → 32 by `2026_06_05_120000_widen_campaign_drafts_review_status_columns.php:32-35` because `revision_requested` overflowed |
| `reviewed_at`                                             | `:66`       | `timestamp`, nullable                                                                                                                                               |
| `reviewed_by_user_id`                                     | `:67-71`    | nullable FK → `users`, `nullOnDelete()`                                                                                                                             |
| `review_feedback`                                         | `:72`       | `text`, nullable                                                                                                                                                    |
| `client_review_*` (4 cols)                                | `:75-82`    | present, **no writer anywhere** — P2 placeholder                                                                                                                    |
| `ai_qc_results` / `ai_qc_passed`                          | `:85-86`    | present, no writer                                                                                                                                                  |

Constraints — the load-bearing pair:

```91:95:apps/api/database/migrations/2026_06_05_110000_create_campaign_drafts_table.php
        Schema::table('campaign_drafts', function (Blueprint $table): void {
            // One version number per assignment (resubmit computes max+1).
            $table->unique(['assignment_id', 'version'], 'unique_draft_assignment_version');
            $table->index(['assignment_id', 'review_status'], 'idx_drafts_assignment_review_status');
        });
```

Model: `apps/api/app/Modules/Campaigns/Models/CampaignDraft.php` — `$fillable`
`:65-79`, casts `:108-119` (`version` → integer, `review_status` →
`DraftReviewStatus`), relations `assignment()` / `submittedByCreator()` /
`reviewedBy()` at `:84-103`. **No scopes, no default ordering, no version
accessor** — ordering is applied per-query in controllers.

`DraftReviewStatus` has exactly four cases — `pending`, `approved`, `rejected`,
`revision_requested` (`DraftReviewStatus.php:16-22`).

### 2.2 Resubmit-in-place or new row? — new row, always

The whole answer is one transaction in
`CreatorAssignmentDraftController::submitDraft()`:

```315:340:apps/api/app/Modules/Creators/Http/Controllers/CreatorAssignmentDraftController.php
        $draft = DB::transaction(function () use ($model, $creator, $user, $validated, $media, $links, $machine): CampaignDraft {
            $version = (int) (CampaignDraft::query()
                ->where('assignment_id', $model->id)
                ->max('version') ?? 0) + 1;

            $draft = CampaignDraft::create([
                'assignment_id' => $model->id,
                'version' => $version,
                'submitted_by_creator_id' => $creator->id,
                'submitted_at' => now(),
                'caption' => $validated['caption'] ?? null,
                // ... media / links normalisation ...
                'review_status' => DraftReviewStatus::Pending,
            ]);
```

`CampaignDraft::create()` at `:320` is the **only** draft-creating call in the
codebase. There is no `updateOrCreate`, and the sole `save()` on a draft row is
the agency review trail (`CampaignAssignmentReviewController.php:204`), which
updates the latest row's four review columns and creates nothing.

Submit and resubmit are the **same endpoint and method** — AH-044 recorded this
explicitly ("submit and resubmit are the same endpoint/method (producing /
contracted / revision_requested all route through it)",
`adhoc-changes-log.md:1338-1343`). The three legal source states are gated
fail-closed:

```273:281:apps/api/app/Modules/Creators/Http/Controllers/CreatorAssignmentDraftController.php
        $producible = [AssignmentStatus::Producing, AssignmentStatus::Contracted, AssignmentStatus::RevisionRequested];
        if (! in_array($model->status, $producible, true)) {
            return ErrorResponse::single(
                $request,
                422,
                'assignment.not_producible',
                'This assignment is not ready for a draft submission.',
            );
        }
```

and `contracted` / `revision_requested` are lifted to `producing` before the
submit transition, so the machine — not the controller — owns the audit rows:

```342:355:apps/api/app/Modules/Creators/Http/Controllers/CreatorAssignmentDraftController.php
            // The two-step machine path: lift contracted / revision_requested
            // up to producing first, then submit (D-4/D-6). producing submits
            // directly. The machine owns both audit rows + events.
            if ($model->status !== AssignmentStatus::Producing) {
                $machine->startProducing($model, $user);
            }

            $machine->submitDraft($model, $user, context: [
                'draft_id' => $draft->ulid,
                'version' => $version,
                'media_count' => count($media),
                // Link URLs are free text — count only (the D-3 discipline).
                'link_count' => count($draft->links ?? []),
            ]);
```

Retention is test-pinned, not merely intended:

```207:209:apps/api/tests/Feature/Modules/Creators/CreatorAssignmentDraftTest.php
    // History preserved — both versions remain as their own rows.
    $versions = CampaignDraft::query()->where('assignment_id', $assignment->id)->orderBy('version')->pluck('version')->all();
    expect($versions)->toBe([1, 2]);
```

Ordering: every read orders `orderByDesc('version')` — creator show
(`CreatorAssignmentDraftController.php:85-88`), agency assignment show
(`CampaignAssignmentReviewController.php:60-63`), and the review write's
latest-row selection (`:181-184`). The campaign-wide Drafts list returns **flat
rows across assignments**, not latest-per-assignment, by design
(`campaign-drafts-tab-review.md:15`).

### 2.3 What the "Draft history" block actually renders

Two different blocks, with **different field sets** — this asymmetry matters for (A).

**Creator side** — `data-testid="assignment-draft-history"`, rendered whenever any
version exists:

```917:941:apps/main/src/modules/creators/pages/CreatorAssignmentDetailPage.vue
      <!-- Draft version history (always shown when versions exist, D-6) -->
      <v-card v-if="drafts.length > 0" variant="outlined" data-testid="assignment-draft-history">
        <v-card-title class="text-subtitle-1">
          {{ t('creator.ui.assignments.detail.history.title') }}
        </v-card-title>
        <v-list density="compact">
          <v-list-item
            v-for="draft in drafts"
            :key="draft.id"
            :data-testid="`assignment-draft-version-${draft.attributes.version}`"
          >
            <v-list-item-title>
              {{
                t('creator.ui.assignments.detail.history.version', { n: draft.attributes.version })
              }}
              <v-chip size="x-small" variant="tonal" class="ml-2">
                {{
                  t(`creator.ui.assignments.detail.reviewStatus.${draft.attributes.review_status}`)
                }}
              </v-chip>
            </v-list-item-title>
            <v-list-item-subtitle v-if="draft.attributes.caption">
              {{ draft.attributes.caption }}
            </v-list-item-subtitle>
          </v-list-item>
        </v-list>
      </v-card>
```

Per row: `version`, `review_status` chip, `caption`. **The creator's own history
does not show the feedback it received, nor when it was submitted.** i18n:
`creator.ui.assignments.detail.history.title` = "Draft history",
`.history.version` = "Version {n}" (`locales/en/creator.json:416-425`) — which is
the literal source of the screenshot's "Draft history — Version 1 — Approved".

**Agency side** — `ReviewDraftDrawer.vue`, `data-test="review-history"`:

```277:301:apps/main/src/modules/campaigns/components/ReviewDraftDrawer.vue
          <!-- Version history -->
          <v-card
            v-if="history.length > 0"
            variant="outlined"
            class="mt-4"
            data-test="review-history"
          >
            <v-card-title class="text-subtitle-2">{{
              t('app.campaigns.review.history')
            }}</v-card-title>
            <v-list density="compact">
              <v-list-item
                v-for="draft in history"
                :key="draft.id"
                :data-test="`review-history-${draft.attributes.version}`"
              >
                <v-list-item-title>
                  {{ t('app.campaigns.review.draftVersion', { n: draft.attributes.version }) }}
                  <v-chip size="x-small" variant="tonal" class="ml-2">
                    {{ t(`app.campaigns.review.draftStatus.${draft.attributes.review_status}`) }}
                  </v-chip>
                </v-list-item-title>
                <v-list-item-subtitle v-if="draft.attributes.review_feedback">
                  {{ draft.attributes.review_feedback }}
                </v-list-item-subtitle>
              </v-list-item>
            </v-list>
          </v-card>
```

Per row: `version`, `review_status` chip, `review_feedback`. i18n
`app.campaigns.review.history` = "Version history",
`.draftVersion` = "Draft v{n}" (`locales/en/app.json:750-767`).

So the agency sees per-round feedback and the creator does not; the creator sees
the caption and the agency does not. Both call the same thing by two different
names ("Draft history" / "Version history", "Version {n}" / "Draft v{n}").

`latestDraft` / `history` both derive from the same newest-first array
(`ReviewDraftDrawer.vue:66-67`), so "the round under review" and "the history" are
the same data, not two fetches.

### 2.4 Resource shapes — what the wire already carries

```34:51:apps/api/app/Modules/Campaigns/Http/Resources/CampaignDraftResource.php
        return [
            'id' => $draft->ulid,
            'type' => 'campaign_draft',
            'attributes' => [
                'version' => $draft->version,
                'submitted_at' => $draft->submitted_at?->toIso8601String(),
                'caption' => $draft->caption,
                'hashtags' => $draft->hashtags,
                'mentions' => $draft->mentions,
                'media' => $this->mapMedia($draft),
                // External reference links (draft-composer facelift): plain
                // url+name pairs, no signing — they are external URLs.
                'links' => $draft->links,
                'review_status' => $draft->review_status->value,
                'reviewed_at' => $draft->reviewed_at?->toIso8601String(),
                'review_feedback' => $draft->review_feedback,
            ],
        ];
```

`version`, `submitted_at`, `review_status`, `reviewed_at` and `review_feedback`
are **all already on the wire** for both roles. The summary shape used by the
Drafts tab carries the same five minus `reviewed_at`, plus an assignment stub
(`CampaignDraftListItemResource.php:47-65`) — deliberately no media, no signed
URLs (`campaign-drafts-tab-review.md:46`, D-3). TS mirrors:
`packages/api-client/src/types/campaign.ts:421-437` and `:592-615`.

**Consequence for (A):** a "Draft N — awaiting review" label plus a per-round
history row carrying submission time _and_ its feedback requires **no API field
additions and no migration**. It is copy, layout, and — if the label needs to
distinguish "awaiting review" from "changes requested" — a derivation over
`review_status` + the assignment's own status, both of which are already present.

### 2.5 Factory

`CampaignDraftFactory` hard-codes `'version' => 1` (`:29`) with an explicit
`version(int $version)` state helper (`:45-48`). It does not set
`review_feedback`, `reviewed_at`, or `links` by default — relevant because any new
round-history assertion will need those states set explicitly.

---

## 3. IA2 — The review cycle's surfaces

### 3.1 Every surface that shows draft state today

| #   | Surface                                                     | Path                                                  | Shows                                                                                      | Where a round number / round history would land                                                                 |
| --- | ----------------------------------------------------------- | ----------------------------------------------------- | ------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------- |
| 1   | Creator assignment detail — state-dependent action slot     | `CreatorAssignmentDetailPage.vue:143-186`, `:898-915` | `isResubmit`, `isAwaitingReview`, `revisionFeedback` banner                                | the "awaiting review" and "changes requested" alerts are where "Draft 2 — awaiting review" belongs              |
| 2   | Creator assignment detail — Draft history card              | `:917-943`                                            | version, status chip, caption                                                              | the per-round block; **needs `submitted_at` + `review_feedback` added to the row**                              |
| 3   | Creator assignments **list**                                | `CreatorAssignmentsPage.vue:137`                      | assignment status chip via `app.campaigns.assignmentStatus.*`                              | round number would be net-new on this surface                                                                   |
| 4   | Agency `ReviewDraftDrawer` — latest draft + Version history | `ReviewDraftDrawer.vue:66-67`, `:277-304`             | version, status chip, feedback                                                             | the reviewer's round context; already has feedback per row                                                      |
| 5   | Agency campaign-detail **Drafts tab**                       | `DraftsTab.vue:190-211`                               | creator name, version, review_status, assignment status, `submitted_at`, `review_feedback` | the richest existing row — closest to a round row already                                                       |
| 6   | Agency campaign-detail **Creators tab** row actions         | `CampaignDetailPage.vue:192-198`, `:618`              | assignment status chip; Review / Resolve gating                                            | round number as a column or chip suffix                                                                         |
| 7   | Board **card face**                                         | `BoardCard.vue:48`                                    | assignment status chip                                                                     | a round badge would go here                                                                                     |
| 8   | Board **card drawer — Detail timeline**                     | `BoardCardDrawer.vue:190-220`                         | five fixed milestones off assignment timestamps                                            | see §3.2 — this is the surface the ask's "drawer timeline" names, and it is **not** round-aware by construction |
| 9   | Board card drawer — movement history                        | `BoardCardDrawer.vue:238-245`                         | column moves + `triggered_event_key`                                                       | already shows repeated In Review → Approved hops; the de-facto round trail today                                |
| 10  | Messages tab — system messages                              | `WriteSystemMessage.php:36-47`                        | one line per allowlisted transition                                                        | round context could ride the message copy                                                                       |

Surface 8 deserves its own note because the ask assumes it is a timeline of
events. It is not — it is five **fixed** milestones read off five assignment
columns:

```198:220:apps/main/src/modules/boards/components/BoardCardDrawer.vue
// Five milestones off the detail timestamps (all previously fetched but never
// shown). Labels reuse the assignmentStatus keys — no new i18n surface.
const timelineSteps = computed<TimelineStep[]>(() => {
  const a = detail.value?.attributes
  if (!a) return []
  return [
    {
      key: 'invited',
      label: t('app.campaigns.assignmentStatus.invited'),
      at: a.invited_at ?? null,
    },
    {
      key: 'draft_submitted',
      label: t('app.campaigns.assignmentStatus.draft_submitted'),
      at: a.submitted_draft_at,
    },
    { key: 'approved', label: t('app.campaigns.assignmentStatus.approved'), at: a.approved_at },
    { key: 'posted', label: t('app.campaigns.assignmentStatus.posted'), at: a.posted_at },
    {
      key: 'live_verified',
      label: t('app.campaigns.assignmentStatus.live_verified'),
      at: a.verified_live_at,
    },
  ]
})
```

Because `submitted_draft_at` is overwritten by every `submitDraft()`
(`CampaignAssignmentStateMachine.php:312-314`), the timeline shows **only the
latest** submission and has no slot for round 1. A round-aware timeline here is a
rewrite of the computed, not an addition to it — and it would want the drafts
relation, which the drawer's detail payload already carries (AH-045 established
the drawer reads the same `CampaignDraftResource` collection,
`adhoc-changes-log.md:1281-1287`).

### 3.2 Feedback storage shape — per-draft, not per-event

Feedback is a **column on the submission row**, written in the same transaction as
the transition and deliberately _not_ snapshotted into audit:

```196:204:apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAssignmentReviewController.php
            DB::transaction(function () use ($draft, $assignment, $machine, $actor, $reviewStatus, $feedback): void {
                // Write the review trail FIRST (column-only fields shipped in
                // Chunk 1) so the transition event's notification listener reads
                // the persisted feedback.
                $draft->review_status = $reviewStatus;
                $draft->reviewed_at = now();
                $draft->reviewed_by_user_id = $actor->id;
                $draft->review_feedback = $feedback;
                $draft->save();
```

The machine's docblock states the discipline explicitly — free-text feedback lives
on the draft, the audit row gets only the `{draft_id, version}` link:

```319:327:apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php
    /**
     * draft_submitted → revision_requested (the review loop, D-5). The
     * `$context` carries the reviewed draft's `{draft_id, version}` so the
     * single transition audit row LINKS back to the draft (the Chunk 1
     * context-thread mechanism). The free-text reviewer feedback itself is
     * persisted on the draft's `review_feedback` column by the controller (in
     * the same transaction), NOT snapshotted into the audit metadata (the
     * hand-written-audit / free-text-redaction discipline, D-3).
```

**Exactly one feedback string per round, and it is already attached to the right
round.** There is no separate feedback table, no per-event feedback rows, and no
second feedback slot — grep for `review_feedback` finds only the
`campaign_drafts` column plus the unused `client_review_feedback` sibling.

Two consequences worth flagging:

- **Reject also stores feedback.** `rejectDraft()` takes a mandatory reason
  (`CampaignAssignmentStateMachine.php:382-399`) and the controller writes that
  same string to `review_feedback` while the machine puts it in the audit
  `reason` field. So a rejected round has feedback and is terminal.
- **`requestResubmitFresh` / `requestResubmitInPlace` feedback is NOT persisted.**
  The optional feedback on both resolution actions rides the creator notification
  only (`CampaignAssignmentResolutionController.php:87-90`, `:120-123`). That is a
  deliberate asymmetry, and it means the _posting_-cycle feedback has no history
  at all — worth knowing if (A)'s "per-round history" is ever asked to cover the
  post-verification loop too.

One live rough edge in the creator UI that (A) will collide with:

```180:186:apps/main/src/modules/creators/pages/CreatorAssignmentDetailPage.vue
/** The most recent agency feedback (Chunk 2 populates `review_feedback`). */
const revisionFeedback = computed<string | null>(() => {
  for (const draft of drafts.value) {
    if (draft.attributes.review_feedback) return draft.attributes.review_feedback
  }
  return null
})
```

This scans the newest-first array and returns the first **non-empty** feedback
found anywhere in history — not the feedback on a specific round. It is correct
today only because the banner is gated on `isResubmit`
(`status === 'revision_requested'`) at `:561-575`, where the newest row is the one
carrying feedback. Once rounds are displayed individually, this scan is the wrong
primitive and should be read per-round instead. The banner does already handle
absent feedback gracefully via
`creator.ui.assignments.detail.revision.noFeedback` (`:572-574`), which is the
existing empty-state a per-round view can reuse.

### 3.3 The cycle's notification types, and whether any carries round context

Five `NotificationType` cases touch the cycle (`NotificationType.php:49-54`):

| Type                            | Recipient                            | Group      | templateKey                                      | Fired on                                |
| ------------------------------- | ------------------------------------ | ---------- | ------------------------------------------------ | --------------------------------------- |
| `assignment.draft_submitted`    | agency (fans out to admins+managers) | assignment | `notifications.types.assignment_draft_submitted` | `AuditAction::AssignmentDraftSubmitted` |
| `assignment.revision_requested` | creator                              | assignment | `…assignment_revision_requested`                 | `AssignmentRevisionRequested`           |
| `assignment.draft_approved`     | creator                              | assignment | `…assignment_draft_approved`                     | `AssignmentDraftApproved`               |
| `assignment.draft_rejected`     | creator                              | assignment | `…assignment_draft_rejected`                     | `AssignmentDraftRejected`               |
| `assignment.manually_verified`  | creator                              | assignment | `…assignment_manually_verified`                  | `AssignmentManuallyVerified`            |

Registry: `apps/main/src/modules/notifications/templates.ts:100-130`. Note the
per-direction split is already honoured per §5.37 — the submit notification is
agency-recipient, the three outcomes are creator-recipient, so no single type
serves both parties.

There is deliberately **no** "resubmitted" notification type. Resubmission fires
`assignment.draft_submitted` again — the same type the first submission fires —
because the transition verb is the same. The enum's docblock also records which
verbs are excluded on purpose (`NotificationType.php:24-27`), and the resubmit
_requests_ (ACT2/ACT3) are email-only, sent directly by the resolution endpoint
rather than through the listener (`SendAssignmentNotifications.php:35-39`).

**Does any notification carry round context naturally? No.** The dispatch payload
is fixed and version-free on all three creator outcomes:

```207:219:apps/api/app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php
        $this->notifications->notify(
            recipient: $recipient,
            type: $this->reviewNotificationType($outcome),
            subject: $assignment,
            actor: $reviewer,
            data: [
                'campaign_name' => $campaign->name,
                'creator_name' => $creator->display_name ?? $recipient->name,
                'outcome' => $outcome,
                'feedback' => $feedback,
                'assignment_ulid' => $assignment->ulid,
            ],
        );
```

Same for the agency submit fan-out (`:151-156`: creator_name, campaign_name,
campaign_ulid) and the mailable, whose constructor takes creatorName,
campaignName, outcome, feedback, assignmentUlid (`:189-197`) — no version.

Where round context **does** already exist, for free:

- **The audit trail.** Every review transition's metadata carries
  `{draft_id, version}` (`CampaignAssignmentReviewController.php:206`, merged in
  `commit()` at `CampaignAssignmentStateMachine.php:601-638`), and every submit
  carries `{draft_id, version, media_count, link_count}`
  (`CreatorAssignmentDraftController.php:349-355`). The round number is already
  in `audit_logs` for both halves of every round.
- **The listener already loads the row that knows the version.** It fetches the
  latest draft to read feedback and reviewer (`SendAssignmentNotifications.php:181-184`),
  selecting only `['review_feedback', 'reviewed_by_user_id']` — adding `version`
  to that select is the whole mechanical cost of round-aware notification copy.

Ripple if round context is added to notification copy: each of the four affected
types needs its i18n template touched across 24 locales in
`notifications.json`, and the parity specs
(`i18n-notifications-parity.spec.ts`, 10 tests; `templates.spec.ts`, 10 tests)
pin the registry ↔ enum ↔ locale triangle. Adding a _new_ type would also demand
one `AuditAction` verb and one `LIVE_TYPES` entry per §5.37 — but nothing in
ask (A) as locked requires a new type, since feedback closes a round rather than
starting a new event.

---

## 4. IA3 — Round semantics edges

Four cases were probed. One is clean, one is unreachable, one is a genuine
open hole, and one is a small concurrency footnote.

### 4.1 Approve-after-changes — clean, no complication

`approve()` accepts only `draft_submitted` as a source
(`CampaignAssignmentStateMachine.php:351`) and the review controller refuses
anything else fail-closed (`assignment.not_reviewable` at
`CampaignAssignmentReviewController.php:176`). So the sequence v1 submit → changes requested →
v2 submit → approve produces exactly two rows: v1 `revision_requested` with its
feedback, v2 `approved` with `review_feedback = null` (approvals carry no
feedback — `SendAssignmentNotifications.php:186`). Rounds stay 1:1 with
submissions, earlier rounds keep their own closing status, and
`approved_at` stamps the assignment once (`:358-360`).

One presentational consequence: the creator's history will show
`Version 1 — Changes requested` above `Version 2 — Approved`, which is exactly
the round history (A) wants — the data is right, only the label is "Version".

### 4.2 Withdraw / cancel mid-cycle — **unreachable today**

`cancel()` exists on the machine, fires from any non-terminal state, requires a
reason, and stamps three columns:

```562:585:apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php
    public function cancel(CampaignAssignment $assignment, string $reason, ?User $actor = null): CampaignAssignment
    {
        if ($assignment->status->isTerminal()) {
            throw AssignmentTransitionException::terminal($assignment->status);
        }

        $trimmed = trim($reason);
        if ($trimmed === '') {
            throw AssignmentTransitionException::reasonRequired();
        }

        return $this->commit(
            $assignment,
            AssignmentStatus::Cancelled,
            AuditAction::AssignmentCancelled,
            $actor,
            reason: $trimmed,
            mutate: function (CampaignAssignment $a) use ($trimmed, $actor): void {
                $a->cancelled_at = now();
                $a->cancelled_reason = $trimmed;
                $a->cancelled_by_user_id = $actor?->id;
            },
        );
    }
```

But **no production code calls it.** A repo-wide grep for `->cancel(` /
`machine->cancel` in `*.php` returns three hits, all in
`CampaignAssignmentStateMachineTest.php:317`, `:345`, `:363`. There is no cancel
route in `apps/api/app/Modules/Campaigns/Routes/api.php` (grep for `cancel`:
no matches), and no creator-side withdraw. The only `AssignmentStatus::Cancelled`
references outside the machine are read-side: `JobLifecycleState.php:91`,
`MessageThread.php:66` (send-blocked statuses), `BoardCardService.php:149`.

So "withdraw/cancel mid-cycle" is a **latent** path, not a live one. Two things
follow:

- It does not complicate round semantics **today**, because it cannot happen.
- When it ships, it _will_: `cancel()` touches no `campaign_drafts` row, so a
  cancelled assignment leaves its newest round `review_status = 'pending'`
  forever — an open round with no closing feedback, which contradicts the locked
  "feedback closes a round". Whether that is acceptable (an abandoned round is
  honestly abandoned) or wants a closing status is a product call the round-number
  UI makes visible for the first time. Reported, not decided.

Note the campaign-level distinction: `CampaignStatus` has its own
`cancelled`/`completed` values used by the AH-054 listing rules
(`LISTABLE_STATUSES`), and AH-058 shipped a terminal auto-reject for pending
**applications** on cancelled/completed campaigns. Neither cancels an
_assignment_. Do not conflate the two vocabularies at kickoff.

### 4.3 The empty-draft rule (AH-044) — protects round semantics

```283:297:apps/api/app/Modules/Creators/Http/Controllers/CreatorAssignmentDraftController.php
        // A draft must carry SOMETHING to review: at least one media attachment
        // OR at least one external link (AH-044). Both empty is a no-op draft.
        /** @var list<array<string, mixed>> $media */
        $media = $validated['media'] ?? [];
        /** @var list<array<string, mixed>> $links */
        $links = $validated['links'] ?? [];
        if ($media === [] && $links === []) {
            return ErrorResponse::single(
                $request,
                422,
                'draft.empty',
                'Add at least one media attachment or link before submitting.',
                source: ['pointer' => '/data/attributes/media'],
            );
        }
```

This rejects **before** the transaction opens, so a refused submission consumes no
version number and creates no row. That is a _help_, not a complication: it means
no phantom rounds, and the round counter cannot be advanced by an empty submit.
The FE mirrors the same gate plus an `emptyHint` caption
(`CreatorAssignmentDetailPage.vue:188-195`; AH-044's "silent-disabled is a bug"
ruling, `adhoc-changes-log.md:1350-1351`).

Caption-only is worth naming explicitly: caption is `nullable` and is **not** part
of the "at least one of" invariant, so a round can consist of a link with no
caption — in which case the creator's history row renders version + status chip
and no subtitle at all (`CreatorAssignmentDetailPage.vue:938-940` is
`v-if="draft.attributes.caption"`). A round-history row that shows nothing but a
number is a real empty-state to design for.

### 4.4 Concurrency footnote on `max(version) + 1`

The version is computed by a `max()` read inside the same transaction as the
insert (`:316-320`), with the database's `unique_draft_assignment_version`
constraint as the only serialisation. Two simultaneous submits on one assignment
would race and the loser would surface a unique-constraint violation rather than
a validated 422. Practically improbable (one creator, one assignment, a
disabled-while-submitting button) and it is the pre-existing behaviour, not
something (A) introduces — but a visible round number makes any gap in the
sequence user-visible for the first time, so it is worth an explicit "accepted as
is" at kickoff rather than a silent assumption.

---

## 5. IB1 — The toggle's landing

### 5.1 The campaigns table has exactly three booleans, and two live patterns

| Column                           | Migration                                                      | Shape                                                                             |
| -------------------------------- | -------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| `is_marketplace_visible`         | `2026_06_05_100000_create_campaigns_table.php:84`              | `default(false)`, NOT NULL — P3 placeholder, no UI, **not** in the audit snapshot |
| `requires_per_campaign_contract` | same file, `:88`                                               | `default(false)`, NOT NULL — the AH-042 toggle                                    |
| `listed_on_jobs_board`           | `2026_07_27_100000_add_jobs_board_listing_to_campaigns.php:46` | `default(false)`, NOT NULL, `after(...)` — the AH-054 toggle                      |

The AH-054 additive shape, which is the pattern the ask names:

```43:52:apps/api/database/migrations/2026_07_27_100000_add_jobs_board_listing_to_campaigns.php
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->boolean('listed_on_jobs_board')->default(false)->after('requires_per_campaign_contract');
            $table->string('listing_duration', 120)->nullable()->after('listed_on_jobs_board');
            $table->string('listing_fee', 120)->nullable()->after('listing_duration');
            $table->jsonb('listing_languages')->nullable()->after('listing_fee');
            $table->jsonb('listing_regions')->nullable()->after('listing_languages');
            $table->string('listing_examples_url', 2048)->nullable()->after('listing_regions');
        });
    }
```

with a true inverse (`:62-74`, `dropColumn`). AH-054's own §5.40 line reads
"**LOW** — the migration is purely additive with an honest inverse; no existing
row is read or rewritten" (`adhoc-changes-log.md:934`).

Note the house does **not** use nullable booleans here: both live toggles are
NOT NULL with a `false` default, and the model additionally declares
`$attributes` defaults so an unsaved model reads `false` rather than `null`
(`Campaign.php:87-92`), plus `'boolean'` casts (`:259-263`).

### 5.2 The write path — the AH-054 read-pass catch applies verbatim

`store()` writes through an **explicit whitelist**, not `$fillable`:

```87:113:apps/api/app/Modules/Campaigns/Http/Controllers/CampaignController.php
        $campaign = Campaign::query()->create([
            'agency_id' => $agency->id,
            // ...
            'requires_per_campaign_contract' => $validated['requires_per_campaign_contract'] ?? false,

            // Jobs-board listing copy (AH-054, D2). `listed_on_jobs_board` is
            // absent by design — create never lists (D4); the column default
            // (false) carries it.
            'listing_duration' => $validated['listing_duration'] ?? null,
            // ...
        ]);
```

AH-054 recorded why this matters: "Fields added to the model only would have
validated, returned 201 and silently never persisted. Every create test in this
chunk asserts the **persisted** value" (`adhoc-changes-log.md:916-918`). Ask (B)'s
toggle is a **create-form** field, so it must be added to this array or it will
silently no-op — and its test must assert the stored row, not the response.

`update()` is different: no second whitelist, it fills from `validated()`
(`:151-189`), so a `sometimes|boolean` rule plus `$fillable` membership is
sufficient there. Validation lives at
`CreateCampaignRequest.php:68` and `UpdateCampaignRequest.php:66-68`
(both `['sometimes', 'boolean']`), with the listing floor's cross-field logic in
`UpdateCampaignRequest::withValidator()` `:82-127` and the shared
`ValidatesJobsBoardListing` concern.

Audit: both existing toggles are in the snapshot; `is_marketplace_visible` is not
(`CampaignController.php:289-302`). AH-054's Q6 ruled that a toggle flip "rides
`campaign.updated` rather than earning a new verb"
(`adhoc-changes-log.md:915`) — the precedent for (B) needing no new
`AuditAction`.

Emission: `CampaignResource.php:49-56` (snake_case, matching column names).
Types: `packages/api-client/src/types/campaign.ts:79-87` (resource attributes),
`:174` (create payload), `:190-201` (update payload). The creator-side meta key
`requires_per_campaign_contract?: boolean` at `:517` is the AH-042 precedent for
threading a campaign toggle down to the **creator's** assignment view — directly
reusable by (B), which needs the creator page to know whether posting is expected.

### 5.3 Form and Settings placement

**Create** — `CampaignForm.vue` renders the contract toggle as a `v-switch`:

```294:301:apps/main/src/modules/campaigns/components/CampaignForm.vue
    <v-switch
      :model-value="local.requires_per_campaign_contract ?? false"
      :label="t('app.campaigns.fields.requiresContract')"
      color="primary"
      density="compact"
      data-test="campaign-requires-contract"
      @update:model-value="update('requires_per_campaign_contract', $event ?? false)"
    />
```

The new-campaign default is achieved by **omission plus two independent
defaults**: `CampaignCreatePage.emptyForm()` doesn't include the key
(`CampaignCreatePage.vue:21-28`), the switch renders `?? false`, and the server
coerces `?? false`. A default-OFF toggle for (B) inherits all three for free —
which is worth noting because the ask's "default OFF for new campaigns" needs no
special mechanism at all on the create side.

**Settings** — `CampaignDetailPage.vue` hosts the same nested `CampaignForm` plus
a standalone jobs-board switch (`:947-958`), and Save sends the **whole form**:

```401:408:apps/main/src/modules/campaigns/pages/CampaignDetailPage.vue
  const payload: UpdateCampaignPayload = {
    ...rest,
    status: editStatus.value,
    listed_on_jobs_board: editListed.value,
  }

  try {
    const res = await campaignsApi.update(agencyId, ulid.value, payload)
```

The contrasting single-key PATCH lives on the list page, and its comment states
the rule the house follows:

```285:299:apps/main/src/modules/campaigns/pages/CampaignListPage.vue
/**
 * The one write. A SINGLE-KEY PATCH — `{ listed_on_jobs_board }` and nothing
 * else — which is why this surface cannot overwrite anything the row does not
 * own: every other field is governed by the endpoint's `sometimes` rules and is
 * preserved by its own absence. Settings sends the whole form because Settings
 * IS the whole form.
 */
async function commitListing(item: CampaignResource, value: boolean): Promise<void> {
```

i18n for the existing toggles: `app.campaigns.fields.requiresContract`
(`locales/en/app.json:460`), `app.campaigns.fields.listedOnJobsBoard` (`:470`),
with the listing hint block at `:848-874`. **24 locale directories** in
`apps/main/src/core/i18n/locales`, seven JSON namespaces each
(`app.json`, `auth.json`, `availability.json`, `creator.json`, `dashboard.json`,
`impersonation.json`, `notifications.json`); `apps/api/lang` also has 24 locales.

### 5.4 Migration posture — the ask's "new column default OFF, existing rows backfilled ON in the same migration"

This is the one place the ask proposes something the house has done exactly
**twice**, and both times deliberately narrowly. §5.40 is explicit: data mutations
ship as guarded, idempotent, dry-runnable commands, "never as migration side
effects. The only exception is a narrowly-scoped backfill that is (a) idempotent,
(b) predicate-guarded to touch **only** the rows belonging to the concept it
serves, and (c) test-pinned including the **leaves-everything-else-alone** case"
(`PROJECT-WORKFLOW.md:484`). The two reference shapes it names:

- **AH-041** — the board-column backfill, quoted here in full because it is the
  pattern the ask should be measured against:

```28:70:apps/api/database/migrations/2026_07_13_110000_backfill_cancelled_rejected_board_column.php
    public function up(): void
    {
        DB::table('board_columns')
            ->where('name', 'Cancelled')
            ->where('is_terminal_failure', true)
            ->update(['name' => 'Cancelled / Rejected', 'updated_at' => now()]);

        $boards = DB::table('boards')->pluck('id');

        foreach ($boards as $boardId) {
            $exists = DB::table('board_automations')
                ->where('board_id', $boardId)
                ->where('event_key', self::EVENT_KEY)
                ->exists();

            if ($exists) {
                continue;
            }

            $target = DB::table('board_columns')
                ->where('board_id', $boardId)
                ->where('is_terminal_failure', true)
                ->value('id');

            if ($target === null) {
                continue;
            }

            DB::table('board_automations')->insert([
                'ulid' => (string) Str::ulid(),
                'board_id' => $boardId,
                'event_key' => self::EVENT_KEY,
                // ...
            ]);
        }
    }
```

Note the three properties: a **narrow predicate** (`name = 'Cancelled' AND
  is_terminal_failure = true`, so a renamed column survives — test-pinned in
`BoardProvisioningServiceTest`), **idempotency** (`$exists` guard on the
`(board_id, event_key)` unique), and a `down()` that AH-041's entry itself
describes as "deliberately **blunt** in the opposite direction"
(`adhoc-changes-log.md:1488-1494`).

- **AH-048** — additive-nullable only, no backfill at all
  (`2026_07_16_100000_add_incomplete_nudge_sent_at_to_creators_table.php:29-41`).

**Assessment of the proposed mechanism.** A default-flip backfill for (B) would be
_structurally simpler_ than AH-041 in one respect and _broader_ in another:

- Simpler: the predicate is trivially total. "Every existing campaign gets ON" has
  no sub-selection to get wrong, and idempotency is free — a
  `where('<new_col>', false)` guard makes a re-run a no-op, and re-running after a
  deliberate operator flip-to-OFF would be the only way to over-reach, which the
  guard's ordering can't prevent by itself. That last point is the one real trap:
  an idempotency guard keyed on the column's own value cannot distinguish "not yet
  backfilled" from "operator set it OFF on purpose", so a second `migrate` run on
  a partially-operated system would silently re-flip. Nothing in the two existing
  backfills has this shape, because both key their guard on a **different** column
  than the one they write.
- Broader: it rewrites a column on **every row of a populated table**, which is
  exactly the language §5.40's alarm rule wants declared in plain terms ("this
  rewrites column Z on all rows"). AH-054's LOW rating came precisely from "no
  existing row is read or rewritten" — (B) cannot claim that sentence.

Two alternative mechanisms are visible in the codebase and should be weighed
rather than assumed away:

1. **Invert the column so the default carries the migration.** Store the _negation_
   — a column whose `false` default already means "existing behaviour" — so the
   migration is purely additive (AH-054's LOW posture) and no row is rewritten.
   Cost: the stored column reads inversely to the UI label, which the house has a
   documented allergy to (`CampaignResource.php:51-55` goes out of its way to
   explain that `listed_on_jobs_board` is _intent_, not _visibility_, precisely to
   stop that class of drift). A nullable tri-state (`null` = legacy = ON) is the
   same trick with the same readability cost and is what §5.40's
   "additive-nullable only" sentence would most naturally produce.
2. **The AH-042 D4 shape — a separate guarded, dry-runnable, idempotent command.**
   AH-042 needed exactly this for rows stranded by a toggle
   (`campaigns:advance-contractless-accepted`, `--dry-run`, predicate-scoped,
   registered as a post-deploy obligation in `RESUMPTION-TEMPLATE.md` Part 2 —
   `adhoc-changes-log.md:1413-1414`, `:1440-1442`). It is the §5.40-canonical
   mechanism, it is inspectable before it writes, and it costs one post-deploy
   step plus a deploy-obligations entry.

My read, offered as input rather than a decision: **(1) or (2) is a better fit for
§5.40 than the in-migration total flip**, with (2) the strongest if the flip must
be observable before it lands, because a `--dry-run` count of "N campaigns will be
set to ON" is exactly the pre-deploy evidence the standard asks for and an
in-migration `update()` can never provide. Whichever is chosen, the risk line for
that chunk is **not** NONE and must say so in plain language.

---

## 6. IB2 — The approval transition and the auto-advance target

### 6.1 The exact approve path

Route → controller → machine:

- `POST /api/v1/agencies/{agency}/campaigns/{campaign}/assignments/{assignment}/approve`
  (`apps/api/app/Modules/Campaigns/Routes/api.php:87-88`)
- `CampaignAssignmentReviewController::approve()` (`:120-123`) delegates to a
  shared private `review()` (`:154-228`) with `DraftReviewStatus::Approved`.
- `review()` refuses any assignment not in `draft_submitted`
  (`assignment.not_reviewable` at `:176`), selects the latest draft
  `orderByDesc('version')->first()` (`:181-184`), then in **one transaction**
  writes the draft's review trail first and drives the machine second
  (`:196-215`), dispatching on the review status:
  `Approved → approve()`, `RevisionRequested → requestRevision()`,
  `Rejected → rejectDraft($assignment, (string) $feedback, …)`.

The machine's approve edge:

```349:363:apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php
    public function approve(CampaignAssignment $assignment, ?User $actor = null, array $context = []): CampaignAssignment
    {
        $this->assertSource($assignment, [AssignmentStatus::DraftSubmitted], AssignmentStatus::Approved);

        return $this->commit(
            $assignment,
            AssignmentStatus::Approved,
            AuditAction::AssignmentDraftApproved,
            $actor,
            mutate: function (CampaignAssignment $a): void {
                $a->approved_at = now();
            },
            context: $context,
        );
    }
```

`commit()` is the single funnel for every transition — it sets the status, runs
the mutate callback, writes one audit row with `{from, to}` merged with
`$context`, and dispatches `AssignmentTransitioned`, **all inside one DB
transaction** (`:601-638`).

### 6.2 What follows approval today, and what "auto-advance" can mean

The real chain after `approved`, with every hop's driver:

| Hop                               | Driver                                                                                                                                                      | Automatic?                  |
| --------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------- |
| `approved → posted`               | creator POSTs posted-content; `markPosted()` (`CreatorAssignmentDraftController.php:373-416`, machine `:410-419`)                                           | no — creator action         |
| `posted → live_verified`          | `VerifyPostedContentJob` on a Verified outcome (`:99-112`), dispatched by `DispatchPostedContentVerification` only when `social_verification_enabled` is ON | yes, when the flag is armed |
| `posted` stays `posted`           | `not_found` / `mismatch` — agency emailed, no transition                                                                                                    | —                           |
| `posted → manually_verified`      | agency ACT1, mandatory reason (`CampaignAssignmentResolutionController`, machine `:488`)                                                                    | no                          |
| `posted → approved`               | agency ACT2 fresh resubmit (machine `:522-532`)                                                                                                             | no                          |
| `live_verified → payment_held`    | `holdPayment()` — **throws** `escrowUnavailable()`                                                                                                          | unreachable                 |
| `payment_held → payment_released` | `releasePayment()` — **throws**                                                                                                                             | unreachable                 |

So the assignment's reachable resting places after approval are `posted`,
`live_verified`, and `manually_verified`. There is no `completed`.

The AH-042 precedent for chaining, quoted in full because it is the shape (B)
should reinterpret rather than reinvent:

```60:84:apps/api/app/Modules/Creators/Http/Controllers/CreatorAssignmentController.php
    public function accept(Request $request, string $assignment, CampaignAssignmentStateMachine $machine): JsonResponse
    {
        return $this->transition($request, $assignment, 'assignment.accepted', static function (CampaignAssignment $a, User $actor) use ($machine): void {
            // Toggle-off flow (D2): a campaign that does NOT require a
            // per-campaign contract auto-advances straight through
            // `accepted → contracted` with NO contract, so the creator never
            // sees, waits for, or hears about a contract — the next screen is
            // the draft form. requires=true stays at `accepted` (the
            // creator-accepts-a-contract path, unchanged, D7). Both flips run
            // in ONE outer transaction (all-or-nothing). The contract-less
            // advance is audit-distinguished from the agency's manual
            // proceed-without-contract via `auto_advanced: true` (D6); the
            // machine permits the null advance regardless of the flag (D1).
            DB::transaction(function () use ($machine, $a, $actor): void {
                $machine->accept($a, $actor);

                $campaign = Campaign::query()
                    ->withoutGlobalScope(BelongsToAgencyScope::class)
                    ->find($a->campaign_id);

                if ($campaign !== null && ! $campaign->requires_per_campaign_contract) {
                    $machine->contract($a, null, $actor, ['auto_advanced' => true]);
                }
            });
        });
    }
```

Three properties to carry forward: **one outer transaction** wrapping both
transitions; the campaign toggle read with the tenancy global scope removed
because the actor is the creator; and the D6 audit distinguishability —
`['auto_advanced' => true]` on the chained hop, with the backfill path adding
`'source' => 'backfill'` (`AdvanceContractlessAcceptedAssignments.php:72-75`) so
three contract-less paths carry three distinct signatures
(`adhoc-changes-log.md:1437-1439`).

**The three candidate mechanisms, with their suppress-vs-express consequences.**
Reported for the kickoff to choose; each is a different amount of new surface.

_Option 1 — chain `approve() → markPosted() → manuallyVerify()` using existing
edges._ Cheapest in enum terms (no new case, D5 mapping untouched, both hops land
in `Completed`). But it fires three transitions and therefore three fan-outs of
side effects, and the middle one is a **lie**: `posted` means "the creator posted
it", `assignment.posted_by_creator` is the verb, and `posted_at` is stamped
(`machine :419+`). The specific side effects that would need suppressing rather
than expressing:

- `DispatchPostedContentVerification` would queue a verification job for a post
  that does not exist. It reads `posted_content_id` from the event context
  (`DispatchPostedContentVerification.php:32-48`) — and `markPosted()`'s real
  caller creates a `campaign_posted_content` row first
  (`CreatorAssignmentDraftController.php:373-416`), so a synthetic advance has
  no row to reference. Must be suppressed.
- `WriteSystemMessage` would write "posted", "live/manually verified" lines into
  the assignment thread — its allowlist includes
  `AssignmentPostedByCreator`, `AssignmentLiveVerified`,
  `AssignmentManuallyVerified` (`WriteSystemMessage.php:36-47`). AH-043 is the
  precedent for the _right_ answer here, and it chose **express-through with
  truthful copy, not suppression**: "Neutral copy, not suppression: `contracted`
  on an OFF campaign genuinely _does_ mean production can begin — only the
  'contract was signed' clause is false. A distinct, truthful key preserves the
  production-start milestone" (`adhoc-changes-log.md:1381-1383`). AH-043 also
  records that AH-042's own review **missed** this listener entirely
  (`:1386-1391`) — the single most transferable lesson for (B): sweep **all
  seven** `AssignmentTransitioned` consumers, not the notification one.
- `SendAssignmentNotifications` would emit `assignment.manually_verified` to the
  creator — a creator-recipient in-app row plus a queued
  `PostManuallyVerifiedMail` saying their post was verified
  (`SendAssignmentNotifications.php:83-118`). Must be suppressed or forked.
- `BoardAutomationListener` would move the card to **Posted** three times over
  (all three verbs target it — `BoardDefaults.php:57-59`), i.e. into the very
  column (B) wants hidden. Given the ask also hides that column, the card would
  land in a column the board does not render — a state worth naming out loud.
- `manuallyVerify()` requires a mandatory reason (`machine :488`, reason-required
  per `AuditAction.php:419-456`), so a synthetic advance must invent one.

_Option 2 — a new `approved → <terminal>` edge to an existing state._ Skips
`posted` entirely, so `DispatchPostedContentVerification` never fires and no
posted-content row is implied. Still inherits the notification and
system-message consequences of whichever target verb is used, and still lands the
card in Posted via the target's automation.

_Option 3 — a new enum case (e.g. a genuine `completed`)._ Cleanest semantically:
a distinct verb means every consumer opts in explicitly rather than being
suppressed, which is the §5.38 contract-keyed-listener posture and matches
AH-043's "truthful distinct key" ruling. Cost is the full 17th-case ripple —
enumerated in §8.4 — including a deliberate `JobLifecycleState` family
assignment (which the exhaustiveness test will _force_, by design), the TS union,
and the status-label i18n block across 24 locales.

### 6.3 The D5 mapping — where the auto-advanced terminal must land

The three-family reflection, exhaustive and `default`-free by design:

```71:93:apps/api/app/Modules/Creators/Enums/JobLifecycleState.php
    public static function fromAssignmentStatus(AssignmentStatus $status): self
    {
        return match ($status) {
            AssignmentStatus::Invited,
            AssignmentStatus::Countered,
            AssignmentStatus::Accepted,
            AssignmentStatus::Contracted,
            AssignmentStatus::Producing,
            AssignmentStatus::DraftSubmitted,
            AssignmentStatus::RevisionRequested,
            AssignmentStatus::Approved => self::InProgress,

            AssignmentStatus::Posted,
            AssignmentStatus::LiveVerified,
            AssignmentStatus::ManuallyVerified,
            AssignmentStatus::PaymentHeld,
            AssignmentStatus::PaymentReleased => self::Completed,

            AssignmentStatus::Declined,
            AssignmentStatus::Rejected,
            AssignmentStatus::Cancelled => self::Ended,
        };
    }
```

The ask's constraint ("must be Completed") is satisfied automatically by
`posted`, `live_verified`, `manually_verified`, `payment_held`, and
`payment_released`, and violated by `approved`. AH-059 recorded the two rulings
that make this partition non-obvious and therefore worth not re-litigating:
"**`approved` is In progress, not Completed** (nothing is live yet), and **the
payment pair is Completed** — the `isTerminal()` trap, since `payment_released` is
terminal _and a success_" (`adhoc-changes-log.md:574-576`).

The pin that will catch a careless 17th case:

```23:36:apps/api/tests/Feature/Modules/Creators/JobLifecycleStateTest.php
it('maps every single AssignmentStatus case — the exhaustiveness pin', function (): void {
    // The whole point: iterate the SOURCE enum, not a hand-listed set. A new
    // case reaches `fromAssignmentStatus()` and either maps or throws — there is
    // no third outcome, because there is no `default` arm to absorb it.
    foreach (AssignmentStatus::cases() as $case) {
        $state = JobLifecycleState::fromAssignmentStatus($case);

        expect($state)->toBeInstanceOf(
            JobLifecycleState::class,
            "AssignmentStatus::{$case->name} has no JobLifecycleState family. Add it to "
            .'JobLifecycleState::fromAssignmentStatus() deliberately — do not add a default arm.',
        );
    }
});
```

plus a disjoint-and-complete 8/5/3 membership assertion at `:38-77`. Note the
consequence for Option 1/2 above: because the mapping is **per status, not per
campaign**, if a toggle-OFF campaign's terminal is an existing status, the
creator-facing family label of that status is shared with toggle-ON campaigns.
There is no way to say "approved means Completed on OFF campaigns only" without
either a new case or moving `assignment_state` off a pure status function.

`isPaymentEligible()` has a related and currently harmless asymmetry: it returns
true for `live_verified` and `manually_verified` (`AssignmentStatus.php:93-99`)
and has **no production call site** — only `CampaignEnumsTest.php:92-109` and
`CampaignAssignmentStateMachineTest.php:468`. Whatever (B) picks as its terminal
determines whether toggle-OFF assignments will be payment-eligible when Sprint 10
consumes that predicate. See §7.

---

## 7. IB3 — The Posted column

### 7.1 How columns are provisioned

`BoardDefaults` is the single source (7 columns, 10 automations):

```34:42:apps/api/app/Modules/Boards/Support/BoardDefaults.php
        return [
            self::column('To Define', 'status-todefine'),
            self::column('Invited', 'status-progress'),
            self::column('In Review', 'status-review'),
            self::column('Approved', 'status-aligned'),
            self::column('Posted', 'status-posted'),
            self::column('Paid', 'status-paid', success: true),
            self::column('Cancelled / Rejected', 'status-blocked', failure: true),
        ];
```

```53:67:apps/api/app/Modules/Boards/Support/BoardDefaults.php
        return [
            self::automation(AuditAction::AssignmentInvited, 'Invited'),
            self::automation(AuditAction::AssignmentDraftSubmitted, 'In Review'),
            self::automation(AuditAction::AssignmentDraftApproved, 'Approved'),
            self::automation(AuditAction::AssignmentPostedByCreator, 'Posted'),
            self::automation(AuditAction::AssignmentLiveVerified, 'Posted'),
            self::automation(AuditAction::AssignmentManuallyVerified, 'Posted'),
            self::automation(AuditAction::AssignmentResubmitRequested, 'Approved'),
            // INERT until Sprint 10 (D-11) — escrow is gated, so the event never fires.
            self::automation(AuditAction::AssignmentPaymentReleased, 'Paid'),
            self::automation(AuditAction::AssignmentCancelled, 'Cancelled / Rejected'),
            // Draft rejection is the terminal review outcome — the card lands in
            // the same failure column as a cancel.
            self::automation(AuditAction::AssignmentDraftRejected, 'Cancelled / Rejected'),
        ];
```

**Three of the ten automations target Posted.** Provisioning maps target _name_ →
id once, at seed time, and tolerates a missing name by storing a null target:

```41:83:apps/api/app/Modules/Boards/Services/BoardProvisioningService.php
    private function seedColumns(Board $board): void
    {
        // Seed columns only when the board has none — never clobber agency edits.
        if ($board->columns()->exists()) {
            return;
        }

        $position = 1;
        foreach (BoardDefaults::columns() as $column) {
            BoardColumn::query()->create([
                'board_id' => $board->id,
                // ...
            ]);
        }
    }

    private function seedAutomations(Board $board): void
    {
        // Map column NAME → id for the freshly-seeded (or existing) columns.
        $columnIdsByName = $board->columns()
            ->get(['id', 'name'])
            ->pluck('id', 'name');

        foreach (BoardDefaults::automations() as $automation) {
            $targetColumnId = $columnIdsByName[$automation['target_column_name']] ?? null;
            // firstOrCreate on the (board_id, event_key) UNIQUE — idempotent…
```

At runtime, resolution is by stored `target_column_id`, and a null target is a
silent no-op:

```52:73:apps/api/app/Modules/Boards/Services/BoardAutomationService.php
        $automation = $board->automations()
            ->where('event_key', $eventKey)
            ->where('is_enabled', true)
            ->first();

        if ($automation === null
            || $automation->action_type !== BoardAutomationActionType::MoveToColumn
            || $automation->target_column_id === null) {
            return;
        }
```

That silence is worth flagging on AH-059's own precedent: the c5 chunk's real
finding was that a notifier "was **silent about its own silence**", costing an
hour of eyes-on (`adhoc-changes-log.md:561-568`). Three intentionally-null
automations per OFF campaign is exactly that shape.

Also relevant: `board_automations` has a `condition` column, nullable, evaluated
by `evaluateCondition()` before a move (`BoardAutomationService.php:66-68`;
schema `2026_06_06_120002_create_board_automations_table.php:36-61`). Whether that
existing condition mechanism can already express "only when the campaign expects
posting" is a question for the plan pass — I did not read `evaluateCondition()`'s
grammar, and I am not claiming it can.

### 7.2 Is per-campaign omission expressible? Not today.

- **Data model: yes, trivially** — columns are per-board rows with a free-text
  name, one board per campaign.
- **Provisioning: no variance exists** — every board gets the same 7
  (`BoardProvisioningService.php`, no campaign parameter anywhere).
- **Rendering: no conditional** — the SPA renders all API columns sorted by
  position (`useBoardStore.ts:71-73` → `BoardColumns.vue:51-60` →
  `BoardColumn.vue:73-78`, which prints `column.attributes.name` verbatim).
- **Column names are English DB values, not i18n keys.** `'Posted'` is a literal
  in `BoardDefaults.php:39`, stored in `board_columns.name`, emitted by
  `BoardColumnResource`, and rendered raw. The `app.campaigns.assignmentStatus.posted`
  key (`locales/en/app.json:739`) is the **assignment status label**, a different
  thing. So "hide the Posted column" is a data question, not a translation one —
  and an agency that has renamed the column is not matchable by name.

### 7.3 What hiding it requires — and the existing-board reality

**New boards.** Omit the column at seed time and let the three targeting
automations resolve to null, or omit those automations too. Small change,
localised to `BoardProvisioningService` / `BoardDefaults`, plus a decision about
whether the seeded set becomes campaign-aware (a signature change:
`provisionDefaults(Board $board)` currently takes no campaign).

**Existing boards.** Because all existing campaigns migrate to ON, no board needs
surgery on day one. The live question is a **later flip to OFF** on a campaign
whose board already has the column. The facts, reported without a recommendation:

1. The column exists and will keep rendering unless something removes or hides it.
2. Cards may already sit in it. Any assignment at `posted`, `live_verified`, or
   `manually_verified` maps to the Posted column through
   `BoardCardService::representativeEventKey()` (`:144-146`), and the lazy-heal
   path places card-less assignments there on the next board GET
   (`BoardLazyHealTest.php:28-45` pins exactly that).
3. Deleting the column is a real endpoint with real guards — it re-homes cards and
   refuses the last column (`BoardColumnDeleteTest.php`, 4 tests). Deleting also
   nulls the three automation targets via `nullOnDelete()`
   (`2026_06_06_120002_create_board_automations_table.php:26-28`), which is
   coherent but silent.
4. **Provisioning will never re-add it** — `seedColumns()` bails when any column
   exists (`:43-45`), and `ensureBoard()` only provisions on
   `wasRecentlyCreated` (`BoardService.php:60-64`). `forCampaign()` heals missing
   _cards_, not columns (`:70-86`).
5. **`BoardResetService` WILL re-add it** — reset restores all 7 defaults
   (`BoardResetTest.php`, 9 tests). So a reset on an OFF campaign would resurrect
   a column the toggle says should not exist. This is the sharpest edge I found in
   IB3, and it exists for _any_ omission mechanism that works by deleting rows
   rather than by filtering at render or provision time.
6. Column deletion is also a **manual agency affordance today**, so agencies can
   already reach a Posted-less board by hand, and `processEvent()` already
   tolerates it silently.

The choice between "omit the row" and "filter the render" is therefore not
cosmetic: row-omission collides with reset and with the never-re-seed rule;
render-filtering leaves the row (and any cards in it) intact and orphaned. The
posture gets decided at kickoff, as the ask says — this section is the input.

---

## 8. IB4 / IB5 — Downstream and ripple

### 8.1 Payments and Sprint-10 prep

There is **no payment code**. `isPaymentEligible()` has zero production call
sites; `holdPayment()` / `releasePayment()` throw unconditionally
(`CampaignAssignmentStateMachine.php:539-556`). What exists is a contract for
Sprint 10 to honour, recorded in three places:

- The enum docblock instructs S10 to consume the predicate, never the literal
  string, so a manual override stays payable without collapsing the audit
  distinction (`AssignmentStatus.php:83-92`).
- `docs/tech-debt.md:1697-1703` — S10's release gate must consume
  `isPaymentEligible()` and widen `holdPayment()`'s source to include
  `manually_verified`.
- `docs/20-PHASE-1-SPEC.md:590` — the "Release payment" affordance is gated on a
  payment-eligible status.

**The consequence for (B), which is the thing to carry to kickoff:** eligibility
is keyed on the two _verified_ statuses. If a toggle-OFF campaign's terminal is
anything other than `live_verified` / `manually_verified` (or a new case
deliberately added to the predicate), then **toggle-OFF assignments will be
invisible to the Sprint 10 release gate** — the creators most likely to be
already paid out-of-band, or never paid at all. That is not a bug today because
nothing reads the predicate; it becomes one the moment Sprint 10 ships, and it is
much cheaper to decide now than to discover then. Report, don't decide — but do
not leave it unstated at kickoff.

Creator-side payment provider seams exist for onboarding only
(`Modules/Creators/Integrations/Contracts/PaymentProvider.php` plus Deferred /
Skipped / Mock / Stripe stubs); escrow is explicitly deferred to a future
`Modules/Payments`.

### 8.2 The digest and the overdue scan

Three scheduled commands, all `->daily()` (`apps/api/bootstrap/app.php:34-48`):
`messages:send-digest`, `boards:scan-overdue`, `creators:send-incomplete-nudges`.

**The digest does not touch assignment status at all.** It is a _messaging_
digest — unread human messages per user, thread-scoped
(`MessageDigestService.php:52-64`, agency threads `:77-80`, creator threads
`:122-128`, unread count `:236-248`). No status predicate anywhere. So the ask's
"the digest/overdue scans' relationship to posted/verification states" has a
short answer for the digest half: **there is none.**

**The overdue scan keys off dates and one-shot flags, not statuses or columns:**

```84:88:apps/api/app/Modules/Boards/Services/OverdueScanService.php
CampaignAssignment::query()
    ->withoutGlobalScope(BelongsToAgencyScope::class)
    ->whereNotNull($dueColumn)       // posting_due_at OR draft_due_at
    ->whereNull($flagColumn)         // posting_overdue_flagged_at OR draft_overdue_flagged_at
    ->where($dueColumn, '<', $now)
    ->get();
```

No status filter — a documented Q3 choice. It emits
`assignment.posting_overdue` / `assignment.draft_overdue` through
`BoardAutomationService::processEvent()` (`:99-107`), and **no default automation
is seeded for either key**, so both are inert on a default board.

The (B)-relevant consequence: a toggle-OFF campaign whose assignments carry a
`posting_due_at` would still be scanned and flagged as **posting**-overdue for a
posting step that will never happen. Whether `posting_due_at` is even set on such
campaigns I did not trace to its writer — flagged as an open question in §10.

### 8.3 Consumers that a toggle-OFF campaign would leave forever-unreached

Reported, not decided:

| Consumer                                                                                        | Path                                                                                   | What never happens on an OFF campaign                                                                                                                                                         |
| ----------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `campaign_posted_content` rows                                                                  | `CreatorAssignmentDraftController.php:373-416`                                         | no row is ever created; every reader below sees an empty relation                                                                                                                             |
| `DispatchPostedContentVerification` → `VerifyPostedContentJob`                                  | `DispatchPostedContentVerification.php:32-48`                                          | never dispatched (no `posted_by_creator` verb)                                                                                                                                                |
| `PostVerificationFailedMail` to the inviter                                                     | `VerifyPostedContentJob.php:99-112`                                                    | never sent                                                                                                                                                                                    |
| The three resolution actions (ACT1/2/3)                                                         | `CampaignAssignmentResolutionController`                                               | unreachable — all three require `status === Posted` (`:160-175`)                                                                                                                              |
| `ResolveVerificationDrawer` + its three doors (Creators tab, Drafts tab, board drawer — AH-045) | `CampaignDetailPage.vue:192-198`, `DraftsTab.vue:80-87`, `BoardCardDrawer.vue:109-115` | gates are `status === 'posted' && verification ∈ {not_found, mismatch}`; never true                                                                                                           |
| `ViewPostedContentDrawer`                                                                       | `ViewPostedContentDrawer.spec.ts` (3 tests)                                            | nothing to view                                                                                                                                                                               |
| `NotificationType::AssignmentManuallyVerified`                                                  | `SendAssignmentNotifications.php:83-118`                                               | never emitted (unless it becomes the auto-advance verb)                                                                                                                                       |
| System messages for `posted_by_creator` / `live_verified` / `manually_verified`                 | `WriteSystemMessage.php:36-47`                                                         | absent from the thread — the AH-043 "does the milestone still deserve a truthful line?" question, again                                                                                       |
| Board Posted column                                                                             | `BoardDefaults.php:39`                                                                 | intentionally hidden by the ask                                                                                                                                                               |
| `verification_status` on the two resources                                                      | `CampaignAssignmentResource.php:46-47`, `CampaignDraftListItemResource.php:43-44`      | always null (already null-tolerant — AH-045 shipped it optional)                                                                                                                              |
| `creator.ui.assignments.detail.awaitingVerification` + the AH-047 `verifiedNotice`              | `CreatorAssignmentDetailPage.vue:898-915`                                              | the ask replaces these with approved-complete copy; note `isVerified` is `live_verified \|\| manually_verified` (`:176-178`), so the sibling copy needs a third branch or a widened predicate |
| `isPaymentEligible()`                                                                           | `AssignmentStatus.php:93-99`                                                           | see §8.1 — possibly never true                                                                                                                                                                |

Nothing reads `campaign_posted_content` for reporting, exports, or metrics; the
`metrics` / `metrics_history` columns have no consumer beyond schema tests
(`Sprint9MigrationTest.php`). And there is **no campaign-level progress or
completion counter** to worry about: `CampaignResource.php:64` emits an
unfiltered `assignment_count` — the `applications()` docblock states it stays
"an unfiltered count of `assignments`" and keeps its shipped meaning
(`Campaign.php:181-183`) — and the
Overview tab shows campaign metadata only (`CampaignDetailPage.vue:501-570`).

### 8.4 The 17th-case ripple (if a new enum case is chosen)

Every site a new `AssignmentStatus` case must be reckoned with. The
`default`-free matches are compile/analysis breaks; the rest are silent.

| Site                                         | Path                                                                                                                                                                      | Breaks how                                                                                    |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| `JobLifecycleState::fromAssignmentStatus()`  | `JobLifecycleState.php:73-92`                                                                                                                                             | no `default` — PHPStan + the exhaustiveness test                                              |
| `BoardCardService::representativeEventKey()` | `BoardCardService.php:135-152`                                                                                                                                            | all 16 enumerated                                                                             |
| `AssignmentStatus::isTerminal()`             | `:74-80`                                                                                                                                                                  | has `default => false` — **silently** treats a new case as non-terminal                       |
| `AssignmentStatus::isPaymentEligible()`      | `:95-99`                                                                                                                                                                  | has `default => false` — **silently** not payable                                             |
| TS union `AssignmentStatus`                  | `packages/api-client/src/types/campaign.ts:28-44`                                                                                                                         | vue-tsc                                                                                       |
| TS `board.ts` card payload                   | `packages/api-client/src/types/board.ts:63`                                                                                                                               | via the union                                                                                 |
| Status label i18n block                      | `locales/*/app.json:728-744`                                                                                                                                              | 24 locales × 1 key; parity gate reds                                                          |
| Six dynamic label consumers                  | `CampaignDetailPage.vue:618`, `DraftsTab.vue:200`, `BoardCard.vue:48`, `BoardCardDrawer.vue:378`, `CreatorAssignmentsPage.vue:137`, `CreatorAssignmentDetailPage.vue:484` | all use `t(\`app.campaigns.assignmentStatus.${status}\`)` — a missing key renders the raw key |
| 16-case catalogue test                       | `CampaignEnumsTest.php:44-75`                                                                                                                                             | reds by design                                                                                |
| `MessageThread::HUMAN_SEND_BLOCKED_STATUSES` | `MessageThread.php:63-67`                                                                                                                                                 | silent — a new terminal stays chat-open unless added                                          |
| Backend transition strings                   | `lang/*/messages.php` (`system.assignment.*`)                                                                                                                             | 24 locales if the case earns a system message                                                 |

**Gap worth naming:** no test parses `AssignmentStatus.php` and compares it to the
TS union. Parity there is comment-documented only, unlike locales
(`packages/api-client/src/locales.spec.ts`, 11 tests) and notification types
(`templates.spec.ts`, 10 tests). §5.25 would nominate one.

### 8.5 Tests that pin the affected paths

Counts are `^(it|test)(` declarations counted at HEAD, not Pest run counts —
dataset-driven tests expand at runtime (which is why AH-054's entry says 31 for a
file whose declarations count 28).

**Backend — draft submit / review / approve**

| File                                                                     | Tests |
| ------------------------------------------------------------------------ | ----- |
| `tests/Feature/Modules/Campaigns/CampaignAssignmentStateMachineTest.php` | 32    |
| `tests/Feature/Modules/Creators/CreatorAssignmentDraftTest.php`          | 23    |
| `tests/Feature/Modules/Campaigns/CampaignAssignmentReviewTest.php`       | 11    |
| `tests/Feature/Modules/Campaigns/CampaignDraftListTest.php`              | 10    |
| `tests/Feature/Modules/Campaigns/CampaignEnumsTest.php`                  | 9     |
| `tests/Feature/Modules/Creators/JobLifecycleStateTest.php`               | 7     |

**Backend — post / verify**

| File                                                                   | Tests |
| ---------------------------------------------------------------------- | ----- |
| `tests/Feature/Modules/Campaigns/CampaignAssignmentResolutionTest.php` | 8     |
| `tests/Feature/Modules/Campaigns/VerifyPostedContentJobTest.php`       | 7     |

**Backend — boards**

| File                                                            | Tests |
| --------------------------------------------------------------- | ----- |
| `tests/Feature/Modules/Boards/BoardApiTest.php`                 | 12    |
| `tests/Feature/Modules/Boards/BoardResetTest.php`               | 9     |
| `tests/Feature/Modules/Boards/OverdueScanTest.php`              | 8     |
| `tests/Feature/Modules/Boards/BoardProvisioningServiceTest.php` | 7     |
| `tests/Feature/Modules/Boards/BoardAutomationServiceTest.php`   | 6     |
| `tests/Feature/Modules/Boards/BoardColumnDeleteTest.php`        | 4     |
| `tests/Feature/Modules/Boards/BoardLazyHealTest.php`            | 3     |

`BoardProvisioningServiceTest` asserts the exact 7-column order and the 10
automation targets (`:15-72`) — a campaign-aware default set reds it by design.
Also in scope: `CampaignJobsBoardListingTest.php` (28 declarations) as the
reference shape for a toggle's own test file, per AH-054's pin list.

**Frontend Vitest**

| File                                                     | Tests |
| -------------------------------------------------------- | ----- |
| `campaigns/pages/CampaignDetailPage.spec.ts`             | 36    |
| `creators/pages/CreatorAssignmentDetailPage.spec.ts`     | 24    |
| `campaigns/pages/CampaignListPage.spec.ts`               | 12    |
| `boards/components/BoardColumns.spec.ts`                 | 11    |
| `campaigns/components/DraftsTab.spec.ts`                 | 8     |
| `campaigns/components/ReviewDraftDrawer.spec.ts`         | 7     |
| `boards/components/BoardView.spec.ts`                    | 6     |
| `campaigns/components/ResolveVerificationDrawer.spec.ts` | 5     |
| `campaigns/components/ViewPostedContentDrawer.spec.ts`   | 3     |

**`CampaignForm.vue` has no spec file** (verified: only `CampaignForm.vue` exists
in `apps/main/src/modules/campaigns/components/`). Its toggles are covered
indirectly through `CampaignDetailPage.spec.ts`. A create-form toggle for (B)
would either create that spec or extend the page spec — worth an explicit call
rather than an accident.

### 8.6 i18n scope

- `apps/main/src/core/i18n/locales`: **24** locale directories × **7** JSON
  namespaces = 168 files. Ask (A) lands in `creator.json` + `app.json`; ask (B)
  adds `app.json` (the toggle label + hint) and `creator.json` (the
  approved-complete banner), plus `notifications.json` only if a new
  notification type appears.
- `apps/api/lang`: **24** locale directories (`app.php`, `auth.php`,
  `campaigns.php`, `creators.php`, `invitations.php`, `messages.php`,
  `mock-vendor.php`). A new system-message key lands in `messages.php` ×24 —
  AH-043's shape (`adhoc-changes-log.md:1374-1376`).
- Parity gates: `apps/main/tests/unit/architecture/i18n-locale-parity.spec.ts`
  (4 tests), `i18n-notifications-parity.spec.ts` (10),
  `apps/main/src/modules/notifications/templates.spec.ts` (10),
  `packages/api-client/src/locales.spec.ts` (11).
- The **flaky 10** are pinned in code, not just docs:

```169:180:apps/api/tests/Feature/Modules/Campaigns/ApplicationMailTest.php
it('renders in the flaky-10 locales with real translations, not the English fallback', function (string $locale): void {
    // The flaky 10 are where machine-translation baselines have gone missing
    // before (AH-028/046/047). New keys never ship with an English fallback, so
    // every one of these must differ from en — subject AND body.
```

with the dataset `['bg', 'el', 'et', 'fi', 'ga', 'hu', 'lt', 'lv', 'mt', 'ro']`
— matching `WORKING-PROCESS.md:102` and the AH-028/046/047 ruling that new keys
never ship with an English fallback.

Renaming "Version" → "Draft round" is not a free copy change: it touches
`creator.ui.assignments.detail.history.*` and
`app.campaigns.review.history` / `.draftVersion` across 24 locales each, and both
label sets are asserted by name in existing specs.

### 8.7 Playwright E2E

**18 spec files, 2 projects** (chromium desktop + an iPhone-13 mobile project —
`apps/main/playwright.config.ts:84-116`), living in
`apps/main/playwright/specs/`.

**The critical finding: no E2E spec traverses posting or verification today.** The
AH-059 full-lifecycle spec stops before the draft cycle, and says so in its own
header:

```45:51:apps/main/playwright/specs/jobs-board-full-lifecycle.spec.ts
 * ── Where it stops, and why that is the honest line (Q8) ────────────────────
 *
 * Step 7 is the end. Drafts and posting would need the review cycle, media
 * uploads and the verification hop — three more surfaces, each with its own
 * feature coverage, and a spec that owned all of them would fail for reasons
 * that have nothing to do with the jobs board. The named future spec is the
 * draft → review → posted → verified leg, recorded in the chunk's review.
```

AH-059 also recorded the two product facts the spec's stopping point rests on:
the assignment auto-advances to `contracted` because the seeded campaign is
`requires_per_campaign_contract = false`, and the card does not change columns —
"same ULID, changed chip" (`adhoc-changes-log.md:579-582`).

So the ask's framing — "the full-lifecycle spec traverses posting — what a
toggle-OFF variant leg needs" — rests on a premise that does not hold. There is
**no posting leg to fork**. A toggle-OFF E2E leg would be built on top of the
draft → review → posted → verified spec that AH-059 explicitly deferred, i.e. the
E2E cost for (B) is _write the deferred spec first_, and the honest options are
(a) build both, (b) build the OFF path only and leave ON uncovered end-to-end, or
(c) accept Vitest + Pest coverage and defer E2E again with a recorded trigger.
That is a kickoff decision with real cost attached, not a leg to add.

Seeding helpers available (`playwright/fixtures/test-helpers.ts`):
`POST /api/v1/_test/agencies/setup`,
`…/_test/agencies/{agencyUlid}/listable-campaign`,
`…/_test/creators/listed-job`,
`…/_test/agencies/{agencyUlid}/pending-applications`, plus clock, rate-limiter,
TOTP and verification-token helpers. There is **no** helper that seeds an
assignment at `approved` or `posted`, so either would be net-new — and the
existing helper has its own backend gate test
(`tests/Feature/TestHelpers/CreateListableCampaignTest.php`, 8 tests), which is
the pattern a new helper would follow. Playwright also needs the dev stack down
and its own E2E DB (`WORKING-PROCESS.md:250-251`).

---

## 9. §5.40 risk, re-derived per prospective chunk

Restating the ask's own note with the evidence now in hand. **These are scoping
estimates. The binding declaration happens at each plan-pause per
`WORKING-PROCESS.md` §6, on the plan actually proposed.**

**Ask (A) — numbered rounds: LOW, and lower than the ask assumed.** Confirmed as
LOW _and_ narrowed: because versions are already retained with per-round feedback
already attached (§0.1), the likely shape is **no migration at all** — copy, an
i18n rename across 24 locales, richer history rows from fields already on the
wire, and possibly `version` added to a notification payload. Nothing reads or
rewrites an existing row. If the plan turns out to need a column (a cap counter, a
`closed_at`), the risk line must be re-derived then.

**Ask (B) — the toggle: MEDIUM, and the ask's characterisation is right for the
right reasons.** Three distinct risk surfaces, and it is worth separating them
because they may not ship together:

1. **The column + backfill.** The additive column alone is AH-054-shaped and LOW.
   The proposed in-migration flip-all-rows-to-ON is what makes it MEDIUM: it
   rewrites a column on every row of a populated table, and the obvious
   idempotency guard is self-referential (§5.4). A `--dry-run` command or an
   inverted/nullable default would pull this back toward LOW.
2. **The state-machine edge.** A new transition or enum case in the live
   assignment machine, on a path real assignments traverse daily, with seven
   `AssignmentTransitioned` consumers to sweep (§6.2) — and AH-043 is the
   standing proof that sweeping only the notification listener is not enough.
   Nothing here rewrites historical rows, but a wrong edge changes what live
   assignments _can do next_, and the auto-advance writes status on real rows
   going forward. This is the MEDIUM the ask names, and I agree with it.
3. **The board column.** Whether OFF campaigns' boards lose a column (a
   destructive row operation on agency-visible data, with the `BoardResetService`
   resurrection edge in §7.3) or merely stop rendering one. Row-deletion on
   existing boards is the branch that would push this leg above MEDIUM; the ask's
   "existing campaigns migrate to ON" defers it, but the later-flip case is real.

Pre-deploy snapshot is mandatory for any of (B)'s legs carrying a migration,
backfill, or one-shot command (`WORKING-PROCESS.md:159-162`).

---

## 10. Open questions for the kickoffs

Not answered here because the answers are product or architecture calls, not
facts in the repo.

1. **(A) Does "round" get its own vocabulary or reuse "version"?** Two surfaces
   currently say "Version {n}" (creator) and "Draft v{n}" (agency). Unifying them
   is the honest fix and costs 24 locales × both key sets.
2. **(A) Does the creator's history gain the feedback it received?** Today the
   agency sees per-round feedback and the creator does not (§2.3). Adding it needs
   no API change.
3. **(A) The visible counter vs. contract clause 2.4's three free rounds** (§1).
   Product/legal, flagged not resolved; engineering review is not legal review.
4. **(A) Round 1 in the drawer timeline** — `submitted_draft_at` is overwritten
   per submission, so surface 8 can only ever show the latest (§3.1).
5. **(A) The cancelled-mid-cycle open round** — latent today because `cancel()`
   has no caller (§4.2), but it contradicts "feedback closes a round" the moment a
   cancel path ships.
6. **(B) Which of the three auto-advance mechanisms** (§6.2) — and per mechanism,
   which side effects are suppressed vs. expressed-through with truthful copy
   (the AH-043 ruling favours the latter).
7. **(B) The Sprint 10 eligibility question** — will toggle-OFF assignments be
   payment-eligible? Decided implicitly by the terminal chosen (§8.1).
8. **(B) Row-omission vs. render-filter for the Posted column**, and the
   `BoardResetService` resurrection edge (§7.3).
9. **(B) Cards already in Posted** when a live campaign flips OFF — re-home,
   leave, or refuse the flip.
10. **(B) Migration mechanism** — in-migration total flip vs. inverted/nullable
    default vs. an AH-042-style dry-runnable command (§5.4).
11. **(B) Does `posting_due_at` get set on OFF campaigns?** If so the overdue scan
    will flag a posting step that cannot happen (§8.2). I did not trace the
    writer of that column.
12. **(B) The E2E order-of-operations** — the deferred draft → review → posted →
    verified spec does not exist, so there is no posting leg to fork (§8.7).
13. **(B) Creator-side copy branch** — `isVerified` is
    `live_verified || manually_verified` (`CreatorAssignmentDetailPage.vue:176-178`);
    the approved-complete banner needs a third branch or a widened predicate,
    and it must not claim a post was verified.

---

## 11. Limits of this pass

- **Read-only.** No code was run, no test executed, no migration inspected against
  a live schema. Every claim is source-derived at `ea9d686`.
- **Test counts are declaration counts** (`^(it|test)(`), not Pest/Vitest run
  counts; dataset-driven tests expand at runtime (§8.5).
- **Not traced:** `BoardAutomationService::evaluateCondition()`'s grammar (so I
  make no claim about whether the existing `condition` column could express
  "campaign expects posting" — §7.1); the writer of
  `campaign_assignments.posting_due_at` (§8.2, Q11); the full `CampaignStatus`
  vocabulary beyond `LISTABLE_STATUSES`; and the admin SPA, which has no
  assignment-status keys.
- **Sizing is not a plan.** Nothing here proposes sub-steps, and the §9 risk lines
  are inputs to a plan-pause declaration, not a substitute for one.
- **Per `WORKING-PROCESS.md` §7**, this file is authoritative over any chat
  summary of it; where it and a summary disagree, this file wins.

---

_Provenance: drafted by Cursor as the read-only inventory for the Draft Workflow v2
asks (`WORKING-PROCESS.md` §2 Mode A step 1). No edits, no plan, no code — the
prompt's constraint, honoured. Awaiting Claude's kickoff with locked decisions._
