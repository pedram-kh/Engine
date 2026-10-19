import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { describe, expect, it } from 'vitest'

import { APP_LINK_HOST, APP_LINK_PATH_PREFIX } from './appLink'

/**
 * AH-103b — the guards that keep four places saying the same thing: the
 * TypeScript constants, the iOS association file and entitlement, and the
 * Android manifest filter. Each reads the REAL file from disk, so a change to
 * one without the others fails here instead of silently breaking links on a
 * device (where nothing reports the mismatch).
 */

/** `apps/main`, resolved from this spec's own location (src/core/platform/links, four up). */
const APP_ROOT = resolve(__dirname, '../../../..')

const read = (relative: string): string => readFileSync(resolve(APP_ROOT, relative), 'utf8')

describe('the iOS association file (public/.well-known/apple-app-site-association)', () => {
  const raw = read('public/.well-known/apple-app-site-association')
  const aasa = JSON.parse(raw) as {
    applinks: {
      details: Array<{ appIDs: string[]; components: Array<Record<string, string>> }>
    }
  }

  it('is valid JSON with exactly one detail (the shape check C4 relies on)', () => {
    expect(aasa.applinks.details).toHaveLength(1)
  })

  it('names the app by team id + bundle id, read from the Xcode project', () => {
    const pbxproj = read('ios/App/App.xcodeproj/project.pbxproj')
    const teams = new Set(
      [...pbxproj.matchAll(/DEVELOPMENT_TEAM = ([A-Z0-9]+);/g)].map((m) => m[1]),
    )
    const bundles = new Set(
      [...pbxproj.matchAll(/PRODUCT_BUNDLE_IDENTIFIER = ([A-Za-z0-9.-]+);/g)].map((m) => m[1]),
    )

    // One team and one bundle id across every configuration, else the guard is ambiguous.
    expect([...teams]).toHaveLength(1)
    expect([...bundles]).toHaveLength(1)

    expect(aasa.applinks.details[0]!.appIDs).toEqual([`${[...teams][0]}.${[...bundles][0]}`])
  })

  it('claims /creator/* and nothing else (R2: /auth/* and agency paths stay web)', () => {
    expect(aasa.applinks.details[0]!.components).toEqual([{ '/': `${APP_LINK_PATH_PREFIX}*` }])
  })

  it('is the Capacitor app id too (one identity across iOS, Android and the file)', () => {
    const config = read('capacitor.config.ts')

    expect(config).toContain("appId: 'com.catalystgrowth.platform'")
    expect(aasa.applinks.details[0]!.appIDs[0]).toMatch(/\.com\.catalystgrowth\.platform$/)
  })
})

describe('the Netlify headers file (public/_headers)', () => {
  const rules = read('public/_headers')
    .split('\n')
    .filter((line) => line.trim() !== '' && !line.trim().startsWith('#'))

  it('has ONE rule, for ONE path, setting ONLY the content type (no wildcard, no cache override)', () => {
    expect(rules).toEqual([
      '/.well-known/apple-app-site-association',
      '  Content-Type: application/json',
    ])
  })
})

describe('the iOS entitlement (ios/App/App/App.entitlements)', () => {
  const plist = read('ios/App/App/App.entitlements')

  it('associates exactly the production host, as applinks:', () => {
    const block = plist.match(
      /<key>com\.apple\.developer\.associated-domains<\/key>\s*<array>([\s\S]*?)<\/array>/,
    )

    expect(block).not.toBeNull()
    expect([...block![1]!.matchAll(/<string>([^<]*)<\/string>/g)].map((m) => m[1])).toEqual([
      `applinks:${APP_LINK_HOST}`,
    ])
  })

  it('does not use developer mode (D4: the committed entitlement is the production one)', () => {
    expect(plist).not.toContain('mode=developer')
  })

  it('keeps the push entitlement it already had', () => {
    expect(plist).toContain('<key>aps-environment</key>')
  })
})

describe('the Android manifest filter (AndroidManifest.xml)', () => {
  const xml = read('android/app/src/main/AndroidManifest.xml')
  const doc = new DOMParser().parseFromString(xml, 'text/xml')
  const filters = [...doc.getElementsByTagName('intent-filter')]
  const linkFilters = filters.filter((f) => f.getAttribute('android:autoVerify') !== null)

  it('has exactly one auto-verifying filter, on the main activity', () => {
    expect(linkFilters).toHaveLength(1)
    expect(linkFilters[0]!.parentElement!.getAttribute('android:name')).toBe('.MainActivity')
    expect(linkFilters[0]!.getAttribute('android:autoVerify')).toBe('true')
  })

  it('is a VIEW / DEFAULT / BROWSABLE filter', () => {
    const f = linkFilters[0]!
    const names = (tag: string) =>
      [...f.getElementsByTagName(tag)].map((e) => e.getAttribute('android:name'))

    expect(names('action')).toEqual(['android.intent.action.VIEW'])
    expect(names('category').sort()).toEqual([
      'android.intent.category.BROWSABLE',
      'android.intent.category.DEFAULT',
    ])
  })

  it('claims https, the production host and the /creator/ prefix, and nothing wider', () => {
    const data = [...linkFilters[0]!.getElementsByTagName('data')]

    expect(data).toHaveLength(1)
    expect(data[0]!.getAttribute('android:scheme')).toBe('https')
    expect(data[0]!.getAttribute('android:host')).toBe(APP_LINK_HOST)
    expect(data[0]!.getAttribute('android:pathPrefix')).toBe(APP_LINK_PATH_PREFIX)
    // No wider matcher sneaks in beside the prefix.
    for (const attr of ['android:path', 'android:pathPattern', 'android:pathAdvancedPattern']) {
      expect(data[0]!.getAttribute(attr)).toBeNull()
    }
  })

  it('leaves the launcher filter alone', () => {
    const launcher = filters.filter((f) =>
      [...f.getElementsByTagName('category')].some(
        (c) => c.getAttribute('android:name') === 'android.intent.category.LAUNCHER',
      ),
    )

    expect(launcher).toHaveLength(1)
    expect(launcher[0]!.getAttribute('android:autoVerify')).toBeNull()
  })
})

describe('the boot wiring (src/main.ts)', () => {
  // main.ts boots the whole app and cannot be imported by a spec, so the one line that
  // starts link handling is pinned as text. Without it the handler is dead code and no
  // other spec notices; only a device would.
  const main = read('src/main.ts')

  it('imports the handler and the starter from the links module', () => {
    expect(main).toMatch(
      /import \{ createAppLinkHandler \} from '\.\/core\/platform\/links\/appLinkRouting'/,
    )
    expect(main).toMatch(
      /import \{ startAppLinks \} from '\.\/core\/platform\/links\/startAppLinks'/,
    )
  })

  it('starts link handling once, with the handler bound to the real router', () => {
    const calls = main.match(/startAppLinks\(/g) ?? []
    expect(calls).toHaveLength(1)
    expect(main).toContain('startAppLinks({ onLink: createAppLinkHandler(router) })')
  })
})

describe('no assetlinks.json is published (R3: no real signing fingerprint exists yet)', () => {
  it('apps/main/public/.well-known/assetlinks.json does not exist', () => {
    // If this fails, someone added the file. Publishing it is a conscious change: only
    // with a REAL release / Play App Signing fingerprint (never a debug one), after the
    // tech-debt row "Android App Links are not verified" is resolved. See
    // docs/reviews/universal-links-plan.md and docs/tech-debt.md ("AH-103b").
    expect(existsSync(resolve(APP_ROOT, 'public/.well-known/assetlinks.json'))).toBe(false)
  })

  it('the .well-known folder holds only the iOS association file', () => {
    expect(existsSync(resolve(APP_ROOT, 'public/.well-known/apple-app-site-association'))).toBe(
      true,
    )
  })
})
