import type { RelationshipMessageResource } from '@catalyst/api-client'
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

function feed(messages: RelationshipMessageResource[], hasMore = false) {
  return {
    data: messages,
    meta: {
      thread: { id: 'thread-ulid', last_message_at: null, unread_count: 0 },
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
