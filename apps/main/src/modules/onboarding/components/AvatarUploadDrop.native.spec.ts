/**
 * AH-100d — the profile photo's filled state inside the Capacitor app
 * (Figma frame 03): a ROW — thumbnail, "Profile photo *", the size hint,
 * Replace + Remove — instead of the centered thumbnail, dashed dropzone and red
 * text link. The dropzone stays the EMPTY state. Web polarity beside each: the
 * web component keeps its markup (thumbnail, dropzone, red remove link).
 */

import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

const platform = vi.hoisted(() => ({ native: false }))
vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
}))

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: { bootstrap: vi.fn(), uploadAvatar: vi.fn(), deleteAvatar: vi.fn() },
}))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import AvatarUploadDrop from './AvatarUploadDrop.vue'

let teardown: (() => void) | null = null

function creatorWith(avatar: boolean): never {
  return {
    data: {
      id: '01',
      type: 'creators',
      attributes: {
        display_name: 'Sofia',
        avatar_path: avatar ? 'avatars/01.jpg' : null,
        avatar_url: avatar ? 'https://cdn.example.test/avatars/01.jpg' : null,
        application_status: 'incomplete',
      },
      wizard: { next_step: 'profile', is_submitted: false, steps: [], weights: {}, flags: {} },
    },
  } as never
}

async function mountIt(native: boolean, avatar: boolean) {
  platform.native = native
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue(creatorWith(avatar))
  const mounted = await mountAuthPage(AvatarUploadDrop, {
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

describe('AvatarUploadDrop — native, a photo is set (frame 03)', () => {
  it('draws the row: thumbnail, "Profile photo *", the hint, Replace and Remove', async () => {
    const wrapper = await mountIt(true, true)

    const row = wrapper.find('[data-testid="avatar-row"]')
    expect(row.exists()).toBe(true)
    expect(row.find('[data-testid="avatar-preview"] img').attributes('src')).toBe(
      'https://cdn.example.test/avatars/01.jpg',
    )
    expect(row.find('.avatar-upload-drop__row-title').text()).toBe('Profile photo *')
    expect(row.find('.avatar-upload-drop__row-hint').text()).toContain('up to 5 MB')
    expect(row.find('[data-testid="avatar-replace-button"]').text()).toBe('Replace')
    expect(row.find('[data-testid="avatar-remove-button"]').text()).toBe('Remove')
  })

  it('has no dropzone and no red text link', async () => {
    const wrapper = await mountIt(true, true)

    expect(wrapper.find('[data-testid="avatar-drop-zone"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="avatar-remove-button"]').classes()).not.toContain(
      'text-error',
    )
    expect(wrapper.text()).not.toContain('Remove photo')
  })

  it('Replace opens the same file input the dropzone used', async () => {
    const wrapper = await mountIt(true, true)
    const input = wrapper.find<HTMLInputElement>('[data-testid="avatar-file-input"]')
    const click = vi.spyOn(input.element, 'click')

    await wrapper.find('[data-testid="avatar-replace-button"]').trigger('click')

    expect(click).toHaveBeenCalledTimes(1)
    expect(input.attributes('accept')).toContain('image/jpeg')
  })

  it('Remove deletes the avatar', async () => {
    const wrapper = await mountIt(true, true)
    vi.mocked(onboardingApi.deleteAvatar).mockResolvedValue(creatorWith(false))

    await wrapper.find('[data-testid="avatar-remove-button"]').trigger('click')
    await flushPromises()

    expect(onboardingApi.deleteAvatar).toHaveBeenCalledTimes(1)
  })
})

describe('AvatarUploadDrop — native, no photo yet (frame 02): the dropzone stays', () => {
  it('draws the dropzone and no row', async () => {
    const wrapper = await mountIt(true, false)

    expect(wrapper.find('[data-testid="avatar-drop-zone"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="avatar-row"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="avatar-replace-button"]').exists()).toBe(false)
  })
})

describe('AvatarUploadDrop — web is unchanged', () => {
  it('keeps the thumbnail, the dropzone and the red "Remove photo" link; no row', async () => {
    const wrapper = await mountIt(false, true)

    expect(wrapper.find('[data-testid="avatar-row"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="avatar-replace-button"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="avatar-preview"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="avatar-drop-zone"]').exists()).toBe(true)
    const remove = wrapper.find('[data-testid="avatar-remove-button"]')
    expect(remove.text()).toBe('Remove photo')
    expect(remove.classes()).toContain('text-error')
  })
})
