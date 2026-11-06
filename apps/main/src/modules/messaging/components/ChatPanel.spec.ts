import { ApiError } from '@catalyst/api-client'
import type { MessageResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

import type { ChatTransport } from '../api/messaging.api'
import ChatPanel from './ChatPanel.vue'

function msg(id: string, attrs: Partial<MessageResource['attributes']> = {}): MessageResource {
  return {
    id,
    type: 'message',
    attributes: {
      kind: 'text',
      sender_role: 'agency_user',
      body: 'hello there',
      attachments: [],
      system_event_key: null,
      system_event_data: null,
      is_own: false,
      sender: { name: 'Agency Op' },
      created_at: '2026-01-01T00:00:00+00:00',
      ...attrs,
    },
  }
}

function feed(messages: MessageResource[], opts: { blocked?: boolean; hasMore?: boolean } = {}) {
  return {
    data: messages,
    meta: {
      thread: {
        id: 'thread-ulid',
        assignment_id: 'assignment-ulid',
        last_message_at: null,
        unread_count: 0,
        human_send_blocked: opts.blocked ?? false,
      },
      has_more: opts.hasMore ?? false,
    },
  }
}

function makeTransport(overrides: Partial<ChatTransport> = {}): ChatTransport {
  return {
    list: vi.fn().mockResolvedValue(feed([msg('m1')])),
    send: vi.fn(),
    markRead: vi.fn().mockResolvedValue({ meta: { marked: 0, unread_count: 0 } }),
    attachmentInit: vi.fn(),
    attachmentComplete: vi.fn(),
    ...overrides,
  }
}

async function mountPanel(transport: ChatTransport) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(ChatPanel, {
    props: { transport },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('ChatPanel', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('renders the loaded feed', async () => {
    const wrapper = await mountPanel(makeTransport())
    expect(wrapper.find('[data-test="chat-feed"]').text()).toContain('hello there')
    wrapper.unmount()
  })

  it('renders a system message from its event key (localized, never stored text)', async () => {
    const system = msg('s1', {
      kind: 'system',
      sender_role: 'system',
      body: null,
      sender: null,
      system_event_key: 'assignment.contracted',
    })
    const wrapper = await mountPanel(
      makeTransport({ list: vi.fn().mockResolvedValue(feed([system])) }),
    )
    expect(wrapper.find('[data-test="chat-feed"]').text()).toContain(
      'The contract was signed — production can begin.',
    )
    wrapper.unmount()
  })

  it('renders the truthful no-contract line for a contract-less advance (never claims a signed contract)', async () => {
    const system = msg('s2', {
      kind: 'system',
      sender_role: 'system',
      body: null,
      sender: null,
      system_event_key: 'assignment.contracted_without_contract',
    })
    const wrapper = await mountPanel(
      makeTransport({ list: vi.fn().mockResolvedValue(feed([system])) }),
    )
    const feedText = wrapper.find('[data-test="chat-feed"]').text()
    expect(feedText).toContain('Production can begin.')
    expect(feedText).not.toContain('The contract was signed')
    wrapper.unmount()
  })

  // ── AH-118: the offer-edit history line ────────────────────────────────────

  function offerEdited(data: Record<string, unknown> | null): MessageResource {
    return msg('oe1', {
      kind: 'system',
      sender_role: 'system',
      body: null,
      sender: null,
      system_event_key: 'assignment.offer_edited',
      system_event_data: data,
    })
  }

  async function feedTextFor(message: MessageResource, locale = 'en'): Promise<string> {
    const i18n = createI18n({ legacy: false, locale, messages: { [locale]: enApp } as never })
    const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
    const wrapper = mount(ChatPanel, {
      props: { transport: makeTransport({ list: vi.fn().mockResolvedValue(feed([message])) }) },
      global: { plugins: [i18n, vuetify] },
      attachTo: document.createElement('div'),
    })
    await flushPromises()
    const text = wrapper.find('[data-test="chat-feed"]').text()
    wrapper.unmount()
    return text
  }

  it('renders "The offer changed from {from} to {to}." with both amounts formatted in the locale', async () => {
    const text = await feedTextFor(
      offerEdited({ previous_amount: 100_00, new_amount: 250_50, currency: 'EUR' }),
    )
    expect(text).toContain('The offer changed from €100.00 to €250.50.')
  })

  it('formats the amounts for a non-English reader (de: decimal comma)', async () => {
    const text = await feedTextFor(
      offerEdited({ previous_amount: 100_00, new_amount: 250_50, currency: 'EUR' }),
      'de',
    )
    expect(text).toMatch(/100,00\s€/)
    expect(text).toMatch(/250,50\s€/)
  })

  it.each([
    ['an absent bag', null],
    ['a bag with no currency', { previous_amount: 100_00, new_amount: 250_00 }],
    ['a bag with a string amount', { previous_amount: '100', new_amount: 250_00, currency: 'EUR' }],
    ['an empty currency', { previous_amount: 100_00, new_amount: 250_00, currency: '' }],
  ])(
    'falls back to the generic system line for %s, never a half-filled sentence',
    async (_label, data) => {
      const text = await feedTextFor(offerEdited(data as Record<string, unknown> | null))
      expect(text).toContain('The assignment was updated.')
      expect(text).not.toContain('The offer changed')
    },
  )

  it('hides the compose form and shows the closed notice on a terminal thread', async () => {
    const wrapper = await mountPanel(
      makeTransport({ list: vi.fn().mockResolvedValue(feed([msg('m1')], { blocked: true })) }),
    )
    expect(wrapper.find('[data-test="chat-closed"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="chat-compose"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('sends a typed message through the transport and clears the field', async () => {
    const transport = makeTransport({
      send: vi.fn().mockResolvedValue({ data: msg('m2', { is_own: true, body: 'my reply' }) }),
    })
    const wrapper = await mountPanel(transport)

    await wrapper.find('[data-test="chat-compose-body"] textarea').setValue('my reply')
    await wrapper.find('[data-test="chat-compose"]').trigger('submit')
    await flushPromises()

    expect(transport.send).toHaveBeenCalledWith({ body: 'my reply' })
    expect(
      (wrapper.find('[data-test="chat-compose-body"] textarea').element as HTMLTextAreaElement)
        .value,
    ).toBe('')
    wrapper.unmount()
  })

  it('binds a 422 body validation error onto the compose field', async () => {
    const transport = makeTransport({
      send: vi.fn().mockRejectedValue(
        new ApiError({
          status: 422,
          code: 'validation.field_required',
          message: 'Validation failed.',
          details: [
            {
              detail: 'The message body is invalid.',
              source: { pointer: '/data/attributes/body' },
              meta: { field: 'body' },
            },
          ],
        }),
      ),
    })
    const wrapper = await mountPanel(transport)

    await wrapper.find('[data-test="chat-compose-body"] textarea').setValue('bad')
    await wrapper.find('[data-test="chat-compose"]').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('The message body is invalid.')
    wrapper.unmount()
  })
})

// ── AH-119 A6: linkify + inline images + paste (shared surface) ─────────────

vi.mock('@catalyst/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@catalyst/api-client')>()),
  uploadToPresignedUrl: vi.fn().mockResolvedValue(undefined),
}))

const A6_I18N_FALLBACKS: Record<string, string> = {
  attachmentDropHint: 'Drop images to attach',
  attachmentImageUnavailable: 'Image unavailable',
  attachmentPasteUnsupportedType: 'Only PNG, JPEG, WebP and GIF images can be pasted.',
}

function a6Messages() {
  const clone = JSON.parse(JSON.stringify(enApp)) as typeof enApp & {
    app: { messaging: Record<string, string> }
  }
  for (const [k, v] of Object.entries(A6_I18N_FALLBACKS)) {
    clone.app.messaging[k] ??= v
  }
  return clone
}

const stripScope = (html: string) => html.replace(/ data-v-[a-z0-9]+=""/g, '')

async function a6Mount(
  native: boolean,
  messages: MessageResource[],
  tr: ChatTransport = makeTransport(),
) {
  void native
  vi.mocked(tr.list).mockResolvedValue(feed(messages))
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: a6Messages() } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(ChatPanel, {
    props: { transport: tr },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return { wrapper, tr }
}

function a6Paste(wrapper: { get: (s: string) => { element: Element } }, files: File[]): Event {
  const event = new Event('paste', { bubbles: true, cancelable: true })
  Object.defineProperty(event, 'clipboardData', { value: { files } })
  wrapper.get('[data-test="chat-compose"]').element.dispatchEvent(event)
  return event
}

describe.each([['web', false]])('ChatPanel AH-119 A6 (%s)', (_label, native) => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('renders a URL in the body as a safe, clickable link', async () => {
    const { wrapper } = await a6Mount(native, [
      msg('l1', { body: 'read https://example.com/doc. thanks' }),
    ])
    const a = wrapper.get('[data-test="message-link"]')
    expect(a.attributes('href')).toBe('https://example.com/doc')
    expect(a.attributes('rel')).toBe('noopener noreferrer')
    expect(a.attributes('target')).toBe('_blank')
    expect(wrapper.get('.chat-message__body').element.textContent).toBe(
      'read https://example.com/doc. thanks',
    )
    wrapper.unmount()
  })

  it('does not link javascript: and keeps HTML as text', async () => {
    const { wrapper } = await a6Mount(native, [
      msg('l2', { body: 'javascript:alert(1) <img src=x onerror=alert(1)>' }),
    ])
    expect(wrapper.find('[data-test="message-link"]').exists()).toBe(false)
    expect(wrapper.find('.chat-message__body img').exists()).toBe(false)
    wrapper.unmount()
  })

  it('POLARITY: a URL-free, image-free message renders the exact old body markup', async () => {
    const { wrapper } = await a6Mount(native, [msg('p1', { body: 'hello there' })])
    expect(stripScope(wrapper.get('.chat-message__body').element.outerHTML)).toBe(
      '<p class="chat-message__body">hello there</p>',
    )
    wrapper.unmount()
  })

  it('POLARITY: a non-image file attachment renders exactly as before', async () => {
    const { wrapper } = await a6Mount(native, [
      msg('p2', {
        attachments: [
          {
            s3_path: null,
            mime_type: 'application/pdf',
            name: 'a.pdf',
            size_bytes: 1,
            view_url: 'https://cdn.test/a.pdf',
          },
        ],
      }),
    ])
    expect(wrapper.find('[data-test="message-image"]').exists()).toBe(false)
    expect(stripScope(wrapper.get('.chat-message__attachments').element.outerHTML)).toBe(
      '<ul class="chat-message__attachments"><li><a href="https://cdn.test/a.pdf" target="_blank" rel="noopener noreferrer"><i class="mdi-paperclip mdi v-icon notranslate v-theme--light v-icon--size-x-small mr-1" aria-hidden="true"></i> a.pdf</a></li></ul>',
    )
    wrapper.unmount()
  })

  it('renders an image attachment as a lazy inline <img> inside a lightbox button', async () => {
    const { wrapper } = await a6Mount(native, [
      msg('i1', {
        attachments: [
          {
            s3_path: null,
            mime_type: 'image/png',
            name: 'pic.png',
            size_bytes: 1,
            view_url: 'https://cdn.test/pic.png',
          },
        ],
      }),
    ])
    const img = wrapper.get('[data-test="message-image"]')
    expect(img.attributes()).toMatchObject({
      loading: 'lazy',
      src: 'https://cdn.test/pic.png',
      alt: 'pic.png',
    })
    expect(img.classes()).toContain('message-image')
    // A button that opens the lightbox: no link, so nothing opens in a new tab.
    expect(img.element.closest('a')).toBeNull()
    expect(img.element.closest('button')?.getAttribute('data-test')).toBe('message-image-open')
    wrapper.unmount()
  })

  it('falls back to the file card when the image fails to load', async () => {
    const { wrapper } = await a6Mount(native, [
      msg('i2', {
        attachments: [
          {
            s3_path: null,
            mime_type: 'image/png',
            name: 'pic.png',
            size_bytes: 1,
            view_url: 'https://cdn.test/pic.png',
          },
        ],
      }),
    ])
    await wrapper.get('[data-test="message-image"]').trigger('error')
    expect(wrapper.find('[data-test="message-image"]').exists()).toBe(false)
    const card = wrapper.get('.chat-message__attachments a')
    expect(card.attributes('href')).toBe('https://cdn.test/pic.png')
    expect(card.attributes('title')).toBe(a6Messages().app.messaging.attachmentImageUnavailable)
    expect(card.text()).toContain('pic.png')
    wrapper.unmount()
  })

  it('does not inline an image with a null view_url', async () => {
    const { wrapper } = await a6Mount(native, [
      msg('i3', {
        attachments: [
          {
            ...{
              s3_path: null,
              mime_type: 'image/png',
              name: 'pic.png',
              size_bytes: 1,
              view_url: 'https://cdn.test/pic.png',
            },
            view_url: null,
          },
        ],
      }),
    ])
    expect(wrapper.find('[data-test="message-image"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('a pasted image joins the pending list and goes out through the existing send path', async () => {
    const tr = makeTransport()
    vi.mocked(tr.attachmentInit).mockResolvedValue({
      data: { upload_url: 'https://s3.test/u', upload_id: 'up-1' },
    } as never)
    vi.mocked(tr.send).mockResolvedValue({ data: msg('sent', {}) } as never)
    const { wrapper } = await a6Mount(native, [msg('m1', {})], tr)

    const png = new File(['x'], 'shot.png', { type: 'image/png' })
    const event = a6Paste(wrapper, [png])
    await flushPromises()
    expect(event.defaultPrevented).toBe(true)
    expect(wrapper.get('[data-test="chat-pending-files"]').text()).toContain('shot.png')

    await wrapper.get('[data-test="chat-compose"]').trigger('submit')
    await flushPromises()
    expect(tr.attachmentInit).toHaveBeenCalledWith({ mime_type: 'image/png', size_bytes: png.size })
    expect(tr.send).toHaveBeenCalledWith(
      expect.objectContaining({
        attachments: [expect.objectContaining({ upload_id: 'up-1', name: 'shot.png' })],
      }),
    )
    wrapper.unmount()
  })

  it('pasted images append to files already picked with the paperclip', async () => {
    const { wrapper } = await a6Mount(native, [msg('m1', {})])
    a6Paste(wrapper, [new File(['x'], 'one.png', { type: 'image/png' })])
    a6Paste(wrapper, [new File(['x'], 'two.webp', { type: 'image/webp' })])
    await flushPromises()
    const text = wrapper.get('[data-test="chat-pending-files"]').text()
    expect(text).toContain('one.png')
    expect(text).toContain('two.webp')
    wrapper.unmount()
  })

  it('a pasted pdf is refused with the inline error and nothing is queued', async () => {
    const { wrapper } = await a6Mount(native, [msg('m1', {})])
    const event = a6Paste(wrapper, [new File(['x'], 'a.pdf', { type: 'application/pdf' })])
    await flushPromises()
    expect(event.defaultPrevented).toBe(false)
    expect(wrapper.find('[data-test="chat-pending-files"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="chat-general-error"]').text()).toBe(
      a6Messages().app.messaging.attachmentPasteUnsupportedType,
    )
    wrapper.unmount()
  })

  it('shows the drop hint while a file is dragged over the composer', async () => {
    const { wrapper } = await a6Mount(native, [msg('m1', {})])
    expect(wrapper.find('[data-test="chat-drop-hint"]').exists()).toBe(false)
    const enter = new Event('dragenter', { bubbles: true, cancelable: true })
    Object.defineProperty(enter, 'dataTransfer', { value: { types: ['Files'], files: [] } })
    wrapper.get('[data-test="chat-compose"]').element.dispatchEvent(enter)
    await flushPromises()
    expect(wrapper.get('[data-test="chat-drop-hint"]').text()).toBe(
      a6Messages().app.messaging.attachmentDropHint,
    )
    wrapper.unmount()
  })
})
