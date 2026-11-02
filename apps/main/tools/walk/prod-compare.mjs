#!/usr/bin/env node
/**
 * AH-114 S5.2, the one real-data check: the creator-facing pages of the PRODUCTION site
 * (the code that is live today) against a production build of this tree, read the same way.
 *
 *   node tools/walk/prod-compare.mjs --prod https://platform.catalyst-growth.com \
 *     --dist /tmp/s52-dist-head [--out /tmp/prod-compare.json] [--shots /tmp/prod-shots]
 *
 * What it does, per public route x theme x viewport:
 *   1. loads the route on the live site and on `vite preview` of `--dist`;
 *   2. answers every `/api` and `/sanctum` request in the browser with one fixed 503, on
 *      BOTH sides, so no API request leaves the machine and the two pages see the same data;
 *   3. reads the DOM hash, the computed-style hash and the theme stylesheet of `#app`,
 *      the same probe as `walk.mjs`, and compares the two.
 *
 * Read-only: GETs of public static pages and assets, nothing is submitted, no sign-in. It
 * cannot reach pages behind a sign-in (no credentials); that limit is stated in the review.
 * Dev tool only, not in the build, not in any test glob.
 */

import { mkdirSync, writeFileSync } from 'node:fs'
import path from 'node:path'

import { chromium } from '@playwright/test'
import { preview } from 'vite'

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`)
  return i === -1 ? fallback : process.argv[i + 1]
}
const PROD = arg('prod', 'https://platform.catalyst-growth.com').replace(/\/$/, '')
const DIST = path.resolve(arg('dist', '/tmp/s52-dist-head'))
const OUT = arg('out', null)
const SHOTS = arg('shots', null)
const PORT = Number(arg('port', '5196'))

const ROUTES = ['/sign-in', '/sign-up', '/forgot-password', '/delete-account', '/no-such-page']
const VIEWPORTS = { desktop: { width: 1280, height: 800 }, mobile: { width: 390, height: 844 } }

/** Runs inside the page; the same reads as walk.mjs `probeInPage`, kept self-contained. */
function probeInPage() {
  const STYLE_PROPS = [
    'color',
    'backgroundColor',
    'borderTopColor',
    'borderRadius',
    'fontFamily',
    'fontSize',
    'fontWeight',
    'lineHeight',
    'letterSpacing',
    'paddingTop',
    'paddingLeft',
    'marginTop',
    'width',
    'height',
    'boxShadow',
    'display',
    'opacity',
  ]
  const djb = (s) => {
    let h = 0
    for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0
    return h
  }
  const app = document.querySelector('#app')
  // Digits are normalised (as in the baseline); asset file names carry a build hash, so
  // the hashed names inside `src`, `href` and `url(...)` are masked too.
  const dom = (app ? app.outerHTML : '')
    .replace(/(assets\/[A-Za-z0-9_.-]*?)-[A-Za-z0-9_-]{8}(\.[a-z0-9]+)/g, '$1-H$2')
    .replace(/\d+/g, '#')
  let styleHash = 0
  let elements = 0
  for (const el of document.querySelectorAll('#app *')) {
    const c = getComputedStyle(el)
    const s = el.tagName + '|' + STYLE_PROPS.map((p) => c[p]).join('|')
    for (let i = 0; i < s.length; i++) styleHash = (styleHash * 31 + s.charCodeAt(i)) | 0
    elements++
  }
  const sheet = document.getElementById('vuetify-theme-stylesheet')
  const sheetText = sheet ? sheet.textContent || '' : ''
  return {
    dom,
    domLen: dom.length,
    domHash: djb(dom),
    styleHash,
    elements,
    themeSheetLen: sheetText.length,
    themeSheetHash: djb(sheetText),
    agencyClassCount: document.querySelectorAll('[class*="v-theme--agency"]').length,
    htmlClass: document.documentElement.className,
    bodyClass: document.body.className,
    title: document.title,
  }
}

async function readOne(browser, origin, route, theme, vp, shotPath) {
  const context = await browser.newContext({
    viewport: VIEWPORTS[vp],
    deviceScaleFactor: 1,
    reducedMotion: 'reduce',
    colorScheme: 'dark',
    locale: 'en-US',
    timezoneId: 'Europe/Madrid',
  })
  await context.addInitScript((t) => window.localStorage.setItem('catalyst.main.theme', t), theme)
  const blocked = []
  await context.route(
    (url) => /^\/(api|sanctum)\//.test(url.pathname),
    (r) => {
      blocked.push(`${r.request().method()} ${new URL(r.request().url()).pathname}`)
      return r.fulfill({ status: 503, contentType: 'application/json', body: '{}' })
    },
  )
  // An API on another origin (the production API host) is answered the same way.
  await context.route(/platform-api\.catalyst-growth\.com/, (r) => {
    blocked.push(`${r.request().method()} ${new URL(r.request().url()).pathname} (api host)`)
    return r.fulfill({ status: 503, contentType: 'application/json', body: '{}' })
  })
  const page = await context.newPage()
  const errors = []
  page.on('pageerror', (e) => errors.push(e.message))
  await page.goto(origin + route, { waitUntil: 'load', timeout: 60000 })
  await page.waitForSelector('#app .v-application', { timeout: 30000 })
  await page.evaluate(() => document.fonts.ready)
  await page.waitForTimeout(2500)
  const probe = await page.evaluate(probeInPage)
  if (shotPath) await page.screenshot({ path: shotPath, animations: 'disabled', caret: 'hide' })
  await context.close()
  return { ...probe, blocked: [...new Set(blocked)].sort(), errors }
}

const server = await preview({
  root: path.resolve(DIST, '..'),
  logLevel: 'error',
  build: { outDir: DIST },
  preview: { host: '127.0.0.1', port: PORT, strictPort: true },
})
const local = `http://127.0.0.1:${PORT}`
const browser = await chromium.launch()
if (SHOTS) mkdirSync(SHOTS, { recursive: true })
const rows = []
try {
  for (const route of ROUTES) {
    for (const theme of ['dark', 'light']) {
      for (const vp of Object.keys(VIEWPORTS)) {
        const id = `${route}.${theme}.${vp}`
        const shot = (side) =>
          SHOTS ? path.join(SHOTS, `${side}${route.replace(/\//g, '_')}.${theme}.${vp}.png`) : null
        const prod = await readOne(browser, PROD, route, theme, vp, shot('prod'))
        const mine = await readOne(browser, local, route, theme, vp, shot('local'))
        const same = {
          domHash: prod.domHash === mine.domHash,
          styleHash: prod.styleHash === mine.styleHash,
          themeSheet: prod.themeSheetHash === mine.themeSheetHash,
          noAgency: prod.agencyClassCount === 0 && mine.agencyClassCount === 0,
          htmlBody: prod.htmlClass === mine.htmlClass && prod.bodyClass === mine.bodyClass,
        }
        const ok = Object.values(same).every(Boolean)
        console.log(`${ok ? 'SAME' : 'DIFF'}  ${id}  elements ${prod.elements}/${mine.elements}`)
        rows.push({ id, ok, same, prod, local: mine })
      }
    }
  }
} finally {
  await browser.close()
  await server.close()
}

const bad = rows.filter((r) => !r.ok)
const summary = `${rows.length - bad.length}/${rows.length} stops identical`
console.log(bad.length === 0 ? `PROD COMPARE OK: ${summary}` : `PROD COMPARE DIFF: ${summary}`)
if (OUT) {
  mkdirSync(path.dirname(path.resolve(OUT)), { recursive: true })
  writeFileSync(OUT, JSON.stringify(rows, null, 1) + '\n')
}
process.exit(bad.length === 0 ? 0 : 1)
