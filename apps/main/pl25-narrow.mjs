import { open, BASE, BRAND, W } from './pl25-shots-lib.mjs'
const { browser, page } = await open(390, 900)
const base = `/brands/${BRAND}/waves/${W.w1}/strategy`
for (const [name, p] of [
  ['usps', 'usps'],
  ['personas', 'personas'],
  ['matrix', 'matrix'],
  ['concepts', 'concepts'],
]) {
  await page.goto(`${BASE}${base}/${p}`)
  await page.waitForSelector('[data-test="strategy-page"]')
  await page.waitForTimeout(1500)
  await page.locator('.agency-topbar .v-app-bar-nav-icon').click()
  await page.waitForTimeout(600)
  const info = await page.evaluate(() => {
    const m = document.querySelector('.strategy-grid__main')
    const d = document.querySelector('.v-navigation-drawer')
    return {
      main: m && Math.round(m.getBoundingClientRect().width),
      drawer: d && Math.round(d.getBoundingClientRect().width),
      drawerOpen: d && !d.classList.contains('v-navigation-drawer--temporary') ? 'perm' : 'temp',
      sw: document.documentElement.scrollWidth,
    }
  })
  console.log(name, JSON.stringify(info))
  await page.screenshot({ path: `/tmp/pl25/shots/n390-${name}.png`, fullPage: true })
}
await browser.close()
