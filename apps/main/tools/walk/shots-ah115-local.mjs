#!/usr/bin/env node
/**
 * AH-115 local-walk screenshots against the REAL dev stack (web on :5173, API on :8000) with the data
 * of `Ah115WalkSeeder`: signs in as the seeded creator, then captures the given routes, dark and light,
 * 1280 and 390 wide.
 *
 *   node tools/walk/shots-ah115-local.mjs --out ../../playwright-report/ah-115-walk2 \
 *        --routes inbox=/creator/messages,invitations=/creator/assignments [--click '[data-test=x]']
 *
 * Route entries are `name=path`, `name=path@click` (clicked before the shot) and `name=path%scroll` (scrolled into
 * view first); `@` and `%` combine as `path@click%scroll`.
 * A click may also be `fill:<selector>::<text>` (types into a field).
 * Dev tool only. Needs `pnpm dev` and the seeder; never run against anything but local data.
 */

import { mkdirSync } from 'node:fs'
import path from 'node:path'

import { chromium } from '@playwright/test'

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`)
  return i === -1 ? fallback : process.argv[i + 1]
}
const BASE = arg('base', 'http://localhost:5173')
const OUT = path.resolve(arg('out', '../../playwright-report/ah-115-walk2'))
const EMAIL = arg('email', 'lena@ah115-walk.local')
const PASSWORD = 'password-12chars'
const ROUTES = arg('routes', 'inbox=/creator/messages')
  .split(',')
  .map((entry) => {
    const eq = entry.indexOf('=')
    const name = entry.slice(0, eq)
    const rest = entry.slice(eq + 1)
    const [head, scroll] = rest.split('%')
    const [to, click] = head.split('@')
    return { name, to, click: click ?? null, scroll: scroll ?? null }
  })
const THEMES = arg('themes', 'dark,light').split(',')
const WIDTHS = arg('widths', '1280,390').split(',').map(Number)

mkdirSync(OUT, { recursive: true })
const browser = await chromium.launch()

// Sign in ONCE (the API throttles sign-ins) and share the session with every capture context.
const signIn = await browser.newContext()
const signInPage = await signIn.newPage()
await signInPage.goto(`${BASE}/sign-in`, { waitUntil: 'domcontentloaded' })
await signInPage.getByLabel(/email/i).first().fill(EMAIL)
await signInPage.locator('input[type="password"]').first().fill(PASSWORD)
await signInPage.locator('button[type="submit"]').first().click()
await signInPage.waitForURL(/creator|onboarding/, { timeout: 30000 })
const storageState = await signIn.storageState()
await signIn.close()

for (const theme of THEMES) {
  for (const width of WIDTHS) {
    const context = await browser.newContext({
      viewport: { width, height: width > 600 ? 900 : 844 },
      reducedMotion: 'reduce',
      storageState,
    })
    await context.addInitScript((t) => window.localStorage.setItem('catalyst.main.theme', t), theme)
    const page = await context.newPage()

    for (const { name, to, click, scroll } of ROUTES) {
      await page.goto(`${BASE}${to}`, { waitUntil: 'domcontentloaded' })
      await page.waitForLoadState('networkidle').catch(() => undefined)
      await page.waitForTimeout(1200)
      if (click !== null) {
        // `a;;b` clicks a, then b (a popover opened by the first).
        for (const selector of click.split(';;')) {
          if (selector.startsWith('fill:')) {
            const [target, text] = selector.slice(5).split('::')
            await page.locator(target).first().fill(text)
          } else {
            await page.locator(selector).first().click()
          }
          await page.waitForTimeout(800)
        }
        console.log(`${theme}-${width}-${name}: after click -> ${page.url().replace(BASE, '')}`)
      }
      if (scroll !== null) {
        await page.locator(scroll).first().scrollIntoViewIfNeeded()
        await page.waitForTimeout(400)
      }
      await page.screenshot({
        path: path.join(OUT, `${theme}-${width}-${name}.png`),
        fullPage: false,
      })
    }
    await context.close()
  }
}
await browser.close()
console.log(`shots in ${OUT}`)
