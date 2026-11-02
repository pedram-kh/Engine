/**
 * AH-116 (A3 + item 5) — Vitest coverage for the brand DETAIL page.
 *
 * `GET brands/{brand}` now returns an archived brand (a campaign or pool can
 * still point at one, and their brand names are links). The page must therefore
 * open an archived brand READ-ONLY: the Archived pill says why, and neither Edit
 * (its `update` route does not resolve archived brands, so it would 404) nor
 * Archive (already done) is drawn.
 */

import type { BrandResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'
import { useAgencyStore } from '@/core/stores/useAgencyStore'

import BrandDetailPage from './BrandDetailPage.vue'

vi.mock('../api/brands.api', () => ({
  brandsApi: {
    show: vi.fn(),
    archive: vi.fn(),
  },
}))

import { brandsApi } from '../api/brands.api'

const BRAND_ULID = '01BRANDULIDXXXXXXXXXXXXXX'

const localStorageStore: Record<string, string> = {}
Object.defineProperty(globalThis, 'localStorage', {
  value: {
    getItem: (k: string): string | null => localStorageStore[k] ?? null,
    setItem: (k: string, v: string): void => {
      localStorageStore[k] = v
    },
    removeItem: (k: string): void => {
      delete localStorageStore[k]
    },
  },
  writable: true,
})

function makeBrand(status: 'active' | 'archived'): BrandResource {
  return {
    id: BRAND_ULID,
    type: 'brands',
    attributes: {
      name: 'Acme Corp',
      slug: 'acme-corp',
      description: null,
      industry: null,
      website_url: null,
      logo_path: null,
      logo_url: null,
      default_currency: 'EUR',
      default_language: 'en',
      status,
      brand_safety_rules: null,
      exclusivity_window_days: null,
      client_portal_enabled: false,
      created_at: '2026-05-15T10:00:00.000000Z',
      updated_at: '2026-05-15T10:00:00.000000Z',
    },
  } as unknown as BrandResource
}

async function mountDetail(
  status: 'active' | 'archived',
): Promise<{ wrapper: ReturnType<typeof mount>; cleanup: () => void }> {
  const pinia = createPinia()
  setActivePinia(pinia)

  vi.mocked(brandsApi.show).mockResolvedValue({ data: makeBrand(status) })

  const agency = useAgencyStore()
  agency.initFromUser([
    { agency_id: 'agency-ulid', agency_name: 'Test Agency', role: 'agency_admin' },
  ])

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/brands', name: 'brands.list', component: { template: '<div />' } },
      { path: '/brands/:ulid', name: 'brands.detail', component: { template: '<div />' } },
      { path: '/brands/:ulid/edit', name: 'brands.edit', component: { template: '<div />' } },
    ],
  })
  await router.push(`/brands/${BRAND_ULID}`)
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })

  const wrapper = mount(BrandDetailPage, {
    global: { plugins: [pinia, router, i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()

  return {
    wrapper,
    cleanup: () => {
      wrapper.unmount()
      Object.keys(localStorageStore).forEach((k) => delete localStorageStore[k])
    },
  }
}

describe('BrandDetailPage (AH-116)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  it('loads the brand scoped to the agency and the route ulid', async () => {
    const harness = await mountDetail('active')
    cleanup = harness.cleanup

    expect(brandsApi.show).toHaveBeenCalledWith('agency-ulid', BRAND_ULID)
    expect(harness.wrapper.get('[data-test="brand-detail-heading"]').text()).toBe('Acme Corp')
  })

  it('an active brand shows an Active pill and offers Edit and Archive', async () => {
    const harness = await mountDetail('active')
    cleanup = harness.cleanup

    expect(harness.wrapper.get('[data-test="brand-detail-status"]').text()).toBe('Active')
    expect(harness.wrapper.find('[data-test="brand-edit-btn"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="brand-archive-btn"]').exists()).toBe(true)
  })

  it('an archived brand opens, shows the Archived pill, and offers neither Edit nor Archive', async () => {
    const harness = await mountDetail('archived')
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="brand-detail-error"]').exists()).toBe(false)
    expect(harness.wrapper.get('[data-test="brand-detail-heading"]').text()).toBe('Acme Corp')
    expect(harness.wrapper.get('[data-test="brand-detail-status"]').text()).toBe('Archived')
    expect(harness.wrapper.find('[data-test="brand-detail-card"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="brand-edit-btn"]').exists()).toBe(false)
    expect(harness.wrapper.find('[data-test="brand-archive-btn"]').exists()).toBe(false)
  })
})
