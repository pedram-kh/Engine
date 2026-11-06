/**
 * AH-119 Stage B — message search in the agency Messages page (unified inbox).
 *
 * Pins: with both switches on, a term of 2 or more characters calls `message-search` ONCE after the
 * 300 ms debounce (one character shows the minimum hint and calls nothing); a "Messages" group
 * under the threads shows snippet, "in {name}", "You" for an own hit and the creator's name for
 * theirs; a hit links to its thread (Direct by creator, campaign by campaign and assignment); the
 * empty, error and load-more states; a stale response is dropped; with the Stage B switch off the
 * field stays the client-side filter and the API is never called.
 *
 * Break-revert: search from 1 character → the minChars case fails; drop the debounce → the
 * one-call case fails; ignore the Stage B switch → the flag-off cases fail; drop the generation
 * guard → the stale-response case fails.
 */

import type { MessageInboxResponse, MessageSearchResponse } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

vi.mock('vuetify', async (importOriginal) => {
  const actual = await importOriginal<typeof import('vuetify')>()
  return {
    ...actual,
    useDisplay: () => ({ mdAndUp: { value: true }, smAndDown: { value: false } }),
  }
})

vi.mock('../api/relationshipMessaging.api', () => ({
  relationshipMessagingApi: { agencyInbox: vi.fn(), messageableCreators: vi.fn() },
}))
vi.mock('../api/messageInbox.api', () => ({
  messageInboxApi: { list: vi.fn(), reassignOwner: vi.fn() },
}))
vi.mock('../api/messageSearch.api', () => ({
  MESSAGE_SEARCH_MIN_CHARS: 2,
  messageSearchApi: { search: vi.fn() },
}))

import { messageInboxApi } from '../api/messageInbox.api'
import { relationshipMessagingApi } from '../api/relationshipMessaging.api'
import { messageSearchApi } from '../api/messageSearch.api'
import AgencyMessagesPage from './AgencyMessagesPage.vue'

const INBOX: MessageInboxResponse = {
  data: [
    {
      id: 'thr-direct',
      type: 'relationship_thread',
      attributes: {
        creator: { id: '01MAYA', display_name: 'Maya Thompson', avatar_url: null },
        brand: null,
        campaign: null,
        assignment_id: null,
        last_message_at: '2020-03-14T10:00:00+00:00',
        preview: 'Hello from Maya',
        preview_kind: 'text',
        unread_count: 0,
        owner: null,
      },
    },
  ],
  meta: { unread_threads_total: 0, next_cursor: null, facets: { brands: [], campaigns: [] } },
}

type Hit = MessageSearchResponse['data'][number]

function hit(over: {
  message: string
  thread?: 'relationship_thread' | 'assignment_thread'
  snippet?: string
  own?: boolean
  senderRole?: 'creator' | 'agency_user'
  creator?: { id: string | null; display_name: string | null; is_erased?: boolean }
}): Hit {
  const assignment = over.thread === 'assignment_thread'
  return {
    id: assignment ? 'thr-asg' : 'thr-direct',
    type: over.thread ?? 'relationship_thread',
    attributes: {
      message_ulid: over.message,
      snippet: over.snippet ?? `…about ${over.message}…`,
      sent_at: '2026-11-05T09:30:00+00:00',
      sender_role: over.senderRole ?? 'creator',
      sender_is_erased: false,
      ...(over.own !== undefined ? { is_own: over.own } : {}),
      creator: {
        avatar_url: null,
        ...(over.creator ?? { id: '01MAYA', display_name: 'Maya Thompson' }),
      },
      brand: assignment ? { id: 'b1', name: 'Acme', color: 'blue' } : null,
      campaign: assignment ? { id: 'camp1', name: 'Summer' } : null,
      assignment_id: assignment ? 'asg1' : null,
      last_message_at: '2020-03-14T10:00:00+00:00',
      preview: 'x',
      preview_kind: 'text',
      unread_count: 0,
      owner: null,
    },
  } as Hit
}

const page = (data: Hit[], next: string | null = null): MessageSearchResponse => ({
  data,
  meta: { next_cursor: next, per_page: 30 },
})

async function mountPage(
  features: Record<string, boolean> = { messages_v2_inbox: true, messages_v2_edit_search: true },
) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const agency = useAgencyStore()
  agency.initFromUser([{ agency_id: 'agency-ulid', agency_name: 'A', role: 'agency_admin' }])
  agency.currentAgencyId = 'agency-ulid'
  useAuthStore().user = { id: 'u1', type: 'users', attributes: { features } } as never

  vi.mocked(relationshipMessagingApi.agencyInbox).mockResolvedValue({ data: [] } as never)
  vi.mocked(relationshipMessagingApi.messageableCreators).mockResolvedValue({
    data: [],
    meta: { page: 1, last_page: 1, per_page: 25, total: 0 },
  } as never)
  vi.mocked(messageInboxApi.list).mockResolvedValue(INBOX)

  const stub = (name: string) => ({ template: `<div data-test="${name}" />` })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/messages',
        name: 'messages.inbox',
        component: AgencyMessagesPage,
        children: [
          { path: ':creatorUlid', name: 'messages.thread', component: stub('thread-stub') },
          {
            path: 'c/:campaignUlid/:assignmentUlid',
            name: 'messages.assignment-thread',
            component: stub('assignment-stub'),
          },
        ],
      },
    ],
  })
  await router.push('/messages')
  await router.isReady()
  const wrapper = mount(
    { template: '<router-view />' },
    {
      global: {
        plugins: [
          pinia,
          router,
          createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never }),
          createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives }),
        ],
      },
      attachTo: document.body,
    },
  )
  await flushPromises()
  return wrapper
}

type W = Awaited<ReturnType<typeof mountPage>>

async function type(w: W, term: string): Promise<void> {
  await w.get('[data-test="agency-inbox-search"] input').setValue(term)
}
async function debounce(ms = 300): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms)
  await flushPromises()
}
const group = (w: W) => w.find('[data-test="message-search-results"]')

describe('AgencyMessagesPage — message search (AH-119 Stage B)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(messageSearchApi.search).mockResolvedValue(page([]))
  })
  afterEach(() => {
    vi.useRealTimers()
    document.body.innerHTML = ''
  })

  describe('with both switches on', () => {
    it('shows no group and calls nothing for an empty field', async () => {
      const w = await mountPage()
      expect(group(w).exists()).toBe(false)
      expect(messageSearchApi.search).not.toHaveBeenCalled()
      w.unmount()
    })

    it('shows the minimum-characters hint for one character and calls nothing', async () => {
      const w = await mountPage()
      vi.useFakeTimers()
      await type(w, 'm')
      await debounce(1000)

      expect(w.get('[data-test="message-search-min"]').text()).toBe(
        'Type at least 2 characters to search messages.',
      )
      expect(messageSearchApi.search).not.toHaveBeenCalled()
      w.unmount()
    })

    it('searches once, 300 ms after the last keystroke, from 2 characters', async () => {
      const w = await mountPage()
      vi.useFakeTimers()
      await type(w, 'ma')
      await debounce(299)
      expect(messageSearchApi.search).not.toHaveBeenCalled()
      await type(w, 'mar')
      await debounce(299)
      expect(messageSearchApi.search).not.toHaveBeenCalled()
      await type(w, 'mark')
      await debounce(300)

      expect(messageSearchApi.search).toHaveBeenCalledTimes(1)
      expect(messageSearchApi.search).toHaveBeenCalledWith('agency-ulid', 'mark')
      w.unmount()
    })

    it('trims the term before searching', async () => {
      const w = await mountPage()
      vi.useFakeTimers()
      await type(w, '  brief  ')
      await debounce()
      expect(messageSearchApi.search).toHaveBeenCalledWith('agency-ulid', 'brief')
      w.unmount()
    })

    it('shows the Messages group with snippet, "in {name}", You / creator name, and links to the thread', async () => {
      vi.mocked(messageSearchApi.search).mockResolvedValue(
        page([
          hit({ message: 'm-direct', senderRole: 'creator', snippet: '…the brief is attached…' }),
          hit({ message: 'm-own', senderRole: 'agency_user', own: true, snippet: '…my brief…' }),
          hit({ message: 'm-asg', thread: 'assignment_thread', snippet: '…brief for Summer…' }),
        ]),
      )
      const w = await mountPage()
      vi.useFakeTimers()
      await type(w, 'brief')
      await debounce()

      expect(w.get('.agency-msg-search__heading').text()).toBe('Messages')
      const direct = w.get('[data-test="message-hit-m-direct"]')
      expect(direct.text()).toContain('in Maya Thompson')
      expect(direct.get('[data-test="message-hit-snippet"]').text()).toBe(
        'Maya Thompson: …the brief is attached…',
      )
      expect(direct.attributes('href')).toMatch(/^\/messages\/01MAYA\?.*name=Maya/)

      expect(
        w.get('[data-test="message-hit-m-own"] [data-test="message-hit-snippet"]').text(),
      ).toBe('You: …my brief…')

      const asg = w.get('[data-test="message-hit-m-asg"]')
      expect(asg.attributes('href')).toMatch(/^\/messages\/c\/camp1\/asg1/)
      w.unmount()
    })

    it('does not label an agency-sent hit "You" when the API does not say it is the viewer’s', async () => {
      vi.mocked(messageSearchApi.search).mockResolvedValue(
        page([hit({ message: 'm-col', senderRole: 'agency_user', snippet: '…brief…' })]),
      )
      const w = await mountPage()
      vi.useFakeTimers()
      await type(w, 'brief')
      await debounce()
      expect(
        w.get('[data-test="message-hit-m-col"] [data-test="message-hit-snippet"]').text(),
      ).toBe('…brief…')
      w.unmount()
    })

    it('renders a snippet as text, never as HTML', async () => {
      vi.mocked(messageSearchApi.search).mockResolvedValue(
        page([hit({ message: 'm-x', snippet: '<img src=x onerror=alert(1)> <b>bold</b>' })]),
      )
      const w = await mountPage()
      vi.useFakeTimers()
      await type(w, 'bold')
      await debounce()
      const el = w.get('[data-test="message-hit-m-x"] [data-test="message-hit-snippet"]')
      expect(el.find('img').exists()).toBe(false)
      expect(el.find('b').exists()).toBe(false)
      expect(el.text()).toContain('<b>bold</b>')
      w.unmount()
    })

    it('labels an erased creator’s hit with the anonymised creator label', async () => {
      vi.mocked(messageSearchApi.search).mockResolvedValue(
        page([
          hit({
            message: 'm-erased',
            creator: { id: '01GONE', display_name: null, is_erased: true },
            snippet: '…brief…',
          }),
        ]),
      )
      const w = await mountPage()
      vi.useFakeTimers()
      await type(w, 'brief')
      await debounce()
      const text = w.get('[data-test="message-hit-m-erased"]').text()
      expect(text).not.toContain('null')
      expect(text).toMatch(/in \S+/)
      w.unmount()
    })

    it('shows "No messages match" for an empty result', async () => {
      const w = await mountPage()
      vi.useFakeTimers()
      await type(w, 'zzz')
      await debounce()
      expect(w.get('[data-test="message-search-none"]').text()).toBe('No messages match')
      w.unmount()
    })

    it('shows the error line when the search fails, and recovers on the next term', async () => {
      vi.mocked(messageSearchApi.search).mockRejectedValueOnce(new Error('500'))
      const w = await mountPage()
      vi.useFakeTimers()
      await type(w, 'brief')
      await debounce()
      expect(w.get('[data-test="message-search-error"]').text()).toContain(
        "We couldn't search messages.",
      )

      vi.mocked(messageSearchApi.search).mockResolvedValue(page([hit({ message: 'm1' })]))
      await type(w, 'briefs')
      await debounce()
      expect(w.find('[data-test="message-search-error"]').exists()).toBe(false)
      expect(w.find('[data-test="message-hit-m1"]').exists()).toBe(true)
      w.unmount()
    })

    it('loads the next page with the cursor and appends without duplicates', async () => {
      vi.mocked(messageSearchApi.search)
        .mockResolvedValueOnce(page([hit({ message: 'm1' })], 'CUR1'))
        .mockResolvedValueOnce(page([hit({ message: 'm1' }), hit({ message: 'm2' })], null))
      const w = await mountPage()
      vi.useFakeTimers()
      await type(w, 'brief')
      await debounce()

      await w.get('[data-test="message-search-more"]').trigger('click')
      await flushPromises()

      expect(messageSearchApi.search).toHaveBeenLastCalledWith('agency-ulid', 'brief', 'CUR1')
      expect(
        w
          .findAll('[data-test^="message-hit-m"]')
          .filter((e) => /message-hit-m\d$/.test(e.attributes('data-test') ?? '')),
      ).toHaveLength(2)
      expect(w.find('[data-test="message-search-more"]').exists()).toBe(false)
      w.unmount()
    })

    it('drops a stale response: a slow answer for the old term never replaces the new term’s results', async () => {
      let resolveSlow!: (v: MessageSearchResponse) => void
      vi.mocked(messageSearchApi.search)
        .mockImplementationOnce(
          () => new Promise<MessageSearchResponse>((res) => (resolveSlow = res)),
        )
        .mockResolvedValueOnce(page([hit({ message: 'm-new' })]))
      const w = await mountPage()
      vi.useFakeTimers()
      await type(w, 'old')
      await debounce()
      await type(w, 'new')
      await debounce()
      expect(w.find('[data-test="message-hit-m-new"]').exists()).toBe(true)

      resolveSlow(page([hit({ message: 'm-old' })]))
      await flushPromises()
      expect(w.find('[data-test="message-hit-m-old"]').exists()).toBe(false)
      expect(w.find('[data-test="message-hit-m-new"]').exists()).toBe(true)
      w.unmount()
    })

    it('clearing the field removes the group and cancels a pending search', async () => {
      const w = await mountPage()
      vi.useFakeTimers()
      await type(w, 'brief')
      await type(w, '')
      await debounce(1000)
      expect(group(w).exists()).toBe(false)
      expect(messageSearchApi.search).not.toHaveBeenCalled()
      w.unmount()
    })
  })

  describe('with the Stage B switch off', () => {
    it.each([
      ['false', { messages_v2_inbox: true, messages_v2_edit_search: false }],
      ['absent', { messages_v2_inbox: true }],
    ])(
      'the field stays the client-side filter and nothing is searched (switch %s)',
      async (_n, features) => {
        const w = await mountPage(features)
        vi.useFakeTimers()
        await type(w, 'maya')
        await debounce(1000)

        expect(messageSearchApi.search).not.toHaveBeenCalled()
        expect(group(w).exists()).toBe(false)
        expect(w.find('[data-test="inbox-row-relationship_thread:thr-direct"]').exists()).toBe(true)

        await type(w, 'nobody-matches-this')
        await flushPromises()
        expect(w.find('[data-test="inbox-row-relationship_thread:thr-direct"]').exists()).toBe(
          false,
        )
        w.unmount()
      },
    )
  })

  describe('without the unified inbox', () => {
    it('does not search even with the Stage B switch on (the Direct-only list has no campaign route)', async () => {
      const w = await mountPage({ messages_v2_edit_search: true })
      vi.useFakeTimers()
      await w.get('[data-test="agency-inbox-search"] input').setValue('maya')
      await debounce(1000)
      expect(messageSearchApi.search).not.toHaveBeenCalled()
      w.unmount()
    })
  })
})
