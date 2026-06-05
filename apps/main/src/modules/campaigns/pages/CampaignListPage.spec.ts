/**
 * Sprint 8 Chunk 1 — Vitest coverage for the campaign list page. List LOGIC
 * (renders rows from the {data,meta} envelope, empty state, the create
 * affordance) is unit-tested here with light Vuetify; full DOM + navigation
 * is Playwright.
 */

import type { CampaignResource } from '@catalyst/api-client'
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

import CampaignListPage from './CampaignListPage.vue'

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: { list: vi.fn() },
}))
vi.mock('@/modules/brands/api/brands.api', () => ({
  brandsApi: { list: vi.fn() },
}))

import { campaignsApi } from '../api/campaigns.api'
import { brandsApi } from '@/modules/brands/api/brands.api'

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

function makeCampaign(overrides: Partial<CampaignResource['attributes']> = {}): CampaignResource {
  return {
    id: '01HZA1B2C3D4E5F6G7H8J9K0M1',
    type: 'campaigns',
    attributes: {
      name: 'Summer launch',
      description: null,
      objective: 'awareness',
      status: 'active',
      budget_minor_units: 250000,
      budget_currency: 'EUR',
      starts_at: null,
      ends_at: null,
      posting_window_starts_at: null,
      posting_window_ends_at: null,
      brief: null,
      target_creator_count: null,
      requires_per_campaign_contract: false,
      is_marketplace_visible: false,
      published_at: null,
      completed_at: null,
      assignment_count: 0,
      created_at: '2026-06-01T10:00:00.000000Z',
      updated_at: '2026-06-01T10:00:00.000000Z',
      ...overrides,
    },
    relationships: {
      brand: { data: { id: 'brand-ulid', type: 'brands', name: 'Acme' } },
      agency: { data: { id: 'agency-ulid', type: 'agencies' } },
    },
  }
}

async function mountList(
  campaigns: CampaignResource[] = [],
): Promise<{ wrapper: ReturnType<typeof mount>; cleanup: () => void }> {
  const pinia = createPinia()
  setActivePinia(pinia)

  vi.mocked(campaignsApi.list).mockResolvedValue({
    data: campaigns,
    meta: { total: campaigns.length, page: 1, per_page: 25, last_page: 1 },
  })
  vi.mocked(brandsApi.list).mockResolvedValue({
    data: [],
    meta: { current_page: 1, from: null, last_page: 1, per_page: 100, to: null, total: 0 },
    links: { first: null, last: null, prev: null, next: null },
  })

  const agency = useAgencyStore()
  agency.initFromUser([
    { agency_id: 'agency-ulid', agency_name: 'Test Agency', role: 'agency_admin' },
  ])

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/campaigns', name: 'campaigns.list', component: { template: '<div />' } },
      { path: '/campaigns/new', name: 'campaigns.create', component: { template: '<div />' } },
      { path: '/campaigns/:ulid', name: 'campaigns.detail', component: { template: '<div />' } },
    ],
  })
  await router.push('/campaigns')
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

  const wrapper = mount(CampaignListPage, {
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

describe('CampaignListPage (Sprint 8 Chunk 1)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  it('renders the empty state when there are no campaigns', async () => {
    const harness = await mountList([])
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="campaign-empty-state"]').exists()).toBe(true)
  })

  it('renders campaign rows from the {data,meta} envelope', async () => {
    const harness = await mountList([makeCampaign({ name: 'Summer launch' })])
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="campaign-table"]').exists()).toBe(true)
    expect(harness.wrapper.text()).toContain('Summer launch')
  })

  it('always shows the create affordance (backend enforces the admin/manager gate)', async () => {
    const harness = await mountList([makeCampaign()])
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="campaign-create-btn"]').exists()).toBe(true)
  })

  it('threads the status filter to the API when a chip is selected', async () => {
    const harness = await mountList([makeCampaign()])
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { statusFilter: string }).statusFilter = 'draft'
    await flushPromises()
    expect(vi.mocked(campaignsApi.list).mock.calls.at(-1)?.[1]).toMatchObject({ status: 'draft' })
  })
})
