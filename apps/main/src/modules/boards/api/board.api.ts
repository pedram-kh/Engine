/**
 * Typed wrapper for the Boards module API (Sprint 12 Chunk 2, D-12).
 *
 * Tenant-scoped to the current agency via the `agencyId` (ULID) path segment,
 * mirroring `campaigns.api.ts` / `messaging.api.ts`. The HTTP client handles
 * CSRF preflight + Sanctum cookie auth transparently.
 *
 * Endpoint prefix: /api/v1/agencies/{agency}/campaigns/{campaign}/board
 * Contract: docs/10-BOARD-AUTOMATION.md §11 + the Chunk 1 controllers.
 */

import type {
  BoardAutomationEnvelope,
  BoardCardEnvelope,
  BoardCardMovementListResponse,
  BoardColumnEnvelope,
  BoardColumnListResponse,
  BoardEnvelope,
  CreateBoardColumnPayload,
  DeleteBoardColumnPayload,
  MoveBoardCardPayload,
  ReorderBoardColumnsPayload,
  UpdateBoardAutomationPayload,
  UpdateBoardColumnPayload,
} from '@catalyst/api-client'

import { http } from '@/core/api'

function boardBase(agencyId: string, campaignUlid: string): string {
  return `/agencies/${agencyId}/campaigns/${campaignUlid}/board`
}

export const boardApi = {
  /** GET …/board — the full board (columns + automations + cards) in one payload (§10.3). */
  show(agencyId: string, campaignUlid: string): Promise<BoardEnvelope> {
    return http.get<BoardEnvelope>(boardBase(agencyId, campaignUlid))
  },

  /** POST …/board/cards/{card}/move — manual move (`reason` omitted on drag, Q2). */
  moveCard(
    agencyId: string,
    campaignUlid: string,
    cardUlid: string,
    payload: MoveBoardCardPayload,
  ): Promise<BoardCardEnvelope> {
    return http.post<BoardCardEnvelope>(
      `${boardBase(agencyId, campaignUlid)}/cards/${cardUlid}/move`,
      payload,
    )
  },

  /** GET …/board/cards/{card}/movements — the §13 history feed (newest-first). */
  movements(
    agencyId: string,
    campaignUlid: string,
    cardUlid: string,
  ): Promise<BoardCardMovementListResponse> {
    return http.get<BoardCardMovementListResponse>(
      `${boardBase(agencyId, campaignUlid)}/cards/${cardUlid}/movements`,
    )
  },

  /** POST …/board/columns — add a column (§7.1). 422 binds name / color_token. */
  createColumn(
    agencyId: string,
    campaignUlid: string,
    payload: CreateBoardColumnPayload,
  ): Promise<BoardColumnEnvelope> {
    return http.post<BoardColumnEnvelope>(`${boardBase(agencyId, campaignUlid)}/columns`, payload)
  },

  /** PATCH …/board/columns/{column} — rename / recolor / terminal (§7.2, §7.5). */
  updateColumn(
    agencyId: string,
    campaignUlid: string,
    columnUlid: string,
    payload: UpdateBoardColumnPayload,
  ): Promise<BoardColumnEnvelope> {
    return http.patch<BoardColumnEnvelope>(
      `${boardBase(agencyId, campaignUlid)}/columns/${columnUlid}`,
      payload,
    )
  },

  /**
   * DELETE …/board/columns/{column} — the §14.3 safeguard. A non-empty column
   * requires `destination_column_id`; the 422s (`board.column.last_column` /
   * `board.column.destination_required`) surface as `ApiError.code` banners.
   */
  deleteColumn(
    agencyId: string,
    campaignUlid: string,
    columnUlid: string,
    payload: DeleteBoardColumnPayload = {},
  ): Promise<void> {
    return http.delete<void>(`${boardBase(agencyId, campaignUlid)}/columns/${columnUlid}`, payload)
  },

  /** PATCH …/board/columns/reorder — the FULL ordered ULID list (§7.3). */
  reorderColumns(
    agencyId: string,
    campaignUlid: string,
    payload: ReorderBoardColumnsPayload,
  ): Promise<BoardColumnListResponse> {
    return http.patch<BoardColumnListResponse>(
      `${boardBase(agencyId, campaignUlid)}/columns/reorder`,
      payload,
    )
  },

  /** PATCH …/board/automations/{automation} — enable / target column (§8). */
  updateAutomation(
    agencyId: string,
    campaignUlid: string,
    automationUlid: string,
    payload: UpdateBoardAutomationPayload,
  ): Promise<BoardAutomationEnvelope> {
    return http.patch<BoardAutomationEnvelope>(
      `${boardBase(agencyId, campaignUlid)}/automations/${automationUlid}`,
      payload,
    )
  },
}
