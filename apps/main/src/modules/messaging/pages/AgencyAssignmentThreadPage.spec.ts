/**
 * AH-119 — `AgencyAssignmentThreadPage`: a campaign conversation inside the unified inbox.
 *
 * The page feeds the real agency thread view through `assignmentThreadTransport`; the chat
 * transport and the inbox API are mocked. Pins the transport wiring, the header resolved from the
 * inbox (name, `Brand · Campaign` tag with the brand colour, "View campaign"), no link action, the
 * closed wording, the "⋯" menu, and that a slow answer for a conversation the user already left
 * never writes the header.
 *
 * Break-revert: drop the `assignmentUlid !== assignment` guard → the stale case fails; pass
 * `no-links` away → the no-link case fails; point "View campaign" at the wrong route → the link
 * case fails.
 */

import type { MessageInboxResponse, MessageInboxRow } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import { useAgencyStore } from '@/core/stores/useAgencyStore'

vi.mock('../api/messageInbox.api', () => ({
  messageInboxApi: { list: vi.fn(), reassignOwner: vi.fn() },
}))
vi.mock('../api/messaging.api', () => ({
  agencyChatTransport: vi.fn(),
}))
vi.mock('@/modules/agency-users/api/members.api', () => ({
  membersApi: { list: vi.fn() },
}))

import { membersApi } from '@/modules/agency-users/api/members.api'

import { messageInboxApi } from '../api/messageInbox.api'
import { agencyChatTransport } from '../api/messaging.api'
import AgencyAssignmentThreadPage from './AgencyAssignmentThreadPage.vue'

function inboxRow(
  assignmentId: string,
  name: string,
  overrides: Partial<MessageInboxRow['attributes']> = {},
): MessageInboxRow {
  return {
    id: `thr-${assignmentId}`,
    type: 'assignment_thread',
    attributes: {
      creator: { id: `creator-${assignmentId}`, display_name: name, avatar_url: null },
      brand: { id: 'b1', name: 'Acme', color: 'blue' },
      campaign: { id: 'camp1', name: 'Summer' },
      assignment_id: assignmentId,
      last_message_at: null,
      preview: null,
      preview_kind: 'text',
      unread_count: 0,
      owner: { id: 'user-owner', name: 'Olga Owner' },
      ...overrides,
    },
  }
}

function inbox(...rows: MessageInboxRow[]): MessageInboxResponse {
  return {
    data: rows,
    meta: { unread_threads_total: 0, next_cursor: null, facets: { brands: [], campaigns: [] } },
  }
}

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((res) => {
    resolve = res
  })
  return { promise, resolve }
}

function chatFeed(opts: { blocked?: boolean } = {}) {
  return {
    data: [
      {
        id: 'sys-1',
        type: 'message',
        attributes: {
          kind: 'system',
          sender_role: 'system',
          body: null,
          attachments: [],
          is_own: false,
          sender: null,
          created_at: '2026-01-01T09:00:00+00:00',
          system_event_key: 'assignment.draft_approved',
          system_event_data: null,
        },
      },
      {
        id: 'm-1',
        type: 'message',
        attributes: {
          kind: 'text',
          sender_role: 'creator',
          body: 'Here is the draft',
          attachments: [],
          is_own: false,
          sender: { name: 'Daniel' },
          created_at: '2026-01-01T09:30:00+00:00',
          system_event_key: null,
          system_event_data: null,
        },
      },
    ],
    meta: {
      thread: {
        id: 'thread-ulid',
        last_message_at: null,
        unread_count: 0,
        human_send_blocked: opts.blocked ?? false,
      },
      has_more: false,
    },
  }
}

function chatTransport(opts: { blocked?: boolean } = {}) {
  return {
    list: vi.fn().mockResolvedValue(chatFeed(opts)),
    send: vi.fn(),
    markRead: vi.fn().mockResolvedValue({ meta: { marked: 0, unread_count: 0 } }),
    attachmentInit: vi.fn(),
    attachmentComplete: vi.fn(),
  }
}

async function mountPage(
  options: {
    path?: string
    role?: 'agency_admin' | 'agency_manager' | 'agency_staff'
    blocked?: boolean
    desktop?: boolean
  } = {},
): Promise<{
  wrapper: ReturnType<typeof mount>
  router: Router
  agency: ReturnType<typeof useAgencyStore>
}> {
  const pinia = createPinia()
  setActivePinia(pinia)
  const agency = useAgencyStore()
  agency.initFromUser([
    { agency_id: 'agency-ulid', agency_name: 'Test Agency', role: options.role ?? 'agency_admin' },
  ])
  agency.currentAgencyId = 'agency-ulid'

  vi.mocked(agencyChatTransport).mockImplementation(() => chatTransport(options) as never)

  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/messages', name: 'messages.inbox', component: stub },
      { path: '/messages/:creatorUlid', name: 'messages.thread', component: stub },
      {
        path: '/messages/c/:campaignUlid/:assignmentUlid',
        name: 'messages.assignment-thread',
        component: AgencyAssignmentThreadPage,
      },
      { path: '/campaigns/:ulid', name: 'campaigns.detail', component: stub },
      { path: '/roster/:ulid', name: 'roster.detail', component: stub },
    ],
  })
  await router.push(options.path ?? '/messages/c/camp1/asg1?name=Hint+Name')
  await router.isReady()

  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(
    { template: '<router-view />' },
    { global: { plugins: [pinia, router, i18n, vuetify] }, attachTo: document.body },
  )
  await flushPromises()
  return { wrapper, router, agency }
}

const title = (w: ReturnType<typeof mount>) =>
  w.get('[data-test="relationship-thread-title"]').text()

function inBody(testId: string): HTMLElement | null {
  return document.body.querySelector<HTMLElement>(`[data-test="${testId}"]`)
}

describe('AgencyAssignmentThreadPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(messageInboxApi.list).mockResolvedValue(inbox(inboxRow('asg1', 'Daniel Okafor')))
    vi.mocked(membersApi.list).mockResolvedValue({ data: [], meta: {} } as never)
  })
  afterEach(() => {
    document.body.innerHTML = ''
  })

  describe('transport', () => {
    it('builds the chat transport for the route campaign and assignment, in the current agency', async () => {
      const { wrapper } = await mountPage()
      expect(agencyChatTransport).toHaveBeenCalledWith('agency-ulid', 'camp1', 'asg1')
      wrapper.unmount()
    })

    it('feeds the thread view through the adapter: messages and a localised system line render', async () => {
      const { wrapper } = await mountPage()
      expect(wrapper.get('[data-test="relationship-message-m-1"]').text()).toContain(
        'Here is the draft',
      )
      expect(wrapper.get('[data-test="thread-system-sys-1"]').text()).toBe(
        'The draft was approved.',
      )
      wrapper.unmount()
    })

    it('loads the feed from that transport (and no other)', async () => {
      const { wrapper } = await mountPage()
      expect(agencyChatTransport).toHaveBeenCalledTimes(1)
      const transport = vi.mocked(agencyChatTransport).mock.results[0]!.value
      expect(transport.list).toHaveBeenCalledTimes(1)
      wrapper.unmount()
    })
  })

  describe('header', () => {
    it('asks the unified inbox, narrowed to the route campaign, 100 per page', async () => {
      const { wrapper } = await mountPage()
      expect(messageInboxApi.list).toHaveBeenCalledWith('agency-ulid', {
        campaign: ['camp1'],
        perPage: 100,
      })
      wrapper.unmount()
    })

    it('shows the ?name= hint first, then the creator from the inbox row', async () => {
      const pending = deferred<MessageInboxResponse>()
      vi.mocked(messageInboxApi.list).mockReturnValue(pending.promise)
      const { wrapper } = await mountPage()
      expect(title(wrapper)).toBe('Hint Name')
      expect(wrapper.find('[data-test="thread-campaign-tag"]').exists()).toBe(false)

      pending.resolve(inbox(inboxRow('asg1', 'Daniel Okafor')))
      await flushPromises()
      expect(title(wrapper)).toBe('Daniel Okafor')
      wrapper.unmount()
    })

    it('picks the row of THIS assignment out of the campaign list', async () => {
      vi.mocked(messageInboxApi.list).mockResolvedValue(
        inbox(inboxRow('asg0', 'Someone Else'), inboxRow('asg1', 'Daniel Okafor')),
      )
      const { wrapper } = await mountPage()
      expect(title(wrapper)).toBe('Daniel Okafor')
      wrapper.unmount()
    })

    it('keeps the hint when the assignment is not in the list, and when the call fails', async () => {
      vi.mocked(messageInboxApi.list).mockResolvedValue(inbox(inboxRow('other', 'Nobody')))
      const missing = await mountPage()
      expect(title(missing.wrapper)).toBe('Hint Name')
      expect(missing.wrapper.find('[data-test="thread-campaign-tag"]').exists()).toBe(false)
      missing.wrapper.unmount()

      vi.mocked(messageInboxApi.list).mockRejectedValue(new Error('boom'))
      const failed = await mountPage()
      expect(title(failed.wrapper)).toBe('Hint Name')
      failed.wrapper.unmount()
    })

    it('shows the "Brand · Campaign" tag with the brand colour', async () => {
      const { wrapper } = await mountPage()
      const tag = wrapper.get('[data-test="thread-campaign-tag"]')
      expect(tag.text()).toBe('Acme · Summer')
      expect(tag.get('.agency-thread__tag-dot').attributes('style')).toContain(
        'var(--agency-brand-blue)',
      )
      wrapper.unmount()
    })

    it('shows the campaign name alone, with no dot, when the row has no brand', async () => {
      vi.mocked(messageInboxApi.list).mockResolvedValue(
        inbox(inboxRow('asg1', 'Daniel Okafor', { brand: null })),
      )
      const { wrapper } = await mountPage()
      const tag = wrapper.get('[data-test="thread-campaign-tag"]')
      expect(tag.text()).toBe('Summer')
      expect(tag.find('.agency-thread__tag-dot').exists()).toBe(false)
      wrapper.unmount()
    })

    it('"View campaign" links to campaigns.detail of the route campaign', async () => {
      const { wrapper } = await mountPage()
      const btn = wrapper.get('[data-test="thread-view-campaign"]')
      expect(btn.text()).toBe('View campaign')
      expect(btn.attributes('href')).toBe('/campaigns/camp1')
      wrapper.unmount()
    })

    it('links the name to the creator profile', async () => {
      const { wrapper } = await mountPage()
      expect(wrapper.get('[data-test="relationship-thread-title"]').attributes('href')).toBe(
        '/roster/creator-asg1',
      )
      wrapper.unmount()
    })

    it('does not link an erased creator', async () => {
      vi.mocked(messageInboxApi.list).mockResolvedValue(
        inbox(
          inboxRow('asg1', 'Gone', {
            creator: { id: 'creator-asg1', display_name: null, is_erased: true, avatar_url: null },
          }),
        ),
      )
      const { wrapper } = await mountPage()
      expect(wrapper.get('[data-test="relationship-thread-title"]').element.tagName).toBe('SPAN')
      wrapper.unmount()
    })
  })

  describe('composer', () => {
    it('has no link action (no-links), but still has the file action', async () => {
      const { wrapper } = await mountPage()
      await wrapper.get('[data-test="relationship-attach-toggle"]').trigger('click')
      expect(wrapper.find('[data-test="relationship-attach-file"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="relationship-attach-link"]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('shows the composer and no closed notice while sending is allowed', async () => {
      const { wrapper } = await mountPage({ blocked: false })
      expect(wrapper.find('[data-test="relationship-compose"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="relationship-closed"]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('replaces the composer with app.messaging.closed when human_send_blocked', async () => {
      const { wrapper } = await mountPage({ blocked: true })
      expect(wrapper.find('[data-test="relationship-compose"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="relationship-closed"]').text()).toBe(
        enApp.app.messaging.closed,
      )
      // History stays readable.
      expect(wrapper.text()).toContain('Here is the draft')
      wrapper.unmount()
    })
  })

  describe('⋯ menu', () => {
    it('is present once the creator is known, and shows the owner from the row', async () => {
      const { wrapper } = await mountPage()
      await wrapper.get('[data-test="thread-more"]').trigger('click')
      await flushPromises()
      expect(inBody('thread-owner-line')?.textContent).toContain('Owner: Olga Owner')
      wrapper.unmount()
    })

    it('is absent until the creator is known (no row yet)', async () => {
      vi.mocked(messageInboxApi.list).mockResolvedValue(inbox())
      const { wrapper } = await mountPage()
      expect(wrapper.find('[data-test="thread-more"]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('offers Reassign to an admin and a manager, not to staff', async () => {
      for (const [role, expected] of [
        ['agency_admin', true],
        ['agency_manager', true],
        ['agency_staff', false],
      ] as const) {
        const { wrapper } = await mountPage({ role })
        await wrapper.get('[data-test="thread-more"]').trigger('click')
        await flushPromises()
        expect(inBody('thread-reassign') !== null, role).toBe(expected)
        wrapper.unmount()
        document.body.innerHTML = ''
      }
    })
  })

  describe('navigating between conversations', () => {
    it('drops the previous header at once and shows the next hint until its row lands', async () => {
      const second = deferred<MessageInboxResponse>()
      vi.mocked(messageInboxApi.list)
        .mockResolvedValueOnce(inbox(inboxRow('asg1', 'Daniel Okafor')))
        .mockReturnValueOnce(second.promise)
      const { wrapper, router } = await mountPage()
      expect(title(wrapper)).toBe('Daniel Okafor')

      await router.push('/messages/c/camp1/asg2?name=Next+Hint')
      await flushPromises()
      expect(title(wrapper)).toBe('Next Hint')
      expect(wrapper.find('[data-test="thread-campaign-tag"]').exists()).toBe(false)
      expect(agencyChatTransport).toHaveBeenLastCalledWith('agency-ulid', 'camp1', 'asg2')

      second.resolve(inbox(inboxRow('asg2', 'Priya Rao')))
      await flushPromises()
      expect(title(wrapper)).toBe('Priya Rao')
      wrapper.unmount()
    })

    it('never writes the header from a stale response after navigating to another assignment', async () => {
      const stale = deferred<MessageInboxResponse>()
      vi.mocked(messageInboxApi.list)
        .mockReturnValueOnce(stale.promise)
        .mockResolvedValueOnce(
          inbox(inboxRow('asg1', 'Daniel Okafor'), inboxRow('asg2', 'Priya Rao')),
        )
      const { wrapper, router } = await mountPage()

      await router.push('/messages/c/camp1/asg2?name=Next+Hint')
      await flushPromises()
      expect(title(wrapper)).toBe('Priya Rao')

      // The first request answers late, with the OLD assignment's row.
      stale.resolve(inbox(inboxRow('asg1', 'Daniel Okafor', { owner: null })))
      await flushPromises()
      expect(title(wrapper)).toBe('Priya Rao')
      await wrapper.get('[data-test="thread-more"]').trigger('click')
      await flushPromises()
      // The stale row had no owner; the current one has.
      expect(inBody('thread-owner-line')?.textContent).toContain('Owner: Olga Owner')
      wrapper.unmount()
    })

    it('a stale response for the old assignment does not write even while the new one is still pending', async () => {
      const stale = deferred<MessageInboxResponse>()
      const pending = deferred<MessageInboxResponse>()
      vi.mocked(messageInboxApi.list)
        .mockReturnValueOnce(stale.promise)
        .mockReturnValueOnce(pending.promise)
      const { wrapper, router } = await mountPage()
      await router.push('/messages/c/camp1/asg2?name=Next+Hint')
      await flushPromises()

      stale.resolve(inbox(inboxRow('asg1', 'Daniel Okafor')))
      await flushPromises()
      expect(title(wrapper)).toBe('Next Hint')
      expect(wrapper.find('[data-test="thread-campaign-tag"]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('drops a response that lands after the agency was switched', async () => {
      const stale = deferred<MessageInboxResponse>()
      vi.mocked(messageInboxApi.list).mockReturnValueOnce(stale.promise).mockResolvedValue(inbox())
      const { wrapper, agency } = await mountPage()
      agency.initFromUser([
        { agency_id: 'agency-two', agency_name: 'Second', role: 'agency_admin' },
      ])
      agency.currentAgencyId = 'agency-two'
      await flushPromises()

      stale.resolve(inbox(inboxRow('asg1', 'Daniel Okafor')))
      await flushPromises()
      expect(title(wrapper)).toBe('Hint Name')
      wrapper.unmount()
    })
  })
})
