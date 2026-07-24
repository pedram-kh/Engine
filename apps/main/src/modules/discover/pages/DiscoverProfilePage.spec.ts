/**
 * Sprint 6.6a + 6.6b — Vitest coverage for the public creator profile page.
 *
 * 6.6a: agency-scoped load, the public render, the 404 → not-found message.
 * 6.6b (D-10/D-11): the status-driven send-request affordance + the three
 * annotation states. The button presence is derived from the calling-agency-
 * only relationship_status, admin/manager-gated (the canEdit role pattern):
 *   - none      → "Send request" (W1)
 *   - pending   → "Request pending" (disabled)
 *   - connected → "View in roster" (keys on `roster`)
 *   - declined  → "Declined" + "Request again" (D-4)
 *   - ended     → "Previously connected" + "Request again" (AH-051 D-3)
 *
 * The @catalyst/ui leaf components are stubbed to keep the mount lean; the
 * page chrome (header, connection chip, action button) renders for real.
 */

import type { ConnectionRequestResponse, CreatorPublicProfile } from '@catalyst/api-client'
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
  discoveryApi: { show: vi.fn(), sendConnectionRequest: vi.fn() },
}))

import { discoveryApi } from '../api/discovery.api'

type AgencyRole = 'agency_admin' | 'agency_manager' | 'agency_staff'

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
      accent: null,
      content_companions: null,
      categories: ['tech'],
      avatar_url: null,
      cover_url: null,
      profile_completeness_score: 90,
      social_accounts: [],
      portfolio: [],
      relationship_status: null,
      ...overrides,
    },
  }
}

function sendResponse(
  status: ConnectionRequestResponse['data']['attributes']['relationship_status'],
  code: ConnectionRequestResponse['meta']['code'],
): ConnectionRequestResponse {
  return {
    data: {
      id: '01CREATORULIDXXXXXXXXXXXXXX',
      type: 'agency_connection_request',
      attributes: { relationship_status: status },
    },
    meta: { code },
  }
}

async function mountProfile(
  options: { profile?: CreatorPublicProfile; reject?: ApiError | Error; role?: AgencyRole } = {},
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
    { agency_id: 'agency-ulid', agency_name: 'Test Agency', role: options.role ?? 'agency_admin' },
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

describe('DiscoverProfilePage', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  it('loads the public profile scoped to the agency + creator ULID', async () => {
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
    // No relation-gated editor (rating/notes live on the 2a roster detail).
    expect(harness.wrapper.find('[data-test="creator-detail-save"]').exists()).toBe(false)
    expect(harness.wrapper.find('[data-test="creator-detail-rating"]').exists()).toBe(false)
  })

  it('surfaces the read-only profile completeness score to the agency', async () => {
    const harness = await mountProfile({
      profile: makeProfile({ profile_completeness_score: 90 }),
    })
    cleanup = harness.cleanup

    const bar = harness.wrapper.find('[data-test="discover-profile-completeness"]')
    expect(bar.exists()).toBe(true)
    // The agency-voice label ("Profile {percent}% complete") renders the score.
    expect(bar.text()).toContain('Profile 90% complete')
  })

  it('connected (roster) → "Connected" chip + the View-in-roster link, navigating to the 2a detail', async () => {
    const harness = await mountProfile({ profile: makeProfile({ relationship_status: 'roster' }) })
    cleanup = harness.cleanup
    const pushSpy = vi.spyOn(harness.router, 'push')

    expect(
      harness.wrapper.find('[data-test="discover-profile-connection-connected"]').text(),
    ).toContain('Connected')
    // The send-request button is NOT shown when already connected.
    expect(harness.wrapper.find('[data-test="discover-profile-send-request"]').exists()).toBe(false)

    const link = harness.wrapper.find('[data-test="discover-profile-view-in-roster"]')
    expect(link.exists()).toBe(true)
    await link.trigger('click')
    await flushPromises()
    expect(pushSpy).toHaveBeenCalledWith({
      name: 'roster.detail',
      params: { ulid: '01CREATORULIDXXXXXXXXXXXXXX' },
    })
  })

  it('not-connected (admin) → shows "Send request"; clicking it sends and flips to pending', async () => {
    vi.mocked(discoveryApi.sendConnectionRequest).mockResolvedValue(
      sendResponse('pending_request', 'connection.requested'),
    )
    const harness = await mountProfile({ profile: makeProfile({ relationship_status: null }) })
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="discover-profile-notconnected"]').exists()).toBe(true)
    const btn = harness.wrapper.find('[data-test="discover-profile-send-request"]')
    expect(btn.exists()).toBe(true)

    await btn.trigger('click')
    await flushPromises()

    expect(vi.mocked(discoveryApi.sendConnectionRequest).mock.calls[0]).toEqual([
      'agency-ulid',
      '01CREATORULIDXXXXXXXXXXXXXX',
    ])
    // The button re-derives to the disabled "Request pending" state.
    expect(harness.wrapper.find('[data-test="discover-profile-request-pending"]').exists()).toBe(
      true,
    )
    expect(harness.wrapper.find('[data-test="discover-profile-send-request"]').exists()).toBe(false)
  })

  it('pending → "Request pending" chip + disabled pending button, no send action', async () => {
    const harness = await mountProfile({
      profile: makeProfile({ relationship_status: 'pending_request' }),
    })
    cleanup = harness.cleanup

    expect(
      harness.wrapper.find('[data-test="discover-profile-connection-pending"]').text(),
    ).toContain('Request pending')
    expect(harness.wrapper.find('[data-test="discover-profile-request-pending"]').exists()).toBe(
      true,
    )
    expect(harness.wrapper.find('[data-test="discover-profile-send-request"]').exists()).toBe(false)
  })

  it('declined → "Declined" chip + an explicit "Request again" that re-requests (D-4)', async () => {
    vi.mocked(discoveryApi.sendConnectionRequest).mockResolvedValue(
      sendResponse('pending_request', 'connection.re_requested'),
    )
    const harness = await mountProfile({
      profile: makeProfile({ relationship_status: 'declined' }),
    })
    cleanup = harness.cleanup

    expect(
      harness.wrapper.find('[data-test="discover-profile-connection-declined"]').text(),
    ).toContain('Declined')

    const again = harness.wrapper.find('[data-test="discover-profile-request-again"]')
    expect(again.exists()).toBe(true)
    await again.trigger('click')
    await flushPromises()

    expect(discoveryApi.sendConnectionRequest).toHaveBeenCalledTimes(1)
    // Re-derives to pending after the re-request.
    expect(harness.wrapper.find('[data-test="discover-profile-request-pending"]').exists()).toBe(
      true,
    )
  })

  it('ended → truthful "Previously connected" chip + "Request again" (AH-051 D-3), NOT the not-connected empty state', async () => {
    vi.mocked(discoveryApi.sendConnectionRequest).mockResolvedValue(
      sendResponse('pending_request', 'connection.re_requested'),
    )
    const harness = await mountProfile({
      profile: makeProfile({ relationship_status: 'ended' }),
    })
    cleanup = harness.cleanup

    // The chip states a TRUTHFUL prior-relationship fact...
    expect(
      harness.wrapper.find('[data-test="discover-profile-connection-ended"]').text(),
    ).toContain('Previously connected')
    // ...and it is NOT the "never connected" empty state.
    expect(harness.wrapper.find('[data-test="discover-profile-notconnected"]').exists()).toBe(false)

    // Re-requestable like `declined` (its own data-test seam so the two arms
    // stay independently pinned).
    const again = harness.wrapper.find('[data-test="discover-profile-request-again-ended"]')
    expect(again.exists()).toBe(true)
    await again.trigger('click')
    await flushPromises()

    expect(discoveryApi.sendConnectionRequest).toHaveBeenCalledTimes(1)
    expect(harness.wrapper.find('[data-test="discover-profile-request-pending"]').exists()).toBe(
      true,
    )
  })

  it('staff sees NO send-request action on a not-connected profile (admin/manager-gated, D-10)', async () => {
    const harness = await mountProfile({
      profile: makeProfile({ relationship_status: null }),
      role: 'agency_staff',
    })
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="discover-profile-notconnected"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="discover-profile-send-request"]').exists()).toBe(false)
  })

  // AH-050 — the "Who appears in their content" row (display-only, D5).
  it('renders the companions row with localized chip labels when disclosed', async () => {
    const harness = await mountProfile({
      profile: makeProfile({ content_companions: ['partner', 'pets_dogs'] }),
    })
    cleanup = harness.cleanup

    const row = harness.wrapper.find('[data-testid="discover-profile-companions"]')
    expect(row.exists()).toBe(true)
    expect(row.text()).toContain('Who appears in their content')

    // CategoryChips is stubbed — assert the localized labels it receives.
    const chips = row.findComponent({ name: 'CategoryChips' })
    expect(chips.props('labels')).toEqual(['Partner', 'Pets — dogs'])
  })

  it('renders the companions row empty (undisclosed) for null — no phantom state', async () => {
    const harness = await mountProfile({
      profile: makeProfile({ content_companions: null }),
    })
    cleanup = harness.cleanup

    const row = harness.wrapper.find('[data-testid="discover-profile-companions"]')
    expect(row.exists()).toBe(true)
    const chips = row.findComponent({ name: 'CategoryChips' })
    expect(chips.props('labels')).toEqual([])
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
