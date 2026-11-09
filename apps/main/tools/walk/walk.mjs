#!/usr/bin/env node
/**
 * AH-114 walk: drives `tools/walk/index.html` (the stub harness) in headless
 * Chromium and records, for every target, a DOM hash, a computed-style hash,
 * the Vuetify theme-stylesheet hash, a count of agency theme classes, and a
 * screenshot hash. It can compare a run against a recorded baseline.
 *
 *   node tools/walk/walk.mjs --set creator --out /tmp/run.json [--shots /tmp/shots]
 *   node tools/walk/walk.mjs --set creator --compare ../../docs/reviews/ah-114-creator-baseline/baseline.json
 *   node tools/walk/walk.mjs --set creator --only creator.dashboard --compare <baseline>   (subset)
 *
 * Sets:
 *   creator   every non-agency route (creator, onboarding, auth, app), dark and
 *             light, 1280 and 390 wide, plus agency-to-creator crossover pairs
 *   shell     the agency shell on the probe page, dark and light (screenshots)
 *   agency    AH-115 L0.1: the 24 agency routes as REAL pages over the mock API
 *             (l3-fixtures), dark and light, 1280 and 390 wide, plus creator-to-agency
 *             crossover pairs (the "creator theme leaves nothing behind" check)
 *
 * Dev tool only. It starts its own Vite dev server on a private port, uses the
 * real app code and NO API (every /api and /sanctum request answers 503).
 */

import { FROZEN_NOW } from './freeze-clock.mjs'

import { createHash } from 'node:crypto'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

import { chromium } from '@playwright/test'
import sharp from 'sharp'
import { createServer } from 'vite'

import { AGENCY_ROUTES, agencyPageFor, PROTECTED_AUTH } from './agency-routes.mjs'
import { fixtureFor } from './l3-fixtures.mjs'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const APP_ROOT = path.resolve(HERE, '../..')

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`)
  return i === -1 ? fallback : process.argv[i + 1]
}

const SET = arg('set', 'creator')
const OUT = arg('out', null)
const COMPARE = arg('compare', null)
const SHOTS = arg('shots', null)
const PORT = Number(arg('port', '5199'))
const BASE = arg('base', null)
const CONCURRENCY = Number(arg('concurrency', '4'))
const ONLY = arg('only', null) // substring filter on route names (a subset run; the missing-target check is skipped)

const VIEWPORTS = {
  desktop: { width: 1280, height: 800 },
  wide: { width: 1600, height: 900 },
  mobile: { width: 390, height: 844 },
}

/** Runs inside the page. Kept self-contained (it is serialised by Playwright). */
function probeInPage(opts) {
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
  const rootSel = opts.rootSel || '#app'
  const app = document.querySelector(rootSel)
  const dom = app ? app.outerHTML.replace(/\d+/g, '#') : ''
  let styleHash = 0
  let elements = 0
  const els = [...document.querySelectorAll(`${rootSel} *`)]
  if (opts.includeOverlays) {
    els.push(...document.querySelectorAll('.v-overlay-container *'))
  }
  for (const el of els) {
    const c = getComputedStyle(el)
    // KNOWN NON-DETERMINISTIC SOURCE (AH-115 L1): an indeterminate progress bar animates its own
    // `width` (a keyframe snapshot taken at whatever frame the capture lands on), so that one
    // property is masked. Nothing else is: every other property and element still counts.
    const animated = el.matches('.v-progress-linear__indeterminate')
    const s =
      el.tagName + '|' + STYLE_PROPS.map((p) => (animated && p === 'width' ? '~' : c[p])).join('|')
    for (let i = 0; i < s.length; i++) styleHash = (styleHash * 31 + s.charCodeAt(i)) | 0
    elements++
  }
  const sheet = document.getElementById('vuetify-theme-stylesheet')
  const sheetText = sheet ? sheet.textContent || '' : ''
  const root = document.querySelector('.v-application')
  const q = (sel) => document.querySelector(sel)
  const font = (sel) => {
    const el = q(sel)
    return el ? getComputedStyle(el).fontFamily : null
  }
  return {
    url: location.pathname + location.search,
    domLen: dom.length,
    domHash: djb(dom),
    styleHash,
    elements,
    themeSheetLen: sheetText.length,
    themeSheetSha: sheetText,
    themeSheetHasAgency: /agency/.test(sheetText),
    agencyClassCount: document.querySelectorAll('[class*="v-theme--agency"]').length,
    rootClass: root ? root.className : null,
    rootBg: root ? getComputedStyle(root).backgroundColor : null,
    htmlBg: getComputedStyle(document.documentElement).backgroundColor,
    bodyBg: getComputedStyle(document.body).backgroundColor,
    htmlClass: document.documentElement.className,
    htmlStyle: document.documentElement.getAttribute('style'),
    bodyClass: document.body.className,
    bodyStyle: document.body.getAttribute('style'),
    fonts: {
      h6: font('[data-test=probe-h6]'),
      body2: font('[data-test=probe-body2]'),
      caption: font('[data-test=probe-caption]'),
      html: getComputedStyle(document.documentElement).fontFamily,
    },
    errors: window.__walk ? window.__walk.errors.slice() : [],
  }
}

const sha = (x) => createHash('sha256').update(x).digest('hex')

/**
 * Screenshots are not bit-stable in Chromium (a few anti-aliased pixels move between
 * runs), so they are compared as a 32x20 greyscale thumbnail with a tolerance. The DOM
 * hash, the computed-style hash and the theme-stylesheet hash are the exact checks.
 */
async function thumbnail(png) {
  const raw = await sharp(png)
    .greyscale()
    .resize(32, 20, { fit: 'fill', kernel: 'cubic' })
    .raw()
    .toBuffer()
  return raw.toString('hex')
}
const THUMB_CELL_MAX = 12
const THUMB_MEAN_MAX = 1.0

function thumbDiff(a, b) {
  if (a === undefined || b === undefined || a.length !== b.length)
    return { cell: Infinity, mean: Infinity }
  let max = 0
  let sum = 0
  const n = a.length / 2
  for (let i = 0; i < n; i++) {
    const d = Math.abs(
      parseInt(a.slice(i * 2, i * 2 + 2), 16) - parseInt(b.slice(i * 2, i * 2 + 2), 16),
    )
    if (d > max) max = d
    sum += d
  }
  return { cell: max, mean: sum / n }
}

async function newPage(browser, { viewport, theme, user, base, fixtures = false }) {
  const context = await browser.newContext({
    viewport: VIEWPORTS[viewport],
    deviceScaleFactor: 1,
    reducedMotion: 'reduce',
    colorScheme: 'dark',
    locale: 'en-US',
    timezoneId: 'Europe/Madrid',
  })
  await context.addInitScript((t) => {
    try {
      window.localStorage.setItem('catalyst.main.theme', t)
    } catch {
      /* storage unavailable: harmless */
    }
  }, theme)
  // Only the real API prefixes: a bare /api/ regex also matches source paths such as /src/modules/auth/api/.
  await context.route(
    (url) => /^\/(api|sanctum)\//.test(url.pathname),
    (route) => {
      if (fixtures) {
        const url = new URL(route.request().url())
        const body = fixtureFor(url, route.request().method())
        if (body) {
          return route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(body),
          })
        }
      }
      return route.fulfill({ status: 503, contentType: 'application/json', body: '{}' })
    },
  )
  // The agency set freezes the page clock too (clock-text widths such as a bubble time).
  if (fixtures) await context.clock.setFixedTime(new Date(FROZEN_NOW))
  const page = await context.newPage()
  return { context, page, base, theme, user }
}

async function open(handle, route, { real = '', settle = 400 } = {}) {
  const { page, base, theme, user } = handle
  const qs = new URLSearchParams({ to: route, user, theme })
  if (real) qs.set('real', real)
  await page.goto(`${base}/tools/walk/index.html?${qs}`, { waitUntil: 'domcontentloaded' })
  await page.waitForFunction(() => window.__walk && window.__walk.ready === true, null, {
    timeout: 60000,
  })
  await page.evaluate(() => document.fonts.ready)
  // AH-115 L5: `fonts.ready` can resolve before a face is requested at all (nothing has used it yet).
  // The locale select is auto-width and its options are written in every script (Latin-extended,
  // Cyrillic, Greek), so its width depends on which Inter subset files have arrived (MEASURED:
  // 67.34 px against 67.67 px, only the targets that show the select). Request every subset and
  // weight explicitly, then wait.
  await page.evaluate(async () => {
    const sample = 'Aa ąžŁ Ωλ Жд ăâ ệ'
    await Promise.all(
      [400, 500, 600, 700].map((w) =>
        document.fonts.load(`${w} 16px Inter`, sample).catch(() => []),
      ),
    )
    await document.fonts.ready
  })
  await page.waitForTimeout(settle)
}

async function measure(handle, id, meta, opts = {}) {
  const { page } = handle
  const probe = await page.evaluate(probeInPage, opts)
  const png = await page.screenshot({ animations: 'disabled', caret: 'hide' })
  if (SHOTS) {
    mkdirSync(SHOTS, { recursive: true })
    writeFileSync(path.join(SHOTS, `${id.replace(/[^a-z0-9._-]+/gi, '_')}.png`), png)
  }
  const { themeSheetSha, ...rest } = probe
  return { id, ...meta, ...rest, themeSheetSha: sha(themeSheetSha), thumb: await thumbnail(png) }
}

async function creatorTargets(base, browser) {
  const h = await newPage(browser, { viewport: 'desktop', theme: 'dark', user: 'creator', base })
  await open(h, '/creator/dashboard')
  const routes = await h.page.evaluate(() => window.__walk.routes)
  await h.context.close()
  return routes.filter((r) => r.layout !== 'agency' && (!ONLY || r.name.includes(ONLY)))
}

async function pool(items, worker) {
  const out = new Array(items.length)
  let next = 0
  await Promise.all(
    Array.from({ length: CONCURRENCY }, async () => {
      while (next < items.length) {
        const i = next++
        out[i] = await worker(items[i], i)
      }
    }),
  )
  return out
}

async function runCreator(base, browser) {
  const routes = await creatorTargets(base, browser)
  const jobs = []
  for (const r of routes) {
    for (const theme of ['dark', 'light']) {
      for (const viewport of ['desktop', 'mobile']) {
        jobs.push({ r, theme, viewport })
      }
    }
  }
  const direct = await pool(jobs, async ({ r, theme, viewport }) => {
    const user = r.layout === 'auth' || r.layout === 'error' ? 'none' : 'creator'
    const h = await newPage(browser, { viewport, theme, user, base })
    try {
      await open(h, r.path)
      return await measure(h, `${r.name}.${theme}.${viewport}`, {
        kind: 'direct',
        route: r.path,
        name: r.name,
        layout: r.layout,
        theme,
        viewport,
      })
    } finally {
      await h.context.close()
    }
  })
  // Crossover: an agency page first, then a client-side push to the creator route.
  const crossJobs = []
  for (const theme of ['dark', 'light']) {
    for (const r of routes.filter((x) => x.layout === 'creator').slice(0, 3)) {
      crossJobs.push({ r, theme })
    }
  }
  const cross = await pool(crossJobs, async ({ r, theme }) => {
    const h = await newPage(browser, { viewport: 'desktop', theme, user: 'creator', base })
    try {
      await open(h, '/campaigns')
      await h.page.evaluate((p) => window.__walk.push(p), r.path)
      await h.page.waitForTimeout(600)
      return await measure(h, `crossover.${r.name}.${theme}.desktop`, {
        kind: 'crossover',
        route: r.path,
        name: r.name,
        layout: r.layout,
        theme,
        viewport: 'desktop',
      })
    } finally {
      await h.context.close()
    }
  })
  return [...direct, ...cross]
}

async function runShell(base, browser) {
  const out = []
  for (const theme of ['dark', 'light']) {
    const h = await newPage(browser, { viewport: 'wide', theme, user: 'agency', base })
    await open(h, '/campaigns')
    out.push(
      await measure(
        h,
        `shell.${theme}`,
        { kind: 'shell', route: '/campaigns', theme, viewport: 'wide' },
        { includeOverlays: true },
      ),
    )
    await h.context.close()
  }
  return out
}

/** AH-115 L0.1: every agency route as the real page over the mock API, plus creator-to-agency crossovers. */
async function runAgency(base, browser) {
  const jobs = []
  for (const [route, slug] of AGENCY_ROUTES) {
    for (const theme of ['dark', 'light']) {
      for (const viewport of ['desktop', 'mobile']) {
        if (ONLY && !slug.includes(ONLY)) continue
        const page = agencyPageFor(slug, viewport === 'mobile' ? 390 : 1280)
        if (!page) throw new Error(`no REAL_PAGES entry for agency route ${route} (${slug})`)
        jobs.push({ route, slug, theme, viewport, page })
      }
    }
  }
  const direct = await pool(jobs, async ({ slug, theme, viewport, page }) => {
    const h = await newPage(browser, { viewport, theme, user: 'agency', base, fixtures: true })
    try {
      await open(h, page.to, { real: page.names, settle: 1200 })
      return await measure(h, `agency.${slug}.${theme}.${viewport}`, {
        kind: 'direct',
        route: page.to,
        name: slug,
        layout: 'agency',
        theme,
        viewport,
      })
    } finally {
      await h.context.close()
    }
  })
  // Crossover: a creator page first (its layout registers whatever it registers), then a
  // client-side push to the agency route. The agency render must equal the direct one.
  const crossSlugs = ONLY ? [] : ['dashboard', 'campaigns', 'messages']
  const crossJobs = []
  for (const theme of ['dark', 'light']) {
    for (const slug of crossSlugs) crossJobs.push({ slug, theme })
  }
  const cross = await pool(crossJobs, async ({ slug, theme }) => {
    const page = agencyPageFor(slug, 1280)
    const h = await newPage(browser, {
      viewport: 'desktop',
      theme,
      user: 'agency',
      base,
      fixtures: true,
    })
    try {
      await open(h, '/creator/dashboard', { real: page.names })
      await h.page.evaluate((p) => window.__walk.push(p), page.to)
      await h.page.waitForTimeout(1600)
      return await measure(h, `crossover.agency.${slug}.${theme}.desktop`, {
        kind: 'crossover',
        route: page.to,
        name: slug,
        layout: 'agency',
        theme,
        viewport: 'desktop',
      })
    } finally {
      await h.context.close()
    }
  })
  // The auth screens the creator restyle must NOT change (AH-115 rulings Q3 and 1): the agency
  // sign-in and the screens an agency user can reach (2FA, forgot, reset, accept invitation).
  // Stub pages: these routes' own pages are not what is measured, the AuthLayout around them is.
  const authJobs = []
  for (const [name, route] of PROTECTED_AUTH) {
    for (const theme of ['dark', 'light']) {
      for (const viewport of ['desktop', 'mobile']) {
        if (ONLY && !name.includes(ONLY)) continue
        authJobs.push({ name, route, theme, viewport })
      }
    }
  }
  const auth = await pool(authJobs, async ({ name, route, theme, viewport }) => {
    const h = await newPage(browser, { viewport, theme, user: 'none', base, fixtures: true })
    try {
      await open(h, route)
      return await measure(h, `agencyauth.${name}.${theme}.${viewport}`, {
        kind: 'direct',
        route,
        name,
        layout: 'auth',
        theme,
        viewport,
      })
    } finally {
      await h.context.close()
    }
  })
  return [...direct, ...cross, ...auth]
}

function compare(baseline, run) {
  const byId = new Map(baseline.targets.map((t) => [t.id, t]))
  const problems = []
  const FIELDS = [
    'domHash',
    'domLen',
    'styleHash',
    'elements',
    'themeSheetLen',
    'themeSheetSha',
    'agencyClassCount',
  ]
  for (const t of run) {
    const b = byId.get(t.id)
    if (!b) {
      problems.push(`${t.id}: not in baseline`)
      continue
    }
    for (const f of FIELDS) {
      if (JSON.stringify(b[f]) !== JSON.stringify(t[f])) {
        problems.push(`${t.id}: ${f} baseline=${JSON.stringify(b[f])} run=${JSON.stringify(t[f])}`)
      }
    }
    const d = thumbDiff(b.thumb, t.thumb)
    if (d.cell > THUMB_CELL_MAX || d.mean > THUMB_MEAN_MAX) {
      problems.push(
        `${t.id}: screenshot differs (max cell delta ${d.cell}, mean ${d.mean.toFixed(2)})`,
      )
    }
  }
  for (const id of ONLY ? [] : byId.keys()) {
    if (!run.some((t) => t.id === id)) problems.push(`${id}: in baseline, missing from run`)
  }
  return problems
}

const server = BASE
  ? null
  : await createServer({
      root: APP_ROOT,
      logLevel: 'error',
      server: { host: '127.0.0.1', port: PORT, strictPort: true, hmr: false },
    })
if (server) await server.listen()
const base = BASE || `http://127.0.0.1:${PORT}`
const browser = await chromium.launch()
let exit = 0
try {
  // Warm the dependency optimiser so a mid-run "optimized dependencies changed, reloading" cannot hit a target.
  for (const [route, user] of [
    ['/creator/dashboard', 'creator'],
    ['/campaigns', 'agency'],
    ['/sign-in', 'none'],
  ]) {
    const h = await newPage(browser, { viewport: 'desktop', theme: 'dark', user, base })
    await open(h, route)
    await h.context.close()
  }
  await new Promise((r) => setTimeout(r, 4000))

  const targets =
    SET === 'shell'
      ? await runShell(base, browser)
      : SET === 'agency'
        ? await runAgency(base, browser)
        : await runCreator(base, browser)
  const version = browser.version()
  const result = {
    meta: {
      set: SET,
      browser: `chromium ${version}`,
      platform: process.platform,
      targets: targets.length,
    },
    targets,
  }
  if (OUT) {
    mkdirSync(path.dirname(path.resolve(OUT)), { recursive: true })
    writeFileSync(OUT, JSON.stringify(result, null, 1) + '\n')
  }
  const withErrors = targets.filter((t) => t.errors.length)
  console.log(
    `walk ${SET}: ${targets.length} targets, ${withErrors.length} with page errors, chromium ${version}`,
  )
  for (const t of withErrors.slice(0, 5))
    console.log(`  ${t.id}: ${t.errors.join(' | ').slice(0, 200)}`)
  if (COMPARE) {
    const baseline = JSON.parse(readFileSync(COMPARE, 'utf8'))
    const problems = compare(baseline, targets)
    console.log(
      problems.length === 0
        ? `COMPARE OK: ${targets.length} targets identical to ${path.basename(COMPARE)}`
        : `COMPARE FAILED: ${problems.length} difference(s)`,
    )
    for (const p of problems.slice(0, 40)) console.log(`  ${p}`)
    if (problems.length) exit = 1
  }
} finally {
  await browser.close()
  if (server) await server.close()
}
process.exit(exit)
