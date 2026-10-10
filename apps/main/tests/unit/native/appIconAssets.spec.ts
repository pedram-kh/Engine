// @vitest-environment node
/**
 * AH-100b D4 — the app icon + splash set, pinned.
 *
 * Four things can silently rot and each is pinned here:
 *   1. the committed `assets/` sources must be exactly what the committed
 *      script produces from the committed logo (reproducibility, Q5);
 *   2. their geometry must hold the constraints the stores / Android enforce
 *      (no alpha on the iOS icon, the mark inside the 66dp adaptive safe zone);
 *   3. the generated iOS + Android output must exist at the right sizes and
 *      be on the dark canvas (a white launcher/splash flash is the regression
 *      this chunk exists to remove);
 *   4. the canvas colour is the native palette's `bg-canvas`, not a copy.
 */
import { existsSync, readFileSync } from 'node:fs'
import { mkdir, mkdtemp, rm } from 'node:fs/promises'
import path from 'node:path'

import { nativePalette } from '@catalyst/design-tokens/native'
import sharp from 'sharp'
import { afterAll, describe, expect, it } from 'vitest'

import {
  ADAPTIVE_LAYER_PX,
  ADAPTIVE_XML,
  BACKGROUND_COLOR_XML,
  LEGACY_ICON_PX,
  withSplashBackground,
} from '../../../scripts/app-icon-android-fixup.mjs'
import {
  ADAPTIVE_SAFE_ZONE,
  ANDROID_SPLASH_DIR,
  APP_ROOT,
  buildSources,
  CANVAS,
  ICON_SIZE,
  iosSplash,
  maxRadiusShare,
  OUT_DIR,
  SPLASH_FRAME,
  SPLASH_SIZE,
} from '../../../scripts/app-icon-sources.mjs'

const RES = path.join(APP_ROOT, 'android/app/src/main/res')
const IOS_ASSETS = path.join(APP_ROOT, 'ios/App/App/Assets.xcassets')

const raw = async (file: string | Buffer) => {
  const { data, info } = await sharp(file).ensureAlpha().raw().toBuffer({ resolveWithObject: true })
  return { data, width: info.width, height: info.height, channels: info.channels }
}

const pixel = async (file: string, x: number, y: number) => {
  const { data, width } = await raw(file)
  const i = (y * width + x) * 4
  return { r: data[i], g: data[i + 1], b: data[i + 2], a: data[i + 3] }
}

const CANVAS_RGB = { r: 11, g: 11, b: 12, a: 255 }

describe('canvas colour', () => {
  it('is the native palette bg-canvas, not a copy', () => {
    expect(CANVAS).toBe(nativePalette['bg-canvas'])
  })
})

describe('committed sources', () => {
  const buildDir = path.join(APP_ROOT, 'test-results', 'app-icon-build')
  let rebuilt: string

  afterAll(async () => {
    if (rebuilt) {
      await rm(rebuilt, { recursive: true, force: true })
    }
  })

  it('are reproduced pixel-for-pixel by the committed script', async () => {
    await rm(buildDir, { recursive: true, force: true })
    // `test-results/` is gitignored, so a clean checkout (CI) has no such directory.
    await mkdir(path.dirname(buildDir), { recursive: true })
    rebuilt = await mkdtemp(`${buildDir}-`)
    const { files } = await buildSources(rebuilt)
    expect(files).toHaveLength(7)
    for (const name of files as string[]) {
      const committed = await raw(path.join(OUT_DIR, name))
      const fresh = await raw(path.join(rebuilt, name))
      expect([name, committed.width, committed.height]).toEqual([name, fresh.width, fresh.height])
      expect(committed.data.equals(fresh.data), `${name} differs from a fresh build`).toBe(true)
    }
  }, 60_000)

  it('has the sizes the generator needs', async () => {
    for (const name of ['icon-only', 'icon-foreground', 'icon-background']) {
      const meta = await sharp(path.join(OUT_DIR, `${name}.png`)).metadata()
      expect([name, meta.width, meta.height]).toEqual([name, ICON_SIZE, ICON_SIZE])
    }
    for (const name of [
      'splash',
      'splash-dark',
      `${ANDROID_SPLASH_DIR}/splash`,
      `${ANDROID_SPLASH_DIR}/splash-dark`,
    ]) {
      const meta = await sharp(path.join(OUT_DIR, `${name}.png`)).metadata()
      expect([name, meta.width, meta.height]).toEqual([name, SPLASH_SIZE, SPLASH_SIZE])
    }
  })

  // AH-101 U2 — the iOS splash is Figma frame 01 placed on the canvas, and
  // Android's stays the icon-on-colour splash (Q8).
  it('places the Figma splash frame at full height, centred, with no alpha (iOS)', async () => {
    const meta = await sharp(path.join(OUT_DIR, 'splash.png')).metadata()
    expect(meta.hasAlpha).toBe(false)
    const frame = await sharp(SPLASH_FRAME).metadata()
    // 390×844 at 4× — the frame the committed source must be.
    expect([frame.width, frame.height]).toEqual([1560, 3376])
    const placed = Math.round((SPLASH_SIZE * 1560) / 3376)
    const left = Math.round((SPLASH_SIZE - placed) / 2)
    const centre = await pixel(path.join(OUT_DIR, 'splash.png'), SPLASH_SIZE / 2, SPLASH_SIZE / 2)
    // The ghosted mark's centre glow is lit — it is not the flat canvas.
    expect(centre).not.toEqual(CANVAS_RGB)
    // The strip's left and right edges sit symmetrically either side of the centre.
    expect(left + placed + left).toBeLessThanOrEqual(SPLASH_SIZE + 1)
    // And outside the strip is the flat canvas.
    expect(await pixel(path.join(OUT_DIR, 'splash.png'), left - 2, SPLASH_SIZE - 1)).toEqual(
      CANVAS_RGB,
    )
  })

  it('is deterministic: the placement function reproduces the committed iOS splash', async () => {
    const fresh = await raw(await iosSplash())
    const committed = await raw(path.join(OUT_DIR, 'splash.png'))
    expect(committed.data.equals(fresh.data)).toBe(true)
  }, 60_000)

  it('keeps the Android splash as the mark on the canvas — a lit mark, flat canvas around it', async () => {
    const file = path.join(OUT_DIR, ANDROID_SPLASH_DIR, 'splash.png')
    expect(await pixel(file, 40, SPLASH_SIZE / 2)).toEqual(CANVAS_RGB)
    expect(await pixel(file, SPLASH_SIZE / 2, SPLASH_SIZE / 2)).not.toEqual(CANVAS_RGB)
    // The mark is the AH-100b size (18% of the canvas, ±246px), not the Figma ghost mark.
    expect(await pixel(file, SPLASH_SIZE / 2 + 300, SPLASH_SIZE / 2)).toEqual(CANVAS_RGB)
  })

  it('has no alpha channel on the iOS icon (the App Store rejects one)', async () => {
    const meta = await sharp(path.join(OUT_DIR, 'icon-only.png')).metadata()
    expect(meta.hasAlpha).toBe(false)
  })

  it('keeps the adaptive mark inside the 66dp safe zone, and not a speck', async () => {
    // The platform's definition: a 66dp circle on the 108dp layer.
    expect(ADAPTIVE_SAFE_ZONE).toBeCloseTo(66 / 108, 10)
    const share = await maxRadiusShare(
      await sharp(path.join(OUT_DIR, 'icon-foreground.png')).toBuffer(),
    )
    expect(share).toBeLessThanOrEqual(ADAPTIVE_SAFE_ZONE / 2)
    // Not a speck: the mark reads as large as iOS's does in its tile — the
    // same share of the visible 72dp window (so about 0.6 × 72/108 of the layer).
    expect(share).toBeGreaterThan(0.2)
  })

  it('paints the canvas colour at every corner of the opaque sources', async () => {
    for (const name of [
      'icon-only',
      'icon-background',
      'splash',
      'splash-dark',
      `${ANDROID_SPLASH_DIR}/splash`,
      `${ANDROID_SPLASH_DIR}/splash-dark`,
    ]) {
      const file = path.join(OUT_DIR, `${name}.png`)
      const { width, height } = await raw(file)
      const corners: [number, number][] = [
        [0, 0],
        [width - 1, 0],
        [0, height - 1],
        [width - 1, height - 1],
      ]
      for (const [x, y] of corners) {
        expect([name, await pixel(file, x, y)]).toEqual([name, CANVAS_RGB])
      }
    }
  })
})

describe('generated iOS output', () => {
  it('has a 1024 opaque app icon on the canvas', async () => {
    const file = path.join(IOS_ASSETS, 'AppIcon.appiconset/AppIcon-512@2x.png')
    const meta = await sharp(file).metadata()
    expect([meta.width, meta.height, meta.hasAlpha]).toEqual([1024, 1024, false])
    expect(await pixel(file, 0, 0)).toEqual(CANVAS_RGB)
  })

  it('has light and dark splash images on the canvas, and no orphaned old splash', async () => {
    const dir = path.join(IOS_ASSETS, 'Splash.imageset')
    const contents = JSON.parse(readFileSync(path.join(dir, 'Contents.json'), 'utf8')) as {
      images: { filename: string }[]
    }
    expect(contents.images).toHaveLength(6)
    for (const { filename } of contents.images) {
      expect(existsSync(path.join(dir, filename)), filename).toBe(true)
      expect([filename, await pixel(path.join(dir, filename), 0, 0)]).toEqual([
        filename,
        CANVAS_RGB,
      ])
    }
    for (const old of [
      'splash-2732x2732.png',
      'splash-2732x2732-1.png',
      'splash-2732x2732-2.png',
    ]) {
      expect(existsSync(path.join(dir, old)), old).toBe(false)
    }
  })
})

describe('generated Android output', () => {
  it('cuts every adaptive foreground layer at its true 108dp size', async () => {
    for (const [density, px] of Object.entries(ADAPTIVE_LAYER_PX)) {
      const meta = await sharp(
        path.join(RES, `mipmap-${density}/ic_launcher_foreground.png`),
      ).metadata()
      expect([density, meta.width, meta.height]).toEqual([density, px, px])
    }
    // 108dp × 4 — guards against the generator's 192px (legacy-size) layer.
    expect(ADAPTIVE_LAYER_PX.xxxhdpi).toBe(432)
  })

  it('has no generator background bitmaps (the colour resource is the background)', () => {
    for (const density of Object.keys(ADAPTIVE_LAYER_PX)) {
      expect(
        existsSync(path.join(RES, `mipmap-${density}/ic_launcher_background.png`)),
        density,
      ).toBe(false)
    }
  })

  it('has the template-shaped adaptive XML, with no second inset', () => {
    for (const name of ['ic_launcher.xml', 'ic_launcher_round.xml']) {
      const xml = readFileSync(path.join(RES, 'mipmap-anydpi-v26', name), 'utf8')
      expect(xml).toBe(ADAPTIVE_XML)
      expect(xml).not.toContain('inset')
    }
  })

  it('has the legacy launcher icons (square + round) on the canvas', async () => {
    // Probe the left edge mid-height (the corners are masked). The generator's
    // own legacy icons had a fixed 8px transparent border, i.e. empty here.
    for (const [density, legacy] of Object.entries(LEGACY_ICON_PX)) {
      for (const name of ['ic_launcher', 'ic_launcher_round']) {
        const file = path.join(RES, `mipmap-${density}/${name}.png`)
        expect((await sharp(file).metadata()).width).toBe(legacy)
        const probe = await pixel(file, Math.round(legacy * 0.08), Math.floor(legacy / 2))
        expect([density, name, probe]).toEqual([density, name, CANVAS_RGB])
      }
    }
  })

  it('has a dark launcher background colour (not the template white)', () => {
    const xml = readFileSync(path.join(RES, 'values/ic_launcher_background.xml'), 'utf8')
    expect(xml.toLowerCase()).toContain(`>${CANVAS.toLowerCase()}<`)
    expect(xml.toLowerCase()).not.toContain('#ffffff')
    // …and the committed file is exactly what the fix-up script writes.
    expect(xml).toBe(BACKGROUND_COLOR_XML)
  })

  it('has a dark Android 12+ splash background in the launch theme', () => {
    const xml = readFileSync(path.join(RES, 'values/styles.xml'), 'utf8')
    expect(xml).toMatch(
      /<style name="AppTheme\.NoActionBarLaunch"[^>]*>\s*<item name="windowSplashScreenBackground">@color\/ic_launcher_background<\/item>/,
    )
  })

  it('has the pre-12 splash bitmap on the canvas', async () => {
    expect(await pixel(path.join(RES, 'drawable/splash.png'), 0, 0)).toEqual(CANVAS_RGB)
  })
})

describe('withSplashBackground', () => {
  const styles = `<style name="AppTheme.NoActionBarLaunch" parent="Theme.SplashScreen">\n        <item name="android:background">@drawable/splash</item>\n    </style>`

  it('adds the splash background once and is idempotent', () => {
    const once = withSplashBackground(styles)
    expect(once).toContain('windowSplashScreenBackground')
    expect(withSplashBackground(once)).toBe(once)
  })

  it('leaves styles without the launch theme untouched', () => {
    const other = '<style name="Other"></style>'
    expect(withSplashBackground(other)).toBe(other)
  })
})
