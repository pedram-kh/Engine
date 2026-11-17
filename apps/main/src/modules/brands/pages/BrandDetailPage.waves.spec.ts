/**
 * PL-1.1 W1 — the brand detail page behind `features.waves`.
 *
 * The existing `BrandDetailPage.spec.ts` pins the flag-OFF page and stays unchanged. This file pins that
 * OFF (false or absent) draws no panel, no modifier class, and calls neither new API; and that ON draws
 * both panels in the two-column layout, with the write controls gated on role and on an active brand.
 */
import type { BrandResource } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/brands.api', () => ({ brandsApi: { show: vi.fn(), archive: vi.fn() } }))
vi.mock('../api/brandFiles.api', () => ({
  brandFilesApi: {
    list: vi.fn(),
    initUpload: vi.fn(),
    completeUpload: vi.fn(),
    show: vi.fn(),
    delete: vi.fn(),
  },
}))
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
import { BRAND_ULID, makeWave, mountWithApp, type Role } from '@/modules/waves/waveTestKit'
import { brandFilesApi } from '../api/brandFiles.api'
import { brandsApi } from '../api/brands.api'
import BrandDetailPage from './BrandDetailPage.vue'

function brand(status: 'active' | 'archived'): BrandResource {
  return {
    id: BRAND_ULID,
    type: 'brands',
    attributes: {
      name: 'Acme Corp',
      slug: 'acme',
      description: null,
      industry: null,
      website_url: null,
      logo_url: null,
      status,
      created_at: '2026-05-15T10:00:00.000000Z',
    },
  } as unknown as BrandResource
}

async function mountPage(opts: {
  flag: boolean | 'absent'
  role?: Role
  status?: 'active' | 'archived'
}) {
  vi.mocked(brandsApi.show).mockResolvedValue({ data: brand(opts.status ?? 'active') })
  vi.mocked(brandFilesApi.list).mockResolvedValue({
    data: [],
    meta: { total: 0, page: 1, per_page: 100, last_page: 1 },
  })
  vi.mocked(wavesApi.list).mockResolvedValue({
    data: [makeWave()],
    meta: { total: 1, page: 1, per_page: 25, last_page: 1 },
  })
  const w = await mountWithApp(
    BrandDetailPage,
    {},
    { flag: opts.flag, role: opts.role, route: `/brands/${BRAND_ULID}` },
  )
  await flushPromises()
  return w
}

describe('BrandDetailPage behind features.waves (PL-1.1 W1)', () => {
  beforeEach(() => vi.clearAllMocks())

  it.each([false, 'absent'] as const)(
    'flag %s: no panel, no modifier class, no new API call',
    async (flag) => {
      const w = await mountPage({ flag })
      expect(w.find('[data-test="brand-detail-card"]').exists()).toBe(true)
      expect(w.find('.agency-detail').classes()).toEqual(['agency-detail'])
      expect(w.find('[data-test="brand-detail-side"]').exists()).toBe(false)
      expect(w.find('[data-test="brand-waves-panel"]').exists()).toBe(false)
      expect(w.find('[data-test="brand-files-panel"]').exists()).toBe(false)
      expect(wavesApi.list).not.toHaveBeenCalled()
      expect(brandFilesApi.list).not.toHaveBeenCalled()
      w.unmount()
    },
  )

  it('flag on: both panels, the two-column modifier, and both lists are loaded for this brand', async () => {
    const w = await mountPage({ flag: true })
    expect(w.get('.agency-detail').classes()).toContain('agency-detail--with-side')
    expect(w.find('[data-test="brand-detail-side"] [data-test="brand-files-panel"]').exists()).toBe(
      true,
    )
    expect(w.find('[data-test="brand-detail-side"] [data-test="brand-waves-panel"]').exists()).toBe(
      true,
    )
    expect(wavesApi.list).toHaveBeenCalledWith('agency-ulid', BRAND_ULID, { per_page: 25 })
    expect(brandFilesApi.list).toHaveBeenCalledWith('agency-ulid', BRAND_ULID, { per_page: 100 })
    expect(w.find('[data-test="wave-new-btn"]').exists()).toBe(true)
    expect(w.find('[data-test="brand-files-upload-btn"]').exists()).toBe(true)
    w.unmount()
  })

  it('flag on, staff: panels show, but no New wave and no Upload', async () => {
    const w = await mountPage({ flag: true, role: 'agency_staff' })
    expect(w.find('[data-test="brand-waves-panel"]').exists()).toBe(true)
    expect(w.find('[data-test="wave-new-btn"]').exists()).toBe(false)
    expect(w.find('[data-test="brand-files-upload-btn"]').exists()).toBe(false)
    w.unmount()
  })

  it('flag on, archived brand: panels show read-only for an admin too', async () => {
    const w = await mountPage({ flag: true, status: 'archived' })
    expect(w.find('[data-test="brand-waves-panel"]').exists()).toBe(true)
    expect(w.find('[data-test="wave-new-btn"]').exists()).toBe(false)
    expect(w.find('[data-test="brand-files-upload-btn"]').exists()).toBe(false)
    w.unmount()
  })
})
