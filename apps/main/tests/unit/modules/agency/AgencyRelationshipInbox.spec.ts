/**
 * AH-114 (S2.2) — `AgencyRelationshipInbox`, the agency fork of `RelationshipInbox`.
 *
 * Pins what the fork must keep from the original (the `data-test` anchors the two-pane
 * Messages page and its specs read: row, unread count, list, empty state, start button), the
 * states (skeleton, error, empty), and what is new: header controls that render only when the
 * parent supplies their words, search and unread filtering, the dot, and a tag that exists only
 * when an item carries one. Layout and colour are pinned in a real browser by
 * `tools/walk/pins-s22.mjs`.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import AgencyRelationshipInbox, {
  type AgencyInboxItem,
  type AgencyInboxLabels,
} from '@/modules/agency/components/AgencyRelationshipInbox.vue'

function makeItem(over: Partial<AgencyInboxItem> = {}): AgencyInboxItem {
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

const FULL_LABELS: AgencyInboxLabels = {
  search: 'Search conversations',
  unreadPill: (count) => `${count} unread`,
  all: 'All',
  unread: 'Unread',
  noMatch: 'Nothing matches',
}

async function mountInbox(props: {
  items: AgencyInboxItem[]
  loading?: boolean
  loadError?: boolean
  activeId?: string | null
  labels?: AgencyInboxLabels
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
  const wrapper = mount(AgencyRelationshipInbox, {
    props: { loading: false, loadError: false, ...props },
    global: { plugins: [i18n, vuetify, router] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('AgencyRelationshipInbox', () => {
  it('keeps the data-test anchors of the shared inbox: list, row, unread count', async () => {
    const wrapper = await mountInbox({
      items: [makeItem({ unreadCount: 3 }), makeItem({ id: 'c2', title: 'Ari Cole' })],
    })

    expect(wrapper.find('[data-test="relationship-inbox"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="relationship-inbox-list"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="relationship-inbox-row-c1"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="relationship-inbox-row-c2"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="relationship-inbox-unread-c1"]').text()).toBe('3')
    expect(wrapper.find('[data-test="relationship-inbox-unread-c2"]').exists()).toBe(false)
  })

  it('links each row to its conversation', async () => {
    const wrapper = await mountInbox({ items: [makeItem()] })
    expect(wrapper.get('[data-test="relationship-inbox-row-c1"]').attributes('href')).toBe(
      '/messages/c1',
    )
  })

  it('shows the title and subtitle from existing keys', async () => {
    const wrapper = await mountInbox({ items: [makeItem()] })
    expect(wrapper.text()).toContain(enApp.app.messaging.relationship.inboxTitle)
    expect(wrapper.text()).toContain(enApp.app.messaging.relationship.inboxSubtitle)
  })

  it('marks the open conversation and an unread row', async () => {
    const wrapper = await mountInbox({
      items: [makeItem({ unreadCount: 1 }), makeItem({ id: 'c2' })],
      activeId: 'c2',
    })
    expect(wrapper.get('[data-test="relationship-inbox-row-c2"]').classes()).toContain(
      'agency-inbox-row--active',
    )
    expect(wrapper.get('[data-test="relationship-inbox-row-c1"]').classes()).toContain(
      'agency-inbox-row--unread',
    )
    expect(wrapper.get('[data-test="relationship-inbox-row-c1"]').classes()).not.toContain(
      'agency-inbox-row--active',
    )
  })

  it('shows the empty state with a start button that emits start', async () => {
    const wrapper = await mountInbox({ items: [] })
    expect(wrapper.find('[data-test="relationship-inbox-empty"]').exists()).toBe(true)
    await wrapper.get('[data-test="relationship-inbox-start"]').trigger('click')
    expect(wrapper.emitted('start')).toHaveLength(1)
  })

  it('the header "New conversation" button emits start', async () => {
    const wrapper = await mountInbox({ items: [makeItem()] })
    const button = wrapper.get('[data-test="agency-inbox-new"]')
    expect(button.attributes('aria-label')).toBe(enApp.app.messaging.relationship.newConversation)
    await button.trigger('click')
    expect(wrapper.emitted('start')).toHaveLength(1)
  })

  it('shows the error state and no list', async () => {
    const wrapper = await mountInbox({ items: [], loadError: true })
    expect(wrapper.text()).toContain(enApp.app.messaging.relationship.inboxLoadError)
    expect(wrapper.find('[data-test="relationship-inbox-list"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="relationship-inbox-empty"]').exists()).toBe(false)
  })

  it('shows a skeleton while loading, not the empty state', async () => {
    const wrapper = await mountInbox({ items: [], loading: true })
    expect(wrapper.find('.v-skeleton-loader').exists()).toBe(true)
    expect(wrapper.find('[data-test="relationship-inbox-empty"]').exists()).toBe(false)
  })

  it('falls back to the existing "no messages" word when a row has no preview', async () => {
    const wrapper = await mountInbox({ items: [makeItem({ preview: null })] })
    expect(wrapper.get('[data-test="relationship-inbox-row-c1"]').text()).toContain(
      enApp.app.messaging.rollup.noMessages,
    )
  })

  describe('controls that need a word from the parent', () => {
    it('renders none of search, toggle or pill without labels', async () => {
      const wrapper = await mountInbox({ items: [makeItem({ unreadCount: 2 })] })
      expect(wrapper.find('[data-test="agency-inbox-search"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="agency-inbox-toggle"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="agency-inbox-unread-pill"]').exists()).toBe(false)
    })

    it('renders the toggle only when both segment words are supplied', async () => {
      const wrapper = await mountInbox({
        items: [makeItem()],
        labels: { all: 'All' },
      })
      expect(wrapper.find('[data-test="agency-inbox-toggle"]').exists()).toBe(false)
    })

    it('shows the pill with the parent word and the number of unread conversations', async () => {
      const wrapper = await mountInbox({
        items: [
          makeItem({ unreadCount: 4 }),
          makeItem({ id: 'c2', unreadCount: 1 }),
          makeItem({ id: 'c3' }),
        ],
        labels: FULL_LABELS,
      })
      expect(wrapper.get('[data-test="agency-inbox-unread-pill"]').text()).toBe('2 unread')
    })

    it('hides the pill when nothing is unread', async () => {
      const wrapper = await mountInbox({ items: [makeItem()], labels: FULL_LABELS })
      expect(wrapper.find('[data-test="agency-inbox-unread-pill"]').exists()).toBe(false)
    })

    it('filters by name and by preview', async () => {
      const wrapper = await mountInbox({
        items: [
          makeItem(),
          makeItem({ id: 'c2', title: 'Ari Cole', preview: 'Contract attached' }),
        ],
        labels: FULL_LABELS,
      })
      const input = wrapper.get('[data-test="agency-inbox-search"] input')

      await input.setValue('ari')
      expect(wrapper.find('[data-test="relationship-inbox-row-c1"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="relationship-inbox-row-c2"]').exists()).toBe(true)

      await input.setValue('on set')
      expect(wrapper.find('[data-test="relationship-inbox-row-c1"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="relationship-inbox-row-c2"]').exists()).toBe(false)
    })

    it('the Unread segment leaves only unread rows, All brings the rest back', async () => {
      const wrapper = await mountInbox({
        items: [makeItem({ unreadCount: 2 }), makeItem({ id: 'c2' })],
        labels: FULL_LABELS,
      })
      const all = wrapper.get('[data-test="agency-inbox-toggle-all"]')
      const unread = wrapper.get('[data-test="agency-inbox-toggle-unread"]')
      expect(all.attributes('aria-checked')).toBe('true')

      await unread.trigger('click')
      expect(unread.attributes('aria-checked')).toBe('true')
      expect(all.attributes('aria-checked')).toBe('false')
      expect(wrapper.find('[data-test="relationship-inbox-row-c1"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="relationship-inbox-row-c2"]').exists()).toBe(false)

      await all.trigger('click')
      expect(wrapper.find('[data-test="relationship-inbox-row-c2"]').exists()).toBe(true)
    })

    it('says nothing matches only when the parent supplied the words', async () => {
      const withWord = await mountInbox({ items: [makeItem()], labels: FULL_LABELS })
      await withWord.get('[data-test="agency-inbox-search"] input').setValue('zzz')
      expect(withWord.get('[data-test="agency-inbox-no-match"]').text()).toBe('Nothing matches')

      const without = await mountInbox({
        items: [makeItem()],
        labels: { search: 'Search' },
      })
      await without.get('[data-test="agency-inbox-search"] input').setValue('zzz')
      expect(without.find('[data-test="agency-inbox-no-match"]').exists()).toBe(false)
    })
  })

  describe('tag', () => {
    it('renders only when the item carries one', async () => {
      const wrapper = await mountInbox({
        items: [
          makeItem({ tag: 'Glow Co.' }),
          makeItem({ id: 'c2' }),
          makeItem({ id: 'c3', tag: '' }),
        ],
      })
      expect(wrapper.get('[data-test="agency-inbox-tag-c1"]').text()).toBe('Glow Co.')
      expect(wrapper.find('[data-test="agency-inbox-tag-c2"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="agency-inbox-tag-c3"]').exists()).toBe(false)
    })
  })

  describe('row stamp (plan §9.2)', () => {
    const NOW = new Date(2026, 10, 1, 15, 0, 0) // 1 Nov 2026, local time

    afterEach(() => {
      vi.useRealTimers()
    })

    async function stampOf(lastMessageAt: Date): Promise<string> {
      vi.useFakeTimers({ toFake: ['Date'], now: NOW })
      const wrapper = await mountInbox({
        items: [makeItem({ lastMessageAt: lastMessageAt.toISOString() })],
      })
      return wrapper.get('.agency-inbox-row__time').text()
    }

    it('shows a time for today', async () => {
      const at = new Date(2026, 10, 1, 9, 30)
      expect(await stampOf(at)).toBe(
        new Intl.DateTimeFormat('en', { timeStyle: 'short' }).format(at),
      )
    })

    it('shows the day and month earlier this year, with no year', async () => {
      const at = new Date(2026, 2, 14, 9, 30)
      const stamp = await stampOf(at)
      expect(stamp).toBe(
        new Intl.DateTimeFormat('en', { day: 'numeric', month: 'short' }).format(at),
      )
      expect(stamp).not.toContain('2026')
    })

    it('treats yesterday as an ordinary earlier day, not a special word', async () => {
      const at = new Date(2026, 9, 31, 22, 0)
      const stamp = await stampOf(at)
      expect(stamp.toLowerCase()).not.toContain('yesterday')
      expect(stamp).toContain('31')
    })

    it('shows the full date for an earlier year', async () => {
      const at = new Date(2020, 2, 14, 9, 30)
      const stamp = await stampOf(at)
      expect(stamp).toBe(
        new Intl.DateTimeFormat('en', { day: 'numeric', month: 'short', year: 'numeric' }).format(
          at,
        ),
      )
      expect(stamp).toContain('2020')
    })

    it('shows nothing for a row with no timestamp', async () => {
      vi.useFakeTimers({ toFake: ['Date'], now: NOW })
      const wrapper = await mountInbox({ items: [makeItem({ lastMessageAt: null })] })
      expect(wrapper.get('.agency-inbox-row__time').text()).toBe('')
    })
  })

  it('draws an initial when there is no avatar image', async () => {
    const wrapper = await mountInbox({ items: [makeItem({ avatarText: 'nadia' })] })
    expect(wrapper.get('.agency-inbox-row__initial').text()).toBe('N')
  })
})
