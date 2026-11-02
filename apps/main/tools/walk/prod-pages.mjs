#!/usr/bin/env node
/**
 * AH-116 end-of-series check (plan §6.3): the agency pages of the chunk, loaded in a PRODUCTION build
 * of `apps/main` (the real `index.html`, the real router with its guards, the minified bundle),
 * served by `vite preview`, with the mock API of `l3-fixtures.mjs` answering in the browser.
 *
 *   node tools/walk/prod-pages.mjs --dist /tmp/ah116-dist [--shots /tmp/ah116-prod] [--theme dark|light]
 *
 * `walk.mjs` cannot run on a production build (its harness `tools/walk/index.html` is a dev entry that
 * is not built), so this is the production-build leg for the agency pages; the creator-side production
 * leg is `prod-compare.mjs`. Per page it opens the real route, waits for the `expect` selectors, runs one
 * small assertion for what the step changed, and records page errors. `GET /api/v1/me` answers the
 * signed-in agency admin; every other request goes to `fixtureFor`, and anything it does not know is a
 * 503 that is counted and listed. Read-only, no network beyond localhost. Dev tool only, not built.
 */

import { mkdirSync } from 'node:fs'
import path from 'node:path'

import { chromium } from '@playwright/test'
import { preview } from 'vite'

import { AGENCY, REAL_PAGES, fixtureFor } from './l3-fixtures.mjs'

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`)
  return i === -1 ? fallback : process.argv[i + 1]
}
const DIST = path.resolve(arg('dist', '/tmp/ah116-dist'))
const SHOTS = arg('shots', null)
const THEMES = arg('theme', null) ? [arg('theme', null)] : ['dark', 'light']
const PORT = Number(arg('port', '5198'))

const ME = {
  data: {
    id: '01HWALK000000000000000000',
    type: 'user',
    attributes: {
      email: 'maya.lindqvist@northwind.example',
      email_verified_at: '2026-01-01T00:00:00Z',
      name: 'Maya',
      last_name: 'Lindqvist',
      user_type: 'agency_user',
      preferred_language: 'en',
      preferred_currency: null,
      timezone: 'Europe/Madrid',
      theme_preference: 'dark',
      mfa_required: false,
      two_factor_enabled: true,
      mfa_exempt: false,
      last_login_at: null,
      created_at: '2026-01-01T00:00:00Z',
    },
    relationships: {
      agency_memberships: {
        data: [{ agency_id: AGENCY, agency_name: 'Northwind', role: 'agency_admin' }],
      },
    },
  },
}

/** The pages, one per step of the chunk; `check` runs in the page and returns [ok, what it read]. */
const PAGES = [
  {
    id: 'campaigns-1600',
    step: 'W1 status counts',
    check: () => {
      const text = document.body.innerText
      const m = text.match(/(\d+) campaigns?, (\d+) active/)
      return [m !== null, m ? m[0] : 'no "N campaigns, M active" line']
    },
  },
  {
    id: 'dashboard-1600',
    step: 'W1 dashboard KPI',
    check: () => {
      const text = document.body.innerText
      return [
        /Active campaigns/i.test(text) && !/Payments due/i.test(text),
        'Active campaigns drawn, no Payments due card',
      ]
    },
  },
  {
    id: 'discover-age-1600',
    step: 'W2 Discover age filter',
    check: () => {
      const min = document.querySelector('[data-test="discover-age-min"] input')?.value
      const max = document.querySelector('[data-test="discover-age-max"] input')?.value
      const h = (s) => document.querySelector(s)?.getBoundingClientRect().height
      const pill = h('[data-test="discover-country-filter"]')
      const field = h('[data-test="discover-age-min"]')
      return [
        min === '21' && max === '34' && pill === field,
        `min ${min}, max ${max}, pill ${pill}, field ${field}`,
      ]
    },
  },
  {
    id: 'roster-1600',
    step: 'W3 roster filters / W5 name link',
    check: () => {
      const gone =
        document.querySelector('[data-test="roster-followers-filter"]') === null &&
        document.querySelector('[data-test="roster-engagement-filter"]') === null
      const link = document.querySelector('a[data-test^="roster-name-"]')
      return [
        gone && link !== null,
        `no Followers/Engagement filter; first name link ${link?.getAttribute('href')}`,
      ]
    },
  },
  {
    id: 'pool-members-filtered-1600',
    step: 'W4 pool member filters',
    check: () => {
      const text = document.body.innerText
      return [/United Kingdom/i.test(text), 'Country pill reads United Kingdom']
    },
  },
  {
    id: 'campaign-detail-1600',
    step: 'W5 campaign detail links',
    check: () => {
      const brand = document.querySelector('a[href*="/brands/"]')
      return [brand !== null, `brand link ${brand?.getAttribute('href')}`]
    },
  },
  {
    id: 'campaign-files-1600',
    step: 'W6 files strip',
    check: () => {
      const strip = document.querySelector('[data-test="campaign-files-overview"]')
      return [
        strip !== null && /Internal/.test(strip.innerText),
        'Overview strip drawn with an Internal tag',
      ]
    },
  },
  {
    id: 'campaign-chat-files-1600',
    step: 'W6 chat pinned strip',
    check: () => {
      const strip = document.querySelector('[data-test="campaign-files-chat"]')
      return [
        strip !== null && !/Internal/.test(strip.innerText),
        'chat strip drawn, no Internal file',
      ]
    },
  },
  {
    id: 'application-message-1600',
    step: 'W7 Message action',
    check: () => {
      const buttons = [...document.querySelectorAll('[data-test^="applications-message-"]')]
      return [buttons.length >= 1, `${buttons.length} Message link(s)`]
    },
  },
]

const server = await preview({
  root: DIST,
  logLevel: 'error',
  preview: { host: '127.0.0.1', port: PORT, strictPort: true },
  build: { outDir: DIST },
})
const base = `http://127.0.0.1:${PORT}`
const browser = await chromium.launch()
const problems = []
const unknown = new Set()

try {
  const html = await (await fetch(`${base}/`)).text()
  if (html.includes('/@vite/client') || !/\/assets\/index-[\w-]+\.js/.test(html)) {
    throw new Error('the served index.html is not a production build')
  }
  console.log('serving a production build:', html.match(/\/assets\/index-[\w-]+\.js/)[0])

  for (const theme of THEMES) {
    for (const spec of PAGES) {
      const real = REAL_PAGES[spec.id]
      if (!real) throw new Error(`unknown page ${spec.id}`)
      const context = await browser.newContext({
        viewport: { width: real.width, height: real.height ?? 1000 },
        deviceScaleFactor: 1,
        reducedMotion: 'reduce',
        colorScheme: 'dark',
        locale: 'en-US',
        timezoneId: 'Europe/Madrid',
      })
      await context.addInitScript(
        (t) => window.localStorage.setItem('catalyst.main.theme', t),
        theme,
      )
      await context.route(
        (url) => /^\/(api|sanctum)\//.test(url.pathname),
        (route) => {
          const url = new URL(route.request().url())
          const method = route.request().method()
          if (method === 'GET' && url.pathname === '/api/v1/me') {
            return route.fulfill({
              status: 200,
              contentType: 'application/json',
              body: JSON.stringify(ME),
            })
          }
          if (url.pathname === '/sanctum/csrf-cookie')
            return route.fulfill({ status: 204, body: '' })
          const body = fixtureFor(url, method)
          if (body) {
            return route.fulfill({
              status: 200,
              contentType: 'application/json',
              body: JSON.stringify(body),
            })
          }
          unknown.add(`${method} ${url.pathname}`)
          return route.fulfill({ status: 503, contentType: 'application/json', body: '{}' })
        },
      )
      const page = await context.newPage()
      const errors = []
      page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`))
      await page.goto(`${base}${real.to}`, { waitUntil: 'domcontentloaded' })
      let failed = null
      try {
        for (const sel of real.click ?? []) {
          await page.locator(sel).first().click({ timeout: 20000 })
        }
        for (const sel of real.expect ?? []) {
          await page.locator(sel).first().waitFor({ state: 'visible', timeout: 20000 })
        }
        await page.waitForTimeout(800)
      } catch (e) {
        failed = `an expected element never appeared (${String(e.message).split('\n')[0]})`
      }
      const url = page.url().replace(base, '')
      const [ok, what] = failed ? [false, failed] : await page.evaluate(spec.check)
      const onRoute = url.split('?')[0] === real.to.split('?')[0]
      const line = `${ok && onRoute && errors.length === 0 ? 'OK  ' : 'FAIL'} ${theme.padEnd(5)} ${spec.id.padEnd(28)} ${spec.step.padEnd(34)} ${what}${onRoute ? '' : `  [landed on ${url}]`}${errors.length ? `  [${errors.join(' | ').slice(0, 160)}]` : ''}`
      console.log(line)
      if (!(ok && onRoute && errors.length === 0)) problems.push(line)
      if (SHOTS) {
        mkdirSync(SHOTS, { recursive: true })
        await page.screenshot({
          path: path.join(SHOTS, `${theme}-${spec.id}.png`),
          animations: 'disabled',
          caret: 'hide',
        })
      }
      await context.close()
    }
  }
} finally {
  await browser.close()
  await server.close()
}
if (unknown.size) console.log(`requests with no fixture (503): ${[...unknown].sort().join('; ')}`)
console.log(
  problems.length === 0
    ? `PROD PAGES OK: ${PAGES.length * THEMES.length} loads`
    : `PROD PAGES: ${problems.length} problem(s)`,
)
process.exit(problems.length === 0 ? 0 : 1)
