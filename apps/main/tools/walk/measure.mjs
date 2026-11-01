#!/usr/bin/env node
/**
 * AH-114 theme measurements (the S0 spike's five items, re-runnable on the real
 * layout, on the dev server or on a PRODUCTION BUILD of the harness).
 *
 *   node tools/walk/measure.mjs                 dev server
 *   node tools/walk/measure.mjs --build         vite build of the harness, then vite preview
 *   node tools/walk/measure.mjs --build --json /tmp/measure.json
 *
 * Reports, per item:
 *   A  creator page: theme stylesheet length and hash, agency rules in it, agency
 *      classes in the DOM (the creator baseline numbers are in baseline.json)
 *   B  agency route, first paint: on every animation frame from navigation, whether
 *      `.v-application` carries the agency class and whether the stylesheet already
 *      holds the agency rules (no frame may show one without the other, and none may
 *      show the global theme class on the agency root)
 *   C  stored `light`: root class and `html` background at load
 *   D  teleported overlay (the user menu): theme class and computed font-family
 *   E  `text-h6` / `text-body-2` computed font-family; `html` background in dark
 *   F  agency -> creator navigation: nothing left behind (classes, stylesheet)
 *   G  shell geometry and colours (S1.2), dark and light: drawer width, top-bar height,
 *      nav item height/radius/active styles, fills, separators, content padding, avatar label
 *
 * Dev tool only. No API: every /api and /sanctum request answers 503.
 */

import { createHash } from 'node:crypto'
import { writeFileSync } from 'node:fs'
import { mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

import { chromium } from '@playwright/test'
import { build, createServer, preview } from 'vite'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const APP_ROOT = path.resolve(HERE, '../..')

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`)
  return i === -1 ? fallback : process.argv[i + 1]
}
const BUILD = process.argv.includes('--build')
const PORT = Number(arg('port', '5198'))
const JSON_OUT = arg('json', null)

const sha = (text) => createHash('sha256').update(text).digest('hex').slice(0, 16)

/** Installed before any page script: samples every animation frame until the app is ready. */
function frameRecorder() {
  window.__frames = []
  const tick = () => {
    const app = document.querySelector('.v-application')
    const sheet = document.getElementById('vuetify-theme-stylesheet')?.textContent ?? ''
    if (app) {
      window.__frames.push({
        app: [...app.classList].filter((c) => c.startsWith('v-theme--')).join(' '),
        sheetHasAgency: /\.v-theme--agency-(dark|light)/.test(sheet),
      })
    }
    if (!(window.__walk && window.__walk.ready === true) || window.__frames.length < 3) {
      requestAnimationFrame(tick)
    }
  }
  requestAnimationFrame(tick)
}

async function open(browser, { route, user, theme, base }) {
  const context = await browser.newContext({
    viewport: { width: 1280, height: 800 },
    deviceScaleFactor: 1,
    reducedMotion: 'reduce',
    colorScheme: 'dark',
    locale: 'en-US',
    timezoneId: 'Europe/Madrid',
  })
  await context.addInitScript((t) => window.localStorage.setItem('catalyst.main.theme', t), theme)
  await context.addInitScript(frameRecorder)
  await context.route(
    (url) => /^\/(api|sanctum)\//.test(url.pathname),
    (r) => r.fulfill({ status: 503, contentType: 'application/json', body: '{}' }),
  )
  const page = await context.newPage()
  const qs = new URLSearchParams({ to: route, user, theme })
  await page.goto(`${base}/tools/walk/index.html?${qs}`, { waitUntil: 'domcontentloaded' })
  await page.waitForFunction(() => window.__walk && window.__walk.ready === true, null, {
    timeout: 60000,
  })
  await page.evaluate(() => document.fonts.ready)
  await page.waitForTimeout(500)
  return { context, page }
}

const readState = () => {
  const sheet = document.getElementById('vuetify-theme-stylesheet')?.textContent ?? ''
  const app = document.querySelector('.v-application')
  const font = (sel) => {
    const el = document.querySelector(sel)
    return el ? getComputedStyle(el).fontFamily : null
  }
  return {
    sheetLen: sheet.length,
    sheetText: sheet,
    sheetHasAgency: /agency/.test(sheet),
    agencyClassCount: document.querySelectorAll('[class*="v-theme--agency"]').length,
    appClass: app ? [...app.classList].filter((c) => c.startsWith('v-theme--')).join(' ') : null,
    htmlBg: getComputedStyle(document.documentElement).backgroundColor,
    htmlClass: document.documentElement.className,
    bodyClass: document.body.className,
    h6Font: font('.text-h6'),
    body2Font: font('.text-body-2'),
    appBg: app ? getComputedStyle(app).backgroundColor : null,
  }
}

function summariseFrames(frames) {
  const first = frames[0] ?? null
  return {
    framesSeen: frames.length,
    firstFrame: first,
    anyFrameOnGlobalTheme: frames.some(
      (f) => /v-theme--(dark|light)\b/.test(f.app) && !/agency/.test(f.app),
    ),
    anyAgencyClassWithoutRules: frames.some((f) => /agency/.test(f.app) && !f.sheetHasAgency),
  }
}

async function main() {
  const out = { mode: BUILD ? 'production build' : 'dev server' }
  let server = null
  let base
  if (BUILD) {
    const outDir = mkdtempSync(path.join(tmpdir(), 'walk-dist-'))
    await build({
      root: APP_ROOT,
      logLevel: 'error',
      build: {
        outDir,
        emptyOutDir: true,
        rollupOptions: { input: path.join(APP_ROOT, 'tools/walk/index.html') },
      },
    })
    server = await preview({
      root: APP_ROOT,
      logLevel: 'error',
      build: { outDir },
      preview: { host: '127.0.0.1', port: PORT, strictPort: true },
    })
    base = `http://127.0.0.1:${PORT}`
    out.outDir = outDir
  } else {
    server = await createServer({
      root: APP_ROOT,
      logLevel: 'error',
      server: { host: '127.0.0.1', port: PORT, strictPort: true, hmr: false },
    })
    await server.listen()
    base = `http://127.0.0.1:${PORT}`
  }

  const browser = await chromium.launch()
  try {
    if (!BUILD) {
      // Warm the dev dependency optimiser.
      for (const [route, user] of [
        ['/creator/dashboard', 'creator'],
        ['/campaigns', 'agency'],
      ]) {
        const w = await open(browser, { route, user, theme: 'dark', base })
        await w.context.close()
      }
      await new Promise((r) => setTimeout(r, 3000))
    }

    // A: creator page.
    {
      const w = await open(browser, {
        route: '/creator/dashboard',
        user: 'creator',
        theme: 'dark',
        base,
      })
      const s = await w.page.evaluate(readState)
      out.A_creator = {
        sheetLen: s.sheetLen,
        sheetSha: sha(s.sheetText),
        sheetHasAgency: s.sheetHasAgency,
        agencyClassCount: s.agencyClassCount,
        appClass: s.appClass,
        htmlClass: s.htmlClass,
        bodyClass: s.bodyClass,
      }
      await w.context.close()
    }

    // B, E: agency route, dark, from a cold load.
    {
      const w = await open(browser, { route: '/campaigns', user: 'agency', theme: 'dark', base })
      const s = await w.page.evaluate(readState)
      const frames = await w.page.evaluate(() => window.__frames)
      out.B_first_paint = summariseFrames(frames)
      out.E_dark = {
        appClass: s.appClass,
        htmlBackground: s.htmlBg,
        appBackground: s.appBg,
        h6FontFamily: s.h6Font,
        body2FontFamily: s.body2Font,
        sheetHasAgency: s.sheetHasAgency,
        sheetLen: s.sheetLen,
      }

      // D: the user menu overlay.
      await w.page.click('[data-test="user-menu-btn"]')
      await w.page.waitForSelector('.v-overlay-container .v-overlay--active', { timeout: 5000 })
      await w.page.waitForTimeout(300)
      out.D_overlay = await w.page.evaluate(() => {
        const overlay = document.querySelector('.v-overlay-container .v-overlay--active')
        const container = document.querySelector('.v-overlay-container')
        const card = overlay?.querySelector('[data-test="user-menu"]')
        return {
          overlayThemeClass: overlay
            ? [...overlay.classList].filter((c) => c.startsWith('v-theme--')).join(' ')
            : null,
          overlayFontFamily: overlay ? getComputedStyle(overlay).fontFamily : null,
          overlayContainerFontFamily: container ? getComputedStyle(container).fontFamily : null,
          menuCardFontFamily: card ? getComputedStyle(card).fontFamily : null,
          overlayShadowVar: overlay
            ? getComputedStyle(overlay).getPropertyValue('--v-shadow-overlay').trim()
            : null,
        }
      })
      await w.page.keyboard.press('Escape')
      await w.page.waitForTimeout(300)

      // F: agency -> creator, same session.
      await w.page.evaluate(() => window.__walk.push('/creator/dashboard'))
      await w.page.waitForTimeout(600)
      const after = await w.page.evaluate(readState)
      out.F_agency_to_creator = {
        agencyClassCount: after.agencyClassCount,
        appClass: after.appClass,
        htmlBackground: after.htmlBg,
        htmlClass: after.htmlClass,
        bodyClass: after.bodyClass,
        sheetHasAgency: after.sheetHasAgency,
        sheetLen: after.sheetLen,
        sheetEqualsCreatorLoad: sha(after.sheetText) === out.A_creator.sheetSha,
      }
      await w.context.close()
    }

    // G: shell geometry, both themes.
    out.G_shell = {}
    for (const theme of ['dark', 'light']) {
      const w = await open(browser, { route: '/campaigns', user: 'agency', theme, base })
      out.G_shell[theme] = await w.page.evaluate(() => {
        const cs = (el) => (el ? getComputedStyle(el) : null)
        const rect = (el) => {
          const r = el?.getBoundingClientRect()
          return r
            ? { w: Math.round(r.width * 100) / 100, h: Math.round(r.height * 100) / 100 }
            : null
        }
        const drawer = document.querySelector('[data-test="agency-sidebar"]')
        const bar = document.querySelector('[data-test="agency-topbar"]')
        const items = [...document.querySelectorAll('[data-test^="nav-"]')]
        const active = document.querySelector('[data-test^="nav-"].v-list-item--active')
        const idle = items.find((i) => i !== active)
        const main = document.querySelector('[data-test="agency-main"] .v-container')
        const avatarLabel = document.querySelector('[data-test="user-menu-btn"] .v-avatar span')
        const logo = document.querySelector('.agency-sidebar__logo')
        const afterBar = bar ? getComputedStyle(bar, '::after') : null
        return {
          drawer: {
            ...rect(drawer),
            bg: cs(drawer)?.backgroundColor,
            borderRight: cs(drawer)?.borderRightWidth + ' ' + cs(drawer)?.borderRightColor,
            shadow: cs(drawer)?.boxShadow,
          },
          topbar: {
            ...rect(bar),
            bg: cs(bar)?.backgroundColor,
            shadow: cs(bar)?.boxShadow,
            separator: afterBar ? { h: afterBar.height, bg: afterBar.backgroundColor } : null,
          },
          navItems: items.length,
          navIdle: idle
            ? {
                ...rect(idle),
                radius: cs(idle).borderRadius,
                color: cs(idle).color,
                border: cs(idle).borderTopWidth + ' ' + cs(idle).borderTopColor,
              }
            : null,
          navActive: active
            ? {
                ...rect(active),
                radius: cs(active).borderRadius,
                color: cs(active).color,
                bg: cs(active).backgroundColor,
                border: cs(active).borderTopWidth + ' ' + cs(active).borderTopColor,
                overlayOpacity: cs(active.querySelector('.v-list-item__overlay'))?.opacity,
              }
            : null,
          content: main
            ? { paddingLeft: cs(main).paddingLeft, paddingTop: cs(main).paddingTop }
            : null,
          avatarLabel: avatarLabel
            ? { color: cs(avatarLabel).color, classes: avatarLabel.className }
            : null,
          logoFilter: logo ? cs(logo).filter : null,
          mainTop: Math.round(
            document.querySelector('[data-test="agency-main"]').getBoundingClientRect().top,
          ),
          viewportW: window.innerWidth,
        }
      })
      await w.context.close()
    }

    // C: stored light.
    {
      const w = await open(browser, { route: '/campaigns', user: 'agency', theme: 'light', base })
      const s = await w.page.evaluate(readState)
      const frames = await w.page.evaluate(() => window.__frames)
      out.C_light = {
        appClass: s.appClass,
        htmlBackground: s.htmlBg,
        appBackground: s.appBg,
        h6FontFamily: s.h6Font,
        first_paint: summariseFrames(frames),
      }
      await w.context.close()
    }
  } finally {
    await browser.close()
    if (server) {
      if (typeof server.close === 'function') await server.close()
      else await new Promise((resolve) => server.httpServer.close(resolve))
    }
  }

  console.log(JSON.stringify(out, null, 2))
  if (JSON_OUT) writeFileSync(JSON_OUT, JSON.stringify(out, null, 2) + '\n')
}

await main()
process.exit(0)
