import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { describe, expect, it } from 'vitest'

import {
  NOTIFICATION_FALLBACK_KEY,
  hasLiveTemplate,
  liveNotificationTypes,
  notificationTemplateKey,
  preferenceGroupsForRole,
} from './templates'

import enNotifications from '@/core/i18n/locales/en/notifications.json'

/**
 * The tripwire that AH-051 needed and did not have.
 *
 * Two relation notification types shipped with real backend emit sites and no
 * registry entries. Nothing pinned the two together, so the suite stayed green
 * while users were served "You have a new notification." for both. These specs
 * make that failure mode impossible: the registry is checked against the
 * backend enum, and every live template key is checked against the locale file.
 */

/**
 * Verbs that are deliberately NOT live: forward-declared in the backend enum
 * (and the api-client union) but with no emitter yet, so they legitimately fall
 * through to the generic fallback.
 *
 * ⚠ This is an ALLOWLIST, not a dumping ground. Adding a verb here asserts "no
 * emit site exists". When you ship the emitter, delete the entry and register a
 * real template — otherwise the notification reaches users as a shrug.
 */
const DEFERRED_WITHOUT_EMITTER = [
  // AH-083 (①) — `assignment.invited` went live (dual-emit invite mail +
  // in-app), so it left this allowlist and gained a real registration below.
  'assignment.declined',
  'assignment.countered',
  'assignment.accepted',
  'assignment.cancelled',
  'assignment.payment_funded',
  'assignment.payment_released',
] as const

/**
 * The backend enum, read from source rather than duplicated here — a hand-copied
 * list would drift in exactly the way this spec exists to prevent.
 */
function backendNotificationTypes(): string[] {
  const enumPath = resolve(
    __dirname,
    '../../../../../apps/api/app/Modules/Notifications/Enums/NotificationType.php',
  )
  const source = readFileSync(enumPath, 'utf8')
  const matches = [...source.matchAll(/case\s+\w+\s*=\s*'([a-z_.]+)'/g)]

  return matches.map((match) => match[1] as string)
}

/** Resolve a dotted i18n key against the locale JSON. */
function translationFor(key: string): string | undefined {
  const segments = key.split('.')
  let node: unknown = enNotifications

  for (const segment of segments) {
    if (typeof node !== 'object' || node === null) {
      return undefined
    }
    node = (node as Record<string, unknown>)[segment]
  }

  return typeof node === 'string' ? node : undefined
}

describe('notification registry parity', () => {
  it('reads a non-empty backend enum (the spec is actually looking at something)', () => {
    // Guards against the parity assertions passing vacuously if the enum file
    // is moved or its `case` syntax changes.
    expect(backendNotificationTypes().length).toBeGreaterThan(10)
  })

  it('every backend type is either live or explicitly deferred', () => {
    const deferred = new Set<string>(DEFERRED_WITHOUT_EMITTER)

    const unaccounted = backendNotificationTypes().filter(
      (type) => !hasLiveTemplate(type) && !deferred.has(type),
    )

    // The exact failure AH-051 shipped: a live type with no registry entry.
    expect(unaccounted).toEqual([])
  })

  it('every live type exists in the backend enum (no phantom registrations)', () => {
    const backend = new Set(backendNotificationTypes())
    const phantom = liveNotificationTypes().filter((type) => !backend.has(type))

    expect(phantom).toEqual([])
  })

  it('every deferred verb really is absent from the registry', () => {
    // Keeps the allowlist honest: once a verb goes live it must leave this list.
    const stillLive = DEFERRED_WITHOUT_EMITTER.filter((type) => hasLiveTemplate(type))

    expect(stillLive).toEqual([])
  })

  it('every live template key resolves to a real, non-empty translation', () => {
    const unresolved = liveNotificationTypes()
      .map((type) => notificationTemplateKey(type))
      .filter((key) => {
        const value = translationFor(key)
        return value === undefined || value.trim() === ''
      })

    expect(unresolved).toEqual([])
  })

  it('no live type silently resolves to the fallback template', () => {
    const fellBack = liveNotificationTypes().filter(
      (type) => notificationTemplateKey(type) === NOTIFICATION_FALLBACK_KEY,
    )

    expect(fellBack).toEqual([])
  })
})

describe('AH-068 draft round on the review-cycle rows', () => {
  /** The five verbs whose payloads gain the round (D4 + Q4's draft_rejected). */
  const ROUND_BEARING = [
    'assignment.draft_submitted',
    'assignment.draft_approved',
    'assignment.revision_requested',
    'assignment.draft_rejected',
  ] as const

  function listenerSource(): string {
    return readFileSync(
      resolve(
        __dirname,
        '../../../../../apps/api/app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php',
      ),
      'utf8',
    )
  }

  /**
   * The payload key, pinned by name across the two languages that have to agree
   * on it (the AH-058 Q8 precedent). The backend writes `data['version']`; the
   * NotificationCenter reads `data.version`. Renaming either side alone reds here
   * instead of silently dropping the round from every row.
   */
  it('the backend writes the round under the key `version`', () => {
    expect(listenerSource()).toContain("$data['version'] = $round;")
  })

  /** Q2 — a direct machine call cannot invent a round number. */
  it('the backend omits the key entirely when the transition carried no version', () => {
    const source = listenerSource()

    // The guard returns the payload untouched rather than writing a null.
    expect(source).toContain('if ($round === null) {')
    expect(source).not.toContain("$data['version'] = null")
  })

  /**
   * Q1(a), enforced structurally rather than by memory. Every notification row
   * written before AH-068 was stored with no `version` in its `data` bag, and
   * `bodyText` spreads that bag as the template's named params. The moment one of
   * these templates references `{version}`, every one of those rows renders with
   * a hole — so the round belongs in its own element, and this reds if anyone
   * "simplifies" it back into the copy.
   */
  it('no round-bearing body template interpolates {version}', () => {
    const offenders = ROUND_BEARING.filter((type) => {
      const template = translationFor(notificationTemplateKey(type))
      return template !== undefined && template.includes('{version}')
    })

    expect(offenders).toEqual([])
  })

  it('the round label is its own key, present and interpolating the number', () => {
    const label = translationFor('notifications.center.round')

    expect(label).toBeTypeOf('string')
    expect(label).toContain('{n}')
  })

  it('every round-bearing verb is live — the round rides real templates, not fallbacks', () => {
    for (const type of ROUND_BEARING) {
      expect(hasLiveTemplate(type), `${type} is not live`).toBe(true)
      expect(notificationTemplateKey(type)).not.toBe(NOTIFICATION_FALLBACK_KEY)
    }
  })
})

describe('AH-051 relation notifications', () => {
  it('renders a bespoke template for admin-connected, naming the agency', () => {
    const key = notificationTemplateKey('agency_creator_relation.admin_connected')

    expect(key).not.toBe(NOTIFICATION_FALLBACK_KEY)
    expect(translationFor(key)).toContain('{agency_name}')
  })

  it('renders a bespoke template for disconnected, naming the counterparty', () => {
    const key = notificationTemplateKey('agency_creator_relation.disconnected')

    expect(key).not.toBe(NOTIFICATION_FALLBACK_KEY)
    // Direction-agnostic: the same verb reaches both sides, so the template can
    // only interpolate the counterparty — never a role-specific noun.
    expect(translationFor(key)).toContain('{counterparty_name}')
  })

  it('offers NO preference toggle for either relation type (always-on)', () => {
    const exposed = [...preferenceGroupsForRole('creator'), ...preferenceGroupsForRole('agency')]
      .flatMap((group) => group.types)
      .map((view) => view.type)

    expect(exposed).not.toContain('agency_creator_relation.admin_connected')
    expect(exposed).not.toContain('agency_creator_relation.disconnected')
  })

  it('keeps the existing toggles intact after the always-on refactor', () => {
    // The prefs UI is derived from the same registry the relation types joined;
    // a mistake in the `preference: null` plumbing would silently empty it.
    const creatorTypes = preferenceGroupsForRole('creator').flatMap((group) =>
      group.types.map((view) => view.type),
    )
    const agencyTypes = preferenceGroupsForRole('agency').flatMap((group) =>
      group.types.map((view) => view.type),
    )

    expect(creatorTypes).toContain('creator.approved')
    expect(creatorTypes).toContain('message.relationship_received_by_creator')
    expect(agencyTypes).toContain('assignment.draft_submitted')
    expect(agencyTypes).toContain('message.received_by_agency')

    // Role partition still holds — no creator-only toggle leaks to agencies.
    expect(agencyTypes).not.toContain('creator.approved')
  })
})
