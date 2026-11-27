import { closeSync, ftruncateSync, openSync, unlinkSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

import type { APIRequestContext, Locator, Page } from '@playwright/test'
import { expect, test } from '../fixtures/test'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedAgencyAdmin,
  seedAgencyStaff,
  setFeatureFlag,
} from '../fixtures/test-helpers'
import { marketIds, wireMarkets } from '../helpers/markets'
import { dt, testIds } from '../helpers/selectors'

/**
 * PL-3.2b — script lines, B-roll, languages and reference images in a real browser (WORKING-PROCESS §4.2). Each test makes its own
 * agency, brand, wave, campaign, creator and script through the API; serial; short. Set PL32B_SHOTS=1 to write screenshots to
 * /tmp/pl32b/shots (the real-browser pass reads them).
 */

// One worker, so one test at a time (playwright.config.ts); `default` so one failure does not hide the rest while this is built.
test.describe.configure({ mode: 'default' })
test.setTimeout(25_000)

const DESKTOP = { width: 1600, height: 1000 }
const PHONE = { width: 390, height: 844 }
const HOSTILE = '<img src=x onerror=alert(1)> {{ 7*7 }} <script>window.__pwn=1</script>'

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Json = any

// A 1x1 PNG, enough for the server to accept as an image.
const PNG_B64 =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='

async function api(page: Page, method: string, path: string, body?: unknown): Promise<Json> {
  return page.evaluate(
    async ({ method, path, body }) => {
      const token = decodeURIComponent(
        document.cookie
          .split('; ')
          .find((row) => row.startsWith('XSRF-TOKEN='))
          ?.slice('XSRF-TOKEN='.length) ?? '',
      )
      const response = await fetch(`/api/v1${path}`, {
        method,
        credentials: 'include',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-XSRF-TOKEN': token,
        },
        body: body === undefined ? undefined : JSON.stringify(body),
      })
      if (!response.ok) throw new Error(`${method} ${path} failed: ${response.status}`)
      const text = await response.text()
      return text === '' ? {} : JSON.parse(text)
    },
    { method, path, body },
  )
}

async function shot(page: Page, name: string): Promise<void> {
  if (process.env.PL32B_SHOTS === undefined) return
  await page.waitForTimeout(600)
  await page.screenshot({ path: `/tmp/pl32b/shots/e2e-${name}.png` })
}

async function signIn(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/sign-in')
  await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
  await page.locator(dt(testIds.signInPassword)).locator('input').fill(password)
  await page.locator(dt(testIds.signInSubmit)).click()
  await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible()
}

async function setup(
  page: Page,
  request: APIRequestContext,
  size: { width: number; height: number },
  opts: { local?: string[] } = {},
) {
  const { email, password, agencyUlid } = await seedAgencyAdmin(request)
  await page.setViewportSize(size)
  await signIn(page, email, password)

  const tag = Math.random().toString(36).slice(2, 8)
  const brand = await api(page, 'POST', `/agencies/${agencyUlid}/brands`, {
    name: `Lines Bolt ${tag}`,
    slug: `lines-bolt-${tag}`,
    description: 'A brand for the lines check.',
    industry: 'Food',
    website_url: 'https://example.com',
  })
  const wavePath = `/agencies/${agencyUlid}/brands/${brand.data.id}/waves`
  const wave = await api(page, 'POST', wavePath, {
    name: 'Lines wave',
    go_live_on: '2027-03-01',
    markets: wireMarkets(['SK']),
    campaign_id: null,
  })
  const base = `${wavePath}/${wave.data.id}`
  const mk = await marketIds((path) => api(page, 'GET', path), base)
  const market = mk['SK']
  const persona = await api(page, 'POST', `${base}/strategy/personas`, {
    market,
    title: 'The Office Organiser',
    name: 'Eva',
  })
  const linked = await api(page, 'POST', `${base}/campaign`, { name: 'Lines campaign' })
  const campaignUlid = linked.data.relationships.campaign.data.id as string
  const { seedPendingApplications } = await import('../fixtures/test-helpers')
  await seedPendingApplications(request, agencyUlid, [{ displayName: 'Lucia Horvathova' }], {
    campaignUlid,
  })
  const candidates = await api(
    page,
    'GET',
    `${base}/strategy/creators/candidates?market=${market}&per_page=50`,
  )
  const added = await api(page, 'POST', `${base}/strategy/creators`, {
    market,
    creators: [candidates.data[0].id as string],
    persona: persona.data.id,
  })
  const slot = (added.data.markets ?? []).flatMap((m: Json) => m.slots)[0].id as string
  const script = await api(page, 'POST', `${base}/strategy/scripts`, { slot })
  const scriptId = script.data.id as string
  if ((opts.local ?? []).length > 0) {
    await api(page, 'PUT', `${base}/strategy/markets/${market}/script-languages`, {
      languages: opts.local,
    })
  }
  const url = `/brands/${brand.data.id}/waves/${wave.data.id}/strategy/scripts?market=${market}`
  const scriptPath = `${base}/strategy/scripts/${scriptId}`

  const closeDrawer = async (target: Page): Promise<void> => {
    const width = target.viewportSize()?.width ?? 1600
    if (width >= 768) return
    const drawer = target.locator('[data-test="agency-sidebar"]')
    await expect(async () => {
      if ((await drawer.getAttribute('inert')) === null)
        await target.locator('.v-app-bar-nav-icon').click()
      await expect(drawer).toHaveAttribute('inert', '', { timeout: 1000 })
    }).toPass({ timeout: 8000 })
  }
  const open = async (target: Page = page): Promise<void> => {
    await target.goto(url)
    await closeDrawer(target)
    await expect(target.locator('[data-test="script-editor"]')).toBeVisible()
  }
  const get = async (target: Page = page): Promise<Json> =>
    (await api(target, 'GET', scriptPath)).data
  const addLine = async (n = 1, target: Page = page): Promise<string[]> => {
    let last: Json = null
    for (let i = 0; i < n; i++) last = await api(target, 'POST', `${scriptPath}/lines`)
    return last.data.lines.map((l: Json) => l.id as string)
  }
  const addBroll = async (lineId: string, n = 1, target: Page = page): Promise<void> => {
    for (let i = 0; i < n; i++) await api(target, 'POST', `${scriptPath}/lines/${lineId}/broll`)
  }
  const addImage = async (
    block: 'hook' | 'line',
    id: string,
    name = 'ref.png',
    target: Page = page,
  ): Promise<void> => {
    await target.evaluate(
      async ({ scriptPath, block, id, name, b64 }) => {
        const token = decodeURIComponent(
          document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length) ?? '',
        )
        const headers = {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-XSRF-TOKEN': token,
        }
        const bytes = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0))
        const init = await fetch(`/api/v1${scriptPath}/images/uploads/init`, {
          method: 'POST',
          credentials: 'include',
          headers,
          body: JSON.stringify({
            block,
            id,
            mime_type: 'image/png',
            declared_bytes: bytes.length,
            original_filename: name,
          }),
        }).then((r) => r.json())
        const put = await fetch(init.data.upload_url, {
          method: 'PUT',
          headers: { 'Content-Type': 'image/png' },
          body: bytes,
        })
        if (!put.ok) throw new Error(`PUT failed ${put.status}`)
        const done = await fetch(`/api/v1${scriptPath}/images/uploads/complete`, {
          method: 'POST',
          credentials: 'include',
          headers,
          body: JSON.stringify({ upload_id: init.data.upload_id }),
        })
        if (!done.ok) throw new Error(`complete failed ${done.status}`)
      },
      { scriptPath, block, id, name, b64: PNG_B64 },
    )
  }
  return {
    base,
    scriptPath,
    scriptId,
    market,
    agencyUlid,
    url,
    open,
    get,
    addLine,
    addBroll,
    addImage,
    closeDrawer,
    hookIds: script.data.hooks.map((h: Json) => h.id as string),
    page,
  }
}

const lineBox = (page: Page, id: string): Locator => page.locator(`[data-test="script-line-${id}"]`)
const lineText = (page: Page, id: string, lang = ''): Locator =>
  page.locator(`[data-test="script-line-text-${id}${lang}"] textarea:not([aria-hidden])`)
const lineStatus = (page: Page, id: string): Locator =>
  page.locator(`[data-test="script-line-status-${id}"]`)
const order = (page: Page): Promise<string[]> =>
  page
    .locator('[data-block="line"]')
    .evaluateAll((els) => els.map((e) => e.getAttribute('data-test')!.replace('script-line-', '')))
const numbers = (page: Page): Promise<string[]> =>
  page
    .locator('[data-block="line"]')
    .evaluateAll((els) => els.map((e) => e.getAttribute('data-number')!))

/** Open every line card (a card is open when its toggle says "collapse"). */
async function expandAll(page: Page): Promise<void> {
  await expect(page.locator('[data-test="script-lines-section"]')).toBeVisible()
  const toggles = page.locator('[data-test^="script-line-toggle-"]')
  const n = await toggles.count()
  for (let i = 0; i < n; i++) {
    if ((await toggles.nth(i).getAttribute('aria-expanded')) === 'false')
      await toggles.nth(i).click()
  }
}

test.describe('Scripts: lines, B-roll, languages, images', () => {
  test.beforeEach(async ({ request }) => {
    await neutralizeThrottle(request, 'auth-ip')
    await setFeatureFlag(request, 'waves_enabled', true)
    await setFeatureFlag(request, 'strategy_enabled', true)
    await setFeatureFlag(request, 'creators_scripts_enabled', true)
  })
  test.afterEach(async ({ request }) => {
    await setFeatureFlag(request, 'creators_scripts_enabled', false)
    await setFeatureFlag(request, 'strategy_enabled', false)
    await setFeatureFlag(request, 'waves_enabled', false)
    await restoreThrottle(request, 'auth-ip')
  })

  test('lines: add (numbered from 2, text box focused), type and Saved, reload keeps it, a hostile string stays text, delete asks and says what goes with it', async ({
    page,
    request,
  }) => {
    const alerts: string[] = []
    page.on('dialog', async (d) => {
      alerts.push(d.message())
      await d.dismiss()
    })
    const s = await setup(page, request, DESKTOP)
    await s.open()
    await expect(page.locator('[data-test="script-lines-empty"]')).toBeVisible()
    await page.locator('[data-test="script-add-line"]').click()
    await expect(page.locator('[data-block="line"]')).toHaveCount(1)
    const [first] = await order(page)
    await expect(page.locator(`[data-test="script-line-name-${first}"]`)).toHaveText('Line 2')
    await expect(lineText(page, first)).toBeFocused()
    await lineText(page, first).fill(HOSTILE)
    await expect(lineStatus(page, first)).toContainText('Saved', { timeout: 8000 })
    await shot(page, 'lines-one')
    await page.locator('[data-test="script-add-line"]').click()
    await expect(page.locator('[data-block="line"]')).toHaveCount(2)
    await expect(page.locator('[data-test="script-lines-live"]')).toBeAttached()
    await page.reload()
    await expect(lineText(page, first)).toHaveValue(HOSTILE)
    await expect(page.locator('img[src="x"]')).toHaveCount(0)
    expect(
      await page.evaluate(() => (window as unknown as { __pwn?: number }).__pwn),
    ).toBeUndefined()
    expect(alerts).toEqual([])

    // delete: asks, cancel keeps, quick reopen, confirm removes; the second line becomes Line 2.
    await page.locator(`[data-test="script-line-menu-${first}"]`).click()
    await page.locator(`[data-test="script-line-delete-${first}"]`).click()
    await expect(page.locator('[data-test="confirm-cancel"]')).toBeVisible()
    await expect(page.locator('.v-dialog .v-card-text').first()).toContainText('deleted')
    await page.locator('[data-test="confirm-cancel"]').click()
    await page.locator(`[data-test="script-line-menu-${first}"]`).click()
    await page.locator(`[data-test="script-line-delete-${first}"]`).click()
    await page.locator('[data-test="confirm-ok"]').click()
    await expect(page.locator('[data-block="line"]')).toHaveCount(1)
    await expect(page.locator('[data-block="line"]').first()).toHaveAttribute('data-number', '2')
  })

  test('reorder: keyboard (grip arrows) and the menu both move a line; numbers update at once and survive a reload; the first cannot move up', async ({
    page,
    request,
  }) => {
    const s = await setup(page, request, DESKTOP)
    const ids = await s.addLine(3)
    await s.open()
    await expandAll(page)
    for (const [i, id] of ids.entries()) await lineText(page, id).fill(`text ${i + 2}`)
    await expect(lineStatus(page, ids[2])).toContainText('Saved', { timeout: 8000 })

    // The grip, arrow down: line 2 goes to place 3; the text moves with it.
    await page.locator(`[data-test="script-line-grip-${ids[0]}"]`).focus()
    await page.keyboard.press('ArrowDown')
    await expect.poll(() => order(page)).toEqual([ids[1], ids[0], ids[2]])
    expect(await numbers(page)).toEqual(['2', '3', '4'])
    await expect(lineText(page, ids[0])).toHaveValue('text 2')
    await expect(page.locator(`[data-test="script-line-name-${ids[0]}"]`)).toHaveText('Line 3')
    await expect(page.locator('[data-test="script-lines-live"]')).not.toBeEmpty()
    await expect(page.locator(`[data-test="script-line-grip-${ids[0]}"]`)).toBeFocused()

    // The menu: "Move up" on the last line, and the first line's "Move up" is off.
    await page.locator(`[data-test="script-line-menu-${ids[2]}"]`).click()
    await page.locator(`[data-test="script-line-up-${ids[2]}"]`).click()
    await expect.poll(() => order(page)).toEqual([ids[1], ids[2], ids[0]])
    await page.locator(`[data-test="script-line-menu-${ids[1]}"]`).click()
    await expect(page.locator(`[data-test="script-line-up-${ids[1]}"]`)).toHaveClass(
      /v-list-item--disabled/,
    )
    await page.keyboard.press('Escape')
    await page.reload()
    await expect.poll(() => order(page)).toEqual([ids[1], ids[2], ids[0]])
    expect(await numbers(page)).toEqual(['2', '3', '4'])
    await shot(page, 'reorder')
  })

  test('drag: a line dropped on another takes its place; so does a hook', async ({
    page,
    request,
  }) => {
    const s = await setup(page, request, DESKTOP)
    const ids = await s.addLine(3)
    await s.open()
    await page
      .locator(`[data-test="script-line-grip-${ids[2]}"]`)
      .dragTo(page.locator(`[data-test="script-line-${ids[0]}"]`))
    await expect.poll(() => order(page)).toEqual([ids[2], ids[0], ids[1]])
    await expect
      .poll(async () => (await s.get()).lines.map((l: Json) => l.id))
      .toEqual([ids[2], ids[0], ids[1]])

    // The three hooks are far apart; a tall window keeps both ends of the drag on screen (the drag does not auto-scroll).
    await page.setViewportSize({ width: 1600, height: 3200 })
    await page.reload()
    await expect(page.locator('[data-block="hook"]').first()).toBeVisible()
    const hooks = s.hookIds
    const hookIds = (): Promise<string[]> =>
      page
        .locator('[data-block="hook"]')
        .evaluateAll((els) =>
          els.map((e) => e.getAttribute('data-test')!.replace('script-hook-', '')),
        )
    await page
      .locator(`[data-test="script-hook-grip-${hooks[2]}"]`)
      .dragTo(page.locator(`[data-test="script-hook-${hooks[0]}"]`))
    await expect.poll(hookIds).toEqual([hooks[2], hooks[0], hooks[1]])
    await shot(page, 'drag')
  })

  test('B-roll: add rows (codes {line}_{n}), edit and Saved, reorder inside the line, delete; the codes follow a reordered line', async ({
    page,
    request,
  }) => {
    const s = await setup(page, request, DESKTOP)
    const ids = await s.addLine(2)
    await s.open()
    await expandAll(page)
    const [a, b] = ids
    for (let i = 0; i < 2; i++)
      await page.locator(`[data-test="script-line-add-shot-${a}"]`).click()
    const codes = (id: string): Promise<string[]> =>
      page
        .locator(`[data-test="script-line-broll-${id}"] [data-test^="script-broll-code-"]`)
        .allTextContents()
    await expect.poll(() => codes(a)).toEqual(['2_1', '2_2'])
    const rows = await page
      .locator(`[data-test="script-line-broll-${a}"] [data-test^="script-broll-"][data-code]`)
      .evaluateAll((els) =>
        els.map((e) => e.getAttribute('data-test')!.replace('script-broll-', '')),
      )
    await page
      .locator(`[data-test="script-broll-text-${rows[0]}"] textarea:not([aria-hidden])`)
      .fill('first shot')
    await page
      .locator(`[data-test="script-broll-text-${rows[1]}"] textarea:not([aria-hidden])`)
      .fill('second shot')
    await page.locator(`[data-test="script-broll-shot-${rows[0]}"]`).click()
    await page.locator(`[data-test="script-broll-shot-${rows[0]}-wide"]`).click()
    await expect(lineStatus(page, a)).toContainText('Saved', { timeout: 8000 })
    await shot(page, 'broll')

    // reorder inside the line: the second row moves up; codes stay 1_1, 1_2 and the descriptions swap.
    await page.locator(`[data-test="script-broll-up-${rows[1]}"]`).click()
    await expect
      .poll(() =>
        page
          .locator(
            `[data-test="script-line-broll-${a}"] [data-test^="script-broll-text-"] textarea:not([aria-hidden])`,
          )
          .evaluateAll((els) => els.map((e) => (e as HTMLTextAreaElement).value)),
      )
      .toEqual(['second shot', 'first shot'])
    expect(await codes(a)).toEqual(['2_1', '2_2'])

    // the codes follow a reordered line: the first line is "Line 2", so its B-roll is 2_x; after it moves down it is "Line 3" and 3_x.
    await page.locator(`[data-test="script-line-grip-${a}"]`).focus()
    await page.keyboard.press('ArrowDown')
    await expect.poll(() => order(page)).toEqual([b, a])
    await expect.poll(() => codes(a)).toEqual(['3_1', '3_2'])

    // delete a row
    await page.locator(`[data-test="script-broll-delete-${rows[0]}"]`).click()
    await expect.poll(() => codes(a)).toEqual(['3_1'])
    await page.reload()
    await expandAll(page)
    await expect.poll(() => codes(a)).toEqual(['3_1'])
  })

  test('limits: 29 lines then Add goes off at 30; the line usage shows from 25; B-roll usage from 35 and Add shot off at 40', async ({
    page,
    request,
  }) => {
    test.setTimeout(40_000)
    const s = await setup(page, request, DESKTOP)
    const ids = await s.addLine(24)
    await s.open()
    await expect(page.locator('[data-test="script-lines-usage"]')).toHaveCount(0)
    await s.addLine(5)
    await page.reload()
    await expect(page.locator('[data-block="line"]')).toHaveCount(29)
    await expect(page.locator('[data-test="script-lines-usage"]')).toBeVisible()
    await expect(page.locator('[data-test="script-add-line"]')).toBeEnabled()
    await page.locator('[data-test="script-add-line"]').click()
    await expect(page.locator('[data-block="line"]')).toHaveCount(30)
    await expect(page.locator('[data-test="script-add-line"]')).toBeDisabled()
    await shot(page, 'limit-lines')

    // B-roll: 34 rows show nothing, 35 shows the usage, 40 turns Add shot off.
    const target = ids[0]
    await s.addBroll(target, 34)
    await page.reload()
    await expandAll(page)
    await expect(page.locator(`[data-test="script-line-broll-usage-${target}"]`)).toHaveCount(0)
    await page.locator(`[data-test="script-line-add-shot-${target}"]`).scrollIntoViewIfNeeded()
    await page.locator(`[data-test="script-line-add-shot-${target}"]`).click()
    await expect(page.locator(`[data-test="script-line-broll-usage-${target}"]`)).toBeVisible()
    for (let i = 0; i < 5; i++)
      await page.locator(`[data-test="script-line-add-shot-${target}"]`).click()
    await expect(page.locator(`[data-test="script-line-add-shot-${target}"]`)).toBeDisabled()
    await shot(page, 'limit-broll')
  })

  test('languages: English only shows no toggle and no second column; add Slovak, type it, remove it (text kept and hidden), add it back (text returns); English cannot be removed', async ({
    page,
    request,
  }) => {
    const s = await setup(page, request, DESKTOP)
    const [line] = await s.addLine(1)
    await s.open()
    await expandAll(page)
    await expect(page.locator('[data-test="script-language-toggle"]')).toHaveCount(0)
    await expect(page.locator('[data-language="sk"]')).toHaveCount(0)
    await expect(page.locator(`[data-test="script-line-${line}"] [data-language]`)).toHaveCount(1)

    await page.locator('[data-test="script-languages-open"]').click()
    await expect(page.locator('[data-test="script-languages-en"]')).toBeVisible()
    await expect(page.locator('[data-test="script-languages-remove-en"]')).toHaveCount(0)
    await page.locator('[data-test="script-languages-add"]').click()
    await page.getByRole('option', { name: 'Slovak' }).click()
    await page.locator('[data-test="script-languages-save"]').click()
    await expect(page.locator('[data-test="script-languages-dialog"]')).toBeHidden()
    await expect(page.locator('[data-test="script-language-toggle"]')).toBeVisible()
    await expandAll(page)
    // default is English beside Slovak
    await expect(page.locator(`[data-test="script-line-${line}"] [data-language]`)).toHaveCount(2)
    await lineText(page, line).fill('English words')
    await lineText(page, line, '-sk').fill('Slovenské slová')
    await expect(lineStatus(page, line)).toContainText('Saved', { timeout: 8000 })
    await shot(page, 'languages-side')

    await page.locator('[data-test="script-language-en"]').click()
    await expect(page.locator(`[data-test="script-line-${line}"] [data-language]`)).toHaveCount(1)
    await page.locator('[data-test="script-language-sk"]').click()
    await expect(lineText(page, line, '-sk')).toHaveValue('Slovenské slová')

    // remove: the dialog says the text is kept; after saving, Slovak is gone from the screen
    await page.locator('[data-test="script-languages-open"]').click()
    await page.locator('[data-test="script-languages-remove-sk"]').click()
    await expect(page.locator('[data-test="script-languages-keep"]')).toBeVisible()
    await page.locator('[data-test="script-languages-save"]').click()
    await expect(page.locator('[data-test="script-languages-dialog"]')).toBeHidden()
    await expect(page.locator('[data-test="script-language-toggle"]')).toHaveCount(0)
    await expect(page.locator('[data-language="sk"]')).toHaveCount(0)
    // add back: the text returns
    await page.locator('[data-test="script-languages-open"]').click()
    await page.locator('[data-test="script-languages-add"]').click()
    await page.getByRole('option', { name: 'Slovak' }).click()
    await page.locator('[data-test="script-languages-save"]').click()
    await expect(page.locator('[data-test="script-languages-dialog"]')).toBeHidden()
    await expandAll(page)
    await expect(lineText(page, line, '-sk')).toHaveValue('Slovenské slová')
  })

  // The upload itself needs a real object store (the presigned PUT; the E2E run has none, like the concept images, tech-debt
  // row 41 / pl-2-3-review): upload, retry, remove and enlarge are walked in a real browser on the walk stack (MinIO). What needs
  // no store is here: the refusals the browser makes BEFORE any request.
  test('images: the empty field is a drop zone; a PDF and a file over 300 MB are refused in plain words before any request', async ({
    page,
    request,
  }) => {
    const s = await setup(page, request, DESKTOP)
    const [line] = await s.addLine(1)
    await s.open()
    await expandAll(page)
    const field = `script-line-${line}`
    let asked = 0
    page.on('request', (r) => {
      if (r.url().includes('/images/uploads/')) asked++
    })
    await expect(page.locator(`[data-test="${field}-image-drop"]`)).toBeVisible()
    await shot(page, 'images-empty')

    await page.locator(`[data-test="${field}-image-input"]`).setInputFiles({
      name: 'notes.pdf',
      mimeType: 'application/pdf',
      buffer: Buffer.from('%PDF-1.4'),
    })
    await expect(page.locator(`[data-test="${field}-upload-notices"]`)).toContainText('notes.pdf')
    await shot(page, 'images-refused-type')

    const big = join(tmpdir(), `pl32b-over-300mb-${Date.now()}.png`)
    const fd = openSync(big, 'w')
    ftruncateSync(fd, 301 * 1024 * 1024)
    closeSync(fd)
    try {
      await page.locator(`[data-test="${field}-image-input"]`).setInputFiles(big)
      await expect(page.locator(`[data-test="${field}-upload-notices"]`)).toContainText('300')
    } finally {
      unlinkSync(big)
    }
    expect(asked).toBe(0)
    expect((await s.get()).lines[0].images).toHaveLength(0)
  })

  test('two tabs on one line: the second save gets the 409 with their text shown and mine kept; Keep mine wins; the other line is untouched', async ({
    page,
    request,
    context,
  }) => {
    const s = await setup(page, request, DESKTOP)
    const [a, b] = await s.addLine(2)
    await s.open()
    await expandAll(page)
    const other = await context.newPage()
    await other.setViewportSize(DESKTOP)
    await s.open(other)
    await expandAll(other)

    await lineText(page, a).fill('tab one wrote this')
    await expect(lineStatus(page, a)).toContainText('Saved', { timeout: 8000 })
    await lineText(other, a).fill('tab two wrote this')
    const conflict = other.locator(`[data-test="script-line-conflict-${a}"]`)
    await expect(conflict).toBeVisible({ timeout: 8000 })
    await expect(conflict).toContainText('tab one wrote this')
    await expect(lineText(other, a)).toHaveValue('tab two wrote this')
    await shot(other, 'line-conflict')
    await other.locator(`[data-test="script-line-conflict-${a}-keep"]`).click()
    await expect(conflict).toBeHidden()
    await expect(lineStatus(other, a)).toContainText('Saved')
    // Keep mine covers one conflict only: the button is gone, and the other line saves without any conflict.
    await expect(other.locator(`[data-test="script-line-conflict-${a}-keep"]`)).toHaveCount(0)
    await lineText(other, b).fill('tab two, other line')
    await expect(lineStatus(other, b)).toContainText('Saved', { timeout: 8000 })
    await expect(other.locator('[data-test^="script-line-conflict-"]')).toHaveCount(0)
    await page.reload()
    await expandAll(page)
    await expect(lineText(page, a)).toHaveValue('tab two wrote this')
    await expect(lineText(page, b)).toHaveValue('tab two, other line')
  })

  test('stale reorder: the order changed in another tab; my move finds a line added elsewhere, reloads the order, says so and loses no text', async ({
    page,
    request,
    context,
  }) => {
    const s = await setup(page, request, DESKTOP)
    const ids = await s.addLine(3)
    await s.open()
    await expandAll(page)
    await lineText(page, ids[2]).fill('written before the clash')
    await expect(lineStatus(page, ids[2])).toContainText('Saved', { timeout: 8000 })
    const other = await context.newPage()
    await s.open(other)
    const added = await api(other, 'POST', `${s.scriptPath}/lines`)
    const fresh = added.data.lines.map((l: Json) => l.id as string)

    await page.locator(`[data-test="script-line-grip-${ids[0]}"]`).focus()
    await page.keyboard.press('ArrowDown')
    await expect(page.locator('[data-test="script-lines-notice"]')).toBeVisible({ timeout: 8000 })
    await shot(page, 'stale-reorder')
    await expect.poll(() => order(page)).toEqual(fresh)
    expect(await numbers(page)).toEqual(['2', '3', '4', '5'])
    await expect(lineText(page, ids[2])).toHaveValue('written before the clash')
    expect((await s.get()).lines.map((l: Json) => l.id)).toEqual(fresh)
  })

  test('a line deleted in another tab: my unsaved text stays on screen with Copy; the card says so', async ({
    page,
    request,
    context,
  }) => {
    const s = await setup(page, request, DESKTOP)
    const [a, b] = await s.addLine(2)
    await s.open()
    await expandAll(page)
    let down = true
    await page.route('**/strategy/scripts/**/lines/**', (route) =>
      down && route.request().method() === 'PUT' ? route.abort() : route.continue(),
    )
    await lineText(page, b).fill('typed while the line was being deleted')
    await expect(lineStatus(page, b)).toContainText('Could not save', { timeout: 8000 })
    const other = await context.newPage()
    await s.open(other)
    await api(other, 'DELETE', `${s.scriptPath}/lines/${b}`)
    down = false
    await page.locator(`[data-test="script-line-status-${b}-retry"]`).click()
    await expect(page.locator(`[data-test="script-line-gone-${b}"]`)).toBeVisible({ timeout: 8000 })
    await expect(lineText(page, b)).toHaveValue('typed while the line was being deleted')
    await expect(page.locator(`[data-test="script-line-gone-copy-${b}"]`)).toBeVisible()
    await shot(page, 'line-gone')
    await expect(page.locator('[data-test="script-editor"]')).toBeVisible()
    await expect(lineBox(page, a)).toBeVisible()
    await page.locator(`[data-test="script-line-gone-close-${b}"]`).click()
    await expect(lineBox(page, b)).toHaveCount(0)
  })

  test('staff: can add, edit and reorder lines and sees the image drop zone, sees no "Delete for good"; a second agency gets not found on the same script', async ({
    page,
    request,
    browser,
  }) => {
    test.setTimeout(45_000)
    const s = await setup(page, request, DESKTOP)
    const ids = await s.addLine(2)
    const staff = await seedAgencyStaff(request, s.agencyUlid)
    const ctx = await browser.newContext({ viewport: DESKTOP })
    const ctxB = await browser.newContext({ viewport: DESKTOP })
    try {
      const sp = await ctx.newPage()
      await signIn(sp, staff.email, staff.password)
      await s.open(sp)
      await expandAll(sp)
      await sp.locator('[data-test="script-add-line"]').click()
      await expect(sp.locator('[data-block="line"]')).toHaveCount(3)
      await lineText(sp, ids[0]).fill('staff wrote this')
      await expect(lineStatus(sp, ids[0])).toContainText('Saved', { timeout: 8000 })
      await sp.locator(`[data-test="script-line-grip-${ids[0]}"]`).focus()
      await sp.keyboard.press('ArrowDown')
      await expect.poll(() => order(sp).then((o) => o.indexOf(ids[0]))).toBe(1)
      await expect(sp.locator(`[data-test="script-line-${ids[1]}-image-drop"]`)).toBeVisible()
      await expect(sp.locator('[data-test="script-delete"]')).toHaveCount(0)

      // a second agency: the same script id is "not found"
      const second = await seedAgencyAdmin(request)
      const pb = await ctxB.newPage()
      await signIn(pb, second.email, second.password)
      await expect(async () => {
        await api(pb, 'GET', s.scriptPath.replace(s.agencyUlid, second.agencyUlid))
      }).rejects.toThrow(/404/)
      await expect(async () => {
        await api(pb, 'POST', `${s.scriptPath.replace(s.agencyUlid, second.agencyUlid)}/lines`)
      }).rejects.toThrow(/404/)
    } finally {
      await ctx.close()
      await ctxB.close()
    }
  })

  test('phone width: lines, B-roll, side by side (stacked, each keeps its language label) and the image drop zone fit with no sideways scroll', async ({
    page,
    request,
  }) => {
    test.setTimeout(40_000)
    const s = await setup(page, request, PHONE, { local: ['sk'] })
    const ids = await s.addLine(2)
    await s.addBroll(ids[0], 2)
    await s.open()
    await expandAll(page)
    await expect(page.locator('[data-test="script-language-toggle"]')).toBeVisible()
    const cols = page.locator(`[data-test="script-line-${ids[0]}"] [data-language]`)
    await expect(cols).toHaveCount(2)
    const [x, y] = await cols
      .evaluateAll((els) =>
        els.map((e) => {
          const r = e.getBoundingClientRect()
          return { left: Math.round(r.left), top: Math.round(r.top) }
        }),
      )
      .then((r) => [r[0], r[1]])
    expect(y.top).toBeGreaterThan(x.top)
    expect(Math.abs(y.left - x.left)).toBeLessThanOrEqual(12)
    await expect(cols.nth(0).locator('.tcols__lang')).toHaveText('EN')
    await expect(cols.nth(1).locator('.tcols__lang')).toHaveText('SK')
    const widths = await page.evaluate(() => ({
      scroll: document.documentElement.scrollWidth,
      inner: window.innerWidth,
    }))
    expect(widths.scroll).toBeLessThanOrEqual(widths.inner)
    await lineBox(page, ids[0]).scrollIntoViewIfNeeded()
    await shot(page, 'phone-line')
    await page.locator(`[data-test="script-line-menu-${ids[0]}"]`).click()
    await expect(page.locator(`[data-test="script-line-down-${ids[0]}"]`)).toBeVisible()
    await shot(page, 'phone-menu')
    await page.keyboard.press('Escape')
    await page.locator('[data-test="script-languages-open"]').click()
    await expect(page.locator('[data-test="script-languages-dialog"]')).toBeVisible()
    await shot(page, 'phone-languages')
    await page.keyboard.press('Escape')
    const after = await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    )
    expect(after).toBe(true)
  })
})
