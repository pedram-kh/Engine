/**
 * AH-115 (walk) — the creator inbox's two filters: an All | Unread segmented control and a "Direct"
 * toggle chip, kept in the URL (`unread=1`, `direct=1`) and sent to `creators/me/message-inbox`.
 * No brand, campaign or Mine on the creator side; flag off shows none of it.
 *
 * Also pins the search field's layout (item 3): the field is one row tall whatever the list holds.
 *
 * Break-revert: stop sending the filters → the request cases fail; stop writing the URL → the URL
 * cases fail; delete `flex: 0 0 auto` from `.msg-shell__search` → the layout case fails.
 */

import type { CreatorInboxResponse, CreatorInboxRow } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
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

const ROW: CreatorInboxRow = {
  id: 'thr-direct',
  type: 'relationship_thread',
  attributes: {
    agency: { id: 'ag-northwind', name: 'Northwind', logo_url: null },
    brand: null,
    campaign: null,
    assignment_id: null,
    last_message_at: '2020-03-14T10:00:00+00:00',
    preview: 'Hello',
    preview_kind: 'text',
    unread_count: 1,
  },
}
const FULL: CreatorInboxResponse = {
  data: [ROW],
  meta: { unread_threads_total: 1, next_cursor: null },
}
const EMPTY: CreatorInboxResponse = {
  data: [],
  meta: { unread_threads_total: 0, next_cursor: null },
}

/** A route change and the reload it triggers take a few ticks. */
async function settle(): Promise<void> {
  for (let i = 0; i < 4; i++) {
    await flushPromises()
  }
}

async function mountPage(flag: boolean, url = '/creator/messages', inbox = FULL) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().user = {
    id: 'u1',
    type: 'users',
    attributes: { features: { messages_v2_creator_inbox: flag } },
  } as never
  vi.mocked(relationshipMessagingApi.creatorInbox).mockResolvedValue({ data: [] } as never)
  vi.mocked(creatorInboxApi.inbox).mockResolvedValue(inbox)

  const stub = { template: '<div />' }
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
  await router.push(url)
  await router.isReady()
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(
    { template: '<router-view />' },
    { global: { plugins: [pinia, router, i18n, vuetify] }, attachTo: document.body },
  )
  await flushPromises()
  return { wrapper, router }
}

describe('CreatorMessagesPage — filters (AH-115 walk)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    document.body.innerHTML = ''
  })

  it('flag on: All | Unread and a Direct chip, no brand, campaign or Mine', async () => {
    const { wrapper } = await mountPage(true)

    expect(wrapper.get('[data-test="creator-inbox-toggle-all"]').text()).toBe('All')
    expect(wrapper.get('[data-test="creator-inbox-toggle-unread"]').text()).toBe('Unread')
    expect(wrapper.get('[data-test="creator-inbox-direct"]').text()).toBe('Direct')
    expect(wrapper.get('[data-test="creator-inbox-toggle-all"]').attributes('aria-checked')).toBe(
      'true',
    )
    expect(wrapper.get('[data-test="creator-inbox-direct"]').attributes('aria-pressed')).toBe(
      'false',
    )
    expect(wrapper.find('[data-test="agency-inbox-mine"]').exists()).toBe(false)
    await vi.waitFor(() =>
      expect(creatorInboxApi.inbox).toHaveBeenLastCalledWith(null, {
        unread: false,
        direct: false,
      }),
    )
  })

  it('flag off: no filter controls and the new endpoint is never called', async () => {
    const { wrapper } = await mountPage(false)

    expect(wrapper.find('[data-test="creator-inbox-filters"]').exists()).toBe(false)
    expect(creatorInboxApi.inbox).not.toHaveBeenCalled()
  })

  it('Unread writes unread=1 to the URL and requests it; All clears it', async () => {
    const { wrapper, router } = await mountPage(true)

    await wrapper.get('[data-test="creator-inbox-toggle-unread"]').trigger('click')
    await settle()
    await vi.waitFor(() => expect(router.currentRoute.value.query.unread).toBe('1'))
    await vi.waitFor(() =>
      expect(creatorInboxApi.inbox).toHaveBeenLastCalledWith(null, { unread: true, direct: false }),
    )
    expect(
      wrapper.get('[data-test="creator-inbox-toggle-unread"]').attributes('aria-checked'),
    ).toBe('true')

    await wrapper.get('[data-test="creator-inbox-toggle-all"]').trigger('click')
    await settle()
    await vi.waitFor(() => expect(router.currentRoute.value.query.unread).toBeUndefined())
    await vi.waitFor(() =>
      expect(creatorInboxApi.inbox).toHaveBeenLastCalledWith(null, {
        unread: false,
        direct: false,
      }),
    )
  })

  it('the Direct chip toggles direct=1 (on = Direct only; off = all) and combines with Unread', async () => {
    const { wrapper, router } = await mountPage(true)

    await wrapper.get('[data-test="creator-inbox-direct"]').trigger('click')
    await settle()
    await vi.waitFor(() => expect(router.currentRoute.value.query.direct).toBe('1'))
    expect(wrapper.get('[data-test="creator-inbox-direct"]').attributes('aria-pressed')).toBe(
      'true',
    )
    await vi.waitFor(() =>
      expect(creatorInboxApi.inbox).toHaveBeenLastCalledWith(null, { unread: false, direct: true }),
    )

    await wrapper.get('[data-test="creator-inbox-toggle-unread"]').trigger('click')
    await settle()
    await vi.waitFor(() =>
      expect(creatorInboxApi.inbox).toHaveBeenLastCalledWith(null, { unread: true, direct: true }),
    )

    await wrapper.get('[data-test="creator-inbox-direct"]').trigger('click')
    await settle()
    await vi.waitFor(() => expect(router.currentRoute.value.query.direct).toBeUndefined())
    await vi.waitFor(() => expect(router.currentRoute.value.query.unread).toBe('1'))
  })

  it('reads the filters from the URL on load, and a direct row carries them onto its thread link', async () => {
    const { wrapper } = await mountPage(true, '/creator/messages?unread=1&direct=1')

    expect(creatorInboxApi.inbox).toHaveBeenCalledWith(null, { unread: true, direct: true })
    const href = wrapper.get('[data-test="relationship-inbox-row-thr-direct"]').attributes('href')
    expect(href).toContain('unread=1')
    expect(href).toContain('direct=1')
  })

  it('a filter with no match says so instead of offering "Start a conversation"', async () => {
    const { wrapper } = await mountPage(true, '/creator/messages?unread=1', EMPTY)

    expect(wrapper.get('[data-test="creator-inbox-filtered-empty"]').text()).toBe(
      'No conversations match these filters.',
    )
    expect(wrapper.find('[data-test="relationship-inbox-start"]').exists()).toBe(false)
  })

  it('an empty list without a filter keeps the empty state and its CTA, below the filters', async () => {
    const { wrapper } = await mountPage(true, '/creator/messages', EMPTY)

    expect(wrapper.find('[data-test="relationship-inbox-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="creator-inbox-filtered-empty"]').exists()).toBe(false)
  })
})

describe('CreatorMessagesPage — the search field keeps its height (AH-115 walk)', () => {
  it('`.msg-shell__search` is not a growing flex item', () => {
    const src = readFileSync(path.resolve(__dirname, 'CreatorMessagesPage.vue'), 'utf8').replace(
      /\s+/g,
      ' ',
    )
    const m = src.match(/\.msg-shell__search \{([^}]*)\}/)
    expect(m).not.toBeNull()
    expect(m![1]).toMatch(/flex:\s*0 0 auto/)
  })
})
