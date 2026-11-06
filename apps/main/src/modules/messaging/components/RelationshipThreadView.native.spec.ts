/**
 * AH-101 (U4) — the relationship thread's attach controls, native vs web.
 *
 * Unlike the campaign thread, the DM's "+" menu held TWO actions (file and link),
 * so a lone paperclip would have been a feature removal. In the native app the
 * paperclip and the link control now sit inline in the composer row — the same
 * pair the assignment page already renders — and the "+" menu is gone. The web
 * composer keeps the "+" menu exactly as it was (its own behaviour is pinned in
 * RelationshipThreadView.spec.ts). Both polarities are pinned here.
 */

import type { RelationshipMessageResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

const platform = vi.hoisted(() => ({ native: false }))
vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
}))

import {
  FLAG_OFF_PINS,
  relTransport,
  renderHtml,
} from '../../../../tests/unit/helpers/stageBFlagOff'
import type { RelationshipChatTransport } from '../api/relationshipMessaging.api'
import RelationshipThreadView from './RelationshipThreadView.vue'

function msg(id: string): RelationshipMessageResource {
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
    },
  }
}

function transport(): RelationshipChatTransport {
  return {
    list: vi.fn().mockResolvedValue({
      data: [msg('m1')],
      meta: {
        thread: {
          id: 'thread-ulid',
          counterparty: { name: 'Acme Agency' },
          closed: false,
          closed_reason: null,
        },
        has_more: false,
      },
    }),
    send: vi.fn().mockResolvedValue({ data: msg('m2') }),
    markRead: vi.fn().mockResolvedValue({ meta: { marked: 0, unread_count: 0 } }),
    attachmentInit: vi.fn(),
    attachmentComplete: vi.fn(),
  } as unknown as RelationshipChatTransport
}

async function mountView(native: boolean) {
  platform.native = native
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(RelationshipThreadView, {
    props: { transport: transport(), title: 'Acme Agency' },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

afterEach(() => {
  platform.native = false
  vi.restoreAllMocks()
  document.body.innerHTML = ''
})

describe('RelationshipThreadView attach controls — native', () => {
  it('renders the paperclip AND the link control inline, with no "+" toggle and no menu', async () => {
    const wrapper = await mountView(true)

    expect(wrapper.find('[data-test="relationship-attach-file"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="relationship-attach-link"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="relationship-attach-toggle"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="relationship-attach-menu"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('places both controls inside the composer row (not a separate menu row)', async () => {
    const wrapper = await mountView(true)
    const row = wrapper.find('.rel-thread__compose-row')

    expect(row.find('[data-test="relationship-attach-file"]').exists()).toBe(true)
    expect(row.find('[data-test="relationship-attach-link"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('opens the file picker in one tap', async () => {
    const wrapper = await mountView(true)
    const click = vi.spyOn(HTMLInputElement.prototype, 'click').mockImplementation(() => {})

    await wrapper.find('[data-test="relationship-attach-file"]').trigger('click')

    expect(click).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('opens the link dialog in one tap — no capability was lost with the menu', async () => {
    const wrapper = await mountView(true)

    await wrapper.find('[data-test="relationship-attach-link"]').trigger('click')
    await flushPromises()

    expect(document.body.querySelector('[data-test="relationship-link-url"]')).not.toBeNull()
    wrapper.unmount()
  })

  it('still attaches a link end to end from the inline control', async () => {
    const wrapper = await mountView(true)

    await wrapper.find('[data-test="relationship-attach-link"]').trigger('click')
    await flushPromises()
    const urlInput = document.body.querySelector(
      '[data-test="relationship-link-url"] input',
    ) as HTMLInputElement
    urlInput.value = 'https://x.example/a'
    urlInput.dispatchEvent(new Event('input'))
    await flushPromises()
    ;(document.body.querySelector('[data-test="relationship-link-add"]') as HTMLElement).click()
    await flushPromises()

    expect(wrapper.find('[data-test="relationship-pending-links"]').exists()).toBe(true)
    wrapper.unmount()
  })
})

describe('RelationshipThreadView attach controls — web (unchanged)', () => {
  it('keeps the "+" toggle; the file and link controls exist only inside its menu', async () => {
    const wrapper = await mountView(false)

    expect(wrapper.find('[data-test="relationship-attach-toggle"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="relationship-attach-file"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="relationship-attach-link"]').exists()).toBe(false)

    await wrapper.find('[data-test="relationship-attach-toggle"]').trigger('click')

    expect(wrapper.find('[data-test="relationship-attach-menu"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="relationship-attach-file"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="relationship-attach-link"]').exists()).toBe(true)
    wrapper.unmount()
  })
})

function a6Msg(
  id: string,
  attrs: Partial<RelationshipMessageResource['attributes']> = {},
): RelationshipMessageResource {
  const base = msg(id)
  return { ...base, attributes: { ...base.attributes, ...attrs } }
}

function a6Feed(messages: RelationshipMessageResource[]) {
  return {
    data: messages,
    meta: {
      thread: {
        id: 'thread-ulid',
        last_message_at: null,
        unread_count: 0,
        can_send: true,
        closed_reason: null,
      },
      has_more: false,
    },
  }
}

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
  messages: RelationshipMessageResource[],
  tr: RelationshipChatTransport = transport(),
) {
  platform.native = native
  vi.mocked(tr.list).mockResolvedValue(a6Feed(messages))
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: a6Messages() } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(RelationshipThreadView, {
    props: { transport: tr, title: 'Acme Agency' },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return { wrapper, tr }
}

function a6Paste(wrapper: { get: (s: string) => { element: Element } }, files: File[]): Event {
  const event = new Event('paste', { bubbles: true, cancelable: true })
  Object.defineProperty(event, 'clipboardData', { value: { files } })
  wrapper.get('[data-test="relationship-compose"]').element.dispatchEvent(event)
  return event
}

describe.each([
  ['web', false],
  ['native', true],
])('RelationshipThreadView AH-119 A6 (%s)', (_label, native) => {
  afterEach(() => {
    platform.native = false
    vi.restoreAllMocks()
  })

  it('renders a URL in the body as a safe, clickable link', async () => {
    const { wrapper } = await a6Mount(native, [
      a6Msg('l1', { body: 'read https://example.com/doc. thanks' }),
    ])
    const a = wrapper.get('[data-test="message-link"]')
    expect(a.attributes('href')).toBe('https://example.com/doc')
    expect(a.attributes('rel')).toBe('noopener noreferrer')
    expect(a.attributes('target')).toBe('_blank')
    expect(wrapper.get('.rel-bubble__body').element.textContent).toBe(
      'read https://example.com/doc. thanks',
    )
    wrapper.unmount()
  })

  it('does not link javascript: and keeps HTML as text', async () => {
    const { wrapper } = await a6Mount(native, [
      a6Msg('l2', { body: 'javascript:alert(1) <img src=x onerror=alert(1)>' }),
    ])
    expect(wrapper.find('[data-test="message-link"]').exists()).toBe(false)
    expect(wrapper.find('.rel-bubble__body img').exists()).toBe(false)
    wrapper.unmount()
  })

  it('POLARITY: a URL-free, image-free message renders the exact old body markup', async () => {
    const { wrapper } = await a6Mount(native, [a6Msg('p1', { body: 'hello there' })])
    expect(stripScope(wrapper.get('.rel-bubble__body').element.outerHTML)).toBe(
      '<p class="rel-bubble__body">hello there</p>',
    )
    wrapper.unmount()
  })

  it('POLARITY: a non-image file attachment renders exactly as before', async () => {
    const { wrapper } = await a6Mount(native, [
      a6Msg('p2', {
        attachments: [
          {
            kind: 'file',
            s3_path: null,
            mime_type: 'application/pdf',
            name: 'a.pdf',
            size_bytes: 1,
            view_url: 'https://cdn.test/a.pdf',
          } as const,
        ],
      }),
    ])
    expect(wrapper.find('[data-test="message-image"]').exists()).toBe(false)
    expect(stripScope(wrapper.get('.rel-bubble__attachments').element.outerHTML)).toBe(
      '<ul class="rel-bubble__attachments"><li data-test="relationship-attachment-file"><a href="https://cdn.test/a.pdf" target="_blank" rel="noopener noreferrer"><i class="mdi-paperclip mdi v-icon notranslate v-theme--light v-icon--size-x-small mr-1" aria-hidden="true"></i> a.pdf</a></li></ul>',
    )
    wrapper.unmount()
  })

  it('renders an image attachment as a lazy inline <img> inside a lightbox button', async () => {
    const { wrapper } = await a6Mount(native, [
      a6Msg('i1', {
        attachments: [
          {
            kind: 'file',
            s3_path: null,
            mime_type: 'image/png',
            name: 'pic.png',
            size_bytes: 1,
            view_url: 'https://cdn.test/pic.png',
          } as const,
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
      a6Msg('i2', {
        attachments: [
          {
            kind: 'file',
            s3_path: null,
            mime_type: 'image/png',
            name: 'pic.png',
            size_bytes: 1,
            view_url: 'https://cdn.test/pic.png',
          } as const,
        ],
      }),
    ])
    await wrapper.get('[data-test="message-image"]').trigger('error')
    expect(wrapper.find('[data-test="message-image"]').exists()).toBe(false)
    const card = wrapper.get('.rel-bubble__attachments a')
    expect(card.attributes('href')).toBe('https://cdn.test/pic.png')
    expect(card.attributes('title')).toBe(a6Messages().app.messaging.attachmentImageUnavailable)
    expect(card.text()).toContain('pic.png')
    wrapper.unmount()
  })

  it('does not inline an image with a null view_url', async () => {
    const { wrapper } = await a6Mount(native, [
      a6Msg('i3', {
        attachments: [
          {
            ...({
              kind: 'file',
              s3_path: null,
              mime_type: 'image/png',
              name: 'pic.png',
              size_bytes: 1,
              view_url: 'https://cdn.test/pic.png',
            } as const),
            view_url: null,
          },
        ],
      }),
    ])
    expect(wrapper.find('[data-test="message-image"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('a pasted image joins the pending list and goes out through the existing send path', async () => {
    const tr = transport()
    vi.mocked(tr.attachmentInit).mockResolvedValue({
      data: { upload_url: 'https://s3.test/u', upload_id: 'up-1' },
    } as never)
    vi.mocked(tr.send).mockResolvedValue({ data: a6Msg('sent', {}) } as never)
    const { wrapper } = await a6Mount(native, [a6Msg('m1', {})], tr)

    const png = new File(['x'], 'shot.png', { type: 'image/png' })
    const event = a6Paste(wrapper, [png])
    await flushPromises()
    expect(event.defaultPrevented).toBe(true)
    expect(wrapper.get('[data-test="relationship-pending-files"]').text()).toContain('shot.png')

    await wrapper.get('[data-test="relationship-compose"]').trigger('submit')
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
    const { wrapper } = await a6Mount(native, [a6Msg('m1', {})])
    a6Paste(wrapper, [new File(['x'], 'one.png', { type: 'image/png' })])
    a6Paste(wrapper, [new File(['x'], 'two.webp', { type: 'image/webp' })])
    await flushPromises()
    const text = wrapper.get('[data-test="relationship-pending-files"]').text()
    expect(text).toContain('one.png')
    expect(text).toContain('two.webp')
    wrapper.unmount()
  })

  it('a pasted pdf is refused with the inline error and nothing is queued', async () => {
    const { wrapper } = await a6Mount(native, [a6Msg('m1', {})])
    const event = a6Paste(wrapper, [new File(['x'], 'a.pdf', { type: 'application/pdf' })])
    await flushPromises()
    expect(event.defaultPrevented).toBe(false)
    expect(wrapper.find('[data-test="relationship-pending-files"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="relationship-general-error"]').text()).toBe(
      a6Messages().app.messaging.attachmentPasteUnsupportedType,
    )
    wrapper.unmount()
  })

  it('shows the drop hint while a file is dragged over the composer', async () => {
    const { wrapper } = await a6Mount(native, [a6Msg('m1', {})])
    expect(wrapper.find('[data-test="relationship-drop-hint"]').exists()).toBe(false)
    const enter = new Event('dragenter', { bubbles: true, cancelable: true })
    Object.defineProperty(enter, 'dataTransfer', { value: { types: ['Files'], files: [] } })
    wrapper.get('[data-test="relationship-compose"]').element.dispatchEvent(enter)
    await flushPromises()
    expect(wrapper.get('[data-test="relationship-drop-hint"]').text()).toBe(
      a6Messages().app.messaging.attachmentDropHint,
    )
    wrapper.unmount()
  })
})

// AH-119 Stage B — flag off = the DOM of today. The panel is rendered with the Stage B switch off
// (no Pinia, the switch false, and the switch absent) in the web and the Capacitor polarity and must
// equal the markup captured from the last pre-Stage-B commit (`stageBFlagOffPins.json`).
//
// Break-revert: render a `<!--v-if-->` instead of the empty gate, or add any element or class to the
// bubble for a flag-off message → these pins fail.
describe('RelationshipThreadView — Stage B flag off renders the pre-Stage-B markup', () => {
  const SWITCHES: Array<[string, Record<string, boolean> | undefined]> = [
    ['no Pinia', undefined],
    ['switch false', { messages_v2_edit_search: false }],
    ['switch absent', {}],
  ]

  for (const native of [false, true]) {
    for (const [label, features] of SWITCHES) {
      it(`${native ? 'native' : 'web'}, ${label}: identical to the pin`, async () => {
        platform.native = native
        const html = await renderHtml(
          RelationshipThreadView,
          { transport: relTransport(), title: 'X' },
          '[data-test="relationship-thread"]',
          features === undefined ? {} : { features },
        )
        expect(html).toBe(FLAG_OFF_PINS[`rel-${native ? 'native' : 'web'}`])
        expect(html).not.toContain('message-actions')
        expect(html).not.toContain('message-edited')
        expect(html).not.toContain('message-deleted')
      })
    }
  }
})
