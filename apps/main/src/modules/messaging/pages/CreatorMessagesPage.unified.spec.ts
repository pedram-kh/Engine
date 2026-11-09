/**
 * AH-115 L4 — the creator Messages page with the unified inbox behind
 * `features.messages_v2_creator_inbox`.
 *
 * Pins: the flag decides which inbox is fed (on → `creatorInboxApi.inbox`, the old
 * `creatorInbox` is never called; off or absent → the old list and the new endpoints are NEVER
 * called, no tag and no search field), the row tag (`Direct` / `Brand · Campaign`), the row links
 * (a direct thread opens beside the list, an assignment row deep-links to its chat), and the search
 * (debounced, 2 characters, hits link like rows).
 *
 * Break-revert: ignore the flag → the flag-off cases fail; drop the `focus` query → the link case
 * fails; call the search below 2 characters → the minimum case fails.
 */

import type { CreatorInboxResponse, CreatorInboxRow } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

vi.mock('vuetify', async (importOriginal) => {
  const actual = await importOriginal<typeof import('vuetify')>()
  return {
    ...actual,
    useDisplay: () => ({ mdAndUp: { value: true }, smAndDown: { value: false } }),
  }
})

vi.mock('../api/relationshipMessaging.api', () => ({
  relationshipMessagingApi: { creatorInbox: vi.fn(), messageableAgencies: vi.fn() },
}))
vi.mock('../api/creatorInbox.api', () => ({
  creatorInboxApi: { inbox: vi.fn(), search: vi.fn() },
}))

import { creatorInboxApi } from '../api/creatorInbox.api'
import { relationshipMessagingApi } from '../api/relationshipMessaging.api'
import CreatorMessagesPage from './CreatorMessagesPage.vue'

const DIRECT: CreatorInboxRow = {
  id: 'thr-direct',
  type: 'relationship_thread',
  attributes: {
    agency: { id: 'ag-northwind', name: 'Northwind', logo_url: null },
    brand: null,
    campaign: null,
    assignment_id: null,
    last_message_at: '2020-03-14T10:00:00+00:00',
    preview: 'Hello from Northwind',
    preview_kind: 'text',
    unread_count: 2,
  },
}

const CAMPAIGN: CreatorInboxRow = {
  id: 'thr-asg',
  type: 'assignment_thread',
  attributes: {
    agency: { id: 'ag-northwind', name: 'Northwind', logo_url: null },
    brand: { id: 'b1', name: 'Acme' },
    campaign: { id: 'c1', name: 'Autumn push' },
    assignment_id: 'asg-1',
    last_message_at: '2020-03-14T11:00:00+00:00',
    preview: 'Draft is up',
    preview_kind: 'text',
    unread_count: 0,
  },
}

const INBOX: CreatorInboxResponse = {
  data: [CAMPAIGN, DIRECT],
  meta: { unread_threads_total: 1, next_cursor: null },
}

const OLD_INBOX = {
  data: [
    {
      id: 'thread-ag',
      type: 'relationship_thread',
      attributes: {
        agency: { id: 'ag-northwind', name: 'Northwind', logo_path: null, logo_url: null },
        last_message_at: '2020-03-14T10:00:00+00:00',
        last_message_preview: 'Old hello',
        unread_count: 1,
      },
    },
  ],
}

async function mountPage(flag: boolean | 'absent') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().user = {
    id: 'u1',
    type: 'users',
    attributes: { features: flag === 'absent' ? {} : { messages_v2_creator_inbox: flag } },
  } as never

  vi.mocked(relationshipMessagingApi.creatorInbox).mockResolvedValue(OLD_INBOX as never)
  vi.mocked(creatorInboxApi.inbox).mockResolvedValue(INBOX)
  vi.mocked(creatorInboxApi.search).mockResolvedValue({
    data: [
      {
        id: 'thr-asg',
        type: 'assignment_thread',
        attributes: {
          ...CAMPAIGN.attributes,
          message_ulid: 'm1',
          snippet: 'the needle is here',
          sent_at: '2020-03-14T11:00:00+00:00',
          sender_role: 'agency_user',
          is_own: false,
        },
      },
    ],
    meta: { next_cursor: null, per_page: 30 },
  })

  const stub = { template: '<div data-test="stub" />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/creator/messages',
        name: 'creator.messages',
        component: CreatorMessagesPage,
        children: [{ path: ':agencyUlid', name: 'creator.messages.thread', component: stub }],
      },
      { path: '/creator/assignments/:ulid', name: 'creator.assignment.detail', component: stub },
    ],
  })
  await router.push('/creator/messages')
  await router.isReady()

  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(
    { template: '<router-view />' },
    { global: { plugins: [pinia, router, i18n, vuetify] }, attachTo: document.body },
  )
  await flushPromises()
  return wrapper
}

describe('CreatorMessagesPage — unified inbox (AH-115 L4)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })
  afterEach(() => {
    vi.useRealTimers()
    document.body.innerHTML = ''
  })

  it.each([false, 'absent'] as const)(
    'flag %s: the previous list, no tag, no search, no new call',
    async (flag) => {
      const wrapper = await mountPage(flag)

      expect(relationshipMessagingApi.creatorInbox).toHaveBeenCalled()
      expect(creatorInboxApi.inbox).not.toHaveBeenCalled()
      expect(creatorInboxApi.search).not.toHaveBeenCalled()
      expect(wrapper.find('[data-test="creator-inbox-search"]').exists()).toBe(false)
      expect(wrapper.find('[data-test^="relationship-inbox-tag-"]').exists()).toBe(false)
      expect(wrapper.text()).toContain('Old hello')
    },
  )

  it('flag on: one list, tagged Direct and Brand · Campaign, from the new endpoint only', async () => {
    const wrapper = await mountPage(true)

    expect(creatorInboxApi.inbox).toHaveBeenCalled()
    expect(relationshipMessagingApi.creatorInbox).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="relationship-inbox-tag-thr-direct"]').text()).toBe('Direct')
    expect(wrapper.get('[data-test="relationship-inbox-tag-thr-asg"]').text()).toBe(
      'Acme · Autumn push',
    )
    const order = wrapper
      .findAll('[data-test^="relationship-inbox-row-"]')
      .map((r) => r.attributes('data-test'))
    expect(order).toEqual(['relationship-inbox-row-thr-asg', 'relationship-inbox-row-thr-direct'])
  })

  it('flag on: a campaign row deep-links to its assignment chat, a direct row to the thread', async () => {
    const wrapper = await mountPage(true)

    const campaignHref = wrapper
      .get('[data-test="relationship-inbox-row-thr-asg"]')
      .attributes('href')
    expect(campaignHref).toBe('/creator/assignments/asg-1?focus=messages')
    const directHref = wrapper
      .get('[data-test="relationship-inbox-row-thr-direct"]')
      .attributes('href')
    expect(directHref).toContain('/creator/messages/ag-northwind')
  })

  it('flag on: search starts at 2 characters, debounced, and a hit links like its row', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const wrapper = await mountPage(true)
    const input = wrapper.get('[data-test="creator-inbox-search"] input')

    await input.setValue('n')
    await vi.advanceTimersByTimeAsync(500)
    expect(creatorInboxApi.search).not.toHaveBeenCalled()

    await input.setValue('needle')
    await vi.advanceTimersByTimeAsync(500)
    await flushPromises()
    expect(creatorInboxApi.search).toHaveBeenCalledWith('needle', undefined)
    expect(wrapper.text()).toContain('the needle is here')
    const hit = wrapper.find('[data-test="message-search-results"] a')
    expect(hit.attributes('href')).toBe('/creator/assignments/asg-1?focus=messages')
  })
})
