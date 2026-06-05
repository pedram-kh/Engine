/**
 * Sprint 8 Chunk 1 — Vitest coverage for the campaign detail page (the app's
 * first tabbed surface). Pins: the tab set renders, the Settings tab is
 * role-gated (admin/manager only), the Board/Drafts/Payments/Messages tabs are
 * empty-state "coming soon" (nothing half-built), and the Creators tab shows
 * its empty state when there are no assignments.
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

import CampaignDetailPage from './CampaignDetailPage.vue'

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: { show: vi.fn(), assignments: vi.fn(), update: vi.fn() },
}))

import { campaignsApi } from '../api/campaigns.api'

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

const CAMPAIGN_ULID = '01HZA1B2C3D4E5F6G7H8J9K0M1'

function makeCampaign(): CampaignResource {
  return {
    id: CAMPAIGN_ULID,
    type: 'campaigns',
    attributes: {
      name: 'Summer launch',
      description: 'A push.',
      objective: 'awareness',
      status: 'draft',
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
    },
    relationships: {
      brand: { data: { id: 'brand-ulid', type: 'brands', name: 'Acme' } },
      agency: { data: { id: 'agency-ulid', type: 'agencies' } },
    },
  }
}

async function mountDetail(
  role: 'agency_admin' | 'agency_manager' | 'agency_staff' = 'agency_admin',
): Promise<{ wrapper: ReturnType<typeof mount>; cleanup: () => void }> {
  const pinia = createPinia()
  setActivePinia(pinia)

  vi.mocked(campaignsApi.show).mockResolvedValue({ data: makeCampaign() })
  vi.mocked(campaignsApi.assignments).mockResolvedValue({
    data: [],
    meta: { total: 0, page: 1, per_page: 25, last_page: 1 },
  })

  const agency = useAgencyStore()
  agency.initFromUser([{ agency_id: 'agency-ulid', agency_name: 'Test Agency', role }])

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/campaigns', name: 'campaigns.list', component: { template: '<div />' } },
      { path: '/campaigns/:ulid', name: 'campaigns.detail', component: { template: '<div />' } },
    ],
  })
  await router.push(`/campaigns/${CAMPAIGN_ULID}`)
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

  const wrapper = mount(CampaignDetailPage, {
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

describe('CampaignDetailPage (Sprint 8 Chunk 1)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  it('renders the tab bar with the live + coming-soon tabs', async () => {
    const harness = await mountDetail()
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="tab-overview"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="tab-creators"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="tab-board"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="tab-payments"]').exists()).toBe(true)
  })

  it('shows the Settings tab for admin/manager', async () => {
    const harness = await mountDetail('agency_manager')
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="tab-settings"]').exists()).toBe(true)
  })

  it('hides the Settings tab for staff', async () => {
    const harness = await mountDetail('agency_staff')
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="tab-settings"]').exists()).toBe(false)
  })

  it('renders an empty-state "coming soon" for the Board tab (nothing half-built)', async () => {
    const harness = await mountDetail()
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { tab: string }).tab = 'board'
    await flushPromises()
    expect(harness.wrapper.find('[data-test="board-coming-soon"]').exists()).toBe(true)
  })

  it('shows the Creators empty state and loads assignments when the tab opens', async () => {
    const harness = await mountDetail()
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { tab: string }).tab = 'creators'
    await flushPromises()
    expect(campaignsApi.assignments).toHaveBeenCalledWith('agency-ulid', CAMPAIGN_ULID)
    expect(harness.wrapper.find('[data-test="creators-empty-state"]').exists()).toBe(true)
  })
})
