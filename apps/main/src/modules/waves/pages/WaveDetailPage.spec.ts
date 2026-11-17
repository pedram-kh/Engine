/** PL-1.1 W6 — WaveDetailPage: shell fields, campaign link, flag-off redirect, 404 state. */
import { flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/waves.api', () => ({
  wavesApi: {
    list: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    show: vi.fn(),
    campaignOptions: vi.fn(),
  },
}))

import { wavesApi } from '../api/waves.api'
import { AGENCY_ULID, BRAND_ULID, makeWave, mountWithApp, WAVE_ULID } from '../waveTestKit'
import WaveDetailPage from './WaveDetailPage.vue'

describe('WaveDetailPage (PL-1.1 W6)', () => {
  beforeEach(() => vi.clearAllMocks())

  it('shows the wave shell: name, status, brand, go-live, markets, progress, creator', async () => {
    vi.mocked(wavesApi.show).mockResolvedValue({ data: makeWave() })
    const w = await mountWithApp(WaveDetailPage, {})
    await flushPromises()
    expect(wavesApi.show).toHaveBeenCalledWith(AGENCY_ULID, BRAND_ULID, WAVE_ULID)
    expect(w.get('[data-test="wave-detail-heading"]').text()).toBe('Spring push')
    expect(w.get('[data-test="wave-status"]').text()).toBe('Draft')
    expect(w.get('[data-test="wave-field-brand"]').text()).toContain('Acme Corp')
    expect(w.get('[data-test="wave-field-go-live"]').text()).toContain('2026')
    expect(w.get('[data-test="wave-field-markets"]').text()).toContain('Spain')
    expect(w.get('[data-test="wave-field-markets"]').text()).toContain('Portugal')
    expect(w.get('[data-test="wave-field-progress"]').text()).toContain('Not started')
    expect(w.get('[data-test="wave-field-created-by"]').text()).toContain('Ada Admin')
    w.unmount()
  })

  it('links to the campaign when there is one, and says so when there is not', async () => {
    vi.mocked(wavesApi.show).mockResolvedValue({ data: makeWave({}, true) })
    const linked = await mountWithApp(WaveDetailPage, {})
    await flushPromises()
    const link = linked.get('[data-test="wave-campaign-link"]')
    expect(link.text()).toBe('Spring campaign')
    expect(link.attributes('href')).toBe('/campaigns/01CAMPAIGNULIDXXXXXXXXXX')
    linked.unmount()

    vi.mocked(wavesApi.show).mockResolvedValue({ data: makeWave() })
    const unlinked = await mountWithApp(WaveDetailPage, {})
    await flushPromises()
    expect(unlinked.find('[data-test="wave-campaign-link"]').exists()).toBe(false)
    expect(unlinked.get('[data-test="wave-field-campaign"]').text()).toContain('No campaign linked')
    unlinked.unmount()
  })

  it('Edit is for an admin or manager only', async () => {
    vi.mocked(wavesApi.show).mockResolvedValue({ data: makeWave() })
    const admin = await mountWithApp(WaveDetailPage, {})
    await flushPromises()
    expect(admin.find('[data-test="wave-edit-btn"]').exists()).toBe(true)
    admin.unmount()
    const staff = await mountWithApp(WaveDetailPage, {}, { role: 'agency_staff' })
    await flushPromises()
    expect(staff.find('[data-test="wave-edit-btn"]').exists()).toBe(false)
    staff.unmount()
  })

  it('with the flag off (or absent) it redirects to the brand and never loads the wave', async () => {
    for (const flag of [false, 'absent'] as const) {
      const w = await mountWithApp(WaveDetailPage, {}, { flag })
      await flushPromises()
      expect(wavesApi.show).not.toHaveBeenCalled()
      expect(w.vm.$router.currentRoute.value.name).toBe('brands.detail')
      expect(w.vm.$router.currentRoute.value.params.ulid).toBe(BRAND_ULID)
      w.unmount()
    }
  })

  it('shows the not-found state when the wave is unknown (the server answers 404)', async () => {
    vi.mocked(wavesApi.show).mockRejectedValue(new Error('404'))
    const w = await mountWithApp(WaveDetailPage, {})
    await flushPromises()
    expect(w.get('[data-test="wave-detail-error"]').text()).toBe('This wave was not found.')
    expect(w.find('[data-test="wave-detail-card"]').exists()).toBe(false)
    w.unmount()
  })
})
