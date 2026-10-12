/**
 * Source-inspection parity test (AH-102b): the admin Push deliveries type
 * filter lists exactly the notification types that can reach the push log, and
 * every one has a readable label in every locale.
 *
 * The backend owns the set: `PushEligibility::TOGGLEABLE` + `ALWAYS_ON`
 * (`NotificationType::X` lines; the value of each case is in the
 * `NotificationType` enum). If someone makes a new type push-eligible, this
 * fails until `PUSH_NOTIFICATION_TYPES` and the labels follow, so the filter
 * can never silently omit a type an admin needs to find.
 */

import { readFileSync, readdirSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import {
  PUSH_NOTIFICATION_TYPES,
  pushNotificationTypeLabelKey,
} from '@/modules/operations/api/pushDeliveries.api'

const ADMIN_ROOT = path.resolve(__dirname, '../../..')
const REPO_ROOT = path.resolve(ADMIN_ROOT, '../..')
const NOTIFICATIONS = path.join(REPO_ROOT, 'apps/api/app/Modules/Notifications')
const LOCALES = path.join(ADMIN_ROOT, 'src/core/i18n/locales')

function enumValues(): Map<string, string> {
  const src = readFileSync(path.join(NOTIFICATIONS, 'Enums/NotificationType.php'), 'utf8')
  const map = new Map<string, string>()
  for (const m of src.matchAll(/^\s*case\s+(\w+)\s*=\s*'([^']+)'\s*;/gm)) {
    map.set(m[1]!, m[2]!)
  }
  return map
}

function pushEligibleValues(): string[] {
  const src = readFileSync(path.join(NOTIFICATIONS, 'Push/PushEligibility.php'), 'utf8')
  const values = enumValues()
  const out: string[] = []
  for (const list of ['TOGGLEABLE', 'ALWAYS_ON']) {
    const block = new RegExp(`const array ${list} = \\[([\\s\\S]*?)\\];`).exec(src)
    expect(block, `${list} list not found in PushEligibility.php`).not.toBeNull()
    for (const m of block![1]!.matchAll(/NotificationType::(\w+)/g)) {
      const value = values.get(m[1]!)
      expect(value, `unknown enum case ${m[1]}`).toBeDefined()
      out.push(value!)
    }
  }
  return out
}

describe('push notification types (admin filter)', () => {
  it('PUSH_NOTIFICATION_TYPES is exactly the backend push-eligible set', () => {
    const backend = pushEligibleValues()
    expect(backend.length).toBeGreaterThan(0)
    expect([...PUSH_NOTIFICATION_TYPES].sort()).toEqual([...backend].sort())
  })

  it('every type has a readable label in every locale', () => {
    for (const locale of readdirSync(LOCALES)) {
      const json = JSON.parse(readFileSync(path.join(LOCALES, locale, 'operations.json'), 'utf8'))
      const types = json.admin.operations.push_deliveries.types as Record<string, string>
      for (const type of PUSH_NOTIFICATION_TYPES) {
        const key = pushNotificationTypeLabelKey(type).split('.').pop()!
        expect(types[key], `${locale}: ${key}`).toEqual(expect.any(String))
        expect(types[key]!.trim().length, `${locale}: ${key}`).toBeGreaterThan(0)
      }
    }
  })
})
