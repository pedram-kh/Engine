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
