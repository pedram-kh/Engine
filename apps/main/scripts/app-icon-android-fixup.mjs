#!/usr/bin/env node
/**
 * AH-100b D4 — the part of the Android icon + splash set that
 * `@capacitor/assets` (3.0.5) gets wrong for this app. Runs right after
 * `capacitor-assets generate` (see `assets:generate`) and is idempotent.
 *
 * What the generator does, and what this corrects:
 *
 * 1. ADAPTIVE LAYERS ARE UNDER-SIZED. It writes `ic_launcher_foreground.png`
 *    at the LEGACY launcher size (192px at xxxhdpi) and insets it 16.7% into
 *    the 108dp layer, so the 288px area it fills is stretched from 192px
 *    (1.5× on xxxhdpi, worse on the lower densities). A soft edge on the
 *    mark is exactly the "blurry icon shipped silently" this chunk forbids.
 *    The layers are re-cut here at their true 108dp sizes
 *    (ldpi 81 → xxxhdpi 432) from `assets/icon-foreground.png`, which
 *    `app-icon-sources.mjs` already composes with the mark inside the 66dp
 *    safe zone — so the inset is dropped too (it would shrink the mark a
 *    second time).
 * 2. THE LEGACY (API 24–25) ICONS CARRY A FIXED 8px TRANSPARENT BORDER at
 *    every density — a third of the 48px mdpi square is empty. They are re-cut
 *    here from `assets/icon-only.png` (rounded square + circle, full bleed).
 * 3. THE LAUNCHER BACKGROUND IS WHITE. `values/ic_launcher_background.xml`
 *    is set to the dark canvas, and the adaptive-icon XML returns to the
 *    Capacitor template shape (colour background, bitmap foreground); the
 *    generator's per-density background bitmaps are removed as unused.
 * 4. THE ANDROID 12+ SPLASH IS WHITE. The launch theme's
 *    `windowSplashScreenBackground` is set to the same dark canvas (the
 *    `android:background` drawable only covers Android 11 and below).
 *
 * The generator also reformats `AndroidManifest.xml` (whitespace only); after
 * a re-run, `git checkout -- android/app/src/main/AndroidManifest.xml`.
 */

import { existsSync } from 'node:fs'
import { readFile, rm, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { pathToFileURL } from 'node:url'

import sharp from 'sharp'

import { APP_ROOT, CANVAS, OUT_DIR } from './app-icon-sources.mjs'

export const RES = path.join(APP_ROOT, 'android/app/src/main/res')

/** The adaptive layer is 108dp: px = 108 × density. */
export const ADAPTIVE_LAYER_PX = {
  ldpi: 81,
  mdpi: 108,
  hdpi: 162,
  xhdpi: 216,
  xxhdpi: 324,
  xxxhdpi: 432,
}

/** Legacy launcher icon size: 48dp × density. */
export const LEGACY_ICON_PX = {
  ldpi: 36,
  mdpi: 48,
  hdpi: 72,
  xhdpi: 96,
  xxhdpi: 144,
  xxxhdpi: 192,
}

/** Corner radius of the legacy square icon, as a share of its width. */
export const LEGACY_SQUARE_RADIUS = 0.14

export const ADAPTIVE_XML = `<?xml version="1.0" encoding="utf-8"?>
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@color/ic_launcher_background"/>
    <foreground android:drawable="@mipmap/ic_launcher_foreground"/>
</adaptive-icon>`

export const BACKGROUND_COLOR_XML = `<?xml version="1.0" encoding="utf-8"?>
<resources>
    <color name="ic_launcher_background">${CANVAS.toUpperCase()}</color>
</resources>`

const SPLASH_BACKGROUND_ITEM =
  '<item name="windowSplashScreenBackground">@color/ic_launcher_background</item>'

/** Adds the Android 12+ splash background to the launch theme, once. */
export function withSplashBackground(stylesXml) {
  if (stylesXml.includes('windowSplashScreenBackground')) {
    return stylesXml
  }
  return stylesXml.replace(
    /(<style name="AppTheme\.NoActionBarLaunch"[^>]*>)/,
    `$1\n        ${SPLASH_BACKGROUND_ITEM}`,
  )
}

/** The full-bleed icon cut to `px`, clipped by an SVG shape (`dest-in`). */
async function legacyIcon(source, px, shape) {
  const mask = Buffer.from(
    `<svg width="${px}" height="${px}" xmlns="http://www.w3.org/2000/svg">${shape}</svg>`,
  )
  const resized = await sharp(source).resize(px, px, { kernel: 'lanczos3' }).png().toBuffer()
  return sharp(resized)
    .composite([{ input: mask, blend: 'dest-in' }])
    .png()
    .toBuffer()
}

export async function fixAndroid(res = RES, sourceDir = OUT_DIR) {
  const foreground = await readFile(path.join(sourceDir, 'icon-foreground.png'))
  const iconOnly = await readFile(path.join(sourceDir, 'icon-only.png'))
  for (const [density, px] of Object.entries(ADAPTIVE_LAYER_PX)) {
    const dir = path.join(res, `mipmap-${density}`)
    await sharp(foreground)
      .resize(px, px, { kernel: 'lanczos3' })
      .png()
      .toFile(path.join(dir, 'ic_launcher_foreground.png'))
    await rm(path.join(dir, 'ic_launcher_background.png'), { force: true })
    const legacy = LEGACY_ICON_PX[density]
    const radius = legacy * LEGACY_SQUARE_RADIUS
    await writeFile(
      path.join(dir, 'ic_launcher.png'),
      await legacyIcon(
        iconOnly,
        legacy,
        `<rect width="${legacy}" height="${legacy}" rx="${radius}" fill="#fff"/>`,
      ),
    )
    await writeFile(
      path.join(dir, 'ic_launcher_round.png'),
      await legacyIcon(
        iconOnly,
        legacy,
        `<circle cx="${legacy / 2}" cy="${legacy / 2}" r="${legacy / 2}" fill="#fff"/>`,
      ),
    )
  }
  for (const name of ['ic_launcher.xml', 'ic_launcher_round.xml']) {
    await writeFile(path.join(res, 'mipmap-anydpi-v26', name), ADAPTIVE_XML)
  }
  await writeFile(path.join(res, 'values/ic_launcher_background.xml'), BACKGROUND_COLOR_XML)
  const stylesPath = path.join(res, 'values/styles.xml')
  if (existsSync(stylesPath)) {
    await writeFile(stylesPath, withSplashBackground(await readFile(stylesPath, 'utf8')))
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  fixAndroid().then(() =>
    console.log('android adaptive layers, launcher background and splash theme fixed'),
  )
}
