/**
 * AH-114 (S3.4) — the agency Messages page on the agency inbox.
 *
 * The page is the shell: the agency inbox fork in the left pane (it owns its header, so the
 * page has no title or "New conversation" button of its own), the open thread in the right pane,
 * one pane at a time below `md`. The page hands the inbox the words that switch its search box,
 * unread pill and All / Unread toggle on.
 *
 * Break-revert: drop `:labels` from the inbox → the controls case fails; render the shared
 * `RelationshipInbox` again → the header case fails.
 */

import type { AgencyRelationshipInboxEnvelope } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import frApp from '@/core/i18n/locales/fr/app.json'
import { useAgencyStore } from '@/core/stores/useAgencyStore'

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

import { relationshipMessagingApi } from '../api/relationshipMessaging.api'
import AgencyMessagesPage from './AgencyMessagesPage.vue'

function row(
  id: string,
  name: string,
  unread: number,
): AgencyRelationshipInboxEnvelope['data'][number] {
  return {
    id: `thread-${id}`,
    type: 'relationship_thread',
    attributes: {
      creator: { id, display_name: name, avatar_url: null },
      last_message_at: '2020-03-14T10:00:00+00:00',
      last_message_preview: `Hello from ${name}`,
      unread_count: unread,
    },
  }
}

const INBOX: AgencyRelationshipInboxEnvelope = {
  data: [
    row('01MAYA', 'Maya Thompson', 2),
    row('01DAN', 'Daniel Okafor', 1),
    row('01PRI', 'Priya', 0),
  ],
}

async function mountPage(
  options: { path?: string; locale?: 'en' | 'fr'; desktop?: boolean } = {},
): Promise<{ wrapper: ReturnType<typeof mount>; router: Router }> {
  display.desktop = { value: options.desktop ?? true }
  const pinia = createPinia()
  setActivePinia(pinia)
  useAgencyStore().initFromUser([
    { agency_id: 'agency-ulid', agency_name: 'Test Agency', role: 'agency_admin' },
  ])
  vi.mocked(relationshipMessagingApi.agencyInbox).mockResolvedValue(INBOX)
  vi.mocked(relationshipMessagingApi.messageableCreators).mockResolvedValue({
    data: [],
    meta: { page: 1, last_page: 1, per_page: 25, total: 0 },
  } as never)

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/messages',
        name: 'messages.inbox',
        component: AgencyMessagesPage,
        children: [
          {
            path: ':creatorUlid',
            name: 'messages.thread',
            component: { template: '<div data-test="thread-stub" />' },
          },
        ],
      },
    ],
  })
  await router.push(options.path ?? '/messages')
  await router.isReady()

  const locale = options.locale ?? 'en'
  const i18n = createI18n({
    legacy: false,
    locale,
    fallbackLocale: 'en',
    messages: { en: enApp, fr: frApp } as never,
  })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(
    { template: '<router-view />' },
    { global: { plugins: [pinia, router, i18n, vuetify] }, attachTo: document.body },
  )
  await flushPromises()
  return { wrapper, router }
}

describe('AgencyMessagesPage (S3.4)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('renders the agency inbox, whose header carries the title and the new-conversation button', async () => {
    const { wrapper } = await mountPage()
    expect(wrapper.find('[data-test="agency-inbox-header"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="agency-inbox-new"]').exists()).toBe(true)
    // The page has no header of its own any more.
    expect(wrapper.find('[data-test="agency-new-conversation"]').exists()).toBe(false)
    expect(wrapper.findAll('h1')).toHaveLength(0)
    expect(wrapper.find('[data-test="relationship-inbox-row-01MAYA"]').text()).toContain(
      'Maya Thompson',
    )
    wrapper.unmount()
  })

  it('hands the inbox its search words, unread pill and All / Unread toggle', async () => {
    const { wrapper } = await mountPage()
    expect(wrapper.find('[data-test="agency-inbox-search"] input').attributes('placeholder')).toBe(
      'Search creators or messages',
    )
    expect(wrapper.get('[data-test="agency-inbox-unread-pill"]').text()).toBe('2 unread')
    expect(wrapper.get('[data-test="agency-inbox-toggle-all"]').text()).toBe('All')
    expect(wrapper.get('[data-test="agency-inbox-toggle-unread"]').text()).toBe('Unread')
    wrapper.unmount()
  })

  it('counts the unread pill by conversation and takes the locale plural form', async () => {
    const { wrapper } = await mountPage({ locale: 'fr' })
    expect(wrapper.get('[data-test="agency-inbox-unread-pill"]').text()).toBe('2 non lues')
    wrapper.unmount()
  })

  it('the header button opens the contact picker and loads the creators', async () => {
    const { wrapper } = await mountPage()
    await wrapper.get('[data-test="agency-inbox-new"]').trigger('click')
    await flushPromises()
    expect(relationshipMessagingApi.messageableCreators).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('on desktop shows both panes, with the placeholder until a conversation is open', async () => {
    const { wrapper, router } = await mountPage()
    expect(wrapper.find('[data-test="messages-list-pane"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="messages-placeholder"]').exists()).toBe(true)

    await router.push('/messages/01MAYA')
    await flushPromises()
    expect(wrapper.find('[data-test="messages-placeholder"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="thread-stub"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="messages-list-pane"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('highlights the open conversation in the list', async () => {
    const { wrapper } = await mountPage({ path: '/messages/01MAYA' })
    expect(wrapper.get('[data-test="relationship-inbox-row-01MAYA"]').classes().join(' ')).toMatch(
      /active/,
    )
    wrapper.unmount()
  })

  it('below md shows one pane at a time', async () => {
    const list = await mountPage({ desktop: false })
    expect(list.wrapper.find('[data-test="messages-list-pane"]').exists()).toBe(true)
    expect(list.wrapper.find('[data-test="messages-detail-pane"]').exists()).toBe(false)
    list.wrapper.unmount()

    const open = await mountPage({ desktop: false, path: '/messages/01MAYA' })
    expect(open.wrapper.find('[data-test="messages-list-pane"]').exists()).toBe(false)
    expect(open.wrapper.find('[data-test="messages-detail-pane"]').exists()).toBe(true)
    open.wrapper.unmount()
  })
})
