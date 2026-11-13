/**
 * AH-122 — the country-code pickers select by ISO country code, not by dial code.
 *
 * Found in the build 5 device walk (step 12): both pickers bound `item-value="dialCode"`, and about
 * 25 registry entries share "+1" (others share +44, +358, +7), so one value matched many rows and
 * the open list showed several countries selected. The saved string stays "<dialCode> <local>".
 *
 * Break-revert, one per test (each reported when this spec was added):
 *   - bind `item-value="dialCode"` again → the "only Canada / only Bahamas selected" tests fail;
 *   - make `isoForDialCode` ignore the creator's country → the CA test fails;
 *   - make it ignore the registry order → the ES test fails.
 */

import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { VAutocomplete } from 'vuetify/components'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    updateProfile: vi.fn(),
    uploadAvatar: vi.fn(),
    deleteAvatar: vi.fn(),
  },
}))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import Step2ProfileBasicsPage from '../pages/Step2ProfileBasicsPage.vue'

let teardown: (() => void) | null = null

const baseAttributes = {
  display_name: 'Existing Name',
  bio: null,
  country_code: 'IT',
  // D1: region is part of the seven-field floor. The base fixture fills it so
  // the "happy" gate paths satisfy the full floor; the avatar-missing tests
  // still isolate on the single missing avatar, and the region-gates test
  // overrides it back to null with the avatar present.
  region: 'Lazio',
  // AH-088 D6: date_of_birth joined the same floor, so the base fixture fills it
  // for the same reason region does — otherwise every "gate opens" test would be
  // asserting against two missing fields instead of the one it isolates.
  date_of_birth: '1994-03-17',
  age: 32,
  primary_language: 'en',
  secondary_languages: [] as string[],
  // AH-050 — null means "undisclosed" (hydrates to an empty chip group).
  content_companions: null as string[] | null,
  categories: ['lifestyle'] as string[],
  avatar_path: null,
  cover_path: null,
  avatar_url: null,
  cover_url: null,
  verification_level: 'unverified' as const,
  application_status: 'incomplete' as const,
  tier: null,
  kyc_status: 'none' as const,
  kyc_verified_at: null,
  tax_profile_complete: false,
  payout_method_set: false,
  has_signed_master_contract: false,
  click_through_accepted_at: null,
  social_accounts: [],
  portfolio: [],
  profile_completeness_score: 35,
  submitted_at: null,
  approved_at: null,
  created_at: '2026-05-14T00:00:00+00:00',
  updated_at: '2026-05-14T00:00:00+00:00',
}

/**
 * Resolve the profile PATCH with a well-formed bootstrap payload — the store
 * re-hydrates from the response, so a bare `mockResolvedValue({})` would blow up
 * inside the store rather than in the assertion. The `mockBootstrap` factory
 * above is the same idea for the initial GET.
 */
function mockUpdateProfile(attrOverrides: Record<string, unknown> = {}): void {
  vi.mocked(onboardingApi.updateProfile).mockResolvedValue({
    data: {
      id: '01',
      type: 'creators',
      attributes: { ...baseAttributes, ...attrOverrides },
      wizard: {
        next_step: 'profile',
        is_submitted: false,
        steps: [],
        weights: {},
        flags: {
          kyc_verification_enabled: false,
          creator_payout_method_enabled: false,
          contract_signing_enabled: false,
        },
      },
    } as never,
  })
}

function mockBootstrap(attrOverrides: Record<string, unknown> = {}): void {
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue({
    data: {
      id: '01',
      type: 'creators',
      attributes: { ...baseAttributes, ...attrOverrides },
      wizard: {
        next_step: 'profile',
        is_submitted: false,
        steps: [],
        weights: {},
        flags: {
          kyc_verification_enabled: false,
          creator_payout_method_enabled: false,
          contract_signing_enabled: false,
        },
      },
    } as never,
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  mockBootstrap()
  mockUpdateProfile()
})

afterEach(() => {
  teardown?.()
  teardown = null
  document.body.innerHTML = ''
})

async function mountForm(attrs: Record<string, unknown> = {}) {
  mockBootstrap({ avatar_path: 'creators/seed/avatar/x.jpg', ...attrs })
  const { wrapper, unmount } = await mountAuthPage(Step2ProfileBasicsPage, {
    initialRoute: { path: '/onboarding/profile' },
    beforeMount: async () => {
      await useOnboardingStore().bootstrap()
    },
  })
  teardown = unmount
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountForm>>

function dial(wrapper: Wrapper, testId: 'profile-phone-dial' | 'profile-whatsapp-dial') {
  const found = wrapper
    .findAllComponents(VAutocomplete)
    .find((c) => c.attributes('data-testid') === testId)
  if (found === undefined) throw new Error(`no picker ${testId}`)
  return found
}

function closedText(wrapper: Wrapper, testId: 'profile-phone-dial' | 'profile-whatsapp-dial') {
  return wrapper
    .findAll(`[data-testid="${testId}"] .profile-basics-form__dial-selection`)
    .map((n) => n.text().replace(/\u00a0/g, ' '))
}

/** Open the list and return the titles of the rows Vuetify marks selected. */
async function selectedRows(
  wrapper: Wrapper,
  testId: 'profile-phone-dial' | 'profile-whatsapp-dial',
) {
  await wrapper.find(`[data-testid="${testId}"] .v-field`).trigger('mousedown')
  await wrapper.find(`[data-testid="${testId}"] input`).trigger('focus')
  await flushPromises()
  return [...document.body.querySelectorAll('.v-overlay-container .v-list-item--active')].map((n) =>
    (n.textContent ?? '').trim(),
  )
}

describe('AH-122 country-code picker', () => {
  it.each(['profile-phone-dial', 'profile-whatsapp-dial'] as const)(
    '%s: selecting Canada shows one Canada flag and "+1", only Canada selected, saves "+1 <local>"',
    async (testId) => {
      const wrapper = await mountForm({ phone: null, whatsapp: null })
      dial(wrapper, testId).vm.$emit('update:modelValue', 'CA')
      await flushPromises()

      expect(closedText(wrapper, testId)).toEqual(['\u{1F1E8}\u{1F1E6} +1'])
      const rows = await selectedRows(wrapper, testId)
      expect(rows).toHaveLength(1)
      expect(rows[0]).toContain('Canada')

      const localId = testId === 'profile-phone-dial' ? 'profile-phone' : 'profile-whatsapp'
      await wrapper.find(`[data-testid="${localId}"] input`).setValue('555 0100')
      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()
      const key = testId === 'profile-phone-dial' ? 'phone' : 'whatsapp'
      expect(onboardingApi.updateProfile).toHaveBeenCalledWith(
        expect.objectContaining({ [key]: '+1 555 0100' }),
      )
    },
  )

  it.each(['profile-phone-dial', 'profile-whatsapp-dial'] as const)(
    '%s: selecting Bahamas shows one Bahamas flag and "+1", only Bahamas selected',
    async (testId) => {
      const wrapper = await mountForm({ phone: null, whatsapp: null })
      dial(wrapper, testId).vm.$emit('update:modelValue', 'BS')
      await flushPromises()

      expect(closedText(wrapper, testId)).toEqual(['\u{1F1E7}\u{1F1F8} +1'])
      const rows = await selectedRows(wrapper, testId)
      expect(rows).toHaveLength(1)
      expect(rows[0]).toContain('Bahamas')
    },
  )

  it('loads "+1 555 0100" with country_code CA as Canada', async () => {
    const wrapper = await mountForm({ country_code: 'CA', phone: '+1 555 0100', whatsapp: null })
    expect(closedText(wrapper, 'profile-phone-dial')).toEqual(['\u{1F1E8}\u{1F1E6} +1'])
    expect(dial(wrapper, 'profile-phone-dial').props('modelValue')).toBe('CA')
  })

  it('loads "+1 555 0100" with country_code ES as the first "+1" entry (US)', async () => {
    const wrapper = await mountForm({ country_code: 'ES', phone: '+1 555 0100', whatsapp: null })
    expect(dial(wrapper, 'profile-phone-dial').props('modelValue')).toBe('US')
    expect(closedText(wrapper, 'profile-phone-dial')).toEqual(['\u{1F1FA}\u{1F1F8} +1'])
  })
})
