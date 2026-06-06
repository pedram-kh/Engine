/**
 * useBoardStore (Sprint 12 Chunk 2, D-2/D-3/D-4). Pins the optimistic source of
 * truth: the bucketing, the optimistic move + revert (load-bearing #1), the
 * reconcile skip-while-pending gate (load-bearing #2), the deleted-mid-move
 * no-ghost edge, and the column / automation mutators.
 *
 * The board API is mocked so no transport runs.
 */

import { ApiError } from '@catalyst/api-client'
import type { BoardCardResource, BoardColumnResource, BoardResource } from '@catalyst/api-client'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/board.api', () => ({
  boardApi: {
    show: vi.fn(),
    moveCard: vi.fn(),
    movements: vi.fn(),
    createColumn: vi.fn(),
    updateColumn: vi.fn(),
    deleteColumn: vi.fn(),
    reorderColumns: vi.fn(),
    updateAutomation: vi.fn(),
  },
}))

import { boardApi } from '../api/board.api'
import { useBoardStore } from './useBoardStore'

const mockApi = vi.mocked(boardApi)

const AGENCY = 'agency-ulid'
const CAMPAIGN = 'campaign-ulid'

function col(id: string, position: number, attrs: Partial<BoardColumnResource['attributes']> = {}) {
  return {
    id,
    type: 'board_columns' as const,
    attributes: {
      name: id,
      position,
      color_token: 'status-todefine',
      is_terminal_success: false,
      is_terminal_failure: false,
      card_count: null,
      created_at: '2026-06-01T00:00:00+00:00',
      updated_at: '2026-06-01T00:00:00+00:00',
      ...attrs,
    },
  }
}

function card(id: string, columnId: string | null): BoardCardResource {
  return {
    id,
    type: 'board_cards',
    attributes: {
      position: 0,
      created_at: '2026-06-01T00:00:00+00:00',
      updated_at: '2026-06-01T00:00:00+00:00',
    },
    relationships: {
      column: { data: { id: columnId, type: 'board_columns' } },
      assignment: {
        data: {
          id: `assignment-${id}`,
          type: 'campaign_assignments',
          status: 'invited',
          deliverables: null,
          posting_due_at: null,
          creator: { id: `creator-${id}`, display_name: `Creator ${id}` },
        },
      },
    },
  }
}

function board(
  columns: BoardColumnResource[],
  cards: BoardCardResource[],
  automations: BoardResource['automations'] = [],
): BoardResource {
  return {
    id: 'board-ulid',
    type: 'boards',
    attributes: {
      created_at: '2026-06-01T00:00:00+00:00',
      updated_at: '2026-06-01T00:00:00+00:00',
    },
    relationships: { campaign: { data: { id: CAMPAIGN, type: 'campaigns' } } },
    columns,
    automations,
    cards,
  }
}

function columnUlidsOf(cards: BoardCardResource[]): Record<string, string | null> {
  return Object.fromEntries(cards.map((c) => [c.id, c.relationships.column.data.id]))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

describe('useBoardStore — load + bucketing', () => {
  it('loads the board and buckets the flat card list by column', async () => {
    mockApi.show.mockResolvedValue({
      data: board(
        [col('c1', 1), col('c2', 2)],
        [card('k1', 'c1'), card('k2', 'c1'), card('k3', 'c2')],
      ),
    })
    const store = useBoardStore()
    await store.load(AGENCY, CAMPAIGN)

    expect((store.cardsByColumn['c1'] ?? []).map((c) => c.id)).toEqual(['k1', 'k2'])
    expect((store.cardsByColumn['c2'] ?? []).map((c) => c.id)).toEqual(['k3'])
    expect(store.cardCountByColumn).toEqual({ c1: 2, c2: 1 })
  })

  it('flags loadError when the initial fetch fails with no prior state', async () => {
    mockApi.show.mockRejectedValue(new Error('boom'))
    const store = useBoardStore()
    await store.load(AGENCY, CAMPAIGN)
    expect(store.loadError).toBe(true)
  })
})

describe('useBoardStore — optimistic move + revert (load-bearing #1)', () => {
  it('moves the card optimistically and keeps it on success', async () => {
    mockApi.show.mockResolvedValue({
      data: board([col('c1', 1), col('c2', 2)], [card('k1', 'c1')]),
    })
    mockApi.moveCard.mockResolvedValue({ data: card('k1', 'c2') })
    const store = useBoardStore()
    await store.load(AGENCY, CAMPAIGN)

    const ok = await store.moveCard('k1', 'c2')

    expect(ok).toBe(true)
    expect(mockApi.moveCard).toHaveBeenCalledWith(AGENCY, CAMPAIGN, 'k1', {
      target_column_id: 'c2',
    })
    expect(columnUlidsOf(store.cards)['k1']).toBe('c2')
    expect(store.isMovePending('k1')).toBe(false)
  })

  it('reverts the card to its ORIGIN column when the server rejects the move (422)', async () => {
    // NON-trivial origin: the card starts in c2 (NOT the first column). The store
    // captures that origin BEFORE the optimistic mutation; this fixture makes two
    // plausible bugs fail loudly — a "revert to columns[0]" bug would land on c1,
    // and a "revert re-reads the already-mutated current column" bug would land on
    // the target c3. Only restoring the captured c2 passes.
    mockApi.show.mockResolvedValue({
      data: board([col('c1', 1), col('c2', 2), col('c3', 3)], [card('k1', 'c2')]),
    })
    mockApi.moveCard.mockRejectedValue(
      new ApiError({ status: 422, code: 'validation.failed', message: 'no' }),
    )
    const store = useBoardStore()
    await store.load(AGENCY, CAMPAIGN)

    // Optimistically drag c2 → c3, then the server rejects it.
    const ok = await store.moveCard('k1', 'c3')

    expect(ok).toBe(false)
    // Restored to the SPECIFIC captured origin (c2) — not the first column (c1),
    // not the rejected target (c3). The board must not lie after a reject.
    expect(columnUlidsOf(store.cards)['k1']).toBe('c2')
    expect(store.isMovePending('k1')).toBe(false)
  })
})

describe('useBoardStore — reconcile skip-while-pending (load-bearing #2)', () => {
  it('takes the server column for a NON-pending card', async () => {
    mockApi.show.mockResolvedValue({
      data: board([col('c1', 1), col('c2', 2)], [card('k1', 'c1')]),
    })
    const store = useBoardStore()
    await store.load(AGENCY, CAMPAIGN)

    // A poll observes the card already moved to c2 server-side (e.g. an automation).
    store.reconcile(board([col('c1', 1), col('c2', 2)], [card('k1', 'c2')]))
    expect(columnUlidsOf(store.cards)['k1']).toBe('c2')
  })

  it('skips the pending card but STILL applies server moves for non-pending cards in the same reconcile (gate is specific, not a blanket freeze)', async () => {
    // k1 is the dragged (pending) card; k2 is a bystander the server moves via an
    // automation. Both ride the SAME reconcile, so this proves the gate is scoped
    // to inFlightMoves rather than a global "ignore the server" freeze.
    mockApi.show.mockResolvedValue({
      data: board([col('c1', 1), col('c2', 2)], [card('k1', 'c1'), card('k2', 'c1')]),
    })
    // Hold the move POST open so k1 stays in-flight during the reconcile.
    let resolveMove: (v: { data: BoardCardResource }) => void = () => {}
    mockApi.moveCard.mockReturnValue(
      new Promise((resolve) => {
        resolveMove = resolve
      }),
    )
    const store = useBoardStore()
    await store.load(AGENCY, CAMPAIGN)

    const movePromise = store.moveCard('k1', 'c2') // optimistic k1 → c2, now in-flight
    expect(store.isMovePending('k1')).toBe(true)
    expect(columnUlidsOf(store.cards)['k1']).toBe('c2')

    // The 30s poll fires mid-move: the server still has k1 at its ORIGIN (c1) — it
    // hasn't caught up yet — AND it reports k2 moved to c2 (an automation fired).
    store.reconcile(board([col('c1', 1), col('c2', 2)], [card('k1', 'c1'), card('k2', 'c2')]))

    // (a) The in-flight gate held: k1's optimistic c2 is PRESERVED, not snapped to c1.
    expect(columnUlidsOf(store.cards)['k1']).toBe('c2')
    // (b) The gate is SPECIFIC: the NON-pending k2 DID take the server column
    //     (c1 → c2) in the very same reconcile. A blanket freeze that ignored all
    //     server card-moves would leave k2 at c1 and fail here.
    expect(columnUlidsOf(store.cards)['k2']).toBe('c2')

    resolveMove({ data: card('k1', 'c2') })
    await movePromise
    expect(columnUlidsOf(store.cards)['k1']).toBe('c2')
    expect(store.isMovePending('k1')).toBe(false)
  })

  it('revert-then-remove leaves no ghost when the card is deleted server-side mid-move', async () => {
    mockApi.show.mockResolvedValue({
      data: board([col('c1', 1), col('c2', 2)], [card('k1', 'c1')]),
    })
    mockApi.moveCard.mockRejectedValue(
      new ApiError({ status: 404, code: 'not_found', message: 'gone' }),
    )
    const store = useBoardStore()
    await store.load(AGENCY, CAMPAIGN)

    // The card was deleted server-side; the move 404s → revert fires.
    const ok = await store.moveCard('k1', 'c2')
    expect(ok).toBe(false)
    expect(columnUlidsOf(store.cards)['k1']).toBe('c1') // reverted to origin, still present

    // The NEXT reconcile (card no longer pending, server omits it) removes it —
    // no ghost left behind.
    store.reconcile(board([col('c1', 1), col('c2', 2)], []))
    expect(store.cards.find((c) => c.id === 'k1')).toBeUndefined()
  })
})

describe('useBoardStore — column mutators', () => {
  beforeEach(async () => {
    mockApi.show.mockResolvedValue({
      data: board([col('c1', 1), col('c2', 2)], [card('k1', 'c1')]),
    })
  })

  it('createColumn appends the returned column', async () => {
    mockApi.createColumn.mockResolvedValue({ data: col('c3', 3, { name: 'Producing' }) })
    const store = useBoardStore()
    await store.load(AGENCY, CAMPAIGN)

    await store.createColumn({ name: 'Producing', color_token: 'status-review' })
    expect(store.columns.map((c) => c.id)).toEqual(['c1', 'c2', 'c3'])
  })

  it('updateColumn replaces the edited column and refreshes after a terminal flag', async () => {
    mockApi.updateColumn.mockResolvedValue({ data: col('c2', 2, { is_terminal_success: true }) })
    const store = useBoardStore()
    await store.load(AGENCY, CAMPAIGN)
    mockApi.show.mockClear()

    await store.updateColumn('c2', { is_terminal_success: true })
    // Terminal swap is server-enforced → a refresh refetches the board.
    expect(mockApi.show).toHaveBeenCalledTimes(1)
  })

  it('deleteColumn propagates the destination_required 422 as an ApiError', async () => {
    mockApi.deleteColumn.mockRejectedValue(
      new ApiError({ status: 422, code: 'board.column.destination_required', message: 'pick one' }),
    )
    const store = useBoardStore()
    await store.load(AGENCY, CAMPAIGN)

    await expect(store.deleteColumn('c1')).rejects.toMatchObject({
      code: 'board.column.destination_required',
    })
  })

  it('reorderColumns is optimistic and reverts on a rejected reorder', async () => {
    mockApi.reorderColumns.mockRejectedValue(
      new ApiError({ status: 422, code: 'board.column.reorder_mismatch', message: 'no' }),
    )
    const store = useBoardStore()
    await store.load(AGENCY, CAMPAIGN)

    const ok = await store.reorderColumns(['c2', 'c1'])
    expect(ok).toBe(false)
    expect(store.columns.map((c) => c.id)).toEqual(['c1', 'c2']) // reverted
  })
})
