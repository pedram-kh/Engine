#!/usr/bin/env node
/**
 * AH-115 L4 review screenshots: the creator unified inbox (flag on: Direct and Brand · Campaign tags),
 * the inbox with a search typed, and the new Notifications page, dark and light, 1280 and 390.
 * Real page components fed mock API fixtures.
 *
 *   node tools/walk/shots-ah115-l4.mjs --out ../../playwright-report/ah-115-l4 [--only inbox]
 *
 * Dev tool only. /api requests not covered by a fixture answer 503.
 */

import { mkdirSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

import { chromium } from '@playwright/test'
import { createServer } from 'vite'

import { creatorInboxFixtureFor } from './l4-creator-inbox-fixtures.mjs'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const APP_ROOT = path.resolve(HERE, '../..')
function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`)
  return i === -1 ? fallback : process.argv[i + 1]
}
const OUT = path.resolve(arg('out', '../../playwright-report/ah-115-l4'))
const PORT = Number(arg('port', '5197'))
const ONLY = arg('only', null)

// [name, route, real route names, features, search term to type]
const CASES = [
  [
    'inbox',
    '/creator/messages',
    'creator.messages,creator.messages.thread',
    'messages_v2_creator_inbox',
    null,
  ],
  [
    'inbox-search',
    '/creator/messages',
    'creator.messages,creator.messages.thread',
    'messages_v2_creator_inbox',
    'first cut',
  ],
  ['inbox-flag-off', '/creator/messages', 'creator.messages,creator.messages.thread', '', null],
  ['notifications', '/creator/notifications', 'creator.notifications', '', null],
].filter(([name]) => !ONLY || name.includes(ONLY))

const SIZES = { 1280: { width: 1280, height: 800 }, 390: { width: 390, height: 844 } }

const server = await createServer({
  root: APP_ROOT,
  logLevel: 'error',
  server: { host: '127.0.0.1', port: PORT, strictPort: true, hmr: false },
})
await server.listen()
const base = `http://127.0.0.1:${PORT}`
const browser = await chromium.launch()
mkdirSync(OUT, { recursive: true })
const misses = new Set()

async function shot(theme, width, [name, to, real, features, term]) {
  const context = await browser.newContext({
    viewport: SIZES[width],
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
      const body = creatorInboxFixtureFor(url, route.request().method())
      if (!body) misses.add(`${name}: ${route.request().method()} ${url.pathname}`)
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
  page.on('pageerror', (e) => console.log(`[${name}] pageerror: ${e.message}`))
  const qs = new URLSearchParams({ to, user: 'creator', theme, real })
  if (features) qs.set('features', features)
  await page.goto(`${base}/tools/walk/index.html?${qs}`, { waitUntil: 'domcontentloaded' })
  await page.waitForFunction(() => window.__walk && window.__walk.ready === true, null, {
    timeout: 60000,
  })
  await page.evaluate(() => document.fonts.ready)
  await page.waitForTimeout(1200)
  if (term) {
    await page.fill('[data-test="creator-inbox-search"] input', term)
    await page.waitForSelector('[data-test="message-search-list"]', { timeout: 10000 })
    await page.waitForTimeout(400)
  }
  await page.screenshot({
    path: path.join(OUT, `${theme}-${width}-${name}.png`),
    animations: 'disabled',
  })
  await context.close()
}

try {
  await shot('dark', 1280, CASES[0]) // warm the optimiser
  await new Promise((r) => setTimeout(r, 2000))
  for (const c of CASES)
    for (const theme of ['dark', 'light']) for (const w of [1280, 390]) await shot(theme, w, c)
} finally {
  await browser.close()
  await server.close()
}
console.log([...misses].sort().join('\n') || 'no unanswered API calls')
