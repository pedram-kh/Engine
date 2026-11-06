/**
 * AH-119 Stage B — `MessageSearchResults`, the "Messages" group of the unified inbox.
 *
 * Pins each state (idle renders nothing, minChars, loading, error, empty, results), the snippet
 * as text with an optional sender label, the "in {name}" line, the hit being a link to the given
 * route, and "Load more" appearing only with `hasMore` and emitting once.
 *
 * Break-revert: render the group while idle → the idle case fails; v-html the snippet → the
 * text-only case fails; show Load more without `hasMore` → the load-more case fails.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import type { MessageSearchState } from '@/modules/messaging/composables/useMessageSearch'

import MessageSearchResults, { type MessageSearchItem } from './MessageSearchResults.vue'

const router = createRouter({
  history: createMemoryHistory(),
  routes: [{ path: '/messages/:id', name: 'messages.thread', component: { template: '<i/>' } }],
})

const ITEM: MessageSearchItem = {
  key: 'm1',
  threadTitle: 'Maya',
  senderLabel: 'You',
  snippet: '…brief…',
  sentAt: '2026-11-05T09:30:00+00:00',
  to: { name: 'messages.thread', params: { id: 'abc' } },
}

function render(
  state: MessageSearchState,
  items: MessageSearchItem[] = [],
  extra: { hasMore?: boolean; loadingMore?: boolean } = {},
) {
  return mount(MessageSearchResults, {
    props: {
      state,
      items,
      hasMore: extra.hasMore ?? false,
      loadingMore: extra.loadingMore ?? false,
    },
    global: {
      plugins: [
        router,
        createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never }),
        createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives }),
      ],
    },
  })
}

describe('MessageSearchResults', () => {
  it('renders nothing while idle', () => {
    expect(render('idle').find('[data-test="message-search-results"]').exists()).toBe(false)
  })

  it.each([
    ['minChars', 'message-search-min', 'Type at least 2 characters to search messages.'],
    ['error', 'message-search-error', "We couldn't search messages."],
    ['empty', 'message-search-none', 'No messages match'],
  ] as const)('state %s shows only its own line under the heading', (state, test, text) => {
    const w = render(state)
    expect(w.get('.agency-msg-search__heading').text()).toBe('Messages')
    expect(w.get(`[data-test="${test}"]`).text()).toContain(text)
    expect(w.find('[data-test="message-search-list"]').exists()).toBe(false)
  })

  it('state loading shows a skeleton and no list', () => {
    const w = render('loading')
    expect(w.find('[data-test="message-search-loading"]').exists()).toBe(true)
    expect(w.find('[data-test="message-search-list"]').exists()).toBe(false)
  })

  it('shows a hit as a link with "in {name}", the sender label and the snippet', () => {
    const w = render('results', [ITEM])
    const hit = w.get('[data-test="message-hit-m1"]')
    expect(hit.element.tagName).toBe('A')
    expect(hit.attributes('href')).toBe('/messages/abc')
    expect(hit.text()).toContain('in Maya')
    expect(w.get('[data-test="message-hit-snippet"]').text()).toBe('You: …brief…')
  })

  it('omits the sender label when the sender cannot be named', () => {
    const w = render('results', [{ ...ITEM, senderLabel: null }])
    expect(w.get('[data-test="message-hit-snippet"]').text()).toBe('…brief…')
  })

  it('renders the snippet as text, never HTML', () => {
    const w = render('results', [{ ...ITEM, snippet: '<b>x</b><img src=y>' }])
    const el = w.get('[data-test="message-hit-snippet"]')
    expect(el.find('b').exists()).toBe(false)
    expect(el.find('img').exists()).toBe(false)
    expect(el.text()).toContain('<b>x</b>')
  })

  it('shows an empty date for an unparseable time rather than "Invalid Date"', () => {
    const w = render('results', [{ ...ITEM, sentAt: 'nonsense' }])
    expect(w.text()).not.toContain('Invalid')
  })

  it('shows Load more only with hasMore, and emits loadMore once per click', async () => {
    expect(render('results', [ITEM]).find('[data-test="message-search-more"]').exists()).toBe(false)
    const w = render('results', [ITEM], { hasMore: true })
    await w.get('[data-test="message-search-more"]').trigger('click')
    expect(w.emitted('loadMore')).toHaveLength(1)
  })
})
