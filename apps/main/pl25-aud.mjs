import { open, BASE, BRAND, W } from './pl25-shots-lib.mjs'
const { browser, page } = await open(1400, 900)
await page.goto(`${BASE}/brands/${BRAND}/waves/${W.w3}/strategy/usps`)
await page.waitForSelector('[data-test="strategy-page"]')
page.on('response', async (r) => {
  if (r.url().includes('audience'))
    console.log(
      r.status(),
      r.url().slice(-60),
      Object.keys(JSON.parse(await r.text()).data.attributes).join(','),
    )
})
await page.click('[data-test="audience-edit"]')
await page.fill('[data-test="audience-field"] input', 'Hello <b>x</b>')
const t = Date.now()
await page.click('[data-test="audience-save"]')
page.on('pageerror', (e) => console.log('PAGEERR', e.message))
page.on('console', (m) => m.type() === 'error' && console.log('CONSOLE', m.text().slice(0, 300)))
await page.waitForTimeout(4000)
console.log('ms', Date.now() - t, await page.locator('[data-test="audience-title"]').count())
await browser.close()
