import { webkit, devices } from '@playwright/test'
const label = process.argv[2] ?? 'run'
const qs = process.argv[3] ?? ''
const b = await webkit.launch()
const ctx = await b.newContext({ ...devices['iPhone 15 Pro'] })
const p = await ctx.newPage()
p.on('pageerror', (e) => console.log('pageerror', e.message))
await p.goto('http://localhost:5199/index.html' + qs)
await p.waitForSelector('[data-test="delete-account-dialog-title"]', { timeout: 20000 })
await p.waitForTimeout(800)
const r = await p.evaluate(() => {
  const q = (s) => document.querySelector(s)
  const L = (el) => (el ? +el.getBoundingClientRect().left.toFixed(2) : null)
  const title = q('[data-test="delete-account-dialog-title"]')
  const range = document.createRange()
  range.selectNodeContents(title)
  const box = q(
    '[data-test="delete-account-ack"] .v-selection-control .v-selection-control__input i, [data-test="delete-account-ack"] .v-selection-control__input',
  )
  const icon = q(
    '[data-test="delete-account-ack"] .v-selection-control__input .v-icon, [data-test="delete-account-ack"] .v-selection-control__input i',
  )
  const field = (s) => L(q(`[data-test="${s}"] .v-field__outline, [data-test="${s}"] .v-field`))
  const labelEl = q('[data-test="delete-account-type-prompt"]')
  const Y = (el) => {
    const b = el.getBoundingClientRect()
    return [b.top, b.bottom]
  }
  return {
    bands: {
      title: Y(title),
      ack: Y(icon),
      password: Y(q('[data-test="delete-account-password"] .v-field')),
      prompt: Y(labelEl),
      word: Y(q('[data-test="delete-account-word"] .v-field')),
    },
    titleText: +range.getBoundingClientRect().left.toFixed(2),
    titleEl: L(title),
    ackInput: L(box),
    ackGlyph: L(icon),
    password: field('delete-account-password'),
    word: field('delete-account-word'),
    prompt: L(labelEl),
    sheet: L(q('.v-overlay__content')),
    vw: window.innerWidth,
  }
})
console.log(label, JSON.stringify(r))
import { writeFileSync } from 'node:fs'
writeFileSync(`/tmp/align-${label}.json`, JSON.stringify(r))
await p.screenshot({ path: `/tmp/align-${label}.png` })
await b.close()
