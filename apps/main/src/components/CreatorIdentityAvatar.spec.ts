import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import CreatorIdentityAvatar from './CreatorIdentityAvatar.vue'

function mountAvatar(creator: Record<string, unknown> | null | undefined) {
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  return mount(CreatorIdentityAvatar, { props: { creator }, global: { plugins: [vuetify] } })
}

describe('CreatorIdentityAvatar', () => {
  it('draws the signed image when there is one', () => {
    const w = mountAvatar({ display_name: 'Ada', avatar_url: 'https://cdn/a.jpg' })
    expect(w.findComponent({ name: 'VImg' }).props('src')).toBe('https://cdn/a.jpg')
  })

  it('draws the initial when there is no image', () => {
    const w = mountAvatar({ display_name: 'ada' })
    expect(w.findComponent({ name: 'VImg' }).exists()).toBe(false)
    expect(w.text()).toBe('A')
  })

  it('never draws an image for an erased creator and shows ?', () => {
    const w = mountAvatar({
      display_name: 'Leaked',
      avatar_url: 'https://cdn/a.jpg',
      is_erased: true,
    })
    expect(w.findComponent({ name: 'VImg' }).exists()).toBe(false)
    expect(w.text()).toBe('?')
  })

  it('shows ? for a missing creator', () => {
    expect(mountAvatar(null).text()).toBe('?')
  })
})
