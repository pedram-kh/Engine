#!/usr/bin/env node
/**
 * AH-100b D4 — rebuilds the creator app's icon + splash SOURCE images in
 * `apps/main/assets/` from the platform's own logo, so anyone can regenerate
 * them from the repo (`pnpm --filter @catalyst/main assets:generate`).
 *
 * Source of truth: `src/modules/auth/assets/catalyst-logo.svg` — the sign-in
 * header lockup. It is VECTOR (viewBox 121.315 × 27.676), so a 1024 or 2732
 * render is native resolution, never an upscale.
 *
 * The lockup carries the wordmark, and an icon is the symbol, not the lockup,
 * so the mark is cropped out: it spans x 0 → 26.84 and the first letter of the
 * wordmark starts at x 35.64 (measured on a render, AH-100b plan-pause), an
 * 8.8-unit gutter. The crop box below sits inside that gutter. The Figma
 * "Logo" node (22:560) agrees: its mark vector is 24.37 px square at the
 * origin of a 110-px-wide lockup — the same 1.103 scale (24.37 → 26.88).
 *
 * Outputs (all committed; `@capacitor/assets` reads them next):
 *   icon-only.png        1024², opaque canvas, mark = 60% of the canvas (iOS)
 *   icon-foreground.png  1024², transparent, mark = 60% of the 72dp launcher
 *                        window (same as iOS), inside the 66dp safe zone
 *   icon-background.png  1024², solid canvas
 *   splash.png           2732², canvas + centred mark
 *   splash-dark.png      identical — the app is dark-only (AH-100 Q6)
 */

import { mkdir, readFile, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

import sharp from 'sharp'

const here = path.dirname(fileURLToPath(import.meta.url))
export const APP_ROOT = path.resolve(here, '..')
export const LOGO_SVG = path.join(APP_ROOT, 'src/modules/auth/assets/catalyst-logo.svg')
export const OUT_DIR = path.join(APP_ROOT, 'assets')

/** `bg/canvas` — pinned equal to `nativePalette['bg-canvas']` by the asset spec. */
export const CANVAS = '#0b0b0c'
/** The mark's square in the logo's own coordinates (the ring spans 0 → 26.84). */
export const MARK_CROP = { x: 0, y: 0, size: 26.88 }

export const ICON_SIZE = 1024
export const SPLASH_SIZE = 2732
/** iOS icon: the mark's width as a share of the canvas. */
export const IOS_MARK_SHARE = 0.6
/**
 * Android adaptive icon: the guaranteed-visible safe zone is a circle 66dp wide
 * on the 108dp layer (66/108 ≈ 61%). NOT 66% — the 72dp mask window is wider
 * than the safe zone, and content on its edge touches a circular mask.
 */
export const ADAPTIVE_SAFE_ZONE = 66 / 108
/** Splash: the mark's width as a share of the (square) canvas. */
export const SPLASH_MARK_SHARE = 0.18

/** The logo with its Figma CSS-variable fills resolved to their fallbacks (rasterisers ignore `var()`). */
export function resolveLogoSvg(svg) {
  return svg.replace(/var\(--fill-\d+,\s*([^)]+)\)/g, '$1').trim()
}

/** The mark alone: the logo's viewBox cropped to {@link MARK_CROP}, rendered `px` wide. */
export function markSvg(svg, px) {
  const { x, y, size } = MARK_CROP
  return resolveLogoSvg(svg).replace(/<svg\b[^>]*>/, (tag) =>
    tag
      .replace(/\sviewBox="[^"]*"/, '')
      .replace(/\swidth="[^"]*"/, '')
      .replace(/\sheight="[^"]*"/, '')
      .replace('<svg', `<svg viewBox="${x} ${y} ${size} ${size}" width="${px}" height="${px}"`),
  )
}

export async function renderMark(svg, px) {
  return sharp(Buffer.from(markSvg(svg, px)), { density: 72 })
    .ensureAlpha()
    .png()
    .toBuffer()
}

/** Largest distance from the centre to any visibly opaque pixel, as a share of the canvas width. */
export async function maxRadiusShare(pngBuffer, alphaThreshold = 16) {
  const { data, info } = await sharp(pngBuffer)
    .ensureAlpha()
    .raw()
    .toBuffer({ resolveWithObject: true })
  const cx = (info.width - 1) / 2
  const cy = (info.height - 1) / 2
  let max = 0
  for (let y = 0; y < info.height; y++) {
    for (let x = 0; x < info.width; x++) {
      if (data[(y * info.width + x) * 4 + 3] > alphaThreshold) {
        max = Math.max(max, Math.hypot(x - cx, y - cy))
      }
    }
  }
  return max / info.width
}

/**
 * The mark centred on a square canvas. With a `background` the result is
 * written WITHOUT an alpha channel (the App Store rejects an iOS icon that
 * has one); with none it is a transparent canvas (the adaptive foreground).
 */
async function onCanvas(size, markPx, mark, background) {
  const offset = Math.round((size - markPx) / 2)
  const canvas = sharp({
    create: {
      width: size,
      height: size,
      channels: 4,
      background: background ?? { r: 0, g: 0, b: 0, alpha: 0 },
    },
  }).composite([{ input: mark, left: offset, top: offset }])
  return (background ? canvas.removeAlpha() : canvas).png().toBuffer()
}

/** The launcher shows the central 72dp of the 108dp adaptive layer. */
export const ADAPTIVE_WINDOW_SHARE = 72 / 108

/**
 * The foreground mark width: the SAME share of the visible 72dp window that
 * the iOS mark has of its tile, so the two stores' icons read alike. That the
 * result stays inside the 66dp safe zone is pinned by the asset spec (measured
 * on the committed PNG), not asserted here.
 */
export function adaptiveMarkPx() {
  return Math.round(ICON_SIZE * IOS_MARK_SHARE * ADAPTIVE_WINDOW_SHARE)
}

export async function buildSources(outDir = OUT_DIR) {
  const svg = await readFile(LOGO_SVG, 'utf8')
  await mkdir(outDir, { recursive: true })

  const iosPx = Math.round(ICON_SIZE * IOS_MARK_SHARE)
  const adaptivePx = adaptiveMarkPx()
  const splashPx = Math.round(SPLASH_SIZE * SPLASH_MARK_SHARE)

  const opaque = { r: 11, g: 11, b: 12, alpha: 1 }
  const files = {
    'icon-only.png': await onCanvas(ICON_SIZE, iosPx, await renderMark(svg, iosPx), opaque),
    'icon-foreground.png': await onCanvas(
      ICON_SIZE,
      adaptivePx,
      await renderMark(svg, adaptivePx),
      null,
    ),
    'icon-background.png': await sharp({
      create: { width: ICON_SIZE, height: ICON_SIZE, channels: 3, background: opaque },
    })
      .png()
      .toBuffer(),
    'splash.png': await onCanvas(SPLASH_SIZE, splashPx, await renderMark(svg, splashPx), opaque),
  }
  files['splash-dark.png'] = files['splash.png']

  for (const [name, buffer] of Object.entries(files)) {
    await writeFile(path.join(outDir, name), buffer)
  }
  return { iosPx, adaptivePx, splashPx, files: Object.keys(files) }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  buildSources().then((r) => {
    console.log(
      `icon sources written to ${OUT_DIR} — ios mark ${r.iosPx}px, adaptive mark ${r.adaptivePx}px, splash mark ${r.splashPx}px`,
    )
  })
}
