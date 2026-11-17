/** PL-1.1 W2 — BrandWavesPanel: rows, states, role gating, row click. */
import { flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/waves/api/waves.api', () => ({
  wavesApi: {
    list: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    show: vi.fn(),
    campaignOptions: vi.fn(),
  },
}))

import { wavesApi } from '@/modules/waves/api/waves.api'
import {
  AGENCY_ULID,
  BRAND_ULID,
  makeWave,
  mountWithApp,
  WAVE_ULID,
} from '@/modules/waves/waveTestKit'
import BrandWavesPanel from './BrandWavesPanel.vue'

const props = (canWrite: boolean) => ({
  agencyId: AGENCY_ULID,
  brandId: BRAND_ULID,
  brandName: 'Acme Corp',
  canWrite,
})
const route = `/brands/${BRAND_ULID}`

function list(waves = [makeWave()]) {
  vi.mocked(wavesApi.list).mockResolvedValue({
    data: waves,
    meta: { total: waves.length, page: 1, per_page: 25, last_page: 1 },
  })
}

describe('BrandWavesPanel (PL-1.1 W2)', () => {
  beforeEach(() => vi.clearAllMocks())

  it('lists a wave with name, go-live date, market codes, progress, status and updated', async () => {
    list([makeWave({ markets: ['ES', 'PT', 'FR', 'DE', 'IT'] })])
    const w = await mountWithApp(BrandWavesPanel, props(true), { route })
    await flushPromises()
    expect(wavesApi.list).toHaveBeenCalledWith(AGENCY_ULID, BRAND_ULID, { per_page: 25 })
    const row = w.get(`[data-test="wave-row-${WAVE_ULID}"]`)
    expect(row.text()).toContain('Spring push')
    expect(row.text()).toContain('Go-live')
    expect(row.get('[data-test="wave-row-markets"]').text().replace(/\s+/g, ' ')).toBe(
      'ES, PT, FR +2',
    )
    expect(row.get('[data-test="wave-progress"]').findAll('.wave-progress__segment')).toHaveLength(
      6,
    )
    expect(row.text()).toContain('Not started')
    expect(row.get('[data-test="agency-status-pill"]').text()).toBe('Draft')
    expect(w.get('[data-test="brand-waves-count"]').text()).toBe('1 wave')
    w.unmount()
  })

  it('shows the loading state, then the empty state', async () => {
    let resolve: (v: never) => void = () => undefined
    vi.mocked(wavesApi.list).mockReturnValue(new Promise((r) => (resolve = r as never)))
    const w = await mountWithApp(BrandWavesPanel, props(true), { route })
    expect(w.find('[data-test="brand-waves-loading"]').exists()).toBe(true)
    resolve({ data: [], meta: { total: 0, page: 1, per_page: 25, last_page: 1 } } as never)
    await flushPromises()
    expect(w.find('[data-test="brand-waves-empty"]').exists()).toBe(true)
    expect(w.find('[data-test="brand-waves-table"]').exists()).toBe(false)
    w.unmount()
  })

  it('shows the error state when the list fails', async () => {
    vi.mocked(wavesApi.list).mockRejectedValue(new Error('boom'))
    const w = await mountWithApp(BrandWavesPanel, props(true), { route })
    await flushPromises()
    expect(w.get('[data-test="brand-waves-error"]').text()).toBe('Failed to load waves.')
    w.unmount()
  })

  it('shows New wave to an admin or manager and hides it for staff and on an archived brand', async () => {
    list()
    const admin = await mountWithApp(BrandWavesPanel, props(true), { route })
    await flushPromises()
    expect(admin.find('[data-test="wave-new-btn"]').exists()).toBe(true)
    admin.unmount()

    // `canWrite` is false for staff and for an archived brand (BrandDetailPage computes it).
    const staff = await mountWithApp(BrandWavesPanel, props(false), { route, role: 'agency_staff' })
    await flushPromises()
    expect(staff.find('[data-test="wave-new-btn"]').exists()).toBe(false)
    expect(staff.find(`[data-test="wave-row-${WAVE_ULID}"]`).exists()).toBe(true)
    staff.unmount()
  })

  it('a row click goes to the wave page', async () => {
    list()
    const w = await mountWithApp(BrandWavesPanel, props(true), { route })
    await flushPromises()
    const router = w.vm.$router
    const push = vi.spyOn(router, 'push')
    await w.get(`[data-test="wave-row-${WAVE_ULID}"]`).trigger('click')
    await flushPromises()
    expect(push).toHaveBeenCalledWith({
      name: 'waves.detail',
      params: { ulid: BRAND_ULID, waveUlid: WAVE_ULID },
    })
    await push.mock.results[0]?.value
    expect(router.currentRoute.value.name).toBe('waves.detail')
    expect(router.currentRoute.value.params).toMatchObject({
      ulid: BRAND_ULID,
      waveUlid: WAVE_ULID,
    })
    w.unmount()
  })
})
