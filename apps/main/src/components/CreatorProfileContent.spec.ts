/**
 * Vitest coverage for `CreatorProfileContent` (AH-080) — the body
 * `CreatorProfileDialog` and the board drawer's Profile tab both mount.
 *
 * Covers: FULL mode (rating/notes editor + blacklist mgmt, the D4 reuse —
 * same components/imports `CreatorDetailPage.vue` uses), THIN mode + the
 * §5.34 zero-contact assertion (cites `CreatorPublicProfileResource`'s
 * server-side withholding, pinned by
 * `AgencyCreatorDiscoveryTest.php:249`), the honest thinness notice, the
 * fallback sequence (roster 404 → discover, never a second roster call),
 * and `assumeFull` skipping the fallback for the application mount context.
 */

import type {
  AgencyCreatorDetailEnvelope,
  CreatorPublicProfileEnvelope,
} from '@catalyst/api-client'
import { ApiError } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import enCreator from '@/core/i18n/locales/en/creator.json'
import { useAgencyStore } from '@/core/stores/useAgencyStore'

vi.mock('@/modules/roster/api/roster.api', () => ({
  rosterApi: {
    show: vi.fn(),
    updateRelation: vi.fn(),
    blacklist: vi.fn(),
    unblacklist: vi.fn(),
  },
}))
vi.mock('@/modules/discover/api/discovery.api', () => ({
  discoveryApi: { show: vi.fn() },
}))
vi.mock('@/modules/brands/api/brands.api', () => ({
  brandsApi: { list: vi.fn().mockResolvedValue({ data: [] }) },
}))

import { discoveryApi } from '@/modules/discover/api/discovery.api'
import { rosterApi } from '@/modules/roster/api/roster.api'

import CreatorProfileContent from './CreatorProfileContent.vue'

const AGENCY_ID = 'agency-ulid'
const CREATOR_ULID = '01CREATORULIDXXXXXXXXXXXXXX'

function fullEnvelope(
  overrides: Partial<AgencyCreatorDetailEnvelope['data']['attributes']> = {},
  creatorOverrides: Record<string, unknown> = {},
): AgencyCreatorDetailEnvelope {
  return {
    data: {
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
          account_name: 'Ada',
          account_last_name: 'Lovelace',
          country_code: 'GB',
          region: null,
          primary_language: 'en',
          secondary_languages: null,
          accent: null,
          content_companions: null,
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
    },
  }
}

function thinEnvelope(
  overrides: Partial<CreatorPublicProfileEnvelope['data']['attributes']> = {},
): CreatorPublicProfileEnvelope {
  return {
    data: {
      id: CREATOR_ULID,
      type: 'creator_public_profiles',
      attributes: {
        display_name: 'Ada Lovelace',
        bio: 'Pioneering mathematician',
        country_code: 'GB',
        region: null,
        primary_language: 'en',
        secondary_languages: null,
        accent: null,
        content_companions: null,
        categories: ['tech'],
        avatar_url: null,
        cover_url: null,
        profile_completeness_score: 60,
        social_accounts: [],
        portfolio: [],
        relationship_status: null,
        ...overrides,
      },
    },
  }
}

async function mountContent(
  options: {
    role?: 'agency_admin' | 'agency_manager' | 'agency_staff'
    assumeFull?: boolean
    creatorUlid?: string
  } = {},
): Promise<{ wrapper: ReturnType<typeof mount>; cleanup: () => void }> {
  const pinia = createPinia()
  setActivePinia(pinia)

  const agency = useAgencyStore()
  agency.initFromUser([
    { agency_id: AGENCY_ID, agency_name: 'Test Agency', role: options.role ?? 'agency_admin' },
  ])

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: { ...enApp, ...enCreator } } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  const wrapper = mount(CreatorProfileContent, {
    props: {
      agencyId: AGENCY_ID,
      creatorUlid: options.creatorUlid ?? CREATOR_ULID,
      assumeFull: options.assumeFull ?? false,
    },
    global: {
      plugins: [pinia, i18n, vuetify],
      stubs: {
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
    cleanup: () => wrapper.unmount(),
  }
}

describe('CreatorProfileContent (AH-080)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  describe('FULL mode (a relation exists)', () => {
    beforeEach(() => {
      vi.mocked(rosterApi.show).mockResolvedValue(fullEnvelope())
    })

    it('resolves via roster only, renders the profile + name, and never calls discover', async () => {
      const harness = await mountContent()
      cleanup = harness.cleanup

      expect(rosterApi.show).toHaveBeenCalledWith(AGENCY_ID, CREATOR_ULID)
      expect(discoveryApi.show).not.toHaveBeenCalled()
      expect(harness.wrapper.find('[data-test="creator-profile-content-name"]').text()).toBe(
        'Ada Lovelace',
      )
      expect(
        harness.wrapper.find('[data-test="creator-profile-content-thin-notice"]').exists(),
      ).toBe(false)
    })

    it('renders the contact block when the server shipped it', async () => {
      vi.mocked(rosterApi.show).mockResolvedValue(
        fullEnvelope(
          {},
          { phone: '+1 555 0100', whatsapp: '+1 555 0142', address_street: '12 Market Street' },
        ),
      )
      const harness = await mountContent()
      cleanup = harness.cleanup

      expect(harness.wrapper.find('[data-test="creator-profile-content-contact"]').exists()).toBe(
        true,
      )
      expect(harness.wrapper.find('[data-test="creator-profile-content-phone"]').text()).toBe(
        '+1 555 0100',
      )
    })

    it('hides the contact block on a FULL payload with no contact keys (withheld, not thin)', async () => {
      const harness = await mountContent()
      cleanup = harness.cleanup

      expect(harness.wrapper.find('[data-test="creator-profile-content-contact"]').exists()).toBe(
        false,
      )
      // Distinguish from thin — this is still full mode, just withheld.
      expect(
        harness.wrapper.find('[data-test="creator-profile-content-thin-notice"]').exists(),
      ).toBe(false)
    })

    it('D4 — shows the rating/notes EDITOR for admin/manager, wired to rosterApi.updateRelation', async () => {
      const harness = await mountContent({ role: 'agency_admin' })
      cleanup = harness.cleanup

      expect(harness.wrapper.find('[data-test="creator-profile-content-notes"]').exists()).toBe(
        true,
      )
      expect(harness.wrapper.find('[data-test="creator-profile-content-save"]').exists()).toBe(true)

      vi.mocked(rosterApi.updateRelation).mockResolvedValue(
        fullEnvelope({ internal_rating: 5, internal_notes: 'Updated note' }),
      )
      const vm = harness.wrapper.vm as unknown as { ratingDraft: number | null; notesDraft: string }
      vm.ratingDraft = 5
      vm.notesDraft = 'Updated note'
      await harness.wrapper.vm.$nextTick()
      await harness.wrapper.find('[data-test="creator-profile-content-save"]').trigger('click')
      await flushPromises()

      expect(rosterApi.updateRelation).toHaveBeenCalledWith(AGENCY_ID, CREATOR_ULID, {
        internal_rating: 5,
        internal_notes: 'Updated note',
      })
    })

    it('D4 — renders rating/notes READ-ONLY for staff (no editor)', async () => {
      const harness = await mountContent({ role: 'agency_staff' })
      cleanup = harness.cleanup

      expect(harness.wrapper.find('[data-test="creator-profile-content-notes"]').exists()).toBe(
        false,
      )
      expect(harness.wrapper.find('[data-test="creator-profile-content-save"]').exists()).toBe(
        false,
      )
      expect(
        harness.wrapper.find('[data-test="creator-profile-content-notes-readonly"]').text(),
      ).toContain('Reliable')
    })

    it('D4 — shows blacklist management for admin/manager, wired to rosterApi.unblacklist', async () => {
      vi.mocked(rosterApi.show).mockResolvedValue(
        fullEnvelope({ is_blacklisted: true, blacklist_scope: 'agency', blacklist_type: 'hard' }),
      )
      const harness = await mountContent({ role: 'agency_admin' })
      cleanup = harness.cleanup

      expect(
        harness.wrapper.find('[data-test="creator-profile-content-blacklist-section"]').exists(),
      ).toBe(true)
      expect(
        harness.wrapper.find('[data-test="creator-profile-content-unblacklist"]').exists(),
      ).toBe(true)

      vi.mocked(rosterApi.unblacklist).mockResolvedValue({
        data: { type: 'creator_blacklist', attributes: { is_blacklisted: false } },
        meta: { code: 'creator.unblacklisted' },
      })
      await harness.wrapper
        .find('[data-test="creator-profile-content-unblacklist"]')
        .trigger('click')
      await flushPromises()

      expect(rosterApi.unblacklist).toHaveBeenCalledWith(AGENCY_ID, CREATOR_ULID, {
        scope: 'agency',
      })
    })

    it('hides blacklist management from staff', async () => {
      const harness = await mountContent({ role: 'agency_staff' })
      cleanup = harness.cleanup

      expect(
        harness.wrapper.find('[data-test="creator-profile-content-blacklist-section"]').exists(),
      ).toBe(false)
    })
  })

  describe('THIN mode (§5.34 — no relation, the truthful fallback)', () => {
    beforeEach(() => {
      vi.mocked(rosterApi.show).mockRejectedValue(
        new ApiError({ status: 404, code: 'not_found', message: 'Not found' }),
      )
      vi.mocked(discoveryApi.show).mockResolvedValue(thinEnvelope())
    })

    it('§5.34 — renders ZERO contact section, ZERO account section, ZERO rating/notes, ZERO blacklist on a thin payload', async () => {
      const harness = await mountContent({ role: 'agency_admin' })
      cleanup = harness.cleanup

      expect(rosterApi.show).toHaveBeenCalledTimes(1)
      expect(discoveryApi.show).toHaveBeenCalledTimes(1)

      // §5.34 — mirrors the server-side guarantee: CreatorPublicProfileResource
      // never emits contact/relation/blacklist keys, pinned by
      // AgencyCreatorDiscoveryTest.php:249 ("public detail WITHHOLDS email,
      // the relation block, blacklist, counters and admin KYC"). This asserts
      // the CLIENT renders zero DOM for all of them, even for an admin.
      expect(harness.wrapper.find('[data-test="creator-profile-content-contact"]').exists()).toBe(
        false,
      )
      expect(harness.wrapper.find('[data-test="creator-profile-content-account"]').exists()).toBe(
        false,
      )
      expect(
        harness.wrapper.find('[data-test="creator-profile-content-rating-notes"]').exists(),
      ).toBe(false)
      expect(
        harness.wrapper.find('[data-test="creator-profile-content-blacklist-section"]').exists(),
      ).toBe(false)
      expect(harness.wrapper.find('[data-test="creator-profile-content-phone"]').exists()).toBe(
        false,
      )
      expect(
        harness.wrapper.find('[data-test="creator-profile-content-account-email"]').exists(),
      ).toBe(false)
    })

    it('states its thinness honestly — a visible notice, not an empty contact skeleton', async () => {
      const harness = await mountContent()
      cleanup = harness.cleanup

      const notice = harness.wrapper.find('[data-test="creator-profile-content-thin-notice"]')
      expect(notice.exists()).toBe(true)
      expect(notice.text().length).toBeGreaterThan(0)
    })

    it('still renders the profile + socials — the two blocks both payloads carry', async () => {
      const harness = await mountContent()
      cleanup = harness.cleanup

      expect(harness.wrapper.find('[data-test="creator-profile-content-profile"]').exists()).toBe(
        true,
      )
      expect(harness.wrapper.find('[data-test="creator-profile-content-social"]').exists()).toBe(
        true,
      )
      expect(harness.wrapper.find('[data-test="creator-profile-content-name"]').text()).toBe(
        'Ada Lovelace',
      )
    })
  })

  describe('fallback mechanics (D3)', () => {
    it('a genuine 404 on BOTH endpoints surfaces the not-found error, never a phantom profile', async () => {
      vi.mocked(rosterApi.show).mockRejectedValue(
        new ApiError({ status: 404, code: 'not_found', message: 'Not found' }),
      )
      vi.mocked(discoveryApi.show).mockRejectedValue(
        new ApiError({ status: 404, code: 'not_found', message: 'Not found' }),
      )
      const harness = await mountContent()
      cleanup = harness.cleanup

      expect(harness.wrapper.find('[data-test="creator-profile-content-error"]').exists()).toBe(
        true,
      )
      expect(rosterApi.show).toHaveBeenCalledTimes(1)
      expect(discoveryApi.show).toHaveBeenCalledTimes(1)
    })

    it('assumeFull (applicant context, D2c) skips the fallback dance — one fetch, no wasted 404 call to discover', async () => {
      vi.mocked(rosterApi.show).mockRejectedValue(
        new ApiError({ status: 404, code: 'not_found', message: 'Not found' }),
      )
      const harness = await mountContent({ assumeFull: true })
      cleanup = harness.cleanup

      expect(rosterApi.show).toHaveBeenCalledTimes(1)
      expect(discoveryApi.show).not.toHaveBeenCalled()
      expect(harness.wrapper.find('[data-test="creator-profile-content-error"]').exists()).toBe(
        true,
      )
    })
  })
})
