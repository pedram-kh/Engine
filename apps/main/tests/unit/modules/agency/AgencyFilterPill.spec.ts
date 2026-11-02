/**
 * AH-114 (S3.3) — `AgencyFilterPill`: unset it reads the filter's name, set it reads
 * "Name: value" and carries a close button, choosing the chosen value again clears it, and
 * the component only emits (the page owns the value). Colour, type and spacing are pinned in a
 * real browser by `tools/walk/pins-s33.mjs`.
 */

import { mount, flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import AgencyFilterPill from '@/modules/agency/components/AgencyFilterPill.vue'

const ITEMS = [
  { title: 'Portugal', value: 'PT' },
  { title: 'Italy', value: 'IT' },
]

const wrappers: Array<ReturnType<typeof mount>> = []

function mountPill(modelValue: string | null) {
  const wrapper = mount(AgencyFilterPill, {
    props: { label: 'Country', items: ITEMS, modelValue },
    global: {
      plugins: [createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })],
    },
    attachTo: document.createElement('div'),
  })
  wrappers.push(wrapper)
  return wrapper
}

const options = () =>
  [...document.querySelectorAll<HTMLElement>('[data-test="agency-filter-pill-option"]')].map((e) =>
    (e.textContent ?? '').trim(),
  )

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()?.unmount()
})

describe('AgencyFilterPill', () => {
  it('unset: reads the filter name, has no close button and is not the applied look', () => {
    const wrapper = mountPill(null)

    expect(wrapper.get('[data-test="agency-filter-pill-button"]').text()).toBe('Country')
    expect(wrapper.find('[data-test="agency-filter-pill-clear"]').exists()).toBe(false)
    expect(wrapper.classes()).not.toContain('agency-filter-pill--applied')
  })

  it('set: reads "Name: value", has the applied look and a close button', () => {
    const wrapper = mountPill('PT')

    expect(wrapper.get('[data-test="agency-filter-pill-button"]').text()).toBe('Country: Portugal')
    expect(wrapper.classes()).toContain('agency-filter-pill--applied')
    expect(wrapper.find('[data-test="agency-filter-pill-clear"]').exists()).toBe(true)
  })

  it('a value the list does not offer reads as unset', () => {
    const wrapper = mountPill('ZZ')

    expect(wrapper.get('[data-test="agency-filter-pill-button"]').text()).toBe('Country')
    expect(wrapper.find('[data-test="agency-filter-pill-clear"]').exists()).toBe(false)
  })

  it('opens a list of the choices and emits the one picked', async () => {
    const wrapper = mountPill(null)

    await wrapper.get('[data-test="agency-filter-pill-button"]').trigger('click')
    await flushPromises()
    expect(options()).toStrictEqual(['Portugal', 'Italy'])

    document.querySelectorAll<HTMLElement>('[data-test="agency-filter-pill-option"]')[1]?.click()
    await flushPromises()
    expect(wrapper.emitted('update:modelValue')).toStrictEqual([['IT']])
  })

  it('choosing the value that is already set clears it', async () => {
    const wrapper = mountPill('PT')

    await wrapper.get('[data-test="agency-filter-pill-button"]').trigger('click')
    await flushPromises()
    document.querySelectorAll<HTMLElement>('[data-test="agency-filter-pill-option"]')[0]?.click()
    await flushPromises()

    expect(wrapper.emitted('update:modelValue')).toStrictEqual([[null]])
  })

  it('the close button clears, and is named "Clear <filter>"', async () => {
    const wrapper = mountPill('PT')
    const close = wrapper.get('[data-test="agency-filter-pill-clear"]')

    expect(close.attributes('aria-label')).toBe('Clear Country')
    await close.trigger('click')

    expect(wrapper.emitted('update:modelValue')).toStrictEqual([[null]])
  })

  it('the button announces a list popup and its open state', async () => {
    const wrapper = mountPill(null)
    const button = wrapper.get('[data-test="agency-filter-pill-button"]')

    expect(button.attributes('aria-haspopup')).toBe('listbox')
    expect(button.attributes('aria-expanded')).toBe('false')
    await button.trigger('click')
    await flushPromises()
    expect(button.attributes('aria-expanded')).toBe('true')
  })
})
