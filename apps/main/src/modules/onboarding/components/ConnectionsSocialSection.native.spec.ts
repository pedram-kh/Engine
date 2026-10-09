/**
 * AH-100d — the social accounts as the creator app draws them (Figma frames
 * 04/05/13): one card per platform with a brand tile, the @handle, an "Added"
 * pill and inline Edit / Remove — or "Not connected" and an "@ username" entry
 * with Add. Web polarity beside it: the web keeps its own layout, none of the
 * native markup.
 */

import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

const platform = vi.hoisted(() => ({ native: false }))
vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
}))

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: { bootstrap: vi.fn(), connectSocial: vi.fn(), disconnectSocial: vi.fn() },
}))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import ConnectionsSocialSection from './ConnectionsSocialSection.vue'

let teardown: (() => void) | null = null

function creator(handles: Record<string, string> = {}): never {
  return {
    data: {
      id: '01',
      type: 'creators',
      attributes: {
        display_name: 'Sofia',
        application_status: 'incomplete',
        social_accounts: Object.entries(handles).map(([p, handle]) => ({
          platform: p,
          handle,
          profile_url: `https://${p}.com/${handle}`,
          is_primary: false,
        })),
        portfolio: [],
      },
      wizard: { next_step: 'social', is_submitted: false, steps: [], weights: {}, flags: {} },
    },
  } as never
}

async function mountIt(native: boolean, handles: Record<string, string> = {}) {
  platform.native = native
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue(creator(handles))
  const mounted = await mountAuthPage(ConnectionsSocialSection, {
    initialRoute: { path: '/' },
    beforeMount: async () => {
      await useOnboardingStore().bootstrap()
    },
  })
  teardown = mounted.unmount
  await flushPromises()
  return mounted.wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
})

afterEach(() => {
  teardown?.()
  teardown = null
  platform.native = false
})

describe('social accounts — native, connected platform (frame 04)', () => {
  it('draws the brand tile, name, @handle and the "Added" pill', async () => {
    const wrapper = await mountIt(true, { instagram: 'sofia.marin' })
    const card = wrapper.find('[data-testid="social-form-instagram"]')

    expect(card.classes()).toContain('social-card--native')
    expect(card.find('.social-card__tile--instagram .mdi-instagram').exists()).toBe(true)
    expect(card.find('.social-card__platform').text()).toBe('Instagram')
    expect(card.find('.social-card__handle').text()).toBe('@sofia.marin')
    expect(card.find('[data-testid="social-added-instagram"]').text()).toBe('Added')
  })

  it('offers Edit and Remove inline (Remove in the danger style), and no input', async () => {
    const wrapper = await mountIt(true, { instagram: 'sofia.marin' })

    expect(wrapper.find('[data-testid="social-edit-instagram"]').text()).toBe('Edit')
    const remove = wrapper.find('[data-testid="social-remove-instagram"]')
    expect(remove.text()).toBe('Remove')
    expect(remove.classes()).toContain('text-error')
    expect(wrapper.find('[data-testid="social-handle-instagram"]').exists()).toBe(false)
  })

  it('Edit opens the entry prefilled with Save and Cancel; Cancel goes back to the card', async () => {
    const wrapper = await mountIt(true, { instagram: 'sofia.marin' })

    await wrapper.find('[data-testid="social-edit-instagram"]').trigger('click')
    const input = wrapper.find('[data-testid="social-handle-instagram"] input')
    expect((input.element as HTMLInputElement).value).toBe('sofia.marin')
    expect(wrapper.find('[data-testid="social-connect-instagram"]').text()).toBe('Save')

    await wrapper.find('[data-testid="social-cancel-instagram"]').trigger('click')
    expect(wrapper.find('[data-testid="social-edit-instagram"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="social-handle-instagram"]').exists()).toBe(false)
  })

  it('Remove disconnects the platform', async () => {
    const wrapper = await mountIt(true, { instagram: 'sofia.marin' })
    vi.mocked(onboardingApi.disconnectSocial).mockResolvedValue(creator())

    await wrapper.find('[data-testid="social-remove-instagram"]').trigger('click')
    await flushPromises()

    expect(onboardingApi.disconnectSocial).toHaveBeenCalledWith('instagram')
  })
})

describe('social accounts — native, platform not connected (frame 04)', () => {
  it('says "Not connected" and shows the "@ username" entry with Add — no pill, no Edit/Remove', async () => {
    const wrapper = await mountIt(true, { instagram: 'sofia.marin' })
    const card = wrapper.find('[data-testid="social-form-tiktok"]')

    expect(card.find('.social-card__status').text()).toBe('Not connected')
    expect(card.find('.social-card__handle').exists()).toBe(false)
    expect(card.find('[data-testid="social-added-tiktok"]').exists()).toBe(false)
    expect(card.find('.v-text-field__prefix').text()).toBe('@')
    expect(card.find('input').attributes('placeholder')).toBe('username')
    expect(card.find('[data-testid="social-connect-tiktok"]').text()).toBe('Add')
    expect(card.find('[data-testid="social-edit-tiktok"]').exists()).toBe(false)
    expect(card.find('[data-testid="social-remove-tiktok"]').exists()).toBe(false)
  })

  it('draws TikTok with its own inline mark (MDI has none) and YouTube with the MDI glyph', async () => {
    const wrapper = await mountIt(true)

    expect(wrapper.find('.social-card__tile--tiktok svg path').exists()).toBe(true)
    expect(wrapper.find('.social-card__tile--youtube .mdi-youtube').exists()).toBe(true)
  })

  it('Add stays disabled until the handle is valid, then connects with the normalised handle', async () => {
    const wrapper = await mountIt(true)
    vi.mocked(onboardingApi.connectSocial).mockResolvedValue(creator({ tiktok: 'sofia.marin' }))
    const add = wrapper.find('[data-testid="social-connect-tiktok"]')
    expect(add.attributes('disabled')).toBeDefined()

    await wrapper.find('[data-testid="social-handle-tiktok"] input').setValue('@sofia.marin')
    expect(add.attributes('disabled')).toBeUndefined()
    await add.trigger('click')
    await flushPromises()

    expect(onboardingApi.connectSocial).toHaveBeenCalledWith({
      platform: 'tiktok',
      handle: 'sofia.marin',
      profile_url: 'https://tiktok.com/@sofia.marin',
    })
  })
})

describe('social accounts — web is unchanged', () => {
  it('renders the web rows and none of the native card markup', async () => {
    const wrapper = await mountIt(false, { instagram: 'sofia.marin' })

    expect(wrapper.find('.social-card--native').exists()).toBe(false)
    expect(wrapper.find('.social-card__tile').exists()).toBe(false)
    expect(wrapper.find('.social-card__pill').exists()).toBe(false)
    expect(wrapper.find('[data-testid="social-added-instagram"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="social-form-instagram"]').classes()).toContain(
      'social-accounts__form-row',
    )
    expect(wrapper.find('[data-testid="social-connect-instagram"]').text()).toBe('Edit')
    expect(wrapper.find('[data-testid="social-connect-tiktok"]').text()).toBe('Add')
  })
})
