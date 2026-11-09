/**
 * AH-115 L2 — mock API for the CREATOR messaging pages (the inbox and a relationship thread),
 * used by `shots-ah115-l2.mjs`. Everything else falls through to the shared fixtures.
 * Dev tool only. Fixture text avoids the banned vocabulary.
 */

import { fixtureFor } from './l3-fixtures.mjs'

export const CREATOR_AGENCY = '01HWALKAGENCY000000000000'

const ago = (days, minutes = 0) =>
  new Date(Date.now() - days * 86_400_000 - minutes * 60_000).toISOString()

const AGENCIES = [
  [
    CREATOR_AGENCY,
    'Northwind Agency',
    ago(0, 25),
    'Perfect, send the raw footage when it is ready',
    2,
  ],
  [
    '01HWALKAGENCY000000000002',
    'Lumen Collective',
    ago(1, 40),
    'Thanks for the update, speak soon',
    0,
  ],
  ['01HWALKAGENCY000000000003', 'Aster Talent', ago(9), 'Contract signed and returned', 0],
]

function message(id, at, body, own) {
  return {
    id,
    type: 'relationship_message',
    attributes: {
      kind: 'text',
      sender_role: own ? 'creator' : 'agency_user',
      body,
      attachments: [],
      is_own: own,
      sender: { name: own ? 'Maya' : 'Jordan' },
      read_by_counterparty: own ? true : null,
      created_at: at,
    },
  }
}

export function creatorFixtureFor(url, method) {
  const p = url.pathname
  if (method === 'GET' && p === '/api/v1/creators/me/relationship-threads') {
    return {
      data: AGENCIES.map(([id, name, at, preview, unread]) => ({
        id: `thread-${id}`,
        type: 'relationship_thread',
        attributes: {
          agency: { id, name, logo_path: null, logo_url: null },
          last_message_at: at,
          last_message_preview: preview,
          unread_count: unread,
        },
      })),
    }
  }
  const base = `/api/v1/creators/me/relationship-threads/${CREATOR_AGENCY}`
  if (p === `${base}/messages/read`) return { meta: { marked: 0, unread_count: 0 } }
  if (method === 'GET' && p === `${base}/messages`) {
    return {
      data: [
        message(
          'm1',
          ago(1, 95),
          'Hi Maya, the brief is attached. Three hook variations, each under a minute.',
          false,
        ),
        message('m2', ago(1, 70), 'Thank you! Should I film all three in the same outfit?', true),
        message('m3', ago(1, 50), 'Switch it up, a different look for each one works best.', false),
        message(
          'm4',
          ago(0, 25),
          'Perfect, I will film them tomorrow morning and send the footage by end of day.',
          true,
        ),
      ],
      meta: {
        thread: {
          id: 'thread-x',
          last_message_at: ago(0, 25),
          unread_count: 0,
          can_send: true,
          closed_reason: null,
        },
        has_more: false,
      },
    }
  }
  return fixtureFor(url, method)
}
