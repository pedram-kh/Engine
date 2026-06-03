/**
 * Sprint 6.6a — Vitest coverage for the public creator profile page.
 *
 * Covers: agency-scoped load, the read-only render (NO send-request action,
 * D-9), the "View in roster" link shown ONLY when already connected (D-9), the
 * not-connected state, and the 404 → not-found message (the public detail
 * 404s only for a non-discoverable creator, never for no-relation — D-6).
 *
 * The @catalyst/ui leaf components are stubbed to keep the mount lean; the
 * page chrome (header, connection chip, view-in-roster) renders for real.
 */

import type { CreatorPublicProfile } from '@catalyst/api-client'
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

import DiscoverProfilePage from './DiscoverProfilePage.vue'

vi.mock('../api/discovery.api', () => ({
  discoveryApi: { show: vi.fn() },
}))

import { discoveryApi } from '../api/discovery.api'

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

function makeProfile(
  overrides: Partial<CreatorPublicProfile['attributes']> = {},
): CreatorPublicProfile {
  return {
    id: '01CREATORULIDXXXXXXXXXXXXXX',
    type: 'creator_public_profiles',
    attributes: {
      display_name: 'Ada Lovelace',
      bio: 'Pioneering mathematician',
      country_code: 'GB',
      region: 'London',
      primary_language: 'en',
      secondary_languages: [],
      categories: ['tech'],
      avatar_url: null,
      cover_url: null,
      profile_completeness_score: 90,
      social_accounts: [],
      portfolio: [],
      is_connected: false,
      relationship_status: null,
      ...overrides,
    },
  }
}

async function mountProfile(
  options: { profile?: CreatorPublicProfile; reject?: ApiError | Error } = {},
): Promise<{
  wrapper: ReturnType<typeof mount>
  router: ReturnType<typeof createRouter>
  cleanup: () => void
}> {
  const pinia = createPinia()
  setActivePinia(pinia)

  if (options.reject !== undefined) {
    vi.mocked(discoveryApi.show).mockRejectedValue(options.reject)
  } else {
    vi.mocked(discoveryApi.show).mockResolvedValue({ data: options.profile ?? makeProfile() })
  }

  const agency = useAgencyStore()
  agency.initFromUser([
    { agency_id: 'agency-ulid', agency_name: 'Test Agency', role: 'agency_admin' },
  ])

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/discover', name: 'discover.list', component: { template: '<div />' } },
      { path: '/discover/:ulid', name: 'discover.detail', component: { template: '<div />' } },
      { path: '/roster/:ulid', name: 'roster.detail', component: { template: '<div />' } },
    ],
  })
  await router.push('/discover/01CREATORULIDXXXXXXXXXXXXXX')
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: { ...enApp, ...enCreator } } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  const wrapper = mount(DiscoverProfilePage, {
    global: {
      plugins: [pinia, router, i18n, vuetify],
      stubs: {
        CategoryChips: true,
        CountryDisplay: true,
        LanguageList: true,
        SocialAccountList: true,
        PortfolioGallery: true,
        CEmptyState: true,
      },
    },
    attachTo: document.createElement('div'),
  })

  await flushPromises()

  return {
    wrapper,
    router,
    cleanup: () => {
      wrapper.unmount()
      Object.keys(localStorageStore).forEach((k) => delete localStorageStore[k])
    },
  }
}

describe('DiscoverProfilePage (Sprint 6.6a)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  it('loads the public profile scoped to the agency + creator ULID and renders read-only', async () => {
    const harness = await mountProfile({ profile: makeProfile({ display_name: 'Ada Lovelace' }) })
    cleanup = harness.cleanup

    expect(vi.mocked(discoveryApi.show).mock.calls[0]).toEqual([
      'agency-ulid',
      '01CREATORULIDXXXXXXXXXXXXXX',
    ])
    expect(harness.wrapper.find('[data-test="discover-profile-name"]').text()).toContain(
      'Ada Lovelace',
    )
    expect(harness.wrapper.find('[data-test="discover-profile-bio"]').text()).toContain(
      'Pioneering mathematician',
    )

    // Read-only (D-9): there is NO send-request action anywhere on the page,
    // and no rating/notes editor. The connect lifecycle is Sprint 6.6b.
    expect(harness.wrapper.find('[data-test="discover-profile-send-request"]').exists()).toBe(false)
    expect(harness.wrapper.find('[data-test="creator-detail-save"]').exists()).toBe(false)
    expect(harness.wrapper.find('[data-test="creator-detail-rating"]').exists()).toBe(false)
  })

  it('shows the View-in-roster link ONLY when already connected, and navigates to the 2a detail', async () => {
    const harness = await mountProfile({
      profile: makeProfile({ is_connected: true, relationship_status: 'roster' }),
    })
    cleanup = harness.cleanup
    const pushSpy = vi.spyOn(harness.router, 'push')

    expect(harness.wrapper.find('[data-test="discover-profile-connected"]').text()).toContain(
      'Roster',
    )

    const link = harness.wrapper.find('[data-test="discover-profile-view-in-roster"]')
    expect(link.exists()).toBe(true)
    await link.trigger('click')
    await flushPromises()
    expect(pushSpy).toHaveBeenCalledWith({
      name: 'roster.detail',
      params: { ulid: '01CREATORULIDXXXXXXXXXXXXXX' },
    })
  })

  it('shows the not-connected state and NO view-in-roster link for an unrelated creator', async () => {
    const harness = await mountProfile({ profile: makeProfile({ is_connected: false }) })
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="discover-profile-notconnected"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="discover-profile-view-in-roster"]').exists()).toBe(
      false,
    )
  })

  it('shows the not-found message on a 404 (non-discoverable creator)', async () => {
    const harness = await mountProfile({
      reject: new ApiError({ status: 404, code: 'http.not_found', message: 'not found' }),
    })
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="discover-profile-error"]').text()).toContain(
      "isn't available",
    )
  })
})
