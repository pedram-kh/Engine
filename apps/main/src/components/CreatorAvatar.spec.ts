import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import CreatorAvatar from './CreatorAvatar.vue'

const PHOTO = 'https://signed.example/ada.jpg'

function mountAvatar(props: { src?: string | null; name?: string } = {}) {
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  return mount(CreatorAvatar, {
    props: {
      src: props.src === undefined ? PHOTO : props.src,
      name: props.name ?? 'Ada Lovelace',
      previewLabel: 'Open preview',
      closeLabel: 'Close preview',
    },
    global: { plugins: [vuetify] },
    attachTo: document.body,
  })
}

/** The lightbox teleports out of the wrapper, so it is read off the document. */
function preview(): HTMLElement | null {
  return document.querySelector('[data-test="creator-avatar-preview"]')
}

describe('CreatorAvatar', () => {
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('renders the photo as a squared-off avatar, not a circle', () => {
    const wrapper = mountAvatar()

    const avatar = wrapper.findComponent({ name: 'VAvatar' })
    expect(avatar.props('rounded')).toBe('lg')
    expect(wrapper.findComponent({ name: 'VImg' }).props('src')).toBe(PHOTO)

    wrapper.unmount()
  })

  it('opens the photo in a lightbox on click and closes it again', async () => {
    const wrapper = mountAvatar()
    expect(preview()).toBeNull()

    await wrapper.findComponent({ name: 'VAvatar' }).trigger('click')
    await flushPromises()

    const image = document.querySelector('[data-test="creator-avatar-preview-image"]')
    expect(image?.getAttribute('src')).toBe(PHOTO)
    expect(image?.getAttribute('alt')).toBe('Ada Lovelace')

    const close = document.querySelector<HTMLButtonElement>(
      '[data-test="creator-avatar-preview-close"]',
    )
    expect(close?.getAttribute('aria-label')).toBe('Close preview')
    close?.click()
    await flushPromises()

    // The node lingers through Vuetify's leave transition, which jsdom never
    // finishes — the dialog's own state is the honest signal that it shut.
    expect(wrapper.findComponent({ name: 'VDialog' }).props('modelValue')).toBe(false)
    wrapper.unmount()
  })

  it('opens from the keyboard — the trigger is reachable without a mouse', async () => {
    const wrapper = mountAvatar()
    const avatar = wrapper.findComponent({ name: 'VAvatar' })

    expect(avatar.attributes('role')).toBe('button')
    expect(avatar.attributes('tabindex')).toBe('0')
    expect(avatar.attributes('aria-label')).toBe('Open preview')

    await avatar.trigger('keydown.enter')
    await flushPromises()

    expect(preview()).not.toBeNull()
    wrapper.unmount()
  })

  it('stays INERT with no photo — the initial stands in and nothing opens', async () => {
    const wrapper = mountAvatar({ src: null })

    const avatar = wrapper.findComponent({ name: 'VAvatar' })
    expect(wrapper.findComponent({ name: 'VImg' }).exists()).toBe(false)
    expect(avatar.text()).toBe('A')
    // No click affordance at all: no role, no tab stop, no label.
    expect(avatar.attributes('role')).toBeUndefined()
    expect(avatar.attributes('tabindex')).toBeUndefined()

    await avatar.trigger('click')
    await flushPromises()

    expect(preview()).toBeNull()
    wrapper.unmount()
  })

  it('binds the caller data-test to the avatar (two roots break fallthrough)', () => {
    const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
    const wrapper = mount(CreatorAvatar, {
      props: { src: PHOTO, name: 'Ada Lovelace' },
      attrs: { 'data-test': 'creator-detail-avatar' },
      global: { plugins: [vuetify] },
    })

    expect(wrapper.find('[data-test="creator-detail-avatar"]').exists()).toBe(true)
    wrapper.unmount()
  })
})
