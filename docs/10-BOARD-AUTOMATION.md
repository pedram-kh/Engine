# 10 — Board & Automation Engine

> **Status: Always active reference. Defines the campaign board, dynamic columns, and smart automation system. This is one of the platform's signature features — get it right.**

The board is where agencies live. Each campaign has its own Kanban-style board with cards representing creator assignments. Columns are user-defined per campaign, but the system provides a fixed catalog of events that can automatically move cards between columns. This document defines exactly how that works.

---

## 1. The model

### 1.1 Hierarchy

```
Campaign  →  Board  (1:1)
             ├── Columns  (user-defined, ordered)
             ├── Automations  (event → column mappings)
             └── Cards  (one per CampaignAssignment)
```

### 1.2 What's user-configurable

- **Column names** (free text, max 64 chars)
- **Column colors** (chosen from the design system status palette)
- **Column order** (drag and drop)
- **Adding and removing columns** (with safeguards for non-empty columns)
- **Marking columns as terminal** (success or failure terminal)
- **Mapping events to columns** (which event moves cards to which column)
- **Enabling/disabling individual automations**

### 1.3 What's NOT user-configurable in Phase 1

- The catalog of events. Phase 1 ships with a fixed set defined below.
- Custom rules (if-then logic beyond simple event-to-column).
- Cross-board automations.
- Time-based triggers as standalone rules (some events have time semantics built in).

These limitations are intentional. They keep Phase 1 shippable while supporting 95% of real workflows. Phase 3 may introduce a generic rule builder.

---

## 2. The event catalog (Phase 1)

These are the events the system emits that can drive card movement. Each event corresponds to a meaningful state change in a CampaignAssignment.

| Event key                       | Emitted when                                                      | Phase |
| ------------------------------- | ----------------------------------------------------------------- | ----- |
| `assignment.invited`            | Agency invites a creator to a campaign                            | P1    |
| `assignment.declined`           | Creator declines the invitation                                   | P1    |
| `assignment.countered`          | Creator submits a counter-offer                                   | P1    |
| `assignment.accepted`           | Creator accepts the invitation                                    | P1    |
| `assignment.contracted`         | All required contracts are signed                                 | P1    |
| `assignment.draft_submitted`    | Creator submits a draft for review                                | P1    |
| `assignment.draft_approved`     | Agency approves the draft                                         | P1    |
| `assignment.draft_rejected`     | Agency rejects the draft (terminal — moves to a "stalled" column) | P1    |
| `assignment.revision_requested` | Agency requests changes; back to producing                        | P1    |
| `assignment.client_approved`    | Brand client approves draft (P2; designed but unused in P1)       | P2    |
| `assignment.posted_by_creator`  | Creator marks content as posted                                   | P1    |
| `assignment.live_verified`      | System confirms post is live via social API                       | P1    |
| `assignment.payment_funded`     | Brand/agency funds escrow                                         | P1    |
| `assignment.payment_released`   | Funds released to creator                                         | P1    |
| `assignment.cancelled`          | Assignment is cancelled at any stage                              | P1    |
| `assignment.posting_overdue`    | Posting deadline passed without action (time-triggered)           | P1    |
| `assignment.draft_overdue`      | Draft deadline passed without submission (time-triggered)         | P1    |

Each event carries a payload with at minimum:

```
{
  assignment_id, campaign_id, brand_id, agency_id,
  occurred_at, triggered_by_user_id (or null for system),
  metadata (event-specific)
}
```

### 2.1 Event definitions

Events are defined as PHP classes in `app/Modules/Campaigns/Events/`:

```php
final class AssignmentDraftSubmitted
{
    public function __construct(
        public readonly CampaignAssignment $assignment,
        public readonly CampaignDraft $draft,
        public readonly Carbon $occurredAt
    ) {}

    public function eventKey(): string
    {
        return 'assignment.draft_submitted';
    }
}
```

Events are dispatched via Laravel's event system from the relevant service:

```php
// In CampaignDraftService::submit()
event(new AssignmentDraftSubmitted($assignment, $draft, now()));
```

### 2.2 Adding events in later phases

Future phases add events for new features (marketplace applications, AI QC outcomes, etc.). The event catalog grows; existing automations referencing prior events keep working.

---

## 3. Default board template

When a campaign is created, its board is provisioned with default columns and automations. The defaults are sensible and immediately usable; agencies can edit them per campaign.

### 3.1 Default columns

| Position | Name      | Color (status palette)          | Terminal?        |
| -------- | --------- | ------------------------------- | ---------------- |
| 1        | To Define | `status-todefine` (gray dashed) | No               |
| 2        | Invited   | `status-progress` (violet)      | No               |
| 3        | In Review | `status-review` (amber)         | No               |
| 4        | Approved  | `status-aligned` (teal)         | No               |
| 5        | Posted    | `status-posted` (cyan)          | No               |
| 6        | Paid      | `status-paid` (green)           | Terminal success |
| 7        | Cancelled | `status-blocked` (red)          | Terminal failure |

### 3.2 Default automations

| Event                          | Target column                   |
| ------------------------------ | ------------------------------- |
| `assignment.invited`           | Invited                         |
| `assignment.draft_submitted`   | In Review                       |
| `assignment.draft_approved`    | Approved                        |
| `assignment.posted_by_creator` | Posted                          |
| `assignment.live_verified`     | Posted (no-op if already there) |
| `assignment.payment_released`  | Paid                            |
| `assignment.cancelled`         | Cancelled                       |

### 3.3 Why these defaults

- Most campaigns benefit from a simple, linear flow.
- Agencies can immediately drag and drop manually if they don't want auto-movement.
- Edge cases (declined, countered, revision_requested) keep cards in their current column unless explicitly mapped — agency staff handles them via filters or by reading the card status.

---

## 4. The card

### 4.1 What a card represents

A card is a CampaignAssignment. One card per (campaign, creator) pair. Cards exist for the lifetime of the assignment.

### 4.2 What's on the card

- **Top:** creator avatar (24x24 round) + creator handle in caption text
- **Middle:** assignment summary in body-sm — typically "1 Reel + 3 Stories" derived from the brief
- **Bottom row (icons):**
  - Platform icon (IG/TikTok/YT)
  - Days remaining indicator (red if overdue)
  - Unread message count if any
  - Status badge for the current assignment status
- **Hover:** subtle shadow elevation, drag handle visible
- **Click:** opens the assignment side drawer (480px right drawer)

See `01-UI-UX.md` § 5 for the full visual spec.

### 4.3 What clicking opens

The right-side drawer shows:

- Creator info (with link to full creator profile)
- Assignment status and dates
- Brief summary (deliverables, posting window)
- Latest draft (if any) with preview and approval actions
- Posted content link (if posted)
- Payment status
- Chat thread (link to expand)
- Audit history of this assignment

All actions on the assignment happen in this drawer without leaving the board.

### 4.4 Card behavior on card click

- Click on the body of the card → opens drawer
- Drag the card → moves it (manually) to another column

Manual moves are allowed but bypass automation. They emit a `board.card_moved_manually` event for audit and don't trigger downstream side effects (a card moved to "Paid" manually does NOT release the actual payment).

This is critical: **board state is a visualization layer.** It reflects reality but doesn't drive it. Moving a card to "Approved" doesn't approve the draft. The agency takes the action (approve draft via the drawer), the system emits the event, the automation moves the card.

---

## 5. The automation engine

### 5.1 How it works

1. A service action occurs (e.g., `CampaignDraftService::approve()`).
2. The service emits an event: `event(new AssignmentDraftApproved(...))`.
3. The `BoardAutomationListener` listens for assignment events.
4. The listener finds the board for the campaign, looks up the automation for the event key, evaluates any condition.
5. If the automation is enabled and the condition passes, the listener creates a `BoardCardMovement` record and updates the card's `column_id`.
6. The change is broadcast (Phase 2: WebSocket; Phase 1: client polls or refetches on action).

### 5.2 The listener implementation (sketch)

```php
final class BoardAutomationListener
{
    public function __construct(
        private readonly BoardAutomationService $service
    ) {}

    public function handle(object $event): void
    {
        if (! $event instanceof AssignmentEventContract) {
            return;
        }

        $this->service->processEvent(
            assignmentId: $event->assignment->id,
            eventKey: $event->eventKey(),
            metadata: $event->metadata(),
            triggeredByUserId: $event->triggeredByUserId(),
        );
    }
}
```

The listener subscribes to all events implementing `AssignmentEventContract`. The `BoardAutomationService` does the work:

```php
final class BoardAutomationService
{
    public function processEvent(
        int $assignmentId,
        string $eventKey,
        array $metadata,
        ?int $triggeredByUserId
    ): void {
        $assignment = CampaignAssignment::with('campaign.board')->find($assignmentId);
        if (! $assignment || ! $assignment->campaign->board) {
            return;
        }

        $automation = $assignment->campaign->board
            ->automations()
            ->where('event_key', $eventKey)
            ->where('is_enabled', true)
            ->first();

        if (! $automation || $automation->action_type !== 'move_to_column') {
            return;
        }

        if (! $this->evaluateCondition($automation->condition, $assignment)) {
            return;
        }

        $card = $assignment->boardCard;
        if (! $card || $card->column_id === $automation->target_column_id) {
            return; // already in target column
        }

        DB::transaction(function () use ($card, $automation, $eventKey, $triggeredByUserId) {
            $fromColumnId = $card->column_id;
            $card->update(['column_id' => $automation->target_column_id]);

            BoardCardMovement::create([
                'card_id' => $card->id,
                'from_column_id' => $fromColumnId,
                'to_column_id' => $automation->target_column_id,
                'triggered_by' => 'event',
                'triggered_event_key' => $eventKey,
                'triggered_by_user_id' => $triggeredByUserId,
                'reason' => null,
            ]);
        });
    }

    private function evaluateCondition(?array $condition, CampaignAssignment $assignment): bool
    {
        if (empty($condition)) {
            return true;
        }
        // Phase 1: a small set of supported condition keys
        // ... implementation
        return true;
    }
}
```

### 5.3 Conditions (Phase 1)

Automations can have an optional `condition` (jsonb on `board_automations`). Phase 1 supports a small fixed set of conditions:

- `{ "brand_auto_approve": true }` — only fires if the brand is configured for auto-approve
- `{ "amount_minor_units_gte": 1000000 }` — only fires for assignments above a threshold
- `{ "category_in": ["video", "reel"] }` — only for specific deliverable kinds

Conditions are explicitly enumerated. New conditions are added by code change, not configuration. This is intentional: the goal is "smart, not arbitrary."

### 5.4 Manual movement

Agencies can drag cards manually to any column. The system:

- Allows the move
- Records a `board_card_movement` row with `triggered_by: 'user'` and the user ID
- Does NOT trigger downstream business logic (this is the key principle)
- Does NOT prevent automations from later moving the card again if their event fires

A manual move is a UI hint, not a state change. The underlying assignment state machine drives reality.

---

## 6. Card creation and lifecycle

### 6.1 Creation

When a CampaignAssignment is created (typically when a creator is invited), a card is created on the campaign's board. The default column for new cards is configurable per board (typically the column mapped to `assignment.invited` automation, or the first column if none).

### 6.2 Updates

Cards update via:

- Automation moving them between columns
- Manual drag and drop
- The underlying assignment changing (e.g., draft submitted → card body shows updated info)

### 6.3 Deletion

When an assignment is soft-deleted, the card is also soft-deleted. The board still shows the removed card with a "removed" indicator (so audit trails are clear), filterable out by default.

### 6.4 Archival

When a campaign is completed or cancelled, its board enters "archive" mode:

- Read-only by default
- Can still be viewed
- Cards stay in their final columns

---

## 7. Column management

### 7.1 Adding a column

User clicks "+ Add Column" in the board UI. Form asks for name and color. Position defaults to the end. Saved.

### 7.2 Renaming a column

Click the column header → inline edit. Saves on blur.

### 7.3 Reordering columns

Drag column header. Position recomputed for affected columns.

### 7.4 Deleting a column

User clicks delete on a column header. Two cases:

- **Empty column:** confirmation modal, then deleted.
- **Non-empty column:** modal asks where to move existing cards (dropdown of other columns). After confirmation, cards are moved (as manual movements, audit-logged), then column is deleted.

### 7.5 Column rules

- **Minimum:** at least 1 column on every board.
- **Maximum:** no hard limit, but UI shows a warning above 12 columns.
- **Terminal columns:** at most one terminal-success and one terminal-failure column. Marking a second one as terminal swaps the previous.

---

## 8. Automation management UI

A dedicated tab on the campaign settings or a button in the board header opens the automation configuration:

- List of all events
- For each event: dropdown to select target column (or "No automation")
- Enable/disable toggle per automation
- Optional condition picker (Phase 1: dropdown of pre-defined conditions; Phase 3 may expand)

Changes are saved per-board.

### 8.1 "Reset to defaults"

A button restores the default column set and default automations. Confirmation required because it discards customizations.

### 8.2 "Apply template"

Phase 2 introduces saved board templates per agency. Phase 1 just has the system default.

---

## 9. Multi-campaign rollup view

Phase 2 introduces a cross-campaign rollup showing cards from multiple campaigns in a single "personal kanban" view per user. Phase 1 doesn't have this.

For Phase 1, a list view of "my open work" exists outside the board but is just a sortable list, not a kanban.

---

## 10. Performance considerations

### 10.1 Card count per board

- Typical campaign: 5–20 creators → 5–20 cards.
- Large campaign: up to 100 creators.
- Boards stay performant up to several hundred cards. Virtualized rendering kicks in above 50 cards per column.

### 10.2 Real-time updates

- Phase 1: client polls the board API every 30 seconds while the board is visible. Adequate for the volumes involved.
- Phase 2: WebSocket broadcasting (Laravel Reverb) for real-time updates without polling.

### 10.3 Database queries

- Loading a board: one query for the board, one for columns, one for cards (with eager-loaded assignment + creator + latest draft). Total: 4 queries regardless of card count.
- Card movement: one update query, one insert into `board_card_movements`. Two queries.
- Automation evaluation: zero additional queries beyond what already happens for the triggering event (the listener uses the loaded model).

---

## 11. API endpoints

```
GET    /api/v1/agencies/{agency}/campaigns/{campaign}/board
POST   /api/v1/agencies/{agency}/campaigns/{campaign}/board/columns
PATCH  /api/v1/agencies/{agency}/campaigns/{campaign}/board/columns/{column}
DELETE /api/v1/agencies/{agency}/campaigns/{campaign}/board/columns/{column}
PATCH  /api/v1/agencies/{agency}/campaigns/{campaign}/board/columns/reorder

GET    /api/v1/agencies/{agency}/campaigns/{campaign}/board/automations
PATCH  /api/v1/agencies/{agency}/campaigns/{campaign}/board/automations/{automation}

POST   /api/v1/agencies/{agency}/campaigns/{campaign}/board/cards/{card}/move
       (manual move; body: { target_column_id, reason? })

GET    /api/v1/agencies/{agency}/campaigns/{campaign}/board/cards/{card}/movements
       (audit history of card movements)

POST   /api/v1/agencies/{agency}/campaigns/{campaign}/board/reset-to-defaults
```

---

## 12. Frontend implementation

### 12.1 Component breakdown

```
BoardView.vue
├── BoardHeader.vue         # Title, filters, settings, reset button
├── BoardColumns.vue        # Horizontal scrolling container
│   └── BoardColumn.vue     # One column
│       ├── BoardColumnHeader.vue
│       └── BoardCardList.vue
│           └── CBoardCard.vue   # The card component (in packages/ui)
└── AssignmentDrawer.vue    # The right-side drawer
```

### 12.2 Drag and drop

Use `vuedraggable` or `@vueuse/integrations` (sortable.js wrapper) for drag and drop. Drop targets:

- Between columns (move card)
- Within column (reorder — Phase 2; Phase 1 doesn't support intra-column ordering)

### 12.3 State management

The board's state is a Pinia store: `useBoardStore({ campaignId })`. The store:

- Loads the board on mount
- Polls every 30 seconds while visible
- Exposes `columns`, `cards`, `automations`
- Exposes actions: `moveCard`, `addColumn`, `editColumn`, `deleteColumn`, `updateAutomation`
- Optimistically updates state on user action, then reconciles with server response

### 12.4 Optimistic updates

- User drags a card → state updates immediately → API call → on failure, revert with toast notification
- Important: optimistic updates are for UX only; server is the source of truth.

---

## 13. Audit & history

Every card movement is recorded in `board_card_movements`:

- `triggered_by`: 'event' or 'user'
- `triggered_event_key`: the event that triggered (if event-driven)
- `triggered_by_user_id`: who triggered manually
- `from_column_id`, `to_column_id`
- `reason`: optional, captured on manual moves if user provides one
- `created_at`

A timeline view of card movements is accessible from the assignment detail drawer ("Movement history" tab).

---

## 14. Edge cases and rules

### 14.1 What happens when an event has no automation?

Nothing — the card stays in its current column. Agencies can drag manually if they want.

### 14.2 What happens when multiple events fire for the same assignment quickly?

Events are processed in order. Each looks at the current state. If an automation maps an event to a column the card is already in, no movement happens (no duplicate `board_card_movements` entry).

### 14.3 What happens when a column is deleted while cards are in it?

Modal forces the user to choose a destination column first. Cards are moved (audit-logged as manual movements), then column is deleted.

### 14.4 What happens when an automation references a deleted column?

The automation is disabled and target column is null. The board's automation UI shows it as broken; agency must fix.

### 14.5 What happens when an assignment is restored from soft-delete?

A new card is created on the board (in the column matching the assignment's current state). The old card's audit history is preserved.

### 14.6 What happens to cards when the campaign is cancelled?

The cancellation event fires, the cancellation automation moves all cards to the "Cancelled" column. The board enters archive mode.

---

## 15. Phase-by-phase roadmap

| Phase  | Additions                                                                                                                                            |
| ------ | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| **P1** | Smart automations as defined here. Default templates. Manual drag and drop. Audit history per card.                                                  |
| **P2** | WebSocket real-time updates. Saved board templates per agency. Cross-campaign rollup view. Brand-side board view (read-only or limited).             |
| **P3** | Generic rule builder (if-then-else logic). Time-based triggers as standalone rules. Cross-board automations. AI suggestions for board configuration. |
| **P4** | Workflow templates per vertical (music, beauty, gaming) with vertical-specific events and columns.                                                   |

---

## 16. Why this design

The Smart automation pattern (vs. lite manual or full rule builder) is the right Phase 1 trade because:

- **Lite (manual only):** loses the magic. Agencies use ClickUp because it's free and known.
- **Full rule builder:** months of work, large test surface, easy to misuse, brittle in production. Phase 3 territory at best.
- **Smart:** real automation that works, fixed catalog ensures correctness, configurable enough for 95% of workflows.

By Phase 3, if real demand emerges for a generic rule builder, it can be built knowing the smart system has proven the concept.

The other key insight is the strict separation between **board state** (visualization) and **assignment state** (reality). Manual card movements never trigger business logic. This prevents disasters like "I moved a card to Paid by accident and the system actually paid out €10,000."

---

**End of board automation spec. The agency's daily UI lives here.**
