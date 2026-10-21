import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { describe, expect, it } from 'vitest'

import {
  NATIVE_ONLY_CHANNELS,
  NOTIFICATION_FALLBACK_KEY,
  hasLiveTemplate,
  liveNotificationTypes,
  notificationDestination,
  notificationRecipient,
  notificationTemplateKey,
  PREFERENCE_CHANNEL_ORDER,
  preferenceColumns,
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
  // `assignment.cancelled` went live (AH-104b-del B6: the erased-creator notice).
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

describe('AH-101 preference matrix registry', () => {
  /** Every (type, channel) pair the registry offers as a SWITCH, for a role. */
  function switches(role: 'creator' | 'agency'): string[] {
    return preferenceGroupsForRole(role).flatMap((group) =>
      group.types.flatMap((view) => view.channels.map((channel) => `${view.type}::${channel}`)),
    )
  }

  /**
   * The backend send sites that read the recipient's `email` preference
   * (`NotificationService::isEmailEnabled`) — pinned from the other side by the
   * backend's `EmailPreferenceGuardTest`. An `email` cell may exist ONLY for a
   * type listed here; adding a cell without wiring the guard is a dead control.
   */
  const EMAIL_GUARDED = [
    'assignment.invited',
    'assignment.completed_on_approval',
    'assignment.manually_verified',
    'assignment.live_verified',
    'assignment.draft_approved',
    'assignment.revision_requested',
    'assignment.draft_rejected',
    'message.received_by_creator',
    'message.relationship_received_by_creator',
    'campaign.job_posted',
    'campaign_application.submitted',
    'campaign_application.accepted',
    'campaign_application.rejected',
  ]

  it('offers an email switch for exactly the guarded types', () => {
    const emailTypes = [...switches('creator'), ...switches('agency')]
      .filter((key) => key.endsWith('::email'))
      .map((key) => key.split('::')[0])

    expect([...emailTypes].sort()).toEqual([...EMAIL_GUARDED].sort())
  })

  it('never offers an email switch for the agency-inviter mails (Q3) or the account-lifecycle mails (Q2)', () => {
    const all = [...switches('creator'), ...switches('agency')]

    for (const type of [
      'assignment.draft_submitted',
      'assignment.contracted',
      'creator.approved',
      'creator.rejected',
      'agency_creator_relation.admin_connected',
      'agency_creator_relation.disconnected',
    ]) {
      expect(all).not.toContain(`${type}::email`)
    }
  })

  it('shows creator.approved / creator.rejected email as always-on, never as a switch', () => {
    const creatorGroup = preferenceGroupsForRole('creator').find((g) => g.group === 'creator')

    expect(creatorGroup?.types.map((v) => v.type).sort()).toEqual([
      'creator.approved',
      'creator.rejected',
    ])
    for (const view of creatorGroup?.types ?? []) {
      expect(view.channels).toEqual(['in_app'])
      expect(view.alwaysOn).toEqual(['email'])
    }
  })

  it('keeps digest on the campaign message type only', () => {
    const digest = [...switches('creator'), ...switches('agency')].filter((key) =>
      key.endsWith('::digest'),
    )

    expect(digest.sort()).toEqual([
      'message.received_by_agency::digest',
      'message.received_by_creator::digest',
    ])
  })

  it("orders every row's channels by the single column constant", () => {
    for (const role of ['creator', 'agency'] as const) {
      for (const group of preferenceGroupsForRole(role)) {
        for (const view of group.types) {
          const ranks = view.channels.map((c) => PREFERENCE_CHANNEL_ORDER.indexOf(c))
          expect(ranks).toEqual([...ranks].sort((a, b) => a - b))
          expect(ranks.every((r) => r >= 0)).toBe(true)
        }
      }
    }
  })

  it('derives columns from the union of channels — a column exists only when a row fills it', () => {
    const groups = preferenceGroupsForRole('creator')
    const byName = (name: string) => groups.filter((g) => g.group === name)

    expect(preferenceColumns(byName('assignment'))).toEqual(['in_app', 'email'])
    expect(preferenceColumns(byName('messaging'))).toEqual(['in_app', 'email', 'digest'])
    expect(preferenceColumns([])).toEqual([])
  })

  it('adding a channel to one type adds its column with no other change (the push seam)', () => {
    // The shape AH-102 relies on: `push` is a constant entry + an enum case.
    // Simulated here with a synthetic group, since no live type lists it yet.
    const columns = preferenceColumns([
      {
        group: 'assignment',
        types: [
          { type: 'assignment.invited', channels: ['push', 'in_app'] as never, alwaysOn: [] },
        ],
      },
    ])

    // An unknown channel sorts after the known ones rather than breaking the grid.
    expect(columns).toEqual(['in_app', 'push'])
  })
})

/**
 * AH-102 (C3.1) — the PUSH column. The registry and the backend's
 * `PushEligibility` must name the same types: a `push` cell for a type the
 * dispatcher never pushes is a dead control, and a pushed type with no cell is a
 * notification the creator cannot silence. Like the enum above, the backend
 * lists are READ FROM SOURCE, not copied.
 */
describe('AH-102 push column registry', () => {
  const root = resolve(__dirname, '../../../../../apps/api/app/Modules/Notifications')

  /** Backend enum case name → wire value, from source. */
  function enumValueByCase(): Map<string, string> {
    const source = readFileSync(resolve(root, 'Enums/NotificationType.php'), 'utf8')
    return new Map(
      [...source.matchAll(/case\s+(\w+)\s*=\s*'([a-z_.]+)'/g)].map((m) => [
        m[1] as string,
        m[2] as string,
      ]),
    )
  }

  /** One of `PushEligibility`'s `public const array NAME = [ NotificationType::X, … ]` lists. */
  function eligibilityList(name: 'TOGGLEABLE' | 'ALWAYS_ON'): string[] {
    const source = readFileSync(resolve(root, 'Push/PushEligibility.php'), 'utf8')
    const body = new RegExp(`const array ${name} = \\[([\\s\\S]*?)\\];`).exec(source)?.[1] ?? ''
    const values = enumValueByCase()

    return [...body.matchAll(/NotificationType::(\w+)/g)].map((m) => {
      const value = values.get(m[1] as string)
      if (value === undefined) {
        throw new Error(`PushEligibility names an unknown NotificationType case: ${m[1]}`)
      }
      return value
    })
  }

  const NATIVE = { includeNativeOnly: true } as const

  /** Types offering a `push` SWITCH, across both roles, native shape. */
  function pushSwitchTypes(): string[] {
    return (['creator', 'agency'] as const).flatMap((role) =>
      preferenceGroupsForRole(role, NATIVE).flatMap((g) =>
        g.types.filter((v) => v.channels.includes('push')).map((v) => v.type as string),
      ),
    )
  }

  /** Types showing a `push` ALWAYS-ON caption, across both roles, native shape. */
  function pushAlwaysOnTypes(): string[] {
    return (['creator', 'agency'] as const).flatMap((role) =>
      preferenceGroupsForRole(role, NATIVE).flatMap((g) =>
        g.types.filter((v) => v.alwaysOn.includes('push')).map((v) => v.type as string),
      ),
    )
  }

  it('reads the backend lists (the spec is looking at the ruled sets: 10 toggleable, 4 always-on)', () => {
    expect(eligibilityList('TOGGLEABLE')).toHaveLength(10)
    expect(eligibilityList('ALWAYS_ON')).toHaveLength(4)
  })

  it('offers a push switch for exactly PushEligibility::TOGGLEABLE — no more, no fewer', () => {
    expect([...pushSwitchTypes()].sort()).toEqual([...eligibilityList('TOGGLEABLE')].sort())
    expect(pushSwitchTypes()).toHaveLength(10)
  })

  it('shows push as an always-on caption for the ALWAYS_ON types that HAVE a matrix row, and registers the rest as toggle-less', () => {
    const alwaysOn = eligibilityList('ALWAYS_ON')

    // creator.approved / creator.rejected have a matrix row, so they carry the caption…
    expect([...pushAlwaysOnTypes()].sort()).toEqual(['creator.approved', 'creator.rejected'])
    // …the two relation types are always-on with NO row at all (`preference: null`),
    // so they appear in neither list — but must still be live, creator-reaching types.
    const noRow = alwaysOn.filter((type) => !pushAlwaysOnTypes().includes(type))
    expect([...noRow].sort()).toEqual([
      'agency_creator_relation.admin_connected',
      'agency_creator_relation.disconnected',
    ])
    for (const type of noRow) {
      expect(liveNotificationTypes()).toContain(type)
    }
    expect(pushAlwaysOnTypes()).toHaveLength(2)
    expect(alwaysOn).toHaveLength(2 + noRow.length)
  })

  it('never lists a type as BOTH a push switch and an always-on push caption (disjoint)', () => {
    const overlap = pushSwitchTypes().filter((type) => pushAlwaysOnTypes().includes(type))
    expect(overlap).toEqual([])
  })

  it('gives no push cell to a type the dispatcher will not push (live_verified, application.rejected, every agency type)', () => {
    const pushed = new Set([...eligibilityList('TOGGLEABLE'), ...eligibilityList('ALWAYS_ON')])
    const cells = new Set([...pushSwitchTypes(), ...pushAlwaysOnTypes()])

    for (const type of [
      'assignment.live_verified',
      'campaign_application.rejected',
      'assignment.draft_submitted',
      'assignment.contracted',
      'message.received_by_agency',
      'message.relationship_received_by_agency',
      'campaign_application.submitted',
    ]) {
      expect(pushed.has(type)).toBe(false)
      expect(cells.has(type)).toBe(false)
    }
    // …and no agency user sees any push cell at all.
    expect(
      preferenceGroupsForRole('agency', NATIVE).flatMap((g) =>
        g.types.flatMap((v) => [...v.channels, ...v.alwaysOn]),
      ),
    ).not.toContain('push')
  })

  it('WEB default: no push cell, switch or caption, for either role (the pre-AH-102 shape)', () => {
    for (const role of ['creator', 'agency'] as const) {
      const cells = preferenceGroupsForRole(role).flatMap((g) =>
        g.types.flatMap((v) => [...v.channels, ...v.alwaysOn]),
      )
      expect(cells).not.toContain('push')
      // The explicit `false` is the same thing as the default.
      expect(preferenceGroupsForRole(role, { includeNativeOnly: false })).toEqual(
        preferenceGroupsForRole(role),
      )
    }
  })

  it('native is purely ADDITIVE: strip push from the native groups and you get the web groups exactly', () => {
    for (const role of ['creator', 'agency'] as const) {
      const stripped = preferenceGroupsForRole(role, NATIVE).map((g) => ({
        ...g,
        types: g.types.map((v) => ({
          ...v,
          channels: v.channels.filter((c) => c !== 'push'),
          alwaysOn: v.alwaysOn.filter((c) => c !== 'push'),
        })),
      }))
      expect(stripped).toEqual(preferenceGroupsForRole(role))
    }
  })

  it('puts push LAST in the column order, and a native group gains the column only where a row fills it', () => {
    expect(PREFERENCE_CHANNEL_ORDER[PREFERENCE_CHANNEL_ORDER.length - 1]).toBe('push')

    const groups = preferenceGroupsForRole('creator', NATIVE)
    const columns = (name: string) => preferenceColumns(groups.filter((g) => g.group === name))

    expect(columns('assignment')).toEqual(['in_app', 'email', 'push'])
    expect(columns('jobs_board')).toEqual(['in_app', 'email', 'push'])
    expect(columns('creator')).toEqual(['in_app', 'email', 'push'])
    expect(columns('messaging')).toEqual(['in_app', 'email', 'digest', 'push'])
  })

  it('pins the native-only set to exactly `push` (a new native-only channel is a deliberate edit)', () => {
    expect([...NATIVE_ONLY_CHANNELS]).toEqual(['push'])
  })
})

describe('assignment.cancelled — the erased-creator notice (AH-104b-del B6)', () => {
  it('is live, with its own template (not the fallback)', () => {
    expect(hasLiveTemplate('assignment.cancelled')).toBe(true)
    expect(notificationTemplateKey('assignment.cancelled')).toBe(
      'notifications.types.assignment_cancelled',
    )
    expect(translationFor('notifications.types.assignment_cancelled')).toContain('{campaign_name}')
  })

  it('reaches the agency only, and is always-on (no toggle for either role, no push cell)', () => {
    expect(notificationRecipient('assignment.cancelled')).toBe('agency')
    for (const role of ['creator', 'agency'] as const) {
      const types = preferenceGroupsForRole(role, { includeNativeOnly: true }).flatMap((g) =>
        g.types.map((t) => t.type),
      )
      expect(types).not.toContain('assignment.cancelled')
    }
  })

  it('opens the campaign on its creators tab', () => {
    expect(notificationDestination('assignment.cancelled')).toEqual({
      kind: 'route',
      name: 'campaigns.detail',
      params: { ulid: 'campaign_ulid' },
      query: { tab: 'creators' },
    })
  })

  it('the reason code has a localised line, and the template names no creator', () => {
    expect(
      (enNotifications.notifications.center as Record<string, unknown>).reasons,
    ).toHaveProperty('creator_account_erased')
    expect(translationFor('notifications.types.assignment_cancelled')).not.toContain(
      '{creator_name}',
    )
  })
})
