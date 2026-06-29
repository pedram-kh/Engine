/**
 * Source-inspection regression test (S11.0 Ch3a, D-6) — en/pt/it PARITY for
 * the `notifications.*` bundle.
 *
 * Unlike `i18n-creator-codes.spec.ts` (which walks the BACKEND for error codes
 * that must resolve), `notifications.*` is a UI-only namespace with no backend
 * error codes mapping under it. What must hold instead is locale PARITY: every
 * notification-center string + per-type body template exists in all three
 * locales with an IDENTICAL key-set, so a translator who adds (or drops) a key
 * in one locale can't silently ship a render that falls back to English — or,
 * worse, throws a missing-key warning — in another.
 *
 * It additionally pins the "only the live types are templated + one generic
 * fallback" invariant (D-6): `notifications.types` must contain EXACTLY the
 * live-emit-site templates plus `fallback`, and the FE template-key map
 * (`notificationTemplateKey`) must agree. The emit-less / forward-declared
 * types get NO bespoke template by design — they ride the fallback.
 *
 * Sprint 11 (D-7): the two dual-recipient messaging types each gained a live
 * emit site (SendMessageNotifications), so the live-set grew 8 → 10.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { UI_LOCALES } from '@catalyst/api-client'

import {
  hasLiveTemplate,
  notificationTemplateKey,
  preferenceGroupsForRole,
} from '@/modules/notifications/templates'

const LOCALE_ROOT = path.resolve(__dirname, '../../../src/core/i18n/locales')
// The rendered set is the shared registry (en/pt/it today), so the S8 flip to
// 24 locales extends this parity gate with no edit here.
const LOCALES = UI_LOCALES

/**
 * The 12 notification types with a live emit site (Ch1/Ch2 + Sprint 11 campaign
 * messaging + AH-010 relationship messaging). AH-010 (D5) grew the live-set
 * 10 → 12: the two dual-recipient relationship-message types each gained a live
 * emit site (RelationshipMessageNotifications).
 */
const LIVE_TYPES = [
  'assignment.draft_approved',
  'assignment.revision_requested',
  'assignment.draft_rejected',
  'assignment.manually_verified',
  'assignment.draft_submitted',
  'assignment.contracted',
  'creator.approved',
  'creator.rejected',
  'message.received_by_creator',
  'message.received_by_agency',
  'message.relationship_received_by_creator',
  'message.relationship_received_by_agency',
] as const

/** Emit-less / forward-declared types that MUST route to the fallback. */
const EMIT_LESS_TYPES = [
  'assignment.invited',
  'assignment.declined',
  'assignment.countered',
  'assignment.accepted',
  'assignment.cancelled',
  'assignment.payment_funded',
  'assignment.payment_released',
] as const

interface NotificationsBundle {
  notifications: {
    types: Record<string, unknown>
    [key: string]: unknown
  }
}

async function loadBundle(locale: string): Promise<NotificationsBundle> {
  const file = path.join(LOCALE_ROOT, locale, 'notifications.json')
  return JSON.parse(await fs.readFile(file, 'utf8')) as NotificationsBundle
}

/** Collect every leaf (string-valued) dotted key in a nested message object. */
function collectLeafKeys(node: unknown, prefix = ''): string[] {
  if (typeof node !== 'object' || node === null) {
    return []
  }
  const keys: string[] = []
  for (const [key, value] of Object.entries(node as Record<string, unknown>)) {
    const dotted = prefix === '' ? key : `${prefix}.${key}`
    if (typeof value === 'string') {
      keys.push(dotted)
    } else {
      keys.push(...collectLeafKeys(value, dotted))
    }
  }
  return keys.sort()
}

describe('i18n notifications.* — en/pt/it parity + only-8-templated invariant', () => {
  it('all rendered locales expose an identical key-set', async () => {
    const enKeys = collectLeafKeys(await loadBundle('en'))
    expect(enKeys.length).toBeGreaterThan(0)

    for (const locale of LOCALES) {
      if (locale === 'en') {
        continue
      }
      const keys = collectLeafKeys(await loadBundle(locale))
      expect(keys, `${locale}/notifications.json key-set differs from en`).toEqual(enKeys)
    }
  })

  it('notifications.types holds EXACTLY the 12 live templates + fallback', async () => {
    const en = await loadBundle('en')
    const typeKeys = Object.keys(en.notifications.types).sort()

    const expected = [
      'assignment_contracted',
      'assignment_draft_approved',
      'assignment_draft_rejected',
      'assignment_draft_submitted',
      'assignment_manually_verified',
      'assignment_revision_requested',
      'creator_approved',
      'creator_rejected',
      'fallback',
      'message_received_by_agency',
      'message_received_by_creator',
      'message_relationship_received_by_agency',
      'message_relationship_received_by_creator',
    ]
    expect(typeKeys).toEqual(expected)
  })

  it('every live type maps to a bespoke template key that resolves in the bundle', async () => {
    const en = await loadBundle('en')
    const types = en.notifications.types

    for (const type of LIVE_TYPES) {
      expect(hasLiveTemplate(type)).toBe(true)
      const key = notificationTemplateKey(type)
      const leaf = key.replace('notifications.types.', '')
      expect(types[leaf], `missing template for live type ${type}`).toBeTypeOf('string')
      expect(key).not.toBe('notifications.types.fallback')
    }
  })

  it('every emit-less / forward-declared type routes to the fallback (no bespoke template)', async () => {
    for (const type of EMIT_LESS_TYPES) {
      expect(hasLiveTemplate(type)).toBe(false)
      expect(notificationTemplateKey(type)).toBe('notifications.types.fallback')
    }
  })

  it('a genuinely unknown string (not in the union) also routes to the fallback', () => {
    expect(notificationTemplateKey('totally.made.up.verb')).toBe('notifications.types.fallback')
    expect(hasLiveTemplate('totally.made.up.verb')).toBe(false)
  })
})

/**
 * S11.0 Ch3b + Sprint 11 — the prefs role-partition and the Ch3a template map
 * are ONE source of truth (the `LIVE_TYPES` registry). These pin that they can't
 * drift: the union of the two recipient roles' prefs types is EXACTLY the 10
 * live-template types, the two roles are disjoint, and every prefs-exposed type
 * has a bespoke (non-fallback) template. A type added to / dropped from the
 * registry, or given a recipient that doesn't match its template, fails here.
 *
 * Sprint 11 (D-10) also pins the CHANNEL partition: the `digest` channel is
 * exposed ONLY for the messaging types (the only ones whose digest consumer
 * ships) — never a dead digest toggle on a type the digest job ignores.
 */
describe('notifications prefs role-partition — single live-set source of truth', () => {
  const creatorTypes = preferenceGroupsForRole('creator').flatMap((g) => g.types.map((t) => t.type))
  const agencyTypes = preferenceGroupsForRole('agency').flatMap((g) => g.types.map((t) => t.type))

  it('creator + agency prefs types partition the 12 live types exactly (disjoint, complete)', () => {
    // Disjoint — no type is offered to both roles.
    expect(creatorTypes.filter((t) => agencyTypes.includes(t))).toEqual([])
    // Complete — together they are exactly the LIVE_TYPES set.
    expect([...creatorTypes, ...agencyTypes].sort()).toEqual([...LIVE_TYPES].sort())
  })

  it('the known role split is honest (creator = 8 review/lifecycle/messaging, agency = 4 fan-out/messaging)', () => {
    expect([...creatorTypes].sort()).toEqual(
      [
        'assignment.draft_approved',
        'assignment.draft_rejected',
        'assignment.manually_verified',
        'assignment.revision_requested',
        'creator.approved',
        'creator.rejected',
        'message.received_by_creator',
        'message.relationship_received_by_creator',
      ].sort(),
    )
    expect([...agencyTypes].sort()).toEqual(
      [
        'assignment.contracted',
        'assignment.draft_submitted',
        'message.received_by_agency',
        'message.relationship_received_by_agency',
      ].sort(),
    )
  })

  it('every prefs-exposed type has a bespoke (non-fallback) template — no drift from Ch3a', () => {
    for (const type of [...creatorTypes, ...agencyTypes]) {
      expect(hasLiveTemplate(type)).toBe(true)
      expect(notificationTemplateKey(type)).not.toBe('notifications.types.fallback')
    }
  })

  it('the digest channel is exposed ONLY for the messaging types (D-10 honest channel lift)', () => {
    const rows = [
      ...preferenceGroupsForRole('creator'),
      ...preferenceGroupsForRole('agency'),
    ].flatMap((g) => g.types)

    const withDigest = rows.filter((r) => r.channels.includes('digest')).map((r) => r.type)
    expect(withDigest.sort()).toEqual(
      ['message.received_by_agency', 'message.received_by_creator'].sort(),
    )
    // Every live type still supports the in-app feed.
    expect(rows.every((r) => r.channels.includes('in_app'))).toBe(true)
  })
})
