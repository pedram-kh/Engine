import { beforeEach, describe, expect, it, vi } from 'vitest'

import { lookupThreadCounterparty } from './threadLookup'

import { relationshipMessagingApi } from '@/modules/messaging/api/relationshipMessaging.api'

vi.mock('@/modules/messaging/api/relationshipMessaging.api', () => ({
  relationshipMessagingApi: { creatorInbox: vi.fn(), agencyInbox: vi.fn() },
}))

const THREAD = '01J9ZK3Q8M5T7WXYTHREADAAAA'
const OTHER = '01J9ZK3Q8M5T7WXYXTHERAAAAA'
const AGENCY = '01J9ZK3Q8M5T7WXYAGENCYAAAA'
const CREATOR = '01J9ZK3Q8M5T7WXYCREATRAAAA'

const creatorInbox = vi.mocked(relationshipMessagingApi.creatorInbox)
const agencyInbox = vi.mocked(relationshipMessagingApi.agencyInbox)

beforeEach(() => {
  vi.clearAllMocks()
})

describe('lookupThreadCounterparty', () => {
  it('creator side: finds the thread row by id and returns the AGENCY ulid', async () => {
    creatorInbox.mockResolvedValue({
      data: [
        {
          id: OTHER,
          type: 'relationship_thread',
          attributes: {
            agency: { id: 'x', name: null, logo_path: null, logo_url: null },
            last_message_at: null,
            last_message_preview: null,
            unread_count: 0,
          },
        },
        {
          id: THREAD,
          type: 'relationship_thread',
          attributes: {
            agency: { id: AGENCY, name: 'Acme', logo_path: null, logo_url: null },
            last_message_at: null,
            last_message_preview: null,
            unread_count: 1,
          },
        },
      ],
    })

    expect(await lookupThreadCounterparty('creator', THREAD, null)).toBe(AGENCY)
  })

  it('agency side: uses the given agency id and returns the CREATOR ulid', async () => {
    agencyInbox.mockResolvedValue({
      data: [
        {
          id: THREAD,
          type: 'relationship_thread',
          attributes: {
            creator: { id: CREATOR, display_name: 'Nora', avatar_url: null },
            last_message_at: null,
            last_message_preview: null,
            unread_count: 0,
          },
        },
      ],
    })

    expect(await lookupThreadCounterparty('agency', THREAD, 'AGENCY-ID')).toBe(CREATOR)
    expect(agencyInbox).toHaveBeenCalledWith('AGENCY-ID')
  })

  it('agency side with no current agency → null, and no request is made', async () => {
    expect(await lookupThreadCounterparty('agency', THREAD, null)).toBeNull()
    expect(agencyInbox).not.toHaveBeenCalled()
  })

  it('a thread that is not in the (unpaginated) inbox → null', async () => {
    creatorInbox.mockResolvedValue({ data: [] })

    expect(await lookupThreadCounterparty('creator', THREAD, null)).toBeNull()
  })

  it('any error (offline / 401 / 5xx) → null, never a throw', async () => {
    creatorInbox.mockRejectedValue(new Error('Network Error'))
    agencyInbox.mockRejectedValue(new Error('500'))

    expect(await lookupThreadCounterparty('creator', THREAD, null)).toBeNull()
    expect(await lookupThreadCounterparty('agency', THREAD, 'A')).toBeNull()
  })
})
