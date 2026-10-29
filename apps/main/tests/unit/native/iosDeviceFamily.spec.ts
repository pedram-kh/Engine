// @vitest-environment node
/**
 * AH-105 S1 (D1) — the iOS app is iPhone-only, pinned.
 *
 * Why it can rot silently: the device family lives in two places of the Xcode
 * project (Debug and Release of the app target) and a `cap sync` or a template
 * refresh can put `"1,2"` back. An iPad-capable build means iPad screenshots, an
 * iPad review and a phone-first UI on a tablet, none of which the store
 * submission plans for (docs/reviews/store-prep-plan.md §2).
 *
 * An iPhone-only app still installs on an iPad in compatibility mode, so a
 * tester on an iPad is not locked out.
 */
import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { APP_ROOT } from '../../../scripts/app-icon-sources.mjs'

const read = (rel: string) => readFileSync(path.join(APP_ROOT, rel), 'utf8')

describe('iPhone-only (D1)', () => {
  const pbx = read('ios/App/App.xcodeproj/project.pbxproj')

  it('targets the iPhone family only, on both configurations of the app target', () => {
    expect(pbx.match(/TARGETED_DEVICE_FAMILY = [^;]+;/g)).toEqual([
      'TARGETED_DEVICE_FAMILY = 1;',
      'TARGETED_DEVICE_FAMILY = 1;',
    ])
  })

  it('declares no iPad orientation set (nothing reads it on an iPhone-only app)', () => {
    expect(read('ios/App/App/Info.plist')).not.toContain('UISupportedInterfaceOrientations~ipad')
  })
})
