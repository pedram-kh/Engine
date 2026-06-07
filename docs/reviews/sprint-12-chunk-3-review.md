# Sprint 12 — Boards & Automation · Chunk 3 Review — Overdue events + scheduler + reset-to-defaults (backend)

**Status:** Closed.

**Reviewer:** drafted by Cursor (implementation); awaiting independent review + merge.

**Reviewed against:** the Sprint 12 Chunk 3 kickoff (D-1…D-9, the IN/OUT scope, the plan-pause seams + test obligations), the approved plan (Q1 Option A / Q2 extract / Q3 no-status-filter + the two load-bearing break-reverts + the 9 sub-steps), the closed Chunk 1 engine contracts ([review](sprint-12-chunk-1-review.md) — `processEvent`, the dual-trail, `resolveInitialColumnId`, `BoardDefaults`, the SET-NULL movement posture) and Chunk 2 ([review](sprint-12-chunk-2-review.md) — which moved `reset-to-defaults` here), `10-BOARD-AUTOMATION.md` (§2 catalog, §3.2 defaults, §6.1 placement, §8.1 reset, §14.3 column-delete safeguard, §15 time-trigger framing), the `SendMessageDigests` + `MessageDigestService` scheduler/console-tenancy precedent, `07-TESTING.md §3.1` (scheduled-command pattern), `PROJECT-WORKFLOW.md §5` (5.18 Pint/Larastan CI-authority, 5.35 break-revert).

This chunk is **backend-only** and ships the new-backend work neither Ch1 (engine) nor Ch2 (UI) covered: the two time-triggered overdue events, the daily scheduler that fires them, and the destructive `reset-to-defaults`. There is no Chunk 4 — everything remaining ships here.

## ⚠ The two load-bearing specs (built as distinct assertions + break-reverted)

### 1 · Overdue one-shot on the DRAGGED-OUT case (D-4) — kept SEPARATE from steady state

[`OverdueScanTest`](../../apps/api/tests/Feature/Modules/Boards/OverdueScanTest.php) carries TWO distinct one-shot tests:

- **Steady state** — "is a one-shot in steady state — two scans produce exactly one overdue movement." Two scans → exactly one `assignment.posting_overdue` movement. (The engine's already-in-target no-op alone would also satisfy this — which is precisely why it is NOT sufficient on its own.)
- **⚠ Dragged-out** — "does NOT re-fire after the card is dragged OUT of the overdue column (the flagged_at one-shot, not just already-in-target)." Scan fires (card → target, marker stamped) → a human drags the card OUT of the target column → scan again → **no second fire** (movement count stays 1, the card stays where it was dragged). Here already-in-target would NOT save us (the card is no longer in target); only the `posting_overdue_flagged_at` gate does.

**Break-revert (§5.35) — proven, not reasoned.** Temporarily removed `->whereNull($flagColumn)` from the scan query → the dragged-out test failed (`Failed asserting that 2 is identical to 1` — a second fire) while steady-state still passed. Reverted → green. This proves the marker gate (not already-in-target) is what closes the dragged-out hole.

### 2 · Reset atomicity + Option-A ordering prevents the RESTRICT violation (D-7)

[`BoardResetTest`](../../apps/api/tests/Feature/Modules/Boards/BoardResetTest.php):

- **Atomicity** — "rolls back to the ORIGINAL custom board on a mid-transaction failure (atomic, not a half-reset)." A forced failure at the audit-write step (a swapped throwing `Audit` instance — `AuditLogger` is `final`, so swap not mock) → the whole `DB::transaction` rolls back: the original custom columns survive (ids + names unchanged, the custom "Negotiating" column intact), no `board.reset` row written. **Not** a both-old-and-new half-reset.
- **Placement against the FRESH set** — "re-homes every card onto a FRESH default column by current state (none orphaned on an old column)." Each card's `column_id` is asserted to be IN the fresh column-id set AND NOT in the old set, plus the column name matches the state (`Invited→Invited`, `DraftSubmitted→In Review`, `Approved→Approved`). Asserting against the fresh set specifically is what catches the Seam-A bug — a naive happy-path "card has a column" check would pass even when cards land on about-to-be-deleted old columns.

**Break-revert (§5.35) — proven.** Temporarily replaced `DB::transaction(fn …)` with a direct IIFE (no transaction) → the atomicity test failed (the board left half-reset: original columns gone). Reverted → green.

## Decisions (built as approved)

- **D-1 · Three net-new `AuditAction` verbs + tripwire.** [`AuditAction`](../../apps/api/app/Modules/Audit/Enums/AuditAction.php) gains `assignment.posting_overdue`, `assignment.draft_overdue`, `board.reset`; all three added to [`AuditActionEnumTest`](../../apps/api/tests/Feature/Modules/Audit/AuditActionEnumTest.php) `$expected`. All three are **audit-only** — `NotificationTypeEnumTest` is untouched and green (the `board.card_moved_manually` precedent). ⚠ The two overdue verbs write **NO audit row** (they ride `processEvent`, movement-only — the Ch1 dual-trail invariant); they exist as the board event-key vocabulary. Only `board.reset` writes an audit row.
- **D-2 · `draft_due_at`** — net-new nullable column, exact mirror of `posting_due_at` (migration [`2026_06_07_120000`](../../apps/api/database/migrations/2026_06_07_120000_add_overdue_fields_to_campaign_assignments_table.php), model property/fillable/cast, the [`InviteAssignmentRequest`](../../apps/api/app/Modules/Campaigns/Http/Requests/InviteAssignmentRequest.php) `nullable|date` rule, the [controller](../../apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAssignmentController.php) write). On its **own** index `idx_assignments_draft_due_at`. Backend-only; the FE invite-form control is tech-debt. Nullable → `draft_overdue` capable at ship, inert until set (scan skips nulls).
- **D-3 · Direct `processEvent`, not a synthetic `AssignmentTransitioned`.** [`OverdueScanService`](../../apps/api/app/Modules/Boards/Services/OverdueScanService.php) calls `BoardAutomationService::processEvent($id, 'assignment.posting_overdue'|'assignment.draft_overdue', $metadata, triggeredByUserId: null)` directly. No synthetic transition (it has no sane from/to and would mis-fire notifications/system-message/thread-create/card-create).
- **D-4 · `*_overdue_flagged_at` one-shot markers.** Net-new nullable timestamps `posting_overdue_flagged_at` / `draft_overdue_flagged_at`. The scan stamps the marker **before** firing `processEvent` (so the one-shot holds even when `processEvent` no-ops) and gates on `… IS NULL`. Permanent one-shot (no reset-on-un-overdue — tech-debt).
- **D-5 · `boards:scan-overdue` scheduled command, `->daily()`.** [`ScanOverdueAssignments`](../../apps/api/app/Console/Commands/ScanOverdueAssignments.php) (the `SendMessageDigests` shape: `handle(): int`, count-info) registered as the second `->command(...)->daily()` line in the [`bootstrap/app.php`](../../apps/api/bootstrap/app.php) `withSchedule` closure.
- **D-6 · Cross-agency, tenancy-correct sweep.** The scan runs in console (no ambient agency context → `BelongsToAgencyScope` a no-op), queries overdue assignments across ALL agencies via `withoutGlobalScope(BelongsToAgencyScope::class)`, then `processEvent` self-resolves each card's board/agency. The query: `due_at < now() AND flagged_at IS NULL AND due_at IS NOT NULL`. Pinned by the ABSENCE test (below).
- **D-7 · `reset-to-defaults`** — `POST …/board/reset-to-defaults` ([`BoardController::reset`](../../apps/api/app/Modules/Boards/Http/Controllers/BoardController.php) → [`BoardResetService`](../../apps/api/app/Modules/Boards/Services/BoardResetService.php)), agency-scoped, `update`-gated (the column-CRUD precedent). The never-clobber provisioner is not reused — reset has its own destructive path in one `DB::transaction`, **Option-A ordering** (see Q1).
- **D-8 · Movement history survives.** The bulk re-home writes NO movement rows (a reset is ONE operation, not N fake drags); prior movements' `from/to_column_id` SET-NULL on the column delete while the `card_id` CASCADE anchor keeps the row. The one `board.reset` audit row is the trail for the reset itself. Pinned: "preserves movement history across the reset" (the manual-move row survives with both column refs null) + "exactly ONE board.reset audit row and NO per-card movement rows."
- **D-9 · Default automations self-consistent post-reset.** The fresh automations key to the fresh column ids → never dangling. Pinned: "seeds fresh default automations that point at the fresh columns."

## Q-answers honoured

- **Q1 — Option A (re-automate BEFORE re-home).** [`BoardResetService::reset`](../../apps/api/app/Modules/Boards/Services/BoardResetService.php) order, one transaction: (1) seed fresh default columns alongside the custom ones (positions continue past the current max to avoid a transient collision); (2) delete old automations + seed fresh defaults keyed to the **fresh** column ids; (3) re-home each card via the extracted placement method resolving against the **fresh** columns+automations only (bulk `column_id` update grouped by target — no movements); (4) delete the now-empty old columns (RESTRICT satisfied) + renumber the fresh set to a clean 1..N; (5) one `board.reset` audit row. Rejected Option B (a second state→column map) — one parameterized placement path, no dual source of truth.
- **Q2 — extract `resolveColumnForState(Collection $columns, Collection $automations, AssignmentStatus $status): int`.** Added additively to [`BoardCardService`](../../apps/api/app/Modules/Boards/Services/BoardCardService.php); `resolveInitialColumnId` now delegates to it with the board's live `columns()`/`automations()`. The reset passes the freshly-built collections instead — what lets it resolve against the fresh set mid-transaction. ⚠ **Byte-identical verification:** the public surface `forAssignment` is untouched (it still calls the private `resolveInitialColumnId($board, $status)`); the extraction only moved the body behind a delegator. All **43 pre-existing board tests pass unchanged** (provisioning, lazy-heal, create-card, automation-service, manual-move) — the Ch1 placement behavior is preserved. (The extracted method matches collection-side: `event_key`/`is_enabled`/`action_type === MoveToColumn`/non-null target, then `columns->contains('id', …)`, fallback first-by-position; the `(board_id, event_key)` UNIQUE means at most one automation per key, so `->first()`-on-query and `->first()`-on-collection agree.)
- **Q3 — no status filter; honor the exact query.** `due_at < now() AND flagged_at IS NULL AND due_at IS NOT NULL`, no terminal-status gate. ⚠ **Recorded as-built (not a bug):** a terminal assignment with a stale deadline + no mapped overdue automation gets **flagged-once-and-does-nothing** — the scan fires the event (one-shot), `processEvent` no-ops for want of a mapped automation, the marker is stamped, it never fires again. The marker is the recorded answer to "why flagged but nothing moved." Logged in `tech-debt.md`.

## Test obligations → evidence

| Obligation                                                             | Evidence (`OverdueScanTest` / `BoardResetTest` / `CampaignAssignmentInviteTest`)                                                                                                      |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Scan fires overdue for a mapped automation (card moves + movement row) | "fires posting_overdue …" — card → target, a `triggered_by: event`, `triggered_by_user_id: null` movement keyed `assignment.posting_overdue`, marker stamped                          |
| `schedule:list` contains `boards:scan-overdue` (`->daily()`)           | "registers boards:scan-overdue as a daily scheduled command" (`expectsOutputToContain`)                                                                                               |
| ⚠ Cross-agency ABSENCE (MessageDigestTest mirror)                      | "does NOT let agency A's overdue automation fire on agency B's card" — A's card moves (A's automation), B's card UNCHANGED + zero movements; both flagged (B's `processEvent` no-ops) |
| ⚠ One-shot steady state (scan twice → one movement)                    | "is a one-shot in steady state …"                                                                                                                                                     |
| ⚠ One-shot dragged-out (fire → drag out → no second fire)              | "does NOT re-fire after the card is dragged OUT …" (break-reverted)                                                                                                                   |
| `draft_overdue` fires when `draft_due_at` set + passed                 | "fires draft_overdue when draft_due_at is set and passed" (net-new field end-to-end)                                                                                                  |
| `draft_overdue` skips NULL                                             | "skips draft_overdue when draft_due_at is NULL"                                                                                                                                       |
| `posting_overdue` skips NULL                                           | "skips posting_overdue when posting_due_at is NULL"                                                                                                                                   |
| ⚠ Reset destructive: 7 defaults restored, custom gone                  | "restores the 7 default columns, dropping the custom set"                                                                                                                             |
| ⚠ Reset: every card on a FRESH default column by state                 | "re-homes every card onto a FRESH default column …" (asserts against the fresh set, not just any column)                                                                              |
| ⚠ Reset: automations point at fresh columns (not dangling)             | "seeds fresh default automations that point at the fresh columns"                                                                                                                     |
| ⚠ Reset: ONE `board.reset` row, NO per-card movements                  | "writes exactly ONE board.reset audit row and NO per-card movement rows"                                                                                                              |
| ⚠ Reset: movement history survives (SET-NULL)                          | "preserves movement history across the reset" (row survives, both column refs null)                                                                                                   |
| ⚠ Reset: atomic (mid-transaction failure rolls back)                   | "rolls back to the ORIGINAL custom board …" (break-reverted)                                                                                                                          |
| Reset endpoint: 200 / staff 403 / cross-agency 404                     | "lets an admin reset …", "forbids STAFF …", "404s when agency B resets agency A's board"                                                                                              |
| `draft_due_at` set-path on invite (+ NULL when unset)                  | `CampaignAssignmentInviteTest`: "persists draft_due_at + posting_due_at on invite", "leaves draft_due_at NULL when not supplied"                                                      |
| Three enum-add tripwires; `NotificationTypeEnumTest` unchanged         | `AuditActionEnumTest` (+3) green; `NotificationTypeEnumTest` green/untouched                                                                                                          |

## Verification

- **New tests:** `OverdueScanTest` (8) + `BoardResetTest` (9) + `CampaignAssignmentInviteTest` (+2 draft-deadline) = 19 net-new, all green.
- **Affected-suite sweep green (354 tests):** `tests/Feature/Modules/{Boards,Campaigns,Audit,Notifications,Messaging}` — including all 43 pre-existing board tests (the Q2 refactor is behavior-preserving) and the messaging scheduled-command/console-tenancy precedent.
- **Both load-bearing break-reverts performed and reverted clean** (dragged-out gate; reset atomicity).
- **Pint clean** + **Larastan clean** (the CI-authoritative gate, §5.18) on the touched modules/files.
- Migration runs cleanly on the fresh-migrate test DB (the schema smoke test).

## Out of scope (per the plan)

- **Tech-debt:** the FE invite-form control for `draft_due_at` (D-2); the overdue-flag reset-on-un-overdue refinement (D-4). The Ch1 overdue deferral + the Ch2 reset deferral are marked **closed** by this chunk.
- **Out entirely:** any FE; reopening the Ch1/Ch2 surfaces; intra-column ordering (P2, `position` inert).

## Files

**New:** `app/Modules/Boards/Services/OverdueScanService.php`, `app/Modules/Boards/Services/BoardResetService.php`, `app/Console/Commands/ScanOverdueAssignments.php`, `database/migrations/2026_06_07_120000_add_overdue_fields_to_campaign_assignments_table.php`, `tests/Feature/Modules/Boards/OverdueScanTest.php`, `tests/Feature/Modules/Boards/BoardResetTest.php`.
**Edited (app):** `app/Modules/Audit/Enums/AuditAction.php`, `app/Modules/Campaigns/Models/CampaignAssignment.php`, `app/Modules/Campaigns/Http/Requests/InviteAssignmentRequest.php`, `app/Modules/Campaigns/Http/Controllers/CampaignAssignmentController.php`, `app/Modules/Boards/Services/BoardCardService.php` (Q2 extract), `app/Modules/Boards/Http/Controllers/BoardController.php`, `app/Modules/Boards/Routes/api.php`, `bootstrap/app.php`.
**Edited (tests):** `tests/Feature/Modules/Audit/AuditActionEnumTest.php`, `tests/Feature/Modules/Campaigns/CampaignAssignmentInviteTest.php`.
**Edited (docs):** `docs/03-DATA-MODEL.md`, `docs/10-BOARD-AUTOMATION.md`, `docs/security/tenancy.md`, `docs/07-TESTING.md`, `docs/tech-debt.md`.

> **Note for the reviewer:** the docs + this review file are intentionally **uncommitted** — they route to chat for the independent review + merge.

---

## Independent review — verdict (appended to Cursor's draft)

**Status:** Closed. Spot-check passed (no PMC). Sprint 12 Chunk 3 complete — and with it, **Sprint 12 — Boards & Automation is done** (Ch1 engine → Ch2 Kanban UI → Ch3 overdue/scheduler/reset).

**Reviewed against:** the Ch3 kickoff (D-1…D-9 + Q1 Option A / Q2 extract / Q3 no-status-filter), the closed Ch1/Ch2 contracts, the `SendMessageDigests`/`MessageDigestService` scheduler+console-tenancy precedent, `PROJECT-WORKFLOW.md §5` (5.35 break-revert).

### Spot-check anchors → evidence (verified against test bodies)

| Anchor                                          | Evidence                                                                                                                                                                                                                                                                                           |
| ----------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Dragged-out one-shot distinct from steady-state | Card moved OUT to `Invited` before 2nd scan; movement count stays 1 AND `column_id === invited` (card not in target → already-in-target can't be the gate; `flagged_at` is). Break-revert: removing `whereNull($flagColumn)` failed THIS test (2 fires), steady-state passed.                      |
| Reset atomicity, failure mid-transaction        | `Audit::log` is step (5) — fires only after columns/automations/cards/old-column-delete (1–4) inside the txn; throwing `Mockery` swap (AuditLogger is `final`) → original ids+names+`Negotiating` survive, zero `board.reset` rows. Break-revert: removing `DB::transaction` → half-reset failure. |
| Re-home placement IN-fresh AND NOT-in-old       | `toContain(fresh) AND not->toContain(old)` + state→name (`Invited`/`In Review`/`Approved`); disjoint fresh/old id sets → the not-in-old half catches a Seam-A landing on an about-to-be-deleted column.                                                                                            |
| Cross-agency ABSENCE: B untouched AND swept     | A's card → `targetA`; B's card → `originalColumn` + 0 movements (absence); `B.posting_overdue_flagged_at` not null (sweep found B, B's `processEvent` no-op'd for want of a mapped automation — distinguishes "swept but config did nothing" from "never looked at B").                            |

### Decisions confirmed

D-1 three audit-only verbs (only `board.reset` writes a row; the two overdue verbs are board event-key vocabulary, movement-only via `processEvent`); `NotificationTypeEnumTest` untouched. D-2 `draft_due_at` nullable, own index, invite set-path (FE control → tech-debt). D-3 direct `processEvent` (no synthetic transition). D-4 `*_overdue_flagged_at` markers stamped before `processEvent` (one-shot holds even when `processEvent` no-ops); permanent one-shot (reset-on-un-overdue → tech-debt). D-5 `boards:scan-overdue` `->daily()` (2nd `withSchedule` consumer). D-6 cross-agency global sweep + per-card self-resolution. D-7 Option-A reset ordering (seed columns → swap automations → re-home against fresh set → delete old → one audit row), one transaction. D-8 movement history survives (SET-NULL + CASCADE anchor); bulk re-home writes no movement rows. D-9 fresh automations point at fresh columns.

### Q-answers ratified

- **Q1 Option A** — my literal D-7 step order had the Seam-A bug (re-home before re-automate would land cards on about-to-be-deleted columns); Cursor caught it at plan-time. Option A's re-automate-before-re-home is the correct mechanism for D-7's intent; the kickoff order was the error.
- **Q2 extract `resolveColumnForState`** — accepted; byte-identical via 43 prior board tests green + `forAssignment` untouched (delegates to the private method). The right no-behavior-change refactor.
- **Q3 no status filter** — the marker bounds a stale-deadline-on-terminal-assignment to flagged-once-and-does-nothing; recorded as-built (the answer to "why flagged but nothing moved"), not a bug.

### Verification

19 net-new tests; 354-test affected sweep green (incl. all 43 pre-existing board tests — Q2 behavior-preserving); both load-bearing break-reverts performed + reverted clean; Pint + Larastan clean (§5.18); migration runs clean on fresh-migrate.

### Sprint 12 close

Boards & Automation complete: per-campaign boards (lazy-provisioned + card-healed), the `AssignmentEventContract`-driven automation engine (§5.38), the manual-move visualization-layer invariant (state-machine-isolated, proven in both the manual move and the column-delete re-home), the Kanban UI (optimistic/revert + skip-while-pending poll), and the time-triggered overdue vertical + destructive reset. Open under tech-debt: the `draft_due_at` FE invite control, the overdue-flag reset-on-un-overdue refinement, the richer card face (avatar/platform/unread), intra-column ordering (P2), and the inert payment automations (Sprint-10-gated).

---

_Provenance: drafted by Cursor (Sprint 12 Ch3 build); verdict appended + spot-checked by Claude (dragged-out one-shot / mid-transaction atomicity / in-fresh-AND-not-in-old placement / cross-agency-absence-AND-swept all verified at test-body level; both break-reverts re-run live; Q2 byte-identical accepted from the 43-prior-tests backstop). Q1 Seam-A reorder ratified (my kickoff step order was the error). No PMC._
