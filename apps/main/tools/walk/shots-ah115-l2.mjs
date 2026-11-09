#!/usr/bin/env node
/**
 * AH-115 L2 review screenshots: the shared messaging and notification components in the creator
 * shell (dark and light) and in the agency shell (unchanged). Real page components fed mock API
 * fixtures: creator inbox, creator thread (the relationship chat), creator notification centre,
 * and the agency thread, inbox and notification centre.
 *
 *   node tools/walk/shots-ah115-l2.mjs --out ../../playwright-report/ah-115-l2
 *
 * Dev tool only. /api requests not covered by a fixture answer 503.
 */

import { mkdirSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

import { chromium } from '@playwright/test'
import { createServer } from 'vite'

import { CREATOR_AGENCY, creatorFixtureFor } from './l2-fixtures.mjs'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const APP_ROOT = path.resolve(HERE, '../..')
function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`)
  return i === -1 ? fallback : process.argv[i + 1]
}
const OUT = path.resolve(arg('out', '../../playwright-report/ah-115-l2'))
const PORT = Number(arg('port', '5197'))
const THREAD_CREATOR = '01HCREATORMAYA00000000001'

const CASES = [
  ['creator-inbox', 'creator', '/creator/messages', 'creator.messages,creator.messages.thread'],
  [
    'creator-chat',
    'creator',
    `/creator/messages/${CREATOR_AGENCY}`,
    'creator.messages,creator.messages.thread',
  ],
  ['creator-notifications', 'creator', '/creator/notifications', 'creator.notifications'],
  ['agency-inbox', 'agency', '/messages', 'messages.inbox,messages.thread'],
  [
    'agency-chat',
    'agency',
    `/messages/${THREAD_CREATOR}?name=Maya%20Thompson`,
    'messages.inbox,messages.thread',
  ],
  ['agency-notifications', 'agency', '/notifications', 'notifications'],
]

const server = await createServer({
  root: APP_ROOT,
  logLevel: 'error',
  server: { host: '127.0.0.1', port: PORT, strictPort: true, hmr: false },
})
await server.listen()
const base = `http://127.0.0.1:${PORT}`
const browser = await chromium.launch()
mkdirSync(OUT, { recursive: true })

async function shot(theme, [name, user, to, real], size) {
  const context = await browser.newContext({
    viewport: size,
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
      const body = creatorFixtureFor(url, route.request().method())
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
  await page.goto(
    `${base}/tools/walk/index.html?${new URLSearchParams({ to, user, theme, real })}`,
    {
      waitUntil: 'domcontentloaded',
    },
  )
  await page.waitForFunction(() => window.__walk && window.__walk.ready === true, null, {
    timeout: 60000,
  })
  await page.evaluate(() => document.fonts.ready)
  await page.waitForTimeout(1200)
  await page.screenshot({ path: path.join(OUT, `${theme}-${name}.png`), animations: 'disabled' })
  await context.close()
}

try {
  await shot('dark', CASES[0], { width: 1280, height: 800 }) // warm the optimiser
  await new Promise((r) => setTimeout(r, 2000))
  for (const theme of ['dark', 'light']) {
    for (const c of CASES) await shot(theme, c, { width: 1280, height: 800 })
  }
} finally {
  await browser.close()
  await server.close()
}
console.log('done')
