/**
 * AH-085 — Vitest coverage for the talent-pool EDIT page's brand select: it
 * fetches via `brandsApi.listOptions` (unpaginated), not the old paginated
 * `list({ per_page: 100 })` call, so every brand — not just the first
 * alphabetical page — is reachable. No spec previously existed for this page.
 */

import type { TalentPoolResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import { useAgencyStore } from '@/core/stores/useAgencyStore'

vi.mock('../api/talentPools.api', () => ({
  talentPoolsApi: { show: vi.fn(), update: vi.fn() },
}))

vi.mock('@/modules/brands/api/brands.api', () => ({
  brandsApi: { listOptions: vi.fn() },
}))

import { brandsApi } from '@/modules/brands/api/brands.api'
import { talentPoolsApi } from '../api/talentPools.api'
import PoolForm from '../components/PoolForm.vue'
import PoolEditPage from './PoolEditPage.vue'

const POOL_ULID = '01POOLULIDXXXXXXXXXXXXXXXX'

function makePool(): TalentPoolResource {
  return {
    id: POOL_ULID,
    type: 'talent_pools',
    attributes: {
      name: 'Acme Q3',
      description: null,
      brand_id: null,
      brand_name: null,
      is_archived: false,
      creators_count: 0,
      created_at: '2026-06-01T10:00:00.000000Z',
      updated_at: '2026-06-01T10:00:00.000000Z',
    },
  }
}

async function mountEdit(): Promise<ReturnType<typeof mount>> {
  const pinia = createPinia()
  setActivePinia(pinia)

  const agency = useAgencyStore()
  agency.initFromUser([
    { agency_id: 'agency-ulid', agency_name: 'Test Agency', role: 'agency_admin' },
  ])

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/talent-pools/:ulid', name: 'pools.detail', component: { template: '<div />' } },
      { path: '/talent-pools/:ulid/edit', name: 'pools.edit', component: { template: '<div />' } },
    ],
  })
  await router.push(`/talent-pools/${POOL_ULID}/edit`)
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  const wrapper = mount(PoolEditPage, {
    global: { plugins: [pinia, router, i18n, vuetify] },
    attachTo: document.createElement('div'),
  })

  await flushPromises()

  return wrapper
}

describe('PoolEditPage — the brand select (AH-085)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(talentPoolsApi.show).mockResolvedValue({ data: makePool() })
  })

  it('§5.34 — fetches via listOptions (unpaginated), and every brand past the old 25-row page is reachable in the edit-form select', async () => {
    // page_size (25) + 5 — the exact shape the old `per_page: 100` /
    // hardcoded-`paginate(25)` mismatch silently truncated.
    const brands = Array.from({ length: 30 }, (_, i) => ({
      id: `brand-${i}`,
      type: 'brands' as const,
      attributes: { name: `Brand ${String(i).padStart(2, '0')}` },
    }))
    vi.mocked(brandsApi.listOptions).mockResolvedValue({ data: brands })

    const wrapper = await mountEdit()

    expect(brandsApi.listOptions).toHaveBeenCalledWith('agency-ulid', 'active')

    const form = wrapper.findComponent(PoolForm)
    expect(form.props('brandOptions')).toHaveLength(30)
    expect(form.props('brandOptions')).toContainEqual({ value: 'brand-29', title: 'Brand 29' })
  })
})
