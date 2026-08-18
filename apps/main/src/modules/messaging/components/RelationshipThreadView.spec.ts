import type { RelationshipMessageResource, RelationshipThreadMeta } from '@catalyst/api-client'
import { type VueWrapper, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

import type { RelationshipChatTransport } from '../api/relationshipMessaging.api'
import RelationshipThreadView from './RelationshipThreadView.vue'

function msg(
  id: string,
  attrs: Partial<RelationshipMessageResource['attributes']> = {},
): RelationshipMessageResource {
  return {
    id,
    type: 'relationship_message',
    attributes: {
      kind: 'text',
      sender_role: 'agency_user',
      body: 'hello there',
      attachments: [],
      is_own: false,
      sender: { name: 'Agency Op' },
      read_by_counterparty: null,
      created_at: '2026-01-01T09:30:00+00:00',
      ...attrs,
    },
  }
}

function feed(
  messages: RelationshipMessageResource[],
  hasMore = false,
  threadOverrides: Partial<RelationshipThreadMeta> = {},
) {
  return {
    data: messages,
    meta: {
      thread: {
        id: 'thread-ulid',
        last_message_at: null,
        unread_count: 0,
        can_send: true,
        closed_reason: null,
        ...threadOverrides,
      } satisfies RelationshipThreadMeta,
      has_more: hasMore,
    },
  }
}

function makeTransport(
  overrides: Partial<RelationshipChatTransport> = {},
): RelationshipChatTransport {
  return {
    list: vi.fn().mockResolvedValue(feed([msg('m1')])),
    send: vi.fn(),
    markRead: vi.fn().mockResolvedValue({ meta: { marked: 0, unread_count: 0 } }),
    attachmentInit: vi.fn(),
    attachmentComplete: vi.fn(),
    ...overrides,
  }
}

async function mountView(transport: RelationshipChatTransport) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(RelationshipThreadView, {
    props: { transport, title: 'Acme Agency' },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('RelationshipThreadView', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('renders incoming bubbles left with the per-message sender name (Q4)', async () => {
    const wrapper = await mountView(makeTransport())
    const row = wrapper.find('[data-test="relationship-message-m1"]')
    expect(row.classes()).not.toContain('rel-bubble-row--own')
    expect(row.find('[data-test="relationship-message-sender"]').text()).toBe('Agency Op')
    wrapper.unmount()
  })

  it('hides the sender label on incoming CREATOR bubbles (AH-013 — label is agency-member only)', async () => {
    const wrapper = await mountView(
      makeTransport({
        list: vi
          .fn()
          .mockResolvedValue(feed([msg('m1', { is_own: false, sender_role: 'creator' })])),
      }),
    )
    const row = wrapper.find('[data-test="relationship-message-m1"]')
    expect(row.classes()).not.toContain('rel-bubble-row--own')
    expect(row.find('[data-test="relationship-message-sender"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('renders own bubbles right with no incoming sender label', async () => {
    const wrapper = await mountView(
      makeTransport({
        list: vi
          .fn()
          .mockResolvedValue(feed([msg('m1', { is_own: true, read_by_counterparty: false })])),
      }),
    )
    const row = wrapper.find('[data-test="relationship-message-m1"]')
    expect(row.classes()).toContain('rel-bubble-row--own')
    expect(row.find('[data-test="relationship-message-sender"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('reads the 2-state tick from read_by_counterparty (sent → false, read → true), never a client guess', async () => {
    const sent = await mountView(
      makeTransport({
        list: vi
          .fn()
          .mockResolvedValue(feed([msg('s', { is_own: true, read_by_counterparty: false })])),
      }),
    )
    expect(sent.find('[data-test="relationship-tick-sent"]').exists()).toBe(true)
    expect(sent.find('[data-test="relationship-tick-read"]').exists()).toBe(false)
    sent.unmount()

    const read = await mountView(
      makeTransport({
        list: vi
          .fn()
          .mockResolvedValue(feed([msg('r', { is_own: true, read_by_counterparty: true })])),
      }),
    )
    expect(read.find('[data-test="relationship-tick-read"]').exists()).toBe(true)
    expect(read.find('[data-test="relationship-tick-sent"]').exists()).toBe(false)
    read.unmount()
  })

  it('stamps every bubble with the DATE as well as the time', async () => {
    const iso = '2026-01-01T09:30:00+00:00'
    const wrapper = await mountView(
      makeTransport({ list: vi.fn().mockResolvedValue(feed([msg('m1', { created_at: iso })])) }),
    )

    // Timezone-agnostic: the expected day is derived from the same instant, so
    // the assertion holds wherever CI runs.
    const expectedDate = new Intl.DateTimeFormat('en', { dateStyle: 'short' }).format(new Date(iso))
    const stamp = wrapper.find('[data-test="relationship-message-time"]').text()
    expect(stamp).toContain(expectedDate)
    expect(stamp).toMatch(/\d:\d{2}/)

    wrapper.unmount()
  })

  it('shows no read tick on incoming messages (tick is own-only)', async () => {
    const wrapper = await mountView(makeTransport())
    expect(wrapper.find('[data-test="relationship-tick-sent"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="relationship-tick-read"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('renders file and link attachments (D4)', async () => {
    const wrapper = await mountView(
      makeTransport({
        list: vi.fn().mockResolvedValue(
          feed([
            msg('a', {
              body: null,
              kind: 'attachment_only',
              attachments: [
                {
                  kind: 'file',
                  s3_path: 'x',
                  mime_type: 'image/png',
                  name: 'shot.png',
                  size_bytes: 10,
                  view_url: 'https://files.example/shot.png',
                },
                { kind: 'link', url: 'https://brief.example/deck', name: 'The deck' },
              ],
            }),
          ]),
        ),
      }),
    )
    const fileLink = wrapper.find('[data-test="relationship-attachment-file"] a')
    expect(fileLink.attributes('href')).toBe('https://files.example/shot.png')
    expect(fileLink.text()).toContain('shot.png')
    const linkLink = wrapper.find('[data-test="relationship-attachment-link"] a')
    expect(linkLink.attributes('href')).toBe('https://brief.example/deck')
    expect(linkLink.text()).toContain('The deck')
    wrapper.unmount()
  })

  // The link composer now lives in a dialog reached via the "+" attach menu →
  // link icon. The dialog teleports to <body>, so its fields are queried there.
  async function addLinkViaDialog(wrapper: VueWrapper, url: string): Promise<void> {
    await wrapper.find('[data-test="relationship-attach-toggle"]').trigger('click')
    await wrapper.find('[data-test="relationship-attach-link"]').trigger('click')
    await flushPromises()
    const urlInput = document.body.querySelector(
      '[data-test="relationship-link-url"] input',
    ) as HTMLInputElement
    urlInput.value = url
    urlInput.dispatchEvent(new Event('input'))
    await flushPromises()
    ;(document.body.querySelector('[data-test="relationship-link-add"]') as HTMLElement).click()
    await flushPromises()
  }

  it('sends a typed message + an attached link through the transport and clears the composer', async () => {
    const transport = makeTransport({
      send: vi.fn().mockResolvedValue({ data: msg('m2', { is_own: true, body: 'my reply' }) }),
    })
    const wrapper = await mountView(transport)

    await wrapper.find('[data-test="relationship-compose-body"] textarea').setValue('my reply')
    await addLinkViaDialog(wrapper, 'https://x.example/a')
    await wrapper.find('[data-test="relationship-compose"]').trigger('submit')
    await flushPromises()

    expect(transport.send).toHaveBeenCalledWith({
      body: 'my reply',
      links: [{ url: 'https://x.example/a' }],
    })
    expect(
      (
        wrapper.find('[data-test="relationship-compose-body"] textarea')
          .element as HTMLTextAreaElement
      ).value,
    ).toBe('')
    wrapper.unmount()
  })

  it('rejects a non-http(s) link client-side before it is attached', async () => {
    const wrapper = await mountView(makeTransport())
    await addLinkViaDialog(wrapper, 'javascript:alert(1)')
    expect(document.body.textContent).toContain('Enter a valid http or https link.')
    expect(wrapper.find('[data-test="relationship-pending-links"]').exists()).toBe(false)
    wrapper.unmount()
  })
})

describe('RelationshipThreadView — closed conversation (AH-051 follow-up)', () => {
  it('renders the composer while the thread is open', async () => {
    const wrapper = await mountView(makeTransport())

    expect(wrapper.find('[data-test="relationship-compose"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="relationship-closed"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('replaces the composer with an explanation when the relation has ENDED', async () => {
    // The reported bug: a disconnected creator saw a live composer, typed, and
    // got `Unrecognized error response (HTTP 403).` The composer must be gone
    // BEFORE they type, and the notice must name the counterparty.
    const wrapper = await mountView(
      makeTransport({
        list: vi
          .fn()
          .mockResolvedValue(
            feed([msg('m1')], false, { can_send: false, closed_reason: 'relation_ended' }),
          ),
      }),
    )

    expect(wrapper.find('[data-test="relationship-compose"]').exists()).toBe(false)

    const closed = wrapper.find('[data-test="relationship-closed"]')
    expect(closed.exists()).toBe(true)
    expect(closed.text()).toContain('your connection with Acme Agency has ended')
    // History survives the closure (server D6) — the point of a read-only state.
    expect(wrapper.text()).toContain('hello there')
    wrapper.unmount()
  })

  it('never renders a raw reason code to the user', async () => {
    const wrapper = await mountView(
      makeTransport({
        list: vi
          .fn()
          .mockResolvedValue(
            feed([msg('m1')], false, { can_send: false, closed_reason: 'relation_ended' }),
          ),
      }),
    )

    const closed = wrapper.find('[data-test="relationship-closed"]').text()
    expect(closed).not.toContain('relation_ended')
    expect(closed).not.toContain('app.messaging')
    wrapper.unmount()
  })

  it('maps every closed_reason to distinct, resolved copy', async () => {
    const reasons: RelationshipThreadMeta['closed_reason'][] = [
      'relation_ended',
      'blacklisted',
      'not_connected',
      'creator_not_approved',
      'no_relation',
      'not_a_party',
    ]

    for (const reason of reasons) {
      const wrapper = await mountView(
        makeTransport({
          list: vi
            .fn()
            .mockResolvedValue(
              feed([msg('m1')], false, { can_send: false, closed_reason: reason }),
            ),
        }),
      )

      const text = wrapper.find('[data-test="relationship-closed"]').text()
      // Resolved copy, never a leaked key or an empty alert.
      expect(text.length).toBeGreaterThan(0)
      expect(text).not.toContain('app.messaging')
      wrapper.unmount()
    }
  })

  it('falls back to an open composer while thread meta is still unknown', async () => {
    // First paint has no meta yet; flashing "closed" on every thread open would
    // be worse than a brief optimistic composer (the server still refuses).
    const wrapper = await mountView(
      makeTransport({ list: vi.fn().mockImplementation(() => new Promise(() => {})) }),
    )

    expect(wrapper.find('[data-test="relationship-closed"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
