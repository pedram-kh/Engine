/** Flag-off comparison capture: node cmp-pl31.mjs <label>. Dev tool only. */
import { chromium } from '@playwright/test'
import fs from 'node:fs'
const label = process.argv[2]
const B = '01M82XN88B9TWSFQ7C924R19TD',
  W = '01M82XN9NVNF9D05EP1WF239XY'
const pages = {
  board: `/brands/${B}/waves/${W}`,
  campaign: '/campaigns/01M82XN88EQYQZQESBHEFA4XRS',
  evergreen: '/campaigns/01M82XN88JWPBGNETC95MJ1W6N',
  strategy: `/brands/${B}/waves/${W}/strategy`,
  concepts: `/brands/${B}/waves/${W}/strategy/concepts`,
}
const b = await chromium.launch()
const ctx = await b.newContext({ viewport: { width: 1440, height: 1100 } })
const p = await ctx.newPage()
await p.goto('http://127.0.0.1:5173/sign-in')
await p.locator('[data-test="sign-in-email"] input').fill('admin@pl31-walk.local')
await p.locator('[data-test="sign-in-password"] input').fill('password-12chars')
await p.locator('[data-test="sign-in-submit"]').click()
await p.locator('[data-test="agency-layout"]').waitFor()
for (const [k, u] of Object.entries(pages)) {
  await p.goto('http://127.0.0.1:5173' + u)
  await p.waitForTimeout(2500)
  const html = await p.evaluate(
    () => document.querySelector('main')?.innerHTML ?? document.body.innerHTML,
  )
  const norm = html
    .replace(/ data-v-[0-9a-f]+(="")?/g, '')
    .replace(/\bv-\d+(-\d+)?\b/g, 'v-N')
    .replace(/\d{1,2}:\d{2}/g, 'T')
  fs.writeFileSync(`/tmp/pl31/cmp/${label}-${k}.html`, norm)
  await p.screenshot({ path: `/tmp/pl31/cmp/${label}-${k}.png`, fullPage: true })
}
await b.close()
