import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

import ContactPicker, { type ContactPickerItem } from './ContactPicker.vue'

function makeItem(over: Partial<ContactPickerItem> = {}): ContactPickerItem {
  return {
    id: 'c1',
    title: 'Ada Lovelace',
    avatarText: 'Ada Lovelace',
    to: { name: 'messages.thread', params: { creatorUlid: 'c1' } },
    ...over,
  }
}

async function mountPicker(
  props: Partial<{
    modelValue: boolean
    title: string
    items: ContactPickerItem[]
    loading: boolean
    loadError: boolean
    emptyLabel: string
    searchable: boolean
    search: string
    hasMore: boolean
  }> = {},
) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'root', component: { template: '<div />' } },
      {
        path: '/messages/:creatorUlid',
        name: 'messages.thread',
        component: { template: '<div />' },
      },
    ],
  })
  const wrapper = mount(ContactPicker, {
    props: {
      modelValue: true,
      title: 'New conversation',
      items: [],
      loading: false,
      loadError: false,
      emptyLabel: 'No creators to message yet.',
      ...props,
    },
    global: { plugins: [i18n, vuetify, router] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('ContactPicker', () => {
  it('renders a row per contact (teleported into the dialog)', async () => {
    const wrapper = await mountPicker({ items: [makeItem()] })
    // v-dialog teleports to document.body, so query the document, not the wrapper.
    const row = document.querySelector('[data-test="contact-picker-row-c1"]')
    expect(row).not.toBeNull()
    expect(document.body.textContent).toContain('Ada Lovelace')
    // The row routes into the (possibly transient) thread for that contact.
    expect(row?.getAttribute('href')).toContain('c1')
    wrapper.unmount()
  })

  it('renders the empty label when there are no contacts', async () => {
    const wrapper = await mountPicker({ items: [] })
    expect(document.body.textContent).toContain('No creators to message yet.')
    wrapper.unmount()
  })

  it('shows the search field only when searchable, and emits update:search', async () => {
    const plain = await mountPicker({ searchable: false })
    expect(document.querySelector('[data-test="contact-picker-search"]')).toBeNull()
    plain.unmount()

    const searchable = await mountPicker({ searchable: true })
    const input = document.querySelector(
      '[data-test="contact-picker-search"] input',
    ) as HTMLInputElement
    expect(input).not.toBeNull()
    input.value = 'ada'
    input.dispatchEvent(new Event('input'))
    await flushPromises()
    expect(searchable.emitted('update:search')).toBeTruthy()
    searchable.unmount()
  })

  it('emits loadMore when the "load more" button is clicked', async () => {
    const wrapper = await mountPicker({ items: [makeItem()], hasMore: true })
    const more = document.querySelector('[data-test="contact-picker-load-more"]') as HTMLElement
    expect(more).not.toBeNull()
    more.click()
    await flushPromises()
    expect(wrapper.emitted('loadMore')).toHaveLength(1)
    wrapper.unmount()
  })

  it('closes (emits update:modelValue=false) from the close control', async () => {
    const wrapper = await mountPicker({ items: [makeItem()] })
    const close = document.querySelector('[data-test="contact-picker-close"]') as HTMLElement
    expect(close).not.toBeNull()
    close.click()
    await flushPromises()
    const events = wrapper.emitted('update:modelValue')
    expect(events).toBeTruthy()
    expect(events?.at(-1)).toEqual([false])
    wrapper.unmount()
  })
})
