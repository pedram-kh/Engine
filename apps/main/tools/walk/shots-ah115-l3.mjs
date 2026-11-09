#!/usr/bin/env node
/**
 * AH-115 L3 review screenshots: REAL creator pages (mock API) in the creator web look, dark and
 * light, at 1280 and 390. Also logs every /api request no fixture answered (503), so a page that
 * is rendering its error state is visible.
 *
 *   node tools/walk/shots-ah115-l3.mjs --out ../../playwright-report/ah-115-l3 [--only dashboard]
 *
 * Dev tool only.
 */

import { mkdirSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

import { chromium } from '@playwright/test'
import { createServer } from 'vite'

import { ASSIGNMENT, JOB, l3CreatorFixtures } from './l3-creator-fixtures.mjs'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const APP_ROOT = path.resolve(HERE, '../..')
function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`)
  return i === -1 ? fallback : process.argv[i + 1]
}
const OUT = path.resolve(arg('out', '../../playwright-report/ah-115-l3'))
const PORT = Number(arg('port', '5197'))
const ONLY = arg('only', null)

// [name, user, route, real route names, creator status]
const CASES = [
  ['dashboard', 'creator', '/creator/dashboard', 'creator.dashboard', 'approved'],
  ['availability', 'creator', '/creator/availability', 'creator.availability', 'approved'],
  ['assignments', 'creator', '/creator/assignments', 'creator.assignments', 'approved'],
  [
    'assignment-detail',
    'creator',
    `/creator/assignments/${ASSIGNMENT}`,
    'creator.assignment.detail',
    'approved',
  ],
  ['onboarding-profile', 'creator', '/onboarding/profile', 'onboarding.profile', 'incomplete'],
  ['profile', 'creator', '/creator/profile', 'creator.profile', 'approved'],
  ['job-detail', 'creator', `/creator/jobs/${JOB}`, 'creator.job.detail', 'approved'],
  ['sign-in', 'none', '/sign-in', 'auth.sign-in', 'approved'],
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

async function shot(theme, width, [name, user, to, real, status]) {
  const fixtures = l3CreatorFixtures({ status })
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
      const body = fixtures(url, route.request().method())
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
  page.on(
    'console',
    (m) => m.type() === 'error' && console.log(`[${name}] console: ${m.text().slice(0, 200)}`),
  )
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
  await page.waitForTimeout(1500)
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
