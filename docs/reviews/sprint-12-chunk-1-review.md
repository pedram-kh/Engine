# Sprint 12 — Boards & Automation · Chunk 1 Review — Board engine (backend)

**Status:** Closed.

**Reviewer:** drafted by Cursor (implementation); independently reviewed + spot-checked + accepted.

**Reviewed against:** the Sprint 12 Chunk 1 kickoff (D-1…D-12, the IN/OUT scope, the five plan-pause seams, the test obligations), the approved plan (Q1/Q2/Q3 resolutions + 8 sub-steps), `03-DATA-MODEL.md §10` (the column authority), `10-BOARD-AUTOMATION.md` (§1 model, §2 catalogue, §3 defaults, §4 card, §5 engine, §7 columns, §13 movements, §14 edge cases), `security/tenancy.md`, `PROJECT-WORKFLOW.md §5` (5.1 source-inspection, 5.2 Event::fake split, 5.5 transactional audit, 5.6 idempotency, 5.11 handoff contracts), `07-TESTING.md`, `01-UI-UX.md` + `packages/design-tokens` (the status palette).

This chunk ships the board **engine** only — the five tables, lazy provisioning + card-heal, the two `AssignmentTransitioned` consumers, the manual-move dual-trail, and the board/columns/automations/move/movements API + resources. The SPA Kanban is Chunk 2; the time-triggered overdue events are Chunk 3.

## Cross-chunk handoff contracts verified

- **`AssignmentEventContract` (as-built):** `assignment()` / `eventKey()` / `metadata()` / `triggeredByUserId()` — the four members the §5.2 listener needs. `BoardAutomationListener` binds to the contract and `BoardAutomationService::processEvent(assignmentId, eventKey, metadata, triggeredByUserId)` maps 1:1 (D-6). No dedicated per-event classes — the §2.1/§5.2 sketch is superseded.
- **`AuditAction` catalogue coverage (Seam 4):** every default-automation `event_key` is a live `AuditAction` value — pinned by a test (`BoardProvisioningServiceTest` "catalog-to-code"). The 9 defaults bind to `assignment.{invited,draft_submitted,draft_approved,posted_by_creator,live_verified,manually_verified,resubmit_requested,payment_released,cancelled}`.
- **§10 column lists:** the five tables migrated per §10, with the D-2 tenancy denormalization applied additively (see below) and one nullability reconciliation (`to_column_id`). Doc updated.

## Plan-pause seam findings

1. **Status-color tokens (Seam 1):** all 7 `status-*` tokens (`status-todefine/progress/review/aligned/posted/paid/blocked`) map 1:1 to the `boardStatus` palette in `packages/design-tokens`. `color_token` stores the **prefixed** `status-*` spelling (Q2); the create/update requests validate against the palette SoT (`BoardDefaults::colorTokens()`), so a non-palette token is a 422 (no broken-token seed).
2. **Listener order (Seam 2 / D-7):** `CreateBoardCard` registers as the **6th** `Event::listen(AssignmentTransitioned)`, `BoardAutomationListener` as the **7th** — card-first. AND the automation is a **no-op on a missing card** (belt + suspenders) — proven independently of order by `BoardAutomationServiceTest` "no-op when no card yet".
3. **Enum-add ripple (Seam 3 / D-9):** `board.card_moved_manually` added to `AuditAction` + the tripwire `$expected` list (`AuditActionEnumTest`). It is **audit-only** — NOT a `NotificationType`, so it does NOT join the one-vocabulary tie (`NotificationTypeEnumTest` still green, unchanged). `reason` is optional (not `requiresReason()`).
4. **Two-trail consistency (Seam 5 / D-9):** a manual move writes **exactly one** `audit_logs` row + **one** `board_card_movements` row (`triggered_by=user`); an automation move writes **only** the movement row (`triggered_by=event`). Pinned by `BoardManualMoveTest` + `BoardAutomationServiceTest`.

## Decisions (built as proposed)

- **D-1 · Five tables** per §10 (`2026_06_06_120000`–`120004`) + `Board/BoardColumn/BoardAutomation/BoardCard/BoardCardMovement` models + factories + `BoardAutomationActionType` / `MovementTrigger` enums. `board_cards.position` present-but-inert; `board_automations.action_type` honored by the listener.
- **D-2 · Tenancy** — `boards`, `board_columns`, `board_cards` use `BelongsToAgency` + denormalized `agency_id` (RESTRICT, mirroring `message_threads`); `board_automations` + `board_card_movements` scope through `board_id`/`card_id`. **Additive to the §10 column lists** (which defer denormalization to D-2) — documented in `03-DATA-MODEL.md §10`.
- **D-3 · `BoardProvisioningService`** seeds the §3 defaults (7 columns + 9 automations) from the single SoT `BoardDefaults`. Idempotent: columns seed only when absent (never clobbers agency edits); automations `firstOrCreate` on `(board_id, event_key)`.
- **D-4 · Lazy board-GET heal, NO backfill** — `BoardService::forCampaign` `firstOrCreate`s the board (`withoutGlobalScope` + explicit `agency_id`, the `MessageThreadService` pattern) + provisions defaults on first creation + heals a card for every card-less assignment. No `CampaignCreated` event introduced.
- **D-5 · `CreateBoardCard`** clones `CreateMessageThread` — gates on `action === AuditAction::AssignmentInvited`, `firstOrCreate` on the `assignment_id` UNIQUE via `BoardCardService::forAssignment`. Card placement = the invited-automation target (or the state's representative column on a heal, §6.1), falling back to the first column.
- **D-6 · `BoardAutomationListener` + `BoardAutomationService`** against the contract, switch on `eventKey()` (see handoff above).
- **D-7 · Idempotent + safe no-ops** (§14.2): no-ops on missing board / absent-disabled-non-move automation / failed condition / missing card / already-in-target. Reads `assignment.campaign.board` independently; writes only `board_cards` + `board_card_movements`.
- **D-8 · Manual-move ≠ business-logic** — `BoardCardMoveService` has **structurally no** reference to the assignment state machine (source-inspection test, 5.1). A manual move to "Paid" does NOT release payment.
- **D-9 · `board.card_moved_manually`** dual-trail (see Seam 5).
- **D-10 · The API** — board GET (lazy-heal), columns CRUD + reorder, automations list + update, manual move + movements list (§11). Backend only.
- **D-11 · Payment-verb automation inert** — wired, no-ops until Sprint 10 (escrow gated). Logged in tech-debt.
- **D-12 · Time-triggered overdue OUT** — Chunk 3. Logged in tech-debt.

## Resolved questions (from the plan-pause)

- **Q1 · `triggered_by` value:** `event` (automation) + `user` (manual) — §10/§5.2/§13 win over D-9's "automation" wording (the §10 column authority). The `MovementTrigger` enum documents the reconciliation.
- **Q2 · `color_token` storage:** the prefixed `status-todefine` spelling.
- **Q3 · Column lifecycle:** DELETE + re-home safeguard + reorder ship **now**; `reset-to-defaults` deferred to Chunk 2.

## Column-delete safeguard (Q3 / §14.3)

- Min-1-column guard (422 `board.column.last_column`); non-empty-requires-destination (422 `board.column.destination_required`); a missing/self/cross-board destination is a 404 (absence). Re-home runs each card through `BoardCardMoveService` (manual movements, both trails) THEN deletes. `board_cards.column_id` is RESTRICT — the DB-level backstop. The `board_card_movements` column FKs are SET NULL so history survives the delete (the `to_column_id` nullability reconciliation).

## Spot-check anchors → evidence

| Anchor                                                   | Evidence                                                                                                                                                                                                                                                                                                  |
| -------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Manual move = NO side effect (the load-bearing test)** | `BoardManualMoveTest` "critical safety invariant": move Invited→Paid, then assignment status UNCHANGED + `Event::assertNotDispatched(AssignmentTransitioned)` + no payment audit verbs. Plus the 5.1 source-inspection test across `BoardCardMoveService` / `BoardCardController` / `BoardColumnService`. |
| Tenancy = ABSENCE (404, not 403)                         | `BoardManualMoveTest`: agency B GETting / moving on A's board → 404. `BoardColumn`/`BoardCard` scoped → filtered at binding; automations/movements guarded by explicit parent-of-board checks.                                                                                                            |
| Provisioning idempotent                                  | `BoardProvisioningServiceTest` + `BoardLazyHealTest`: 2nd provision / 2nd GET creates no new rows; rename-then-reprovision never clobbers.                                                                                                                                                                |
| Card create (Event::fake split, 5.2)                     | `CreateBoardCardTest`: real listener fires a card on invite; no card on non-invite; `forAssignment` idempotent (one canonical row on the UNIQUE).                                                                                                                                                         |
| Automation fires + idempotent + no-card no-op            | `BoardAutomationServiceTest`: draft_approved moves the card + records `triggered_by=event`; 2nd fire no-ops (already in target); no-op when no card / no board / disabled.                                                                                                                                |
| Two trails                                               | manual = 1 audit + 1 movement (`user`); automation = movement only (`event`).                                                                                                                                                                                                                             |

## Verification

43 new board tests green (207 assertions). Affected-suite sweep green: Campaigns + Audit + Notifications + Messaging + Boards (334 passed in one run); `AuditActionEnumTest` + `NotificationTypeEnumTest` green (the enum-add ripple). Pint clean (`--test` passed; note: Pint's `fully_qualified_strict_types` initially auto-imported a class named only in a doc-block `{@see}` — the 5.1 isolation test caught it; reworded to plain prose). Larastan clean on the Boards module + touched files.

> **Note for the reviewer:** the full-suite `pest` run hits the local PHP `memory_limit` (128M) — an environment limit, not a failure; targeted suites run clean with `-d memory_limit=1G`. Worth a CI memory-limit check.

## Out of scope

- **Chunk 2 (SPA):** BoardView, DnD, `useBoardStore`, the 30s poll, column-CRUD UI, automation-config UI, the assignment drawer, movement-history UI, **reset-to-defaults**.
- **Chunk 3:** time-triggered overdue events + scheduler + draft-deadline field + the 2 net-new audit verbs.
- **Out entirely:** intra-column ordering (P2 — `position` inert); payment (Sprint 10 — the payment automation no-ops); `client_approved` (P2 catalogue).

## Docs (ride this merge)

- `03-DATA-MODEL.md §10` — five tables flipped to ✅ Built (Sprint 12); D-2 `agency_id` additions + the inert `position` + the `to_column_id` nullable reconciliation + the inert payment/overdue automations noted.
- `security/tenancy.md §4` — board surface documented as **standard agency-scoped** (NOT allowlisted), with the 404-absence guard + the named `withoutGlobalScope` provisioning bypass rationale.
- `tech-debt.md` — new Sprint 12 Chunk 1 entry: intra-column ordering (P2), inert payment automation (S10-gated), time-triggered overdue (Ch3), the `to_column_id` reconciliation.
- `PROJECT-WORKFLOW.md §5.38` — the "listen against a shared event contract keyed by `eventKey()`" pattern (D-6). **⚠ Flagged for reviewer ratification** — it generalises beyond boards.

## Open items tracked (not blocking)

- `evaluateCondition` is a P1 stub (returns true for empty/absent condition — no default seeds one; the §5.3 enumerated condition set is future work).
- Board listeners live under the `Boards` module but register in `CampaignsServiceProvider` (the existing `AssignmentTransitioned` subscription home) — deliberate, to keep one registration site.

---

_Provenance: drafted by Cursor (Sprint 12 Chunk 1 build); routes to chat for the independent review + merge; docs ride that merge._

---

## Independent review — verdict (appended to Cursor's draft)

**Status:** Closed. Spot-check passed (no PMC). Sprint 12 Chunk 1 (board engine, backend) complete.

**Reviewed against:** the Ch1 kickoff (D-1…D-12 + Q1/Q2/Q3), `03-DATA-MODEL.md §10` (column authority), `10-BOARD-AUTOMATION.md` (§4.4/§5/§14 — the visualization-layer invariant), `PROJECT-WORKFLOW.md §5` (5.1 source-inspection, 5.2 Event::fake split, 5.35 break-revert).

### Spot-check anchors → evidence (verified against code/tests)

| Anchor                                         | Evidence                                                                                                                                                                                                                   |
| ---------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Manual move = NO side effect (3-way absence)   | `BoardManualMoveTest`: status `=== Invited`, `assertNotDispatched(AssignmentTransitioned)`, no payment audit verb — card moved at the viz layer, reality untouched.                                                        |
| Isolation test has teeth                       | Injected `app(...StateMachine)` → test FAILED; reverted → passed. Strips doc-comments via `token_get_all`, so it inspects executable code (a real call bites; prose doesn't false-trip).                                   |
| Column-delete re-homes through the manual path | `BoardColumnService::delete` routes each card through `BoardCardMoveService::move` (movements + audit, no state machine); 404 on missing/self/cross-board destination, 422 on last-column + non-empty-without-destination. |
| Automation no-ops                              | No-card-yet writes nothing, no throw (proven via direct service call, order-independent — D-7); already-in-target second fire → exactly 1 movement row.                                                                    |
| `to_column_id` nullable null-safe              | Migration `nullable()` + `nullOnDelete()`; Resource `?->ulid`; grep confirms all other sites are writes/relations/eager-load — a deleted target column serializes `null` cleanly.                                          |

### Fix-now applied during spot-check

The column-delete re-home test asserted the movement trail but not the **status-untouched** invariant the review claimed. Strengthened before commit: seeded a non-default `Producing` status (so the assertion is meaningful) and asserted it survives the delete. The visualization-layer invariant is now genuinely proven in BOTH places it lives — the direct manual move AND the column-delete re-home.

### Ratifications

- **`to_column_id` nullable + SET NULL (vs §10's non-null draft) — RATIFIED.** History must survive a column delete; RESTRICT/non-null would block the delete or destroy the trail. Surfaced explicitly, documented in §10 + tech-debt, read side proven null-safe. A spec-drift correction toward what the feature requires (same posture as messaging's nullable sender).
- **`PROJECT-WORKFLOW.md §5.38` (listen against a shared event contract keyed by `eventKey()`) — RATIFIED.** It's now the backbone of notifications, messaging system-messages, AND board automation — three consumers off the one `AssignmentTransitioned` via `AssignmentEventContract`. The pattern generalizes; codifying it is correct.

### Decisions confirmed (built as specified)

D-1 five tables per §10; D-2 `BelongsToAgency` + denormalized `agency_id` on board/columns/cards, transitive scope for automations/movements; D-3 idempotent `BoardProvisioningService` (never clobbers agency edits); D-4 lazy board-GET heal, no backfill, no `CampaignCreated` event; D-5 `CreateBoardCard` clones `CreateMessageThread`; D-6 listener bound to `AssignmentEventContract`, switch on `eventKey()`; D-7 idempotent safe no-ops; D-8 manual-move structurally isolated from the state machine; D-9 dual-trail (manual = audit + movement `user`; automation = movement only `event`); D-10 the API; D-11 payment-verb automation inert (S10-gated); D-12 time-triggered overdue → Ch3. Q1 `triggered_by` = `event`/`user` (§10 authority); Q2 `color_token` = prefixed `status-*`; Q3 DELETE+safeguard+reorder now, reset-to-defaults → Ch2.

### Verification

43 board tests / 207 assertions green; affected-suite sweep (Campaigns + Audit + Notifications + Messaging + Boards) green; `AuditActionEnumTest` + `NotificationTypeEnumTest` green (enum-add ripple, audit-only — no NotificationType tie). Pint + Larastan clean. (Full `pest` run hits the local 128M `memory_limit` — environment, not failure; logged in tech-debt for a CI ≥1G check.)

---

_Provenance: drafted by Cursor (Sprint 12 Ch1 build); verdict appended + spot-checked by Claude (3-way manual-move absence / isolation-test-bit / column-delete-re-home-through-manual-path / automation no-ops / nullable-`to_column_id` null-safe all verified at anchor; the re-home status-untouched test strengthened fix-now before commit). `to_column_id` divergence + §5.38 ratified. No PMC._
