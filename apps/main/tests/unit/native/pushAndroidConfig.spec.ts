// @vitest-environment node
/**
 * AH-102 C4.2 — the Android half of push, pinned.
 *
 * What can rot silently, and is pinned here:
 *   1. the notification icon must be a pure-white-on-transparent silhouette at
 *      the five densities (Android reads only alpha; a colour icon is a white
 *      blob in the shade) and be exactly what the committed script produces;
 *   2. the manifest must keep auto-init OFF (consent-first: no token before the
 *      creator says yes), keep analytics off, and point the default channel at
 *      the id the server sets on every message;
 *   3. the Firebase plugin must be wired into the Android project, and the iOS
 *      project must NOT have picked it up (R-7: a full `cap sync` would add the
 *      Firebase SDK to iOS without a plist).
 */
import { readFileSync } from 'node:fs'
import { mkdtemp, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'

import sharp from 'sharp'
import { afterAll, describe, expect, it } from 'vitest'

import {
  buildPushIcons,
  GLOW_CEILING,
  PUSH_DENSITY,
  PUSH_ICON_NAME,
  RES,
  shapeAlpha,
} from '../../../scripts/app-push-icon.mjs'
import { APP_ROOT } from '../../../scripts/app-icon-sources.mjs'

const MANIFEST = readFileSync(
  path.join(APP_ROOT, 'android/app/src/main/AndroidManifest.xml'),
  'utf8',
)

const metaData = (name: string): string | null => {
  const tag = new RegExp(`<meta-data[^>]*android:name="${name}"[^>]*>`).exec(MANIFEST)?.[0]
  return tag ? (/android:(?:value|resource)="([^"]*)"/.exec(tag)?.[1] ?? null) : null
}

const iconFile = (bucket: string) => path.join(RES, `drawable-${bucket}`, `${PUSH_ICON_NAME}.png`)

const rgba = async (file: string) => {
  const { data, info } = await sharp(file).ensureAlpha().raw().toBuffer({ resolveWithObject: true })
  return { data, width: info.width, height: info.height }
}

const tmpDirs: string[] = []
afterAll(async () => {
  await Promise.all(tmpDirs.map((d) => rm(d, { recursive: true, force: true })))
})

describe('push notification icon', () => {
  for (const [bucket, density] of Object.entries(PUSH_DENSITY)) {
    it(`${bucket}: ${24 * density}px square, pure white wherever it is visible`, async () => {
      const { data, width, height } = await rgba(iconFile(bucket))

      expect(width).toBe(24 * density)
      expect(height).toBe(24 * density)

      let visible = 0
      for (let i = 0; i < data.length; i += 4) {
        if ((data[i + 3] ?? 0) > 0) {
          visible++
          expect([data[i], data[i + 1], data[i + 2]]).toEqual([255, 255, 255])
        }
      }
      // A real glyph, not an empty or a filled square.
      expect(visible / (width * height)).toBeGreaterThan(0.25)
      expect(visible / (width * height)).toBeLessThan(0.8)
    })
  }

  it('keeps 1dp of air on every side and leaves the centre of the C open', async () => {
    const { data, width } = await rgba(iconFile('xxxhdpi'))
    const alpha = (x: number, y: number) => data[(y * width + x) * 4 + 3]

    for (let i = 0; i < width; i++) {
      for (let edge = 0; edge < 4; edge++) {
        expect(alpha(i, edge)).toBe(0)
        expect(alpha(i, width - 1 - edge)).toBe(0)
        expect(alpha(edge, i)).toBe(0)
        expect(alpha(width - 1 - edge, i)).toBe(0)
      }
    }
    // Between the ring and the centre tile: transparent (the logo's glow is not shape).
    expect(alpha(32, 48)).toBe(0)
  })

  it("drops the logo's soft glow instead of rendering it as a haze", () => {
    expect(shapeAlpha(GLOW_CEILING)).toBe(0)
    expect(shapeAlpha(0)).toBe(0)
    expect(shapeAlpha(255)).toBe(255)
    expect(shapeAlpha(110)).toBeGreaterThan(0)
    expect(shapeAlpha(110)).toBeLessThan(255)
  })

  it('is exactly what the committed script produces from the committed logo', async () => {
    const dir = await mkdtemp(path.join(tmpdir(), 'push-icon-'))
    tmpDirs.push(dir)
    await buildPushIcons(dir)

    for (const bucket of Object.keys(PUSH_DENSITY)) {
      const fresh = await rgba(path.join(dir, `drawable-${bucket}`, `${PUSH_ICON_NAME}.png`))
      const committed = await rgba(iconFile(bucket))
      expect(Buffer.compare(fresh.data, committed.data)).toBe(0)
    }
  })
})

describe('AndroidManifest.xml — FCM meta-data', () => {
  it('keeps auto-init OFF: no token before the in-app soft-ask is accepted', () => {
    expect(metaData('firebase_messaging_auto_init_enabled')).toBe('false')
  })

  it('keeps Firebase Analytics collection off', () => {
    expect(metaData('firebase_analytics_collection_enabled')).toBe('false')
  })

  it('defaults notifications to the channel the server sets on every message', () => {
    const php = readFileSync(
      path.join(APP_ROOT, '../api/app/Modules/Notifications/Push/PushMessage.php'),
      'utf8',
    )
    const serverId = /const string DEFAULT_CHANNEL = '([a-z_]+)'/.exec(php)?.[1]

    expect(serverId).toBeDefined()
    expect(metaData('com.google.firebase.messaging.default_notification_channel_id')).toBe(serverId)
  })

  it('points the default notification icon at the committed glyph', () => {
    expect(metaData('com.google.firebase.messaging.default_notification_icon')).toBe(
      `@drawable/${PUSH_ICON_NAME}`,
    )
  })
})

describe('Capacitor project wiring', () => {
  const read = (rel: string) => readFileSync(path.join(APP_ROOT, rel), 'utf8')

  it('Android includes the Firebase messaging plugin', () => {
    expect(read('android/capacitor.settings.gradle')).toContain(
      "include ':capacitor-firebase-messaging'",
    )
    expect(read('android/app/capacitor.build.gradle')).toContain(
      "implementation project(':capacitor-firebase-messaging')",
    )
  })

  it('iOS has NOT picked the plugin up (R-7: no Firebase SDK on iOS before AH-102-ios)', () => {
    const swiftManifest = read('ios/App/CapApp-SPM/Package.swift')

    expect(swiftManifest.toLowerCase()).not.toContain('firebase')
  })
})
