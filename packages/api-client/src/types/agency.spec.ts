/**
 * Unit tests for `deriveConnectionState` — the single source of truth that maps
 * a calling-agency-only `relationship_status` onto the discovery SEND/annotation
 * affordance (see `DiscoverPage.vue` / `DiscoverProfilePage.vue`).
 *
 * The full status matrix is pinned here so a future enum addition cannot slip
 * silently into the `none` ("never connected — Send request") bucket. In
 * particular AH-051 (D-3) `ended` MUST derive to a truthful `ended`
 * ("Previously connected"), never to `none`.
 */

import { describe, expect, it } from 'vitest'

import type { DiscoveryConnectionState, DiscoveryRelationshipStatus } from './agency'
import { deriveConnectionState } from './agency'

describe('deriveConnectionState', () => {
  it.each<[DiscoveryRelationshipStatus | null, DiscoveryConnectionState]>([
    ['roster', 'connected'],
    ['pending_request', 'pending'],
    ['declined', 'declined'],
    ['ended', 'ended'],
    ['prospect', 'none'],
    ['external', 'none'],
    [null, 'none'],
  ])('maps %s → %s', (status, expected) => {
    expect(deriveConnectionState(status)).toBe(expected)
  })

  // AH-051 (D-3) regression guard: `ended` is a SEVERED prior connection, not a
  // never-connected pair. It must NOT fall through to `none` (which would render
  // as "Send request" and read as if the agency had never been connected).
  it('does NOT derive `ended` to `none` (truthful "Previously connected")', () => {
    expect(deriveConnectionState('ended')).not.toBe('none')
    expect(deriveConnectionState('ended')).toBe('ended')
  })
})
