/**
 * AH-100b — `NativeCard` draws a Figma card only inside the Capacitor app.
 * On the web it must leave no trace: the wrapped DOM is exactly the slot.
 */

import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

const platform = vi.hoisted(() => ({ native: false }))
vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
}))

import NativeCard from './NativeCard'

const slot = '<input data-test="a" /><input data-test="b" />'

function mountCard(attrs: Record<string, unknown> = {}) {
  return mount(
    {
      components: { NativeCard },
      template: `<section data-test="host"><NativeCard v-bind="$attrs">${slot}</NativeCard></section>`,
      inheritAttrs: false,
    },
    { attrs },
  )
}

describe('NativeCard', () => {
  afterEach(() => {
    platform.native = false
  })

  it('native: wraps its slot in a .native-card, forwarding attributes', () => {
    platform.native = true
    const wrapper = mountCard({ 'data-test': 'card', class: 'extra' })
    const card = wrapper.find('.native-card')

    expect(card.exists()).toBe(true)
    expect(card.attributes('data-test')).toBe('card')
    expect(card.classes()).toContain('extra')
    expect(card.findAll('input')).toHaveLength(2)
  })

  it('web: renders the slot alone — no wrapper element, the host DOM is unchanged', () => {
    const wrapper = mountCard({ 'data-test': 'card', class: 'extra' })

    expect(wrapper.find('.native-card').exists()).toBe(false)
    expect(wrapper.find('[data-test="card"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="host"]').element.innerHTML).toBe(slot.replace(/ \/>/g, '>'))
  })
})
