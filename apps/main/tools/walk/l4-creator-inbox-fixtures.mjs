/**
 * AH-115 L4 — mock API for the creator unified inbox and search (`/creators/me/message-inbox`,
 * `/creators/me/message-search`), on top of the L2 creator messaging fixtures. Dev tool only.
 */

import { CREATOR_AGENCY, creatorFixtureFor } from './l2-fixtures.mjs'
import { l3CreatorFixtures } from './l3-creator-fixtures.mjs'

const creatorProfile = l3CreatorFixtures({ status: 'approved' })

const ago = (days, minutes = 0) =>
  new Date(Date.now() - days * 86_400_000 - minutes * 60_000).toISOString()

const row = (
  id,
  type,
  agency,
  brand,
  campaign,
  assignment,
  at,
  preview,
  unread,
  kind = 'text',
) => ({
  id,
  type,
  attributes: {
    agency: { id: agency[0], name: agency[1], logo_url: null },
    brand: brand === null ? null : { id: `b-${brand}`, name: brand },
    campaign: campaign === null ? null : { id: `c-${campaign}`, name: campaign },
    assignment_id: assignment,
    last_message_at: at,
    preview,
    preview_kind: kind,
    unread_count: unread,
  },
})

const NORTHWIND = [CREATOR_AGENCY, 'Northwind Agency']
const LUMEN = ['01HWALKAGENCY000000000002', 'Lumen Collective']
const ASTER = ['01HWALKAGENCY000000000003', 'Aster Talent']

export const INBOX_ROWS = [
  row(
    '01HTHREAD0000000000000001',
    'assignment_thread',
    NORTHWIND,
    'Glow Labs',
    'Summer skincare UGC',
    '01HASSIGNMENT0000000001',
    ago(0, 12),
    'The first cut is up, let me know what you think',
    3,
  ),
  row(
    '01HTHREAD0000000000000002',
    'relationship_thread',
    NORTHWIND,
    null,
    null,
    null,
    ago(0, 40),
    'Perfect, send the raw footage when it is ready',
    1,
  ),
  row(
    '01HTHREAD0000000000000003',
    'assignment_thread',
    LUMEN,
    'Lumen Audio',
    'Studio audio review',
    '01HASSIGNMENT0000000003',
    ago(1, 30),
    null,
    0,
    'attachment',
  ),
  row(
    '01HTHREAD0000000000000004',
    'relationship_thread',
    LUMEN,
    null,
    null,
    null,
    ago(3),
    'Thanks for the update, speak soon',
    0,
  ),
  row(
    '01HTHREAD0000000000000005',
    'assignment_thread',
    ASTER,
    'Northwind Coffee',
    'Autumn coffee launch',
    '01HASSIGNMENT0000000002',
    ago(9),
    'Contract signed and returned',
    0,
  ),
]

const SEARCH_ROWS = [
  {
    ...INBOX_ROWS[0],
    attributes: {
      ...INBOX_ROWS[0].attributes,
      message_ulid: '01HMSG00000000000000000001',
      snippet: '…happy with the first cut, the lighting reads well and the pacing is right…',
      sent_at: ago(0, 12),
      sender_role: 'agency_user',
      is_own: false,
    },
  },
  {
    ...INBOX_ROWS[3],
    attributes: {
      ...INBOX_ROWS[3].attributes,
      message_ulid: '01HMSG00000000000000000002',
      snippet: 'I will send the first cut tomorrow morning.',
      sent_at: ago(3),
      sender_role: 'creator',
      is_own: true,
    },
  },
]

export function creatorInboxFixtureFor(url, method) {
  const p = url.pathname
  if (method === 'GET' && p === '/api/v1/creators/me/message-inbox') {
    return { data: INBOX_ROWS, meta: { unread_threads_total: 3, next_cursor: null } }
  }
  if (method === 'GET' && p === '/api/v1/creators/me/message-search') {
    return { data: SEARCH_ROWS, meta: { next_cursor: null, per_page: 30 } }
  }
  if (method === 'GET' && p === '/api/v1/creators/me') return creatorProfile(url, method)
  return creatorFixtureFor(url, method)
}
