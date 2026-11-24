/** Flag-off comparison capture: node cmp-pl31.mjs <label>. Dev tool only. */
import { chromium } from '@playwright/test'
import fs from 'node:fs'
const label = process.argv[2]
/* node cmp-pl31.mjs <label> --brand <ulid> --wave <ulid> --campaign <ulid> --evergreen <ulid> */
const arg = (n) => process.argv[process.argv.indexOf(`--${n}`) + 1]
const B = arg('brand'),
  W = arg('wave')
const pages = {
  board: `/brands/${B}/waves/${W}`,
  campaign: `/campaigns/${arg('campaign')}`,
  evergreen: `/campaigns/${arg('evergreen')}`,
  strategy: `/brands/${B}/waves/${W}/strategy`,
  personas: `/brands/${B}/waves/${W}/strategy/personas`,
  matrix: `/brands/${B}/waves/${W}/strategy/matrix`,
  concepts: `/brands/${B}/waves/${W}/strategy/concepts`,
}
const b = await chromium.launch()
const ctx = await b.newContext({ viewport: { width: 1440, height: 1100 } })
const p = await ctx.newPage()
p.on('pageerror', (e) => console.log('pageerror', String(e).slice(0, 300)))
await p.goto('http://127.0.0.1:5173/sign-in')
await p.locator('[data-test="sign-in-email"] input').fill('admin@pl31-walk.local')
await p.locator('[data-test="sign-in-password"] input').fill('password-12chars')
await p.locator('[data-test="sign-in-submit"]').click()
await p.locator('[data-test="agency-layout"]').waitFor()
for (const [k, u] of Object.entries(pages)) {
  await p.goto('http://127.0.0.1:5173' + u)
  await p.waitForTimeout(5000)
  const html = await p.evaluate(
    () => document.querySelector('main')?.innerHTML ?? document.body.innerHTML,
  )
  const norm = html
    .replace(/ data-v-[0-9a-f]+(="")?/g, '')
    .replace(/\bv-\d+(-\d+)?\b/g, 'v-N')
    .replace(/\d{1,2}:\d{2}/g, 'T')
  const comments = await p.evaluate(() => {
    const w = document.createTreeWalker(
      document.querySelector('main') ?? document.body,
      NodeFilter.SHOW_COMMENT,
    )
    let n = 0
    while (w.nextNode()) n++
    return n
  })
  console.log(k, p.url(), html.length, 'comment nodes', comments)
  fs.writeFileSync(`/tmp/pl31/cmp/${label}-${k}.html`, norm)
  await p.screenshot({ path: `/tmp/pl31/cmp/${label}-${k}.png`, fullPage: true })
}
await b.close()
