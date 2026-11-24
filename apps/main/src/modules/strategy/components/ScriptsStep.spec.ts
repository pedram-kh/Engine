/**
 * PL-3.2a — step 6, Scripts: the rail (groups, status, add n/3, footnote, selection), the empty states, the editor (title row,
 * brief, hooks), autosave states in the page (saving, saved, could not save and retry, the 409 view with the person's own text
 * kept), the leave guard, delete script and delete hook confirms, the archived dialog by role, every menu and dialog opened,
 * closed and quickly reopened, and hostile text drawn as plain text.
 */
import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { VLayout } from 'vuetify/components'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/strategy.api', () => ({
  strategyApi: {
    scripts: vi.fn(),
    script: vi.fn(),
    createScript: vi.fn(),
    saveBrief: vi.fn(),
    saveHook: vi.fn(),
    addHook: vi.fn(),
    duplicateHook: vi.fn(),
    deleteHook: vi.fn(),
    deleteScript: vi.fn(),
    archivedScripts: vi.fn(),
    deleteArchivedScript: vi.fn(),
    listNotes: vi.fn(),
  },
}))

import { strategyApi } from '../api/strategy.api'
import {
  BRAND_ULID,
  makeSummary,
  mkt,
  mountStrategy,
  overlayText,
  WAVE_ULID,
} from '../strategyTestKit'
import {
  afterHookSave,
  conflict,
  HOOK_ID,
  makeArchived,
  makeHook,
  makeOverview,
  makeRailCreator,
  makeScript,
  RAIL_SLOT,
  SCRIPT_ID,
} from '../scriptsTestKit'
import ScriptsStep from './ScriptsStep.vue'

const ROUTE = `/brands/${BRAND_ULID}/waves/${WAVE_ULID}/strategy/scripts`
const HOSTILE = '<img src=x onerror=alert(1)> {{ 7*7 }} <script>alert(1)</script>'
const SK = mkt('SK', 1)

// Timers are faked only after the page has mounted and loaded (the debounce and the retries are what the tests move).
let fakeNext = false

type Role = 'agency_admin' | 'agency_manager' | 'agency_staff'

const Host = defineComponent({
  props: { summary: { type: Object, required: true } },
  setup(props) {
    return () => h(VLayout, null, () => h(ScriptsStep, { summary: props.summary as never }))
  },
})

async function mountStep(
  creators = [makeRailCreator(1, [1, 2]), makeRailCreator(2, [3]), makeRailCreator(3, [])],
  opts: { role?: Role; archived?: number; script?: ReturnType<typeof makeScript> } = {},
) {
  vi.mocked(strategyApi.scripts).mockResolvedValue({
    data: makeOverview(SK.market, creators, { archived: opts.archived ?? 0 }),
  })
  vi.mocked(strategyApi.script).mockResolvedValue({ data: opts.script ?? makeScript(1) })
  vi.mocked(strategyApi.listNotes).mockResolvedValue({ data: [] } as never)
  const w = await mountStrategy(
    Host,
    { summary: makeSummary({ markets: [SK, mkt('RO', 0)] }) },
    { role: opts.role ?? 'agency_admin', route: ROUTE },
  )
  await flushPromises()
  current = w
  if (fakeNext) {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    fakeNext = false
  }
  return w
}

// The wrapper is mounted on a detached div (dialogs and menus teleport to the body): look in both.
let current: { element: Element; unmount: () => void } | null = null
const q = (s: string) =>
  current?.element.querySelector<HTMLElement>(s) ?? document.body.querySelector<HTMLElement>(s)
async function click(selector: string): Promise<void> {
  const el = q(selector)
  if (el === null) throw new Error(`no ${selector}`)
  el.click()
  await flushPromises()
}
async function typeIn(testId: string, value: string): Promise<void> {
  const el = q(`[data-test="${testId}"] textarea`) as HTMLTextAreaElement | null
  if (el === null) throw new Error(`no textarea in ${testId}`)
  el.value = value
  el.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}
const tick = async (ms: number) => {
  await vi.advanceTimersByTimeAsync(ms)
  await flushPromises()
}
// vue-router needs more than one macrotask to finish a navigation here: wait a little (moving the fake clock when it is faked).
async function settle(): Promise<void> {
  if (vi.isFakeTimers()) await vi.advanceTimersByTimeAsync(50)
  else await new Promise((r) => setTimeout(r, 30))
  await flushPromises()
}
const HOOK1 = `script-hook-audio-${HOOK_ID(1)}`

describe('ScriptsStep (PL-3.2a)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(strategyApi.archivedScripts).mockResolvedValue({ data: [] })
  })
  afterEach(() => {
    vi.useRealTimers()
    fakeNext = false
    current?.unmount()
    current = null
    document.body.innerHTML = ''
  })

  describe('rail', () => {
    it('one group per creator with slot code and name, a row per script, the add button with n/3 and the footnote', async () => {
      const w = await mountStep()
      expect(w.findAll('[data-test^="rail-creator-"]')).toHaveLength(3)
      expect(w.get(`[data-test="rail-creator-${RAIL_SLOT(1)}"]`).text()).toContain('SK-C1')
      expect(w.get(`[data-test="rail-creator-${RAIL_SLOT(1)}"]`).text()).toContain('Creator 1')
      expect(
        w.findAll(`[data-test="rail-creator-${RAIL_SLOT(1)}"] [data-test^="rail-script-"]`),
      ).toHaveLength(2)
      expect(w.get(`[data-test="rail-add-${RAIL_SLOT(1)}"]`).text()).toContain('2/3')
      expect(w.get(`[data-test="rail-add-${RAIL_SLOT(3)}"]`).text()).toContain('0/3')
      expect(w.get('[data-test="scripts-rail-foot"]').text()).toBe('Up to 3 scripts per creator')
      expect(w.get('[data-test="scripts-rail-count"]').text()).toBe('3')
    })

    it('the first script is selected and open; clicking another loads it', async () => {
      const w = await mountStep()
      expect(w.get(`[data-test="rail-script-${SCRIPT_ID(1)}"]`).attributes('aria-current')).toBe(
        'true',
      )
      expect(strategyApi.script).toHaveBeenCalledWith(expect.anything(), SCRIPT_ID(1))
      vi.mocked(strategyApi.script).mockResolvedValue({ data: makeScript(3, { number: 1 }) })
      await w.get(`[data-test="rail-script-${SCRIPT_ID(3)}"]`).trigger('click')
      await flushPromises()
      await settle()
      expect(strategyApi.script).toHaveBeenLastCalledWith(expect.anything(), SCRIPT_ID(3))
      expect(w.get(`[data-test="rail-script-${SCRIPT_ID(3)}"]`).attributes('aria-current')).toBe(
        'true',
      )
    })

    it('a creator at three scripts cannot add: the button is disabled and says so', async () => {
      const w = await mountStep([makeRailCreator(1, [1, 2, 3])])
      const add = w.get(`[data-test="rail-add-${RAIL_SLOT(1)}"]`)
      expect(add.attributes('disabled')).toBeDefined()
      expect(add.text()).toContain('limit')
    })

    it('Add script creates for that slot, reloads the rail and opens the new script', async () => {
      const w = await mountStep()
      vi.mocked(strategyApi.createScript).mockResolvedValue({ data: makeScript(5) })
      await w.get(`[data-test="rail-add-${RAIL_SLOT(3)}"]`).trigger('click')
      await flushPromises()
      expect(strategyApi.createScript).toHaveBeenCalledWith(expect.anything(), RAIL_SLOT(3))
      expect(vi.mocked(strategyApi.scripts).mock.calls.length).toBeGreaterThanOrEqual(2)
    })

    it('a refused add (limit reached) shows the server wording and keeps the page', async () => {
      const w = await mountStep()
      vi.mocked(strategyApi.createScript).mockRejectedValue(
        new ApiError({ status: 422, code: 'wave_script.limit_reached', message: 'x' }),
      )
      await w.get(`[data-test="rail-add-${RAIL_SLOT(1)}"]`).trigger('click')
      await flushPromises()
      expect(w.get('[data-test="scripts-error"]').text()).not.toBe('')
      expect(w.find('[data-test="script-editor"]').exists()).toBe(true)
    })

    it('a creator named with markup is drawn as text', async () => {
      const c = makeRailCreator(1, [1])
      c.creator.display_name = HOSTILE
      const w = await mountStep([c])
      expect(w.find('[data-test="scripts-rail"] img').exists()).toBe(false)
      expect(w.get(`[data-test="rail-creator-${RAIL_SLOT(1)}"]`).text()).toContain('<img src=x')
    })
  })

  describe('empty states and counts', () => {
    it('no creators in the market: says so and links to step 5', async () => {
      const w = await mountStep([])
      expect(w.find('[data-test="scripts-no-creators"]').exists()).toBe(true)
      expect(w.get('[data-test="scripts-to-creators"]').attributes('href')).toContain(
        '/strategy/creators',
      )
      expect(w.find('[data-test="script-editor"]').exists()).toBe(false)
    })

    it('creators but no scripts: the rail shows the add buttons and the page asks to add one', async () => {
      const w = await mountStep([makeRailCreator(1, []), makeRailCreator(2, [])])
      expect(w.find('[data-test="scripts-none"]').exists()).toBe(true)
      expect(w.find(`[data-test="rail-add-${RAIL_SLOT(1)}"]`).exists()).toBe(true)
      expect(strategyApi.script).not.toHaveBeenCalled()
    })

    it('the market tab carries the script count', async () => {
      const w = await mountStep()
      expect(w.get('[data-test="market-count-SK"]').text()).toBe('3')
    })

    it('a failed read shows the error with a reload that reads again', async () => {
      vi.mocked(strategyApi.scripts).mockRejectedValue(new Error('x'))
      vi.mocked(strategyApi.listNotes).mockResolvedValue({ data: [] } as never)
      const w = await mountStrategy(
        Host,
        { summary: makeSummary({ markets: [SK, mkt('RO', 0)] }) },
        { route: ROUTE },
      )
      await flushPromises()
      expect(w.find('[data-test="scripts-load-error"]').exists()).toBe(true)
      vi.mocked(strategyApi.scripts).mockResolvedValue({
        data: makeOverview(SK.market, [makeRailCreator(1, [1])]),
      })
      vi.mocked(strategyApi.script).mockResolvedValue({ data: makeScript(1) })
      await w.get('[data-test="scripts-reload"]').trigger('click')
      await flushPromises()
      expect(w.find('[data-test="script-editor"]').exists()).toBe(true)
    })
  })

  describe('editor', () => {
    it('title row: slot, creator and script number, last edited; one read-only Draft pill; no regenerate, no language toggle, no lines section', async () => {
      const w = await mountStep()
      expect(w.get('[data-test="script-slot"]').text()).toBe('SK-C1')
      expect(w.get('[data-test="script-title"]').text()).toContain('Lucia Horváthová')
      expect(w.get('[data-test="script-title"]').text()).toContain('1')
      expect(w.get('[data-test="script-last-edited"]').text()).not.toBe('')
      expect(w.get('[data-test="script-status-pill"]').text()).toBe('Draft')
      expect(w.find('[data-test="script-workflow"] button').exists()).toBe(false)
      const text = w.get('[data-test="script-editor"]').text().toLowerCase()
      expect(text).not.toContain('regenerate')
      expect(text).not.toContain('b-roll')
      expect(w.find('[data-test="script-lines"]').exists()).toBe(false)
    })

    it('brief: persona chip, Priority and Support USPs, the angle, the duration with the estimate, the link to step 3', async () => {
      const w = await mountStep()
      expect(w.get('[data-test="script-brief-persona"]').text()).toContain(
        'The Office Lunch Organiser',
      )
      expect(w.get('[data-test="script-brief-usps"]').text()).toContain('Selection')
      expect(w.get('[data-test="script-brief-usps"]').text()).toContain('Speed')
      expect(
        (w.get('[data-test="script-angle"] textarea').element as HTMLTextAreaElement).value,
      ).toBe('The persona angle')
      expect(w.get('[data-test="script-estimate"]').text()).toContain('16')
      expect(w.get('[data-test="script-brief-step3"]').attributes('href')).toContain(
        '/strategy/matrix',
      )
    })

    it('no persona: the prompt with the link to step 5 replaces the persona rows', async () => {
      const s = makeScript(1)
      s.brief.persona = null
      s.brief.usps = []
      const w = await mountStep(undefined, { script: s })
      expect(w.find('[data-test="script-brief-no-persona"]').exists()).toBe(true)
      expect(w.find('[data-test="script-brief-persona"]').exists()).toBe(false)
      expect(w.get('[data-test="script-brief-step5"]').attributes('href')).toContain(
        '/strategy/creators',
      )
    })

    it('hooks A, B and C by default; the add button counts n/6; at six it is disabled', async () => {
      const w = await mountStep()
      expect(
        w
          .findAll('[data-test^="script-hook-"][data-letter]')
          .map((e) => e.attributes('data-letter')),
      ).toEqual(['A', 'B', 'C'])
      expect(w.get('[data-test="script-add-hook"]').text()).toContain('3/6')
    })

    it('hostile hook text is in the field as text and nowhere as markup; the collapsed line shows it as text', async () => {
      const s = makeScript(1)
      s.hooks[0] = makeHook(1, {
        texts: { audio: { en: HOSTILE }, overlay: { en: HOSTILE } },
        visual_description: HOSTILE,
      })
      const w = await mountStep(undefined, { script: s })
      expect((w.get(`[data-test="${HOOK1}"] textarea`).element as HTMLTextAreaElement).value).toBe(
        HOSTILE,
      )
      await w.get(`[data-test="script-hook-toggle-${HOOK_ID(1)}"]`).trigger('click')
      await flushPromises()
      expect(w.get(`[data-test="script-hook-summary-${HOOK_ID(1)}"]`).text()).toContain(
        '<img src=x',
      )
      expect(w.find('[data-test="script-editor"] img').exists()).toBe(false)
      expect(w.find('[data-test="script-editor"] script').exists()).toBe(false)
    })

    it('collapse and expand keeps the text; a draft is flushed before it collapses', async () => {
      fakeNext = true
      const s = makeScript(1)
      vi.mocked(strategyApi.saveHook).mockResolvedValue({
        data: afterHookSave(s, HOOK_ID(1), { texts: { audio: { en: 'typed' }, overlay: {} } }),
      })
      const w = await mountStep(undefined, { script: s })
      await typeIn(HOOK1, 'typed')
      await w.get(`[data-test="script-hook-toggle-${HOOK_ID(1)}"]`).trigger('click')
      await flushPromises()
      expect(strategyApi.saveHook).toHaveBeenCalledTimes(1)
      expect(w.get(`[data-test="script-hook-summary-${HOOK_ID(1)}"]`).text()).toContain('typed')
    })
  })

  describe('autosave in the page', () => {
    it('type and wait: Saving shows, then the hook is sent once with its revision and the chip says all saved', async () => {
      fakeNext = true
      const s = makeScript(1)
      vi.mocked(strategyApi.saveHook).mockResolvedValue({
        data: afterHookSave(s, HOOK_ID(1), {
          texts: { audio: { en: 'Hello there' }, overlay: {} },
        }),
      })
      const w = await mountStep(undefined, { script: s })
      await typeIn(HOOK1, 'Hello there')
      expect(w.get('[data-test="script-save-chip"]').attributes('data-state')).toBe('saving')
      expect(strategyApi.saveHook).not.toHaveBeenCalled()
      await tick(1600)
      expect(strategyApi.saveHook).toHaveBeenCalledTimes(1)
      const [, scriptId, hookId, payload] = vi.mocked(strategyApi.saveHook).mock.calls[0] ?? []
      expect([scriptId, hookId]).toEqual([SCRIPT_ID(1), HOOK_ID(1)])
      expect(payload).toMatchObject({ revision: 1, texts: { audio: { en: 'Hello there' } } })
      expect(w.get('[data-test="script-save-chip"]').attributes('data-state')).toBe('saved')
      expect(w.get(`[data-test="script-hook-status-${HOOK_ID(1)}"]`).text()).toContain('Saved')
    })

    it('type with the API down: Could not save with Retry, the text stays, the chip is stuck, Retry saves', async () => {
      fakeNext = true
      const s = makeScript(1)
      vi.mocked(strategyApi.saveHook).mockRejectedValue(new Error('network'))
      const w = await mountStep(undefined, { script: s })
      await typeIn(HOOK1, 'offline text')
      await tick(1600)
      expect(w.get('[data-test="script-save-chip"]').attributes('data-state')).toBe('stuck')
      expect(w.get(`[data-test="script-hook-status-${HOOK_ID(1)}"]`).text()).toContain(
        'Could not save',
      )
      expect((w.get(`[data-test="${HOOK1}"] textarea`).element as HTMLTextAreaElement).value).toBe(
        'offline text',
      )
      vi.mocked(strategyApi.saveHook).mockResolvedValue({
        data: afterHookSave(s, HOOK_ID(1), {
          texts: { audio: { en: 'offline text' }, overlay: {} },
        }),
      })
      await w.get(`[data-test="script-hook-status-${HOOK_ID(1)}-retry"]`).trigger('click')
      await flushPromises()
      expect(w.get('[data-test="script-save-chip"]').attributes('data-state')).toBe('saved')
    })

    it('a 409 shows the other version as text beside my own, which stays in the field; Copy my text copies mine', async () => {
      fakeNext = true
      const s = makeScript(1)
      const theirs = makeHook(1, { revision: 5, texts: { audio: { en: HOSTILE }, overlay: {} } })
      vi.mocked(strategyApi.saveHook).mockRejectedValue(conflict('hook', theirs))
      const writeText = vi.fn().mockResolvedValue(undefined)
      Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
      const w = await mountStep(undefined, { script: s })
      await typeIn(HOOK1, 'my own words')
      await tick(1600)
      const box = w.get(`[data-test="script-hook-conflict-${HOOK_ID(1)}"]`)
      expect(box.text()).toContain('<img src=x')
      expect(box.find('img').exists()).toBe(false)
      expect((w.get(`[data-test="${HOOK1}"] textarea`).element as HTMLTextAreaElement).value).toBe(
        'my own words',
      )
      expect(w.get('[data-test="script-save-chip"]').attributes('data-state')).toBe('stuck')
      await box.get(`[data-test="script-hook-conflict-${HOOK_ID(1)}-copy"]`).trigger('click')
      await flushPromises()
      expect(writeText).toHaveBeenCalledWith(expect.stringContaining('my own words'))
    })

    it('409 then Keep mine sends my text on top of their revision', async () => {
      fakeNext = true
      const s = makeScript(1)
      const theirs = makeHook(1, { revision: 5, texts: { audio: { en: 'theirs' }, overlay: {} } })
      vi.mocked(strategyApi.saveHook).mockRejectedValueOnce(conflict('hook', theirs))
      vi.mocked(strategyApi.saveHook).mockResolvedValue({
        data: afterHookSave({ ...s, hooks: [theirs, ...s.hooks.slice(1)] }, HOOK_ID(1), {
          texts: { audio: { en: 'mine' }, overlay: {} },
        }),
      })
      const w = await mountStep(undefined, { script: s })
      await typeIn(HOOK1, 'mine')
      await tick(1600)
      await w.get(`[data-test="script-hook-conflict-${HOOK_ID(1)}-keep"]`).trigger('click')
      await flushPromises()
      expect(vi.mocked(strategyApi.saveHook).mock.calls[1]?.[3]).toMatchObject({
        revision: 5,
        texts: { audio: { en: 'mine' } },
      })
      expect(w.find(`[data-test="script-hook-conflict-${HOOK_ID(1)}"]`).exists()).toBe(false)
    })

    it('two different hooks edited together are both saved, each with its own revision', async () => {
      fakeNext = true
      const s = makeScript(1)
      vi.mocked(strategyApi.saveHook).mockImplementation(async (_s, _id, hookId) => ({
        data: afterHookSave(s, hookId, {}),
      }))
      const w = await mountStep(undefined, { script: s })
      await typeIn(`script-hook-audio-${HOOK_ID(1)}`, 'first')
      await typeIn(`script-hook-audio-${HOOK_ID(2)}`, 'second')
      await tick(1600)
      const sent = vi
        .mocked(strategyApi.saveHook)
        .mock.calls.map((c) => [
          c[2],
          (c[3] as unknown as { texts: { audio: { en: string } } }).texts.audio.en,
        ])
      expect(sent).toEqual(
        expect.arrayContaining([
          [HOOK_ID(1), 'first'],
          [HOOK_ID(2), 'second'],
        ]),
      )
      expect(w.get('[data-test="script-save-chip"]').attributes('data-state')).toBe('saved')
    })

    it('a brief 409 shows the brief conflict box and keeps the typed angle', async () => {
      fakeNext = true
      const s = makeScript(1)
      const theirs = {
        ...s.brief,
        revision: 4,
        angle: { value: 'their angle', source: 'script', custom: true },
        duration_seconds: 45,
      }
      vi.mocked(strategyApi.saveBrief).mockRejectedValue(conflict('brief', theirs))
      const w = await mountStep(undefined, { script: s })
      await typeIn('script-angle', 'my angle')
      await tick(1600)
      expect(w.get('[data-test="script-brief-conflict"]').text()).toContain('their angle')
      expect(
        (w.get('[data-test="script-angle"] textarea').element as HTMLTextAreaElement).value,
      ).toBe('my angle')
    })

    it('a save that finds the script gone (404) takes the page back to the rail without asking', async () => {
      fakeNext = true
      const w = await mountStep()
      vi.mocked(strategyApi.saveHook).mockRejectedValue(
        new ApiError({ status: 404, code: 'wave_script.not_found', message: 'x' }),
      )
      await typeIn(HOOK1, 'x')
      await tick(1600)
      expect(w.get('[data-test="script-save-chip"]').attributes('data-state')).toBe('stuck')
    })
  })

  describe('leave guard', () => {
    it('nothing unsaved: moving to another step is not asked', async () => {
      const w = await mountStep()
      const go = w.vm.$router.push(`/brands/${BRAND_ULID}/waves/${WAVE_ULID}/strategy/matrix`)
      await settle()
      await go
      expect(w.vm.$route.path).toContain('/strategy/matrix')
      expect(q('[data-test="scripts-leave-dialog"]')).toBeNull()
    })

    it('unsaved text is saved first; when that works the person simply moves on', async () => {
      fakeNext = true
      const s = makeScript(1)
      vi.mocked(strategyApi.saveHook).mockResolvedValue({ data: afterHookSave(s, HOOK_ID(1), {}) })
      const w = await mountStep(undefined, { script: s })
      await typeIn(HOOK1, 'leave at once')
      const go = w.vm.$router.push(`/brands/${BRAND_ULID}/waves/${WAVE_ULID}/strategy/matrix`)
      await settle()
      await go
      expect(strategyApi.saveHook).toHaveBeenCalledTimes(1)
      expect(w.vm.$route.path).toContain('/strategy/matrix')
    })

    it('unsaved text that cannot be saved stops the move and asks: Stay keeps the page; Leave anyway goes', async () => {
      fakeNext = true
      vi.mocked(strategyApi.saveHook).mockRejectedValue(new Error('down'))
      const w = await mountStep()
      await typeIn(HOOK1, 'stuck text')
      const go = w.vm.$router.push(`/brands/${BRAND_ULID}/waves/${WAVE_ULID}/strategy/matrix`)
      await settle()
      expect(q('[data-test="scripts-leave-dialog"]')).not.toBeNull()
      await click('[data-test="scripts-leave-stay"]')
      await go
      expect(w.vm.$route.path).toContain('/strategy/scripts')
      expect((w.get(`[data-test="${HOOK1}"] textarea`).element as HTMLTextAreaElement).value).toBe(
        'stuck text',
      )

      const go2 = w.vm.$router.push(`/brands/${BRAND_ULID}/waves/${WAVE_ULID}/strategy/matrix`)
      await settle()
      await click('[data-test="scripts-leave-discard"]')
      await settle()
      await go2
      expect(w.vm.$route.path).toContain('/strategy/matrix')
    })

    it('switching to another script with unsaved text asks the same way', async () => {
      fakeNext = true
      vi.mocked(strategyApi.saveHook).mockRejectedValue(new Error('down'))
      const w = await mountStep()
      await typeIn(HOOK1, 'stuck text')
      await w.get(`[data-test="rail-script-${SCRIPT_ID(3)}"]`).trigger('click')
      await settle()
      expect(q('[data-test="scripts-leave-dialog"]')).not.toBeNull()
      expect(w.get(`[data-test="rail-script-${SCRIPT_ID(1)}"]`).attributes('aria-current')).toBe(
        'true',
      )
    })

    it('the leave dialog opened, closed and quickly reopened is the same dialog with the same buttons', async () => {
      fakeNext = true
      vi.mocked(strategyApi.saveHook).mockRejectedValue(new Error('down'))
      const w = await mountStep()
      await typeIn(HOOK1, 'stuck text')
      for (let i = 0; i < 3; i++) {
        const go = w.vm.$router.push(`/brands/${BRAND_ULID}/waves/${WAVE_ULID}/strategy/matrix`)
        await settle()
        expect(q('[data-test="scripts-leave-stay"]')).not.toBeNull()
        await click('[data-test="scripts-leave-stay"]')
        await go
      }
      expect(w.vm.$route.path).toContain('/strategy/scripts')
    })

    it('the page asks the browser before closing while anything is unsaved, and not otherwise', async () => {
      fakeNext = true
      await mountStep()
      const clean = new Event('beforeunload', { cancelable: true })
      window.dispatchEvent(clean)
      expect(clean.defaultPrevented).toBe(false)
      await typeIn(HOOK1, 'x')
      const dirty = new Event('beforeunload', { cancelable: true })
      window.dispatchEvent(dirty)
      expect(dirty.defaultPrevented).toBe(true)
    })
  })

  describe('delete script and delete hook', () => {
    it('delete script: the confirm counts the hooks; Cancel sends nothing; Confirm deletes and the page reloads the rail', async () => {
      const w = await mountStep()
      await w.get('[data-test="script-delete"]').trigger('click')
      await flushPromises()
      expect(overlayText()).toContain('hooks: 3')
      await click('[data-test="script-delete-dialog"] [data-test="confirm-cancel"]')
      expect(strategyApi.deleteScript).not.toHaveBeenCalled()
      await w.get('[data-test="script-delete"]').trigger('click')
      await flushPromises()
      vi.mocked(strategyApi.deleteScript).mockResolvedValue({
        data: makeOverview(SK.market, [makeRailCreator(1, [2])]),
      })
      await click('[data-test="script-delete-dialog"] [data-test="confirm-ok"]')
      expect(strategyApi.deleteScript).toHaveBeenCalledWith(expect.anything(), SCRIPT_ID(1))
      expect(vi.mocked(strategyApi.scripts).mock.calls.length).toBeGreaterThanOrEqual(2)
    })

    it('delete script: a server refusal shows inside the dialog and keeps it open', async () => {
      const w = await mountStep()
      await w.get('[data-test="script-delete"]').trigger('click')
      await flushPromises()
      vi.mocked(strategyApi.deleteScript).mockRejectedValue(
        new ApiError({ status: 500, code: 'x', message: 'x' }),
      )
      await click('[data-test="script-delete-dialog"] [data-test="confirm-ok"]')
      expect(q('[data-test="script-delete-dialog"] [data-test="confirm-error"]')).not.toBeNull()
    })

    it('delete hook: an empty hook goes at once; one with text asks first; the menu opens, closes and reopens', async () => {
      const s = makeScript(1)
      s.hooks[1] = makeHook(2, { texts: { audio: { en: 'has text' }, overlay: {} } })
      const w = await mountStep(undefined, { script: s })
      vi.mocked(strategyApi.deleteHook).mockResolvedValue({
        data: { ...s, hooks: [s.hooks[0]!, s.hooks[2]!] },
      })

      for (let i = 0; i < 2; i++) {
        await w.get(`[data-test="script-hook-menu-${HOOK_ID(2)}"]`).trigger('click')
        await flushPromises()
        expect(q(`[data-test="script-hook-delete-${HOOK_ID(2)}"]`)).not.toBeNull()
        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
        await flushPromises()
      }
      await w.get(`[data-test="script-hook-menu-${HOOK_ID(2)}"]`).trigger('click')
      await flushPromises()
      await click(`[data-test="script-hook-delete-${HOOK_ID(2)}"]`)
      expect(q(`[data-test="script-hook-delete-dialog-${HOOK_ID(2)}"]`)).not.toBeNull()
      expect(strategyApi.deleteHook).not.toHaveBeenCalled()
      await click(`[data-test="script-hook-delete-dialog-${HOOK_ID(2)}"] [data-test="confirm-ok"]`)
      expect(strategyApi.deleteHook).toHaveBeenCalledWith(
        expect.anything(),
        SCRIPT_ID(1),
        HOOK_ID(2),
      )

      vi.mocked(strategyApi.deleteHook).mockClear()
      await w.get(`[data-test="script-hook-menu-${HOOK_ID(1)}"]`).trigger('click')
      await flushPromises()
      await click(`[data-test="script-hook-delete-${HOOK_ID(1)}"]`)
      expect(strategyApi.deleteHook).toHaveBeenCalledTimes(1)
    })

    it('add hook calls the API; the shot-type menu lists the ten and picking one saves it', async () => {
      fakeNext = true
      const s = makeScript(1)
      const w = await mountStep(undefined, { script: s })
      vi.mocked(strategyApi.addHook).mockResolvedValue({
        data: { ...s, hooks: [...s.hooks, makeHook(4)] },
      })
      await w.get('[data-test="script-add-hook"]').trigger('click')
      await flushPromises()
      expect(strategyApi.addHook).toHaveBeenCalledWith(expect.anything(), SCRIPT_ID(1))
      expect(w.get('[data-test="script-add-hook"]').text()).toContain('4/6')

      vi.mocked(strategyApi.saveHook).mockResolvedValue({
        data: afterHookSave(s, HOOK_ID(1), { shot_type: 'pov' }),
      })
      for (let i = 0; i < 2; i++) {
        await w.get(`[data-test="script-hook-shot-${HOOK_ID(1)}"]`).trigger('click')
        await flushPromises()
        expect(document.body.querySelectorAll('[data-test^="script-shot-"]')).toHaveLength(10)
        if (i === 0) {
          document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
          await flushPromises()
        }
      }
      await click('[data-test="script-shot-pov"]')
      await tick(1600)
      expect(vi.mocked(strategyApi.saveHook).mock.calls[0]?.[3]).toMatchObject({ shot_type: 'pov' })
    })
  })

  describe('archived scripts', () => {
    it('no entry without archived scripts; with them the entry counts and opens the dialog', async () => {
      const none = await mountStep()
      expect(none.find('[data-test="scripts-archived-open"]').exists()).toBe(false)
      none.unmount()
      document.body.innerHTML = ''
      const w = await mountStep(undefined, { archived: 2 })
      expect(w.get('[data-test="scripts-archived-open"]').text()).toContain('2')
    })

    it('admin sees a permanent delete with a confirm; staff see the list only', async () => {
      vi.mocked(strategyApi.archivedScripts).mockResolvedValue({ data: [makeArchived(1)] })
      const admin = await mountStep(undefined, { archived: 1, role: 'agency_admin' })
      await admin.get('[data-test="scripts-archived-open"]').trigger('click')
      await flushPromises()
      expect(q(`[data-test="archived-${makeArchived(1).id}"]`)).not.toBeNull()
      expect(q(`[data-test="archived-delete-${makeArchived(1).id}"]`)).not.toBeNull()
      vi.mocked(strategyApi.deleteArchivedScript).mockResolvedValue({
        data: makeOverview(SK.market, [], { archived: 0 }),
      })
      await click(`[data-test="archived-delete-${makeArchived(1).id}"]`)
      expect(q('[data-test="archived-delete-dialog"]')).not.toBeNull()
      expect(strategyApi.deleteArchivedScript).not.toHaveBeenCalled()
      await click('[data-test="archived-delete-dialog"] [data-test="confirm-ok"]')
      expect(strategyApi.deleteArchivedScript).toHaveBeenCalledWith(
        expect.anything(),
        makeArchived(1).id,
      )
      admin.unmount()
      document.body.innerHTML = ''

      const staff = await mountStep(undefined, { archived: 1, role: 'agency_staff' })
      await staff.get('[data-test="scripts-archived-open"]').trigger('click')
      await flushPromises()
      expect(q(`[data-test="archived-${makeArchived(1).id}"]`)).not.toBeNull()
      expect(q(`[data-test="archived-delete-${makeArchived(1).id}"]`)).toBeNull()
    })

    it('opened, closed and quickly reopened: one fresh read each time, the list is not doubled', async () => {
      vi.mocked(strategyApi.archivedScripts).mockResolvedValue({ data: [makeArchived(1)] })
      const w = await mountStep(undefined, { archived: 1 })
      for (let i = 0; i < 3; i++) {
        await w.get('[data-test="scripts-archived-open"]').trigger('click')
        await flushPromises()
        expect(
          document.body.querySelectorAll(
            '[data-test^="archived-Gone"], [data-test^="archived-01SCRP"]',
          ).length,
        ).toBeGreaterThan(0)
        expect(
          document.body.querySelectorAll(`[data-test="archived-${makeArchived(1).id}"]`),
        ).toHaveLength(1)
        await click('[data-test="archived-close"]')
      }
      expect(vi.mocked(strategyApi.archivedScripts)).toHaveBeenCalledTimes(3)
    })

    it('an archived script whose creator is back says so: the way back is the creator, not a button', async () => {
      vi.mocked(strategyApi.archivedScripts).mockResolvedValue({
        data: [makeArchived(1, { creator_in_market: true })],
      })
      const w = await mountStep(undefined, { archived: 1 })
      await w.get('[data-test="scripts-archived-open"]').trigger('click')
      await flushPromises()
      expect(q(`[data-test="archived-back-${makeArchived(1).id}"]`)).not.toBeNull()
    })
  })
})
