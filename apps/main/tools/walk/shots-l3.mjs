#!/usr/bin/env node
/**
 * AH-114 L3 review screenshots: the REAL page components (Campaigns, Campaign detail,
 * Discover, Messages) over the mock API in `l3-fixtures.mjs`, in the real agency shell,
 * dark and light.
 *
 *   node tools/walk/shots-l3.mjs --out /tmp/ah114-l3 [--only campaigns-1600,...] [--theme dark|light]
 *
 * `--only` takes ids from `REAL_PAGES`; default is every id. Files are
 * `<theme>-<id>.png`; `manifest.json` records them. Dev tool only.
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
const OUT = path.resolve(arg('out', '/tmp/ah114-l3'))
const ONLY_THEME = arg('theme', null)
const ONLY = arg('only', null)?.split(',') ?? Object.keys(REAL_PAGES)
const PORT = Number(arg('port', '5197'))

async function newPage(browser, theme, base, real) {
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
      const body = fixtureFor(new URL(route.request().url()), route.request().method())
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
  const qs = new URLSearchParams({ to: real.to, user: 'agency', theme, real: real.names })
  await page.goto(`${base}/tools/walk/index.html?${qs}`, { waitUntil: 'domcontentloaded' })
  await page.waitForFunction(() => window.__walk && window.__walk.ready === true, null, {
    timeout: 60000,
  })
  await page.evaluate(() => document.fonts.ready)
  await page.waitForTimeout(1200)
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

try {
  {
    // Warm the dev dependency optimiser.
    const w = await newPage(browser, 'dark', base, REAL_PAGES[ONLY[0]])
    await w.context.close()
    await new Promise((r) => setTimeout(r, 2000))
  }
  for (const theme of ONLY_THEME ? [ONLY_THEME] : ['dark', 'light']) {
    for (const id of ONLY) {
      const real = REAL_PAGES[id]
      if (!real) throw new Error(`unknown page ${id}`)
      const w = await newPage(browser, theme, base, real)
      const file = `${theme}-${id}.png`
      await w.page.screenshot({
        path: path.join(OUT, file),
        animations: 'disabled',
        caret: 'hide',
        fullPage: real.fullPage === true,
      })
      manifest.push({ file, theme, id, route: real.to, width: real.width })
      await w.context.close()
    }
  }
} finally {
  await browser.close()
  await server.close()
}
writeFileSync(path.join(OUT, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n')
console.log(`wrote ${manifest.length} screenshots to ${OUT}`)
process.exit(0)
