/**
 * AH-101 (U4) — the campaign-thread composer's attach control, native vs web.
 *
 * The "+" opened a menu with ONE item (the paperclip): a tap spent revealing a
 * tap. In the native app the paperclip now sits in the "+" slot and opens the
 * picker directly. The web composer is unchanged — it keeps the "+" and its menu.
 * Both polarities are pinned, because "native changed" and "web did not" are two
 * separate claims.
 */

import type { MessageResource } from '@catalyst/api-client'
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

import type { ChatTransport } from '../api/messaging.api'
import ChatPanel from './ChatPanel.vue'

function msg(id: string): MessageResource {
  return {
    id,
    type: 'message',
    attributes: {
      kind: 'text',
      sender_role: 'agency_user',
      body: 'hello there',
      attachments: [],
      system_event_key: null,
      is_own: false,
      sender: { name: 'Agency Op' },
      created_at: '2026-01-01T00:00:00+00:00',
    },
  }
}

function transport(): ChatTransport {
  return {
    list: vi.fn().mockResolvedValue({
      data: [msg('m1')],
      meta: {
        thread: {
          id: 'thread-ulid',
          assignment_id: 'assignment-ulid',
          last_message_at: null,
          unread_count: 0,
          human_send_blocked: false,
        },
        has_more: false,
      },
    }),
    send: vi.fn(),
    markRead: vi.fn().mockResolvedValue({ meta: { marked: 0, unread_count: 0 } }),
    attachmentInit: vi.fn(),
    attachmentComplete: vi.fn(),
  }
}

async function mountPanel(native: boolean) {
  platform.native = native
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(ChatPanel, {
    props: { transport: transport() },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

afterEach(() => {
  platform.native = false
  vi.restoreAllMocks()
})

describe('ChatPanel attach control — native', () => {
  it('renders the paperclip directly, with no "+" toggle and no menu', async () => {
    const wrapper = await mountPanel(true)

    expect(wrapper.find('[data-test="chat-attach-file"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="chat-attach-toggle"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="chat-attach-menu"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('opens the file picker in one tap', async () => {
    const wrapper = await mountPanel(true)
    const click = vi.spyOn(HTMLInputElement.prototype, 'click').mockImplementation(() => {})

    await wrapper.find('[data-test="chat-attach-file"]').trigger('click')

    expect(click).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="chat-file-input"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('labels the paperclip for assistive tech', async () => {
    const wrapper = await mountPanel(true)

    expect(wrapper.find('[data-test="chat-attach-file"]').attributes('aria-label')).toBe(
      enApp.app.messaging.attachment,
    )
    wrapper.unmount()
  })
})

describe('ChatPanel attach control — web (unchanged)', () => {
  it('keeps the "+" toggle, and the paperclip is only reachable through its menu', async () => {
    const wrapper = await mountPanel(false)

    expect(wrapper.find('[data-test="chat-attach-toggle"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="chat-attach-menu"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="chat-attach-file"]').exists()).toBe(false)

    await wrapper.find('[data-test="chat-attach-toggle"]').trigger('click')

    expect(wrapper.find('[data-test="chat-attach-menu"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="chat-attach-file"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('opens the picker from the menu item and closes the menu', async () => {
    const wrapper = await mountPanel(false)
    const click = vi.spyOn(HTMLInputElement.prototype, 'click').mockImplementation(() => {})

    await wrapper.find('[data-test="chat-attach-toggle"]').trigger('click')
    await wrapper.find('[data-test="chat-attach-file"]').trigger('click')

    expect(click).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="chat-attach-menu"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
