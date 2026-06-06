/**
 * Unit tests for the Boards API wrapper (Sprint 12 Chunk 2, D-12). Pins every
 * endpoint's URL + body shape against the Chunk 1 controller contract. The HTTP
 * singleton is mocked so no transport runs.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/core/api', () => ({
  http: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}))

import { http } from '@/core/api'
import { boardApi } from './board.api'

const mockHttp = vi.mocked(http)

const AGENCY = 'agency-ulid'
const CAMPAIGN = 'campaign-ulid'
const BASE = `/agencies/${AGENCY}/campaigns/${CAMPAIGN}/board`

beforeEach(() => {
  vi.clearAllMocks()
  mockHttp.get.mockResolvedValue({ data: {} })
  mockHttp.post.mockResolvedValue({ data: {} })
  mockHttp.patch.mockResolvedValue({ data: {} })
  mockHttp.delete.mockResolvedValue(undefined)
})

describe('boardApi', () => {
  it('GETs the full board in one fetch', () => {
    void boardApi.show(AGENCY, CAMPAIGN)
    expect(mockHttp.get).toHaveBeenCalledWith(BASE)
  })

  it('POSTs a manual move with only target_column_id (reason omitted on drag, Q2)', () => {
    void boardApi.moveCard(AGENCY, CAMPAIGN, 'card-1', { target_column_id: 'col-2' })
    expect(mockHttp.post).toHaveBeenCalledWith(`${BASE}/cards/card-1/move`, {
      target_column_id: 'col-2',
    })
  })

  it('GETs the movement history feed', () => {
    void boardApi.movements(AGENCY, CAMPAIGN, 'card-1')
    expect(mockHttp.get).toHaveBeenCalledWith(`${BASE}/cards/card-1/movements`)
  })

  it('POSTs a new column to the columns collection', () => {
    void boardApi.createColumn(AGENCY, CAMPAIGN, {
      name: 'Producing',
      color_token: 'status-review',
    })
    expect(mockHttp.post).toHaveBeenCalledWith(`${BASE}/columns`, {
      name: 'Producing',
      color_token: 'status-review',
    })
  })

  it('PATCHes a column update', () => {
    void boardApi.updateColumn(AGENCY, CAMPAIGN, 'col-1', { name: 'Renamed' })
    expect(mockHttp.patch).toHaveBeenCalledWith(`${BASE}/columns/col-1`, { name: 'Renamed' })
  })

  it('DELETEs a column WITH a destination body (the §14.3 re-home)', () => {
    void boardApi.deleteColumn(AGENCY, CAMPAIGN, 'col-1', { destination_column_id: 'col-2' })
    expect(mockHttp.delete).toHaveBeenCalledWith(`${BASE}/columns/col-1`, {
      destination_column_id: 'col-2',
    })
  })

  it('DELETEs an empty column with an empty body (no destination needed)', () => {
    void boardApi.deleteColumn(AGENCY, CAMPAIGN, 'col-1')
    expect(mockHttp.delete).toHaveBeenCalledWith(`${BASE}/columns/col-1`, {})
  })

  it('PATCHes the reorder endpoint with the full ordered ULID list', () => {
    void boardApi.reorderColumns(AGENCY, CAMPAIGN, { column_ids: ['col-2', 'col-1'] })
    expect(mockHttp.patch).toHaveBeenCalledWith(`${BASE}/columns/reorder`, {
      column_ids: ['col-2', 'col-1'],
    })
  })

  it('PATCHes an automation (target column + enable)', () => {
    void boardApi.updateAutomation(AGENCY, CAMPAIGN, 'auto-1', {
      target_column_id: 'col-3',
      is_enabled: true,
    })
    expect(mockHttp.patch).toHaveBeenCalledWith(`${BASE}/automations/auto-1`, {
      target_column_id: 'col-3',
      is_enabled: true,
    })
  })

  it('PATCHes an automation to "No automation" (explicit null target)', () => {
    void boardApi.updateAutomation(AGENCY, CAMPAIGN, 'auto-1', { target_column_id: null })
    expect(mockHttp.patch).toHaveBeenCalledWith(`${BASE}/automations/auto-1`, {
      target_column_id: null,
    })
  })
})
