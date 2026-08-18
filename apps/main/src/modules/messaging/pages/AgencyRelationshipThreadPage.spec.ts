/**
 * Regression coverage for the AH-013 two-pane thread header.
 *
 * The bug this pins: the shell keeps this page MOUNTED across conversation
 * switches (only the route param changes), so resolving the counterparty in
 * `onMounted` left the header showing the PREVIOUS creator's name and photo
 * while the feed below it correctly swapped — the operator read one person's
 * messages under another person's face.
 *
 * The break-revert is: move the resolution back into `onMounted` → the switch
 * assertions below fail on the stale name/avatar.
 */

import type { AgencyRelationshipInboxEnvelope } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import { useAgencyStore } from '@/core/stores/useAgencyStore'

vi.mock('../api/relationshipMessaging.api', () => ({
  relationshipMessagingApi: { agencyInbox: vi.fn() },
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

import { relationshipMessagingApi } from '../api/relationshipMessaging.api'
import AgencyRelationshipThreadPage from './AgencyRelationshipThreadPage.vue'

const INBOX: AgencyRelationshipInboxEnvelope = {
  data: [
    {
      id: 'thread-nessa',
      type: 'relationship_thread',
      attributes: {
        creator: {
          id: '01NESSA',
          display_name: 'Nessa',
          avatar_url: 'https://signed.example/nessa.jpg',
        },
        last_message_at: '2026-08-11T10:00:00+00:00',
        last_message_preview: 'Hi I would like to consider you...',
        unread_count: 0,
      },
    },
    {
      id: 'thread-dan',
      type: 'relationship_thread',
      attributes: {
        creator: {
          id: '01DAN',
          display_name: 'Dan Richards',
          avatar_url: 'https://signed.example/dan.jpg',
        },
        last_message_at: '2026-08-04T10:00:00+00:00',
        last_message_preview: 'heyyyy',
        unread_count: 0,
      },
    },
  ],
}

async function mountPage(): Promise<{
  wrapper: ReturnType<typeof mount>
  router: Router
}> {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAgencyStore().initFromUser([
    { agency_id: 'agency-ulid', agency_name: 'Test Agency', role: 'agency_admin' },
  ])

  vi.mocked(relationshipMessagingApi.agencyInbox).mockResolvedValue(INBOX)

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/messages', name: 'messages.inbox', component: { template: '<div />' } },
      {
        path: '/messages/:creatorUlid',
        name: 'messages.thread',
        component: { template: '<div />' },
      },
    ],
  })
  await router.push({ name: 'messages.thread', params: { creatorUlid: '01NESSA' } })
  await router.isReady()

  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  const wrapper = mount(AgencyRelationshipThreadPage, {
    global: { plugins: [pinia, router, i18n, vuetify], stubs: { VImg: true } },
    attachTo: document.createElement('div'),
  })
  await flushPromises()

  return { wrapper, router }
}

/** A held inbox response, so the in-flight window is observable. */
function heldInbox(): { promise: Promise<AgencyRelationshipInboxEnvelope>; release: () => void } {
  let resolveFn: (value: AgencyRelationshipInboxEnvelope) => void = () => {}
  const promise = new Promise<AgencyRelationshipInboxEnvelope>((resolve) => {
    resolveFn = resolve
  })
  return { promise, release: () => resolveFn(INBOX) }
}

function headerAvatarSrc(wrapper: ReturnType<typeof mount>): string | undefined {
  return wrapper.findComponent({ name: 'VImg' }).props('src') as string | undefined
}

describe('AgencyRelationshipThreadPage — two-pane header (AH-013)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('resolves the counterparty name and photo for the opened conversation', async () => {
    const { wrapper } = await mountPage()

    expect(wrapper.find('[data-test="relationship-thread-title"]').text()).toBe('Nessa')
    expect(headerAvatarSrc(wrapper)).toBe('https://signed.example/nessa.jpg')

    wrapper.unmount()
  })

  it('FOLLOWS a conversation switch — the page stays mounted, so the header must re-resolve', async () => {
    const { wrapper, router } = await mountPage()
    expect(wrapper.find('[data-test="relationship-thread-title"]').text()).toBe('Nessa')

    await router.push({
      name: 'messages.thread',
      params: { creatorUlid: '01DAN' },
      query: { name: 'Dan Richards' },
    })
    await flushPromises()

    expect(wrapper.find('[data-test="relationship-thread-title"]').text()).toBe('Dan Richards')
    expect(headerAvatarSrc(wrapper)).toBe('https://signed.example/dan.jpg')

    wrapper.unmount()
  })

  it('never shows the previous counterparty while the new row is in flight', async () => {
    const { wrapper, router } = await mountPage()
    expect(headerAvatarSrc(wrapper)).toBe('https://signed.example/nessa.jpg')

    const dan = heldInbox()
    vi.mocked(relationshipMessagingApi.agencyInbox).mockReturnValue(dan.promise)

    await router.push({
      name: 'messages.thread',
      params: { creatorUlid: '01DAN' },
      query: { name: 'Dan Richards' },
    })
    await flushPromises()

    // Mid-flight: the `?name=` hint carries the correct name and the stale
    // photo is GONE (initials), rather than Nessa's face over Dan's thread.
    expect(wrapper.find('[data-test="relationship-thread-title"]').text()).toBe('Dan Richards')
    expect(wrapper.findComponent({ name: 'VImg' }).exists()).toBe(false)

    dan.release()
    await flushPromises()
    expect(headerAvatarSrc(wrapper)).toBe('https://signed.example/dan.jpg')

    wrapper.unmount()
  })

  it('ignores an inbox response that lands after the operator moved on', async () => {
    const { wrapper, router } = await mountPage()

    const dan = heldInbox()
    vi.mocked(relationshipMessagingApi.agencyInbox).mockReturnValueOnce(dan.promise)
    await router.push({ name: 'messages.thread', params: { creatorUlid: '01DAN' } })
    await flushPromises()

    // Switch back before Dan's response lands, then let it through late.
    vi.mocked(relationshipMessagingApi.agencyInbox).mockResolvedValue(INBOX)
    await router.push({
      name: 'messages.thread',
      params: { creatorUlid: '01NESSA' },
      query: { name: 'Nessa' },
    })
    await flushPromises()
    dan.release()
    await flushPromises()

    expect(wrapper.find('[data-test="relationship-thread-title"]').text()).toBe('Nessa')
    expect(headerAvatarSrc(wrapper)).toBe('https://signed.example/nessa.jpg')

    wrapper.unmount()
  })
})
