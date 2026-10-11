#!/usr/bin/env node
/**
 * AH-102 C4.2 — the Android push NOTIFICATION icon, derived from the platform
 * logo (P-4), the same source the launcher icon uses (`app-icon-sources.mjs`).
 *
 * Android renders the small notification icon as a flat silhouette: only the
 * ALPHA channel counts, and the system tints it. A full-colour launcher icon
 * therefore shows as a solid white blob in the shade. This takes the mark's
 * own shape (the ring and the centre tile) and writes it as pure white on
 * transparent, at the five notification-icon densities.
 *
 * Geometry: the icon is 24dp square; the mark fills the central 22dp (1dp of
 * air on every side, per Material's notification-icon guidance).
 *
 * Outputs (committed, idempotent):
 *   android/app/src/main/res/drawable-{mdpi,hdpi,xhdpi,xxhdpi,xxxhdpi}/ic_stat_catalyst.png
 * referenced by the manifest's `default_notification_icon` meta-data.
 *
 * Run: `pnpm --filter @catalyst/main assets:push-icon`
 */

import { mkdir, readFile, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { pathToFileURL } from 'node:url'

import sharp from 'sharp'

import { APP_ROOT, LOGO_SVG, markSvg } from './app-icon-sources.mjs'

export const PUSH_ICON_NAME = 'ic_stat_catalyst'
export const PUSH_ICON_DP = 24
export const PUSH_MARK_DP = 22
/** px per dp at each density the notification icon ships in. */
export const PUSH_DENSITY = { mdpi: 1, hdpi: 1.5, xhdpi: 2, xxhdpi: 3, xxxhdpi: 4 }

export const RES = path.join(APP_ROOT, 'android/app/src/main/res')

/** Alpha at or below this is the logo's glow, not the mark: dropped. */
export const GLOW_CEILING = 64
/** Alpha at or above this is solid mark. Between the two is the antialiased edge. */
export const SOLID_FLOOR = 160

export function shapeAlpha(alpha) {
  const t = (alpha - GLOW_CEILING) / (SOLID_FLOOR - GLOW_CEILING)
  return Math.round(255 * Math.min(1, Math.max(0, t)))
}

/** One density: a `size`² white-on-transparent glyph of the mark. */
export async function pushIconPng(svg, density) {
  const size = Math.round(PUSH_ICON_DP * density)
  const markPx = Math.round(PUSH_MARK_DP * density)
  // Rasterise 4× over, then Lanczos down: crisp edges at 36/48px, not the
  // soft ones a direct small render of the vector gives.
  const mark = await sharp(Buffer.from(markSvg(svg, markPx * 4)), { density: 72 })
    .resize(markPx, markPx, { kernel: 'lanczos3' })
    .ensureAlpha()
    .raw()
    .toBuffer()
  const white = Buffer.alloc(markPx * markPx * 4, 255)
  for (let i = 0; i < markPx * markPx; i++) {
    // Keep the shape, drop every colour — and the logo's soft inner glow, which
    // is faint but non-zero alpha and would render as a grey haze inside the C.
    white[i * 4 + 3] = shapeAlpha(mark[i * 4 + 3])
  }
  const offset = Math.round((size - markPx) / 2)
  return sharp({
    create: {
      width: size,
      height: size,
      channels: 4,
      background: { r: 255, g: 255, b: 255, alpha: 0 },
    },
  })
    .composite([
      {
        input: white,
        raw: { width: markPx, height: markPx, channels: 4 },
        left: offset,
        top: offset,
      },
    ])
    .png()
    .toBuffer()
}

export async function buildPushIcons(res = RES) {
  const svg = await readFile(LOGO_SVG, 'utf8')
  const written = []
  for (const [bucket, density] of Object.entries(PUSH_DENSITY)) {
    const dir = path.join(res, `drawable-${bucket}`)
    await mkdir(dir, { recursive: true })
    const file = path.join(dir, `${PUSH_ICON_NAME}.png`)
    await writeFile(file, await pushIconPng(svg, density))
    written.push(file)
  }
  return written
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  buildPushIcons().then((files) => console.log(`wrote ${files.length} push icons`))
}
