/**
 * AH-119 — the agency Messages page with the unified inbox behind `features.messages_v2_inbox`.
 *
 * The existing `AgencyMessagesPage.spec.ts` pins the flag-OFF page and stays unchanged; this file
 * pins the flag-ON page and, above all, that the flag decides which inbox is fed and polled:
 * on → `messageInboxApi.list` (and the old `agencyInbox` is never called, never polled); off or
 * absent → the old inbox, and `messageInboxApi.list` is NEVER called.
 *
 * Break-revert: ignore the flag (always unified) → the flag-off cases fail; fetch the old inbox in
 * `onMounted` while unified → the not-called / not-polled cases fail; drop `name` or the filter
 * query from the row links → the links case fails; keep the brand on an agency switch → the switch
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
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

const display = vi.hoisted(() => ({ desktop: { value: true } as { value: boolean } }))

vi.mock('vuetify', async (importOriginal) => {
  const actual = await importOriginal<typeof import('vuetify')>()
  return {
    ...actual,
    useDisplay: () => ({
      mdAndUp: display.desktop,
      get smAndDown() {
        return { value: !display.desktop.value }
      },
    }),
  }
})

vi.mock('../api/relationshipMessaging.api', () => ({
  relationshipMessagingApi: { agencyInbox: vi.fn(), messageableCreators: vi.fn() },
}))
vi.mock('../api/messageInbox.api', () => ({
  messageInboxApi: { list: vi.fn(), reassignOwner: vi.fn() },
}))

import { messageInboxApi } from '../api/messageInbox.api'
import { relationshipMessagingApi } from '../api/relationshipMessaging.api'
import AgencyMessagesPage from './AgencyMessagesPage.vue'

const POLL_MS = 45000

const DIRECT_ROW: MessageInboxRow = {
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
    unread_count: 2,
    owner: null,
  },
}

const ASSIGNMENT_ROW: MessageInboxRow = {
  id: 'thr-asg',
  type: 'assignment_thread',
  attributes: {
    creator: { id: '01DAN', display_name: 'Daniel Okafor', avatar_url: null },
    brand: { id: 'b1', name: 'Acme', color: 'blue' },
    campaign: { id: 'camp1', name: 'Summer' },
    assignment_id: 'asg1',
    last_message_at: '2020-03-14T11:00:00+00:00',
    preview: 'Draft is up',
    preview_kind: 'text',
    unread_count: 0,
    owner: null,
  },
}

const INBOX: MessageInboxResponse = {
  data: [DIRECT_ROW, ASSIGNMENT_ROW],
  meta: {
    unread_threads_total: 1,
    next_cursor: null,
    facets: {
      brands: [
        { id: 'a', name: 'Alpha', color: 'violet', unread_threads: 1 },
        { id: 'b1', name: 'Acme', color: 'blue', unread_threads: 0 },
      ],
      campaigns: [{ id: 'camp1', name: 'Summer', brand_id: 'b1', unread_threads: 0 }],
    },
  },
}

const OLD_INBOX = {
  data: [
    {
      id: 'thread-01MAYA',
      type: 'relationship_thread',
      attributes: {
        creator: { id: '01MAYA', display_name: 'Maya Thompson', avatar_url: null },
        last_message_at: '2020-03-14T10:00:00+00:00',
        last_message_preview: 'Old hello',
        unread_count: 2,
      },
    },
  ],
}

interface MountOptions {
  flag?: boolean | 'absent'
  path?: string
  desktop?: boolean
  inbox?: MessageInboxResponse
}

async function mountPage(options: MountOptions = {}): Promise<{
  wrapper: ReturnType<typeof mount>
  router: Router
  agency: ReturnType<typeof useAgencyStore>
}> {
  display.desktop = { value: options.desktop ?? true }
  const pinia = createPinia()
  setActivePinia(pinia)
  const agency = useAgencyStore()
  agency.initFromUser([
    { agency_id: 'agency-ulid', agency_name: 'Test Agency', role: 'agency_admin' },
    { agency_id: 'agency-two', agency_name: 'Second Agency', role: 'agency_admin' },
  ])
  agency.currentAgencyId = 'agency-ulid'
  const flag = options.flag ?? true
  useAuthStore().user = {
    id: 'user-1',
    type: 'users',
    attributes: { features: flag === 'absent' ? {} : { messages_v2_inbox: flag } },
  } as never

  vi.mocked(relationshipMessagingApi.agencyInbox).mockResolvedValue(OLD_INBOX as never)
  vi.mocked(relationshipMessagingApi.messageableCreators).mockResolvedValue({
    data: [],
    meta: { page: 1, last_page: 1, per_page: 25, total: 0 },
  } as never)
  vi.mocked(messageInboxApi.list).mockResolvedValue(options.inbox ?? INBOX)

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
  await router.push(options.path ?? '/messages')
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

/** Lets the reload a route change triggers land (navigation itself is awaited with `waitFor`). */
async function settle(): Promise<void> {
  for (let i = 0; i < 4; i += 1) {
    await flushPromises()
  }
}

const unifiedRow = (w: ReturnType<typeof mount>, key: string) =>
  w.get(`[data-test="inbox-row-${key}"]`)

const DIRECT_KEY = 'relationship_thread:thr-direct'
const ASSIGNMENT_KEY = 'assignment_thread:thr-asg'

function hrefQuery(href: string | undefined): URLSearchParams {
  return new URLSearchParams((href ?? '').split('?')[1] ?? '')
}

function inBody(testId: string): HTMLElement | null {
  return document.body.querySelector<HTMLElement>(`[data-test="${testId}"]`)
}

describe('AgencyMessagesPage — unified inbox (AH-119)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })
  afterEach(() => {
    vi.useRealTimers()
    document.body.innerHTML = ''
  })

  describe('flag on', () => {
    it('renders the unified inbox from messageInboxApi.list and never calls the old inbox', async () => {
      const { wrapper } = await mountPage({ flag: true })

      expect(wrapper.find('[data-test="unified-inbox"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="relationship-inbox"]').exists()).toBe(false)
      expect(messageInboxApi.list).toHaveBeenCalledTimes(1)
      expect(messageInboxApi.list).toHaveBeenCalledWith(
        'agency-ulid',
        expect.objectContaining({ brand: [], campaign: [], unread: false, owner: 'all' }),
      )
      expect(relationshipMessagingApi.agencyInbox).not.toHaveBeenCalled()
      expect(unifiedRow(wrapper, DIRECT_KEY).text()).toContain('Maya Thompson')
      expect(unifiedRow(wrapper, ASSIGNMENT_KEY).text()).toContain('Daniel Okafor')
      expect(wrapper.get('[data-test="agency-inbox-unread-pill"]').text()).toBe('1 unread')
      wrapper.unmount()
    })

    it('does not poll the old inbox; only the unified composable polls, every 45 s', async () => {
      vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
      const { wrapper } = await mountPage({ flag: true })
      expect(messageInboxApi.list).toHaveBeenCalledTimes(1)

      await vi.advanceTimersByTimeAsync(POLL_MS)
      await vi.advanceTimersByTimeAsync(POLL_MS)
      expect(relationshipMessagingApi.agencyInbox).not.toHaveBeenCalled()
      expect(messageInboxApi.list).toHaveBeenCalledTimes(3)
      wrapper.unmount()
    })

    it('stops polling when the page unmounts', async () => {
      vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
      const { wrapper } = await mountPage({ flag: true })
      wrapper.unmount()
      await vi.advanceTimersByTimeAsync(POLL_MS * 3)
      expect(messageInboxApi.list).toHaveBeenCalledTimes(1)
      expect(relationshipMessagingApi.agencyInbox).not.toHaveBeenCalled()
    })

    it('shows the placeholder until a conversation is open, then the routed thread', async () => {
      const { wrapper, router } = await mountPage()
      expect(wrapper.find('[data-test="messages-placeholder"]').exists()).toBe(true)
      await router.push({
        name: 'messages.assignment-thread',
        params: { campaignUlid: 'camp1', assignmentUlid: 'asg1' },
      })
      await flushPromises()
      expect(wrapper.find('[data-test="messages-placeholder"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="assignment-stub"]').exists()).toBe(true)
      wrapper.unmount()
    })

    it('below md shows only the detail pane while an assignment thread is open', async () => {
      const { wrapper } = await mountPage({
        desktop: false,
        path: '/messages/c/camp1/asg1',
      })
      expect(wrapper.find('[data-test="messages-list-pane"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="messages-detail-pane"]').exists()).toBe(true)
      wrapper.unmount()
    })

    it('leaves out an assignment row that has no campaign or assignment, and a Direct row with no creator', async () => {
      const inbox: MessageInboxResponse = {
        ...INBOX,
        data: [
          DIRECT_ROW,
          {
            ...ASSIGNMENT_ROW,
            id: 'no-campaign',
            attributes: { ...ASSIGNMENT_ROW.attributes, campaign: null },
          },
          {
            ...ASSIGNMENT_ROW,
            id: 'no-assignment',
            attributes: { ...ASSIGNMENT_ROW.attributes, assignment_id: null },
          },
          {
            ...DIRECT_ROW,
            id: 'no-creator',
            attributes: {
              ...DIRECT_ROW.attributes,
              creator: { id: null, display_name: null, avatar_url: null },
            },
          },
        ],
      }
      const { wrapper } = await mountPage({ inbox })
      const rows = wrapper
        .findAll('[data-test^="inbox-row-"]')
        .map((r) => r.attributes('data-test'))
      expect(rows).toEqual([`inbox-row-${DIRECT_KEY}`])
      wrapper.unmount()
    })
  })

  describe('flag off', () => {
    it.each([
      ['false', false as const],
      ['absent', 'absent' as const],
    ])(
      'with the flag %s renders the OLD inbox and never calls messageInboxApi.list',
      async (_n, flag) => {
        const { wrapper } = await mountPage({ flag })

        expect(wrapper.find('[data-test="relationship-inbox"]').exists()).toBe(true)
        expect(wrapper.find('[data-test="unified-inbox"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="relationship-inbox-row-01MAYA"]').text()).toContain(
          'Maya Thompson',
        )
        expect(relationshipMessagingApi.agencyInbox).toHaveBeenCalledTimes(1)
        expect(relationshipMessagingApi.agencyInbox).toHaveBeenCalledWith('agency-ulid')
        expect(messageInboxApi.list).not.toHaveBeenCalled()
        wrapper.unmount()
      },
    )

    it('still polls the old inbox every 45 s and never touches the new one', async () => {
      vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
      const { wrapper } = await mountPage({ flag: false })
      expect(relationshipMessagingApi.agencyInbox).toHaveBeenCalledTimes(1)

      await vi.advanceTimersByTimeAsync(POLL_MS)
      expect(relationshipMessagingApi.agencyInbox).toHaveBeenCalledTimes(2)
      await vi.advanceTimersByTimeAsync(POLL_MS)
      expect(relationshipMessagingApi.agencyInbox).toHaveBeenCalledTimes(3)
      expect(messageInboxApi.list).not.toHaveBeenCalled()
      wrapper.unmount()
    })

    it('ignores filter query keys and an agency switch: the new inbox is still never called', async () => {
      const { wrapper, agency } = await mountPage({
        flag: false,
        path: '/messages?brand=a&owner=me&unread=1',
      })
      agency.currentAgencyId = 'agency-two'
      await settle()
      expect(messageInboxApi.list).not.toHaveBeenCalled()
      expect(wrapper.find('[data-test="unified-inbox"]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('the old inbox rows link to messages.thread with the name only', async () => {
      const { wrapper } = await mountPage({ flag: false })
      const href = wrapper.get('[data-test="relationship-inbox-row-01MAYA"]').attributes('href')
      expect(href?.startsWith('/messages/01MAYA?')).toBe(true)
      expect([...hrefQuery(href).keys()]).toEqual(['name'])
      wrapper.unmount()
    })
  })

  describe('filters in the route query', () => {
    it('loading with ?brand=a&owner=me&unread=1 passes them to messageInboxApi.list', async () => {
      const { wrapper } = await mountPage({ path: '/messages?brand=a&owner=me&unread=1' })
      expect(messageInboxApi.list).toHaveBeenCalledWith('agency-ulid', {
        brand: ['a'],
        campaign: [],
        unread: true,
        owner: 'me',
        cursor: null,
        perPage: 30,
      })
      expect(wrapper.get('[data-test="agency-inbox-mine"]').attributes('aria-pressed')).toBe('true')
      expect(
        wrapper.get('[data-test="agency-inbox-toggle-unread"]').attributes('aria-checked'),
      ).toBe('true')
      expect(wrapper.get('[data-test="inbox-filter-brand"]').text()).toBe('Alpha')
      wrapper.unmount()
    })

    it('ignores garbage values in the query', async () => {
      const { wrapper } = await mountPage({ path: '/messages?owner=everyone&unread=yes' })
      expect(messageInboxApi.list).toHaveBeenCalledWith(
        'agency-ulid',
        expect.objectContaining({ owner: 'all', unread: false }),
      )
      wrapper.unmount()
    })

    it('changing the brand selection calls router.replace with ?brand=… and reloads for it', async () => {
      const { wrapper, router } = await mountPage()
      const replace = vi.spyOn(router, 'replace')

      await wrapper.get('[data-test="inbox-filter-brand"]').trigger('click')
      await settle()
      inBody('inbox-filter-brand-option-a')!.click()
      await settle()

      expect(replace).toHaveBeenCalledTimes(1)
      expect(replace).toHaveBeenCalledWith({
        query: expect.objectContaining({ brand: 'a' }),
      })
      expect(router.currentRoute.value.query.brand).toBe('a')
      expect(messageInboxApi.list).toHaveBeenLastCalledWith(
        'agency-ulid',
        expect.objectContaining({ brand: ['a'], cursor: null }),
      )
      wrapper.unmount()
    })

    it('the Mine chip writes owner=me to the route and reloads with owner me', async () => {
      const { wrapper, router } = await mountPage()
      await wrapper.get('[data-test="agency-inbox-mine"]').trigger('click')
      await vi.waitFor(() => expect(router.currentRoute.value.query.owner).toBe('me'))
      await settle()
      expect(messageInboxApi.list).toHaveBeenLastCalledWith(
        'agency-ulid',
        expect.objectContaining({ owner: 'me' }),
      )
      wrapper.unmount()
    })

    it('Clear filters removes every filter key from the route, and leaves other keys alone', async () => {
      const { wrapper, router } = await mountPage({
        path: '/messages?brand=a&campaign=camp1&owner=me&unread=1&keep=1',
      })
      await wrapper.get('[data-test="agency-inbox-clear-filters"]').trigger('click')
      await vi.waitFor(() => expect(router.currentRoute.value.query).toEqual({ keep: '1' }))
      wrapper.unmount()
    })

    it('keeps the open conversation while a filter changes', async () => {
      const { wrapper, router } = await mountPage({ path: '/messages/01MAYA' })
      await wrapper.get('[data-test="agency-inbox-toggle-unread"]').trigger('click')
      await vi.waitFor(() => expect(router.currentRoute.value.query.unread).toBe('1'))
      expect(router.currentRoute.value.params.creatorUlid).toBe('01MAYA')
      wrapper.unmount()
    })
  })

  describe('row links', () => {
    it('a Direct row links to messages.thread with the creator ULID, the filter query and the name', async () => {
      const { wrapper, router } = await mountPage({
        path: '/messages?brand=a&unread=1&junk=zzz',
      })
      const link = unifiedRow(wrapper, DIRECT_KEY)
      const href = link.attributes('href')
      expect(href?.startsWith('/messages/01MAYA?')).toBe(true)
      const q = hrefQuery(href)
      expect(q.get('brand')).toBe('a')
      expect(q.get('unread')).toBe('1')
      expect(q.get('name')).toBe('Maya Thompson')
      expect(q.has('junk')).toBe(false)

      await link.trigger('click')
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('messages.thread'))
      expect(router.currentRoute.value.params).toEqual({ creatorUlid: '01MAYA' })
      wrapper.unmount()
    })

    it('an assignment row links to messages.assignment-thread with the campaign and assignment ULIDs', async () => {
      const { wrapper, router } = await mountPage({ path: '/messages?owner=me' })
      const link = unifiedRow(wrapper, ASSIGNMENT_KEY)
      const href = link.attributes('href')
      expect(href?.startsWith('/messages/c/camp1/asg1?')).toBe(true)
      const q = hrefQuery(href)
      expect(q.get('owner')).toBe('me')
      expect(q.get('name')).toBe('Daniel Okafor')

      await link.trigger('click')
      await vi.waitFor(() =>
        expect(router.currentRoute.value.name).toBe('messages.assignment-thread'),
      )
      expect(router.currentRoute.value.params).toEqual({
        campaignUlid: 'camp1',
        assignmentUlid: 'asg1',
      })
      wrapper.unmount()
    })

    it('with no filters the link carries the name and nothing else', async () => {
      const { wrapper } = await mountPage()
      const q = hrefQuery(unifiedRow(wrapper, DIRECT_KEY).attributes('href'))
      expect([...q.keys()]).toEqual(['name'])
      wrapper.unmount()
    })

    it('keeps the filters on the link after a filter change', async () => {
      const { wrapper } = await mountPage()
      await wrapper.get('[data-test="agency-inbox-mine"]').trigger('click')
      await vi.waitFor(() =>
        expect(hrefQuery(unifiedRow(wrapper, DIRECT_KEY).attributes('href')).get('owner')).toBe(
          'me',
        ),
      )
      const q = hrefQuery(unifiedRow(wrapper, DIRECT_KEY).attributes('href'))
      expect(q.get('owner')).toBe('me')
      wrapper.unmount()
    })
  })

  describe('switching agency', () => {
    it('clears the brand and campaign selection, keeps unread and owner, and reloads for the new agency', async () => {
      const { wrapper, router, agency } = await mountPage({
        path: '/messages?brand=a&campaign=camp1&unread=1&owner=me',
      })
      agency.currentAgencyId = 'agency-two'
      await vi.waitFor(() =>
        expect(router.currentRoute.value.query).toEqual({ unread: '1', owner: 'me' }),
      )
      await settle()

      expect(messageInboxApi.list).toHaveBeenLastCalledWith('agency-two', {
        brand: [],
        campaign: [],
        unread: true,
        owner: 'me',
        cursor: null,
        perPage: 30,
      })
      wrapper.unmount()
    })

    it('leaves the filters alone when the agency does not change', async () => {
      const { wrapper, router } = await mountPage({ path: '/messages?brand=a&unread=1' })
      await settle()
      expect(router.currentRoute.value.query).toEqual({ brand: 'a', unread: '1' })
      wrapper.unmount()
    })
  })

  describe('active row', () => {
    it('follows the route: a Direct thread highlights its row only', async () => {
      const { wrapper } = await mountPage({ path: '/messages/01MAYA' })
      expect(unifiedRow(wrapper, DIRECT_KEY).classes()).toContain('agency-inbox-row--active')
      expect(unifiedRow(wrapper, ASSIGNMENT_KEY).classes()).not.toContain(
        'agency-inbox-row--active',
      )
      wrapper.unmount()
    })

    it('follows the route: an assignment thread highlights its row only', async () => {
      const { wrapper } = await mountPage({ path: '/messages/c/camp1/asg1' })
      expect(unifiedRow(wrapper, ASSIGNMENT_KEY).classes()).toContain('agency-inbox-row--active')
      expect(unifiedRow(wrapper, DIRECT_KEY).classes()).not.toContain('agency-inbox-row--active')
      wrapper.unmount()
    })

    it('moves with navigation, and clears when no conversation is open', async () => {
      const { wrapper, router } = await mountPage({ path: '/messages/01MAYA' })
      await router.push('/messages/c/camp1/asg1')
      await settle()
      expect(unifiedRow(wrapper, ASSIGNMENT_KEY).classes()).toContain('agency-inbox-row--active')
      expect(unifiedRow(wrapper, DIRECT_KEY).classes()).not.toContain('agency-inbox-row--active')

      await router.push('/messages')
      await settle()
      expect(wrapper.find('.agency-inbox-row--active').exists()).toBe(false)
      wrapper.unmount()
    })

    it('matches an assignment by assignment id, not by creator', async () => {
      // 01DAN is the assignment row's creator, but the route is a Direct thread for them.
      const { wrapper } = await mountPage({ path: '/messages/01DAN' })
      expect(wrapper.find('.agency-inbox-row--active').exists()).toBe(false)
      wrapper.unmount()
    })
  })

  it('the start button of the empty unified inbox opens the contact picker with the filter query on its links', async () => {
    const { wrapper } = await mountPage({
      path: '/messages?owner=me',
      inbox: {
        data: [],
        meta: { unread_threads_total: 0, next_cursor: null, facets: { brands: [], campaigns: [] } },
      },
    })
    vi.mocked(relationshipMessagingApi.messageableCreators).mockResolvedValue({
      data: [{ id: '01NEW', attributes: { display_name: 'Nina New', avatar_url: null } }],
      meta: { page: 1, last_page: 1, per_page: 25, total: 1 },
    } as never)
    // With a filter on, the empty list is the "filtered" one: use the header button instead.
    await wrapper.get('[data-test="agency-inbox-new"]').trigger('click')
    await settle()
    expect(relationshipMessagingApi.messageableCreators).toHaveBeenCalledTimes(1)
    const link = document.body.querySelector<HTMLAnchorElement>('a[href^="/messages/01NEW"]')
    expect(link).not.toBeNull()
    const q = new URLSearchParams((link?.getAttribute('href') ?? '').split('?')[1] ?? '')
    expect(q.get('owner')).toBe('me')
    expect(q.get('name')).toBe('Nina New')
    wrapper.unmount()
  })
})
