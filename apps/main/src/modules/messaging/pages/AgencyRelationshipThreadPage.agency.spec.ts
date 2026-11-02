/**
 * AH-114 (S3.4) — the agency thread page renders the agency thread view (the S2.3 fork), not the
 * shared one the creator surface uses. The header, bubbles and composer are pinned in the fork's
 * own spec; this pins that the page is wired to it, and that it fills the pane the shell gives it.
 *
 * Break-revert: import the shared `RelationshipThreadView` again → the first case fails.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import { useAgencyStore } from '@/core/stores/useAgencyStore'

vi.mock('../api/relationshipMessaging.api', () => ({
  relationshipMessagingApi: { agencyInbox: vi.fn().mockResolvedValue({ data: [] }) },
  agencyRelationshipTransport: vi.fn(() => ({
    list: vi.fn().mockResolvedValue({
      data: [],
      meta: {
        thread: {
          id: 'thread-ulid',
          last_message_at: null,
          unread_count: 0,
          can_send: true,
          closed_reason: null,
        },
        has_more: false,
      },
    }),
    send: vi.fn(),
    markRead: vi.fn().mockResolvedValue({ meta: { marked: 0, unread_count: 0 } }),
    attachmentInit: vi.fn(),
    attachmentComplete: vi.fn(),
  })),
}))

import AgencyRelationshipThreadPage from './AgencyRelationshipThreadPage.vue'

async function mountPage(): Promise<ReturnType<typeof mount>> {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAgencyStore().initFromUser([
    { agency_id: 'agency-ulid', agency_name: 'Test Agency', role: 'agency_admin' },
  ])
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/messages', name: 'messages.inbox', component: { template: '<div />' } },
      {
        path: '/messages/:creatorUlid',
        name: 'messages.thread',
        component: { template: '<div />' },
      },
      // AH-116: the header's avatar and name link to the creator.
      { path: '/roster/:ulid', name: 'roster.detail', component: { template: '<div />' } },
    ],
  })
  await router.push({
    name: 'messages.thread',
    params: { creatorUlid: '01MAYA' },
    query: { name: 'Maya Thompson' },
  })
  await router.isReady()
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(AgencyRelationshipThreadPage, {
    global: { plugins: [pinia, router, i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('AgencyRelationshipThreadPage on the agency thread view (S3.4)', () => {
  it('renders the agency thread view', async () => {
    const wrapper = await mountPage()
    expect(wrapper.find('.agency-thread').exists()).toBe(true)
    expect(wrapper.get('[data-test="relationship-thread-title"]').text()).toBe('Maya Thompson')
    wrapper.unmount()
  })

  it('keeps its root anchor, which the shell sizes to the pane', async () => {
    const wrapper = await mountPage()
    expect(wrapper.element.getAttribute('data-test')).toBe('agency-thread-page')
    wrapper.unmount()
  })
})
