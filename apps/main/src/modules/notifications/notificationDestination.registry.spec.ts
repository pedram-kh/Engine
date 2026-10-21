import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { createMemoryHistory } from 'vue-router'
import { describe, expect, it } from 'vitest'

import {
  liveNotificationTypes,
  notificationDestination,
  notificationRecipient,
  type NotificationDestination,
} from './templates'

import { createRouter } from '@/core/router'
import { CAMPAIGN_DETAIL_TABS } from '@/modules/campaigns/campaignTabs'

/**
 * AH-103 C2.1 — the registry's destinations, pinned to the shared contract
 * (`packages/api-client/contracts/notification-route-keys.json`, the SAME file the
 * backend's Pest parity test reads) and to the real route table.
 *
 * Three ways a destination can be silently wrong, one block each:
 *   1. it reads an id the emitter never writes (a tap that always falls back);
 *   2. it points a creator at an agency screen or the reverse;
 *   3. it names a route that was renamed, or omits a param the route needs.
 */

interface ContractType {
  bag: string[]
  push: string[]
}

const contract = JSON.parse(
  readFileSync(
    resolve(__dirname, '../../../../../packages/api-client/contracts/notification-route-keys.json'),
    'utf8',
  ),
) as { types: Record<string, ContractType> }

const CONTRACT_TYPES = Object.keys(contract.types)

/** The id keys a destination reads off the notification. */
function idKeysRead(destination: NotificationDestination): string[] {
  switch (destination.kind) {
    case 'route':
      return Object.values(destination.params ?? {})
    case 'thread':
      return ['thread_ulid']
    case 'list':
      return []
  }
}

describe('registry ⇄ contract parity', () => {
  it('reads a non-empty contract (the spec is looking at something)', () => {
    expect(CONTRACT_TYPES.length).toBeGreaterThanOrEqual(21)
  })

  it('the registry and the contract list exactly the same types', () => {
    expect([...liveNotificationTypes()].sort()).toEqual([...CONTRACT_TYPES].sort())
  })

  it.each(CONTRACT_TYPES)('%s: declares a destination', (type) => {
    expect(notificationDestination(type)).not.toBeNull()
  })

  it.each(CONTRACT_TYPES)(
    '%s: reads only ids its emitter writes, and uses every id the contract says it carries',
    (type) => {
      const destination = notificationDestination(type)!
      const read = idKeysRead(destination).sort()
      const bag = [...contract.types[type]!.bag].sort()

      // (1) never read an id the bag does not have.
      for (const key of read) {
        expect(bag, `${type} reads ${key} but its emitter never writes it`).toContain(key)
      }
      // (2) an emitted id that the destination ignores is dead data: the contract
      //     would be promising something no tap uses.
      expect(read, `${type}: the contract carries ${bag.join(',') || 'no ids'}`).toEqual(bag)
    },
  )

  it('an unknown type has no destination', () => {
    expect(notificationDestination('assignment.declined')).toBeNull()
    expect(notificationDestination('nonsense')).toBeNull()
  })
})

describe('tab parity — a destination only names a tab the campaign page opens', () => {
  it.each(CONTRACT_TYPES)('%s', (type) => {
    const destination = notificationDestination(type)!
    const tab = destination.kind === 'route' ? destination.query?.tab : undefined

    if (tab !== undefined) {
      expect(destination.kind === 'route' && destination.name).toBe('campaigns.detail')
      expect(CAMPAIGN_DETAIL_TABS as readonly string[]).toContain(tab)
    }
  })

  it('the four agency destinations each name a tab (they are the reason ?tab= exists)', () => {
    const tabs = [
      'assignment.draft_submitted',
      'assignment.contracted',
      'message.received_by_agency',
      'campaign_application.submitted',
    ].map((type) => {
      const d = notificationDestination(type)!
      return d.kind === 'route' ? d.query?.tab : undefined
    })

    expect(tabs).toEqual(['drafts', 'creators', 'messages', 'applications'])
  })
})

describe('recipient parity — a notification never opens the other app', () => {
  const agencyRoutes = new Set(['campaigns.detail', 'messages.thread', 'messages.inbox'])

  it.each(CONTRACT_TYPES)('%s: the destination belongs to the recipient', (type) => {
    const recipient = notificationRecipient(type)
    const destination = notificationDestination(type)!

    if (destination.kind === 'list') {
      expect(recipient).toBe('both') // the only deliberately destination-less type reaches both sides
      return
    }

    if (destination.kind === 'thread') {
      expect(destination.side).toBe(recipient)
      return
    }

    if (recipient === 'creator') {
      expect(destination.name.startsWith('creator.'), `${type} → ${destination.name}`).toBe(true)
    } else {
      expect(recipient).toBe('agency')
      expect(agencyRoutes.has(destination.name), `${type} → ${destination.name}`).toBe(true)
    }
  })
})

describe('route-table parity — every destination resolves against the REAL router', () => {
  const router = createRouter(() => createMemoryHistory())
  const ULID = '01J9ZK3Q8M5T7WXYABCDEFGHJK'

  /** Sample params for a destination: every declared param, plus the thread routes' own. */
  function concrete(
    destination: NotificationDestination,
  ): { name: string; params: Record<string, string>; query?: Record<string, string> }[] {
    switch (destination.kind) {
      case 'list':
        return []
      case 'thread':
        return destination.side === 'creator'
          ? [{ name: 'creator.messages.thread', params: { agencyUlid: ULID } }]
          : [{ name: 'messages.thread', params: { creatorUlid: ULID } }]
      case 'route':
        return [
          {
            name: destination.name,
            params: Object.fromEntries(Object.keys(destination.params ?? {}).map((p) => [p, ULID])),
            ...(destination.query !== undefined ? { query: destination.query } : {}),
          },
        ]
    }
  }

  it.each(CONTRACT_TYPES)('%s: the route exists and the declared params satisfy it', (type) => {
    for (const target of concrete(notificationDestination(type)!)) {
      expect(router.hasRoute(target.name), `${type}: no route named ${target.name}`).toBe(true)

      // `resolve` THROWS on a missing required param — so a destination that omits
      // one (or renames it) fails here rather than on a user's phone.
      const resolved = router.resolve(target)

      expect(resolved.name).toBe(target.name)
      if (Object.keys(target.params).length > 0) {
        expect(resolved.fullPath).toContain(ULID)
      }
    }
  })

  it('the thread routes the resolver builds exist with the param names it uses', () => {
    expect(
      router.resolve({ name: 'creator.messages.thread', params: { agencyUlid: ULID } }).fullPath,
    ).toContain(ULID)
    expect(
      router.resolve({ name: 'messages.thread', params: { creatorUlid: ULID } }).fullPath,
    ).toContain(ULID)
  })
})
