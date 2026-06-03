/**
 * Sprint 6 Chunk 2a — unit coverage for the light star-rating input.
 *
 * Built as real <button>s (NOT v-rating, which leaks under jsdom), so it
 * unit-tests directly: select sets the value, re-selecting the current value
 * clears it, fill reflects the model, and readonly renders plain icons (no
 * buttons, no interaction).
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import StarRatingInput from './StarRatingInput.vue'

function mountStars(props: Record<string, unknown> = {}) {
  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })
  return mount(StarRatingInput, {
    props: { modelValue: null, ...props },
    global: { plugins: [vuetify] },
    attachTo: document.createElement('div'),
  })
}

describe('StarRatingInput (Sprint 6 Chunk 2a)', () => {
  it('emits the clicked value', async () => {
    const wrapper = mountStars({ modelValue: null })
    await wrapper.find('[data-test="star-rating-input-star-4"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([4])
    wrapper.unmount()
  })

  it('clears to null when the currently-selected star is re-clicked', async () => {
    const wrapper = mountStars({ modelValue: 3 })
    await wrapper.find('[data-test="star-rating-input-star-3"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([null])
    wrapper.unmount()
  })

  it('fills stars up to the current value', () => {
    const wrapper = mountStars({ modelValue: 2 })
    const checked = wrapper
      .findAll('[role="radio"]')
      .filter((b) => b.attributes('aria-checked') === 'true')
    // Exactly the 2nd star is the selected radio; fill is visual via the icon.
    expect(checked).toHaveLength(1)
    expect(checked[0]?.attributes('data-test')).toBe('star-rating-input-star-2')
    wrapper.unmount()
  })

  it('renders plain icons with no buttons when readonly', async () => {
    const wrapper = mountStars({ modelValue: 4, readonly: true })
    expect(wrapper.findAll('button')).toHaveLength(0)
    // Clicking a readonly star emits nothing.
    await wrapper.find('[data-test="star-rating-input-star-1"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    wrapper.unmount()
  })
})
