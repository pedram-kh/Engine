#!/usr/bin/env node
/**
 * AH-114 computed-style pins, in a real browser (the walk harness, real App.vue and
 * the real AgencyLayout), under `agency-dark` AND `agency-light`.
 *
 *   node tools/walk/pins.mjs                run every pin
 *   node tools/walk/pins.mjs --step S1.3    only one step's pins
 *
 * Each pin injects a snippet into the agency content area, reads computed styles and
 * compares them with the expected values. Exit code 1 on any mismatch. Break-revert
 * is done by editing `agency.css`, running this, and seeing the pin fail.
 *
 * Add the next step's pins to PINS; keep `expect` values as the Figma numbers, not
 * as whatever the stylesheet currently produces.
 */

import path from 'node:path'
import { fileURLToPath } from 'node:url'

import { chromium } from '@playwright/test'
import { createServer } from 'vite'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const APP_ROOT = path.resolve(HERE, '../..')
const PORT = Number(process.env.PINS_PORT ?? 5197)

const stepIdx = process.argv.indexOf('--step')
const ONLY_STEP = stepIdx === -1 ? null : process.argv[stepIdx + 1]

const TYPE = (cls, size, weight, line, tracking = 'normal', transform = 'none', extra = '') => ({
  step: 'S1.3',
  name: `.${cls}${extra ? ' ' + extra : ''}`,
  html: `<p class="${cls} ${extra}">Pin</p>`,
  expect: {
    fontSize: `${size}px`,
    fontWeight: String(weight),
    lineHeight: `${line}px`,
    letterSpacing: tracking,
    textTransform: transform,
  },
})

/** CSS variables read off the agency `<v-app>` (what shared components inherit). */
const VARS = (name, size, weight, line) => ({
  step: 'S1.3',
  vars: true,
  name: `--catalyst-typography-${name}-*`,
  expect: {
    [`--catalyst-typography-${name}-size`]: `${size}px`,
    [`--catalyst-typography-${name}-weight`]: String(weight),
    [`--catalyst-typography-${name}-line-height`]: `${line}px`,
  },
})

const PINS = [
  // ── S1.3: the type map (agency.css header table) ──────────────────────────
  TYPE('text-h5', 28, 500, 36),
  TYPE('text-h6', 18, 600, 24),
  TYPE('text-subtitle-1', 15, 600, 20),
  TYPE('text-subtitle-2', 14, 500, 20),
  TYPE('text-body-1', 14, 400, 20),
  TYPE('text-body-2', 14, 400, 20),
  TYPE('text-caption', 12, 400, 16),
  TYPE('text-overline', 11, 600, 14, '0.6px', 'uppercase'),
  // A weight utility keeps winning over the mapped weight, as it does today.
  { ...TYPE('text-subtitle-1', 15, 700, 20, 'normal', 'none', 'font-weight-bold') },
  // Family is Inter, not Roboto, for every mapped class.
  ...[
    'text-h5',
    'text-h6',
    'text-subtitle-1',
    'text-subtitle-2',
    'text-body-2',
    'text-caption',
  ].map((cls) => ({
    step: 'S1.3',
    name: `.${cls} family`,
    html: `<p class="${cls}">Pin</p>`,
    expect: { fontFamily: /^Inter,/ },
  })),
  // Shared-component consumption: a plain element reading the scale variables.
  {
    step: 'S1.3',
    name: 'shared component reading heading-2 vars',
    html: `<div style="font-size: var(--catalyst-typography-heading-2-size); font-weight: var(--catalyst-typography-heading-2-weight); line-height: var(--catalyst-typography-heading-2-line-height)">Pin</div>`,
    expect: { fontSize: '22px', fontWeight: '600', lineHeight: '28px' },
  },
  VARS('display', 28, 500, 36),
  VARS('heading-1', 26, 500, 32),
  VARS('heading-2', 22, 600, 28),
  VARS('heading-3', 18, 600, 24),
  VARS('heading-4', 15, 600, 20),
  VARS('body', 14, 400, 20),
  VARS('body-sm', 13, 400, 19),
  VARS('caption', 12, 400, 16),
  VARS('overline', 11, 600, 14),
  // No Figma style: the shared values stay.
  VARS('display-xl', 48, 700, 56),
  VARS('body-lg', 16, 400, 24),
  VARS('mono', 13, 500, 20),
]

const THEMES = ['dark', 'light']

function matches(actual, want) {
  return want instanceof RegExp ? want.test(actual) : actual === want
}

async function runTheme(browser, base, theme, pins) {
  const context = await browser.newContext({
    viewport: { width: 1280, height: 800 },
    reducedMotion: 'reduce',
    colorScheme: 'dark',
    locale: 'en-US',
  })
  await context.addInitScript((t) => window.localStorage.setItem('catalyst.main.theme', t), theme)
  await context.route(
    (url) => /^\/(api|sanctum)\//.test(url.pathname),
    (r) => r.fulfill({ status: 503, contentType: 'application/json', body: '{}' }),
  )
  const page = await context.newPage()
  const qs = new URLSearchParams({ to: '/campaigns', user: 'agency', theme })
  await page.goto(`${base}/tools/walk/index.html?${qs}`, { waitUntil: 'domcontentloaded' })
  await page.waitForFunction(() => window.__walk && window.__walk.ready === true, null, {
    timeout: 60000,
  })
  await page.evaluate(() => document.fonts.ready)

  const results = await page.evaluate(
    (list) => {
      const host = document.querySelector('[data-test="agency-main"] .v-container')
      const app = document.querySelector('.v-application')
      return list.map((pin) => {
        if (pin.vars) {
          const cs = getComputedStyle(app)
          return Object.fromEntries(
            Object.keys(pin.expect).map((k) => [k, cs.getPropertyValue(k).trim()]),
          )
        }
        const wrap = document.createElement('div')
        wrap.innerHTML = pin.html
        const el = wrap.firstElementChild
        host.appendChild(el)
        const cs = getComputedStyle(el)
        const got = Object.fromEntries(Object.keys(pin.expect).map((k) => [k, cs[k]]))
        el.remove()
        return got
      })
    },
    pins.map((p) => ({ vars: !!p.vars, html: p.html, expect: serialisable(p.expect) })),
  )
  await context.close()
  return results
}

/** RegExps cannot cross the evaluate boundary; they are compared on the Node side. */
function serialisable(expect) {
  return Object.fromEntries(
    Object.entries(expect).map(([k, v]) => [k, v instanceof RegExp ? '' : v]),
  )
}

const pins = PINS.filter((p) => ONLY_STEP === null || p.step === ONLY_STEP)
const server = await createServer({
  root: APP_ROOT,
  logLevel: 'error',
  server: { host: '127.0.0.1', port: PORT, strictPort: true, hmr: false },
})
await server.listen()
const browser = await chromium.launch()
let failures = 0
let checks = 0
try {
  for (const theme of THEMES) {
    const results = await runTheme(browser, `http://127.0.0.1:${PORT}`, theme, pins)
    pins.forEach((pin, i) => {
      for (const [key, want] of Object.entries(pin.expect)) {
        checks += 1
        const got = results[i][key]
        if (!matches(got, want)) {
          failures += 1
          console.log(
            `FAIL [${theme}] ${pin.step} ${pin.name} ${key}: expected ${want}, got ${got}`,
          )
        }
      }
    })
  }
} finally {
  await browser.close()
  await server.close()
}
console.log(
  failures === 0
    ? `PINS OK: ${pins.length} pins, ${checks} checks, ${THEMES.length} themes`
    : `PINS FAILED: ${failures} of ${checks} checks`,
)
process.exit(failures === 0 ? 0 : 1)
