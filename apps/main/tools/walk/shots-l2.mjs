#!/usr/bin/env node
/**
 * AH-114 L2 review screenshots: the status pill, the inbox, the conversation pane and the KPI
 * cards (stand-ins on the second probe page: no agency page adopts them yet), and the real shell
 * with the top-bar bell. Dark and light, 2x pixel density so the detail is readable.
 *
 *   node tools/walk/shots-l2.mjs --out /tmp/ah114-l2 [--theme dark|light] [--only thread,inbox]
 *
 * Dev tool only. Every /api and /sanctum request answers 503, except the bell's unread count,
 * which a fixture answers so the badge can be seen.
 */

import { mkdirSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

import { chromium } from '@playwright/test'
import { createServer } from 'vite'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const APP_ROOT = path.resolve(HERE, '../..')

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`)
  return i === -1 ? fallback : process.argv[i + 1]
}
const OUT = path.resolve(arg('out', '/tmp/ah114-l2'))
const ONLY_THEME = arg('theme', null)
const ONLY = arg('only', null)?.split(',') ?? null
const PORT = Number(arg('port', '5196'))
const want = (name) => ONLY === null || ONLY.includes(name)

const UNREAD = Number(arg('unread', '7'))

async function newPage(browser, theme, base, viewport) {
  const context = await browser.newContext({
    viewport,
    deviceScaleFactor: 2,
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
      if (/\/notifications\/unread-count$/.test(url.pathname)) {
        return route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: { type: 'notification_unread_count', attributes: { unread_count: UNREAD } },
          }),
        })
      }
      return route.fulfill({ status: 503, contentType: 'application/json', body: '{}' })
    },
  )
  const page = await context.newPage()
  const qs = new URLSearchParams({ to: '/brands', user: 'agency', theme, probe: 'extra' })
  await page.goto(`${base}/tools/walk/index.html?${qs}`, { waitUntil: 'domcontentloaded' })
  await page.waitForFunction(() => window.__walk && window.__walk.ready === true, null, {
    timeout: 60000,
  })
  await page.evaluate(() => document.fonts.ready)
  await page.waitForTimeout(900)
  return { context, page }
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
const manifest = []

async function crop(page, theme, name, selector, note, kind = 'stand-in') {
  const file = `${theme}-${name}.png`
  const el = page.locator(selector).first()
  await el.scrollIntoViewIfNeeded()
  await page.waitForTimeout(250)
  await el.screenshot({ path: path.join(OUT, file), animations: 'disabled', caret: 'hide' })
  manifest.push({ file, theme, name, kind, note })
}

try {
  {
    const w = await newPage(browser, 'dark', base, { width: 1280, height: 1000 })
    await w.context.close()
    await new Promise((r) => setTimeout(r, 3000))
  }
  for (const theme of ONLY_THEME ? [ONLY_THEME] : ['dark', 'light']) {
    const { context, page } = await newPage(browser, theme, base, { width: 1280, height: 1000 })
    if (want('pills')) {
      await crop(
        page,
        theme,
        'status-pills',
        '[data-test="extra-pills"]',
        'all six tones and two table-mode pills (stand-in)',
      )
    }
    if (want('inbox')) {
      await crop(
        page,
        theme,
        'inbox',
        '[data-test="extra-inbox-pane"]',
        'AgencyRelationshipInbox on four stand-in rows: active, unread with tag, read, over-long',
      )
    }
    if (want('thread')) {
      await crop(
        page,
        theme,
        'thread',
        '[data-test="extra-thread-pane"]',
        'AgencyRelationshipThreadView on a stand-in conversation behind a fake transport',
      )
    }
    if (want('kpi')) {
      await crop(
        page,
        theme,
        'kpi',
        '[data-test="extra-kpis"]',
        'AgencyKpiCard variants (stand-in): icon + link, icon, plain, loading, placeholder',
      )
    }
    if (want('bell')) {
      // The real shell's top bar, with the real (forked) bell and a fixture unread count.
      await page.evaluate(() => window.scrollTo(0, 0))
      await page.waitForTimeout(1200)
      await crop(
        page,
        theme,
        'bell-topbar',
        '.v-app-bar',
        `real shell top bar, real bell, fixture unread count ${UNREAD}`,
        'real shell, mock API',
      )
    }
    await context.close()
  }
} finally {
  await browser.close()
  await server.close()
}
writeFileSync(path.join(OUT, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n')
console.log(`wrote ${manifest.length} screenshots to ${OUT}`)
process.exit(0)
