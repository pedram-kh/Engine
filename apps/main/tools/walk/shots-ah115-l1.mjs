#!/usr/bin/env node
/**
 * AH-115 L1 review screenshots: the creator shell on the web, dark and light.
 *
 *   node tools/walk/shots-ah115-l1.mjs --out ../../playwright-report/ah-115-l1
 *
 * Files (per theme): `dashboard-1280` (topbar nav at 1280), `bottom-tabs-390` (bottom tabs
 * at 390), `user-menu-1280` (the avatar menu open), `dialog-1280` (a dialog open). The shell
 * (layout, topbar, nav, avatar menu, bottom bar) is REAL; the page inside it is the probe
 * stand-in (the harness has no creator fixtures), labelled in manifest.json. Also records the
 * theme `<style>` facts the L1 gate reports: whether the creator themes are in the sheet, its
 * length, and what is left after a creator -> agency navigation.
 *
 * Dev tool only. Every /api and /sanctum request answers 503.
 */

import { createHash } from 'node:crypto'
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
const OUT = path.resolve(arg('out', '../../playwright-report/ah-115-l1'))
const PORT = Number(arg('port', '5197'))

const server = await createServer({
  root: APP_ROOT,
  logLevel: 'error',
  server: { host: '127.0.0.1', port: PORT, strictPort: true, hmr: false },
})
await server.listen()
const base = `http://127.0.0.1:${PORT}`
const browser = await chromium.launch()
mkdirSync(OUT, { recursive: true })
const manifest = { shell: 'real', page: 'probe stand-in', sheets: {} }

async function open(theme, width, height, to = '/creator/dashboard', user = 'creator') {
  const context = await browser.newContext({
    viewport: { width, height },
    deviceScaleFactor: 1,
    reducedMotion: 'reduce',
    colorScheme: 'dark',
    locale: 'en-US',
    timezoneId: 'Europe/Madrid',
  })
  await context.addInitScript((t) => window.localStorage.setItem('catalyst.main.theme', t), theme)
  await context.route(
    (url) => /^\/(api|sanctum)\//.test(url.pathname),
    (route) => route.fulfill({ status: 503, contentType: 'application/json', body: '{}' }),
  )
  const page = await context.newPage()
  await page.goto(`${base}/tools/walk/index.html?${new URLSearchParams({ to, user, theme })}`, {
    waitUntil: 'domcontentloaded',
  })
  await page.waitForFunction(() => window.__walk && window.__walk.ready === true, null, {
    timeout: 60000,
  })
  await page.evaluate(() => document.fonts.ready)
  await page.waitForTimeout(800)
  return { context, page }
}

const sheetOf = (page) =>
  page.evaluate(() => document.getElementById('vuetify-theme-stylesheet')?.textContent ?? '')

try {
  // warm the optimiser
  await (await open('dark', 1280, 800)).context.close()
  await new Promise((r) => setTimeout(r, 3000))

  for (const theme of ['dark', 'light']) {
    const shot = (page, name) =>
      page.screenshot({ path: path.join(OUT, `${theme}-${name}.png`), animations: 'disabled' })

    let h = await open(theme, 1280, 800)
    await shot(h.page, 'dashboard-1280')
    const sheet = await sheetOf(h.page)
    manifest.sheets[theme] = {
      length: sheet.length,
      sha: createHash('sha256').update(sheet).digest('hex'),
      hasCreatorDark: sheet.includes('.v-theme--creator-dark'),
      hasCreatorLight: sheet.includes('.v-theme--creator-light'),
      hasAgency: /agency/.test(sheet),
      appThemeClass: await h.page.evaluate(
        () => document.querySelector('.v-application')?.className.match(/v-theme--[\w-]+/)?.[0],
      ),
    }
    await h.page.click('[data-test="user-menu-btn"]')
    await h.page.waitForSelector('[data-test="user-menu"]')
    await h.page.waitForTimeout(500)
    await shot(h.page, 'user-menu-1280')
    await h.page.keyboard.press('Escape')
    await h.page.waitForTimeout(400)
    await h.page.click('[data-test="probe-dialog-btn"]')
    await h.page.waitForSelector('[data-test="probe-dialog-card"]')
    await h.page.waitForTimeout(500)
    await shot(h.page, 'dialog-1280')
    await h.context.close()

    h = await open(theme, 390, 844)
    await shot(h.page, 'bottom-tabs-390')
    await h.page.click('[data-test="creator-bottom-nav-more"]')
    await h.page.waitForTimeout(500)
    await shot(h.page, 'bottom-tabs-more-390')
    await h.context.close()

    // creator -> agency: the sheet must hold no creator theme once the creator layout is gone.
    h = await open(theme, 1280, 800, '/creator/dashboard', 'agency')
    const before = await sheetOf(h.page)
    await h.page.evaluate(() => window.__walk.push('/campaigns'))
    await h.page.waitForTimeout(800)
    const after = await sheetOf(h.page)
    manifest.sheets[`${theme}-creator-to-agency`] = {
      creatorThemesInSheetOnCreatorPage: /creator-(dark|light)/.test(before),
      creatorThemesInSheetAfterAgencyNav: /creator-(dark|light)/.test(after),
      agencyThemesInSheetAfter: /agency-(dark|light)/.test(after),
    }
    await h.context.close()
  }
} finally {
  await browser.close()
  await server.close()
}
writeFileSync(path.join(OUT, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n')
console.log(JSON.stringify(manifest, null, 2))
