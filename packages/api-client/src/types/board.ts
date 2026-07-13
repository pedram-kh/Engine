/**
 * Wire-contract types for the Sprint 12 board surfaces (Chunk 2, D-12).
 *
 * These mirror the backend Boards Resources VERBATIM (snake_case keys, ISO 8601
 * timestamps, ULID identifiers) — the same FE↔BE no-re-casing discipline as the
 * other type modules. Source of truth: the Chunk 1 Resources
 * (`App\Modules\Boards\Http\Resources\*`) + `docs/10-BOARD-AUTOMATION.md`.
 *
 * The board GET (`BoardResource`) ships columns + automations + cards in ONE
 * payload; cards are FLAT (the SPA buckets them by `relationships.column.data.id`
 * client-side). `color_token` is stored PREFIXED (`status-*`, Q2); the FE strips
 * the prefix to map onto the unprefixed `boardStatus` palette (D-11).
 */

import type { AssignmentStatus } from './campaign'

// ---------------------------------------------------------------------------
// Enums (mirror App\Modules\Boards\Enums\*)
// ---------------------------------------------------------------------------

/** Mirror of `BoardAutomationActionType` — the move/inert toggle (D-1). */
export type BoardAutomationActionType = 'move_to_column' | 'none'

/** Mirror of `MovementTrigger` — `event` (automation) or `user` (manual). */
export type BoardMovementTrigger = 'event' | 'user'

// ---------------------------------------------------------------------------
// Column
// ---------------------------------------------------------------------------

export interface BoardColumnAttributes {
  name: string
  position: number
  /** PREFIXED design-system status token, e.g. `status-paid` (Q2). */
  color_token: string
  is_terminal_success: boolean
  is_terminal_failure: boolean
  /** withCount('cards') on the board GET / reorder responses; null otherwise. */
  card_count: number | null
  created_at: string
  updated_at: string
}

export interface BoardColumnResource {
  id: string
  type: 'board_columns'
  attributes: BoardColumnAttributes
}

// ---------------------------------------------------------------------------
// Card
// ---------------------------------------------------------------------------

/**
 * The card-face data, surfaced from the eager-loaded assignment (a card IS a
 * CampaignAssignment, §4.1). Packed INTO the `assignment` relationship's `data`
 * object alongside `id`/`type`. The whole object is `null` when the assignment
 * could not be loaded — the SPA renders a null-safe reduced tile (D-10).
 */
export interface BoardCardAssignmentData {
  id: string
  type: 'campaign_assignments'
  status: AssignmentStatus
  /**
   * True once the row was declined then re-offered (re-offer-after-decline
   * chunk) — drives the "was declined, re-invited" tag on the card face + the
   * drawer. Optional for back-compat with older payloads.
   */
  previously_declined?: boolean
  deliverables: string[] | null
  posting_due_at: string | null
  /** Offer fee for the card face (board-card facelift): amount + free-text unit. */
  agreed_fee_minor_units?: number | null
  agreed_fee_currency?: string | null
  fee_per?: string | null
  creator: {
    id: string
    display_name: string | null
    /** Single signed avatar for the card face; bounded (one page of cards). */
    avatar_url?: string | null
  } | null
}

export interface BoardCardResource {
  id: string
  type: 'board_cards'
  attributes: {
    /** Present per §10 but INERT in P1 (intra-column ordering is P2). */
    position: number
    created_at: string
    updated_at: string
  }
  relationships: {
    column: {
      data: {
        id: string | null
        type: 'board_columns'
      }
    }
    assignment: {
      data: BoardCardAssignmentData | null
    }
  }
}

// ---------------------------------------------------------------------------
// Automation
// ---------------------------------------------------------------------------

export interface BoardAutomationAttributes {
  event_key: string
  action_type: BoardAutomationActionType
  is_enabled: boolean
  condition: Record<string, unknown> | null
  /** Target column ULID, or null when unmapped / the target was deleted (§14.4). */
  target_column_id: string | null
  created_at: string
  updated_at: string
}

export interface BoardAutomationResource {
  id: string
  type: 'board_automations'
  attributes: BoardAutomationAttributes
}

// ---------------------------------------------------------------------------
// Movement (the §13 history feed — newest-first from the API)
// ---------------------------------------------------------------------------

export interface BoardCardMovementResource {
  /** Stringified integer id (movements are append-only, not ULID-keyed). */
  id: string
  type: 'board_card_movements'
  attributes: {
    /** Column ULIDs; null when the column was since deleted (§14.3, null-safe). */
    from_column_id: string | null
    to_column_id: string | null
    triggered_by: BoardMovementTrigger
    triggered_event_key: string | null
    reason: string | null
    created_at: string
  }
}

// ---------------------------------------------------------------------------
// Board (the full single-fetch payload, §10.3)
// ---------------------------------------------------------------------------

export interface BoardResource {
  id: string
  type: 'boards'
  attributes: {
    created_at: string
    updated_at: string
  }
  relationships: {
    campaign: {
      data: {
        id: string | null
        type: 'campaigns'
      }
    }
  }
  columns: BoardColumnResource[]
  automations: BoardAutomationResource[]
  cards: BoardCardResource[]
}

// ---------------------------------------------------------------------------
// Envelopes
// ---------------------------------------------------------------------------

export interface BoardEnvelope {
  data: BoardResource
}

export interface BoardColumnEnvelope {
  data: BoardColumnResource
}

export interface BoardColumnListResponse {
  data: BoardColumnResource[]
}

export interface BoardCardEnvelope {
  data: BoardCardResource
}

export interface BoardAutomationEnvelope {
  data: BoardAutomationResource
}

export interface BoardAutomationListResponse {
  data: BoardAutomationResource[]
}

export interface BoardCardMovementListResponse {
  data: BoardCardMovementResource[]
}

// ---------------------------------------------------------------------------
// Mutation payloads
// ---------------------------------------------------------------------------

/** POST `…/cards/{card}/move` — `reason` omitted on drag-drop (Q2). */
export interface MoveBoardCardPayload {
  target_column_id: string
  reason?: string | null
}

/** POST `…/board/columns` — `name` ≤ 64 chars; `color_token` from the palette. */
export interface CreateBoardColumnPayload {
  name: string
  color_token: string
  is_terminal_success?: boolean
  is_terminal_failure?: boolean
}

/** PATCH `…/board/columns/{column}` — partial update (rename / recolor / terminal). */
export interface UpdateBoardColumnPayload {
  name?: string
  color_token?: string
  is_terminal_success?: boolean
  is_terminal_failure?: boolean
}

/**
 * DELETE `…/board/columns/{column}` body — the destination non-empty cards
 * re-home into (§14.3). Omitted for an empty column.
 */
export interface DeleteBoardColumnPayload {
  destination_column_id?: string | null
}

/** PATCH `…/board/columns/reorder` — the FULL ordered list of column ULIDs. */
export interface ReorderBoardColumnsPayload {
  column_ids: string[]
}

/** PATCH `…/board/automations/{automation}` — partial; `target_column_id: null` = "No automation". */
export interface UpdateBoardAutomationPayload {
  is_enabled?: boolean
  action_type?: BoardAutomationActionType
  target_column_id?: string | null
}
