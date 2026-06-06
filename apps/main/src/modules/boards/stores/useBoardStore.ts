/**
 * The board store (Sprint 12 Chunk 2, D-2/D-3/D-4) — the optimistic source of
 * truth for one campaign's Kanban. A setup-store (the `useAgencyStore` shape).
 *
 * Holds `columns` + `cards` (FLAT, bucketed by `column_id` client-side — the
 * BoardResource ships cards flat) + `automations`, plus an in-flight-move set
 * (net-new, no precedent). The load-bearing seams:
 *
 *   - D-3 optimistic-move-then-revert: `moveCard` mutates the card's column
 *     LOCALLY first, then POSTs; on failure it restores the ORIGIN column +
 *     signals the caller to toast. The board is a visualization layer (the Ch1
 *     invariant) — the one place it can lie to the agency is a drag the server
 *     rejects, so the revert lives here.
 *   - D-4 skip-reconcile-while-a-move-is-pending: `reconcile` (driven by the 30s
 *     poll) takes the server state for every card EXCEPT those in
 *     `inFlightMoves`, whose optimistic column is preserved. A poll firing
 *     mid-move can never snap the pending card back to its origin.
 */

import type {
  BoardAutomationResource,
  BoardCardResource,
  BoardColumnResource,
  BoardResource,
  CreateBoardColumnPayload,
  UpdateBoardAutomationPayload,
  UpdateBoardColumnPayload,
} from '@catalyst/api-client'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { boardApi } from '../api/board.api'

/** The column ULID a card currently sits in (its bucket key), or null. */
function cardColumnId(card: BoardCardResource): string | null {
  return card.relationships.column.data.id
}

/** Return a copy of `card` re-homed into `columnId` (immutable update). */
function withColumn(card: BoardCardResource, columnId: string | null): BoardCardResource {
  return {
    ...card,
    relationships: {
      ...card.relationships,
      column: { data: { id: columnId, type: 'board_columns' } },
    },
  }
}

export const useBoardStore = defineStore('board', () => {
  // ---------------------------------------------------------------
  // State
  // ---------------------------------------------------------------
  const agencyId = ref<string | null>(null)
  const campaignUlid = ref<string | null>(null)
  const boardId = ref<string | null>(null)

  const columns = ref<BoardColumnResource[]>([])
  const cards = ref<BoardCardResource[]>([])
  const automations = ref<BoardAutomationResource[]>([])

  /** Card ULIDs with a move POST in flight — the reconcile gate (D-4). */
  const inFlightMoves = ref<Set<string>>(new Set())

  const loading = ref(false)
  const loadError = ref(false)

  // ---------------------------------------------------------------
  // Getters
  // ---------------------------------------------------------------
  const sortedColumns = computed(() =>
    [...columns.value].sort((a, b) => a.attributes.position - b.attributes.position),
  )

  /** Cards bucketed by their column ULID (the FE buckets the flat list, D-2). */
  const cardsByColumn = computed<Record<string, BoardCardResource[]>>(() => {
    const buckets: Record<string, BoardCardResource[]> = {}
    for (const column of columns.value) {
      buckets[column.id] = []
    }
    for (const card of cards.value) {
      const colId = cardColumnId(card)
      if (colId !== null && buckets[colId] !== undefined) {
        buckets[colId].push(card)
      }
    }
    return buckets
  })

  /** Client-side card counts per column ULID — the §14.3 "non-empty" read. */
  const cardCountByColumn = computed<Record<string, number>>(() => {
    const counts: Record<string, number> = {}
    for (const [colId, list] of Object.entries(cardsByColumn.value)) {
      counts[colId] = list.length
    }
    return counts
  })

  function isMovePending(cardUlid: string): boolean {
    return inFlightMoves.value.has(cardUlid)
  }

  // ---------------------------------------------------------------
  // Internal mutators
  // ---------------------------------------------------------------
  function setCardColumn(cardUlid: string, columnId: string | null): void {
    cards.value = cards.value.map((c) => (c.id === cardUlid ? withColumn(c, columnId) : c))
  }

  function applyServerCard(card: BoardCardResource): void {
    const exists = cards.value.some((c) => c.id === card.id)
    cards.value = exists
      ? cards.value.map((c) => (c.id === card.id ? card : c))
      : [...cards.value, card]
  }

  function markInFlight(cardUlid: string): void {
    const next = new Set(inFlightMoves.value)
    next.add(cardUlid)
    inFlightMoves.value = next
  }

  function clearInFlight(cardUlid: string): void {
    const next = new Set(inFlightMoves.value)
    next.delete(cardUlid)
    inFlightMoves.value = next
  }

  function applyBoard(board: BoardResource): void {
    boardId.value = board.id
    columns.value = board.columns
    automations.value = board.automations
    cards.value = board.cards
  }

  // ---------------------------------------------------------------
  // Actions
  // ---------------------------------------------------------------

  /** Initial load — sets the loading flag (the skeleton). */
  async function load(nextAgencyId: string, nextCampaignUlid: string): Promise<void> {
    agencyId.value = nextAgencyId
    campaignUlid.value = nextCampaignUlid
    loading.value = cards.value.length === 0 && columns.value.length === 0
    loadError.value = false
    try {
      const res = await boardApi.show(nextAgencyId, nextCampaignUlid)
      applyBoard(res.data)
      loadError.value = false
    } catch {
      if (columns.value.length === 0) {
        loadError.value = true
      }
    } finally {
      loading.value = false
    }
  }

  /** Silent refetch → reconcile (no skeleton). The 30s poll's tick + post-mutation refresh. */
  async function refresh(): Promise<void> {
    const a = agencyId.value
    const c = campaignUlid.value
    if (a === null || c === null) return
    try {
      const res = await boardApi.show(a, c)
      reconcile(res.data)
      loadError.value = false
    } catch {
      // Transient — keep the existing board; the next tick retries.
    }
  }

  /**
   * D-4. Take the server state for every card EXCEPT those whose move is still
   * in flight (their optimistic column is preserved). Columns + automations are
   * always replaced from the server. A pending card is kept LOCAL regardless of
   * whether the server still lists it — so a poll mid-move never clobbers it,
   * and the move's own success/revert path is the only thing that resolves it.
   */
  function reconcile(board: BoardResource): void {
    boardId.value = board.id
    columns.value = board.columns
    automations.value = board.automations

    const next: BoardCardResource[] = []
    for (const serverCard of board.cards) {
      if (!inFlightMoves.value.has(serverCard.id)) {
        next.push(serverCard)
      }
    }
    for (const localCard of cards.value) {
      if (inFlightMoves.value.has(localCard.id)) {
        next.push(localCard)
      }
    }
    cards.value = next
  }

  /**
   * D-3 — the load-bearing optimistic move. Re-homes the card LOCALLY, POSTs the
   * move, and on failure restores the ORIGIN column. Returns `true` on success,
   * `false` on a rejected move (the caller toasts). Never throws.
   */
  async function moveCard(cardUlid: string, targetColumnUlid: string): Promise<boolean> {
    const a = agencyId.value
    const c = campaignUlid.value
    const card = cards.value.find((x) => x.id === cardUlid)
    if (a === null || c === null || card === undefined) return false

    const originColumnId = cardColumnId(card)
    if (originColumnId === targetColumnUlid) return true // already there — no-op

    setCardColumn(cardUlid, targetColumnUlid) // optimistic
    markInFlight(cardUlid)
    try {
      const res = await boardApi.moveCard(a, c, cardUlid, { target_column_id: targetColumnUlid })
      applyServerCard(res.data) // server is authoritative on success
      return true
    } catch {
      setCardColumn(cardUlid, originColumnId) // revert to origin (D-3)
      return false
    } finally {
      clearInFlight(cardUlid)
    }
  }

  /** Add a column (§7.1). Errors propagate so the form binds the 422 fields. */
  async function createColumn(payload: CreateBoardColumnPayload): Promise<void> {
    const a = agencyId.value
    const c = campaignUlid.value
    if (a === null || c === null) return
    const res = await boardApi.createColumn(a, c, payload)
    columns.value = [...columns.value, res.data]
  }

  /**
   * Edit a column (§7.2/§7.5). A terminal-flag set triggers a silent refresh:
   * the ≤1-each swap is server-enforced and the PATCH response carries only the
   * edited column, so the previously-terminal column's flip needs a refetch.
   */
  async function updateColumn(
    columnUlid: string,
    payload: UpdateBoardColumnPayload,
  ): Promise<void> {
    const a = agencyId.value
    const c = campaignUlid.value
    if (a === null || c === null) return
    const res = await boardApi.updateColumn(a, c, columnUlid, payload)
    columns.value = columns.value.map((col) => (col.id === columnUlid ? res.data : col))
    if (payload.is_terminal_success === true || payload.is_terminal_failure === true) {
      await refresh()
    }
  }

  /**
   * Delete a column (§14.3). The 422 codes (`board.column.last_column` /
   * `board.column.destination_required`) + the 404 propagate so the caller binds
   * them as `ApiError.code` banners. On success a refresh reflects the re-homed
   * cards (moved server-side as manual movements).
   */
  async function deleteColumn(columnUlid: string, destinationColumnUlid?: string): Promise<void> {
    const a = agencyId.value
    const c = campaignUlid.value
    if (a === null || c === null) return
    await boardApi.deleteColumn(
      a,
      c,
      columnUlid,
      destinationColumnUlid !== undefined ? { destination_column_id: destinationColumnUlid } : {},
    )
    await refresh()
  }

  /** Reorder columns by drag (§7.3). Optimistic; reverts on a rejected reorder. */
  async function reorderColumns(orderedUlids: string[]): Promise<boolean> {
    const a = agencyId.value
    const c = campaignUlid.value
    if (a === null || c === null) return false

    const previous = columns.value
    const byId = new Map(previous.map((col) => [col.id, col]))
    const reordered: BoardColumnResource[] = []
    orderedUlids.forEach((ulid, index) => {
      const col = byId.get(ulid)
      if (col !== undefined) {
        reordered.push({ ...col, attributes: { ...col.attributes, position: index + 1 } })
      }
    })
    columns.value = reordered // optimistic

    try {
      const res = await boardApi.reorderColumns(a, c, { column_ids: orderedUlids })
      columns.value = res.data
      return true
    } catch {
      columns.value = previous // revert
      return false
    }
  }

  /** Set an automation's target column / enable state (§8). Errors propagate. */
  async function updateAutomation(
    automationUlid: string,
    payload: UpdateBoardAutomationPayload,
  ): Promise<void> {
    const a = agencyId.value
    const c = campaignUlid.value
    if (a === null || c === null) return
    const res = await boardApi.updateAutomation(a, c, automationUlid, payload)
    automations.value = automations.value.map((auto) =>
      auto.id === automationUlid ? res.data : auto,
    )
  }

  /** Reset to empty state (called when the board tab unmounts). */
  function reset(): void {
    agencyId.value = null
    campaignUlid.value = null
    boardId.value = null
    columns.value = []
    cards.value = []
    automations.value = []
    inFlightMoves.value = new Set()
    loading.value = false
    loadError.value = false
  }

  return {
    // state
    agencyId,
    campaignUlid,
    boardId,
    columns,
    cards,
    automations,
    inFlightMoves,
    loading,
    loadError,
    // getters
    sortedColumns,
    cardsByColumn,
    cardCountByColumn,
    isMovePending,
    // actions
    load,
    refresh,
    reconcile,
    moveCard,
    createColumn,
    updateColumn,
    deleteColumn,
    reorderColumns,
    updateAutomation,
    reset,
  }
})
