/**
 * Sprint 6 Chunk 2a — Vitest coverage for the agency creator detail page.
 *
 * Per the Chunk-1 jsdom/Playwright split: the LOGIC / EMPTY-STATE / POLICY
 * surface is unit-tested here with the heavy children stubbed (the
 * availability calendar + CMonthGrid, PortfolioGallery, SocialAccountList,
 * etc.); the full detail-page DOM + the live calendar are covered by the
 * Playwright spec.
 *
 * Covers: load scoped to the current agency + route ulid, the email surface
 * (D-2a-8), the admin/manager EDITOR vs the staff READ-ONLY view (D-2a-4), the
 * save round-trip threading rating+notes, the two blocked empty states
 * (D-2a-10), and the 404 not-in-roster error path.
 */

import type { AgencyCreatorDetailResource } from '@catalyst/api-client'
import { ApiError } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'
import enCreator from '@/core/i18n/locales/en/creator.json'
import { useAgencyStore } from '@/core/stores/useAgencyStore'

import CreatorDetailPage from './CreatorDetailPage.vue'

vi.mock('../api/roster.api', () => ({
  rosterApi: {
    show: vi.fn(),
    updateRelation: vi.fn(),
    blacklist: vi.fn(),
    unblacklist: vi.fn(),
  },
}))

vi.mock('@/modules/brands/api/brands.api', () => ({
  brandsApi: {
    list: vi.fn().mockResolvedValue({ data: [] }),
  },
}))

import { rosterApi } from '../api/roster.api'

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

const CREATOR_ULID = '01CREATORULIDXXXXXXXXXXXXXX'

function makeDetail(
  overrides: Partial<AgencyCreatorDetailResource['attributes']> = {},
  creatorOverrides: Record<string, unknown> = {},
): AgencyCreatorDetailResource {
  return {
    id: '01RELATIONULIDXXXXXXXXXXXXX',
    type: 'agency_creator_details',
    attributes: {
      relationship_status: 'roster',
      internal_rating: 4,
      internal_notes: 'Reliable',
      total_campaigns_completed: 3,
      total_paid_minor_units: 0,
      last_engaged_at: null,
      is_blacklisted: false,
      blacklist_scope: null,
      blacklist_type: null,
      blacklisted_at: null,
      creator: {
        id: CREATOR_ULID,
        display_name: 'Ada Lovelace',
        bio: 'Pioneering mathematician',
        email: 'ada@example.com',
        country_code: 'GB',
        region: null,
        primary_language: 'en',
        secondary_languages: null,
        categories: ['tech'],
        avatar_url: null,
        cover_url: null,
        application_status: 'approved',
        social_accounts: [],
        portfolio: [],
        ...creatorOverrides,
      },
      ...overrides,
    },
  }
}

async function mountDetail(
  options: {
    role?: 'agency_admin' | 'agency_manager' | 'agency_staff'
    detail?: AgencyCreatorDetailResource
    showError?: ApiError
  } = {},
): Promise<{ wrapper: ReturnType<typeof mount>; cleanup: () => void }> {
  const pinia = createPinia()
  setActivePinia(pinia)

  if (options.showError !== undefined) {
    vi.mocked(rosterApi.show).mockRejectedValue(options.showError)
  } else {
    vi.mocked(rosterApi.show).mockResolvedValue({ data: options.detail ?? makeDetail() })
  }

  const agency = useAgencyStore()
  agency.initFromUser([
    {
      agency_id: 'agency-ulid',
      agency_name: 'Test Agency',
      role: options.role ?? 'agency_admin',
    },
  ])

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/roster', name: 'roster.list', component: { template: '<div />' } },
      { path: '/roster/:ulid', name: 'roster.detail', component: { template: '<div />' } },
    ],
  })
  await router.push(`/roster/${CREATOR_ULID}`)
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: { ...enApp, ...enCreator } } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })

  // Stub the heavy children — the calendar (CMonthGrid + API), the gallery,
  // the social list, and the display primitives. CEmptyState + StarRatingInput
  // stay REAL (light) so the blocked-section + policy assertions are genuine.
  const wrapper = mount(CreatorDetailPage, {
    global: {
      plugins: [pinia, router, i18n, vuetify],
      stubs: {
        AgencyAvailabilityCalendar: true,
        PortfolioGallery: true,
        SocialAccountList: true,
        CountryDisplay: true,
        LanguageList: true,
        CategoryChips: true,
      },
    },
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

describe('CreatorDetailPage (Sprint 6 Chunk 2a)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  it('loads scoped to the current agency + route ulid and surfaces the contact email (D-2a-8)', async () => {
    const harness = await mountDetail()
    cleanup = harness.cleanup

    expect(vi.mocked(rosterApi.show)).toHaveBeenCalledWith('agency-ulid', CREATOR_ULID)
    expect(harness.wrapper.find('[data-test="creator-detail-name"]').text()).toBe('Ada Lovelace')

    const emailLink = harness.wrapper.find('[data-test="creator-detail-email"]')
    expect(emailLink.exists()).toBe(true)
    expect(emailLink.attributes('href')).toBe('mailto:ada@example.com')
  })

  it('renders honest empty states for the two blocked sections (D-2a-10)', async () => {
    const harness = await mountDetail()
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="creator-detail-metrics-empty"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="creator-detail-campaigns-empty"]').exists()).toBe(true)
  })

  it('shows the editor (textarea + save) for an admin', async () => {
    const harness = await mountDetail({ role: 'agency_admin' })
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="creator-detail-notes"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="creator-detail-save"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="creator-detail-notes-readonly"]').exists()).toBe(false)
  })

  it('shows the editor for a manager', async () => {
    const harness = await mountDetail({ role: 'agency_manager' })
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="creator-detail-save"]').exists()).toBe(true)
  })

  it('renders rating + notes READ-ONLY for staff (no editor — D-2a-4)', async () => {
    const harness = await mountDetail({ role: 'agency_staff' })
    cleanup = harness.cleanup

    // No textarea, no save button.
    expect(harness.wrapper.find('[data-test="creator-detail-notes"]').exists()).toBe(false)
    expect(harness.wrapper.find('[data-test="creator-detail-save"]').exists()).toBe(false)
    // The read-only notes display is present instead.
    expect(harness.wrapper.find('[data-test="creator-detail-notes-readonly"]').text()).toContain(
      'Reliable',
    )
    // The star control is read-only (no radio buttons).
    const stars = harness.wrapper.find('[data-test="creator-detail-rating"]')
    expect(stars.findAll('button')).toHaveLength(0)
  })

  it('threads rating + notes through the PATCH on save', async () => {
    const harness = await mountDetail({ role: 'agency_admin' })
    cleanup = harness.cleanup

    vi.mocked(rosterApi.updateRelation).mockResolvedValue({
      data: makeDetail({ internal_rating: 5, internal_notes: 'Updated note' }),
    })

    // Drive the drafts directly (the textarea is real but we set the vm refs).
    const vm = harness.wrapper.vm as unknown as {
      ratingDraft: number | null
      notesDraft: string
    }
    vm.ratingDraft = 5
    vm.notesDraft = 'Updated note'
    await harness.wrapper.vm.$nextTick()

    await harness.wrapper.find('[data-test="creator-detail-save"]').trigger('click')
    await flushPromises()

    expect(vi.mocked(rosterApi.updateRelation)).toHaveBeenCalledWith('agency-ulid', CREATOR_ULID, {
      internal_rating: 5,
      internal_notes: 'Updated note',
    })
    // The success snackbar fired (it teleports to <body>, so assert the state).
    expect((harness.wrapper.vm as unknown as { savedSnackbar: boolean }).savedSnackbar).toBe(true)
  })

  it('sends null notes when the textarea is cleared', async () => {
    const harness = await mountDetail({ role: 'agency_admin' })
    cleanup = harness.cleanup

    vi.mocked(rosterApi.updateRelation).mockResolvedValue({ data: makeDetail() })

    const vm = harness.wrapper.vm as unknown as { notesDraft: string }
    vm.notesDraft = ''
    await harness.wrapper.vm.$nextTick()

    await harness.wrapper.find('[data-test="creator-detail-save"]').trigger('click')
    await flushPromises()

    expect(vi.mocked(rosterApi.updateRelation).mock.calls.at(-1)?.[2]).toMatchObject({
      internal_notes: null,
    })
  })

  it('shows the blacklist section + open action for an admin on a non-blacklisted creator', async () => {
    const harness = await mountDetail({ role: 'agency_admin' })
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="creator-detail-blacklist-section"]').exists()).toBe(
      true,
    )
    expect(harness.wrapper.find('[data-test="creator-detail-blacklist-open"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="creator-detail-unblacklist"]').exists()).toBe(false)
  })

  it('hides the blacklist management section from staff (admin/manager gate)', async () => {
    const harness = await mountDetail({ role: 'agency_staff' })
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="creator-detail-blacklist-section"]').exists()).toBe(
      false,
    )
  })

  it('shows the un-blacklist action + status when the creator is blacklisted', async () => {
    const harness = await mountDetail({
      role: 'agency_admin',
      detail: makeDetail({
        is_blacklisted: true,
        blacklist_scope: 'agency',
        blacklist_type: 'hard',
        blacklisted_at: '2026-06-01T00:00:00+00:00',
      }),
    })
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="creator-detail-unblacklist"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="creator-detail-blacklist-open"]').exists()).toBe(false)
    expect(harness.wrapper.find('[data-test="creator-detail-blacklist-status"]').text()).not.toBe(
      '',
    )
  })

  it('lifts an agency-wide blacklist via the un-blacklist action', async () => {
    const harness = await mountDetail({
      role: 'agency_admin',
      detail: makeDetail({
        is_blacklisted: true,
        blacklist_scope: 'agency',
        blacklist_type: 'hard',
      }),
    })
    cleanup = harness.cleanup

    vi.mocked(rosterApi.unblacklist).mockResolvedValue({
      data: { type: 'creator_blacklist', attributes: { is_blacklisted: false } },
      meta: { code: 'creator.unblacklisted' },
    })

    await harness.wrapper.find('[data-test="creator-detail-unblacklist"]').trigger('click')
    await flushPromises()

    expect(vi.mocked(rosterApi.unblacklist)).toHaveBeenCalledWith('agency-ulid', CREATOR_ULID, {
      scope: 'agency',
    })
  })

  it('renders a warning (not error) badge for a SOFT blacklist', async () => {
    const harness = await mountDetail({
      role: 'agency_admin',
      detail: makeDetail({
        is_blacklisted: true,
        blacklist_scope: 'agency',
        blacklist_type: 'soft',
      }),
    })
    cleanup = harness.cleanup

    const badge = harness.wrapper.find('[data-test="creator-detail-blacklist"]')
    expect(badge.exists()).toBe(true)
    expect(badge.text()).toBe('Blacklist warning')
  })

  it('shows a not-in-roster message on a 404', async () => {
    const harness = await mountDetail({
      showError: new ApiError({ status: 404, code: 'not_found', message: 'Not found' }),
    })
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="creator-detail-error"]').text()).toContain(
      "isn't in your roster",
    )
  })
})
