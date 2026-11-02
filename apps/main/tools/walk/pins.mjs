#!/usr/bin/env node
/**
 * AH-114 computed-style pins, in a real browser (the walk harness, real App.vue and
 * the real AgencyLayout), under `agency-dark` AND `agency-light`.
 *
 *   node tools/walk/pins.mjs                run every pin
 *   node tools/walk/pins.mjs --step S1.3    only one step's pins
 *
 * Each pin reads computed styles (of an element on the probe page, an injected snippet,
 * or a function's result) and compares them with the expected values. Exit code 1 on any mismatch. Break-revert
 * is done by editing `agency.css`, running this, and seeing the pin fail.
 *
 * Add the next step's pins to PINS; keep `expect` values as the Figma numbers, not
 * as whatever the stylesheet currently produces.
 */

import path from 'node:path'
import { fileURLToPath } from 'node:url'

import { chromium } from '@playwright/test'
import { createServer } from 'vite'

import { REAL_PAGES, fixtureFor } from './l3-fixtures.mjs'
import { RGB } from './pins-colours.mjs'
import { S14_PINS } from './pins-s14.mjs'
import { S20_PINS } from './pins-s20.mjs'
import { S21_PINS } from './pins-s21.mjs'
import { S22_PINS } from './pins-s22.mjs'
import { S23_PINS } from './pins-s23.mjs'
import { S24_PINS } from './pins-s24.mjs'
import { S25_PINS } from './pins-s25.mjs'
import { S31_PINS } from './pins-s31.mjs'
import { S32_PINS } from './pins-s32.mjs'
import { S15_PINS } from './pins-s15.mjs'

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
  // ── S1.4 ───────────────────────────────────────────────────────────────────
  ...S14_PINS,
  // ── S1.5 ───────────────────────────────────────────────────────────────────
  ...S15_PINS,
  // ── S2.0 ───────────────────────────────────────────────────────────────────
  ...S20_PINS,
  // ── S2.1 ───────────────────────────────────────────────────────────────────
  ...S21_PINS,
  ...S22_PINS,
  ...S23_PINS,
  ...S24_PINS,
  ...S25_PINS,
  // ── S3.1 ───────────────────────────────────────────────────────────────────
  ...S31_PINS,
  ...S32_PINS,
]

const THEMES = ['dark', 'light']

function matches(actual, want) {
  return want instanceof RegExp ? want.test(actual) : actual === want
}

async function runTheme(browser, base, theme, pins, probe) {
  // `real:<id>` opens the real page component(s) in `REAL_PAGES[id]` over the L3 fixtures.
  const real = probe.startsWith('real:') ? REAL_PAGES[probe.slice(5)] : null
  if (probe.startsWith('real:') && !real) throw new Error(`unknown real page ${probe}`)
  const context = await browser.newContext({
    viewport: { width: real?.width ?? 1280, height: 1000 },
    reducedMotion: 'reduce',
    colorScheme: 'dark',
    locale: 'en-US',
  })
  await context.addInitScript((t) => window.localStorage.setItem('catalyst.main.theme', t), theme)
  await context.route(
    (url) => /^\/(api|sanctum)\//.test(url.pathname),
    (r) => {
      const body = real ? fixtureFor(new URL(r.request().url()), r.request().method()) : null
      return body
        ? r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) })
        : r.fulfill({ status: 503, contentType: 'application/json', body: '{}' })
    },
  )
  const page = await context.newPage()
  const qs = new URLSearchParams({ to: real?.to ?? '/campaigns', user: 'agency', theme })
  if (real) qs.set('real', real.names)
  else if (probe !== 'main') qs.set('probe', probe)
  await page.goto(`${base}/tools/walk/index.html?${qs}`, { waitUntil: 'domcontentloaded' })
  await page.waitForFunction(() => window.__walk && window.__walk.ready === true, null, {
    timeout: 60000,
  })
  await page.evaluate(() => document.fonts.ready)
  if (real) await page.waitForTimeout(900)

  const act = async (steps) => {
    for (const [kind, sel] of steps ?? []) {
      if (kind === 'focus') await page.locator(sel).first().focus()
      else if (kind === 'blur') await page.locator(sel).first().blur()
      else if (kind === 'click') await page.locator(sel).first().click({ force: true })
      else if (kind === 'hover') await page.locator(sel).first().hover({ force: true })
      else if (kind === 'press') await page.keyboard.press(sel)
      await page.waitForTimeout(400)
    }
  }

  const results = []
  for (const pin of pins) {
    const expect = typeof pin.expect === 'function' ? pin.expect(RGB[theme]) : pin.expect
    await act(pin.act)
    const got = await page.evaluate(
      ({ p, keys }) => {
        const host = document.querySelector('[data-test="agency-main"] .v-container')
        const app = document.querySelector('.v-application')
        if (p.vars) {
          const cs = getComputedStyle(app)
          return Object.fromEntries(keys.map((k) => [k, cs.getPropertyValue(k).trim()]))
        }
        if (p.fn) return new Function(p.fn)()
        let el
        let injected = false
        if (p.sel) {
          el = document.querySelector(p.sel)
          if (!el) return Object.fromEntries(keys.map((k) => [k, 'MISSING ELEMENT']))
        } else {
          const wrap = document.createElement('div')
          wrap.innerHTML = p.html
          el = wrap.firstElementChild
          host.appendChild(el)
          injected = true
        }
        const cs = getComputedStyle(el)
        const out = Object.fromEntries(keys.map((k) => [k, cs[k]]))
        if (injected) el.remove()
        return out
      },
      {
        p: { vars: !!pin.vars, fn: pin.fn ?? null, sel: pin.sel ?? null, html: pin.html ?? null },
        keys: Object.keys(expect),
      },
    )
    await act(pin.undo)
    results.push({ expect, got })
  }
  await context.close()
  return results
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
    const kinds = [...new Set(pins.map((p) => p.page ?? 'main'))].sort()
    for (const probe of kinds) {
      const group = pins.filter((p) => (p.page ?? 'main') === probe)
      if (group.length === 0) continue
      const results = await runTheme(browser, `http://127.0.0.1:${PORT}`, theme, group, probe)
      group.forEach((pin, i) => {
        const { expect, got } = results[i]
        for (const [key, want] of Object.entries(expect)) {
          checks += 1
          if (!matches(got[key], want)) {
            failures += 1
            console.log(
              `FAIL [${theme}] ${pin.step} ${pin.name} ${key}: expected ${want}, got ${got[key]}`,
            )
          }
        }
      })
    }
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
