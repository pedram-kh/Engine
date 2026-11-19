/**
 * PL-2.1 W-OFF — the wave page is exactly as PL-1 left it with strategy off: no entry button and no comment node
 * where it would be (a `v-if` leaves `<!--v-if-->`; the entry is drawn from a zero-or-one list so nothing is left).
 * Also: with strategy on, the button is there for admin, manager and staff and goes to the USPs step.
 */
import { flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/waves/api/waves.api', () => ({
  wavesApi: {
    list: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    show: vi.fn(),
    campaignOptions: vi.fn(),
    tracker: vi.fn(() => new Promise(() => {})),
  },
}))
vi.mock('@/modules/agency-users/api/members.api', () => ({
  membersApi: { list: vi.fn(() => Promise.resolve({ data: [] })) },
}))

import { wavesApi } from '@/modules/waves/api/waves.api'
import WaveDetailPage from '@/modules/waves/pages/WaveDetailPage.vue'
import { BRAND_ULID, makeWave, mountWithApp, WAVE_ULID } from '@/modules/waves/waveTestKit'

/** Comment nodes inside the page-header actions slot. */
function commentsIn(root: Element): string[] {
  const out: string[] = []
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_COMMENT)
  while (walker.nextNode()) out.push(walker.currentNode.textContent ?? '')
  return out
}

describe('wave page entry to the strategy workspace (PL-2.1 W-OFF)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(wavesApi.show).mockResolvedValue({ data: makeWave() })
  })

  it('strategy off, absent or waves-only: no button, and the actions DOM is identical to PL-1', async () => {
    const baseline = await mountWithApp(WaveDetailPage, {}) // PL-1 mount: features { waves: true } only
    await flushPromises()
    const reference = baseline.get('[data-test="wave-edit-btn"]').element.parentElement?.innerHTML
    const referenceComments = commentsIn(baseline.element)
    baseline.unmount()

    for (const strategy of [undefined, false]) {
      const w = await mountWithApp(WaveDetailPage, {}, { strategy })
      await flushPromises()
      expect(w.find('[data-test="wave-strategy-btn"]').exists()).toBe(false)
      expect(w.get('[data-test="wave-edit-btn"]').element.parentElement?.innerHTML).toBe(reference)
      expect(commentsIn(w.element)).toEqual(referenceComments)
      expect(w.html()).not.toContain('Strategy &amp; Scripts')
      w.unmount()
    }
  })

  it('strategy off leaves exactly the comment nodes PL-1 had in the actions area (3, measured on 246713f5)', async () => {
    const w = await mountWithApp(WaveDetailPage, {}, { strategy: false })
    await flushPromises()
    const actions = w.get('[data-test="wave-edit-btn"]').element.parentElement
    expect(actions).not.toBeNull()
    const comments = actions === null ? [] : commentsIn(actions)
    // PL-1's own page (commit 246713f5, before any strategy code) has these 3 empty comments there; a `v-if` for the
    // entry button would make it 4 (`<!--v-if-->`).
    expect(comments).toHaveLength(3)
    w.unmount()
  })

  it('strategy on: the button is there for admin, manager and staff, and goes to the USPs step', async () => {
    for (const role of ['agency_admin', 'agency_manager', 'agency_staff'] as const) {
      const w = await mountWithApp(WaveDetailPage, {}, { strategy: true, role })
      await flushPromises()
      const btn = w.get('[data-test="wave-strategy-btn"]')
      expect(btn.text()).toBe('Strategy & Scripts')
      expect(btn.attributes('href')).toBe(`/brands/${BRAND_ULID}/waves/${WAVE_ULID}/strategy/usps`)
      expect(w.find('[data-test="wave-edit-btn"]').exists()).toBe(role !== 'agency_staff')
      w.unmount()
    }
  })

  it('waves off: the redirect still happens and the button never renders', async () => {
    const w = await mountWithApp(WaveDetailPage, {}, { flag: false, strategy: true })
    await flushPromises()
    expect(w.find('[data-test="wave-strategy-btn"]').exists()).toBe(false)
    expect(wavesApi.show).not.toHaveBeenCalled()
    w.unmount()
  })
})
