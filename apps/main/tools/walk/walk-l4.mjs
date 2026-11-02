#!/usr/bin/env node
/**
 * AH-114 L4 layer gate: walk every agency route (the real page over the mock API, in the
 * real agency shell), dark and light, at 1600 and 1280. For each stop it records
 * what the page adopted (which R-rule markers are in the DOM), and checks for the things a
 * restyle can break: horizontal page overflow, console or page errors, an error alert,
 * a leftover status chip, an un-themed shell.
 *
 *   node tools/walk/walk-l4.mjs --out <FRESH dir> [--widths 1600,1280] [--theme dark|light]
 *
 * Writes `<theme>-<route>-<width>.png`, `walk.json` and `walk.md` (the route-by-rules table).
 * Dev tool only.
 */

import { mkdirSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

import { chromium } from '@playwright/test'
import { createServer } from 'vite'

import { REAL_PAGES, fixtureFor } from './l3-fixtures.mjs'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const APP_ROOT = path.resolve(HERE, '../..')

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`)
  return i === -1 ? fallback : process.argv[i + 1]
}
const OUT = path.resolve(arg('out', '/tmp/ah114-l4-walk'))
const WIDTHS = arg('widths', '1600,1280').split(',')
const THEMES = arg('theme', null) ? [arg('theme', null)] : ['dark', 'light']
const ONLY = arg('only', null)?.split(',') ?? null
const PORT = Number(arg('port', '5198'))

/** The 24 `meta.layout: 'agency'` routes (inventory 5.2, corrected: line 205 is a comment). */
const ROUTES = [
  ['/', 'dashboard'],
  ['/roster', 'roster'],
  ['/roster/:ulid', 'roster-detail'],
  ['/messages', 'messages'],
  ['/messages/:creatorUlid', 'messages-thread'],
  ['/discover', 'discover'],
  ['/discover/:ulid', 'discover-profile'],
  ['/brands', 'brands'],
  ['/brands/new', 'brand-create'],
  ['/brands/:ulid', 'brand-detail'],
  ['/brands/:ulid/edit', 'brand-edit'],
  ['/campaigns', 'campaigns'],
  ['/campaigns/new', 'campaign-create'],
  ['/campaigns/:ulid', 'campaign-detail'],
  ['/talent-pools', 'pools'],
  ['/talent-pools/new', 'pool-create'],
  ['/talent-pools/:ulid', 'pool-detail'],
  ['/talent-pools/:ulid/edit', 'pool-edit'],
  ['/files', 'files'],
  ['/agency-users', 'team'],
  ['/creator-invitations/bulk', 'bulk-invite'],
  ['/settings', 'settings'],
  ['/notifications', 'notifications'],
  ['/notifications/preferences', 'notification-prefs'],
]

/** Rule markers: a rule counts as adopted when its marker is in the DOM. */
const MARKERS = {
  R3: '.agency-page-header',
  R4: '.agency-filters, .agency-seg, .agency-search, .agency-filter',
  R5: '.agency-table',
  R6: '.agency-detail',
  R7: '.agency-card, .agency-kpi',
  R8: '[class*="agency-inbox"], [class*="agency-thread"], [class*="agency-relationship"]',
  R9: '.agency-status-pill',
  R10: '.agency-card--form',
}

async function newPage(browser, theme, base, real, sink) {
  const context = await browser.newContext({
    viewport: { width: real.width, height: real.height ?? 1000 },
    deviceScaleFactor: 1,
    reducedMotion: 'reduce',
    colorScheme: 'dark',
    locale: 'en-US',
    timezoneId: 'Europe/Madrid',
  })
  await context.addInitScript((t) => window.localStorage.setItem('catalyst.main.theme', t), theme)
  await context.route(
    (url) => /^\/(api|sanctum)\//.test(url.pathname),
    (route) => {
      const url = new URL(route.request().url())
      const body = fixtureFor(url, route.request().method())
      if (!body) sink.unfixtured.push(`${route.request().method()} ${url.pathname}`)
      return body
        ? route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(body),
          })
        : route.fulfill({ status: 503, contentType: 'application/json', body: '{}' })
    },
  )
  const page = await context.newPage()
  page.on('pageerror', (e) => sink.errors.push(`pageerror: ${e.message}`))
  page.on('console', (m) => {
    if (m.type() === 'error' && !/Failed to load resource/.test(m.text()))
      sink.errors.push(`console: ${m.text().slice(0, 200)}`)
  })
  const qs = new URLSearchParams({ to: real.to, user: 'agency', theme, real: real.names })
  await page.goto(`${base}/tools/walk/index.html?${qs}`, { waitUntil: 'domcontentloaded' })
  await page.waitForFunction(() => window.__walk && window.__walk.ready === true, null, {
    timeout: 60000,
  })
  await page.evaluate(() => document.fonts.ready)
  await page.waitForTimeout(1200)
  return { context, page }
}

function probe([markers, themeName]) {
  const adopted = []
  for (const [rule, sel] of Object.entries(markers)) {
    if (document.querySelector(sel)) adopted.push(rule)
  }
  const doc = document.documentElement
  // The roster table scrolls inside its own wrapper by design; only the PAGE may not.
  const pageOverflow = doc.scrollWidth - doc.clientWidth
  // A status chip is a tonal or flat v-chip; filter chips (in a group or an agency filter)
  // the availability calendar's own chips and the two shared packages/ui tag components
  // (CategoryChips, BlacklistBadge; not editable here) are not.
  // A status chip is a tonal or flat v-chip; filter chips (in a group or an agency filter)
  // the availability calendar's own chips and the two shared packages/ui tag components
  // (CategoryChips, BlacklistBadge; not editable here) are not.
  const chips = [...document.querySelectorAll('.v-chip--variant-tonal, .v-chip--variant-flat')]
    .filter(
      (c) =>
        !c.closest(
          '.v-chip-group, .agency-filters, .agency-availability-calendar, .category-chips, [data-testid^="blacklist-badge"]',
        ),
    )
    .map((c) => c.textContent.trim().slice(0, 30))
  const h1 = document.querySelector('h1, .agency-page-header__title, h2')
  return {
    adopted,
    pageOverflow,
    errorAlerts: [...document.querySelectorAll('.v-alert')]
      .map((a) => a.textContent.trim().slice(0, 80))
      .filter((t) => t),
    chips,
    themed: !!document.querySelector(`.v-theme--${themeName}`),
    title: h1 ? h1.textContent.trim().slice(0, 60) : null,
    bodyTextLength: document.body.innerText.length,
  }
}

const idFor = (slug, width) => `${slug}-${width}`
const pageFor = (slug, width) => {
  const id = idFor(slug, width)
  if (REAL_PAGES[id]) return REAL_PAGES[id]
  // Not every route has a 1280 entry; reuse the 1600 entry at the requested width.
  const base = REAL_PAGES[idFor(slug, 1600)]
  return base ? { ...base, width: Number(width), height: Math.round(Number(width) * 0.5625) } : null
}

const server = await createServer({
  root: APP_ROOT,
  logLevel: 'error',
  server: { host: '127.0.0.1', port: PORT, strictPort: true, hmr: false },
})
await server.listen()
const base = `http://127.0.0.1:${PORT}`
const browser = await chromium.launch()
mkdirSync(OUT, { recursive: true })
const rows = []

try {
  {
    const sink = { errors: [], unfixtured: [] }
    const w = await newPage(browser, 'dark', base, REAL_PAGES['roster-1600'], sink)
    await w.context.close()
    await new Promise((r) => setTimeout(r, 2000))
  }
  for (const theme of THEMES) {
    for (const [route, slug] of ROUTES) {
      if (ONLY && !ONLY.includes(slug)) continue
      for (const width of WIDTHS) {
        const real = pageFor(slug, width)
        if (!real) {
          rows.push({ route, slug, theme, width, missing: true })
          continue
        }
        const sink = { errors: [], unfixtured: [] }
        console.log(`stop ${theme} ${slug} ${width}`)
        const w = await newPage(browser, theme, base, real, sink)
        const result = await w.page.evaluate(probe, [MARKERS, `agency-${theme}`])
        const file = `${theme}-${slug}-${width}.png`
        await w.page.screenshot({
          path: path.join(OUT, file),
          animations: 'disabled',
          caret: 'hide',
          fullPage: real.fullPage === true,
        })
        rows.push({ route, slug, theme, width, file, ...result, ...sink })
        await w.context.close()
      }
    }
  }
} finally {
  await browser.close()
  await server.close()
}

writeFileSync(path.join(OUT, 'walk.json'), JSON.stringify(rows, null, 2) + '\n')

const problems = rows.filter(
  (r) =>
    r.missing ||
    r.pageOverflow > 0 ||
    r.errors?.length ||
    r.errorAlerts?.length ||
    r.chips?.length ||
    !r.themed ||
    (!r.title && !/^(messages|notifications$)/.test(r.slug)),
)
const byRoute = new Map()
for (const r of rows) {
  if (r.missing || r.theme !== THEMES[0] || r.width !== WIDTHS[0]) continue
  byRoute.set(r.route, r)
}
const md = [
  '| Route | Rules adopted (DOM markers) | Title |',
  '| --- | --- | --- |',
  ...[...byRoute.values()].map(
    (r) => `| \`${r.route}\` | ${r.adopted.join(', ') || 'token-level only'} | ${r.title ?? ''} |`,
  ),
  '',
  `${rows.length} stops, ${problems.length} with a problem.`,
  ...problems.map(
    (p) =>
      `- ${p.theme} ${p.slug} ${p.width}: ${
        p.missing
          ? 'no REAL_PAGES entry'
          : JSON.stringify({
              overflow: p.pageOverflow,
              errors: p.errors,
              alerts: p.errorAlerts,
              chips: p.chips,
              themed: p.themed,
              title: p.title,
            })
      }`,
  ),
]
writeFileSync(path.join(OUT, 'walk.md'), md.join('\n') + '\n')
console.log(md.join('\n'))
console.log(`WALK: ${rows.length} stops, ${problems.length} problems -> ${OUT}`)
process.exit(0)
