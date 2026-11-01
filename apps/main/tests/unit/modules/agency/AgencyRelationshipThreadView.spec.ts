/**
 * AH-114 (S2.3) — `AgencyRelationshipThreadView`, the agency fork of `RelationshipThreadView`.
 *
 * Pins that the fork keeps the original's behaviour (the `data-test` anchors, own and incoming
 * bubbles, the sender-label rule, the two-state read tick, file and link attachments, sending
 * through the transport, Enter to send, the closed-conversation notice) and what is new: day
 * separators, a time-only stamp, the file card, and no "Report a problem" link. Layout and
 * colour are pinned in a real browser by `tools/walk/pins-s23.mjs`.
 */

import type { RelationshipMessageResource, RelationshipThreadMeta } from '@catalyst/api-client'
import { type VueWrapper, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import AgencyRelationshipThreadView from '@/modules/agency/components/AgencyRelationshipThreadView.vue'
import type { RelationshipChatTransport } from '@/modules/messaging/api/relationshipMessaging.api'

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

async function mountView(
  transport: RelationshipChatTransport,
  props: Record<string, unknown> = {},
) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(AgencyRelationshipThreadView, {
    props: { transport, title: 'Nadia Okafor', ...props },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('AgencyRelationshipThreadView', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('shows the counterparty in the header with an initial avatar', async () => {
    const wrapper = await mountView(makeTransport(), { avatarText: 'Nadia Okafor' })
    expect(wrapper.get('[data-test="relationship-thread-title"]').text()).toBe('Nadia Okafor')
    expect(wrapper.get('.agency-thread__initial').text()).toBe('N')
    wrapper.unmount()
  })

  it('has no report link, no online pill and no more menu', async () => {
    const wrapper = await mountView(makeTransport())
    expect(wrapper.find('[data-test="relationship-thread-report"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain(enApp.app.messaging.reportProblem)
    expect(wrapper.find('[aria-haspopup="menu"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('renders incoming bubbles left with the per-message sender name', async () => {
    const wrapper = await mountView(makeTransport())
    const row = wrapper.get('[data-test="relationship-message-m1"]')
    expect(row.classes()).not.toContain('agency-bubble-row--own')
    expect(row.get('[data-test="relationship-message-sender"]').text()).toBe('Agency Op')
    wrapper.unmount()
  })

  it('hides the sender label on incoming CREATOR bubbles', async () => {
    const wrapper = await mountView(
      makeTransport({
        list: vi
          .fn()
          .mockResolvedValue(feed([msg('m1', { is_own: false, sender_role: 'creator' })])),
      }),
    )
    expect(
      wrapper
        .get('[data-test="relationship-message-m1"]')
        .find('[data-test="relationship-message-sender"]')
        .exists(),
    ).toBe(false)
    wrapper.unmount()
  })

  it('renders own bubbles right, with no incoming sender label', async () => {
    const wrapper = await mountView(
      makeTransport({
        list: vi
          .fn()
          .mockResolvedValue(feed([msg('m1', { is_own: true, read_by_counterparty: false })])),
      }),
    )
    const row = wrapper.get('[data-test="relationship-message-m1"]')
    expect(row.classes()).toContain('agency-bubble-row--own')
    expect(row.find('[data-test="relationship-message-sender"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('reads the two-state tick from read_by_counterparty, own messages only', async () => {
    const wrapper = await mountView(
      makeTransport({
        list: vi
          .fn()
          .mockResolvedValue(
            feed([
              msg('s', { is_own: true, read_by_counterparty: false }),
              msg('r', { is_own: true, read_by_counterparty: true }),
              msg('i'),
            ]),
          ),
      }),
    )
    expect(
      wrapper
        .get('[data-test="relationship-message-s"]')
        .find('[data-test="relationship-tick-sent"]')
        .exists(),
    ).toBe(true)
    expect(
      wrapper
        .get('[data-test="relationship-message-r"]')
        .find('[data-test="relationship-tick-read"]')
        .exists(),
    ).toBe(true)
    const incoming = wrapper.get('[data-test="relationship-message-i"]')
    expect(incoming.find('[data-test="relationship-tick-sent"]').exists()).toBe(false)
    expect(incoming.find('[data-test="relationship-tick-read"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('stamps a bubble with the time only; the day is in the separator', async () => {
    const iso = '2026-01-01T09:30:00+00:00'
    const wrapper = await mountView(
      makeTransport({ list: vi.fn().mockResolvedValue(feed([msg('m1', { created_at: iso })])) }),
    )
    const expectedTime = new Intl.DateTimeFormat('en', { timeStyle: 'short' }).format(new Date(iso))
    const expectedDay = new Intl.DateTimeFormat('en', { dateStyle: 'medium' }).format(new Date(iso))

    expect(wrapper.get('[data-test="relationship-message-time"]').text()).toBe(expectedTime)
    expect(wrapper.get('[data-test="agency-thread-day"]').text()).toBe(expectedDay)
    wrapper.unmount()
  })

  it("draws one separator per calendar day, in order, before that day's messages", async () => {
    const wrapper = await mountView(
      makeTransport({
        list: vi
          .fn()
          .mockResolvedValue(
            feed([
              msg('a', { created_at: '2026-01-01T09:00:00+00:00' }),
              msg('b', { created_at: '2026-01-01T09:05:00+00:00' }),
              msg('c', { created_at: '2026-01-04T12:00:00+00:00' }),
            ]),
          ),
      }),
    )
    const items = wrapper.findAll('.agency-thread__messages > li')
    const kinds = items.map((li) =>
      li.attributes('data-test') === 'agency-thread-day' ? 'day' : 'msg',
    )
    expect(kinds).toEqual(['day', 'msg', 'msg', 'day', 'msg'])
    wrapper.unmount()
  })

  it('labels the current day "Today" (localised, not a key)', async () => {
    const now = new Date()
    const wrapper = await mountView(
      makeTransport({
        list: vi.fn().mockResolvedValue(feed([msg('m1', { created_at: now.toISOString() })])),
      }),
    )
    expect(wrapper.get('[data-test="agency-thread-day"]').text()).toBe('Today')
    wrapper.unmount()
  })

  it('renders a file as a card with its name, type and size, and a link to the file', async () => {
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
                  mime_type: 'application/pdf',
                  name: 'Brief.pdf',
                  size_bytes: 1_200_000,
                  view_url: 'https://files.example/brief.pdf',
                },
              ],
            }),
          ]),
        ),
      }),
    )
    const card = wrapper.get('[data-test="relationship-attachment-file"] a')
    expect(card.attributes('href')).toBe('https://files.example/brief.pdf')
    expect(card.attributes('target')).toBe('_blank')
    expect(card.attributes('rel')).toContain('noopener')
    expect(card.get('.agency-attach__name').text()).toBe('Brief.pdf')
    expect(card.get('.agency-attach__meta').text()).toMatch(/PDF\s+·\s+1\.2\s?MB/)
    wrapper.unmount()
  })

  it('leaves out the type or size when the server did not send it', async () => {
    const wrapper = await mountView(
      makeTransport({
        list: vi.fn().mockResolvedValue(
          feed([
            msg('a', {
              attachments: [
                {
                  kind: 'file',
                  s3_path: 'x',
                  mime_type: null,
                  name: 'notes',
                  size_bytes: null,
                  view_url: 'https://files.example/notes',
                },
              ],
            }),
          ]),
        ),
      }),
    )
    expect(
      wrapper.find('[data-test="relationship-attachment-file"] .agency-attach__meta').exists(),
    ).toBe(false)
    wrapper.unmount()
  })

  it('renders a link as a card with its name and host', async () => {
    const wrapper = await mountView(
      makeTransport({
        list: vi.fn().mockResolvedValue(
          feed([
            msg('a', {
              attachments: [{ kind: 'link', url: 'https://brief.example/deck', name: 'The deck' }],
            }),
          ]),
        ),
      }),
    )
    const card = wrapper.get('[data-test="relationship-attachment-link"] a')
    expect(card.attributes('href')).toBe('https://brief.example/deck')
    expect(card.get('.agency-attach__name').text()).toBe('The deck')
    expect(card.get('.agency-attach__meta').text()).toBe('brief.example')
    wrapper.unmount()
  })

  it('shows a file with no signed URL as plain text, not a dead link', async () => {
    const wrapper = await mountView(
      makeTransport({
        list: vi.fn().mockResolvedValue(
          feed([
            msg('a', {
              attachments: [
                {
                  kind: 'file',
                  s3_path: null,
                  mime_type: null,
                  name: 'gone.pdf',
                  size_bytes: null,
                  view_url: null,
                },
              ],
            }),
          ]),
        ),
      }),
    )
    expect(wrapper.find('[data-test="relationship-attachment-file"] a').exists()).toBe(false)
    expect(wrapper.get('[data-test="relationship-attachment-file"]').text()).toContain('gone.pdf')
    wrapper.unmount()
  })

  it('shows "load earlier" only when there is more, and asks the transport for it', async () => {
    const list = vi
      .fn()
      .mockResolvedValueOnce(feed([msg('m2')], true))
      .mockResolvedValue(feed([msg('m1', { created_at: '2025-12-30T10:00:00+00:00' })], false))
    const wrapper = await mountView(makeTransport({ list }))

    await wrapper.get('[data-test="relationship-load-earlier"]').trigger('click')
    await flushPromises()
    expect(list).toHaveBeenCalledTimes(2)
    expect(list).toHaveBeenLastCalledWith('m2')
    expect(wrapper.find('[data-test="relationship-load-earlier"]').exists()).toBe(false)
    wrapper.unmount()
  })

  async function addLinkViaDialog(wrapper: VueWrapper, url: string): Promise<void> {
    await wrapper.get('[data-test="relationship-attach-toggle"]').trigger('click')
    await wrapper.get('[data-test="relationship-attach-link"]').trigger('click')
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

  it('sends a typed message and an attached link through the transport, then clears', async () => {
    const transport = makeTransport({
      send: vi.fn().mockResolvedValue({ data: msg('m2', { is_own: true, body: 'my reply' }) }),
    })
    const wrapper = await mountView(transport)

    await wrapper.get('[data-test="relationship-compose-body"] textarea').setValue('my reply')
    await addLinkViaDialog(wrapper, 'https://x.example/a')
    await wrapper.get('[data-test="relationship-compose"]').trigger('submit')
    await flushPromises()

    expect(transport.send).toHaveBeenCalledWith({
      body: 'my reply',
      links: [{ url: 'https://x.example/a' }],
    })
    expect(
      (
        wrapper.get('[data-test="relationship-compose-body"] textarea')
          .element as HTMLTextAreaElement
      ).value,
    ).toBe('')
    wrapper.unmount()
  })

  it('the send button is disabled until there is something to send, and sends on click', async () => {
    const transport = makeTransport({
      send: vi.fn().mockResolvedValue({ data: msg('m2', { is_own: true, body: 'hi' }) }),
    })
    const wrapper = await mountView(transport)
    const send = wrapper.get('[data-test="relationship-send"]')
    expect(send.attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="relationship-compose-body"] textarea').setValue('hi')
    expect(wrapper.get('[data-test="relationship-send"]').attributes('disabled')).toBeUndefined()

    await wrapper.get('[data-test="relationship-send"]').trigger('click')
    await flushPromises()
    expect(transport.send).toHaveBeenCalledWith({ body: 'hi' })
    wrapper.unmount()
  })

  it('Enter sends and Shift+Enter does not (desktop)', async () => {
    const transport = makeTransport({
      send: vi.fn().mockResolvedValue({ data: msg('m2', { is_own: true, body: 'hi' }) }),
    })
    const wrapper = await mountView(transport)
    const field = wrapper.get('[data-test="relationship-compose-body"] textarea')
    await field.setValue('hi')

    await field.trigger('keydown', { key: 'Enter', shiftKey: true })
    await flushPromises()
    expect(transport.send).not.toHaveBeenCalled()

    await field.trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(transport.send).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('rejects a non-http(s) link client-side before it is attached', async () => {
    const wrapper = await mountView(makeTransport())
    await addLinkViaDialog(wrapper, 'javascript:alert(1)')
    expect(document.body.textContent).toContain('Enter a valid http or https link.')
    expect(wrapper.find('[data-test="relationship-pending-links"]').exists()).toBe(false)
    wrapper.unmount()
  })

  describe('closed conversation', () => {
    it('keeps the composer while the thread is open', async () => {
      const wrapper = await mountView(makeTransport())
      expect(wrapper.find('[data-test="relationship-compose"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="relationship-closed"]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('replaces the composer with the notice, names the counterparty and keeps history', async () => {
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
      const closed = wrapper.get('[data-test="relationship-closed"]')
      expect(closed.text()).toContain('your connection with Nadia Okafor has ended')
      expect(closed.text()).not.toContain('relation_ended')
      expect(wrapper.text()).toContain('hello there')
      wrapper.unmount()
    })

    it('never renders a leaked key for any reason', async () => {
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
        const text = wrapper.get('[data-test="relationship-closed"]').text()
        expect(text.length).toBeGreaterThan(0)
        expect(text).not.toContain('app.messaging')
        wrapper.unmount()
      }
    })

    it('stays open while the thread meta is unknown', async () => {
      const wrapper = await mountView(
        makeTransport({ list: vi.fn().mockImplementation(() => new Promise(() => {})) }),
      )
      expect(wrapper.find('[data-test="relationship-closed"]').exists()).toBe(false)
      wrapper.unmount()
    })
  })
})
