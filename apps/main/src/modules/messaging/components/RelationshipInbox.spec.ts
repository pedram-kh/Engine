import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

import RelationshipInbox, { type RelationshipInboxItem } from './RelationshipInbox.vue'

function makeItem(over: Partial<RelationshipInboxItem> = {}): RelationshipInboxItem {
  return {
    id: 'c1',
    title: 'Nadia Okafor',
    preview: 'See you on set.',
    lastMessageAt: '2026-01-01T09:30:00+00:00',
    unreadCount: 0,
    avatarText: 'Nadia Okafor',
    to: { name: 'messages.thread', params: { creatorUlid: 'c1' } },
    ...over,
  }
}

async function mountInbox(props: {
  items: RelationshipInboxItem[]
  loading?: boolean
  loadError?: boolean
}) {
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
  const wrapper = mount(RelationshipInbox, {
    props: { loading: false, loadError: false, ...props },
    global: { plugins: [i18n, vuetify, router] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('RelationshipInbox', () => {
  it('renders a row per conversation with title and preview', async () => {
    const wrapper = await mountInbox({ items: [makeItem()] })
    const row = wrapper.find('[data-test="relationship-inbox-row-c1"]')
    expect(row.exists()).toBe(true)
    expect(row.text()).toContain('Nadia Okafor')
    expect(row.text()).toContain('See you on set.')
    wrapper.unmount()
  })

  it('shows the unread badge only when unread_count > 0', async () => {
    const withUnread = await mountInbox({ items: [makeItem({ id: 'c1', unreadCount: 3 })] })
    expect(withUnread.find('[data-test="relationship-inbox-unread-c1"]').exists()).toBe(true)
    expect(withUnread.find('[data-test="relationship-inbox-unread-c1"]').text()).toContain('3')
    withUnread.unmount()

    const noUnread = await mountInbox({ items: [makeItem({ id: 'c2', unreadCount: 0 })] })
    expect(noUnread.find('[data-test="relationship-inbox-unread-c2"]').exists()).toBe(false)
    noUnread.unmount()
  })

  it('renders the empty state when there are no conversations', async () => {
    const wrapper = await mountInbox({ items: [] })
    expect(wrapper.find('[data-test="relationship-inbox-empty"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('No conversations yet.')
    wrapper.unmount()
  })

  it('renders the load-error alert', async () => {
    const wrapper = await mountInbox({ items: [], loadError: true })
    expect(wrapper.text()).toContain("We couldn't load your conversations.")
    wrapper.unmount()
  })
})
