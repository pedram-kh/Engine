/**
 * AH-119 Stage B — the edit and delete transports, one per route family:
 * campaign (agency + creator), relationship (agency + creator). Each PATCHes
 * `…/messages/{id}` with `{ body }` and DELETEs `…/messages/{id}`; the id is URL-encoded.
 *
 * Break-revert: use POST for edit → the verb case fails; drop `encodeURIComponent` → the
 * encoding case fails; point the creator DM at the agency path → the paths case fails.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/core/api', () => ({
  http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import { http } from '@/core/api'

import { agencyChatTransport, creatorChatTransport } from './messaging.api'
import {
  agencyRelationshipTransport,
  creatorRelationshipTransport,
} from './relationshipMessaging.api'

const mockHttp = vi.mocked(http)

const FAMILIES = [
  [
    'agency campaign',
    () => agencyChatTransport('ag', 'camp', 'asg'),
    '/agencies/ag/campaigns/camp/assignments/asg/messages',
  ],
  ['creator campaign', () => creatorChatTransport('asg'), '/creators/me/assignments/asg/messages'],
  [
    'agency DM',
    () => agencyRelationshipTransport('ag', 'cr'),
    '/agencies/ag/creators/cr/relationship-messages',
  ],
  [
    'creator DM',
    () => creatorRelationshipTransport('ag'),
    '/creators/me/relationship-threads/ag/messages',
  ],
] as const

describe.each(FAMILIES)('%s transport', (_name, make, base) => {
  beforeEach(() => vi.clearAllMocks())

  it('edit PATCHes the message with the new body', async () => {
    mockHttp.patch.mockResolvedValue({ data: { id: 'm1' } })
    const res = await make().edit!('m1', { body: 'fixed' })
    expect(mockHttp.patch).toHaveBeenCalledWith(`${base}/m1`, { body: 'fixed' })
    expect(mockHttp.post).not.toHaveBeenCalled()
    expect(res).toEqual({ data: { id: 'm1' } })
  })

  it('remove DELETEs the message', async () => {
    mockHttp.delete.mockResolvedValue({ data: { id: 'm1' } })
    await make().remove!('m1')
    expect(mockHttp.delete).toHaveBeenCalledWith(`${base}/m1`)
  })

  it('URL-encodes the message id', async () => {
    mockHttp.patch.mockResolvedValue({ data: {} })
    await make().edit!('a/b?c', { body: 'x' })
    expect(mockHttp.patch).toHaveBeenCalledWith(`${base}/a%2Fb%3Fc`, { body: 'x' })
  })
})
