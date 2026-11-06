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
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import { FLAG_OFF_PINS, relTransport, renderHtml } from '../../helpers/stageBFlagOff'
import AgencyRelationshipThreadView from '@/modules/agency/components/AgencyRelationshipThreadView.vue'
import type { AgencyThreadMessage } from '@/modules/messaging/api/agencyThreadTransport'
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

/**
 * AH-119 — what the unified inbox adds to the agency thread view: system lines, inline images,
 * linked bodies, the campaign tag and "View campaign", the `actions` slot, `noLinks`, and pasting
 * images into the composer. A Direct thread passing none of the new props renders as before.
 */
describe('AgencyRelationshipThreadView — AH-119 additions', () => {
  function amsg(
    id: string,
    attrs: Partial<AgencyThreadMessage['attributes']> = {},
  ): RelationshipMessageResource {
    return {
      ...msg(id),
      attributes: { ...msg(id).attributes, ...attrs },
    } as unknown as RelationshipMessageResource
  }

  function systemMsg(
    id: string,
    key: string | null,
    data: Record<string, unknown> | null = null,
  ): RelationshipMessageResource {
    return amsg(id, {
      kind: 'system',
      body: null,
      sender_role: 'system',
      sender: null,
      system_event_key: key,
      system_event_data: data,
    } as never)
  }

  function fileAtt(overrides: Record<string, unknown> = {}) {
    return {
      kind: 'file',
      s3_path: 'x',
      mime_type: 'image/png',
      name: 'shot.png',
      size_bytes: 2048,
      view_url: 'https://files.example/shot.png',
      ...overrides,
    }
  }

  async function mountAgencyView(
    messages: RelationshipMessageResource[],
    props: Record<string, unknown> = {},
    slots: Record<string, string> = {},
    threadOverrides: Partial<RelationshipThreadMeta> = {},
  ) {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: { template: '<div />' } },
        { path: '/campaigns/:ulid', name: 'campaigns.detail', component: { template: '<div />' } },
      ],
    })
    await router.push('/')
    await router.isReady()
    const transport = makeTransport({
      list: vi.fn().mockResolvedValue(feed(messages, false, threadOverrides)),
    })
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
    const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
    const wrapper = mount(AgencyRelationshipThreadView, {
      props: { transport, title: 'Nadia Okafor', ...props },
      slots,
      global: { plugins: [router, i18n, vuetify] },
      attachTo: document.createElement('div'),
    })
    await flushPromises()
    return wrapper
  }

  const euro = (major: number): string =>
    new Intl.NumberFormat('en', { style: 'currency', currency: 'EUR' }).format(major)

  describe('system lines', () => {
    it('renders a system message as a centred line from app.messaging.system.<key>, not a bubble', async () => {
      const wrapper = await mountAgencyView([systemMsg('s1', 'assignment.draft_approved')])
      const line = wrapper.get('[data-test="thread-system-s1"]')
      expect(line.text()).toBe('The draft was approved.')
      expect(line.classes()).toContain('agency-thread__system')
      expect(wrapper.find('[data-test="relationship-message-s1"]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('falls back to the generic line for an unknown key, and for no key', async () => {
      const wrapper = await mountAgencyView([
        systemMsg('s1', 'assignment.something_new'),
        systemMsg('s2', null),
      ])
      expect(wrapper.get('[data-test="thread-system-s1"]').text()).toBe(
        'The assignment was updated.',
      )
      expect(wrapper.get('[data-test="thread-system-s2"]').text()).toBe(
        'The assignment was updated.',
      )
      wrapper.unmount()
    })

    it('never shows the raw key', async () => {
      const wrapper = await mountAgencyView([systemMsg('s1', 'assignment.something_new')])
      expect(wrapper.text()).not.toContain('assignment.something_new')
      expect(wrapper.text()).not.toContain('app.messaging.system')
      wrapper.unmount()
    })

    it('renders the offer_edited money line with both amounts formatted for the locale', async () => {
      const wrapper = await mountAgencyView([
        systemMsg('s1', 'assignment.offer_edited', {
          previous_amount: 150000,
          new_amount: 180000,
          currency: 'EUR',
        }),
      ])
      expect(wrapper.get('[data-test="thread-system-s1"]').text()).toBe(
        `The offer changed from ${euro(1500)} to ${euro(1800)}.`,
      )
      wrapper.unmount()
    })

    it.each([
      ['no data', null],
      ['text amounts', { previous_amount: '1', new_amount: '2', currency: 'EUR' }],
      ['no currency', { previous_amount: 1, new_amount: 2, currency: '' }],
    ])('offer_edited with %s falls back to the generic line', async (_n, data) => {
      const wrapper = await mountAgencyView([systemMsg('s1', 'assignment.offer_edited', data)])
      expect(wrapper.get('[data-test="thread-system-s1"]').text()).toBe(
        'The assignment was updated.',
      )
      wrapper.unmount()
    })

    it('keeps system lines in order between ordinary messages', async () => {
      const wrapper = await mountAgencyView([
        amsg('a', { created_at: '2026-01-01T09:00:00+00:00' }),
        systemMsg('s1', 'assignment.draft_approved'),
        amsg('b', { created_at: '2026-01-01T09:20:00+00:00' }),
      ])
      const order = wrapper
        .findAll('.agency-thread__messages > li')
        .map((li) => li.attributes('data-test'))
        .filter((d) => d !== 'agency-thread-day')
      expect(order).toEqual([
        'relationship-message-a',
        'thread-system-s1',
        'relationship-message-b',
      ])
      wrapper.unmount()
    })
  })

  describe('inline images', () => {
    function imageMsg(id: string, att = fileAtt()) {
      return amsg(id, { body: null, kind: 'attachment_only', attachments: [att] as never })
    }

    it('renders an image/png attachment as a lazy <img> with the name as alt, inside a lightbox button', async () => {
      const wrapper = await mountAgencyView([imageMsg('i1')])
      const img = wrapper.get('[data-test="message-image"]')
      expect(img.element.tagName).toBe('IMG')
      expect(img.attributes('loading')).toBe('lazy')
      expect(img.attributes('alt')).toBe('shot.png')
      expect(img.attributes('src')).toBe('https://files.example/shot.png')
      // A button that opens the lightbox: no link, so nothing opens in a new tab.
      expect(img.element.closest('a')).toBeNull()
      expect(img.element.closest('button')?.getAttribute('data-test')).toBe('message-image-open')
      expect(wrapper.find('.agency-attach').exists()).toBe(false)
      wrapper.unmount()
    })

    it('uses "Attachment" as alt when the file has no name', async () => {
      const wrapper = await mountAgencyView([imageMsg('i1', fileAtt({ name: null }))])
      expect(wrapper.get('[data-test="message-image"]').attributes('alt')).toBe('Attachment')
      wrapper.unmount()
    })

    it('on an <img> error falls back to the file card for that image only', async () => {
      const wrapper = await mountAgencyView([imageMsg('i1'), imageMsg('i2')])
      expect(wrapper.findAll('[data-test="message-image"]')).toHaveLength(2)

      await wrapper
        .get('[data-test="relationship-message-i1"] [data-test="message-image"]')
        .trigger('error')

      expect(
        wrapper.find('[data-test="relationship-message-i1"] [data-test="message-image"]').exists(),
      ).toBe(false)
      const card = wrapper.get(
        '[data-test="relationship-message-i1"] [data-test="relationship-attachment-file"] a',
      )
      expect(card.classes()).toContain('agency-attach')
      expect(card.get('.agency-attach__name').text()).toBe('shot.png')
      expect(card.attributes('href')).toBe('https://files.example/shot.png')
      // The other message still shows its image.
      expect(
        wrapper.find('[data-test="relationship-message-i2"] [data-test="message-image"]').exists(),
      ).toBe(true)
      wrapper.unmount()
    })

    it('keeps a non-image file as the card it always was', async () => {
      const wrapper = await mountAgencyView([
        imageMsg('f1', fileAtt({ mime_type: 'application/pdf', name: 'Brief.pdf' })),
      ])
      expect(wrapper.find('[data-test="message-image"]').exists()).toBe(false)
      const card = wrapper.get('[data-test="relationship-attachment-file"] a')
      expect(card.get('.agency-attach__name').text()).toBe('Brief.pdf')
      expect(card.attributes('href')).toBe('https://files.example/shot.png')
      expect(card.attributes('target')).toBe('_blank')
      wrapper.unmount()
    })

    it('does not inline an SVG (script-capable) or an image with no signed URL', async () => {
      const wrapper = await mountAgencyView([
        imageMsg('v1', fileAtt({ mime_type: 'image/svg+xml', name: 'x.svg' })),
        imageMsg('n1', fileAtt({ view_url: null, name: 'gone.png' })),
      ])
      expect(wrapper.find('[data-test="message-image"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="relationship-message-v1"] a.agency-attach').exists()).toBe(
        true,
      )
      expect(wrapper.find('[data-test="relationship-message-n1"] a').exists()).toBe(false)
      expect(wrapper.get('[data-test="relationship-message-n1"]').text()).toContain('gone.png')
      wrapper.unmount()
    })

    it('renders a link attachment exactly as before, even with an image-looking URL', async () => {
      const wrapper = await mountAgencyView([
        amsg('l1', {
          attachments: [{ kind: 'link', url: 'https://x.example/pic.png', name: 'A pic' }] as never,
        }),
      ])
      expect(wrapper.find('[data-test="message-image"]').exists()).toBe(false)
      const card = wrapper.get('[data-test="relationship-attachment-link"] a')
      expect(card.classes()).toContain('agency-attach')
      expect(card.attributes('href')).toBe('https://x.example/pic.png')
      expect(card.get('.agency-attach__name').text()).toBe('A pic')
      expect(card.get('.agency-attach__meta').text()).toBe('x.example')
      wrapper.unmount()
    })
  })

  describe('message links', () => {
    it('turns a URL in the body into a safe new-tab anchor', async () => {
      const wrapper = await mountAgencyView([
        amsg('b1', { body: 'see https://brief.example/deck now' }),
      ])
      const a = wrapper.get('[data-test="message-link"]')
      expect(a.element.tagName).toBe('A')
      expect(a.attributes('href')).toBe('https://brief.example/deck')
      expect(a.attributes('rel')).toBe('noopener noreferrer')
      expect(a.attributes('target')).toBe('_blank')
      expect(wrapper.get('.agency-bubble__body').text()).toBe('see https://brief.example/deck now')
      wrapper.unmount()
    })

    it('leaves a plain body with no anchor at all', async () => {
      const wrapper = await mountAgencyView([amsg('b1', { body: 'just words, no link' })])
      expect(wrapper.get('.agency-bubble__body').find('a').exists()).toBe(false)
      expect(wrapper.get('.agency-bubble__body').text()).toBe('just words, no link')
      wrapper.unmount()
    })

    it('does not link a javascript: URL or inject markup', async () => {
      const wrapper = await mountAgencyView([
        amsg('b1', { body: 'javascript:alert(1) <a href="https://evil.example">x</a>' }),
      ])
      const body = wrapper.get('.agency-bubble__body')
      expect(
        body.findAll('a').every((a) => !(a.attributes('href') ?? '').startsWith('javascript:')),
      ).toBe(true)
      expect(body.html()).not.toContain('<a href="https://evil.example">x</a>')
      expect(body.text()).toContain('<a href="https://evil.example">x</a>')
      wrapper.unmount()
    })
  })

  describe('header additions', () => {
    it('renders the campaign tag text, with a dot in the brand colour', async () => {
      const wrapper = await mountAgencyView([amsg('a')], {
        campaignTag: { text: 'Acme · Summer', color: 'teal' },
      })
      const tag = wrapper.get('[data-test="thread-campaign-tag"]')
      expect(tag.text()).toBe('Acme · Summer')
      expect(tag.get('.agency-thread__tag-dot').attributes('style')).toContain(
        'var(--agency-brand-teal)',
      )
      wrapper.unmount()
    })

    it('draws no dot for a tag with no colour key, and a slate dot for a null colour', async () => {
      const bare = await mountAgencyView([amsg('a')], { campaignTag: { text: 'Solo' } })
      expect(bare.get('[data-test="thread-campaign-tag"]').text()).toBe('Solo')
      expect(bare.find('.agency-thread__tag-dot').exists()).toBe(false)
      bare.unmount()

      const slate = await mountAgencyView([amsg('a')], { campaignTag: { text: 'S', color: null } })
      expect(slate.get('.agency-thread__tag-dot').attributes('style')).toContain(
        'var(--agency-brand-slate)',
      )
      slate.unmount()
    })

    it('renders "View campaign" linked to viewCampaignTo', async () => {
      const wrapper = await mountAgencyView([amsg('a')], {
        viewCampaignTo: { name: 'campaigns.detail', params: { ulid: 'camp9' } },
      })
      const btn = wrapper.get('[data-test="thread-view-campaign"]')
      expect(btn.text()).toBe('View campaign')
      expect(btn.attributes('href')).toBe('/campaigns/camp9')
      wrapper.unmount()
    })

    it('renders the #actions slot in the header', async () => {
      const wrapper = await mountAgencyView(
        [amsg('a')],
        {},
        { actions: '<button data-test="slot-actions" type="button">⋯</button>' },
      )
      const slotted = wrapper.get('.agency-thread__header [data-test="slot-actions"]')
      expect(slotted.text()).toBe('⋯')
      wrapper.unmount()
    })

    it('a Direct thread with none of the new props renders as before', async () => {
      const wrapper = await mountAgencyView([amsg('a', { body: 'plain' })])
      expect(wrapper.find('[data-test="thread-campaign-tag"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="thread-view-campaign"]').exists()).toBe(false)
      expect(wrapper.find('.agency-thread__header [data-test="slot-actions"]').exists()).toBe(false)
      expect(wrapper.find('[data-test^="thread-system-"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="message-image"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="relationship-thread-title"]').text()).toBe('Nadia Okafor')
      expect(wrapper.get('[data-test="relationship-message-a"]').text()).toContain('plain')
      // The link action is still there, and the composer is open.
      await wrapper.get('[data-test="relationship-attach-toggle"]').trigger('click')
      expect(wrapper.find('[data-test="relationship-attach-link"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="relationship-compose"]').exists()).toBe(true)
      wrapper.unmount()
    })
  })

  describe('noLinks', () => {
    it('hides the link action but keeps the file action', async () => {
      const wrapper = await mountAgencyView([amsg('a')], { noLinks: true })
      await wrapper.get('[data-test="relationship-attach-toggle"]').trigger('click')
      expect(wrapper.find('[data-test="relationship-attach-file"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="relationship-attach-link"]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('shows the link action when noLinks is off', async () => {
      const wrapper = await mountAgencyView([amsg('a')], { noLinks: false })
      await wrapper.get('[data-test="relationship-attach-toggle"]').trigger('click')
      expect(wrapper.find('[data-test="relationship-attach-link"]').exists()).toBe(true)
      wrapper.unmount()
    })
  })

  describe('closedText', () => {
    it('replaces the relationship wording when given', async () => {
      const wrapper = await mountAgencyView(
        [amsg('a')],
        { closedText: 'Closed for this assignment.' },
        {},
        { can_send: false, closed_reason: 'relation_ended' },
      )
      expect(wrapper.get('[data-test="relationship-closed"]').text()).toBe(
        'Closed for this assignment.',
      )
      wrapper.unmount()
    })
  })

  describe('pasting into the composer', () => {
    function paste(target: Element, clipboardData: unknown): Event {
      const event = new Event('paste', { bubbles: true, cancelable: true })
      Object.defineProperty(event, 'clipboardData', { value: clipboardData })
      target.dispatchEvent(event)
      return event
    }
    function file(name: string, type: string): File {
      return new File(['x'], name, { type })
    }

    it('adds a pasted image to the pending files list', async () => {
      const wrapper = await mountAgencyView([amsg('a')])
      expect(wrapper.find('[data-test="relationship-pending-files"]').exists()).toBe(false)

      const event = paste(wrapper.get('[data-test="relationship-compose-body"] textarea').element, {
        files: [file('clip.png', 'image/png')],
      })
      await flushPromises()

      expect(event.defaultPrevented).toBe(true)
      const pending = wrapper.get('[data-test="relationship-pending-files"]')
      expect(pending.findAll('li')).toHaveLength(1)
      expect(pending.text()).toContain('clip.png')
      wrapper.unmount()
    })

    it('appends a second pasted image to the first', async () => {
      const wrapper = await mountAgencyView([amsg('a')])
      const field = wrapper.get('[data-test="relationship-compose-body"] textarea').element
      paste(field, { files: [file('one.png', 'image/png')] })
      paste(field, { files: [file('two.webp', 'image/webp')] })
      await flushPromises()
      const names = wrapper
        .findAll('[data-test="relationship-pending-files"] li')
        .map((li) => li.text())
      expect(names).toHaveLength(2)
      expect(names[0]).toContain('one.png')
      expect(names[1]).toContain('two.webp')
      wrapper.unmount()
    })

    it('shows the unsupported-type text for a pasted non-image, and adds nothing', async () => {
      const wrapper = await mountAgencyView([amsg('a')])
      const event = paste(wrapper.get('[data-test="relationship-compose-body"] textarea').element, {
        files: [file('doc.pdf', 'application/pdf')],
      })
      await flushPromises()
      expect(event.defaultPrevented).toBe(false)
      expect(wrapper.find('[data-test="relationship-pending-files"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="relationship-general-error"]').text()).toBe(
        'Only images can be pasted here. Use the paperclip for other files.',
      )
      wrapper.unmount()
    })

    it('leaves a plain-text paste alone: nothing pending, no error', async () => {
      const wrapper = await mountAgencyView([amsg('a')])
      const event = paste(wrapper.get('[data-test="relationship-compose-body"] textarea').element, {
        files: [],
        getData: () => 'hello',
      })
      await flushPromises()
      expect(event.defaultPrevented).toBe(false)
      expect(wrapper.find('[data-test="relationship-pending-files"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="relationship-general-error"]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('clears the paste error when an image is pasted afterwards', async () => {
      const wrapper = await mountAgencyView([amsg('a')])
      const field = wrapper.get('[data-test="relationship-compose-body"] textarea').element
      paste(field, { files: [file('doc.pdf', 'application/pdf')] })
      await flushPromises()
      expect(wrapper.find('[data-test="relationship-general-error"]').exists()).toBe(true)
      paste(field, { files: [file('ok.png', 'image/png')] })
      await flushPromises()
      expect(wrapper.find('[data-test="relationship-general-error"]').exists()).toBe(false)
      wrapper.unmount()
    })
  })
})

// AH-119 Stage B — flag off = the DOM of today, for the Direct and the campaign transport alike
// (the markup does not depend on the transport), open and closed thread.
describe('AgencyRelationshipThreadView — Stage B flag off renders the pre-Stage-B markup', () => {
  const SWITCHES: Array<[string, Record<string, boolean> | undefined]> = [
    ['no Pinia', undefined],
    ['switch false', { messages_v2_edit_search: false }],
    ['switch absent', {}],
  ]

  for (const [label, features] of SWITCHES) {
    it(`open thread, ${label}: identical to the pin`, async () => {
      const html = await renderHtml(
        AgencyRelationshipThreadView,
        { transport: relTransport(), title: 'X' },
        '[data-test="relationship-thread"]',
        features === undefined ? {} : { features },
      )
      expect(html).toBe(FLAG_OFF_PINS['agency'])
      expect(html).not.toContain('message-actions')
    })

    it(`closed thread, ${label}: identical to the pin`, async () => {
      const html = await renderHtml(
        AgencyRelationshipThreadView,
        { transport: relTransport(false), title: 'X' },
        '[data-test="relationship-thread"]',
        features === undefined ? {} : { features },
      )
      expect(html).toBe(FLAG_OFF_PINS['agency-closed'])
    })
  }
})
