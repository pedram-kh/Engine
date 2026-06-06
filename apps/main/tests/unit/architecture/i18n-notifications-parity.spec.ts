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
 * It additionally pins the "only the 8 live types are templated + one generic
 * fallback" invariant (D-6): `notifications.types` must contain EXACTLY the 8
 * live-emit-site templates plus `fallback`, and the FE template-key map
 * (`notificationTemplateKey`) must agree. The 7 emit-less / forward-declared
 * types get NO bespoke template by design — they ride the fallback.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import {
  hasLiveTemplate,
  notificationTemplateKey,
  preferenceGroupsForRole,
} from '@/modules/notifications/templates'

const LOCALE_ROOT = path.resolve(__dirname, '../../../src/core/i18n/locales')
const LOCALES = ['en', 'pt', 'it'] as const

/** The 8 notification types with a live emit site (Ch1/Ch2 reviews). */
const LIVE_TYPES = [
  'assignment.draft_approved',
  'assignment.revision_requested',
  'assignment.draft_rejected',
  'assignment.manually_verified',
  'assignment.draft_submitted',
  'assignment.contracted',
  'creator.approved',
  'creator.rejected',
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

async function loadBundle(locale: (typeof LOCALES)[number]): Promise<NotificationsBundle> {
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
  it('all three locales expose an identical key-set', async () => {
    const [en, pt, it] = await Promise.all(LOCALES.map(loadBundle))
    const enKeys = collectLeafKeys(en)
    const ptKeys = collectLeafKeys(pt)
    const itKeys = collectLeafKeys(it)

    expect(enKeys.length).toBeGreaterThan(0)
    expect(ptKeys).toEqual(enKeys)
    expect(itKeys).toEqual(enKeys)
  })

  it('notifications.types holds EXACTLY the 8 live templates + fallback', async () => {
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
 * S11.0 Ch3b — the prefs role-partition and the Ch3a template map are ONE source
 * of truth (the `LIVE_TYPES` registry). These pin that they can't drift: the
 * union of the two recipient roles' prefs types is EXACTLY the 8 live-template
 * types, the two roles are disjoint, and every prefs-exposed type has a bespoke
 * (non-fallback) template. A type added to / dropped from the registry, or given
 * a recipient that doesn't match its template, fails here.
 */
describe('notifications prefs role-partition — single live-set source of truth', () => {
  const creatorTypes = preferenceGroupsForRole('creator').flatMap((g) => g.types)
  const agencyTypes = preferenceGroupsForRole('agency').flatMap((g) => g.types)

  it('creator + agency prefs types partition the 8 live types exactly (disjoint, complete)', () => {
    // Disjoint — no type is offered to both roles.
    expect(creatorTypes.filter((t) => agencyTypes.includes(t))).toEqual([])
    // Complete — together they are exactly the LIVE_TYPES set.
    expect([...creatorTypes, ...agencyTypes].sort()).toEqual([...LIVE_TYPES].sort())
  })

  it('the known role split is honest (creator = 6 review/lifecycle, agency = 2 fan-out)', () => {
    expect([...creatorTypes].sort()).toEqual(
      [
        'assignment.draft_approved',
        'assignment.draft_rejected',
        'assignment.manually_verified',
        'assignment.revision_requested',
        'creator.approved',
        'creator.rejected',
      ].sort(),
    )
    expect([...agencyTypes].sort()).toEqual(['assignment.contracted', 'assignment.draft_submitted'])
  })

  it('every prefs-exposed type has a bespoke (non-fallback) template — no drift from Ch3a', () => {
    for (const type of [...creatorTypes, ...agencyTypes]) {
      expect(hasLiveTemplate(type)).toBe(true)
      expect(notificationTemplateKey(type)).not.toBe('notifications.types.fallback')
    }
  })
})
